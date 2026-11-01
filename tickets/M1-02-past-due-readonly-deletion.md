# M1-02 · Billing lifecycle: past-due read-only and 60-day deletion

**Milestone:** M1 · **Spec:** documentation/mvp-spec.md §4 · **Status:** todo

## Goal
A past-due account has all its sites read-only (with a banner) and is deleted 60 days later, with a warning at day 53.

## Deliverables
- Capability filter (MU plugin) that, when `mody_members.status = past_due`, denies content/style mutation on the member's own sites: Site Editor save, `wp/v2` post/style/theme update, media, and (later) agent tools. Front end and reading stay up.
- Persistent banner on the member dashboard and on each site's wp-admin: "Update your payment to keep editing", linking to *Update card*.
- WP-Cron sweep (hourly):
  - Detect trial/billing due → fire the charge (with M1-01's payment method).
  - On failed charge → set `past_due`, `past_due_since`.
  - Retry the charge daily while `past_due`.
  - At day 53 past due → send a final warning email with a full WP export link.
  - At day 60 past due → delete the member's sites (network trash), keep the account for re-registration.
- All transitions logged and idempotent.

## Acceptance criteria
- Simulated failed charge → every site read-only, banner visible in both dashboard and site wp-admin, REST mutation returns 403.
- Payment recovered → read-only lifted automatically.
- Day-53 warning and day-60 deletion fire on schedule (verify by moving `past_due_since` in a test).
- Re-running the sweep is a no-op (no double deletion).
