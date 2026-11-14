<?php
/**
 * Creates or updates the main site's legal pages: Legal notice
 * (`/legal-notice/`, required by Spain's LSSI-CE, art. 10), Terms of
 * service (`/terms/`, with the 14-day right of withdrawal and its model
 * form) and Privacy policy (`/privacy/`, GDPR art. 13). 2026-11-13.
 *
 * Run from the repository root, on the main site (local or production):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/legal-pages.php
 *
 * The seller's details come from network options, never from this file:
 * `mody_seller_name`, `mody_seller_address`, `mody_seller_tax_id` (the NIF)
 * and `mody_seller_email` (where people write to). Until all four are set,
 * the pages are saved as drafts; with them, published. The numbers (price,
 * space, Mody's tokens, days) come from the code, so the pages say what the
 * platform does.
 *
 * Safe to run again: it finds each page by its slug and replaces its
 * content, so edits made in the editor are lost. Edit this file instead.
 * The texts are a first draft: have them read by a lawyer or gestor before
 * taking real money.
 */

use Mody\Platform\Agent\AgentSettings;
use Mody\Platform\Agent\Budget;
use Mody\Platform\Billing\Billing;
use Mody\Platform\Billing\Lifecycle;
use Mody\Platform\Members\Members;
use Mody\Platform\Members\Offers;
use Mody\Platform\Site\Storage;

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

$monthly  = $e( Members::price( Members::PRICE['monthly'] ) );
$yearly   = $e( Members::price( Members::PRICE['yearly'] ) );
$space    = $e( size_format( Storage::SITE_MB * MB_IN_BYTES ) );
$tokens   = $e( number_format_i18n( Budget::MONTH ) );
$per_day  = $e( number_format_i18n( Budget::DAY ) );
$sites    = (int) Members::SITE_LIMITS['paid'];
$days     = (int) Billing::WITHDRAWAL_DAYS;
$grace    = (int) Lifecycle::DELETE_DAY;
$warn     = (int) Lifecycle::WARN_DAY;
$places   = (int) Offers::places();
$free     = (int) Offers::free_months();
$offer    = (int) Offers::months();
$check    = $e( Members::price( Billing::CARD_CHECK ) );
$keep     = (int) AgentSettings::retention_days();
$terms    = home_url( '/terms/' );
$privacy  = home_url( '/privacy/' );
$notice   = home_url( '/legal-notice/' );
$is_now   = $p( '<em>Last updated: ' . $e( $updated ) . '.</em>' );

// Legal notice -----------------------------------------------------------------------------

$legal_notice = $is_now
	. $p( 'Mody Cloud (mody.cloud and the sites under it) is a service of ' . $tbd( 'name' ) . ', a self-employed professional in Spain (Spain\'s Law 34/2002, LSSI-CE, article 10).' )
	. $ul(
		array(
			'<strong>Name:</strong> ' . $tbd( 'name' ),
			'<strong>Tax ID (NIF):</strong> ' . $tbd( 'tax_id' ),
			'<strong>Address:</strong> ' . $tbd( 'address' ),
			'<strong>Email:</strong> ' . $mail,
			'<strong>Activity:</strong> hosting of WordPress websites with an AI assistant, sold per site as a monthly or yearly subscription.',
		)
	)
	. $p( 'Using Mody Cloud means accepting the <a href="' . esc_url( $terms ) . '">terms of service</a>. How personal data is handled is in the <a href="' . esc_url( $privacy ) . '">privacy policy</a>.' )
	. $h( 'Intellectual property' )
	. $p( 'The Mody Cloud name, its design and the platform\'s own code belong to ' . $tbd( 'name' ) . '. WordPress, Spectra and the other open-source software it runs on keep their own licences. What members put on their sites belongs to them.' );

// Terms of service --------------------------------------------------------------------------

