<?php
/**
 * Creates or updates the main site's legal pages: Legal notice (required by
 * Spain's LSSI-CE, art. 10), Terms of service and Privacy policy (GDPR
 * art. 13), in three languages. 2026-11-13; rewritten for the free service
 * on 2026-11-20 (tickets/free-mvp.md): no price, no payments, sign-ups from
 * Spain only; in Spanish, Catalan and English on 2026-11-27
 * (tickets/spanish-by-default.md, Q3).
 * The paid version (prices, the 14-day withdrawal and its form, Mollie) is
 * in git history, for the paid tiers.
 *
 * - **Spanish** is the main site's default language, so the pages with no
 *   language are in Spanish: /legal-notice/, /terms/ and /privacy/ (the same
 *   addresses as before, so every link and `wp_page_for_privacy_policy` keep
 *   working).
 * - **Catalan and English** are translations (Mody Languages: post meta
 *   `mody_lang` and `mody_source`), each with a slug of its own words, because
 *   slugs are unique on the site: /ca/avis-legal/, /ca/condicions/,
 *   /ca/privadesa/, /en/legal/, /en/terms-of-service/, /en/privacy-policy/.
 *   Their pages link to each other's language, never to the Spanish ones.
 * - The three texts were written by Claude, in the same sections, for the
 *   adviser to read (the maintainer, 2026-11-27: "write them now"). Every
 *   sentence is in `$t( english, spanish, catalan )`, side by side. The
 *   English text is the one the maintainer reviewed on 2026-11-14.
 *
 * Run from the repository root, on the main site (local or production), when
 * its default language is Spanish (`WPLANG` es_ES):
 *
 *   docker exec -i -u www-data mody_cloud_app \
 *     wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/legal-pages.php
 *
 * The seller's details come from network options, never from this file:
 * `mody_seller_name`, `mody_seller_address`, `mody_seller_tax_id` (the NIF)
 * and `mody_seller_email` (where people write to). Until all four are set,
 * the nine pages are saved as drafts; with them, published. The numbers
 * (space, Mody's tokens, sites, days) come from the code, and are written
 * the way each language writes them, so the pages say what the platform does.
 *
 * Safe to run again: it finds each page by its slug (the Spanish ones) or as
 * the translation of the Spanish one, and replaces its content, so edits made
 * in the editor are lost. Edit this file instead. Each page has its SEO
 * description (Mody SEO). The texts are a first draft: have them read by a
 * lawyer or adviser before publishing them, and again before taking any money.
 */

use Mody\Agent\AgentSettings;
use Mody\Agent\Budget;
use Mody\Languages\Languages;
use Mody\Languages\Router;
use Mody\Languages\Translations;
use Mody\Members\Members;
use Mody\Storage\Storage;

if ( ! is_main_site() ) {
	WP_CLI::error( 'Run this on the main site.' );
}
if ( ! class_exists( Languages::class ) ) {
	WP_CLI::error( 'Mody Languages is not loaded.' );
}
if ( 'es_ES' !== Languages::default_locale() ) {
	WP_CLI::error( 'The main site\'s default language must be Spanish (es_ES): the pages with no language are the Spanish ones. Set Settings > General > Site Language first.' );
}
Languages::save( array_merge( Languages::others(), array( 'en_US', 'ca' ) ) );
$locales = array( 'es_ES', 'en_US', 'ca' ); // The default first: the translations are made from it.

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

// The pages: each one's slug in every language. A translation's slug is unique on the site,
// so it has its own words (Mody Languages would otherwise add the language: terms-en).
$slugs = array(
	'legal-notice' => array(
		'es_ES' => 'legal-notice',
		'en_US' => 'legal',
		'ca'    => 'avis-legal',
	),
	'terms'        => array(
		'es_ES' => 'terms',
		'en_US' => 'terms-of-service',
		'ca'    => 'condicions',
	),
	'privacy'      => array(
		'es_ES' => 'privacy',
		'en_US' => 'privacy-policy',
		'ca'    => 'privadesa',
	),
);
// A page's address in a language (/terms/, /en/terms-of-service/, /ca/condicions/): the one it ends up
// at is checked after it is saved. Drafts have no pretty permalink yet, so this is built, not asked for.
$link_of = static fn( string $locale, string $page ): string => Router::home( $locale ) . $slugs[ $page ][ $locale ] . '/';

$sites    = (int) Members::SITE_LIMITS['free'];
$notice_d = 30; // Days of notice before the free plan changes or the service ends.
$keep     = (int) AgentSettings::retention_days();

/**
 * The three pages in one language: page => [ title, markup, SEO description ].
 *
 * @param string $locale es_ES, en_US or ca.
 */
