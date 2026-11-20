<?php
/**
 * Dutch translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'nl_NL',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Vul het adres van je site in.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "Het adres mag alleen kleine letters (a-z) en cijfers bevatten.",
		"That name is not allowed." => "Die naam is niet toegestaan.",
		"Site name must be at least %s character." => "Het adres moet minstens %s teken lang zijn.\0Het adres moet minstens %s tekens lang zijn.",
		"Sorry, you may not use that site name." => "Sorry, dat adres kun je niet gebruiken.",
		"Sorry, site names must have letters too!" => "Sorry, het adres moet ook letters bevatten!",
		"Please enter a site title." => "Vul de naam van je site in.",
		"Sorry, that site already exists!" => "Sorry, dat adres is al bezet!",
		"Sorry, that site is reserved!" => "Sorry, dat adres is gereserveerd!",
		"That site is currently reserved but may be available in a couple days." => "Dat adres is nu gereserveerd, maar komt misschien over een paar dagen vrij.",
		"%s second" => "%s seconde\0%s seconden",
		"%s minute" => "%s minuut\0%s minuten",
		"%s hour" => "%s uur\0%s uur",
		"%s day" => "%s dag\0%s dagen",
	),
);
