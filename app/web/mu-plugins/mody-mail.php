<?php
/**
 * Plugin Name: Mody Mail
 * Description: Sends every email the platform sends: through Brevo's transactional API when BREVO_API_KEY is set, from the sender in MAIL_SENDER_EMAIL and MAIL_SENDER_NAME. Always active.
 * Author:      Mody Cloud
 * License:     GPL-2.0-or-later
 *
 * WordPress loads only the PHP files at the top level of mu-plugins/, so this
 * file loads the plugin from its directory.
 *
 * @package Mody\Mail
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/mody-mail/mody-mail.php';
