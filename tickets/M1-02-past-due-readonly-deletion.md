# M1-02 · Billing lifecycle: past-due read-only and 60-day deletion

**Milestone:** M1 · **Spec:** documentation/mvp-spec.md §4 · **Status:** built 2026-11-13 on `feature/billing-lifecycle` (see Results)

## Goal
A past-due account has all its sites read-only (with a banner) and is deleted 60 days later, with a warning at day 53.

## Deliverables
- Capability filter (MU plugin) that, when `mody_members.status = past_due`, denies content/style mutation on the member's own sites: Site Editor save, `wp/v2` post/style/theme update, media, and agent tools. Front end and reading stay up.
  - Agent (moved here from M3-01 on 2026-11-06): a past-due member gets no tool execution. The agent chat exists (`src/Agent/`); check the status in `Agent::respond()` or in the `mody` abilities' permission callbacks, so REST and MCP are covered too. Log the refusal in `mody_agent_events`.
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

## Results (2026-11-13)
Asked by the maintainer: "go ahead build 1 to 3" (Cancel, read-only and deletion, the daily run with an Ofelia label in compose.prod.yml).

- **`Billing\Lifecycle`:** an account is locked while `past_due` (a failed charge, or no card when the free months end) or `canceled` (a withdrawal, or a cancellation that reached its date). On every site it owns (`mody_owner`), `user_has_cap` takes away the capabilities in `Lifecycle::DENIED` (editing, publishing, deleting, uploads, styles, menus, comments) from everyone but super admins, so the editors, the Site Editor and REST refuse (403). Reading, settings, Plan & billing and Download your site stay.
- **Mody** refuses on a locked site (`Agent\Budget::check()`, `mody_site_read_only`), which covers the chat, the analyst and every model-backed ability.
- **The banner** on every wp-admin screen of a locked site, and the same words as an error notice in the editors; the owner gets the link to Plan & billing.
- **Lifted at once** when a payment goes through: the lock comes from the status alone.
- **Day 53:** one last email with the download link (user meta `mody_billing_final_warning`). **Day 60:** the sites go to the network's trash (WordPress's `deleted` flag; a super admin can still restore them or delete them for good from Network Admin → Sites), and the account is `expired`. Counted from `past_due_since`.
- **The retries** (daily for 60 days) and the charge are in `Billing\Billing` (M1-01 part 3a).
- **The daily run** (`wp mody-billing run`) is started on production by the host's Ofelia scheduler, from the labels on the `wordpress` service in `compose.prod.yml.sample` (06:00 every day, `job-exec` as `www-data`, no overlap). The labels replace the stale newsletter job of compose.yml there (`!override`).
- **Not done:** permanent deletion of the trashed sites (a super admin's choice, from Network Admin), and the media files of a deleted site.
- **Test:** `scripts/billing-test.php` sections 10 to 12.

