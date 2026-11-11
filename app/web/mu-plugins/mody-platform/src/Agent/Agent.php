<?php
/**
 * The in-site agent: a tool-calling loop over the WordPress AI Client.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\PageOutline;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Site\Menu;
use Mody\Platform\Site\Settings;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_AI_Client_Ability_Function_Resolver as Resolver;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Answers one chat message of a member (documentation/mvp-spec.md section 8).
 *
 * The model decides whether to act (a `mody` ability), ask a follow-up
 * question (`ask_user`) or answer. Abilities run in-process through core's
 * ability resolver, so each one checks the member's capabilities and its
 * input schema. Every request and every tool call is written to
 * `mody_agent_events`. Without a brand profile, no model call is made (rule 4).
 *
 * There is no tool that deletes anything or reaches the database, files or
 * other sites. Refusing such requests is the model's job (`refuse_request`);
 * not being able to do them is the code's.
 */
final class Agent {

	/** Who may use the agent. Each tool also checks its own capabilities. */
	public const CAPABILITY = 'edit_pages';

	/** The abilities offered as tools, keyed by their role in the system prompt. */
	public const ABILITIES = array(
		'generate_page'        => 'mody/generate-page',
		'open_editor'          => 'mody/open-editor',
		'update_design'        => 'mody/update-design',
		'publish_page'         => 'mody/publish-page',
		'update_settings'      => 'mody/update-settings', // 2026-11-11, replaces mody/update-site-settings.
		'update_menu'          => 'mody/update-menu', // 2026-11-11.
		'update_styles'        => 'mody/update-styles', // M3-10 part B.
	);

	/**
	 * What each ability changes that an open editor holds a copy of: the
	 * site's Global Styles, or its settings (title, tagline, mission, tone).
	 * The turn lists them in `refresh`, and the editor's bubble reloads them,
	 * so the Site Editor shows the change without a page reload.
	 */
	private const REFRESH = array(
		'mody/update-design'        => 'styles',
		'mody/update-styles'        => 'styles',
		'mody/update-site-settings' => 'site',
		'mody/update-settings'      => 'site',
		'mody/update-menu'          => 'navigation',
	);

	/**
	 * User option (per site) holding the change that waits for the owner's
	 * decision: the owner had unsaved changes in the editor to what it
	 * changes (decided by the maintainer on 2026-11-09). See hold().
	 */
	private const HELD_OPTION = 'mody_agent_held_change';

	/** How long a held change waits for the owner's decision. */
	public const HELD_TTL = 30 * MINUTE_IN_SECONDS;

	/** Plain function: shows a follow-up question in the chat. Acts on nothing. */
	public const ASK_USER = 'ask_user';

	/** Plain function: declines the request, so refusals are logged as such. */
	public const REFUSE = 'refuse_request';

	/**
	 * Plain function, offered only with an editor context (M3-06): changes
	 * what is open in the editor. The result goes back to the editor, unsaved.
	 */
	public const EDIT_CONTENT = 'edit_content';

	/** The most sections one message changes (review, 2026-11-11: a whole-page change fans out to one call per section). */
	public const MAX_SECTIONS = 30;

	/**
	 * Receives what the agent is doing now, for the chat's progress line
	 * (ChatController sets it for a job; 2026-11-11).
	 *
	 * @var callable|null
	 */
	private static $progress = null;

	/**
	 * What each ability is doing, for the progress line.
	 *
	 * @return array<string, string>
	 */
	private static function doing(): array {
		return array(
			'mody/generate-page'        => __( 'Writing the page…', 'mody_cloud' ),
			'mody/update-design'        => __( 'Changing the colors…', 'mody_cloud' ),
			'mody/update-styles'        => __( 'Changing the styles…', 'mody_cloud' ),
			'mody/publish-page'         => __( 'Publishing the page…', 'mody_cloud' ),
			'mody/update-settings'      => __( 'Changing the settings…', 'mody_cloud' ),
			'mody/update-site-settings' => __( 'Changing the settings…', 'mody_cloud' ),
			'mody/update-menu'          => __( 'Changing the menu…', 'mody_cloud' ),
			'mody/open-editor'          => __( 'Opening the screen…', 'mody_cloud' ),
		);
	}

	/** Longest accepted member message, in characters. */
	public const MAX_MESSAGE_LENGTH = 2000;

	/** Earlier messages sent back to the model as context. */
	public const HISTORY_TURNS = 10;

	/** Model requests per member message. */
	private const MAX_STEPS = 4;

	/** Ability executions per member message. */
	private const MAX_TOOL_CALLS = 3;

	/** Low temperature for reliable tool calls. */
	private const TEMPERATURE = 0.1;

	/** Replies and tool arguments are short. */
	private const MAX_TOKENS = 1000;

	/** Longest reply shown, in characters. */
	private const MAX_REPLY_LENGTH = 2000;

	/** Longest ability result kept for the conversation, in bytes of JSON. Longer ones keep their scalar fields. */
	private const MAX_KEPT_RESULT = 1000;

	/** The member's message. */
	private string $input;

	/** When the request started (microtime). */
	private float $started;

	/** Abilities run in this request. */
	private int $executed = 0;

	/** Whether a tool ended the turn (ask_user, refuse_request). */
	private bool $done = false;

	/**
	 * Whether mody/publish-page failed in this turn. Then no other page is
	 * published until the owner says which one: on 2026-11-06, after "There
	 * is no page 259", the model published an older draft with the same
	 * title without asking.
	 */
	private bool $publish_failed = false;

	/**
	 * What the owner has open in the editor (EditorContext::from_input()), or null.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $context = null;

	/**
	 * Where the owner is in the Site Editor (SiteEditor::screen_from_input()),
	 * or null outside it (ticket M3-09).
	 *
	 * @var array{p: string, section: string, canvas: bool}|null
	 */
	private ?array $screen = null;

	/**
	 * What the owner has unsaved changes to in the editor, by REFRESH name
	 * (`styles`, `site`), as the chat bubble reported it with the message.
	 *
	 * @var list<string>
	 */
	private array $unsaved = array();

	/** Whether a narrating reply was already sent back once in this turn (narrates()). */
	private bool $renarrated = false;

	/** Whether edit_content ran in this turn: one edit per message. */
	private bool $edited = false;

	/**
	 * The abilities run in this turn, as the model called them and with the
	 * result it got (links removed), for the conversation's next turns.
	 *
	 * @var list<array{id: string, name: string, args: array, result: mixed}>
	 */
	private array $calls = array();

