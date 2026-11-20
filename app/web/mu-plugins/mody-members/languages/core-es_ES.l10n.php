<?php
/**
 * Spanish translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'es_ES',
	'plural-forms' => 'nplurals=2; plural=n != 1;',
	'messages'     => array(
		"Please enter a site name." => "Escribe la dirección de tu sitio.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "La dirección solo puede tener letras minúsculas (a-z) y números.",
		"That name is not allowed." => "Ese nombre no está permitido.",
		"Site name must be at least %s character." => "La dirección debe tener al menos %s carácter.\0La dirección debe tener al menos %s caracteres.",
		"Sorry, you may not use that site name." => "Lo siento, no puedes usar esa dirección.",
		"Sorry, site names must have letters too!" => "Lo siento, la dirección también debe tener letras.",
		"Please enter a site title." => "Escribe el nombre de tu sitio.",
		"Sorry, that site already exists!" => "Lo siento, esa dirección ya está ocupada.",
		"Sorry, that site is reserved!" => "Lo siento, esa dirección está reservada.",
		"That site is currently reserved but may be available in a couple days." => "Esa dirección está reservada ahora mismo, pero puede quedar libre en un par de días.",
		"%s second" => "%s segundo\0%s segundos",
		"%s minute" => "%s minuto\0%s minutos",
		"%s hour" => "%s hora\0%s horas",
		"%s day" => "%s día\0%s días",
	),
);
