# M1-03 · Chat signup, a free trial without a card, and the editor tour

**Milestone:** M1 · **Spec:** documentation/mvp-spec.md §2 (decision 3), §4, §6, §7 · **Status:** built 2026-11-08 (parts A to D); the browser check is pending. Asked for by the maintainer on 2026-11-08.

## Goal
A visitor answers four questions in a chat on `mody.cloud/register/` and gets, at once, a member account on a **30-day trial with no card**, and their first site at `<slug>.mody.cloud`. An email welcomes them with a link to choose their password. Once they choose it, they land on their site's **Identity** screen (Appearance → Editor → Identity), where a short tutorial shows how the Mody agent works, asks them to type a couple of requests, then tours the Site Editor.

## Decided by the maintainer (2026-11-08)
- **The trial needs no card.** It supersedes spec decision 3 ("Card required at signup") and M1-01 part 2's `pending_payment` signup. The card and the plan are asked for before the trial ends (part 3 of M1-01, reworked; see "Not in this ticket").
- **The signup is a chat, one question at a time:** name, email, the name of the project or website, then its address, shown as a subdomain of `mody.cloud`.
- **Then the account is created, and an email is sent** with a set-password link and a welcome message. No password is typed at signup.
- **After the password is set**, the first screen is the site's Identity screen, with a tutorial: how the agent works, a prompt to type a couple of requests, then a tour of the editor explaining each part.

## Choices made while planning (Inference, review them)
1. **The site is created at signup**, in the same request, so the address is taken at once and the first login lands on a real site. Creating it only after the email is confirmed would need a reservation for the slug and a recovery screen when it is taken meanwhile.
2. **No AI call at signup.** Signup is anonymous, and generating a page costs a model call (and needs the brand, rule 4). The site starts with the default preset, the friendly tone, an empty mission, and a short starter front page. The tutorial then asks the member to tell the agent about their project.
3. **No plan choice at signup.** The trial is the plan; the paid plan is chosen before the trial ends.
4. **The first password logs the member in.** WordPress normally shows "Your password has been reset. Log in"; for a member who has never set a password (user meta `mody_onboarding` = `password`), Mody logs them in and opens the Identity screen. Later password resets behave as in WordPress.
5. **The tour belongs to the member, not the site:** user meta `mody_onboarding` = `tour` until it is finished or skipped. It shows on any member site's Site Editor while pending, and with `?mody-tour=start` for anyone who wants it again.
6. **The tour replaces core's "Welcome to the site editor" guide** for that member (the `core/edit-site` preference `welcomeGuide`). Two guides at once would clash, and core's guide loads its pictures from `s.w.org` (rule 1).
7. **Reserved addresses:** `www`, `mail`, `api`, `admin`, `plugins` and the other infrastructure names in `Provisioning\Pipeline::RESERVED_SLUGS` are refused for every new site, from the signup and from the wizard. Only a site, a pending signup or one of these names takes an address. An account's login does not, although WordPress would keep such an address for that user: logins come from email addresses, so any account would block one. Fixed on 2026-11-11, after `phycticio.mody.cloud` was refused because of the maintainer's own login.

## Parts (each 1 to 2 hours)

