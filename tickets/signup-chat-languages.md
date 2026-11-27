# The signup chat speaks the page's language

**Builds on:** [site-languages.md](site-languages.md) (the `/es/` addresses), [free-mvp.md](free-mvp.md) (sign-ups from Spain only), [M1-03](M1-03-chat-signup-and-editor-tour.md) (the chat) · **Feeds:** [site-languages.md](site-languages.md) part 9 (WordPress's own translations), the launch · **Status:** planned and built on 2026-11-21 on `feature/#33-signup-chat-languages`; merged (#34) and deployed 2026-11-21 in `3a39a7b` (checked on production: `/es/` gives the chat Spanish settings, and a wrong email gets the server's answer in Spanish) · **Issue:** [#33](https://github.com/phycticio/mody.cloud/issues/33)

## Goal (maintainer, 2026-11-21)

> Translate the signup chat to all languages.

## What exists today (checked 2026-11-21)

- The main site's home page is translated into Spanish, German and Italian (`/es/`, `/de/`, `/it/`, `scripts/home-page-translations.json`), and holds the signup chat (`mody/register`, anchor `#join`). The chat itself was English on every page, and sign-ups are open to people who live in Spain only.
- Every word of the chat comes from the server: `Registration::chat_settings()` puts them in `window.modyRegister.strings`, and the REST routes `mody-platform/v1/register/check` and `/register` answer with messages from `Registration`, Mody Sites (`Pipeline::check_address()`, which passes on core's site-name errors), Mody Security (the rate limits, ALTCHA) and Mody Members.
- WordPress switches its words only for installed languages, and none is installed: no core language packs, and no translation file for the `mody_cloud` text domain ([site-languages.md](site-languages.md), part 9, not built). So `switch_to_locale()` alone would change nothing.
- The REST routes did not know the page's language, and neither did the welcome email.

## Plan (built the same day)

1. **The languages:** every language Mody Languages offers but English: Spanish, French, German, Italian, Portuguese, Dutch and Catalan. The main site has Spanish, German and Italian today; the others work as soon as the home page is translated into them.
2. **The translations:** WordPress's PHP translation format, in Mody Members: `mody-members/languages/mody_cloud-<locale>.l10n.php` for our words (64 each) and `core-<locale>.l10n.php` for the 14 of core's words the signup shows (why an address is refused, "2 minutes" in a wait), worded for the signup. Informal "you", as on the translated home page. Generated from one list by Claude; not read by native speakers yet.
3. **`SignupLanguage`** (Mody Members): while a language is in use, WordPress's own gettext filters (`gettext_mody_cloud`, `gettext_default`, their plural and context forms) answer from those files; anything without a translation stays English. Plurals use each file's `Plural-Forms` (French counts 0 and 1 as singular). Nothing else on the page changes, and an English page pays nothing (the filters are added the first time a language is used).
4. **Where the language comes from:**
   - the block and the form posted without JavaScript: the page's language from Mody Languages (`Router::current()`, behind `class_exists()`), only when it is another language than the site's default;
   - the chat: `window.modyRegister.lang`, sent as `lang` with every request; the REST routes use it from the permission check on (`rest_request_before_callbacks`) and drop it after (`rest_request_after_callbacks`); an unknown one is English;
   - work done after the response (the account and its emails, `Registration::later()`) takes the request's language along;
   - an email to a member: their own WordPress language (user meta `locale`) first, else the request's.
5. **The account's language:** a new account keeps the chat's language as its WordPress language (`locale`) only once WordPress has that language installed (part 9). Until then nothing is kept: wp-admin would say it speaks Spanish (`<html lang="es-ES">`) while showing English, which misleads screen readers.
6. **The legal links** under the chat point to the page's translation of the terms and the privacy policy when Mody Languages has a published one; today they are English only, so they stay as they are.

## Open questions

Q2 to Q5 are taken up by [spanish-by-default.md](spanish-by-default.md) (#54, 2026-11-26): new accounts and sites in Spanish, `/register/` translated, everything after the signup in Spanish.

- **Q1.** A native speaker's read of each language, starting with Spanish and Catalan (Spain only). The texts are in the files above; `scripts/signup-languages-test.php` checks that each keeps its placeholders.
- **Q2.** The account's language before WordPress has the language installed (plan item 5): keep nothing until part 9 (built), or keep it now so those members switch when part 9 lands? **Inference:** keeping it now is safe only together with part 9.
- **Q3.** Should a signup in Spanish make a Spanish site (core's Site Language, `WPLANG`, and the starter page)? Today every new site starts in English.
- **Q4.** `/register/` is an English page with no translations. The Spanish path is the chat on `/es/#join`. Does `/register/` need its translations (`/es/register/` and so on), or should it go?
- **Q5.** After the signup everything is English until part 9: the welcome email names English labels ("Lost your password?", "Your plan") because that is what the member will see; the editor tour, the Dashboard and wp-admin are English.

## Not in this ticket

- The paid sign-up's texts (the pay page, the pay-link email, the paid intro): payments are off ([free-mvp.md](free-mvp.md)).
- The editor's preview of the block (`register-block.js`): wp-admin is English.
- The ALTCHA widget's own words: hidden in the chat, and the form without JavaScript cannot solve it anyway.

## Results (2026-11-21)

- **Built:** `mody-members/src/SignupLanguage.php`; 14 translation files in `mody-members/languages/`; `Registration` renders the block and handles the form in the page's language, registers the `lang` argument on both routes, sends the emails in the member's language and passes the language to work done after the response; `assets/register.js` sends `lang`.
- **Tests:**
  - `scripts/signup-languages-test.php` (new, 40 checks): both files of every language read and keep their placeholders; every text the signup asks WordPress for (the block, the chat, each answer's errors, the waits, the welcome email) is translated in every language; the page's language on `/es/`, English on the default language and afterwards; the REST answers in German, Italian (core's plural) and French (the permission check), an unknown language in English; the welcome email in Spanish, in the member's own Catalan first, in English without one; the note to an existing member, sent after the response, in Italian; no language kept on the account while none is installed. All passed.
  - Unchanged and passing: `registration`, `security-signup`, `invites`, `members`, `onboarding`, `antibot`, `languages`.
  - **Browser** (headless Chromium, phone width): on `/es/`, `/de/` and `/it/` the chat speaks the page's language and sends it; a wrong email gets the server's answer in that language. No console errors, no request outside the network.
- **Not done:** the open questions above.
