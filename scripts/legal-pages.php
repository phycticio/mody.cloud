<?php
/**
 * Creates or updates the main site's legal pages: Legal notice
 * (`/legal-notice/`, required by Spain's LSSI-CE, art. 10), Terms of
 * service (`/terms/`) and Privacy policy (`/privacy/`, GDPR art. 13).
 * 2026-11-13; rewritten for the free service on 2026-11-20
 * (tickets/free-mvp.md): no price, no payments, sign-ups from Spain only.
 * The paid version (prices, the 14-day withdrawal and its form, Mollie) is
 * in git history, for the paid tiers.
 *
 * Run from the repository root, on the main site (local or production):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/legal-pages.php
 *
 * The seller's details come from network options, never from this file:
 * `mody_seller_name`, `mody_seller_address`, `mody_seller_tax_id` (the NIF)
 * and `mody_seller_email` (where people write to). Until all four are set,
 * the pages are saved as drafts; with them, published. The numbers (space,
 * Mody's tokens, sites, days) come from the code, so the pages say what the
 * platform does.
 *
 * Safe to run again: it finds each page by its slug and replaces its
 * content, so edits made in the editor are lost. Edit this file instead.
 * The texts are a first draft: have them read by a lawyer or adviser before
 * publishing them, and again before taking any money.
 */

use Mody\Agent\AgentSettings;
use Mody\Agent\Budget;
use Mody\Members\Members;
use Mody\Storage\Storage;

if ( ! is_main_site() ) {
	WP_CLI::error( 'Run this on the main site.' );
}

$seller = array(
	'name'    => trim( (string) get_site_option( 'mody_seller_name', '' ) ),
	'address' => trim( (string) get_site_option( 'mody_seller_address', '' ) ),
	'tax_id'  => trim( (string) get_site_option( 'mody_seller_tax_id', '' ) ),
	'email'   => trim( (string) get_site_option( 'mody_seller_email', '' ) ),
);
$missing = array_keys( array_filter( $seller, static fn( string $v ): bool => '' === $v ) );
$status  = $missing ? 'draft' : 'publish';
$e       = static fn( string $text ): string => esc_html( $text );
$tbd     = static fn( string $key ) => '' !== $seller[ $key ] ? esc_html( $seller[ $key ] ) : '[' . $key . ']';
$mail    = '' !== $seller['email'] ? '<a href="mailto:' . esc_attr( $seller['email'] ) . '">' . esc_html( $seller['email'] ) . '</a>' : '[email]';
$updated = wp_date( 'j F Y' );

// Blocks: headings, paragraphs and lists only (valid serialized blocks, rule 2).
$h  = static fn( string $html, int $level = 2 ): string => '<!-- wp:heading' . ( 2 === $level ? '' : ' {"level":' . $level . '}' ) . " -->\n<h{$level} class=\"wp-block-heading\">{$html}</h{$level}>\n<!-- /wp:heading -->\n\n";
$p  = static fn( string $html ): string => "<!-- wp:paragraph -->\n<p>{$html}</p>\n<!-- /wp:paragraph -->\n\n";
$ul = static function ( array $items ): string {
	$out = "<!-- wp:list -->\n<ul class=\"wp-block-list\">";
	foreach ( $items as $item ) {
		$out .= "<!-- wp:list-item -->\n<li>{$item}</li>\n<!-- /wp:list-item -->";
	}
	return $out . "</ul>\n<!-- /wp:list -->\n\n";
};

$space    = $e( size_format( Storage::SITE_MB * MB_IN_BYTES ) );
$tokens   = $e( number_format_i18n( Budget::MONTH ) );
$per_day  = $e( number_format_i18n( Budget::DAY ) );
$sites    = (int) Members::SITE_LIMITS['free'];
$notice_d = 30; // Days of notice before the free plan changes or the service ends.
$keep     = (int) AgentSettings::retention_days();
$terms    = home_url( '/terms/' );
$privacy  = home_url( '/privacy/' );
$notice   = home_url( '/legal-notice/' );
$is_now   = $p( '<em>Last updated: ' . $e( $updated ) . '.</em>' );

// Legal notice -----------------------------------------------------------------------------

$legal_notice = $is_now
	. $p( 'Mody Cloud (mody.cloud and the sites under it) is a service run by ' . $tbd( 'name' ) . ', a private person in Spain (Spain\'s Law 34/2002, LSSI-CE, article 10).' )
	. $ul(
		array(
			'<strong>Name:</strong> ' . $tbd( 'name' ),
			'<strong>Tax ID (NIF):</strong> ' . $tbd( 'tax_id' ),
			'<strong>Address:</strong> ' . $tbd( 'address' ),
			'<strong>Email:</strong> ' . $mail,
			'<strong>Activity:</strong> hosting of WordPress websites with an AI assistant, offered free of charge.',
		)
	)
	. $p( 'Using Mody Cloud means accepting the <a href="' . esc_url( $terms ) . '">terms of service</a>. How personal data is handled is in the <a href="' . esc_url( $privacy ) . '">privacy policy</a>.' )
	. $h( 'Intellectual property' )
	. $p( 'The Mody Cloud name, its design and the platform\'s own code belong to ' . $tbd( 'name' ) . '. WordPress, Spectra and the other open-source software it runs on keep their own licences. What members put on their sites belongs to them.' );

