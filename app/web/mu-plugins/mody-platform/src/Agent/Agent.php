<?php
/**
 * The in-site agent: a tool-calling loop over the WordPress AI Client.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

use Mody\Platform\AI\PageGenerator;
use Mody\Platform\AI\SystemPrompt;
use Mody\Platform\Brand\BrandProfile;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Tools\DTO\FunctionCall;
use WordPress\AiClient\Tools\DTO\FunctionDeclaration;
use WordPress\AiClient\Tools\DTO\FunctionResponse;
use WP_AI_Client_Ability_Function_Resolver as Resolver;

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
		'update_site_settings' => 'mody/update-site-settings',
	);

	/** Plain function: shows a follow-up question in the chat. Acts on nothing. */
	public const ASK_USER = 'ask_user';

	/** Plain function: declines the request, so refusals are logged as such. */
	public const REFUSE = 'refuse_request';

	/**
	 * Plain function, offered only with an editor context (M3-06): changes
	 * what is open in the editor. The result goes back to the editor, unsaved.
	 */
	public const EDIT_CONTENT = 'edit_content';

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
	 *
	 * @var array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool, edits: list<array{post_id: int, client_id: ?string, markup: string}>}
	 */
	private array $turn = array(
		'reply'   => '',
		'actions' => array(),
		'choices' => array(),
		'refused' => false,
		'error'   => false,
		'edits'   => array(),
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
	 * @return array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool, edits: list<array<string, mixed>>, calls: list<array<string, mixed>>}
	 *         `error` is true when the agent could not answer (no brand profile, model unavailable).
	 *         `edits` are changes for the editor. `calls` are the abilities run, to store
	 *         with the reply as context: not for the client.
	 */
	public static function respond( string $message, array $history = array(), ?array $context = null ): array {
		$agent          = new self( trim( mb_substr( sanitize_textarea_field( $message ), 0, self::MAX_MESSAGE_LENGTH ) ) );
		$agent->context = $context;
		return $agent->run( $history );
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

		$profile = BrandProfile::for_site( get_current_blog_id() );
		if ( null === $profile ) {
			return $this->fail( 'mody_no_brand_profile', __( 'This site has no brand profile yet. Complete the brand wizard first, then I can help you.', 'mody_cloud' ) );
		}

		$editing      = null !== $this->context;
		$system       = SystemPrompt::for_agent( $profile, self::tool_names() )
			. ( $editing ? "\n\n" . SystemPrompt::for_editor( $this->context, self::EDIT_CONTENT ) : '' );
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
				$this->turn['reply'] = self::text_of( $answer );
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
			$this->turn['reply']   = '' !== $reason ? $reason : __( 'I cannot do that here. I can create and publish pages, change the site\'s colors, title and tagline, and open the editor for you.', 'mody_cloud' );
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

		++$this->executed;
		$response = $resolver->execute_ability( $call )->getResponse();
		AgentEvents::log( $this->input, $ability, $args, (string) wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $started );

		// A schema error leaves the target unchanged: the model may fix its arguments.
		if ( 'mody/publish-page' === $ability && is_array( $response ) && isset( $response['error'] ) && 'ability_invalid_input' !== ( $response['code'] ?? '' ) ) {
			$this->publish_failed = true;
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
		}
		if ( $error ) {
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, 'refused: ' . $error[0], $started );
			return array(
				'error' => $error[1] . ' Nothing was changed.',
				'code'  => $error[0],
			);
		}

		++$this->executed;
		$fragment = 'selection' === $scope;
		$markup   = PageGenerator::edit_markup( get_current_blog_id(), $fragment ? $selected['markup'] : (string) $this->context['content'], $instruction, $fragment );
		if ( is_wp_error( $markup ) ) {
			AgentEvents::log( $this->input, self::EDIT_CONTENT, $args, $markup->get_error_code() . ': ' . $markup->get_error_message(), $started );
			return array(
				'error' => $markup->get_error_message() . ' Nothing was changed.',
				'code'  => $markup->get_error_code(),
			);
		}

		$this->edited          = true;
		$this->turn['edits'][] = array(
			'post_id'   => (int) $this->context['post_id'],
			'client_id' => $fragment ? $selected['client_id'] : null,
			'markup'    => $markup,
		);
		$response = array(
			'changed' => $fragment ? 'the selected block' : 'the whole ' . $this->context['post_type'],
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

		$shown = 0;
		foreach ( $links as $key => $label ) {
			$url = (string) ( $response[ $key ] ?? '' );
			if ( '' === $url || ! ( str_starts_with( $url, admin_url() ) || str_starts_with( $url, home_url( '/' ) ) ) ) {
				continue;
			}
			$this->turn['actions'][] = array(
				'label' => sanitize_text_field( $label ),
				'url'   => esc_url_raw( $url ),
			);
			unset( $response[ $key ] );
			++$shown;
		}

		if ( $shown ) {
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
			'Declines a request that no tool covers or that is not allowed: deleting anything, the database, files, code, users, passwords, other sites. Ends your turn.',
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
				'Changes what the owner has open in the editor, following their instruction; the change is shown in the editor, unsaved.',
				array(
					'type'       => 'object',
					'properties' => array(
						'instruction' => array( 'type' => 'string' ),
						'scope'       => array(
							'type' => 'string',
							'enum' => array( 'selection', 'content' ),
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
