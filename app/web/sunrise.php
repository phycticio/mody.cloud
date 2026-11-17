<?php
/**
 * Multisite's sunrise: runs before WordPress picks the site for a request
 * (SUNRISE in wp-config.php). It maps members' custom domains to their sites
 * (tickets/custom-domains.md); everything else is
 * mu-plugins/mody-domains/src/Mapping.php, in the Mody Domains plugin.
 *
 * @package Mody\Domains
 */

defined( 'ABSPATH' ) || exit;

$mody_mapping = __DIR__ . '/mu-plugins/mody-domains/src/Mapping.php';
if ( is_readable( $mody_mapping ) ) {
	require_once $mody_mapping;
	Mody\Domains\Mapping::sunrise();
}
unset( $mody_mapping );
