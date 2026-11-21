# Mody Security replaces Wordfence, with two-step login

**Builds on:** [antibot-altcha.md](antibot-altcha.md) and the security pass of 2026-11-12 ([M5-01](M5-01-hardening-launch.md#security-pass-2026-11-12)), both in the `mody-security` plugin · **Feeds:** [M5-01](M5-01-hardening-launch.md) (the security sign-off), [architecture.md](../documentation/architecture.md#temporary-exceptions-to-rule-1) (one exception less) · **Status:** planned and built on 2026-11-21 on `feature/#38-security-plugin-replaces-wordfence`, tested locally; not committed, not deployed · **Issue:** [#38](https://github.com/phycticio/mody.cloud/issues/38)

## Goal (maintainer, 2026-11-21)

> Let's replace wordfence completely with a mody-plugin authored by us here. Add the basic security settings wordfence has and also the ability to have a security key and a 2FA authenticator login.

Asked the same day:

- **Who must use two-step login:** "The plugin should have a settings page where we change all settings that can be managed. This settings page should have the same template as the mody-dashboard page." It is a setting on that page.
- **The security log keeps addresses for 30 days** (the recommended option).
- **All of it now**, in one branch, rather than two parts.

## What exists today (checked 2026-11-21)

- **Wordfence 9.0.2** is required in `app/composer.json` and network-active **on production only** (not active locally). It is a temporary exception to rule 1 (decided 2026-11-04): it calls Wordfence's servers (Defiant, United States) for firewall rules, malware signatures and scans. On the server it writes `app/web/wflogs/`, `app/wp/wordfence-waf.php` and a `.user.ini`, which `bin/deploy` never sends or deletes.
- **What `mody-security` already does** (no settings screen; limits in code with the `mody_rate_limits` filter):
  - ALTCHA on log in, lost password, comments and the signup chat (`Altcha`, `FormGuard`);
  - rate limits by the visitor's real address (`RateLimit`, `ClientAddress`): failed logins per address (10 in 15 minutes), per account at one address (5), per account from everywhere (100, not for a browser the account has used), lost password, comments, signups;
  - hardening: the installation's own salts, XML-RPC off, no user enumeration, passwords of ten characters (`Hardening`, `Secrets`).
- **Nginx** already refuses PHP in uploads, any `.php` outside core and the code folders, dumps, logs and dotfiles (`config/nginx/app.conf.template`, `scripts/nginx-hardening-test.sh`).
- **Nobody updates code from wp-admin** (`mody-updates`), and no screen installs plugins (`HiddenScreens`).
- **The profile** is on the main site only (`mody-profiles`, `NetworkProfile`): `wp-admin/user/profile.php`.
- **Not checked:** whether anyone uses Wordfence's own two-step login on production (reading production was not allowed from this session). If someone does, they set it up again in Mody Security: Wordfence's secrets are not carried over.

## Wordfence's free features, and what replaces them

| Wordfence | Mody Security |
| --- | --- |
| Brute force protection (lockouts after failures, lockout length) | The existing rate limits, now set on the Security page |
| Immediately lock out these usernames (`admin`…) | **Blocked usernames**: a login with one pauses the address at once |
| Two-factor authentication (authenticator app, recovery codes), required per role | **Two-step login**: an authenticator app (TOTP), security keys (WebAuthn), recovery codes; who must use it is a setting |
| reCAPTCHA (Google) | ALTCHA, already built |
| Web application firewall, rules from Wordfence's servers | **Firewall**: our own short rule set (SQL injection, script injection, path traversal and file inclusion, code injection) and blocked or allowed addresses and ranges |
| Malware scan against wordpress.org and Wordfence's signatures | **File check**: every code file compared with what the last deploy shipped (a manifest `bin/deploy` writes), and any script in uploads |
| Live traffic, login attempts | **Security log**: logins, failures, pauses, blocks, two-step changes; addresses kept 30 days |
| Email alerts | **Alerts** to the super admins: a super admin's login from a new browser, an address paused, the file check finding changes, a firewall block (off by default) |
| Leaked password check (Have I Been Pwned, through Wordfence) | Not replaced: it would call a service outside Europe |

## Plan

Everything goes into the `mody-security` plugin (its one thing: the platform's security). No new dependency: TOTP, WebAuthn (with its small CBOR reader) and the QR code are written here.

1. **Settings and the page.**
   - `Settings`: one network option, `mody_security`, with defaults and sanitizing; the rate limits read from it through `mody_rate_limits`.
   - `SecurityPage`: Network Admin → Mody → Security, super admins only, in the Mody dashboard's layout (its own copy of `admin-page.css`). Sections: Overview, Two-step login, Login, Firewall, Alerts, File check, Log.
2. **Two-step login.**
   - **Who must use it:** nobody, super admins (the default, an **Inference** from the recommended option), or everyone with an administrator role. Someone who must and has not set it up logs in and is sent to their profile's Two-step login until they do.
   - **Methods** (in the profile, main site): an authenticator app (`Totp`, RFC 6238, 30 s, 6 digits, a QR code drawn on our server by `Qr`, no service); security keys and passkeys (`WebAuthn`: ES256, RS256 and Ed25519, attestation `none`); ten recovery codes, each once, stored hashed.
   - **At login** (`TwoFactor`): after the password, ALTCHA and the limits, someone with two-step login gets a second screen on `wp-login.php` instead of a cookie: the code, the key, or a recovery code. Failures count in their own rate limit. The second screen lasts 10 minutes. It also works in wp-admin's "session expired" window.
   - **Not asked:** application passwords (REST, MCP) work as before; WP-CLI.
3. **Login protection** on the page: the three failed-login limits and the pause length; blocked usernames.
4. **Firewall** (`Firewall`): runs first, on every request. Blocked addresses and ranges get a 403. The rules check the address and query of every request, and the form data of visitors who are not logged in (passwords never). On or off, and per rule group. Allowed addresses skip it.
5. **File check** (`FileCheck`, `wp mody-security check-files`, a button on the page): `bin/deploy` writes `app/files.sha256` in the release; the check reports changed, added and missing code files and scripts in uploads. Not scheduled yet (see Q3).
6. **Security log** (`Events`): a network table, 30 days, shown on the page; an address can be blocked from it.
7. **Alerts** (`Alerts`): to every super admin's email.
8. **Wordfence out:** removed from `app/composer.json`, the code's lists and comments, the docs, the privacy policy and the home page's "not there yet" paragraph. The server steps are in "Production steps" below.
9. **Tests:** `scripts/security-test.php` (settings, TOTP against RFC 6238's vectors, WebAuthn with keys made in the test, recovery codes, the login steps, firewall rules and false positives, the file check, the log), `scripts/antibot-test.php` still passing, and a headless browser check: the profile's setup and a login with a virtual security key (Chromium's WebAuthn emulation) and with a code.

## Production steps (when deploying, with the maintainer)

Wordfence's firewall may load before WordPress through `auto_prepend_file` in the server's `.user.ini`. Deleting `wordfence-waf.php` while that line is there breaks every request, so the order matters:

1. Back up the database.
2. Look at `.user.ini` (and `app/wp/.user.ini`); remove the `auto_prepend_file` line if Wordfence put one; wait 5 minutes (`user_ini.cache_ttl`) or restart the app container.
3. `wp plugin deactivate wordfence --network`.
4. Deploy: the sync removes `app/web/plugins/wordfence/` (a Composer folder no longer in the build).
5. Delete `app/web/wflogs/` and `app/wp/wordfence-waf.php`; drop the `wp_wf*` tables and Wordfence's options.
6. Later, a small change removes the deploy's Wordfence excludes.
7. Set up two-step login for the super admin account(s) on the profile.

## Open questions

- **Q1. Security keys on a member's own domain.** A security key belongs to one domain (its relying party): keys added on the profile work on `mody.cloud` and every `*.mody.cloud` site, not on `example.com`. Built: on a custom domain the login offers the code and recovery codes, and says the key works on the site's `mody.cloud` address. Enough, or should members be able to add a key for their own domain too?
- **Q2. Who must use two-step login, by default.** Built with super admins; the page changes it. Should members (site administrators) be required at launch?
- **Q3. Scheduling the file check.** WP-Cron is unreliable here (architecture.md's open question). An Ofelia job like the billing run (`wp mody-security check-files --alert`, daily) needs a line in the server's `compose.prod.yml`. Add it?
- **Q4. Recovering an account without codes.** Built: a super admin turns off someone's two-step login on the network's Edit User screen, and WP-CLI can (`wp mody-security two-factor reset <user>`). Is that the recovery path, or should a member get an email link?

## Results (2026-11-21)

- **Built** in `mody-security` (no new dependency; nothing calls outside our servers):
  - `Settings` (the network option `mody_security`) and `SecurityPage`: Network Admin → Mody → Security, super admins only, in the dashboard's layout (its own copy of `admin-page.css`), with seven sections: Overview, Two-step login, Login protection, Firewall, Alerts, File check, Security log. Each form saves through `admin-post.php` with a nonce; your own address cannot be blocked; Block from the log.
  - Two-step login:
    - `Totp` (RFC 6238); `Qr`, a QR encoder of our own (byte mode, level M, versions 1 to 10, SVG);
    - `Cbor` and `WebAuthn` (ES256, RS256, Ed25519; attestation `none`; relying party the network's domain on it and its subdomains);
    - `TwoFactor`: the app's secret encrypted with sodium under a new `two-factor` secret of `Secrets`; recovery codes as keyed hashes; each TOTP step once;
    - `TwoFactorLogin`: the second screen on `wp-login.php`, ten minutes, the `two_factor` limit (5 in 15 minutes per account), the session-expired window, and wp-admin sending those who must to their profile;
    - `TwoFactorProfile`: the profile's section and its REST routes `mody-security/v1/two-factor/*`; a super admin's Turn off on the network's Edit User. Emails to the account when a method is removed, it is turned off, or a recovery code is used.
  - `FormGuard`: blocked usernames (a name that is no account pauses the address at once, `RateLimit::exhaust()`), every failed login and every pause in the log; `FormGuard::known_device()` public for the new-browser alert.
  - `Firewall` (blocked and allowed addresses on load; the rules at `plugins_loaded`), `FileCheck` and `wp mody-security check-files | files-manifest | two-factor status|reset <user>`, `Events` (30 days), `Alerts`.
  - `bin/deploy` writes `app/files.sha256` in the release (the same 6,589 files as `FileCheck::files()` on this checkout), ignored by git.
  - **Wordfence out:**
    - removed from `app/composer.json` and the lock, and from this checkout (`./bin/composer install`); kept in `MemberPlugins::PLATFORM_PLUGINS` for a leftover copy;
    - comments in `mody-locks`, `mody-updates` and `mody-export`; the download's "not in the download" note;
    - the Nginx test's sample paths;
    - the home page's "not there yet" paragraph (two things now; Spanish, German and Italian updated);
    - the privacy policy: the security log, two-step login's data, Wordfence gone from the providers;
    - AGENTS.md, architecture.md, constraints.md, mvp-spec.md, and the dependency and test skills.
- **Found on the way:**
  - `wp_rand()` is pluggable: an address refused before plugins load died with a 500 in `RateLimit` and `Events`. Both use `random_int()` now.
  - wp-login.php sets `$interim_login` only after `login_form_<action>`, so the second screen sets it itself.
  - `data-mody-2fa` is not `dataset.mody2fa` (a dash before a digit is not camel-cased): the profile's buttons did nothing until the script read the attribute.
  - wp-admin's `h3 { display: block }` beats `[hidden]`: the recovery codes panel left the section's headings showing.
- **Tests:**
  - `scripts/security-test.php`: 123 checks, all passed.
    - Settings; TOTP against RFC 6238's 18 vectors and RFC 4648; the QR code; CBOR against RFC 8949's examples;
    - WebAuthn with keys made in the test (all three algorithms; wrong challenge, origin, type, relying party, flags, signature, counter);
    - the stored setup; the REST routes;
    - logging in over HTTP: code, recovery code, security key, five wrong answers, the session-expired window, the push to the profile;
    - blocked usernames and pauses; the firewall: 19 attacks matched, 17 ordinary texts not, then over HTTP;
    - the file check: changed, added, missing, a script in uploads; the log and every section of the page.
  - **QR codes** decoded by macOS's own reader (Core Image): four texts up to version 10, and one text with each of the eight masks.
  - **Headless Chromium** with its virtual security key:
    - the profile: add a key, which shows the ten codes; a wrong app code, then the right one;
    - logging in through the real form, ALTCHA included: with the key, with an app code, with a recovery code (on a 390 px phone too, no horizontal scroll);
    - the Security page's seven sections, and a super admin sent to the setup on first visit;
    - no console errors, no request outside the network.
  - The other tests: see the suite below.
- **Not checked:**
  - a real phone app and a real hardware key (the codes follow RFC 6238; the key flow ran against Chromium's CTAP2 emulation);
  - Firefox and Safari;
  - production (the steps above).
  - With "every site's administrators", a member on their own domain is sent to the profile on `mody.cloud`, where they log in again (logins are per host, #27). Super admins (the default) cannot log in on members' domains anyway.