### Part A · The trial account and its site (server)
- `Members::start_trial()`: plan `trial`, status `active`, `trial_ends_at` and `billing_due_at` 30 days from now.
- `Registration::register( name, email, title, slug )`: validates each field (`check()`, shared with the chat's checks), creates the user with a random password and no role on the main site, starts the trial, provisions the site, sends the welcome email. A failure after the user exists removes the user and the row.
- **The site:** `Pipeline` with a `starter` input: slug and title only. Mission and prompt may be empty, the default preset and tone apply, the `page` step publishes the starter page without a model call, and the `email` step sends nothing (the welcome email replaces it). The job runs in the signup request: no model call, so a few seconds.
- **The welcome email:** the site's address, the trial's end date, "no card needed", and the set-password link (`wp-login.php?action=rp`, WordPress's own screen; the link works for 24 hours, then "Lost your password?").
- **The no-JS form:** the four fields and **Start my free trial**, posted to the page; the result is the same "check your email" message.

### Part B · The chat on `/register/`
- `assets/register.js` (plain script, no build step) hides the form and asks its questions as a chat: Mody's messages on the left, the visitor's on the right, one input at the bottom.
- Each answer is checked on the server before the next question (`POST mody-platform/v1/register/check`): a duplicate email, a taken or reserved address. The address is suggested from the project name, with a **Keep it** button.
- A summary, then **Create my site** (`POST mody-platform/v1/register`) or **Change something** (pick the answer to change).
- Closed while `mody_registration_open` is off, like the form.

### Part C · The first password opens the Identity screen
- `Onboarding::after_password_reset()`: for a member whose meta is `password`, sets `tour`, logs them in, and redirects to their first site's Identity screen. Without a site (its creation failed) it opens the Mody dashboard, where the wizard offers **Try again**.

### Part D · The editor tour
- `Onboarding\EditorTour` loads `assets/editor-tour.js` and `.css` in the Site Editor while the member's tour is pending.
- **Steps:** welcome; the Identity form (title, tagline, logo, icon, mission, tone); the agent's bubble; "try it" twice, with starters that fill the chat's input (the tour waits for each reply); back to Identity; the preview; Styles, Pages, Navigation, Patterns, Templates in the sidebar; Save; the way back to the dashboard; done.
- Each step points at its element (a spotlight and a card), or shows in the middle when the element is not there. The page stays usable under the tour. **Skip tour** on every step.
- Finished or skipped: `mody_onboarding` is removed through `wp/v2/users/me` (the meta is registered in REST for the user themself).

## Not in this ticket
- **The card and the plan before the trial ends** (M1-01 part 3, reworked): the dashboard asks for both during the trial; at the end the stored card is charged, or the sites go read-only (M1-02). Until then nothing happens at day 30.
- **Accounts that never set a password:** removing them, and their site, after some days needs the WP-Cron decision (M1-01 part 5).
- **Before opening registration in production** ([M5-01](M5-01-hardening-launch.md), [M5-02](M5-02-production-member-sites.md)): a rate limit and a spam check, now more pressing because no card stands in the way; the SMTP relay (the welcome email is the only way in); the wildcard DNS and TLS. The email check also tells whether an address has an account, as the form already did.

## Results (2026-11-08)
**Built** in the MU plugin, wired in `mody-platform.php`:
- **Part A:** `Members::start_trial()` and `trial_ends()`; `Registration` rewritten: `check()` (one question's rules, shared by the chat and the form), `normalize_slug()`, `suggest_slug()`, `register()` (user, trial, starter site, welcome email; a failure before the site removes the user and the row), `send_welcome()`. `Pipeline`: `check_address()` (shared by the wizard and the signup), `RESERVED_SLUGS`, the `starter` input, `starter_markup()`. A signup takes about half a second locally.
- **Part B:** `assets/register.js` and `register.css`; the REST routes `mody-platform/v1/register/check` and `mody-platform/v1/register`, open to visitors, refused while registration is closed or without the page's nonce. The chat offers **Log in** when the email has an account, and asks again only the question a final check refuses (an address taken meanwhile).
- **Part C:** `Onboarding\Onboarding`: the user meta `mody_onboarding` (`password`, `tour`, `done`), registered in REST for the user themself; `after_password_reset()`, which logs the member in on wp-login.php only, clears core's reset cookie, and redirects to `first_url()`.
- **Part D:** `Onboarding\EditorTour` and `assets/editor-tour.js` / `.css`: 13 steps (11 without the agent). The chat bubble gained two hooks for it (`window.modyAgent.openChat()` and `.navigate()`, M3-09's in-place navigation).
- **The welcome email** comes from the signup. Core also emails the network admin "New Site Registration" for each new site, as it does for the wizard's sites (the network option `registrationnotification`).

**Tests** (no model call):
- `scripts/registration-test.php`, 47 checks, rewritten: closed by default (page, block, routes); each question's rules, reserved and taken addresses, the suggestion, register() refusing bad answers; over HTTP the page, both routes, an account in under a second (user without a main-site role, active 30-day trial, stage `password`, the site and its administrator, a starter job with no model call, the starter page, one welcome email with a valid set-password link); duplicate email and taken address refused; the form without JavaScript; closed again.
- `scripts/onboarding-test.php`, 22 checks, new: WordPress's reset screen over HTTP (the link, then the password: 302 to the Identity screen, logged in, stage `tour`); the tour loaded after the bubble, with the member's name and the trial's end; the steps; every selector found in WordPress 7.1's `edit-site.js` or in our scripts (a WordPress update that renames one fails here); `done` saved through `wp/v2/users/me` with the page's nonce, a wrong value refused, nobody setting another user's; no tour afterwards unless `?mody-tour=start`; a later reset behaves as in WordPress; no site → the dashboard.
- Still passing: `members-test`, `provisioning-test` (offline), `wizard-test`, `agent-bubble-test`, `gating-test`.

**Checked in a browser (headless Chromium, driven by a script, 2026-11-08):** the chat at 1440 px and 390 px (an invalid email asked again, the suggested address with Keep, the summary, Done); the reset screen; the landing on `?p=%2Fidentity`; all 13 steps, each finding its element (the welcome and the last step are centered by design); the two tries waiting for a reply and then offering Next (the reply was simulated in the chat's log, so no model call); the stage `done` after finishing. Two bugs were found and fixed: the final chat message had the address and the email swapped, and core's welcome-guide preference was set before the preferences store loaded the saved ones, which overwrote it.

**Not checked:** real requests to Mody during the tour (a model call), and the welcome email in Mailpit (its content is checked by the test).

**Browser check for the maintainer:** open registration (`./bin/wp site option update mody_registration_open 1`), sign up at `https://mody.local/register/`, add the new site to `/etc/hosts`, open the email in Mailpit and choose a password, follow the tour sending two real requests to Mody, then close registration (`./bin/wp site option delete mody_registration_open`).
