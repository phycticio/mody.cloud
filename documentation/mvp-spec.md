# MVP spec — Mody Cloud

**Status:** Agreed 2026-11-01 after team review. This document is the source of truth for the MVP. It supersedes the roadmap's milestone numbering for MVP work; [constraints.md](constraints.md) and the briefing-derived docs remain authoritative for everything else. Milestone exit criteria are the acceptance gate: a milestone is done when its exit criterion passes.

## 1. What the MVP is

A self-serve, European-sovereign WordPress Multisite platform:

1. A visitor registers on the main site with a **card** (Mollie) and gets a **30-day trial** equal to Tier 1 (3 sites).
2. At the end of the trial the selected monthly plan is charged (5/15/50 EUR for 3/10/50 sites). All tiers have the **same benefits**; the only MVP difference is the number of sites.
3. The member creates sites (subdomains) through a short **choice-based brand wizard**; each site is provisioned automatically: FSE theme `theme` + Spectra, per-site Global Styles from the chosen preset, and an AI-generated home page.
4. Everything is edited in the **native Site Editor** (Appearance → Editor). Every edit entry point — dashboard, agent, emails — links to the Site Editor URL.
5. The **agent** (Qwen via Infomaniak AI Services) is the encouraged control channel: change a color, generate a page, activate a market plugin, open the editor for a page, and so on. It decides by itself whether to act, hand over to the editor, or ask a follow-up question.
6. Members have **full control of their site** except: no plugin/theme upload, no theme switching, no file editing — only the curated (free) Mody market.

**Non-goals (next iteration, deliberately):**

- Custom domains per site, Caddy sidecar, domain resale (Caddy is the decided future approach; no code now).
- Newsletter / Infomaniak sending caps (confirmed out of scope).
- Dedicated VPS tier, per-tier resource quotas (storage, upload size), analytics.
- Fonts other than Inter; style variations beyond the six presets.
- Multi-language UI (rule 8: English everywhere).

## 2. Decisions recorded (2026-11-01 review)

