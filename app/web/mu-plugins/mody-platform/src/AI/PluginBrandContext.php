<?php
/**
 * Brand context for the `ai` plugin's features (ticket M3-04).
 *
 * @package Mody\Platform
 */

namespace Mody\Platform\AI;

use Mody\Platform\Brand\BrandProfile;

defined( 'ABSPATH' ) || exit;

/**
 * Makes the `ai` plugin's writing features write in the site's brand (rule 4).
 *
 * The plugin builds each feature's system instruction itself and passes it
 * through its `wpai_system_instruction` filter. On a site with a brand
 * profile, the features in FEATURES get SystemPrompt::for_ai_feature().
 *
 * Decided 2026-11-06: only the features that write text in the site's voice.
 * Left as they are: summaries (the plugin asks for neutral, non-stylistic
 * text), translation (it must keep the source's voice), and the analysis
 * features (alt text, slugs, block labels, comment analysis, classification,
 * editorial notes, revision summaries). Sites without a brand profile are
 * left as they are too; gating them is M4-01's decision.
 */
final class PluginBrandContext {

	/** The `ai` plugin's abilities that get the brand context. */
	public const FEATURES = array(
		'ai/title-generation',
		'ai/excerpt-generation',
		'ai/meta-description',
		'ai/content-resizing',
		'ai/type-ahead',
		'ai/suggest-reply',
		'ai/editorial-updates',
		'ai/image-prompt-generation',
	);

	/**
	 * Filters `wpai_system_instruction`.
	 *
	 * An empty instruction stays empty: the brand is added to the feature's
	 * own rules, never in place of them.
	 *
	 * @param mixed $instruction  The feature's system instruction.
	 * @param mixed $ability_name The ability's name, such as `ai/title-generation`.
	 * @return mixed The instruction, with the brand appended when it applies.
	 */
	public static function filter( $instruction, $ability_name ) {
		if ( ! is_string( $instruction ) || '' === $instruction || ! in_array( $ability_name, self::FEATURES, true ) ) {
			return $instruction;
		}

		$profile = BrandProfile::for_site( get_current_blog_id() );

		return null === $profile ? $instruction : SystemPrompt::for_ai_feature( $instruction, $profile );
	}
}
