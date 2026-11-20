<?php
/**
 * Italian translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'it_IT',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Scrivi l'indirizzo del tuo sito.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "L'indirizzo può contenere solo lettere minuscole (a-z) e numeri.",
		"That name is not allowed." => "Questo nome non è consentito.",
		"Site name must be at least %s character." => "L'indirizzo deve avere almeno %s carattere.\0L'indirizzo deve avere almeno %s caratteri.",
		"Sorry, you may not use that site name." => "Spiacente, non puoi usare questo indirizzo.",
		"Sorry, site names must have letters too!" => "Spiacente, l'indirizzo deve contenere anche lettere!",
		"Please enter a site title." => "Scrivi il nome del tuo sito.",
		"Sorry, that site already exists!" => "Spiacente, questo indirizzo è già occupato!",
		"Sorry, that site is reserved!" => "Spiacente, questo indirizzo è riservato!",
		"That site is currently reserved but may be available in a couple days." => "Questo indirizzo è riservato al momento, ma potrebbe liberarsi tra un paio di giorni.",
		"%s second" => "%s secondo\0%s secondi",
		"%s minute" => "%s minuto\0%s minuti",
		"%s hour" => "%s ora\0%s ore",
		"%s day" => "%s giorno\0%s giorni",
	),
);
