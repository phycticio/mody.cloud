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

		$default = InfomaniakProvider::default_model();
		$list    = array();
		foreach ( $models as $model ) {
			if ( ! is_array( $model ) || 'llm' !== ( $model['type'] ?? '' ) || empty( $model['name'] ) ) {
				continue;
			}
			$id       = (string) $model['name'];
			$options  = self::text_options( in_array( $id, self::VISION_MODELS, true ) );
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
	 * Models verified to accept an image next to the text (2026-11-06).
	 *
	 * Infomaniak's listing has no field for image support, so it is declared
	 * only for models that were tested: each one described a test PNG sent as
	 * an inline `image_url` data URI. New models stay text-only until tested
	 * (documentation/ai-engine.md).
	 */
	private const VISION_MODELS = array(
		'Qwen/Qwen3.8-27B-FP8',
		'mistralai/Ministral-3-14B-Instruct-2512',
		'google/gemma-4-31B-it',
		'mistralai/Mistral-Small-4-119B-2603',
		'Qwen/Qwen3.5-397B-A17B-FP8',
		'moonshotai/Kimi-K2.6',
		'swiss-ai/Apertus-v1.5-70B',
	);

	/**
	 * Options the OpenAI-compatible chat endpoint accepts for these models.
	 *
	 * Function calling and JSON-schema output were verified against
	 * Infomaniak on 2026-11-04, image input on 2026-11-06 (see
	 * documentation/ai-engine.md).
	 *
	 * @param bool $vision Whether the model also accepts images.
	 * @return list<SupportedOption> Supported options.
	 */
	private static function text_options( bool $vision ): array {
		$input_modalities = array( array( ModalityEnum::text() ) );
		if ( $vision ) {
			// An image with or without text: the AI Client compares these as sets.
			$input_modalities[] = array( ModalityEnum::text(), ModalityEnum::image() );
			$input_modalities[] = array( ModalityEnum::image() );
		}

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
			new SupportedOption( OptionEnum::inputModalities(), $input_modalities ),
			new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::text() ) ) ),
		);
	}
}
