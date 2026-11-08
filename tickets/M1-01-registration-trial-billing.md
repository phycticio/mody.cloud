# M1-01 · Registration, trial and Mollie billing

**Milestone:** M1 · **Spec:** documentation/mvp-spec.md §3, §4, §5, §11, §12 · **Status:** in progress: parts 1 and 2 done 2026-11-08; parts 3 to 5 to do. Split into parts on 2026-11-08 (rule 5); before that the ticket was one block.

## Goal
A visitor with a valid card reaches an active 30-day trial (3 sites) in under 2 minutes, and a member manages their plan from the Mody dashboard. Past-due read-only and the 60-day deletion are [M1-02](M1-02-past-due-readonly-deletion.md).

## What exists already (2026-11-08)
- The MU plugin, `app/web/mu-plugins/mody-platform/`, which holds all platform code.
- **The Mody menu in Network Admin:** **Mody → New site**, the brand wizard (M2-03), behind the capability `mody_access_dashboard` (`WizardPage::CAPABILITY`). The dashboard's "+ New site" is this page.
- **The site quota**, `Provisioning\Quota`, with the `mody_site_limit` filter.

## Parts (each 1 to 2 hours)

### Part 1 · Member accounts (done 2026-11-08, Results below)
- The `mody_members` table (spec §11), created on `init` like the other tables.
- **Plans and their site limits** (spec decisions 3 and 5, §4): trial 3, Collaborator 3, Member 10, Patron 50. The fees 0, 5, 15 and 50 EUR are recorded with them, but are not confirmed yet (spec §16, question 6).
- **A row gives:**
  - the dashboard capability (spec §3);
  - the plan's site limit, through `mody_site_limit`.
- No user interface. Nothing changes for users without a row.

### Part 2 · The registration page (done 2026-11-08, Results below)
- `mody.cloud/register/` on the main site: full name, email, password, and three plan cards (name, price, sites). Then **Create account**.
- Server-side validation; a duplicate email is refused. It creates the WordPress user and a `pending_payment` row (`Members::create()`).
- **Closed in production until part 3 works:** a switch that is off unless set, so no account can be created there without a card.
- **Decided by the maintainer on 2026-11-08** ("proceed with all your recommendations"):
  1. **The page** holds a dynamic block from the MU plugin, rendered by PHP with no JS build. The theme stays free of platform logic.
  2. **No new WordPress role.** The `mody_members` row is the membership, so spec §4 step 2's `mody_member` role is dropped. Members are `administrator` of their own sites, and have no role on the main site.
  3. **A `pending_payment` account** logs in and sees a "Finish your payment" step on the dashboard, but creates no site.

### Part 3 · Mollie card verification and webhook (needs the Mollie test keys)
- A Mollie customer and a 0.01 EUR card-verification checkout, then the redirect to Mollie.
- **The webhook:** verified and idempotent (a replayed webhook changes nothing). It sets `active`, `trial_ends_at` and `billing_due_at` to now + 30 days, stores the payment method, sends the welcome email, and returns the member to the dashboard with a single-use token.
- **Env:** `MOLLIE_API_KEY`, plus `MOLLIE_WEBHOOK_KEY` if the webhook mechanism needs one. Never committed.
- **Check when building:**
  - how Mollie authenticates webhooks today. Its classic webhooks send only an ID, which is then fetched from the API with the key; the spec assumes a signature;
  - whether to call Mollie's REST API with `wp_remote_*` (no new dependency) or add its PHP client to Composer (a dependency, so ask first).

### Part 4 · The dashboard: My sites and Plan & billing (spec §5)
- **My sites:** a card per site (name, subdomain, status, created), with Open, Site editor and wp-admin. Delete: soft, typing the slug, 30-day trash. Also "+ New site" and "2 of 3 sites used".
- **Plan & billing:** the current plan, the trial countdown or next charge date, Change plan (effective at the next billing date), Update card (a new Mollie checkout) and Cancel (end of cycle).

### Part 5 · Billing jobs (needs the WP-Cron decision)
- Hourly: the trial-end charge with the stored payment method, then monthly; a plan change applied at the billing date; `expired_signup` after 24 hours without payment.
- Failed charges, retries, read-only and deletion are [M1-02](M1-02-past-due-readonly-deletion.md).
- **Blocked by** the open question "WP-Cron on subsites" in architecture.md. The billing jobs are network-wide, so they could run from the main site, whose loopback requests work. Either way, they must run on time whether or not anyone visits.

## Acceptance criteria (spec §4)
- New valid-card visitor → active trial in <2 min; duplicate email rejected.
- Plan change reflected in quota at next cycle.
- Replayed webhook is a no-op; a forged one is rejected.
- (Failed charge → read-only + banner: M1-02.)

## Open / blocking
- **Mollie test-mode keys** (spec §16, question 1): needed for part 3. None in `.env` on 2026-11-08.
- **The WP-Cron decision:** needed for part 5 and M1-02.
- **Fee confirmation** (spec §16, question 6): needed before part 2 shows prices to visitors.
- **Production mail** (an EU SMTP relay; [M5-02](M5-02-production-member-sites.md)): needed before production signups. Locally, Mailpit catches the welcome email.