// Terms of service --------------------------------------------------------------------------

$terms_page = $is_now
	. $p( 'These terms are the agreement between you and ' . $tbd( 'name' ) . ' (details in the <a href="' . esc_url( $notice ) . '">legal notice</a>), who runs Mody Cloud. Please read them before you sign up.' )
	. $h( '1. The service' )
	. $p( 'Mody Cloud hosts WordPress websites and gives each one an AI assistant, Mody, that writes and changes pages in your brand. Each account has <strong>' . $sites . ' site</strong>, and every site gets the same:' )
	. $ul(
		array(
			$space . ' of space for files.',
			'Its own Mody, which knows only that site, with ' . $tokens . ' tokens of work a month and at most ' . $per_day . ' in one day. A message to Mody uses about 11,500 tokens.',
			'Everything built in, the full Site Editor, and updates and security run by us.',
			'A way out: you can download your whole site at any time.',
		)
	)
	. $p( 'These are real limits: nothing is unlimited.' )
	. $h( '2. Free, with no card' )
	. $p( 'Mody Cloud is <strong>free</strong>. We ask for no card and take no payment, and your free site has no end date. If we offer paid plans later, they will be optional; we will email you at least ' . $notice_d . ' days before anything about your free plan changes.' )
	. $h( '3. Who can sign up' )
	. $p( 'For now, Mody Cloud is open to people who live in Spain. When you sign up, you confirm that you do.' )
	. $h( '4. Ending' )
	. $p( 'You can stop at any time: write to ' . $mail . ' and we delete your account and your site. Download your site first if you want to keep it. If we ever have to end the free service, we email you at least ' . $notice_d . ' days before, so you can download your site and move it.' )
	. $h( '5. Your content' )
	. $p( 'What you put on your site is yours. You allow us to store, copy (for backups), show and process it only as needed to run the service for you. You are responsible for your content being lawful and for having the rights to it. Mody can make mistakes: check what it writes before you publish it.' )
	. $h( '6. Acceptable use' )
	. $p( 'Do not use Mody Cloud for anything illegal, for malware, spam, phishing or attacks on other systems, to infringe other people\'s rights, or to get around the limits in section 1. If a site breaks this, we may make it read-only or suspend it, and we tell you why. For content that is clearly illegal we may act at once, as the law requires of hosting providers.' )
	. $h( '7. Your visitors\' personal data' )
	. $p( 'For the people who visit or use your site, you are the data controller and we process their data on your behalf (article 28 of the GDPR): only to run the service and following your instructions as set in the platform; with confidentiality and appropriate security; with the providers listed in the <a href="' . esc_url( $privacy ) . '">privacy policy</a>, which we will tell you about before we change them; helping you answer their requests; and deleting their data when your site is deleted. Ask us for any information you need to show that you comply.' )
	. $h( '8. Availability and changes' )
	. $p( 'We work to keep Mody Cloud online and backed up, but we cannot promise it will never be down. We may improve or change the service.' )
	. $h( '9. Liability' )
	. $p( 'We are liable for damage we cause intentionally or through gross negligence. Otherwise, we are not liable for indirect losses such as lost profits or lost data you could have downloaded. None of this limits the rights that consumer law gives you.' )
	. $h( '10. Changes to these terms' )
	. $p( 'If we change these terms in a way that matters, we email you at least ' . $notice_d . ' days before. If you do not agree, you can stop using Mody Cloud before the change applies.' )
	. $h( '11. Law and disputes' )
	. $p( 'Spanish law applies. If you are a consumer, you can also go to the courts where you live. Before anything else, write to us at ' . $mail . ': we will try to solve it.' );

// Privacy policy -----------------------------------------------------------------------------

