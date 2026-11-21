<?php
/**
 * Portuguese translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'pt_PT',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Escreve o endereço do teu site.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "O endereço só pode ter letras minúsculas (a-z) e números.",
		"That name is not allowed." => "Esse nome não é permitido.",
		"Site name must be at least %s character." => "O endereço tem de ter pelo menos %s caráter.\0O endereço tem de ter pelo menos %s carateres.",
		"Sorry, you may not use that site name." => "Lamento, não podes usar esse endereço.",
		"Sorry, site names must have letters too!" => "Lamento, o endereço também tem de ter letras!",
		"Please enter a site title." => "Escreve o nome do teu site.",
		"Sorry, that site already exists!" => "Lamento, esse endereço já está ocupado!",
		"Sorry, that site is reserved!" => "Lamento, esse endereço está reservado!",
		"That site is currently reserved but may be available in a couple days." => "Esse endereço está reservado de momento, mas pode ficar livre dentro de alguns dias.",
		"%s second" => "%s segundo\0%s segundos",
		"%s minute" => "%s minuto\0%s minutos",
		"%s hour" => "%s hora\0%s horas",
		"%s day" => "%s dia\0%s dias",
	),
);
