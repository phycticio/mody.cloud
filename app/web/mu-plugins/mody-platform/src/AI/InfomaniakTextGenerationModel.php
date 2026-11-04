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