	/**
	 * What the chat shows.
	 *
	 * `edits` are changes for the editor to apply (M3-06): post_id, client_id
	 * (the selected block, or null for the whole content) and markup.
	 * `refresh` names what the turn changed on the server that the editor
	 * keeps a copy of (`styles`, `site`, see REFRESH).
	 * `decision` (only when a change was held, see hold()) is the held
	 * change's `id` and `record`, for the chat's three choices.
	 *
	 * @var array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool, edits: list<array{post_id: int, client_id: ?string, markup: string}>, refresh: list<string>}
	 */
	private array $turn = array(
		'reply'   => '',
		'actions' => array(),
		'choices' => array(),
		'refused' => false,
		'error'   => false,
		'edits'   => array(),
		'refresh' => array(),
	);

	/**
	 * Starts a turn.
	 *
	 * @param string $input The member's message, already sanitized.
	 */
	private function __construct( string $input ) {
		$this->input   = $input;
		$this->started = microtime( true );
	}

	/**
	 * Answers a member message on the current site, as the current user.
	 *
	 * @param string                                   $message The member's message.
	 * @param list<array{role: string, text: string}> $history Earlier messages, oldest first. An
	 *                                                          assistant message may carry its `calls`.
	 * @param array<string, mixed>|null                $context What is open in the editor, already
	 *                                                          checked (EditorContext::from_input()).
	 * @param array<string, mixed>|null                $screen  Where the owner is in the Site Editor,
	 *                                                          checked (SiteEditor::screen_from_input()).
	 * @param list<string>                             $unsaved What the owner has unsaved changes to in
	 *                                                          the editor (`styles`, `site`): a change to
	 *                                                          it waits for their decision (hold()).
	 * @return array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool, edits: list<array<string, mixed>>, calls: list<array<string, mixed>>}
	 *         `error` is true when the agent could not answer (no brand profile, model unavailable).
	 *         `edits` are changes for the editor. `calls` are the abilities run, to store
	 *         with the reply as context: not for the client.
	 */
	public static function respond( string $message, array $history = array(), ?array $context = null, ?array $screen = null, array $unsaved = array() ): array {
		$agent          = new self( trim( mb_substr( sanitize_textarea_field( $message ), 0, self::MAX_MESSAGE_LENGTH ) ) );
		$agent->context = $context;
		$agent->screen  = $screen;
		$agent->unsaved = array_values( array_intersect( array_unique( array_values( self::REFRESH ) ), $unsaved ) );
		// One `turn` row per message: time, model calls, tokens, the cache, the writer (2026-11-11).
		TurnMetrics::start();
		$turn    = $agent->run( $history );
		$metrics = TurnMetrics::finish( $turn, array_map( static fn( array $made ): string => (string) preg_replace( '/^wpab__mody__/', '', (string) $made['name'] ), (array) ( $turn['calls'] ?? array() ) ) );
		if ( '' !== $agent->input ) {
			AgentEvents::log( $agent->input, TurnMetrics::TOOL, null, (string) wp_json_encode( $metrics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $agent->started );
		}
		return $turn;
	}

	/**
	 * The function names the model sees, keyed like SystemPrompt::for_agent() expects.
	 *
	 * @return array<string, string>
	 */
	public static function tool_names(): array {
		$names = array_map( array( Resolver::class, 'ability_name_to_function_name' ), self::ABILITIES );
		// edit_content is not here: only the editor's context offers it (SystemPrompt::for_editor()).
		return $names + array(
			'ask_user' => self::ASK_USER,
			'refuse'   => self::REFUSE,
		);
	}

	/**
	 * The loop: model, tools, model, until it answers, asks or refuses.
	 *
	 * @param list<array{role: string, text: string}> $history Earlier messages.
	 * @return array<string, mixed> The turn.
	 */
	private function run( array $history ): array {
		if ( '' === $this->input ) {
			$this->turn['reply'] = __( 'Tell me what you would like to do.', 'mody_cloud' );
			return $this->turn;
		}

		$editing      = null !== $this->context;
		$system       = SystemPrompt::for_agent( self::tool_names() )
			. ( $editing ? "\n\n" . SystemPrompt::for_editor( $this->context, self::EDIT_CONTENT, self::tool_names()['update_settings'] ?? 'the settings tool', self::tool_names()['open_editor'] ?? 'the open-editor tool' ) : '' )
			. ( null !== $this->screen ? "\n\n" . SystemPrompt::for_screen( $this->screen ) : '' );
		$declarations = self::declarations( $editing );
		$resolver     = new Resolver( ...array_values( self::ABILITIES ) );
		$messages     = self::history_messages( $history, $editing );
		$messages[]   = new UserMessage( array( new MessagePart( $this->input ) ) );

		for ( $step = 1; $step <= self::MAX_STEPS && ! $this->done; $step++ ) {
			$result = wp_ai_client_prompt( $messages )
				->using_system_instruction( $system )
				->using_provider( 'infomaniak' )
				->using_temperature( self::TEMPERATURE )
				->using_max_tokens( self::MAX_TOKENS )
				->using_function_declarations( ...$declarations )
				->generate_text_result();

			if ( is_wp_error( $result ) ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Never the prompt or the token.
				error_log( sprintf( '[mody-platform] agent site %d step %d: %s %s', get_current_blog_id(), $step, $result->get_error_code(), $result->get_error_message() ) );
				return $this->fail(
					$result->get_error_code(),
					$this->executed
						// A tool already ran (a page created, say): asking again could do it twice.
						? __( 'The assistant stopped before it could finish, but part of your request may already be done. Check your site before asking again.', 'mody_cloud' )
						: __( 'The assistant is not available right now. Please try again in a moment.', 'mody_cloud' )
				);
			}

			$answer = $result->toMessage();
			$calls  = self::calls_of( $answer );
			if ( ! $calls ) {
				$text = self::text_of( $answer );
				// A reply that narrates the request ("The owner wants to see other designs. This
				// maps to…") instead of acting or answering: seen in about 1 real run in 20 on
				// 2026-11-08 (M3-09). Or a bare offer ("I can help with that."): "I want to change
				// my logo" got it 5 times in 6 (M2-08). Ask once more; the member never sees either.
				if ( ! $this->renarrated && $step < self::MAX_STEPS && ( self::narrates( $text ) || self::stalls( $text ) ) ) {
					$this->renarrated = true;
					$messages[]       = $answer;
					$messages[]       = new UserMessage( array( new MessagePart( 'That reply described the request instead of handling it. Handle it now: call the right tool, or answer the owner directly as "you".' ) ) );
					continue;
				}
				// "Done" with no tool run in this turn (rule 2): "Change the slogan to …" got "Done — your
				// tagline is now …" once in about 8 real runs (M2-08). Ask once more.
				if ( ! $this->renarrated && $step < self::MAX_STEPS && 0 === $this->executed && self::claims_done( $text ) ) {
					$this->renarrated = true;
					$messages[]       = $answer;
					$messages[]       = new UserMessage( array( new MessagePart( 'You said it is done, but no tool ran in this turn, so nothing changed. Call the tool now, or tell the owner it is not done.' ) ) );
					continue;
				}
				$this->turn['reply'] = $text;
				break;
			}

			// The model's tool calls, then one message per result: the
			// OpenAI-compatible API takes a single function response per message.
			$messages[] = $answer;
			foreach ( $calls as $call ) {
				$response   = $this->handle( $call, $resolver );
				$messages[] = new UserMessage( array( new MessagePart( new FunctionResponse( $call->getId(), $call->getName(), $response ) ) ) );
			}
		}

		if ( '' === $this->turn['reply'] ) {
			$this->turn['reply'] = __( 'I could not finish this request. Please try again, or say it differently.', 'mody_cloud' );
		}
		$this->turn['reply']   = mb_substr( $this->turn['reply'], 0, self::MAX_REPLY_LENGTH );
		$this->turn['actions'] = array_values( array_column( $this->turn['actions'], null, 'url' ) );

		AgentEvents::log( $this->input, null, null, $this->turn['reply'], $this->started );
		return $this->turn + array( 'calls' => $this->calls );
	}

	/**
	 * Whether an open-editor call asks for the document open in the editor:
	 * the same page, or the same template or part.
	 *
	 * @param array<string, mixed> $args The call's arguments.
	 */
	private function opens_what_is_open( array $args ): bool {
		if ( null === $this->context ) {
			return false;
		}
		$target = (string) ( $args['target'] ?? 'page' );
		$open   = $this->context['post_id'];
		if ( 'page' === $target && 'page' === $this->context['post_type'] ) {
			return (int) ( $args['page_id'] ?? 0 ) === (int) $open
				|| ( '' !== (string) ( $args['page_title'] ?? '' ) && 0 === strcasecmp( (string) $args['page_title'], (string) $this->context['title'] ) );
		}
		$types = array(
			'template' => 'wp_template',
			'part'     => 'wp_template_part',
		);
		return isset( $types[ $target ] ) && $types[ $target ] === $this->context['post_type']
			&& get_stylesheet() . '//' . sanitize_title( (string) ( $args['template'] ?? '' ) ) === (string) $open;
	}

	/**
	 * Whether a reply says the request is done ("Done! …", "Done — …").
	 *
	 * @param string $text The model's reply.
	 */
	public static function claims_done( string $text ): bool {
		return (bool) preg_match( '/^\W*done\b/i', trim( $text ) );
	}

	/**
	 * Whether a reply is a bare offer that neither acts nor answers, such as
	 * "I can help with that." or "Sure, I can help you with this!".
	 *
	 * @param string $text The model's reply.
	 */
	public static function stalls( string $text ): bool {
		return (bool) preg_match( '/^\W*(?:sure|of course|certainly|absolutely|yes)?[\s,!.]*i can (?:help|do that|do this)(?: you)?(?: with (?:that|this|it))?[.!]?\s*$/i', trim( $text ) );
	}

	/**
	 * Whether a reply talks about the owner in the third person, as the model's
	 * own reasoning does, instead of to them. Replies address the owner as "you".
	 *
	 * @param string $text The model's reply.
	 */
	public static function narrates( string $text ): bool {
		return (bool) preg_match( '/^\W*the owner\b|\bthe owner (wants|is asking|asks|asked|would like)\b/i', $text );
	}

	/**
	 * Runs one tool call and returns what the model gets back.
	 *
	 * @param FunctionCall $call     The call.
	 * @param Resolver     $resolver Core's resolver, limited to self::ABILITIES.
	 * @return mixed The function response.
	 */
	private function handle( FunctionCall $call, Resolver $resolver ) {
		$name    = (string) $call->getName();
		$args    = is_array( $call->getArgs() ) ? $call->getArgs() : array();
		$started = microtime( true );

		// The model sometimes gives these tools the abilities' prefix (wpab__mody__edit_content,
		// 5 calls in 6 on 2026-11-08). They are the same tools.
		$plain = (string) preg_replace( '/^wpab__mody__/', '', $name );
		if ( in_array( $plain, array( self::ASK_USER, self::REFUSE, self::EDIT_CONTENT ), true ) ) {
			$name = $plain;
		}

		if ( self::ASK_USER === $name ) {
			$question = sanitize_text_field( (string) ( $args['question'] ?? '' ) );
			$choices  = array_map( static fn( $choice ) => is_scalar( $choice ) ? sanitize_text_field( (string) $choice ) : '', (array) ( $args['choices'] ?? array() ) );
			if ( '' !== $question ) {
				$this->turn['reply']   = $question;
				$this->turn['choices'] = array_slice( array_values( array_filter( $choices, 'strlen' ) ), 0, 4 );
			}
			$this->done = true;
			AgentEvents::log( $this->input, $name, $args, $question, $started );
			return array( 'shown' => true );
		}

		if ( self::REFUSE === $name ) {
			$reason                = sanitize_text_field( (string) ( $args['reason'] ?? '' ) );
			$this->turn['reply']   = '' !== $reason ? $reason : __( 'I cannot do that here. I can create and publish pages, change the site\'s styles and settings, and open the editor for you.', 'mody_cloud' );
			$this->turn['refused'] = true;
			$this->done            = true;
			AgentEvents::log( $this->input, $name, $args, 'refused: ' . $this->turn['reply'], $started );
			return array( 'shown' => true );
		}

		if ( self::EDIT_CONTENT === $name && null !== $this->context ) {
			return $this->edit_content( $call, $args, $started );
		}

		$ability = $resolver->is_ability_call( $call ) ? Resolver::function_name_to_ability_name( $name ) : '';
		if ( ! in_array( $ability, self::ABILITIES, true ) ) {
			AgentEvents::log( $this->input, $name, $args, 'refused: unknown tool', $started );
			return array(
				'error' => sprintf( 'There is no tool named "%s". Nothing was run. Use only the tools you were given.', $name ),
				'code'  => 'mody_unknown_tool',
			);
		}

		if ( $this->executed >= self::MAX_TOOL_CALLS ) {
			AgentEvents::log( $this->input, $ability, $args, 'refused: tool call limit', $started );
			return array(
				'error' => 'Tool call limit reached for this message. Nothing was run. Tell the owner what was done so far.',
				'code'  => 'mody_tool_limit',
			);
		}

		if ( 'mody/publish-page' === $ability && $this->publish_failed ) {
			AgentEvents::log( $this->input, $ability, $args, 'refused: publish failed earlier in this turn', $started );
			return array(
				'error' => sprintf( 'The page asked for could not be published, so no other page is published in this turn. Nothing was run. Call %s and ask the owner which page to publish, with the drafts\' titles as choices.', self::ASK_USER ),
				'code'  => 'mody_publish_needs_answer',
			);
		}

		// Opening what is already open (M3-09). Probed 2026-11-08: "add a short FAQ at the end of
		// this page" opened the open page instead of editing it 1 to 2 times in 6, despite rule 11 (rule 10 until 2026-11-11).
		if ( 'mody/open-editor' === $ability && $this->opens_what_is_open( (array) $args ) ) {
			AgentEvents::log( $this->input, $ability, $args, 'refused: already open', $started );
			return array(
				'error' => sprintf( 'This is already open in the editor, so nothing was opened. To change it, call %s with the owner\'s instruction.', self::EDIT_CONTENT ),
				'code'  => 'mody_already_open',
			);
		}

		// The owner has unsaved changes in the editor to what this changes (decided by the
		// maintainer on 2026-11-09): the call waits, as made, for their decision in the chat.
		if ( isset( self::REFRESH[ $ability ] ) && in_array( self::REFRESH[ $ability ], $this->unsaved, true ) ) {
			return $this->hold( $call, $ability, $args, $started );
		}

		// Settings that need the owner's confirmation, and a menu that loses an item (decided by the maintainer on 2026-11-11):
		// the call waits, as made, for Apply or Cancel in the chat. The model cannot confirm.
		if ( ( 'mody/update-settings' === $ability && Settings::to_confirm( $args ) ) || ( 'mody/update-menu' === $ability && Menu::to_confirm( $args ) ) ) {
			return $this->hold( $call, $ability, $args, $started, 'confirm' );
		}

		++$this->executed;
		if ( isset( self::doing()[ $ability ] ) ) {
			self::progress( self::doing()[ $ability ] );
		}
		$response = $resolver->execute_ability( $call )->getResponse();
		AgentEvents::log( $this->input, $ability, $args, (string) wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $started );

		// A schema error leaves the target unchanged: the model may fix its arguments.
		if ( 'mody/publish-page' === $ability && is_array( $response ) && isset( $response['error'] ) && 'ability_invalid_input' !== ( $response['code'] ?? '' ) ) {
			$this->publish_failed = true;
		}
		if ( isset( self::REFRESH[ $ability ] ) && is_array( $response ) && ! isset( $response['error'] ) && ! in_array( self::REFRESH[ $ability ], $this->turn['refresh'], true ) ) {
			$this->turn['refresh'][] = self::REFRESH[ $ability ];
		}

		$response      = $this->take_link( $response );
		$json          = wp_json_encode( $response );
		$this->calls[] = array(
			'id'     => (string) $call->getId(),
			'name'   => $name,
			'args'   => $args,
			'result' => is_string( $json ) && strlen( $json ) <= self::MAX_KEPT_RESULT ? $response : array_filter( (array) $response, 'is_scalar' ),
		);
		return $response;
	}

	/**
	 * Sets who receives the progress lines (null: nobody).
	 *
	 * @param callable|null $receiver Called with one line of text.
	 */
	public static function on_progress( ?callable $receiver ): void {
		self::$progress = $receiver;
	}

	/**
	 * Says what the agent is doing now, when someone listens.
	 *
	 * @param string $text One line for the owner.
	 */
	private static function progress( string $text ): void {
		if ( self::$progress ) {
			try {
				( self::$progress )( $text );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Progress never stops the work.
			}
		}
	}

	/**
	 * Runs edit_content: asks the page writer to change the selected block or
	 * the whole content, and hands the validated result to the editor. One
	 * edit per message; nothing is saved here.
	 *
	 * @param FunctionCall         $call    The call.
	 * @param array<string, mixed> $args    instruction, scope (selection or content).
	 * @param float                $started When the call started (microtime).
	 * @return array<string, mixed> What the model gets back.
	 */
	private function edit_content( FunctionCall $call, array $args, float $started ): array {
		$instruction = sanitize_textarea_field( (string) ( $args['instruction'] ?? '' ) );
		$selected    = $this->context['selected'];
		$scope       = (string) ( $args['scope'] ?? '' );
		$scope       = in_array( $scope, array( 'selection', 'content' ), true ) ? $scope : ( $selected ? 'selection' : 'content' );

		$error = null;
		if ( $this->edited ) {
			$error = array( 'mody_one_edit', 'One change per message: the editor already shows a change. Tell the owner what changed.' );
		} elseif ( $this->executed >= self::MAX_TOOL_CALLS ) {
			$error = array( 'mody_tool_limit', 'Tool call limit reached for this message. Tell the owner what was done so far.' );
		} elseif ( '' === trim( $instruction ) ) {
			$error = array( 'mody_empty_prompt', 'Give the owner\'s instruction.' );
		} elseif ( 'selection' === $scope && ! $selected ) {
			$error = array( 'mody_no_selection', 'No block is selected. Use scope content, or ask the owner to select the block.' );
		} elseif ( 'content' === $scope && ! empty( $this->context['too_long'] ) && in_array( $this->context['post_type'], EditorContext::TEMPLATE_TYPES, true ) ) {
			$error = array( 'mody_too_long', 'This template is too long to change all at once. Ask the owner to select the block to change (or the section that holds it), then ask again.' );
		}
		if ( $error ) {
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, 'refused: ' . $error[0], $started );
			return array(
				'error' => $error[1] . ' Nothing was changed.',
				'code'  => $error[0],
			);
		}

		$fragment = 'selection' === $scope;
		$template = in_array( $this->context['post_type'], EditorContext::TEMPLATE_TYPES, true );
		$numbers  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $args['sections'] ?? array() ) ), static fn( int $n ): bool => $n > 0 ) ) );
		// A long page, or chosen sections: section by section (decided 2026-11-11).
		if ( ! $fragment && ! $template && ( ! empty( $this->context['too_long'] ) || $numbers ) ) {
			return $this->edit_sections( $call, $args, $started, $instruction, $numbers );
		}

		++$this->executed;
		self::progress( $fragment ? __( 'Changing the selected block…', 'mody_cloud' ) : __( 'Changing the page…', 'mody_cloud' ) );
		$markup   = PageGenerator::edit_markup( get_current_blog_id(), $fragment ? $selected['markup'] : (string) $this->context['content'], $instruction, $fragment, $template );
		if ( is_wp_error( $markup ) ) {
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, $markup->get_error_code() . ': ' . $markup->get_error_message(), $started );
			return array(
				'error' => $markup->get_error_message() . ' Nothing was changed.',
				'code'  => $markup->get_error_code(),
			);
		}

		$this->edited          = true;
		$this->turn['edits'][] = array(
			'post_id'   => $this->context['post_id'], // A number, or a template's ID (theme//header).
			'client_id' => $fragment ? $selected['client_id'] : null,
			'markup'    => $markup,
		);
		$response = array(
			'changed' => $fragment ? 'the selected block' : 'the whole ' . str_replace( array( 'wp_template_part', 'wp_template' ), array( 'template part', 'template' ), $this->context['post_type'] ),
			'shown'   => 'The change is shown in the editor now. The owner can undo it, and it is saved only when they save.',
		);
		AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, (string) wp_json_encode( $response + array( 'bytes' => strlen( $markup ) ) ), $started );
		$this->calls[] = array(
			'id'     => (string) $call->getId(),
			'name'   => self::EDIT_CONTENT,
			'args'   => $args,
			'result' => $response,
		);
		return $response;
	}

	/**
	 * Changes the open page section by section (decided by the maintainer
	 * on 2026-11-11): a page too long to rewrite at once, or the sections
	 * the agent chose. Each section goes to the writer with the whole page
	 * as a compact view; the others stay exactly as they are. The chat shows
	 * which section is being changed. Sections that fail are left as they
	 * were and reported; the rest are shown in the editor as one change.
	 *
	 * @param FunctionCall         $call        The call.
	 * @param array<string, mixed> $args        The call's arguments.
	 * @param float                $started     When the call started.
	 * @param string               $instruction The owner's instruction.
	 * @param int[]                $numbers     The sections to change, from 1; empty for all.
	 * @return array<string, mixed> What the model gets back.
	 */
	private function edit_sections( FunctionCall $call, array $args, float $started, string $instruction, array $numbers ): array {
		$content  = (string) $this->context['content'];
		$sections = PageOutline::sections( $content );
		$total    = count( $sections );
		$targets  = $numbers ? array_values( array_filter( $numbers, static fn( int $n ): bool => $n <= $total ) ) : range( 1, max( 1, $total ) );
		if ( $total && ( ! $numbers || count( $targets ) === count( $numbers ) ) && count( $targets ) > self::MAX_SECTIONS ) {
			// One message never fans out to more than MAX_SECTIONS model calls (review, 2026-11-11).
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, 'refused: mody_too_many_sections', $started );
			return array(
				'error' => sprintf( 'This change would go through %1$d sections; at most %2$d are changed in one message. Nothing was changed. Ask the owner which sections to change, and pass them as sections.', count( $targets ), self::MAX_SECTIONS ),
				'code'  => 'mody_too_many_sections',
			);
		}
		if ( ! $total || ( $numbers && count( $targets ) !== count( $numbers ) ) ) {
			$error = sprintf( 'The page has %d sections; there is no section %s. Nothing was changed.', $total, implode( ', ', array_diff( $numbers, $targets ) ) );
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, 'refused: mody_no_section', $started );
			return array(
				'error' => $error,
				'code'  => 'mody_no_section',
			);
		}

		++$this->executed;
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 900 ); // Each batch of sections is a model call; never unbounded.
		}
		$failed = array();
		// Classic HTML outside blocks stays as it is, and a section too large to return whole is
		// left for the owner to narrow down (review, 2026-11-11): neither costs a model call.
		foreach ( $targets as $index => $number ) {
			$section = $sections[ $number - 1 ];
			if ( ! $section['block'] ) {
				unset( $targets[ $index ] );
				if ( $numbers ) {
					$failed[ $number ] = 'this part is classic HTML outside blocks; it stays as it is.';
				}
			} elseif ( strlen( $section['markup'] ) > PageGenerator::MAX_SECTION ) {
				unset( $targets[ $index ] );
				$failed[ $number ] = 'this section is too large to change at once; ask the owner to select the block to change in it.';
			}
		}
		$targets = array_values( $targets );
		$page  = PageOutline::compact( $content );
		$count = count( $targets );
		$first = $count ? $sections[ $targets[0] - 1 ]['label'] : '';
		self::progress(
			1 === $count
				/* translators: 1: section number, 2: its heading. */
				? sprintf( __( 'Changing section %1$d: %2$s…', 'mody_cloud' ), $targets[0], $first )
				/* translators: 1: how many sections, 2: how many at a time. */
				: sprintf( __( 'Changing %1$d sections, %2$d at a time…', 'mody_cloud' ), $count, min( $count, PageGenerator::PARALLEL ) )
		);
		$finished = 0;
		$results  = ! $count ? array() : PageGenerator::edit_sections(
			get_current_blog_id(),
			array_combine( $targets, array_map( static fn( int $n ): string => $sections[ $n - 1 ]['markup'], $targets ) ),
			$instruction,
			$page,
			$total,
			static function ( int $number ) use ( &$finished, $count, $sections ): void {
				++$finished;
				if ( $count > 1 && $finished < $count ) {
					/* translators: 1: sections done, 2: all of them, 3: the last one's heading. */
					self::progress( sprintf( __( '%1$d of %2$d sections done (last: %3$s)…', 'mody_cloud' ), $finished, $count, $sections[ $number - 1 ]['label'] ) );
				}
			}
		);
		$changed = array();
		foreach ( $targets as $number ) {
			$markup = $results[ $number ] ?? new WP_Error( 'mody_edit_failed', 'No answer.' );
			if ( is_wp_error( $markup ) ) {
				$failed[ $number ] = $markup->get_error_message();
			} elseif ( trim( $markup ) !== trim( $sections[ $number - 1 ]['markup'] ) ) {
				$changed[ $number - 1 ] = $markup;
			}
		}

		$result = array(
			'sections' => $total,
			'changed'  => array_map( static fn( int $index ): int => $index + 1, array_keys( $changed ) ),
		);
		if ( $failed ) {
			$result['failed'] = array_map( static fn( int $n, string $why ): string => 'section ' . $n . ': ' . $why, array_keys( $failed ), $failed );
		}
		if ( ! $changed ) {
			$response = $result + array(
				'error' => $failed ? 'The change could not be made. Nothing was changed.' : 'No section needed a change for this instruction. Nothing was changed.',
				'code'  => $failed ? 'mody_edit_failed' : 'mody_unchanged',
			);
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, (string) wp_json_encode( $response ), $started );
			return $response;
		}

		$markup = PageOutline::splice( $sections, $changed );
		if ( '' === trim( $markup ) ) {
			// Every section removed: the editor would show nothing (review, 2026-11-11).
			$response = $result + array(
				'error' => 'This would remove every section of the page. Nothing was changed. To remove a page, the owner moves it to the trash in the editor.',
				'code'  => 'mody_empty_page',
			);
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, (string) wp_json_encode( $response ), $started );
			return $response;
		}
		$this->edited          = true;
		$this->turn['edits'][] = array(
			'post_id'   => $this->context['post_id'],
			'client_id' => null,
			'markup'    => $markup,
		);
		$response = $result + array(
			'shown' => 'The change is shown in the editor now. The owner can undo it, and it is saved only when they save.' . ( $failed ? ' The failed sections were left as they were: tell the owner which.' : '' ),
		);
		AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, (string) wp_json_encode( $response + array( 'bytes' => strlen( $markup ) ) ), $started );
		$this->calls[] = array(
			'id'     => (string) $call->getId(),
			'name'   => self::EDIT_CONTENT,
			'args'   => $args,
			'result' => $response,
		);
		return $response;
	}

	/**
	 * Holds an ability call instead of running it: the owner has unsaved
	 * changes in the editor to what it changes, which the change would hide
	 * or a later Save would undo. The call is checked (input, permissions)
	 * and kept on the server for this user and site; the turn ends with the
	 * question and a `decision` for the chat, which shows three choices
	 * (decision_labels()). decide() runs the call exactly as it was made.
	 *
	 * @param FunctionCall         $call    The call.
	 * @param string               $ability The ability's name.
	 * @param array<string, mixed> $args    The call's arguments.
	 * @param float                $started When the call started (microtime).
	 * @return array<string, mixed> What the model gets back.
	 */
	private function hold( FunctionCall $call, string $ability, array $args, float $started, string $kind = 'unsaved' ): array {
		if ( isset( $this->turn['decision'] ) ) {
			AgentEvents::log( $this->input, $ability, $args, 'refused: a change already waits for the owner', $started );
			return array(
				'error' => 'Another change already waits for the owner\'s decision. Nothing was run.',
				'code'  => 'mody_change_waiting',
			);
		}

		// What execute() checks first, so a held call can run as it is.
		$object = wp_get_ability( $ability );
		$input  = $object ? $object->normalize_input( $args ? $args : null ) : null;
		$valid  = $object ? $object->validate_input( $input ) : new WP_Error( 'ability_not_found', 'Unknown ability.' );
		if ( true === $valid ) {
			$valid = $object->check_permissions( $input );
		}
		if ( true !== $valid ) {
			$error    = is_wp_error( $valid ) ? $valid : new WP_Error( 'ability_invalid_permissions', 'The owner may not make this change.' );
			$response = array(
				'error' => $error->get_error_message(),
				'code'  => $error->get_error_code(),
			);
			AgentEvents::log( $this->input, $ability, $args, (string) wp_json_encode( $response ), $started );
			return $response;
		}

		$record = self::REFRESH[ $ability ];
		$held   = array(
			'id'      => wp_generate_uuid4(),
			'ability' => $ability,
			'args'    => $args,
			'record'  => $record,
			'kind'    => $kind,
			'name'    => (string) $call->getName(),
			'message' => $this->input,
			'time'    => time(),
		);
		update_user_option( get_current_user_id(), self::HELD_OPTION, $held );

		++$this->executed;
		$this->done             = true;
		$this->turn['decision'] = array(
			'id'      => $held['id'],
			'record'  => $record,
			'choices' => self::choices( $kind ),
		);
		$this->turn['reply']    = 'confirm' === $kind ? sprintf(
			/* translators: %s: the change, such as "search_engines_visible false". */
			__( 'This change needs your confirmation before I make it: %s. Shall I apply it?', 'mody_cloud' ),
			self::describe( $ability, $args )
		) : sprintf(
			/* translators: 1: "the styles" or "the site settings", 2: the change, such as "primary color #2e8b57". */
			__( 'You have unsaved changes to %1$s in the editor, so I have not made this change yet: %2$s. What should I do with your changes first?', 'mody_cloud' ),
			'site' === $record ? __( 'the site settings', 'mody_cloud' ) : ( 'navigation' === $record ? __( 'the menu', 'mody_cloud' ) : __( 'the styles', 'mody_cloud' ) ),
			self::describe( $ability, $args )
		);

		$response      = array(
			'held'    => true,
			'message' => 'confirm' === $kind
				? 'Not done yet: this change needs the owner\'s confirmation. The chat shows them Apply and Cancel, and runs this call as it is if they apply it. Do not say it is done.'
				: 'Not done yet: the owner has unsaved changes to this in the editor. The chat asks them to save theirs first, discard them, or cancel, and runs this call as it is if they choose to.',
		);
		$this->calls[] = array(
			'id'     => (string) $call->getId(),
			'name'   => $held['name'],
			'args'   => $args,
			'result' => $response,
		);
		AgentEvents::log( $this->input, $ability, $args, 'confirm' === $kind ? 'held: needs the owner\'s confirmation' : 'held: unsaved ' . $record . ' in the editor', $started );
		return $response;
	}

	/**
	 * A short description of a held change, for the owner.
	 *
	 * @param string               $ability The ability's name.
	 * @param array<string, mixed> $args    The call's arguments.
	 */
	private static function describe( string $ability, array $args ): string {
		$parts = array();
		if ( 'mody/update-design' === $ability ) {
			if ( isset( $args['preset'] ) ) {
				/* translators: %s: a design preset, such as "Forest". */
				$parts[] = sprintf( __( 'the %s preset', 'mody_cloud' ), ucfirst( (string) $args['preset'] ) );
			}
			foreach ( array( 'primary', 'secondary', 'accent' ) as $slug ) {
				if ( isset( $args[ $slug ] ) ) {
					/* translators: 1: primary, secondary or accent, 2: a hex color. */
					$parts[] = sprintf( __( '%1$s color %2$s', 'mody_cloud' ), $slug, strtolower( (string) $args[ $slug ] ) );
				}
			}
		} elseif ( 'mody/update-settings' === $ability ) {
			foreach ( (array) ( $args['changes'] ?? array() ) as $name => $value ) {
				$shown   = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : ( is_array( $value ) ? implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) : (string) $value );
				/* translators: 1: a setting's name, 2: its new value. */
				$parts[] = sprintf( __( '%1$s → "%2$s"', 'mody_cloud' ), sanitize_key( (string) $name ), mb_strimwidth( sanitize_text_field( $shown ), 0, 120, '…' ) );
			}
		} elseif ( 'mody/update-menu' === $ability ) {
			$labels = array();
			foreach ( (array) ( $args['items'] ?? array() ) as $item ) {
				$labels[] = is_array( $item ) ? (string) ( $item['label'] ?? $item['page'] ?? '' ) : (string) $item;
			}
			/* translators: %s: the menu's items, such as "Home, About, Contact". */
			$parts[] = sprintf( __( 'the menu becomes: %s', 'mody_cloud' ), mb_strimwidth( sanitize_text_field( implode( ', ', array_filter( $labels ) ) ), 0, 200, '…' ) );
		} elseif ( 'mody/update-site-settings' === $ability ) {
			foreach ( array( 'title', 'tagline', 'mission', 'tone' ) as $field ) {
				if ( isset( $args[ $field ] ) ) {
					/* translators: 1: title, tagline, mission or tone, 2: the new value. */
					$parts[] = sprintf( __( '%1$s "%2$s"', 'mody_cloud' ), $field, mb_strimwidth( sanitize_text_field( (string) $args[ $field ] ), 0, 120, '…' ) );
				}
			}
		}
		return $parts ? implode( ', ', $parts ) : ( 'mody/update-settings' === $ability ? __( 'a change to the site\'s settings', 'mody_cloud' ) : __( 'a change to the site\'s styles', 'mody_cloud' ) );
	}

	/**
	 * The owner's three choices for a held change, as the chat shows them and
	 * as the conversation keeps them. `save` and `discard` happen in the
	 * editor first (the chat bubble), then the change runs; `cancel` drops it.
	 *
	 * @return array{save: string, discard: string, cancel: string}
	 */
	public static function decision_labels(): array {
		return array(
			'save'    => __( 'Save my changes, then apply', 'mody_cloud' ),
			'discard' => __( 'Discard my changes, then apply', 'mody_cloud' ),
			'apply'   => __( 'Apply', 'mody_cloud' ),
			'cancel'  => __( 'Cancel', 'mody_cloud' ),
		);
	}

	/**
	 * The choices a held change offers: `unsaved` (the owner has unsaved
	 * changes in the editor) or `confirm` (a setting that needs their
	 * confirmation, 2026-11-11).
	 *
	 * @param string $kind unsaved or confirm.
	 * @return string[]
	 */
	public static function choices( string $kind ): array {
		return 'confirm' === $kind ? array( 'apply', 'cancel' ) : array( 'save', 'discard', 'cancel' );
	}

	/**
	 * The owner's decision on the held change: runs the call as it was made
	 * (after the editor saved or discarded their changes), or drops it.
	 *
	 * @param string $id     The held change's ID, from the turn's `decision`.
	 * @param string $choice save, discard or cancel.
	 * @return array<string, mixed>|WP_Error The turn, like respond()'s, or an error when
	 *                                       nothing waits under this ID any more.
	 */
	public static function decide( string $id, string $choice ): array|WP_Error {
		$held = self::held();
		if ( ! $held || ! hash_equals( (string) $held['id'], $id ) || ! in_array( $choice, self::choices( (string) ( $held['kind'] ?? 'unsaved' ) ), true ) ) {
			return new WP_Error( 'mody_decision_expired', __( 'This change is no longer waiting. Ask me again.', 'mody_cloud' ), array( 'status' => 410 ) );
		}
		self::forget_held();

		$agent = new self( (string) $held['message'] );
		$label = $choice . ': ';
		if ( 'cancel' === $choice ) {
			$agent->turn['reply'] = __( 'Cancelled: nothing was changed.', 'mody_cloud' );
			AgentEvents::log( $agent->input, (string) $held['ability'], $held['args'], $label . 'nothing run', $agent->started );
			return $agent->turn + array( 'calls' => array() );
		}

		$object = wp_get_ability( (string) $held['ability'] );
		$result = $object ? $object->execute( $held['args'] ? $held['args'] : null ) : new WP_Error( 'ability_not_found', 'Unknown ability.' );
		AgentEvents::log( $agent->input, (string) $held['ability'], $held['args'], $label . (string) wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_message() ) : $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $agent->started );
		if ( is_wp_error( $result ) ) {
			$response             = array(
				'error' => $result->get_error_message(),
				'code'  => $result->get_error_code(),
			);
			$agent->turn['error'] = true;
			/* translators: %s: why the change failed. */
			$agent->turn['reply'] = sprintf( __( 'The change could not be made: %s', 'mody_cloud' ), $result->get_error_message() );
		} else {
			$response                 = $agent->take_link( $result );
			$agent->turn['refresh'][] = (string) $held['record'];
			$agent->turn['reply']     = __( 'Done: the change is made.', 'mody_cloud' );
		}
		$agent->turn['actions'] = array_values( array_column( $agent->turn['actions'], null, 'url' ) );
		return $agent->turn + array(
			'calls' => array(
				array(
					'id'     => 'decided_' . substr( (string) $held['id'], 0, 8 ),
					'name'   => (string) $held['name'],
					'args'   => $held['args'],
					'result' => is_string( wp_json_encode( $response ) ) && strlen( (string) wp_json_encode( $response ) ) <= self::MAX_KEPT_RESULT ? $response : array_filter( (array) $response, 'is_scalar' ),
				),
			),
		);
	}

	/**
	 * The change waiting for the current user's decision on this site, or
	 * null (none, or older than HELD_TTL).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function held(): ?array {
		$held = get_user_option( self::HELD_OPTION );
		if ( ! is_array( $held ) || empty( $held['id'] ) || time() - (int) ( $held['time'] ?? 0 ) > self::HELD_TTL ) {
			return null;
		}
		return $held;
	}

	/**
	 * Drops the held change: decided, or the owner sent another message.
	 */
	public static function forget_held(): void {
		delete_user_option( get_current_user_id(), self::HELD_OPTION );
	}

	/**
	 * Turns this site's links in a tool result into chat buttons, and removes
	 * them from what the model sees, so it cannot paste or alter them.
	 *
	 * A result can carry `url`, labelled by `label`, and `edit_url`, a Site
	 * Editor link labelled with the page title. Only links to this site's
	 * wp-admin or front end (a published page) become buttons.
	 *
	 * @param mixed $response The ability's result.
	 * @return mixed The result for the model.
	 */
	private function take_link( $response ) {
		if ( ! is_array( $response ) || isset( $response['error'] ) ) {
			return $response;
		}

		/* translators: %s: page title. */
		$edit_label = sprintf( __( 'Open "%s" in the Site Editor', 'mody_cloud' ), (string) ( $response['title'] ?? '' ) );
		$links      = array(
			'url'      => (string) ( $response['label'] ?? $edit_label ),
			'edit_url' => $edit_label,
		);

		// Screens the Site Editor switches to in place (M3-09, decided 2026-11-08).
		$navigate = ! empty( $response['navigate'] );
		unset( $response['navigate'] );

		$shown  = 0;
		$switch = false;
		foreach ( $links as $key => $label ) {
			$url = (string) ( $response[ $key ] ?? '' );
			if ( '' === $url || ! ( str_starts_with( $url, admin_url() ) || str_starts_with( $url, home_url( '/' ) ) ) ) {
				continue;
			}
			// Admin links (the Site Editor) open in the same tab, with the chat bubble open
			// there (decided 2026-11-07); front-end links open in a new tab.
			$admin  = str_starts_with( $url, admin_url() );
			$action = array(
				'label'  => sanitize_text_field( $label ),
				'url'    => esc_url_raw( $admin ? EditorBubble::open_url( $url ) : $url ),
				'editor' => $admin,
			);
			// In the Site Editor, the bubble opens this screen in place instead of showing the
			// button: the first Site Editor link of a result that asks for it (`url` for
			// mody/open-editor and mody/update-design, `edit_url` for a new page).
			if ( $navigate && ! $switch && str_starts_with( $url, admin_url( 'site-editor.php' ) ) ) {
				$action['navigate'] = true;
				$action['place']    = sanitize_text_field( (string) ( $response['place'] ?? '' ) );
				$switch             = true;
			}
			$this->turn['actions'][] = $action;
			unset( $response[ $key ] );
			++$shown;
		}

		if ( $switch ) {
			$response['link'] = 'Opened for the owner: in the Site Editor the screen switches now, elsewhere they get a button below your reply.';
		} elseif ( $shown ) {
			$response['link'] = 1 === $shown ? 'Shown to the owner as a button below your reply.' : 'Shown to the owner as buttons below your reply.';
		}
		return $response;
	}

	/**
	 * Ends the turn with an error reply, logged as the request's result.
	 *
	 * @param string $code  Error code.
	 * @param string $reply What the member reads.
	 * @return array<string, mixed> The turn.
	 */
	private function fail( string $code, string $reply ): array {
		$this->turn['reply']   = $reply;
		$this->turn['error']   = true;
		$this->turn['actions'] = array_values( array_column( $this->turn['actions'], null, 'url' ) );
		AgentEvents::log( $this->input, null, null, $code . ': ' . $reply, $this->started );
		return $this->turn + array( 'calls' => $this->calls );
	}

	/**
	 * The tools: the abilities, declared the way core's `using_abilities()`
	 * does it, plus ask_user and refuse_request. One list, because
	 * `using_function_declarations()` replaces the previous one.
	 *
	 * @return list<FunctionDeclaration>
	 */
	private static function declarations( bool $editing = false ): array {
		$declarations = array();
		foreach ( self::ABILITIES as $name ) {
			$ability = wp_get_ability( $name );
			if ( $ability ) {
				$schema         = wp_prepare_json_schema_for_client( $ability->get_input_schema() );
				$declarations[] = new FunctionDeclaration( Resolver::ability_name_to_function_name( $name ), $ability->get_description(), $schema ? $schema : null );
			}
		}

		$declarations[] = new FunctionDeclaration(
			self::ASK_USER,
			'Asks the site owner one follow-up question, with optional answer choices shown as buttons. Ends your turn: the owner answers in the next message.',
			array(
				'type'       => 'object',
				'properties' => array(
					'question' => array(
						'type'        => 'string',
						'description' => 'The question, in the owner\'s language.',
					),
					'choices'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'maxItems'    => 4,
						'description' => 'Up to four short answers.',
					),
				),
				'required'   => array( 'question' ),
			)
		);
		$declarations[] = new FunctionDeclaration(
			self::REFUSE,
			'Declines a request that no tool covers or that is not allowed: deleting anything, the database, files, code, users, passwords, the admin email, plugins, other sites. Ends your turn.',
			array(
				'type'       => 'object',
				'properties' => array(
					'reason' => array(
						'type'        => 'string',
						'description' => 'A short explanation for the owner, in their language, with what you can do instead.',
					),
				),
				'required'   => array( 'reason' ),
			)
		);

		if ( $editing ) {
			$declarations[] = new FunctionDeclaration(
				self::EDIT_CONTENT,
				'Changes what the owner has open in the editor, following their instruction: its text, its blocks and their order. The change is shown in the editor, unsaved.',
				array(
					'type'       => 'object',
					'properties' => array(
						'instruction' => array( 'type' => 'string' ),
						'scope'       => array(
							'type' => 'string',
							'enum' => array( 'selection', 'content' ),
						),
						'sections'    => array(
							'type'  => 'array',
							'items' => array( 'type' => 'integer' ),
						),
					),
					'required'   => array( 'instruction' ),
				)
			);
		}

		return $declarations;
	}

	/**
	 * Earlier chat messages as AI Client messages: the last HISTORY_TURNS,
	 * starting with a member message.
	 *
	 * A reply's ability calls come first, as the model made them, with the
	 * results it got. With the reply text alone, the model saw earlier turns
	 * that did things without a tool call, and answered "publish it" with
	 * "Done" and no call (2026-11-06, documentation/ai-engine.md).
	 *
	 * An edit_content call is left out when the editor is not open now: the
	 * model would see a call to a function it was not given.
	 *
	 * @param list<array{role: string, text: string, calls?: list<array{id: string, name: string, args: array, result: mixed}>}> $history Earlier messages.
	 * @param bool $editing Whether edit_content is offered in this request.
	 * @return list<Message>
	 */
	private static function history_messages( array $history, bool $editing = false ): array {
		$messages = array();
		foreach ( array_slice( $history, -self::HISTORY_TURNS ) as $entry ) {
			$text = trim( (string) ( $entry['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( 'user' === ( $entry['role'] ?? '' ) ) {
				$messages[] = new UserMessage( array( new MessagePart( $text ) ) );
				continue;
			}
			if ( ! $messages ) {
				continue;
			}
			foreach ( (array) ( $entry['calls'] ?? array() ) as $i => $call ) {
				$name = is_array( $call ) ? (string) ( $call['name'] ?? '' ) : '';
				if ( '' === $name || ( self::EDIT_CONTENT === $name && ! $editing ) ) {
					continue;
				}
				$id         = '' !== (string) ( $call['id'] ?? '' ) ? (string) $call['id'] : 'call_' . count( $messages ) . '_' . $i;
				$args       = is_array( $call['args'] ?? null ) && $call['args'] ? $call['args'] : new \stdClass();
				$messages[] = new ModelMessage( array( new MessagePart( new FunctionCall( $id, $name, $args ) ) ) );
				$messages[] = new UserMessage( array( new MessagePart( new FunctionResponse( $id, $name, $call['result'] ?? null ) ) ) );
			}
			$messages[] = new ModelMessage( array( new MessagePart( $text ) ) );
		}
		return $messages;
	}

	/**
	 * The visible text of a model message (thoughts left out).
	 *
	 * @param Message $message The model's message.
	 */
	private static function text_of( Message $message ): string {
		$text = '';
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isText() && $part->getChannel()->isContent() ) {
				$text .= (string) $part->getText();
			}
		}
		return trim( $text );
	}

	/**
	 * The function calls in a model message.
	 *
	 * @param Message $message The model's message.
	 * @return list<FunctionCall>
	 */
	private static function calls_of( Message $message ): array {
		$calls = array();
		foreach ( $message->getParts() as $part ) {
			if ( $part->getType()->isFunctionCall() && $part->getFunctionCall() instanceof FunctionCall ) {
				$calls[] = $part->getFunctionCall();
			}
		}
		return $calls;
	}
}