$privacy_page = $is_now
	. $h( '1. Who is responsible' )
	. $p( 'The controller of your personal data is ' . $tbd( 'name' ) . ', ' . $tbd( 'address' ) . ' (Tax ID ' . $tbd( 'tax_id' ) . '). Write to ' . $mail . ' about anything in this policy.' )
	. $h( '2. What we keep about members, and why' )
	. $ul(
		array(
			'<strong>Your account:</strong> your name, your email, your password (kept only as a secure hash) and the sites you own. To provide the service you signed up for (GDPR article 6(1)(b)).',
			'<strong>Your sites:</strong> everything you put on them, including your brand settings. To host them for you (article 6(1)(b)).',
			'<strong>Mody:</strong> your messages to Mody and what it did, kept for ' . $keep . ' days, to show your history, count your monthly allowance and keep the service fair. To answer, Mody sends your request, with your site\'s brand and the content it works on, to Infomaniak AI Services in Switzerland (articles 6(1)(b) and 6(1)(f)).',
			'<strong>Emails:</strong> the welcome email, password links and notices about your account, sent through Brevo (France) (article 6(1)(b)).',
			'<strong>Security:</strong> signing up asks your browser to solve a small puzzle (ALTCHA, on our own servers); counters of attempts by address are kept for a few hours to stop abuse; Wordfence checks for attacks (article 6(1)(f), keeping the service safe).',
		)
	)
	. $h( '3. People who visit the sites we host' )
	. $p( 'This applies to mody.cloud and to every member\'s site. We set <strong>no cookies</strong> for counting visitors, store nothing in their browser, and never keep their address. A visit is recognised for one day by a code made from the address, the browser and a secret that changes every day, and that code is then deleted, so visits cannot be linked to a person. The visitor\'s country and city are worked out from the address on our own server (with the DB-IP database, no outside service), and the address is then dropped. Which pages a visit saw is kept for 13 months, then reduced to daily totals. Comments are checked for spam on our own server. On members\' sites, the member is the controller and we process this data for them (see the terms, section 7).' )
	. $h( '4. Cookies' )
	. $p( 'We only use the cookies WordPress needs to keep you logged in. No analytics, tracking or advertising cookies.' )
	. $h( '5. Where your data is, and who helps us' )
	. $p( 'Our servers are in Spain. These providers process data for us, under contract:' )
	. $ul(
		array(
			'Infomaniak (Switzerland): Mody\'s AI model. Switzerland is recognised by the EU as protecting personal data adequately.',
			'Brevo (France): sending emails.',
			'Cloudflare (United States): sits in front of our network for security and sees its traffic, including visitors\' addresses.',
			'Wordfence (Defiant, United States): security checks on login attempts and threats.',
		)
	)
	. $p( 'Cloudflare and Wordfence are outside the EU. For them we rely on the safeguards the GDPR requires for such transfers (the EU–US Data Privacy Framework where the provider is certified, or the European Commission\'s standard contractual clauses). We plan to replace them with European services.' )
	. $h( '6. How long we keep it' )
	. $ul(
		array(
			'Your account and site: while your account exists. When you ask us to delete it, or if the free service ends (terms, section 4), they are deleted.',
			'Mody\'s records: ' . $keep . ' days.',
			'Visits: 13 months, then daily totals with no visit in them.',
			'Downloads of your site: the files are deleted after 3 days.',
		)
	)
	. $h( '7. Your rights' )
	. $p( 'You can ask to see, correct or delete your data, to restrict or object to how we use it, and to take it with you (Download your site gives you all of your site). Write to ' . $mail . '. If you think we handle your data wrongly, you can complain to the Spanish data protection authority, the AEPD (<a href="https://www.aepd.es">www.aepd.es</a>).' )
	. $h( '8. Changes' )
	. $p( 'If we change this policy in a way that matters, we tell members by email before it applies.' );

// Save -----------------------------------------------------------------------------------------

$pages = array(
	'legal-notice' => array( 'Legal notice', $legal_notice, 'Who runs Mody Cloud: their name, tax ID, address and email.' ),
	'terms'        => array( 'Terms of service', $terms_page, 'The terms of Mody Cloud: the free service, who can sign up, your content, and how it can end.' ),
	'privacy'      => array( 'Privacy policy', $privacy_page, 'What personal data Mody Cloud keeps, why, where and for how long, and your rights.' ),
);
$supers = get_super_admins();
$author = $supers ? get_user_by( 'login', $supers[0] ) : false;
foreach ( $pages as $slug => list( $title, $content, $excerpt ) ) {
	$parsed = parse_blocks( $content );
	if ( serialize_blocks( $parsed ) !== $content ) {
		WP_CLI::error( "The $slug page's blocks do not survive parse_blocks() and serialize_blocks()." );
	}
	$existing = get_page_by_path( $slug, OBJECT, 'page' );
	$page     = array(
		'post_type'    => 'page',
		'post_status'  => $status,
		'post_title'   => $title,
		'post_name'    => $slug,
		'post_content' => $content,
		'post_excerpt' => $excerpt,
	);
	if ( $existing ) {
		$page['ID'] = $existing->ID;
		$id         = wp_update_post( wp_slash( $page ), true );
	} else {
		$page['post_author'] = $author ? $author->ID : 0;
		$id                  = wp_insert_post( wp_slash( $page ), true );
	}
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	WP_CLI::log( sprintf( '%s: page %d, %s, %s', $title, $id, $status, get_permalink( $id ) ) );
}
if ( $missing ) {
	WP_CLI::warning( 'Saved as drafts: set ' . implode( ', ', array_map( static fn( string $k ): string => 'mody_seller_' . $k, $missing ) ) . ' (wp site option update), then run this again to publish.' );
} else {
	WP_CLI::success( 'The legal pages are published.' );
}
