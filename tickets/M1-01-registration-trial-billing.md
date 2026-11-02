# M1-01 · Registration, trial and Mollie billing

**Milestone:** M1 · **Spec:** documentation/mvp-spec.md §4, §5, §11 · **Status:** todo

## Goal
A visitor with a valid card reaches an active 30-day trial (Tier 1 = 3 sites) in under 2 minutes. A simulated failed charge makes all sites read-only with a banner and arms the 60-day deletion job.

## Deliverables
- **MU plugin** `app/web/mu-plugins/mody-platform/` (new, project-owned, tracked, always active).
- Tables: `mody_members` (network-wide, `base_prefix`) — see spec §11.
- Registration page + flow (spec §4 "Signup flow"):
  - `mody.cloud/register/` on the main site: full name, email, password, tier selection (3/10/50 cards), **Create account**.
  - Create WP user (role `mody_member`) + `mody_members.status = pending_payment`.
  - Mollie 0.01 EUR card-verification checkout; redirect to Mollie.
  - Webhook `payment.succeeded` (signature verified, **idempotent**) → `active`, `trial_ends_at = billing_due_at = now + 30d`, welcome email.
- **Mody dashboard** in the Network Admin (`/wp-admin/network/`), members-only via custom capability `mody_access_dashboard`:
  - **My sites** (empty at first) + **+ New site**.
  - **Plan & billing**: current plan, trial countdown, *Change plan*, *Update card*, *Cancel*.
- Billing lifecycle:
  - Trial-end charge of the tier price via stored payment method → monthly recurring.
  - Plan change effective at next billing date (both directions).
  - **Past due:** sites read-only (capability filter denies mutation), banner on dashboard + site wp-admin; daily retry for 60 days.
  - **Day 60:** sites deleted (warn at day 53 with a WP export link).
- WP-Cron jobs (hourly): due billing, past-due sweep, expired-signup cleanup (24 h).
- Env: `MOLLIE_API_KEY`, `MOLLIE_WEBHOOK_KEY` (never committed).

## Acceptance criteria (spec §4)
- New valid-card visitor → active trial in <2 min; duplicate email rejected.
- Failed charge → every site read-only (editor save, REST update, agent tools denied) + banner.
- Plan change reflected in quota at next cycle.
- Replayed webhook is a no-op; bad signature rejected.

## Open / blocking
- **Mollie test-mode keys** must be available before implementation starts (spec §16.1).
