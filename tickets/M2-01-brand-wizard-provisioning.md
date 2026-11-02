# M2-01 · Brand wizard + site provisioning pipeline

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6, §7, §11 · **Status:** todo

## Goal
The 3-step choice-based brand wizard creates a subdomain site that is fully provisioned: theme `theme` + market plugins, Global Styles from the chosen preset, and a generated, validated home page. The job is resumable and never leaves an unthemed site.

## Deliverables
- **Wizard** (MU plugin, runs from **+ New site** on the dashboard), all choices, no raw values (spec §6):
  - Step 1 identity: brand name (prefilled), one-sentence "what do you do?", tone cards (formal/friendly/technical with the same sentence rendered in each).
  - Step 2 design: **6 curated presets** (cards with swatches + mini mock), optional logo upload, Inter fixed; live preview of header + hero.
  - Step 3 first page: one textarea + suggestion chips → **Create my site**.
  - Server-side validation of every field (hex `^#[0-9a-fA-F]{6}$`, tone enum, lengths, logo via media library).
- Tables: `mody_brand_profiles`, `mody_provisioning_jobs` (spec §11).
- **Provisioning pipeline** (in-process, idempotent, resumable), steps `quota → profile → site → theme → styles → page → email → done` (spec §7):
  - `quota`: `can_create_site()` + network-wide slug uniqueness.
  - `site`: `wpmu_create_blog`, member added as `administrator` of the new site.
  - `theme`: activate `theme` + market plugins (Spectra at minimum).
  - `styles`: write the site's Global Styles (`wp_global_styles`) with preset palette + fonts (resolves the "per-site theme.json" open question in favor of Global Styles).
  - `page`: call the agent service (M2-02) to generate + validate + publish the front page; 2 retries; on final failure publish a fallback page + surface **Retry generation**.
  - `email`: welcome email with site URL + "Open your site in the editor" link (member's own login).
- `can_create_site()` quota check wired to `mody_members.plan` (3/10/50).

## Acceptance criteria (spec §7)
- End-to-end creation <2 min wall time for a member; result is a live subdomain with the chosen palette and a coherent generated home page.
- Killing the flow at any step leaves a working site; the job resumes; no step runs twice with side effects.
- Generation failure never blocks site creation (fallback page + retry).
- A first-time user can complete the wizard without help text (3 steps, prefilled).
