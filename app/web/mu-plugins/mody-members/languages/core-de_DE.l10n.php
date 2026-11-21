<?php
/**
 * German translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'de_DE',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Bitte gib die Adresse deiner Website ein.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "Die Adresse darf nur Kleinbuchstaben (a-z) und Zahlen enthalten.",
		"That name is not allowed." => "Dieser Name ist nicht erlaubt.",
		"Site name must be at least %s character." => "Die Adresse muss mindestens %s Zeichen lang sein.\0Die Adresse muss mindestens %s Zeichen lang sein.",
		"Sorry, you may not use that site name." => "Diese Adresse kannst du leider nicht verwenden.",
		"Sorry, site names must have letters too!" => "Die Adresse muss leider auch Buchstaben enthalten!",
		"Please enter a site title." => "Bitte gib den Namen deiner Website ein.",
		"Sorry, that site already exists!" => "Diese Adresse ist leider schon vergeben!",
		"Sorry, that site is reserved!" => "Diese Adresse ist leider reserviert!",
		"That site is currently reserved but may be available in a couple days." => "Diese Adresse ist gerade reserviert, könnte aber in ein paar Tagen frei sein.",
		"%s second" => "%s Sekunde\0%s Sekunden",
		"%s minute" => "%s Minute\0%s Minuten",
		"%s hour" => "%s Stunde\0%s Stunden",
		"%s day" => "%s Tag\0%s Tagen",
	),
);
