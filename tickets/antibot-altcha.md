# Antibot: ALTCHA on the public forms

**Builds on:** the signup chat (M1-03), the mail plugin (`mody-mail`) · **Feeds:** [M5-01](M5-01-hardening-launch.md) (the security pass) · **Status:** asked and decided by the maintainer on 2026-11-10; built and deployed the same day (Results below). Antispam Bee was added the same day ("Add antispam bee now").

## Goal (maintainer, 2026-11-10)
> Now, we need security. What antibot and antispam tool would you recommend for captcha?

The recommendation was ALTCHA run on our own servers, with Antispam Bee for comments. Two ways to build it were offered: (A) our own code, about 50 lines in `mody-platform`, plus the ALTCHA widget bundled with `pnpm build`; or (B) the official WordPress plugin through Composer. The maintainer chose: "Go with option A."

## Why ALTCHA (rule 1)
- **Nothing outside our servers.** The browser solves a proof-of-work puzzle that our PHP signs and checks. No service is called, no cookie is set, and nothing about the visitor is stored.
- **Ruled out:**
  - Google reCAPTCHA and Akismet: US services (rule 1).
  - hCaptcha: a US company.
  - Cloudflare Turnstile: Cloudflare is a temporary exception and must not be used for anything new.
  - Friendly Captcha (German): a good product, but an outside paid service for something we can run ourselves.
- **Free and open source** (MIT licence); it also works with screen readers. The widget's texts come in the eight languages sites can have.

## How it works
- **The widget:**
  - It is the npm package `altcha` 3.5.0, pinned exactly in `package.json`.
  - `src/altcha.js` imports it with its eight languages, and `pnpm build` bundles it into `app/dist/altcha.js`, served at `/dist/altcha.js` on every host.
  - `Security\Altcha::register_script()` registers it as `mody-altcha`.
  - Its only outside addresses are links to altcha.org and Svelte's error-reference URLs. It never requests them.
  - The logo and the footer link are hidden.
- **The protocol:** ALTCHA v3, reimplemented in `Security\Altcha` from the package's own `createChallenge()` and `verifySolution()`.
  - **The challenge:** GET `mody-platform/v1/altcha` (every site, never cached) picks a secret counter between 1,000 and 6,000. It derives a key with PBKDF2/SHA-256, 1,000 iterations, over the nonce plus the counter, with a salt. It sends the key's first half, an HMAC of the whole key and an HMAC of all the parameters. A challenge expires after 30 minutes.
  - **The work:** the browser tries counters from 0 until a key starts with that half.
  - **The check:** `Altcha::verify()` checks the signature, the expiry and the key's HMAC. That is one HMAC: the server never repeats the work.
  - **Each solution is accepted once.** Used ones are kept in the object cache (`wp_cache_add`, atomic with Redis), or in a site transient without one.
- **The secrets** come from WordPress's salts (`wp_salt( 'nonce' )`, one HMAC per purpose). There is nothing to configure, and no `.env` change.
- **The cost:**

  | Who | Time |
  | --- | --- |
  | Headless Chrome, 8-core Mac (8 workers) | about 1 second |
  | A script in PHP | about 1.6 to 3.4 ms an attempt, so about 5 to 12 seconds a form |
  | A phone | slower than the Mac (**Inference**) |

  On a phone the widget starts when the visitor starts typing, so it is usually done by the time they send.

