<?php
/**
 * The in-site agent: a tool-calling loop over the WordPress AI Client.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\Agent;

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
		'generate_page' => 'mody/generate-page',
		'open_editor'   => 'mody/open-editor',
	);

	/** Plain function: shows a follow-up question in the chat. Acts on nothing. */
	public const ASK_USER = 'ask_user';

	/** Plain function: declines the request, so refusals are logged as such. */
	public const REFUSE = 'refuse_request';

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

	/** The member's message. */
	private string $input;

	/** When the request started (microtime). */
	private float $started;

	/** Abilities run in this request. */
	private int $executed = 0;

	/** Whether a tool ended the turn (ask_user, refuse_request). */
	private bool $done = false;

	/**
	 * What the chat shows.
	 *
	 * @var array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool}
	 */
	private array $turn = array(
		'reply'   => '',
		'actions' => array(),
		'choices' => array(),
		'refused' => false,
		'error'   => false,
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
	 * @param list<array{role: string, text: string}> $history Earlier messages, oldest first.
	 * @return array{reply: string, actions: list<array{label: string, url: string}>, choices: list<string>, refused: bool, error: bool}
	 *         `error` is true when the agent could not answer (no brand profile, model unavailable).
	 */
	public static function respond( string $message, array $history = array() ): array {
		$agent = new self( trim( mb_substr( sanitize_textarea_field( $message ), 0, self::MAX_MESSAGE_LENGTH ) ) );
		return $agent->run( $history );
	}

	/**
	 * The function names the model sees, keyed like SystemPrompt::for_agent() expects.
	 *
	 * @return array<string, string>
	 */
	public static function tool_names(): array {
		$names = array_map( array( Resolver::class, 'ability_name_to_function_name' ), self::ABILITIES );
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

		$system       = SystemPrompt::for_agent( $profile, self::tool_names() );
		$declarations = self::declarations();
		$resolver     = new Resolver( ...array_values( self::ABILITIES ) );
		$messages     = self::history_messages( $history );
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
				return $this->fail( $result->get_error_code(), __( 'The assistant is not available right now. Please try again in a moment.', 'mody_cloud' ) );
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
		return $this->turn;
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
			$this->turn['reply']   = '' !== $reason ? $reason : __( 'I cannot do that here. I can create pages and open the editor for you.', 'mody_cloud' );
			$this->turn['refused'] = true;
			$this->done            = true;
			AgentEvents::log( $this->input, $name, $args, 'refused: ' . $this->turn['reply'], $started );
			return array( 'shown' => true );
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

		++$this->executed;
		$response = $resolver->execute_ability( $call )->getResponse();
		AgentEvents::log( $this->input, $ability, $args, (string) wp_json_encode( $response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ), $started );

		return $this->take_link( $response );
	}

	/**
	 * Turns a Site Editor link in a tool result into a chat button, and
	 * removes it from what the model sees, so it cannot paste or alter it.
	 *
	 * @param mixed $response The ability's result.
	 * @return mixed The result for the model.
	 */
	private function take_link( $response ) {
		if ( ! is_array( $response ) || isset( $response['error'] ) ) {
			return $response;
		}

		$url = (string) ( $response['edit_url'] ?? $response['url'] ?? '' );
		if ( '' === $url || ! str_starts_with( $url, admin_url() ) ) {
			return $response;
		}

		$label = isset( $response['label'] )
			? (string) $response['label']
			/* translators: %s: page title. */
			: sprintf( __( 'Open "%s" in the Site Editor', 'mody_cloud' ), (string) ( $response['title'] ?? '' ) );

		$this->turn['actions'][] = array(
			'label' => sanitize_text_field( $label ),
			'url'   => esc_url_raw( $url ),
		);

		unset( $response['edit_url'], $response['url'] );
		$response['link'] = 'Shown to the owner as a button.';
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
		$this->turn['reply'] = $reply;
		$this->turn['error'] = true;
		AgentEvents::log( $this->input, null, null, $code . ': ' . $reply, $this->started );
		return $this->turn;
	}

	/**
	 * The tools: the abilities, declared the way core's `using_abilities()`
	 * does it, plus ask_user and refuse_request. One list, because
	 * `using_function_declarations()` replaces the previous one.
	 *
	 * @return list<FunctionDeclaration>
	 */
	private static function declarations(): array {
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

		return $declarations;
	}

	/**
	 * Earlier chat messages as AI Client messages: text only, the last
	 * HISTORY_TURNS, starting with a member message.
	 *
	 * @param list<array{role: string, text: string}> $history Earlier messages.
	 * @return list<Message>
	 */
	private static function history_messages( array $history ): array {
		$messages = array();
		foreach ( array_slice( $history, -self::HISTORY_TURNS ) as $entry ) {
			$text = trim( (string) ( $entry['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			if ( 'user' === ( $entry['role'] ?? '' ) ) {
				$messages[] = new UserMessage( array( new MessagePart( $text ) ) );
			} elseif ( $messages ) {
				$messages[] = new ModelMessage( array( new MessagePart( $text ) ) );
			}
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