$terms_page = $is_now
	. $p( 'These terms are the contract between you and ' . $tbd( 'name' ) . ' (details in the <a href="' . esc_url( $notice ) . '">legal notice</a>), who runs Mody Cloud. Please read them before you sign up or pay.' )
	. $h( '1. The service' )
	. $p( 'Mody Cloud hosts WordPress websites and gives each one an AI assistant, Mody, that writes and changes pages in your brand. Every site gets the same:' )
	. $ul(
		array(
			$space . ' of space for files.',
			'Its own Mody, which knows only that site, with ' . $tokens . ' tokens of work a month and at most ' . $per_day . ' in one day. A message to Mody uses about 11,500 tokens.',
			'Every plugin in our market, the full Site Editor, and updates and security run by us.',
			'A way out: you can download your whole site at any time, whatever your plan.',
		)
	)
	. $p( 'An account can have up to ' . $sites . ' sites. These are real limits: nothing is unlimited.' )
	. $h( '2. The price' )
	. $p( 'Every site costs <strong>' . $monthly . ' a month</strong> or <strong>' . $yearly . ' a year</strong>, VAT included. The price is the same for everyone and every site. You pay in advance, by card, through Mollie B.V. (Amsterdam, the Netherlands), a licensed payment institution. Your plan, your next payment and every payment made are on your site\'s Dashboard, under Plan &amp; billing.' )
	. $h( '3. The launch offer' )
	. $p( 'During the first ' . $offer . ' months of Mody Cloud, the first ' . $places . ' accounts get one site free for ' . $free . ' months, without a card. Before the free months end we email you a month and a week ahead. To keep your site, add a card on Plan &amp; billing (a card check of ' . $check . ' is charged when you add it); the first payment is taken the day the free months end. Without a card by then, your site becomes read-only (see section 5).' )
	. $h( '4. Payments' )
	. $p( 'Each payment covers the following month or year, for the sites your account has on that day. If a payment fails, we try your card again once a day and email you.' )
	. $h( '5. When a site is not paid for' )
	. $p( 'If your free months end without a card, a payment fails, or your plan ends, your sites stay online but become <strong>read-only</strong>: nobody can change them, and Mody does not work on them. You can still log in, pay, and download your site. We email you a last warning on day ' . $warn . '. If nothing is paid within ' . $grace . ' days, your sites are deleted. A payment at any time before then lifts the read-only state at once.' )
	. $h( '6. Cancelling' )
	. $p( 'You can cancel at any time on Plan &amp; billing. Nothing more is charged, and your plan ends at the end of the period already paid for (or of your free months). Until then you can change your mind with Keep my plan. After the end, your sites are read-only and then deleted as in section 5. Apart from the right of withdrawal below, a period that has started is not refunded.' )
	. $h( '7. Your right of withdrawal' )
	. $p( 'If you are a consumer, you can withdraw from this contract within <strong>' . $days . ' days of your first payment</strong>, without giving any reason, and we give that payment back <strong>in full</strong>. (EU law gives 14 days from the day the contract is made; it is made when you add a card or sign up, which is never after your first payment, so this is never less than the law gives.)' )
	. $p( 'To withdraw, use the <strong>Withdraw from the contract</strong> button on Plan &amp; billing, or tell us by email at ' . $mail . ' (you can use the form below, but you do not have to). We refund the payment to the same card within ' . $days . ' days of hearing from you, at no cost to you. Nothing more is charged, and your site is then read-only and deleted as in section 5; download it first if you want to keep it.' )
	. $p( '<strong>Model withdrawal form</strong> (fill it in and send it only if you want to withdraw): To ' . $tbd( 'name' ) . ', ' . $tbd( 'address' ) . ', ' . $mail . ': I hereby give notice that I withdraw from my contract for the Mody Cloud service. Ordered on / received on: [date]. Name: [your name]. Address: [your address]. Email of the account: [email]. Date: [date].' )
	. $h( '8. Your content' )
	. $p( 'What you put on your sites is yours. You allow us to store, copy (for backups), show and process it only as needed to run the service for you. You are responsible for your content being lawful and for having the rights to it. Mody can make mistakes: check what it writes before you publish it.' )
	. $h( '9. Acceptable use' )
	. $p( 'Do not use Mody Cloud for anything illegal, for malware, spam, phishing or attacks on other systems, to infringe other people\'s rights, or to get around the limits in section 1. If a site breaks this, we may make it read-only or suspend it, and we tell you why. For content that is clearly illegal we may act at once, as the law requires of hosting providers.' )
	. $h( '10. Your visitors\' personal data' )
	. $p( 'For the people who visit or use your sites, you are the data controller and we process their data on your behalf (article 28 of the GDPR): only to run the service and following your instructions as set in the platform; with confidentiality and appropriate security; with the providers listed in the <a href="' . esc_url( $privacy ) . '">privacy policy</a>, which we will tell you about before we change them; helping you answer their requests; and deleting their data when your sites are deleted. Ask us for any information you need to show that you comply.' )
	. $h( '11. Availability and changes' )
	. $p( 'We work to keep Mody Cloud online and backed up, but we cannot promise it will never be down. We may improve or change the service. If we change the price or remove something you pay for, we email you at least 30 days before, and you can cancel before it applies.' )
	. $h( '12. Liability' )
	. $p( 'We are liable for damage we cause intentionally or through gross negligence. Otherwise, our liability is limited to what you paid us in the 12 months before the damage, and we are not liable for indirect losses such as lost profits. None of this limits the rights that consumer law gives you.' )
	. $h( '13. Changes to these terms' )
	. $p( 'If we change these terms in a way that matters, we email you at least 30 days before. If you do not agree, you can cancel before the change applies.' )
	. $h( '14. Law and disputes' )
	. $p( 'Spanish law applies. If you are a consumer, you can also go to the courts where you live, and you keep the protection of the law of your country. Before anything else, write to us at ' . $mail . ': we will try to solve it.' );

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
			'<strong>Payments:</strong> handled by Mollie B.V. (the Netherlands). We never see your full card number; we keep Mollie\'s references to you and your card, the card\'s brand, last four digits and expiry date, and a record of every payment and refund. To charge you (article 6(1)(b)) and to keep the records tax law requires (article 6(1)(c)).',
			'<strong>Emails:</strong> the welcome email, password links, reminders, receipts and notices about your plan, sent through Brevo (France) (article 6(1)(b)).',
			'<strong>Security:</strong> signing up asks your browser to solve a small puzzle (ALTCHA, on our own servers); counters of attempts by address are kept for a few hours to stop abuse; Wordfence checks for attacks (article 6(1)(f), keeping the service safe).',
		)
	)
	. $h( '3. People who visit the sites we host' )
	. $p( 'This applies to mody.cloud and to every member\'s site. We set <strong>no cookies</strong> for counting visitors, store nothing in their browser, and never keep their address. A visit is recognised for one day by a code made from the address, the browser and a secret that changes every day, and that code is then deleted, so visits cannot be linked to a person. The visitor\'s country and city are worked out from the address on our own server (with the DB-IP database, no outside service), and the address is then dropped. Which pages a visit saw is kept for 13 months, then reduced to daily totals. Comments are checked for spam on our own server. On members\' sites, the member is the controller and we process this data for them (see the terms, section 10).' )
	. $h( '4. Cookies' )
	. $p( 'We only use the cookies WordPress needs to keep you logged in. No analytics, tracking or advertising cookies.' )
	. $h( '5. Where your data is, and who helps us' )
	. $p( 'Our servers are in Spain. These providers process data for us, under contract:' )
	. $ul(
		array(
			'Infomaniak (Switzerland): Mody\'s AI model. Switzerland is recognised by the EU as protecting personal data adequately.',
			'Mollie B.V. (the Netherlands): card payments.',
			'Brevo (France): sending emails.',
			'Cloudflare (United States): sits in front of our network for security and sees its traffic, including visitors\' addresses.',
			'Wordfence (Defiant, United States): security checks on login attempts and threats.',
		)
	)
	. $p( 'Cloudflare and Wordfence are outside the EU. For them we rely on the safeguards the GDPR requires for such transfers (the EU–US Data Privacy Framework where the provider is certified, or the European Commission\'s standard contractual clauses). We plan to replace them with European services.' )
	. $h( '6. How long we keep it' )
	. $ul(
		array(
			'Your account and sites: while your account exists. When a plan ends or is not paid, your sites are deleted after ' . $grace . ' days (terms, section 5).',
			'Mody\'s records: ' . $keep . ' days.',
			'Visits: 13 months, then daily totals with no visit in them.',
			'Downloads of your site: the files are deleted after 3 days.',
			'Payment records: as long as Spanish tax and commercial law requires.',
		)
	)
	. $h( '7. Your rights' )
	. $p( 'You can ask to see, correct or delete your data, to restrict or object to how we use it, and to take it with you (Download your site gives you all of your site). Write to ' . $mail . '. If you think we handle your data wrongly, you can complain to the Spanish data protection authority, the AEPD (<a href="https://www.aepd.es">www.aepd.es</a>).' )
	. $h( '8. Changes' )
	. $p( 'If we change this policy in a way that matters, we tell members by email before it applies.' );

// Save -----------------------------------------------------------------------------------------

$pages = array(
	'legal-notice' => array( 'Legal notice', $legal_notice, 'Who runs Mody Cloud: the seller\'s name, tax ID, address and email.' ),
	'terms'        => array( 'Terms of service', $terms_page, 'The contract for Mody Cloud: the service, the price per site, paying, cancelling and the 14-day right of withdrawal.' ),
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
