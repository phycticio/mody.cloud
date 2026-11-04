<?php
/**
 * Infomaniak AI Services provider for the WordPress AI Client.
 *
 * Infomaniak exposes an OpenAI-compatible API per AI product:
 * https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1
 *
 * Environment (never committed, see sample.env):
 * - INFOMANIAK_AI_BASE_URL: the base URL above, including the product ID.
 * - INFOMANIAK_API_KEY: the API token. The AI Client reads it by itself,
 *   following its {PROVIDER_ID}_API_KEY convention, and sends it as
 *   "Authorization: Bearer <token>".
 * - INFOMANIAK_AI_MODEL (optional): the model used when a prompt names none.
 *   Default: Qwen/Qwen3.8-27B-FP8.
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiProvider;
use WordPress\AiClient\Providers\ApiBasedImplementation\ListModelsApiBasedProviderAvailability;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Http\Enums\RequestAuthenticationMethod;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Infomaniak as a cloud provider with API-key authentication.
 */
class InfomaniakProvider extends AbstractApiProvider {

	/**
	 * Provider ID. Also determines the key's variable name: INFOMANIAK_API_KEY.
	 */
	public const ID = 'infomaniak';

	/**
	 * Model used when neither the prompt nor INFOMANIAK_AI_MODEL names one.
	 */
	public const DEFAULT_MODEL = 'Qwen/Qwen3.8-27B-FP8';

	/**
	 * Returns the default model ID (INFOMANIAK_AI_MODEL, or DEFAULT_MODEL).
	 *
	 * @return string The model ID.
	 */
	public static function default_model(): string {
		$model = getenv( 'INFOMANIAK_AI_MODEL' );
		if ( false === $model || '' === $model ) {
			$model = defined( 'INFOMANIAK_AI_MODEL' ) ? (string) constant( 'INFOMANIAK_AI_MODEL' ) : '';
		}
		$model = trim( $model, " \t\n\r\0\x0B\"'" );
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Returns the configured base URL without a trailing slash, or ''.
	 *
	 * Read from the environment first, then from a PHP constant of the same
	 * name, so it works in containers (env_file) and in wp-config.php alike.
	 *
	 * @return string The base URL, or an empty string when not configured.
	 */
	public static function configured_base_url(): string {
		$url = getenv( 'INFOMANIAK_AI_BASE_URL' );
		if ( false === $url || '' === $url ) {
			$url = defined( 'INFOMANIAK_AI_BASE_URL' ) ? (string) constant( 'INFOMANIAK_AI_BASE_URL' ) : '';
		}
		return rtrim( trim( $url, " \t\n\r\0\x0B\"'" ), '/' );
	}

	/**
	 * Infomaniak's own model listing, on the same host as the base URL.
	 *
	 * The OpenAI-compatible /models endpoint lists every model without a type,
	 * embeddings included. The native listing says which ones are LLMs.
	 *
	 * @return string The native models URL.
	 */
	public static function native_models_url(): string {
		$parts = wp_parse_url( self::configured_base_url() );
		$host  = $parts['host'] ?? 'api.infomaniak.com';
		return 'https://' . $host . '/1/ai/models';
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function baseUrl(): string {
		return self::configured_base_url();
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new InfomaniakTextGenerationModel( $model_metadata, $provider_metadata );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			self::ID,
			'Infomaniak AI',
			ProviderTypeEnum::cloud(),
			null,
			RequestAuthenticationMethod::apiKey(),
			'Open models hosted by Infomaniak in Switzerland, through its OpenAI-compatible API.'
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		// Available when the model list can be fetched, which also proves the token works.
		return new ListModelsApiBasedProviderAvailability( static::modelMetadataDirectory() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new InfomaniakModelMetadataDirectory();
	}
}
