<?php
/**
 * Lists the Infomaniak text models for the WordPress AI Client.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleModelMetadataDirectory;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Infomaniak's native model list and keeps the LLMs.
 *
 * The parent sends GET "models" through createRequest(). Here that request
 * goes to the native listing (/1/ai/models), which carries a model type, so
 * embedding, reranker, image and speech models are left out. The AI Client
 * caches the result for a day.
 */
class InfomaniakModelMetadataDirectory extends AbstractOpenAiCompatibleModelMetadataDirectory {

	/**
	 * {@inheritDoc}
	 */
	protected function createRequest( HttpMethodEnum $method, string $path, array $headers = array(), $data = null ): Request {
		return new Request( $method, InfomaniakProvider::native_models_url(), $headers, $data );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function parseResponseToModelMetadataList( Response $response ): array {
		$body   = $response->getData();
		$models = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : array();

		$capabilities = array( CapabilityEnum::textGeneration(), CapabilityEnum::chatHistory() );
		$options      = self::text_options();

		$default = InfomaniakProvider::default_model();
		$list    = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || 'llm' !== ( $model['type'] ?? '' ) || empty( $model['name'] ) ) {
				continue;
			}
			$id       = (string) $model['name'];
			$metadata = new ModelMetadata( $id, $id, $capabilities, $options );
			// The AI Client uses the first suitable model when a prompt names
			// none, so the default model goes first.
			if ( $id === $default ) {
				array_unshift( $list, $metadata );
			} else {
				$list[] = $metadata;
			}
		}
		return $list;
	}

	/**
	 * Options the OpenAI-compatible chat endpoint accepts for these models.
	 *
	 * Function calling and JSON-schema output were verified against
	 * Infomaniak on 2026-11-04 (see documentation/ai-engine.md).
	 *
	 * @return list<SupportedOption> Supported options.
	 */
	private static function text_options(): array {
		return array(
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			new SupportedOption( OptionEnum::topP() ),
			new SupportedOption( OptionEnum::stopSequences() ),
			new SupportedOption( OptionEnum::presencePenalty() ),
			new SupportedOption( OptionEnum::frequencyPenalty() ),
			new SupportedOption( OptionEnum::functionDeclarations() ),
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
			new SupportedOption( OptionEnum::customOptions() ),
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}
}
