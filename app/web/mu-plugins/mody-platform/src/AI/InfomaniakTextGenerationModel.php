<?php
/**
 * Text generation through Infomaniak's OpenAI-compatible chat endpoint.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;

defined( 'ABSPATH' ) || exit;

/**
 * Sends chat completions to {INFOMANIAK_AI_BASE_URL}/chat/completions.
 *
 * Everything else (messages, tools, JSON schema, parsing) is core's
 * OpenAI-compatible implementation. Model-specific parameters go through the
 * AI Client's custom options, for example Qwen's
 * `chat_template_kwargs.enable_thinking`.
 */
class InfomaniakTextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel {

	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request( $method, InfomaniakProvider::url( $path ), $headers, $data, $this->getRequestOptions() );
	}

	/**
	 * Turns Qwen's thinking mode off unless the caller asks for it.
	 *
	 * With thinking on, Qwen spends hundreds of reasoning tokens before
	 * answering: about 5x the tokens for the same block markup, and plugin
	 * features such as excerpt generation ran past the AI Client's 30-second
	 * timeout (checked 2026-11-06). Pass the custom option
	 * `chat_template_kwargs` => array( 'enable_thinking' => true ) to turn it on.
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $prompt The prompt messages.
	 * @return array<string, mixed> The request parameters.
	 */
	protected function prepareGenerateTextParams( array $prompt ): array {
		$params = parent::prepareGenerateTextParams( $prompt );
		if ( str_starts_with( $this->metadata()->getId(), 'Qwen/' ) && ! isset( $params['chat_template_kwargs'] ) ) {
			$params['chat_template_kwargs'] = array( 'enable_thinking' => false );
		}
		return $params;
	}

	/**
	 * Wraps the JSON schema the way the OpenAI API expects it.
	 *
	 * Core sends the schema itself as `response_format.json_schema`. Infomaniak
	 * validates the OpenAI shape, `{ "name": ..., "schema": ... }`, and rejects
	 * a request without `json_schema.name` (HTTP 400, checked 2026-11-04).
	 *
	 * @param array<string, mixed>|null $output_schema The output schema.
	 * @return array<string, mixed> The response_format parameter.
	 */
	protected function prepareResponseFormatParam( ?array $output_schema ): array {
		if ( ! is_array( $output_schema ) ) {
			return array( 'type' => 'json_object' );
		}
		if ( isset( $output_schema['schema'] ) && is_array( $output_schema['schema'] ) ) {
			// Already wrapped by the caller: only make sure it has a name.
			$output_schema['name'] = $output_schema['name'] ?? 'response';
			return array( 'type' => 'json_schema', 'json_schema' => $output_schema );
		}
		return array(
			'type'        => 'json_schema',
			'json_schema' => array(
				'name'   => 'response',
				'schema' => $output_schema,
			),
		);
	}
}