## Results (part 1, 2026-11-08)
**Built** in `app/web/mu-plugins/mody-platform/src/Members/Members.php`, wired in `mody-platform.php`:
- **`install()`:** creates `{base_prefix}mody_members` on `init` through `dbDelta`, with the network option `mody_members_db_version`. It is spec §11's table, with two differences:
  - unsigned `bigint(20)` `user_id` like WordPress's own, and an index on `status` for the billing sweeps;
  - statuses: the spec's enum lists `expired`, while its signup flow (§4 step 4) uses `expired_signup`. Both are in the enum. `expired_signup` means no payment within 24 hours, and `expired` means the sites were deleted after 60 days past due.
- **`PLANS`** (label, fee, sites) and **`STATUSES`**.
- **`get()`**, cached per request; **`create()`**, which validates the user, plan and status and refuses a second row; **`delete()`**.
- **`grant_dashboard()`** on `user_has_cap`: a user with a row has `mody_access_dashboard`, whatever the status. What each status allows comes with parts 2 and 3 and M1-02.
- **`site_limit()`** on `mody_site_limit`: the plan's sites. Users without a row keep `Quota::DEFAULT_LIMIT` (3).

**Found while testing:** Network Admin has no global check for super admins. Each page checks its own capability, so a member who is not a super admin opens **Mody → New site** and sees only the Mody menu.

**Test:** `scripts/members-test.php`, 16 checks, no model call:
- the table's columns, statuses and plans;
- `create()`'s refusals and its pending-trial default;
- the limits through `Quota` (no row: 3; plans: 3, 3, 10, 50);
- the capability (with a row, without one, super admin, nothing else granted);
- over HTTP: a member with a Member plan opens Mody → New site and sees "0 of 10 sites used" and no other network menu, and a user without a row is refused (403).

**Not exposed yet:** nothing creates rows except tests, so in production nobody has one.

## Results (part 2, 2026-11-08)
**Built** in `app/web/mu-plugins/mody-platform/src/Members/Registration.php`, `assets/register.css`, and changes to `Members`, `Provisioning\Quota` and `Wizard\WizardPage`:
- **The block `mody/register`:** a PHP render callback with `supports.autoRegister`. WordPress 7.0 added this: the editor shows the block through ServerSideRender with no JS build. The block is hidden from the inserter, single, and renders nothing outside the main site. Its styles use only the theme's presets.
- **The page:** `Registration::ensure_page()` creates it on `init` the first time registration is open. It is "Create your account" at `/register/`, holding only the block, and its ID is in the main site's option `mody_register_page_id`. An existing `/register/` page is adopted, never changed. While registration is closed nothing is created, and the block says "Registration opens soon."
- **The switch:** the network option `mody_registration_open`, off unless set. Open it with `./bin/wp site option update mody_registration_open 1` (production: the same through `docker exec`), and close it with `site option delete`. A network option needs no container restart, unlike a `.env` value.
- **The form** posts to the page and is handled on `template_redirect` (`handle()`):
  - the nonce is checked; logged-in users and a closed registration are refused;
  - `register()` validates: a name of up to 120 characters, a valid and unused email, a password of 12 to 200 characters, one of the three paid plans;
  - it creates the user with no role on the main site, and a login made from the email's local part (lowercase letters and digits, never an illegal name, a number added when taken);
  - it creates the row: plan `trial`, status `pending_payment`, `next_plan` the chosen plan. If the row fails, the user is deleted;
  - it logs the user in and redirects to the Mody dashboard. On failure the page shows each field's error and keeps the name, email and plan, never the password.
- **`login_redirect`:** a member who logs in without asking for a page lands on the Mody dashboard, not the main site's wp-admin, where they have no role.
- **Pending accounts** (`Members::payment_pending()`: `pending_payment` or `expired_signup`):
  - `Quota::can_create_site()` has a new first check, the filter `mody_can_create_site`. Members refuses unpaid accounts with `mody_payment_pending`, so the wizard's REST route and the pipeline refuse them too;
  - the dashboard page shows "Finish your payment", without the wizard's form or script. Part 3 puts the Mollie card check there.
- **Added during the build (Inference, review it):** the column `next_plan` (`collaborator`, `member` or `patron`; table version 2). Spec §11 has nowhere to keep the plan chosen at signup while `plan` is `trial`, and a plan change for the next billing date needs the same place. The trial's limit stays 3 sites whatever plan was chosen.

**Test:** `scripts/registration-test.php`, 27 checks, no model call:
- closed by default: no page, the block's message, the block's registration;
- validation: all four fields, a duplicate email, the trial refused as a choice;
- over HTTP, open:
  - the page created at `/register/`;
  - the form with the three plans, prices and sites, the trial explained, never "unlimited";
  - a bad post's errors with the name kept, and an expired nonce;
  - a good post: a 302 to the dashboard with the login cookie, the user with no main-site role, and the row `trial`/`pending_payment`/`member`;
- the pending account:
  - no site allowed;
  - the payment step instead of the wizard, and the wizard with "0 of 3 sites used" once active;
  - a logged-in visitor told they have an account;
  - `login_redirect`;
- closed again: the message, and a good post creates nothing.

`scripts/members-test.php` checks `next_plan` too (17 checks).

**Not checked by the tests:** how the page looks in a browser, and the block in the editor. To see it locally, open registration and visit `https://mody.local/register/`.

**For the security pass ([M5-01](M5-01-hardening-launch.md)):** the form has no rate limit and no spam check yet. That is acceptable while it is closed in production. Decide before opening it.