## Antispam Bee (added 2026-11-10)
ALTCHA stops scripts; Antispam Bee (pluginkollektiv, Germany, 2.11.13) catches comment spam that people type. It checks comments on our own server: a honeypot (it renames the comment field, and the plain `comment` field becomes the trap), BBCode links, known spam patterns and the local spam database.
- **A MU plugin on every site,** like Spectra: Composer installs it into `app/web/mu-plugins/antispam-bee/`, and `mu-plugins/antispam-bee.php` loads it. Nobody can switch it off, and production needs no activation step. Its activation hook never runs; it only stored `use_output_buffer` = 0, now the default.
- **Rule 1:** three of its checks call outside services: Gravatar (Automattic, US), the country check (iplocate.io) and the language check (pluginkollektiv's API). They are off by default. `Security\AntispamBee` pins them off whatever is saved, and hides them in Settings → Antispam Bee. Site administrators keep the rest of its settings (spam flagged or deleted, the statistics).

## Where it applies
| Form | The widget | The check |
| --- | --- | --- |
| The signup chat (`/register/`) | In the block's form. The chat (`assets/register.js`) starts it after the first answer, in the background, and sends the solution with Create my site. A new one is solved for a retry. | `rest_register()`: 403 `mody_altcha_failed` without a valid one, before anything is created. The form without JavaScript checks it too and says it needs JavaScript (`<noscript>`). |
| Log in (`wp-login.php`, every site) | `login_form`; it starts when a field gets focus, and WordPress focuses the username field on load. | `authenticate`, last: refused whatever the password check said. |
| Lost password | `lostpassword_form` | `lostpassword_post` |
| Comments by visitors | `comment_form_after_fields` (logged-in users see no fields) | `pre_comment_approved`, for wp-comments-post.php only. Core checks a comment twice, so the verdict is kept for the request. |

**Left alone:**
- WP-CLI, `wp_authenticate()` and application passwords;
- wp-admin's "Send password reset" (`retrieve_password()` outside wp-login.php);
- comments by logged-in users or added by code;
- the password-reset form, which already needs the emailed key;
- the chat's per-answer check route (`register/check`).

## Not done (open)
- **A rate limit** (M5-01) on wp-login.php, xmlrpc.php and the register routes. Behind the proxies (NPM locally, Cloudflare in production), Nginx and PHP see the proxy's address, not the visitor's. A limit per address would throttle everyone together until the real address is passed through (`set_real_ip_from` and `real_ip_header` in Nginx). Wordfence's login limits still apply in production.
- **The check route** still tells whether an email has an account (M5-01).

## Results (2026-11-10)
- **Code:**
  - `app/web/mu-plugins/mody-platform/src/Security/Altcha.php`: challenges, `verify()`, the route, the widget, and `solve()` for tests;
  - `src/Security/FormGuard.php`: login, lost password, comments;
  - `Members\Registration` and `assets/register.js`: the chat and its form;
  - `src/altcha.js` and the `altcha` entry in `config/webpack/config.js`;
  - `package.json` and `pnpm-lock.yaml`: `altcha` 3.5.0.
- **Antispam Bee:** `app/composer.json` and `composer.lock` (`wpackagist-plugin/antispam-bee` 2.11.13, installed into `web/mu-plugins/`), the loader `app/web/mu-plugins/antispam-bee.php`, `Security\AntispamBee`, and `.gitignore`.
- **Tests:**
  - `scripts/antibot-test.php`, 39 checks, all passing. Antispam Bee: loaded; its outside checks stay off even when saved on, and are stored off; no output buffering by default; it flags a BBCode link as spam, and lets a plain comment through without an outside call; a visitor's comment through its renamed field is saved, not spam. ALTCHA:
    - challenges: once only, a changed, expired, foreign or unsolved one fails, the work is real;
    - the route on the main site and alpha;
    - the script built and served;
    - log in on both sites: refused without a solution or with a used one, works with one; `wp_authenticate()` is left alone;
    - lost password, and wp-admin's reset left alone;
    - comments on alpha: the widget for visitors only, 403 without a solution, saved with one, comments by code left alone.
  - `scripts/registration-test.php` has four new checks: the widget on the page, 403 without a solution, with a used one and with a changed one, and the form without one.
  - Also passing: languages, members, gating, agent-template, onboarding, member-plugins, updates, mail, spectra-guard, spectra-layout and spectra-blocks.
  - **Not reviewed:** an independent review (two reviewers with verification, as for site languages) was started, then stopped by the maintainer before it reported.
- **Checked in headless Chrome:**
  - **The login form**, on the main site (desktop) and alpha (phone width): the widget fits the form and says Verified; a real login works.
  - **The signup chat**, from the first answer to "Done!": the widget is solved after the first answer, the account and site were created, then removed.
  - **Requests:** every request stayed on mody.local; the widget's workers are `blob:` URLs.
- **Fixed after the deploy (reported by the maintainer the same day, locally):** the signup chat on the home page never passed. Spectra's container runs its inner blocks through `wp_kses()` with the "post" tags, which removed `<altcha-widget>`. The chat then sent an empty solution, and every Create got a 403. `/register/`, where the tests and the browser check ran, has no container. Now `Altcha::allow_widget()` (`wp_kses_allowed_html`, context "post") allows the element with the attributes the widget has. `registration-test.php` checks the block inside a container, and the home page chat was run end to end in headless Chrome. Production was not affected yet: its home page has no chat, and registration is closed there.
- **Found while building:**
  - Core runs `pre_comment_approved` twice for one comment, so a once-only solution failed on the second run. The verdict is now kept for the request.
  - A REST argument marked `required` answers with core's generic 400, so `altcha` is optional and a missing one gets the antibot message.
  - ESLint's import resolver does not read the package's `exports` map; webpack does.
- **Locally,** run `pnpm build` (Node 22) once: `app/dist/` is not in git, and without the script nobody can sign up, log in or comment. `bin/build`, which `./bin/deploy` runs, builds it for production.
