<?php
/**
 * French translations of the few of WordPress core's words the signup shows (an address refused, the time to wait), worded for the signup.
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
	'language'     => 'fr_FR',
	'plural-forms' => 'nplurals=2; plural=n > 1;',
	'messages'     => array(
		"Please enter a site name." => "Indique l'adresse de ton site.",
		"Site names can only contain lowercase letters (a-z) and numbers." => "L'adresse ne peut contenir que des lettres minuscules (a-z) et des chiffres.",
		"That name is not allowed." => "Ce nom n'est pas autorisé.",
		"Site name must be at least %s character." => "L'adresse doit contenir au moins %s caractère.\0L'adresse doit contenir au moins %s caractères.",
		"Sorry, you may not use that site name." => "Désolé, tu ne peux pas utiliser cette adresse.",
		"Sorry, site names must have letters too!" => "Désolé, l'adresse doit aussi contenir des lettres !",
		"Please enter a site title." => "Indique le nom de ton site.",
		"Sorry, that site already exists!" => "Désolé, cette adresse est déjà prise !",
		"Sorry, that site is reserved!" => "Désolé, cette adresse est réservée !",
		"That site is currently reserved but may be available in a couple days." => "Cette adresse est réservée pour l'instant, mais pourrait être libre d'ici quelques jours.",
		"%s second" => "%s seconde\0%s secondes",
		"%s minute" => "%s minute\0%s minutes",
		"%s hour" => "%s heure\0%s heures",
		"%s day" => "%s jour\0%s jours",
	),
);
