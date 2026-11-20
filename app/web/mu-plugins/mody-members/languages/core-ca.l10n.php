<?php
/**
 * Catalan translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
 *
 * WordPress's PHP translation format, read by Mody\Members\SignupLanguage
 * (tickets/signup-chat-languages.md). Keys are the English originals, word
 * for word; a plural's forms are joined by "\0". Translated by Claude, not
 * yet read by a native speaker.
 *
 * @package Mody\Members
 */

defined( 'ABSPATH' ) || exit;

return array(
	'domain'       => 'default',
	'language'     => 'ca',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Escriu l'adreça del teu web.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "L'adreça només pot tenir lletres minúscules (a-z) i números.",
		"That name is not allowed." => "Aquest nom no està permès.",
		"Site name must be at least %s character." => "L'adreça ha de tenir com a mínim %s caràcter.\0L'adreça ha de tenir com a mínim %s caràcters.",
		"Sorry, you may not use that site name." => "Ho sento, no pots fer servir aquesta adreça.",
		"Sorry, site names must have letters too!" => "Ho sento, l'adreça també ha de tenir lletres!",
		"Please enter a site title." => "Escriu el nom del teu web.",
		"Sorry, that site already exists!" => "Ho sento, aquesta adreça ja està ocupada!",
		"Sorry, that site is reserved!" => "Ho sento, aquesta adreça està reservada!",
		"That site is currently reserved but may be available in a couple days." => "Aquesta adreça està reservada ara mateix, però pot quedar lliure d'aquí a un parell de dies.",
		"%s second" => "%s segon\0%s segons",
		"%s minute" => "%s minut\0%s minuts",
		"%s hour" => "%s hora\0%s hores",
		"%s day" => "%s dia\0%s dies",
	),
);
