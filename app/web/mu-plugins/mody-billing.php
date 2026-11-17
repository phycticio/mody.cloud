<?php
/**
 * Plugin Name: Mody Billing
 * Description: Paying for sites with a card through Mollie: the card, the charges per site, the withdrawal, cancel and resume, and read-only then deletion for unpaid accounts.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory. The plugin itself is a normal
 * plugin (tickets/independent-mody-plugins.md); on the platform it is
 * always on.
 *
 * @package Mody\Billing
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-billing/mody-billing.php';
