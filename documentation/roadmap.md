# Roadmap

Milestones from the briefing (v4, October 2026). Status is as the briefing reports it. The last column records what the repository adds or changes.

| Phase | Milestone | Deliverable | Status | Repository notes |
| --- | --- | --- | --- | --- |
| 0 · Foundations | 0.1 | WordPress + FSE theme + Spectra on the VPS, "under construction" page + email form | In progress | FSE theme scaffold exists (`theme`). Spectra is not in `composer.json` yet. The NPM proxy host item does not apply (infrastructure decision, see [architecture.md](architecture.md#briefing-recommendations-not-adopted)). |
| | 0.2 | Blog with 3 foundational articles + Matomo + newsletter | Pending | Blog templates exist (`index`, `single`, `archive`, `search`). Matomo is not part of the stack. Analytics is open. |
| 1 · AI core | 1.1 | Python script connects to Ministral and returns valid JSON | Pending | **First task.** See below. |
| | 1.2 | Master prompt + block validator + whitelist | Pending | |
| | 1.3 | Bridge script: AI inserts content into a real WordPress | Pending | |
| 2 · Platform | 2.1 | WordPress Multisite operational | Pending | `wp-config.php` already reads the Multisite switches. |
| | 2.2 | Member registration + site panel + role gating (sites, sends, disk) | Pending | See [membership-tiers.md](membership-tiers.md). |
| | 2.3 | Brand profile backend + `theme.json` writing | Pending | See [brand-profile.md](brand-profile.md). Resolve the per-site styles question first. |
| 3 · Launch | 3.1 | Brand wizard (3 steps) + coherent generation + Newsletter integration | Pending | See [newsletter.md](newsletter.md). |
| | 3.2 | Memberships and payments (Mollie or Stripe, tiers 5/15/50 + custom VPS) | Pending | Payments provider is an open decision. |
| | 3.3 | Launch: 100 founding members | Pending | |

## First concrete step: Milestone 1.1

Implement `hello_ai.py`. It connects to `mistralai/Ministral-3-14B-Instruct-2512` through the Infomaniak API, takes the token from an environment variable and returns one valid Gutenberg paragraph block. Validate the serialized syntax before starting Milestone 1.2.

Sections 5, 6, 7 and 9 of the briefing (tiers, brand profile, coherent generation, Newsletter) define the target data architecture for Phases 2 and 3. Details are in [ai-engine.md](ai-engine.md) and the documents linked from the table above.