$build = static function ( string $locale ) use ( $tbd, $mail, $e, $h, $p, $ul, $link_of, $sites, $notice_d, $keep ): array {
	// One sentence in the three languages, side by side.
	$t = static fn( string $english, string $spanish, string $catalan ): string => match ( $locale ) {
		'es_ES' => $spanish,
		'ca'    => $catalan,
		default => $english,
	};

	// Dates and numbers the way the language writes them.
	switch_to_locale( $locale );
	$month   = wp_date( 'F' );
	$updated = match ( $locale ) {
		'es_ES' => wp_date( 'j \d\e F \d\e Y' ),
		'ca'    => wp_date( 'j' ) . ( preg_match( '/^[aeiouàèéíòóú]/iu', $month ) ? ' d\'' : ' de ' ) . $month . ' de ' . wp_date( 'Y' ),
		default => wp_date( 'j F Y' ),
	};
	$space       = $e( size_format( Storage::SITE_MB * MB_IN_BYTES ) );
	$tokens      = $e( number_format_i18n( Budget::MONTH ) );
	$per_day     = $e( number_format_i18n( Budget::DAY ) );
	$per_message = $e( number_format_i18n( 11500 ) ); // About what one message to Mody uses.
	restore_previous_locale();

	$terms   = esc_url( $link_of( $locale, 'terms' ) );
	$privacy = esc_url( $link_of( $locale, 'privacy' ) );
	$notice  = esc_url( $link_of( $locale, 'legal-notice' ) );

	$is_now = $p( '<em>' . $t( 'Last updated: ', 'Última actualización: ', 'Darrera actualització: ' ) . $e( $updated ) . '.</em>' );
	// Open question for the adviser: the Spanish version prevails.
	$prevails = 'es_ES' === $locale ? '' : $p(
		$t(
			'In case of any discrepancy between this translation and the Spanish version, the Spanish version prevails.',
			'',
			'En cas de discrepància entre aquesta traducció i la versió en castellà, preval la versió en castellà.'
		)
	);

	// Legal notice -----------------------------------------------------------------------------

	$legal_notice = $is_now . $prevails
		. $p(
			$t(
				'Mody Cloud (mody.cloud and the sites under it) is a service run by ' . $tbd( 'name' ) . ', a private person in Spain (Spain\'s Law 34/2002, LSSI-CE, article 10).',
				'Mody Cloud (mody.cloud y los sitios web alojados bajo este dominio) es un servicio gestionado por ' . $tbd( 'name' ) . ', persona física en España (Ley 34/2002, de servicios de la sociedad de la información y de comercio electrónico, LSSI-CE, artículo 10).',
				'Mody Cloud (mody.cloud i els llocs web allotjats sota aquest domini) és un servei gestionat per ' . $tbd( 'name' ) . ', persona física a Espanya (Llei 34/2002, de serveis de la societat de la informació i de comerç electrònic, LSSI-CE, article 10).'
			)
		)
		. $ul(
			array(
				$t( '<strong>Name:</strong> ', '<strong>Nombre:</strong> ', '<strong>Nom:</strong> ' ) . $tbd( 'name' ),
				$t( '<strong>Tax ID (NIF):</strong> ', '<strong>NIF:</strong> ', '<strong>NIF:</strong> ' ) . $tbd( 'tax_id' ),
				$t( '<strong>Address:</strong> ', '<strong>Dirección:</strong> ', '<strong>Adreça:</strong> ' ) . $tbd( 'address' ),
				$t( '<strong>Email:</strong> ', '<strong>Correo electrónico:</strong> ', '<strong>Correu electrònic:</strong> ' ) . $mail,
				$t(
					'<strong>Activity:</strong> hosting of WordPress websites with an AI assistant, offered free of charge.',
					'<strong>Actividad:</strong> alojamiento de sitios web WordPress con un asistente de inteligencia artificial, ofrecido de forma gratuita.',
					'<strong>Activitat:</strong> allotjament de llocs web WordPress amb un assistent d\'intel·ligència artificial, ofert de manera gratuïta.'
				),
			)
		)
		. $p(
			$t(
				'Using Mody Cloud means accepting the <a href="' . $terms . '">terms of service</a>. How personal data is handled is in the <a href="' . $privacy . '">privacy policy</a>.',
				'El uso de Mody Cloud implica la aceptación de las <a href="' . $terms . '">condiciones del servicio</a>. El tratamiento de los datos personales se describe en la <a href="' . $privacy . '">política de privacidad</a>.',
				'L\'ús de Mody Cloud implica l\'acceptació de les <a href="' . $terms . '">condicions del servei</a>. El tractament de les dades personals es descriu a la <a href="' . $privacy . '">política de privadesa</a>.'
			)
		)
		. $h( $t( 'Intellectual property', 'Propiedad intelectual', 'Propietat intel·lectual' ) )
		. $p(
			$t(
				'The Mody Cloud name, its design and the platform\'s own code belong to ' . $tbd( 'name' ) . '. WordPress, Spectra and the other open-source software it runs on keep their own licences. What members put on their sites belongs to them.',
				'El nombre Mody Cloud, su diseño y el código propio de la plataforma pertenecen a ' . $tbd( 'name' ) . '. WordPress, Spectra y el resto del software de código abierto sobre el que funciona conservan sus propias licencias. Lo que los miembros publican en sus sitios les pertenece a ellos.',
				'El nom Mody Cloud, el seu disseny i el codi propi de la plataforma pertanyen a ' . $tbd( 'name' ) . '. WordPress, Spectra i la resta del programari de codi obert sobre el qual funciona conserven les seves pròpies llicències. El que els membres publiquen als seus llocs els pertany a ells.'
			)
		);

	// Terms of service --------------------------------------------------------------------------

	$terms_page = $is_now . $prevails
		. $p(
			$t(
				'These terms are the agreement between you and ' . $tbd( 'name' ) . ' (details in the <a href="' . $notice . '">legal notice</a>), who runs Mody Cloud. Please read them before you sign up.',
				'Estas condiciones son el acuerdo entre usted y ' . $tbd( 'name' ) . ' (sus datos figuran en el <a href="' . $notice . '">aviso legal</a>), que gestiona Mody Cloud. Le rogamos que las lea antes de registrarse.',
				'Aquestes condicions són l\'acord entre vostè i ' . $tbd( 'name' ) . ' (les seves dades figuren a l\'<a href="' . $notice . '">avís legal</a>), que gestiona Mody Cloud. Si us plau, llegeixi-les abans de registrar-se.'
			)
		)
		. $h( $t( '1. The service', '1. El servicio', '1. El servei' ) )
		. $p(
			$t(
				'Mody Cloud hosts WordPress websites and gives each one an AI assistant, Mody, that writes and changes pages in your brand. Each account has <strong>' . $sites . ' site</strong>, and every site gets the same:',
				'Mody Cloud aloja sitios web WordPress y da a cada uno un asistente de inteligencia artificial, Mody, que escribe y modifica páginas conforme a su marca. Cada cuenta tiene <strong>' . $sites . ' sitio web</strong>, y todos los sitios reciben lo mismo:',
				'Mody Cloud allotja llocs web WordPress i dona a cadascun un assistent d\'intel·ligència artificial, Mody, que escriu i modifica pàgines d\'acord amb la seva marca. Cada compte té <strong>' . $sites . ' lloc web</strong>, i tots els llocs reben el mateix:'
			)
		)
		. $ul(
			array(
				$t( $space . ' of space for files.', $space . ' de espacio para archivos.', $space . ' d\'espai per a fitxers.' ),
				$t(
					'Its own Mody, which knows only that site, with ' . $tokens . ' tokens of work a month and at most ' . $per_day . ' in one day. A message to Mody uses about ' . $per_message . ' tokens.',
					'Su propio Mody, que solo conoce ese sitio, con ' . $tokens . ' de tokens de trabajo al mes y, como máximo, ' . $per_day . ' en un solo día. Un mensaje a Mody consume unos ' . $per_message . ' tokens.',
					'El seu propi Mody, que només coneix aquest lloc, amb ' . $tokens . ' de tokens de treball al mes i, com a màxim, ' . $per_day . ' en un sol dia. Un missatge a Mody consumeix uns ' . $per_message . ' tokens.'
				),
				$t(
					'Everything built in, the full Site Editor, and updates and security run by us.',
					'Todo integrado, el Editor del sitio completo, y las actualizaciones y la seguridad a nuestro cargo.',
					'Tot integrat, l\'Editor del lloc complet, i les actualitzacions i la seguretat a càrrec nostre.'
				),
				$t(
					'A way out: you can download your whole site at any time.',
					'Una vía de salida: puede descargar su sitio completo en cualquier momento.',
					'Una via de sortida: pot descarregar el seu lloc complet en qualsevol moment.'
				),
			)
		)
		. $p(
			$t(
				'These are real limits: nothing is unlimited.',
				'Son límites reales: nada es ilimitado.',
				'Són límits reals: res no és il·limitat.'
			)
		)
		. $h( $t( '2. Free, with no card', '2. Gratuito y sin tarjeta', '2. Gratuït i sense targeta' ) )
		. $p(
			$t(
				'Mody Cloud is <strong>free</strong>. We ask for no card and take no payment, and your free site has no end date. If we offer paid plans later, they will be optional; we will email you at least ' . $notice_d . ' days before anything about your free plan changes.',
				'Mody Cloud es <strong>gratuito</strong>. No pedimos tarjeta ni cobramos nada, y su sitio gratuito no tiene fecha de finalización. Si más adelante ofrecemos planes de pago, serán opcionales; le avisaremos por correo electrónico al menos ' . $notice_d . ' días antes de que cambie cualquier aspecto de su plan gratuito.',
				'Mody Cloud és <strong>gratuït</strong>. No demanem cap targeta ni cobrem cap pagament, i el seu lloc gratuït no té data de finalització. Si més endavant oferim plans de pagament, seran opcionals; li enviarem un correu electrònic almenys ' . $notice_d . ' dies abans que canviï qualsevol aspecte del seu pla gratuït.'
			)
		)
		. $h( $t( '3. Who can sign up', '3. Quién puede registrarse', '3. Qui es pot registrar' ) )
		. $p(
			$t(
				'For now, Mody Cloud is open to people who live in Spain. When you sign up, you confirm that you do.',
				'Por ahora, Mody Cloud está abierto a las personas que residen en España. Al registrarse, usted confirma que reside en España.',
				'De moment, Mody Cloud està obert a les persones que resideixen a Espanya. En registrar-se, vostè confirma que hi resideix.'
			)
		)
		. $h( $t( '4. Ending', '4. Baja y finalización del servicio', '4. Baixa i finalització del servei' ) )
		. $p(
			$t(
				'You can stop at any time: write to ' . $mail . ' and we delete your account and your site. Download your site first if you want to keep it. If we ever have to end the free service, we email you at least ' . $notice_d . ' days before, so you can download your site and move it.',
				'Puede dejar de usar Mody Cloud en cualquier momento: escriba a ' . $mail . ' y eliminaremos su cuenta y su sitio. Descargue antes su sitio si desea conservarlo. Si alguna vez tuviéramos que poner fin al servicio gratuito, se lo comunicaremos por correo electrónico al menos ' . $notice_d . ' días antes, para que pueda descargar su sitio y trasladarlo.',
				'Pot deixar d\'utilitzar Mody Cloud en qualsevol moment: escrigui a ' . $mail . ' i eliminarem el seu compte i el seu lloc. Descarregui abans el seu lloc si vol conservar-lo. Si algun cop haguéssim de posar fi al servei gratuït, li ho comunicarem per correu electrònic almenys ' . $notice_d . ' dies abans, perquè pugui descarregar el seu lloc i traslladar-lo.'
			)
		)
		. $h( $t( '5. Your content', '5. Su contenido', '5. El seu contingut' ) )
		. $p(
			$t(
				'What you put on your site is yours. You allow us to store, copy (for backups), show and process it only as needed to run the service for you. You are responsible for your content being lawful and for having the rights to it. Mody can make mistakes: check what it writes before you publish it.',
				'Lo que usted publica en su sitio es suyo. Usted nos autoriza a almacenarlo, copiarlo (para las copias de seguridad), mostrarlo y tratarlo únicamente en la medida necesaria para prestarle el servicio. Usted es responsable de que su contenido sea lícito y de contar con los derechos necesarios sobre él. Mody puede cometer errores: revise lo que escribe antes de publicarlo.',
				'El que vostè publica al seu lloc és seu. Vostè ens autoritza a emmagatzemar-ho, copiar-ho (per a les còpies de seguretat), mostrar-ho i tractar-ho únicament en la mesura que calgui per prestar-li el servei. Vostè és responsable que el seu contingut sigui lícit i de disposar dels drets necessaris sobre aquest. Mody pot cometre errors: revisi el que escriu abans de publicar-ho.'
			)
		)
		. $h( $t( '6. Acceptable use', '6. Uso aceptable', '6. Ús acceptable' ) )
		. $p(
			$t(
				'Do not use Mody Cloud for anything illegal, for malware, spam, phishing or attacks on other systems, to infringe other people\'s rights, or to get around the limits in section 1. If a site breaks this, we may make it read-only or suspend it, and we tell you why. For content that is clearly illegal we may act at once, as the law requires of hosting providers.',
				'No utilice Mody Cloud para nada ilegal, ni para distribuir malware, enviar spam, hacer phishing o atacar otros sistemas, ni para vulnerar los derechos de otras personas, ni para eludir los límites de la sección 1. Si un sitio incumple estas normas, podremos ponerlo en modo de solo lectura o suspenderlo, y le explicaremos el motivo. Ante contenidos manifiestamente ilícitos, podremos actuar de inmediato, como la ley exige a los prestadores de servicios de alojamiento.',
				'No utilitzi Mody Cloud per a res d\'il·legal, ni per distribuir programari maliciós (malware), enviar correu brossa (spam), fer phishing o atacar altres sistemes, ni per vulnerar els drets d\'altres persones, ni per eludir els límits de la secció 1. Si un lloc incompleix aquestes normes, podrem posar-lo en mode de només lectura o suspendre\'l, i li explicarem el motiu. Davant de continguts manifestament il·lícits, podrem actuar immediatament, com la llei exigeix als prestadors de serveis d\'allotjament.'
			)
		)
		. $h( $t( '7. Your visitors\' personal data', '7. Datos personales de sus visitantes', '7. Dades personals dels seus visitants' ) )
		. $p(
			$t(
				'For the people who visit or use your site, you are the data controller and we process their data on your behalf (article 28 of the GDPR): only to run the service and following your instructions as set in the platform; with confidentiality and appropriate security; with the providers listed in the <a href="' . $privacy . '">privacy policy</a>, which we will tell you about before we change them; helping you answer their requests; and deleting their data when your site is deleted. Ask us for any information you need to show that you comply.',
				'Respecto de las personas que visitan o utilizan su sitio, usted es el responsable del tratamiento y nosotros tratamos sus datos en su nombre, como encargados del tratamiento (artículo 28 del RGPD): únicamente para prestar el servicio y siguiendo sus instrucciones, tal como se establecen en la plataforma; con confidencialidad y medidas de seguridad adecuadas; con los proveedores indicados en la <a href="' . $privacy . '">política de privacidad</a>, de cuyo cambio le informaremos con antelación; ayudándole a atender sus solicitudes; y suprimiendo sus datos cuando se elimine su sitio. Solicítenos cualquier información que necesite para acreditar que cumple con la normativa.',
				'Respecte de les persones que visiten o utilitzen el seu lloc, vostè és el responsable del tractament i nosaltres en tractem les dades en nom seu, com a encarregats del tractament (article 28 del RGPD): únicament per prestar el servei i seguint les seves instruccions, tal com s\'estableixen a la plataforma; amb confidencialitat i mesures de seguretat adequades; amb els proveïdors indicats a la <a href="' . $privacy . '">política de privadesa</a>, que li comunicarem abans de canviar-los; col·laborant amb vostè perquè pugui atendre les seves sol·licituds; i suprimint-ne les dades quan s\'elimini el seu lloc. Demani\'ns qualsevol informació que necessiti per acreditar que compleix la normativa.'
			)
		)
		. $h( $t( '8. Availability and changes', '8. Disponibilidad y cambios', '8. Disponibilitat i canvis' ) )
		. $p(
			$t(
				'We work to keep Mody Cloud online and backed up, but we cannot promise it will never be down. We may improve or change the service.',
				'Nos esforzamos por mantener Mody Cloud en línea y con copias de seguridad, pero no podemos garantizar que nunca dejará de estar disponible. Podemos mejorar o modificar el servicio.',
				'Ens esforcem per mantenir Mody Cloud en línia i amb còpies de seguretat, però no podem garantir que mai no deixarà d\'estar disponible. Podem millorar o modificar el servei.'
			)
		)
		. $h( $t( '9. Liability', '9. Responsabilidad', '9. Responsabilitat' ) )
		. $p(
			$t(
				'We are liable for damage we cause intentionally or through gross negligence. Otherwise, we are not liable for indirect losses such as lost profits or lost data you could have downloaded. None of this limits the rights that consumer law gives you.',
				'Respondemos de los daños que causemos de forma intencionada o por negligencia grave. En los demás casos, no respondemos de las pérdidas indirectas, como el lucro cesante o la pérdida de datos que usted podría haber descargado. Nada de lo anterior limita los derechos que la legislación de consumidores y usuarios le reconoce.',
				'Responem dels danys que causem de manera intencionada o per negligència greu. En els altres casos, no responem de les pèrdues indirectes, com ara el lucre cessant o la pèrdua de dades que vostè hauria pogut descarregar. Res del que s\'ha dit limita els drets que la legislació de consum li reconeix.'
			)
		)
		. $h( $t( '10. Changes to these terms', '10. Cambios en estas condiciones', '10. Canvis en aquestes condicions' ) )
		. $p(
			$t(
				'If we change these terms in a way that matters, we email you at least ' . $notice_d . ' days before. If you do not agree, you can stop using Mody Cloud before the change applies.',
				'Si modificamos estas condiciones de forma relevante, se lo comunicaremos por correo electrónico al menos ' . $notice_d . ' días antes. Si no está de acuerdo, puede dejar de usar Mody Cloud antes de que el cambio entre en vigor.',
				'Si modifiquem aquestes condicions de manera rellevant, li ho comunicarem per correu electrònic almenys ' . $notice_d . ' dies abans. Si no hi està d\'acord, pot deixar d\'utilitzar Mody Cloud abans que el canvi entri en vigor.'
			)
		)
		. $h( $t( '11. Law and disputes', '11. Ley aplicable y controversias', '11. Llei aplicable i controvèrsies' ) )
		. $p(
			$t(
				'Spanish law applies. If you are a consumer, you can also go to the courts where you live. Before anything else, write to us at ' . $mail . ': we will try to solve it.',
				'Se aplica la legislación española. Si usted es consumidor, también puede acudir a los tribunales de su domicilio. Antes de acudir a otra vía, escríbanos a ' . $mail . ': intentaremos resolverlo.',
				'S\'aplica la legislació espanyola. Si vostè és consumidor, també pot recórrer als tribunals del seu domicili. Abans de recórrer a una altra via, escrigui\'ns a ' . $mail . ': intentarem resoldre-ho.'
			)
		);

	// Privacy policy -----------------------------------------------------------------------------

	$privacy_page = $prevails
		. $h( $t( '1. Who is responsible', '1. Responsable del tratamiento', '1. Responsable del tractament' ) )
		. $p(
			$t(
				'The controller of your personal data is ' . $tbd( 'name' ) . ', ' . $tbd( 'address' ) . ' (Tax ID ' . $tbd( 'tax_id' ) . '). Write to ' . $mail . ' about anything in this policy.',
				'El responsable del tratamiento de sus datos personales es ' . $tbd( 'name' ) . ', ' . $tbd( 'address' ) . ' (NIF ' . $tbd( 'tax_id' ) . '). Para cualquier cuestión relativa a esta política, escriba a ' . $mail . '.',
				'El responsable del tractament de les seves dades personals és ' . $tbd( 'name' ) . ', ' . $tbd( 'address' ) . ' (NIF ' . $tbd( 'tax_id' ) . '). Per a qualsevol qüestió relativa a aquesta política, escrigui a ' . $mail . '.'
			)
		)
		. $h( $t( '2. What we keep about members, and why', '2. Qué datos conservamos de los miembros y por qué', '2. Quines dades conservem dels membres i per què' ) )
		. $ul(
			array(
				$t(
					'<strong>Your account:</strong> your name, your email, your password (kept only as a secure hash) and the sites you own. To provide the service you signed up for (GDPR article 6(1)(b)).',
					'<strong>Su cuenta:</strong> su nombre, su correo electrónico, su contraseña (guardada únicamente como hash seguro) y los sitios de los que es titular. Para prestarle el servicio al que se ha registrado (artículo 6.1.b del RGPD).',
					'<strong>El seu compte:</strong> el seu nom, el seu correu electrònic, la seva contrasenya (guardada només com a hash segur) i els llocs dels quals és titular. Per prestar-li el servei al qual s\'ha registrat (article 6.1.b del RGPD).'
				),
				$t(
					'<strong>Your sites:</strong> everything you put on them, including your brand settings. To host them for you (article 6(1)(b)).',
					'<strong>Sus sitios:</strong> todo lo que usted incluye en ellos, incluida la configuración de su marca. Para alojarlos por encargo suyo (artículo 6.1.b del RGPD).',
					'<strong>Els seus llocs:</strong> tot el que vostè hi inclou, inclosa la configuració de la seva marca. Per allotjar-los per encàrrec seu (article 6.1.b del RGPD).'
				),
				$t(
					'<strong>Mody:</strong> your messages to Mody and what it did, kept for ' . $keep . ' days, to show your history, count your monthly allowance and keep the service fair. To answer, Mody sends your request, with your site\'s brand and the content it works on, to Infomaniak AI Services in Switzerland (articles 6(1)(b) and 6(1)(f)).',
					'<strong>Mody:</strong> sus mensajes a Mody y lo que ha hecho, conservados durante ' . $keep . ' días, para mostrarle su historial, contar su asignación mensual y mantener un uso equitativo del servicio. Para responder, Mody envía su petición, junto con la marca de su sitio y el contenido sobre el que trabaja, a Infomaniak AI Services, en Suiza (artículos 6.1.b y 6.1.f del RGPD).',
					'<strong>Mody:</strong> els seus missatges a Mody i el que ha fet, conservats durant ' . $keep . ' dies, per mostrar-li el seu historial, comptar la seva assignació mensual i mantenir un ús equitatiu del servei. Per respondre, Mody envia la seva petició, juntament amb la marca del seu lloc i el contingut sobre el qual treballa, a Infomaniak AI Services, a Suïssa (articles 6.1.b i 6.1.f del RGPD).'
				),
				$t(
					'<strong>Emails:</strong> the welcome email, password links and notices about your account, sent through Brevo (France) (article 6(1)(b)).',
					'<strong>Correos electrónicos:</strong> el correo de bienvenida, los enlaces para la contraseña y los avisos sobre su cuenta, enviados a través de Brevo (Francia) (artículo 6.1.b del RGPD).',
					'<strong>Correus electrònics:</strong> el correu de benvinguda, els enllaços per a la contrasenya i els avisos sobre el seu compte, enviats a través de Brevo (França) (article 6.1.b del RGPD).'
				),
				$t(
					'<strong>Security:</strong> signing up asks your browser to solve a small puzzle (ALTCHA, on our own servers); counters of attempts by address are kept for a few hours to stop abuse; a security log of logins, failed logins, blocked requests and changes to two-step login, with the address, is kept for 30 days on our own servers; if you turn on two-step login, your authenticator app\'s secret (encrypted), your security keys\' public keys and your recovery codes (as hashes) are kept with your account (article 6(1)(f), keeping the service safe).',
					'<strong>Seguridad:</strong> al registrarse, se pide a su navegador que resuelva un pequeño reto (ALTCHA, en nuestros propios servidores); los contadores de intentos por dirección IP se conservan unas horas para frenar los abusos; un registro de seguridad de inicios de sesión, inicios de sesión fallidos, solicitudes bloqueadas y cambios en la verificación en dos pasos, con la dirección IP, se conserva 30 días en nuestros propios servidores; si activa la verificación en dos pasos, el secreto de su aplicación de autenticación (cifrado), las claves públicas de sus llaves de seguridad y sus códigos de recuperación (como hashes) se guardan con su cuenta (artículo 6.1.f del RGPD, para mantener la seguridad del servicio).',
					'<strong>Seguretat:</strong> en registrar-se, es demana al seu navegador que resolgui un petit repte (ALTCHA, als nostres propis servidors); els comptadors d\'intents per adreça IP es conserven unes hores per frenar els abusos; un registre de seguretat d\'inicis de sessió, inicis de sessió fallits, sol·licituds bloquejades i canvis en la verificació en dos passos, amb l\'adreça IP, es conserva 30 dies als nostres propis servidors; si activa la verificació en dos passos, el secret de la seva aplicació d\'autenticació (xifrat), les claus públiques de les seves claus de seguretat i els seus codis de recuperació (com a hashes) es guarden amb el seu compte (article 6.1.f del RGPD, per mantenir la seguretat del servei).'
				),
			)
		)
		. $h( $t( '3. People who visit the sites we host', '3. Personas que visitan los sitios que alojamos', '3. Persones que visiten els llocs que allotgem' ) )
		. $p(
			$t(
				'This applies to mody.cloud and to every member\'s site. We set <strong>no cookies</strong> for counting visitors, store nothing in their browser, and never keep their address for counting. Only when someone tries to log in, or our firewall blocks a request, is the address kept, in our security log, for 30 days. A visit is recognised for one day by a code made from the address, the browser and a secret that changes every day, and that code is then deleted, so visits cannot be linked to a person. The visitor\'s country and city are worked out from the address on our own server (with the DB-IP database, no outside service), and the address is then dropped. Which pages a visit saw is kept for 13 months, then reduced to daily totals. Comments are checked for spam on our own server. On members\' sites, the member is the controller and we process this data for them (see the terms, section 7).',
				'Esto se aplica a mody.cloud y al sitio de cada miembro. <strong>No instalamos cookies</strong> para contar visitantes, no almacenamos nada en su navegador y nunca conservamos su dirección IP con fines de recuento. Solo cuando alguien intenta iniciar sesión, o cuando nuestro cortafuegos bloquea una solicitud, se conserva la dirección IP, en nuestro registro de seguridad, durante 30 días. Una visita se reconoce durante un día mediante un código formado a partir de la dirección IP, el navegador y un secreto que cambia cada día, y ese código se elimina después, de modo que las visitas no pueden vincularse a una persona. El país y la ciudad del visitante se determinan a partir de la dirección IP en nuestro propio servidor (con la base de datos DB-IP, sin ningún servicio externo), y la dirección se descarta a continuación. Las páginas que ha visto una visita se conservan durante 13 meses y después se reducen a totales diarios. Los comentarios se comprueban en busca de spam en nuestro propio servidor. En los sitios de los miembros, el miembro es el responsable del tratamiento y nosotros tratamos estos datos por su cuenta (véanse las condiciones, sección 7).',
				'Això s\'aplica a mody.cloud i al lloc de cada membre. <strong>No instal·lem cap cookie</strong> per comptar visitants, no emmagatzemem res al navegador dels visitants i mai no en conservem l\'adreça IP amb finalitats de recompte. Només quan algú intenta iniciar sessió, o quan el nostre tallafocs bloqueja una sol·licitud, es conserva l\'adreça IP, al nostre registre de seguretat, durant 30 dies. Una visita es reconeix durant un dia mitjançant un codi format a partir de l\'adreça IP, el navegador i un secret que canvia cada dia, i aquest codi s\'elimina després, de manera que les visites no es poden vincular a una persona. El país i la ciutat del visitant es determinen a partir de l\'adreça IP al nostre propi servidor (amb la base de dades DB-IP, sense cap servei extern), i l\'adreça es descarta tot seguit. Les pàgines que ha vist una visita es conserven durant 13 mesos i després es redueixen a totals diaris. Els comentaris se sotmeten a una comprovació de correu brossa (spam) al nostre propi servidor. Als llocs dels membres, el membre és el responsable del tractament i nosaltres tractem aquestes dades per compte seu (vegeu les condicions, secció 7).'
			)
		)
		. $h( $t( '4. Cookies', '4. Cookies', '4. Cookies' ) )
		. $p(
			$t(
				'We only use the cookies WordPress needs to keep you logged in. No analytics, tracking or advertising cookies.',
				'Solo utilizamos las cookies que WordPress necesita para mantener su sesión iniciada. No usamos cookies de analítica, de seguimiento ni publicitarias.',
				'Només utilitzem les cookies que WordPress necessita per mantenir la seva sessió iniciada. No fem servir cookies d\'analítica, de seguiment ni publicitàries.'
			)
		)
		. $h( $t( '5. Where your data is, and who helps us', '5. Dónde están sus datos y quién nos ayuda', '5. On són les seves dades i qui ens ajuda' ) )
		. $p(
			$t(
				'Our servers are in Spain. These providers process data for us, under contract:',
				'Nuestros servidores están en España. Estos proveedores tratan datos por cuenta nuestra, en virtud de un contrato:',
				'Els nostres servidors són a Espanya. Aquests proveïdors tracten dades per compte nostre, en virtut d\'un contracte:'
			)
		)
		. $ul(
			array(
				$t(
					'Infomaniak (Switzerland): Mody\'s AI model. Switzerland is recognised by the EU as protecting personal data adequately.',
					'Infomaniak (Suiza): el modelo de IA de Mody. La Unión Europea reconoce que Suiza ofrece un nivel adecuado de protección de los datos personales.',
					'Infomaniak (Suïssa): el model d\'IA de Mody. La Unió Europea reconeix que Suïssa ofereix un nivell adequat de protecció de les dades personals.'
				),
				$t(
					'Brevo (France): sending emails.',
					'Brevo (Francia): envío de correos electrónicos.',
					'Brevo (França): enviament de correus electrònics.'
				),
				$t(
					'Cloudflare (United States): sits in front of our network for security and sees its traffic, including visitors\' addresses.',
					'Cloudflare (Estados Unidos): se sitúa delante de nuestra red por motivos de seguridad y ve su tráfico, incluidas las direcciones IP de los visitantes.',
					'Cloudflare (Estats Units): se situa davant de la nostra xarxa per motius de seguretat i en veu el trànsit, incloses les adreces IP dels visitants.'
				),
			)
		)
		. $p(
			$t(
				'Cloudflare is outside the EU. For it we rely on the safeguards the GDPR requires for such transfers (the EU–US Data Privacy Framework where the provider is certified, or the European Commission\'s standard contractual clauses). We plan to replace it with a European service.',
				'Cloudflare se encuentra fuera de la UE. Para estas transferencias nos amparamos en las garantías que exige el RGPD (el Marco de Privacidad de Datos UE-EE. UU. cuando el proveedor está certificado, o las cláusulas contractuales tipo de la Comisión Europea). Tenemos previsto sustituirlo por un servicio europeo.',
				'Cloudflare és fora de la UE. Per a aquestes transferències ens emparem en les garanties que exigeix el RGPD (el Marc de Privadesa de Dades UE-EUA quan el proveïdor està certificat, o les clàusules contractuals tipus de la Comissió Europea). Tenim previst substituir-lo per un servei europeu.'
			)
		)
		. $h( $t( '6. How long we keep it', '6. Cuánto tiempo los conservamos', '6. Quant de temps les conservem' ) )
		. $ul(
			array(
				$t(
					'Your account and site: while your account exists. When you ask us to delete it, or if the free service ends (terms, section 4), they are deleted.',
					'Su cuenta y su sitio: mientras exista su cuenta. Cuando usted nos pide eliminarla, o si el servicio gratuito termina (condiciones, sección 4), se eliminan.',
					'El seu compte i el seu lloc: mentre existeixi el compte. Quan vostè ens demana eliminar-lo, o si el servei gratuït s\'acaba (condicions, secció 4), s\'eliminen.'
				),
				$t( 'Mody\'s records: ' . $keep . ' days.', 'Registros de Mody: ' . $keep . ' días.', 'Registres de Mody: ' . $keep . ' dies.' ),
				$t(
					'Visits: 13 months, then daily totals with no visit in them.',
					'Visitas: 13 meses; después, totales diarios que no contienen ninguna visita individual.',
					'Visites: 13 mesos; després, totals diaris que no contenen cap visita individual.'
				),
				$t(
					'Downloads of your site: the files are deleted after 3 days.',
					'Descargas de su sitio: los archivos se eliminan transcurridos 3 días.',
					'Descàrregues del seu lloc: els fitxers s\'eliminen al cap de 3 dies.'
				),
				$t( 'The security log: 30 days.', 'El registro de seguridad: 30 días.', 'El registre de seguretat: 30 dies.' ),
			)
		)
		. $h( $t( '7. Your rights', '7. Sus derechos', '7. Els seus drets' ) )
		. $p(
			$t(
				'You can ask to see, correct or delete your data, to restrict or object to how we use it, and to take it with you (Download your site gives you all of your site). Write to ' . $mail . '. If you think we handle your data wrongly, you can complain to the Spanish data protection authority, the AEPD (<a href="https://www.aepd.es">www.aepd.es</a>).',
				'Usted puede solicitar el acceso, la rectificación o la supresión de sus datos, la limitación del tratamiento o la oposición al mismo, y la portabilidad (puede descargar su sitio completo en cualquier momento). Escriba a ' . $mail . '. Si considera que tratamos sus datos de forma incorrecta, puede presentar una reclamación ante la autoridad española de protección de datos, la Agencia Española de Protección de Datos (AEPD) (<a href="https://www.aepd.es">www.aepd.es</a>).',
				'Vostè pot sol·licitar l\'accés, la rectificació o la supressió de les seves dades, la limitació del tractament o l\'oposició a aquest, i la portabilitat (pot descarregar el seu lloc complet en qualsevol moment). Escrigui a ' . $mail . '. Si considera que tractem les seves dades de manera incorrecta, pot presentar una reclamació davant l\'autoritat espanyola de protecció de dades, l\'Agència Espanyola de Protecció de Dades (AEPD) (<a href="https://www.aepd.es">www.aepd.es</a>).'
			)
		)
		. $h( $t( '8. Changes', '8. Cambios', '8. Canvis' ) )
		. $p(
			$t(
				'If we change this policy in a way that matters, we tell members by email before it applies.',
				'Si modificamos esta política de forma relevante, se lo comunicaremos a los miembros por correo electrónico antes de que se aplique.',
				'Si modifiquem aquesta política de manera rellevant, ho comunicarem als membres per correu electrònic abans que s\'apliqui.'
			)
		);

	// Title, markup and SEO description of each page.
	return array(
		'legal-notice' => array(
			$t( 'Legal notice', 'Aviso legal', 'Avís legal' ),
			$legal_notice,
			$t(
				'Who runs Mody Cloud: their name, tax ID, address and email.',
				'Quién gestiona Mody Cloud: nombre, NIF, dirección y correo electrónico.',
				'Qui gestiona Mody Cloud: nom, NIF, adreça i correu electrònic.'
			),
		),
		'terms'        => array(
			$t( 'Terms of service', 'Condiciones del servicio', 'Condicions del servei' ),
			$terms_page,
			$t(
				'The terms of Mody Cloud: the free service, who can sign up, your content, and how it can end.',
				'Las condiciones de Mody Cloud: el servicio gratuito, quién puede registrarse, su contenido y cómo puede terminar.',
				'Les condicions de Mody Cloud: el servei gratuït, qui es pot registrar, el seu contingut i com pot acabar.'
			),
		),
		'privacy'      => array(
			$t( 'Privacy policy', 'Política de privacidad', 'Política de privadesa' ),
			$privacy_page,
			$t(
				'What personal data Mody Cloud keeps, why, where and for how long, and your rights.',
				'Qué datos personales conserva Mody Cloud, por qué, dónde y durante cuánto tiempo, y sus derechos.',
				'Quines dades personals conserva Mody Cloud, per què, on i durant quant de temps, i els seus drets.'
			),
		),
	);
};

