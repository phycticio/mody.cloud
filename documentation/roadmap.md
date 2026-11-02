# Roadmap

> **MVP work is planned in [mvp-spec.md](mvp-spec.md)** (agreed 2026-11-01) with tickets in `tickets/` (M0-01 to M5-01). The table below is the historical briefing milestone list; where it overlaps the MVP, mvp-spec.md wins.

Milestones from the briefing (v4, October 2026). Status is as the briefing reports it. The last column records what the repository adds or changes.

| Phase | Milestone | Deliverable | Status | Repository notes |
| --- | --- | --- | --- | --- |
| 0 · Foundations | 0.1 | WordPress + FSE theme + Spectra on the VPS, "under construction" page + email form | In progress | FSE theme scaffold exists (`theme`). Spectra is in `app/composer.json` per M4-02. The NPM proxy host item does not apply (infrastructure decision, see [architecture.md](architecture.md#briefing-recommendations-not-adopted)). |
| | 0.2 | Blog with 3 foundational articles + Matomo + newsletter | Pending | Blog templates exist (`index`, `single`, `archive`, `search`). Matomo is not part of the stack. Analytics is open. |
| 1 · AI core | 1.1 | Python script connects to Ministral and returns valid JSON | Pending | **First task.** See below. |
| | 1.2 | Master prompt + block validator + whitelist | Pending | |
| | 1.3 | Bridge script: AI inserts content into a real WordPress | Pending | |
| 2 · Platform | 2.1 | WordPress Multisite operational | Done (2026-11-01) | Subdomain network enabled locally in M0-01: wildcard TLS, two subsites owned by one non-super-admin member. See `tickets/M0-01-multisite-foundation.md` and [local-development.md](local-development.md#multisite-subdomain-network). |
| | 2.2 | Member registration + site panel + role gating (sites, sends, disk) | Pending | See [membership-tiers.md](membership-tiers.md). |
| | 2.3 | Brand profile backend + `theme.json` writing | Pending | See [brand-profile.md](brand-profile.md). Resolve the per-site styles question first. |
| 3 · Launch | 3.1 | Brand wizard (3 steps) + coherent generation + Newsletter integration | Pending | See [newsletter.md](newsletter.md). |
| | 3.2 | Memberships and payments (Mollie or Stripe, tiers 5/15/50 + custom VPS) | Pending | Payments provider is an open decision. |
| | 3.3 | Launch: 100 founding members | Pending | |

## First concrete step (superseded)

The MVP spec replaced the Milestone 1.1 `hello_ai.py` task. **M0-01 (subdomain Multisite locally with wildcard TLS) is done** as of 2026-11-01; it left one open question that blocks M1, WP-Cron on subsites ([architecture.md](architecture.md#open-questions)). Next up is **M1-01**. AI integration starts at M2-02 with the Qwen agent service (`tickets/M2-02-ai-agent-qwen-validator.md`).

Sections 5, 6, 7 and 9 of the briefing (tiers, brand profile, coherent generation, Newsletter) define the target data architecture for Phases 2 and 3. Details are in [ai-engine.md](ai-engine.md) and the documents linked from the table above.
