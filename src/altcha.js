/**
 * The ALTCHA widget (tickets/antibot-altcha.md): a proof-of-work check, served
 * from our own servers. It talks only to the challenge route
 * mody-platform/v1/altcha. Built into app/dist/altcha.js; the platform
 * registers it as the `mody-altcha` script.
 *
 * Texts for the languages sites can have (mody-languages); the widget picks
 * the page's language from <html lang>.
 */
import 'altcha';
/* eslint-disable import/no-unresolved -- Paths from the package's "exports" map: webpack reads it, ESLint's resolver does not. */
import 'altcha/i18n/ca';
import 'altcha/i18n/de';
import 'altcha/i18n/es-es';
import 'altcha/i18n/fr-fr';
import 'altcha/i18n/it';
import 'altcha/i18n/nl';
import 'altcha/i18n/pt-pt';
/* eslint-enable import/no-unresolved */