// Build every page first, and check its blocks, so a mistake leaves the site as it was ------------

$built = array();
foreach ( $locales as $locale ) {
	$built[ $locale ] = $build( $locale );
	foreach ( $built[ $locale ] as $page => list( , $content ) ) {
		if ( serialize_blocks( parse_blocks( $content ) ) !== $content ) {
			WP_CLI::error( "The $page page's blocks in $locale do not survive parse_blocks() and serialize_blocks()." );
		}
	}
}

// Save -----------------------------------------------------------------------------------------

$supers = get_super_admins();
$author = $supers ? get_user_by( 'login', $supers[0] ) : false;
foreach ( $slugs as $page => $names ) {
	$source_id = 0;
	foreach ( $locales as $locale ) {
		list( $title, $content, $description ) = $built[ $locale ][ $page ];
		$post                                  = array(
			'post_type'    => 'page',
			'post_status'  => $status,
			'post_title'   => $title,
			'post_name'    => $names[ $locale ],
			'post_content' => $content,
			'post_excerpt' => '', // The SEO description (Mody SEO) is the meta below.
			'meta_input'   => array( 'mody_seo_description' => $description ),
		);
		if ( 'es_ES' === $locale ) {
			// The page with no language: the Spanish one, found by its slug.
			$existing = get_page_by_path( $names[ $locale ], OBJECT, 'page' );
			if ( $existing ) {
				$post['ID'] = $existing->ID;
				$id         = wp_update_post( wp_slash( $post ), true );
			} else {
				$post['post_author'] = $author ? $author->ID : 0;
				$id                  = wp_insert_post( wp_slash( $post ), true );
			}
			$source_id = is_wp_error( $id ) ? 0 : (int) $id;
		} else {
			// A translation of it: found, or made (a draft with the slug Mody Languages gives it), then saved.
			$id = Translations::in( $source_id, $locale, false );
			if ( ! $id ) {
				$id = Translations::create( $source_id, $locale, $content, $title );
			}
			if ( ! is_wp_error( $id ) ) {
				$post['ID'] = $id;
				$id         = wp_update_post( wp_slash( $post ), true );
			}
		}
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}
		if ( get_post_field( 'post_name', $id ) !== $names[ $locale ] ) {
			WP_CLI::error( sprintf( 'The %s page in %s got the slug "%s", not "%s": another page has it. Rename that page, then run this again.', $page, $locale, get_post_field( 'post_name', $id ), $names[ $locale ] ) );
		}
		WP_CLI::log( sprintf( '%s (%s): page %d, %s, %s', $title, $locale, $id, $status, $link_of( $locale, $page ) ) );
	}
}
if ( $missing ) {
	WP_CLI::warning( 'Saved as drafts: set ' . implode( ', ', array_map( static fn( string $k ): string => 'mody_seller_' . $k, $missing ) ) . ' (wp site option update), then run this again to publish.' );
} else {
	WP_CLI::success( 'The legal pages are published in Spanish, Catalan and English.' );
}