| # | Decision | Value |
| --- | --- | --- |
| 1 | AI model & provider | `Qwen/Qwen3.8-27B-FP8` (project-scoped build) via Infomaniak AI Services, OpenAI-compatible API. Supersedes the Ministral decision in [ai-engine.md](ai-engine.md). |
| 2 | Payment provider | **Mollie** (EU). Supersedes the open question in [architecture.md](architecture.md#open-questions). |
| 3 | Trial | 30 days, equals **Tier 1 (3 sites)**. Card required at signup. |
| 4 | Expired accounts | Past-due sites go **read-only with a banner**; billing retried daily; after **60 days** the sites are deleted. |
| 5 | Tier site counts | **3 / 10 / 50**. More sites than that is a dedicated-VPS conversation. |
| 6 | Custom domains | Per **site** (not per page), **next iteration**. Caddy sidecar is the decided mechanism. No domain resale yet. |
| 7 | Plugin market | Membership: TBD (seed: Spectra + Redirection). **Everything is preinstalled via Composer; members only activate.** All plugins free. |
| 8 | Editor redirect | **All** content editing (pages and posts) redirects to the Site Editor on member sites. |
| 9 | Brand wizard | Choice-based, no raw inputs (section 6). |
| 10 | Newsletter | Out of MVP scope (confirmed). |
| 11 | Member dashboard | Custom "Mody" menu in the **Network Admin** (`/wp-admin/network/`). |
| 12 | Languages | UI English only; generated content language follows the member's prompt. |

## 3. Actors and access model

| Actor | Access |
| --- | --- |
| **Prospect** | Public pages on the main site (association site, site 1): landing, register. |
| **Member** | One network-wide WordPress account (shared login, cookies cover all subdomains via `COOKIE_DOMAIN`). Is `administrator` of each own subdomain site. Sees the custom **Mody** menu in the Network Admin: dashboard, new site, billing. |
| **Platform admin** | Super admin of the network, plus the Mody market management page and exception handling. |
| **Agent** | A server-side tool loop running in the Python agent service. Acts through internal, secret-protected endpoints; never exposed to the internet. |

Network-admin access for members: the standard network admin stays super-admin-only. The MU plugin adds its own `network_admin_menu` items with a custom capability (`mody_access_dashboard`) that is granted to every user with a `mody_members` row. All data shown is scoped to `get_current_user_id()`.

Member site URLs: `slug.mody.cloud` (local: `slug.mody.cloud.local`). Dashboard: `mody.cloud/wp-admin/network/`.

## 4. Tiers, trial and billing

| Tier | Role slug | Fee / month | Sites |
| --- | --- | --- | --- |
| Trial (30 days) | `trial` (benefits = `collaborator`) | 0 (card required at signup) | 3 |
| Tier 1 · Collaborator | `collaborator` | 5 EUR | 3 |
| Tier 2 · Member | `member` | 15 EUR | 10 |
| Tier 3 · Patron | `patron` | 50 EUR | 50 |

Fees as documented in [membership-tiers.md](membership-tiers.md); confirm before launch. Resource quotas (storage, upload size) arrive in a later iteration — MVP tiers differ **only** in site count.

### Signup flow (card required)

1. `mody.cloud/register/` (page on the main site): full name, email, password, tier selection (three cards: name, price, site count), **Create account**.
2. Server validates, creates the WP user (role `mody_member`, `mody_members.status = pending_payment`), creates a Mollie customer and a **0.01 EUR card-verification checkout**, redirects to Mollie.
3. Mollie webhook `payment.succeeded` (signature verified, idempotent) → account `active`, `trial_ends_at = billing_due_at = now + 30d`, payment method stored, welcome email, redirect back to the Mody dashboard (single-use token in the return URL).
4. No webhook within 24 h → cron marks the account `expired_signup`; the member can retry payment from the dashboard.

### Billing lifecycle

- **Trial end (day 30):** charge the tier price via the stored Mollie payment method. Success → recurring monthly (`billing_due_at` + 1 month).
- **Plan change:** selected in the dashboard; takes effect at the next billing date (both directions). Prorated upgrades: post-MVP.
- **Failed charge:** account `past_due`. All member sites become **read-only** (capability filter denies content/style mutation; front end and reading still work) with a persistent banner in wp-admin and on the dashboard: "Update your payment to keep editing". Daily retry for 60 days.
- **Day 60 past due:** sites are deleted (trashed network-wide after a final warning email at day 53 offering a full WP export link). The account remains for re-registration with a new card.

**Acceptance criteria**

- A new visitor with a valid card reaches an active trial in under 2 minutes; a duplicate email is rejected.
- Simulating a failed charge makes every site read-only (editor save, REST `wp/v2` update, and agent tools are all denied) and shows the banner.
- A plan change is reflected in the quota at the next cycle.
- All Mollie webhook handling is idempotent (replayed webhook changes nothing) and rejects bad signatures.

## 5. Member dashboard (Network Admin)

A `Mody` top-level menu in `/wp-admin/network/` for members:

- **My sites:** card per site (name, subdomain, status, created), buttons: *Open*, *Site editor*, *wp-admin*, *Delete* (soft; requires typing the slug; 30-day trash). Prominent **+ New site** (starts section 6) and a disabled state with the remaining quota ("2 of 3 sites used").
- **Plan & billing:** current plan, trial countdown or next charge date, *Change plan* (three tier cards), *Update card* (new Mollie checkout with the stored method), *Cancel* (effective end of cycle).
- Past-due: banner at the top of every dashboard page.

The dashboard is the only place where cross-site actions (create/delete site, billing) happen. Per-site actions always link into that site's own wp-admin / Site Editor.

## 6. Brand wizard

**Design principle: the member never types raw values** — no hex codes, no font names, no CSS. Every step is a **choice** from a small number of beautiful, prefilled options, with a live preview. The whole wizard can be completed in three clicks.

Entry: **+ New site** on the dashboard.

### Step 1 — Your brand (identity)

- **Brand name** — prefilled from the chosen site name; editable.
- **What do you do?** — one sentence (becomes the site tagline and part of the generation context). Placeholder example: "We bake sourdough bread for offices in Zurich."
- **Tone** — three cards, each showing the same sentence written in that tone:
  - *Formal* — "We provide professional sourdough services."
  - *Friendly* — "We bake for your team. Come hungry!"
  - *Technical* — "Precise levain management. Documented processes."

### Step 2 — Your design (visual)

- **Six curated Mody presets**, shown as cards: preset name, three swatches (primary / secondary / accent), and a mini mock (a heading + a button rendered in those colors). Selecting one updates a **live preview** of the site header and hero below.
- **Logo upload** — optional drag & drop into the media library.
- Fonts are **Inter, fixed, for the MVP** (the picker appears in a later iteration).
- Fine-tuning is deliberately *not* in the wizard: members can later say "make the primary color a bit warmer" to the agent or adjust Styles in the Site Editor.

Candidate presets (data-driven in the MU plugin, to be curated before M2 ships):

| Preset | Primary | Secondary | Accent |
| --- | --- | --- | --- |
| Mody Teal (default) | `#005f6b` | `#333333` | `#b50ba4` |
| Forest | `#2f5d3a` | `#333333` | `#c77d2e` |
| Ocean | `#0b4f6c` | `#22333b` | `#4ea8de` |
| Plum | `#5b2a86` | `#333333` | `#d94f8f` |
| Graphite | `#374151` | `#1f2937` | `#005f6b` |
| Sand | `#9a6b32` | `#333333` | `#0b4f6c` |

### Step 3 — Your first page

- One textarea: **"Describe the first page of your site."** with three suggestion chips ("Home page for my business", "About me", "Product page").
- **[Create my site]** → provisioning (section 7).

### What happens behind the scenes

- Preset colors are written to the site's **Global Styles** as the `primary` / `secondary` / `accent` palette overrides; generated content always references the *slugs*, never the hex (constraint 2/3).
- Name + one sentence + tone form the brand profile row and the **system prompt of every future generation** (constraint 4).
- The step-3 prompt is the user prompt for the home page generation.

**Acceptance criteria**

- A first-time user can create a site without reading any help text (3 steps, all prefilled).
- The preview in step 2 uses the real theme (header + hero rendered with the preset palette).
- The wizard validates everything server-side (hex `^#[0-9a-fA-F]{6}$`, tone enum, lengths, logo via media library).

## 7. Site provisioning pipeline

Runs **in-process** in the MU plugin (no REST, no Application Passwords — that simplifies the old flow in [brand-profile.md](brand-profile.md#site-creation-flow)). Each step writes to a `mody_provisioning_jobs` row, is idempotent, and is resumable; a site is never left unthemed.

| Step | Action |
| --- | --- |
| 1 `quota` | `can_create_site()` (section 4) and slug uniqueness (network-wide). |
| 2 `profile` | Save the brand profile row (network-wide table, `base_prefix`). |
| 3 `site` | `wpmu_create_blog(slug, slug.mody.cloud, '/', user_id)`; member added as `administrator` of the new site. |
| 4 `theme` | Activate theme `theme` and the market plugins (Spectra, …) on the new site. |
| 5 `styles` | Write the site's **Global Styles** (`wp_global_styles`) with the preset palette and fonts. |
| 6 `page` | Call the agent service: SYSTEM = brand profile, USER = step-3 prompt → model → **validator** (balanced blocks, valid JSON, whitelist from the live block registry, palette slugs only) → publish as front page. Up to 2 retries; on final failure publish a fallback "Hello, I'm {brand}" page and surface a **Retry generation** action. |
| 7 `email` | Welcome email: site URL + "Open your site in the editor" link (the member logs in with their own account — no separate credentials). |

Newsletter domain registration (old step 8) is **post-MVP**.

**Acceptance criteria**

- End-to-end creation takes under 2 minutes of wall time for a member, and the result is a live subdomain with the chosen palette and a coherent generated home page.
- Killing the flow at any step (simulated) leaves a working site and lets the job resume; no step is ever run twice with side effects.
- Generation failure never blocks site creation (fallback page + retry).

## 8. The agent

The agent is the encouraged control channel: *"Change this color. Install this plugin. Open the page editor for page N."* It is a **tool-calling loop**, not a text chat: the model decides whether to act (tool), hand over (open the editor), or ask a follow-up question.

### Components

- **UI:** a `Mody Agent` menu item in each member site's wp-admin: a simple chat page (plain HTML + fetch, no JS build toolchain) with action chips for common intents.
- **Relay:** the page posts to the MU plugin's REST endpoint `mody-platform/v1/chat` (logged-in user + nonce). The plugin relays to the Python agent service over the internal network.
- **Python agent service** (`agent/` at the repo root, new compose service, **no published ports**): assembles the system prompt from the site's brand profile, calls the model, executes tool calls through the internal tool endpoint, and returns the final message plus any actions.
- **Model:** `Qwen/Qwen3.8-27B-FP8` via Infomaniak AI Services (`https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`, all identifiers from environment variables). Native function calling if the model supports it; otherwise a strict JSON action protocol — same server-side guard either way. Low temperature (0.1).

### Tools (all executed server-side, all guarded)

| Tool | Arguments | Effect | Guard |
| --- | --- | --- | --- |
| `generate_page` | `prompt`, `title?`, `publish` | Brand-profile generation → validator → page (front page if first) | Quota-independent; requires an existing brand profile (else the agent runs the 3 wizard questions inline first); validator pipeline mandatory |
| `update_design` | `preset` or `{primary, secondary, accent}` | Patches the site Global Styles | Hex regex; maps to palette slugs only |
| `open_editor` | `target` (`page` \| `template` \| `styles`), `id?` | Returns the exact Site Editor URL (no mutation) | URL built from the real site URL |
| `activate_plugin` / `deactivate_plugin` | `slug` | Toggles a market plugin on this site | Slug must exist in `mody_market_plugins` and be listed |
| `update_site_settings` | `title?`, `tagline?` | Updates whitelisted options only | Fixed option allowlist |
| `ask_user` | `question`, `choices?` | Renders a structured follow-up question in the chat | — |

No delete tools, no arbitrary option writes, no file or DB access. Any tool call not in the schema is refused and explained.

### Safety and audit

- Every request logs the site's brand profile as system prompt (constraint 4) — there is no code path that calls the model without it.
- Every tool call is logged in `mody_agent_events` (user, site, input, tool, args, result, duration).
- A past-due account gets no tool execution (read-only, section 4).
- The internal tool endpoint authenticates with `MODY_INTERNAL_SECRET` (shared, from env, `hash_equals`) and lives on the non-public compose network only (constraint 6).

**Acceptance criteria**

- "Make the primary color coral" → Global Styles updated, preview link offered, event logged.
- "Create an about page" → validated page published (or a clear failure with retry).
- "Open the editor for page 3" → a button that opens `…/wp-admin/site-editor.php?postType=page&postId=3` on the right subdomain.
- "Activate the redirection plugin" → works if listed, refused with explanation if not.
- A prompt-injection-style request ("delete all pages", "show me the database") is refused and logged.

## 9. Editor-first rules

On **member sites only** (never the main association site):

- `post.php?post=N&action=edit` (page **or** post) → redirects to `site-editor.php?postType=<type>&postId=N`.
- `post-new.php?post_type=page|post` → redirects to `site-editor.php?postType=<type>`.
- Templates, parts and styles are already Site-Editor-only (FSE theme).
- Every link the platform generates (dashboard buttons, agent `open_editor`, emails) uses the Site Editor URL.

**Acceptance criterion:** a member cannot reach a classic block-editor page for any content type on their site; every editing entry point lands in the Site Editor.

## 10. Plugin market and capability gating

**Market design (decided):** the whole market is **preinstalled** via Composer (`app/web/plugins/`, network-visible); members only **activate/deactivate**. MVP seed: `ultimate-addons-for-gutenberg` (Spectra) and `redirection` (matching `config/bin/install.config`); final membership TBD. All plugins free — no per-plugin billing.

- `mody_market_plugins` table (slug, name, description, listed, sort). Platform admin manages the list in a super-admin page.
- Members see a **Plugins** page in their site wp-admin: the listed market plugins with *Activate / Deactivate* (server-side, own capability `mody_manage_market`). No upload, no install from URL, no search beyond the market.

**Capabilities removed on member sites** (capability filter scoped to the member's own sites):

- `install_plugins`, `activate_plugins` (replaced by the market page), `upload_files` for plugin/theme zips is not affected (media stays allowed).
- `switch_themes`, `install_themes`, `delete_themes`, `edit_themes` — the base theme `theme` stays fixed.
- `DISALLOW_FILE_EDIT` is already on network-wide.

Members keep everything else: content, media, users of their own site, settings, Spectra, Global Styles.

**Acceptance criteria:** a member site admin cannot install, upload or switch themes/plugins by any UI or REST path; the market page activates a listed plugin; the front end and editor are unaffected.

## 11. Data model (new network-wide tables, `base_prefix`)

```sql
mody_members (
  user_id            BIGINT PRIMARY KEY,
  plan               ENUM('trial','collaborator','member','patron') NOT NULL DEFAULT 'trial',
  status             ENUM('pending_payment','active','past_due','expired') NOT NULL,
  trial_ends_at      DATETIME,
  billing_due_at     DATETIME,
  past_due_since     DATETIME,
  mollie_customer_id VARCHAR(64),
  mollie_payment_method_id VARCHAR(64),
  created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

mody_brand_profiles (           -- see brand-profile.md
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  site_id         BIGINT NOT NULL,
  brand_name      VARCHAR(120) NOT NULL,
  mission         TEXT,          -- the one sentence
  tone_of_voice   ENUM('formal','friendly','technical') DEFAULT 'friendly',
  color_primary   VARCHAR(7) DEFAULT '#005f6b',
  color_secondary VARCHAR(7) DEFAULT '#333333',
  color_accent    VARCHAR(7) DEFAULT '#b50ba4',
  logo_url        VARCHAR(255),
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY user_site (user_id, site_id)
);

mody_provisioning_jobs (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  site_id    BIGINT,
  step       ENUM('quota','profile','site','theme','styles','page','email','done') NOT NULL,
  status     ENUM('running','done','failed') NOT NULL,
  attempts   INT DEFAULT 0,
  last_error TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

mody_market_plugins (
  id          BIGINT AUTO_INCREMENT PRIMARY KEY,
  plugin_slug VARCHAR(190) UNIQUE NOT NULL,
  name        VARCHAR(120) NOT NULL,
  description TEXT,
  listed      TINYINT(1) DEFAULT 1,
  sort        INT DEFAULT 0
);

mody_agent_events (
  id         BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    BIGINT NOT NULL,
  site_id    BIGINT NOT NULL,
  input      TEXT,
  tool       VARCHAR(60),
  args       JSON,
  result     TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

`mody_custom_domains` is **not** created yet (next iteration with the Caddy work).

## 12. Architecture deltas

Everything else in the current stack is unchanged ([architecture.md](architecture.md)).

| Delta | Detail |
| --- | --- |
| Multisite on | `WORDPRESS_ALLOW_MULTISITE` / `WORDPRESS_MULTISITE` / `WORDPRESS_SUBDOMAIN_INSTALL` = `true` (subdomain mode). `wp-config.php` already handles the constants. |
| Wildcard TLS | `APP_DOMAINS` gains `*.${HOST_NAME}` (local: mkcert wildcard; production: upstream wildcard cert for `*.mody.cloud`). `compose.local.yml` already wires the wildcard `server_name`. |
| MU plugin | Project-owned `app/web/mu-plugins/mody-platform/` (tracked, always active, **not** Composer-managed). Holds: registration, billing, dashboard, provisioning, wizard, market, gating, editor redirects, chat relay. Resolves the "where gating lives" open question. |
| Agent service | New compose service built from `agent/Dockerfile` (Python 3.11-slim, `requests` only). No published ports. Env: `MODY_AI_BASE_URL`, `MODY_AI_MODEL`, `MODY_AI_TOKEN`, `MODY_INTERNAL_SECRET`, `WORDPRESS_INTERNAL_URL=http://nginx` (requests carry the target site's `Host` header). |
| Cron | WP-Cron (already enabled): hourly jobs — due billing, past-due sweep (banner, day-53 warning, day-60 deletion), job retry, expired-signup cleanup. |
| Env additions | `MOLLIE_API_KEY`, `MOLLIE_WEBHOOK_KEY`, `MODY_*` as above. Nothing committed; `.env` stays untracked (constraint 6). |
| Nginx | No change beyond the existing wildcard block; custom-domain `server_name` generation is next iteration. |

## 13. Milestones

Ordered; M1 and M2 can partially overlap after M0. Estimates are rough (rule 5: if a task exceeds ~2 days, split it).

| # | Milestone | Scope (section) | Exit criterion |
| --- | --- | --- | --- |
| **M0** | Multisite foundation | 3, 12 | Flip the multisite env switches, add the wildcard to `APP_DOMAINS`, document the network activation step; two subdomain sites reachable over HTTPS locally, both running theme `theme`, owned by one non-super-admin user. |
| **M1** | Accounts, trial and billing | 4, 5 | Register → Mollie 0.01 checkout → webhook → active trial with dashboard (My sites, Plan & billing). Simulated failed charge → read-only + banner + 60-day deletion job verified. |
| **M2** | Site creation | 6, 7, 11 | Wizard (3 choice steps) → provisioned subdomain with Global Styles and a generated, validated home page; job table resumable; fallback page on generation failure. First real model call through Infomaniak (verify model slug + function-calling support here). |
| **M3** | Agent | 8 | Chat in wp-admin; all six tools work end-to-end; injection attempts refused and logged; every event in `mody_agent_events`. |
| **M4** | Gating, market and editor-first | 9, 10 | No plugin/theme upload or theme switch possible on member sites; market page activates listed plugins; every editing entry point lands in the Site Editor. |
| **M5** | Hardening and launch | all | Production compose (no Xdebug/DB ports), wildcard cert at the upstream, backup/restore drill, security pass (nonces, sanitization, webhook signatures), monitoring via Query Monitor, launch checklist green for 100 founding members. |

## 14. Next iteration (out of MVP, decided direction)

- **Custom domains per site + Caddy sidecar** (ACME; DNS-instruction flow: member requests → platform replies with exact records → cron verifies → cert → activate). No domain resale yet.
- **Newsletter** (Infomaniak, per-tier sending caps) per [newsletter.md](newsletter.md).
- **Dedicated VPS** tier; per-tier resource quotas (storage, upload size, AI volume).
- Font choice (locally served `fontFace`), style variations, more presets.
- Analytics (EU-hosted, GDPR-coherent, undecided).

## 15. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Model output breaks block syntax | Validator pipeline + 2 retries + fallback page; provisioning never blocks on the model (section 7). |
| Function calling unsupported by the model | JSON action protocol fallback with identical server-side guard (section 8). |
| Mollie webhook loss / duplicates | Signature check + idempotency + 24 h expiry cron + manual retry from dashboard. |
| Network admin exposed to non-members | Custom capability on the custom menu only; all queries user-scoped; standard network admin untouched. |
| Agent performs unsafe actions | Whitelisted tools only, fixed option allowlist, market allowlist, audit log, no delete tools. |
| Stale Tamarind scripts | Untouched by the MVP; cleanup scheduled after M5 (see [local-development.md](local-development.md#known-stale-items)). |

## 16. Open questions (remaining)

1. **Mollie account** — test-mode keys available? (Needed at M1 start.)
2. **Production SMTP** — which relay for real mail (Infomaniak Mail or self-hosted)? Needed by M5.
3. **Upstream wildcard TLS** — confirm the production front end can terminate a wildcard cert for `*.mody.cloud` (infra task, M5).
4. **Infomaniak AI** — exact model slug + product ID + function-calling support (verify with `GET /1/ai/models` at M2 start).
5. **Final market membership** — beyond Spectra + Redirection (TBD, user-confirmed).
6. **Fee confirmation** — 5/15/50 EUR unchanged for 3/10/50 sites (assumed).
