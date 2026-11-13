# MVP spec — Mody Cloud

**Status:** Agreed 2026-11-01 after team review. This document is the source of truth for the MVP. It supersedes the roadmap's milestone numbering for MVP work; [constraints.md](constraints.md) and the briefing-derived docs remain authoritative for everything else. Milestone exit criteria are the acceptance gate: a milestone is done when its exit criterion passes.

## 1. What the MVP is

A self-serve, European-sovereign WordPress Multisite platform:

1. A visitor answers four questions in a chat on the main site and gets their first site at once, with **no card** (decided 2026-11-08, [M1-03](../tickets/M1-03-chat-signup-and-editor-tour.md)): during the first three months, the first 50 accounts get it **free for six months** (the launch offer, decided 2026-11-13; it replaced the 30-day trial with 3 sites).
2. **Every site costs 7.99 EUR a month or 79.99 EUR a year, VAT included** (decided by the maintainer on 2026-11-13; it replaced the 1, 3 and 10-site plans of 2026-11-10, which had replaced 5/15/50 EUR for 3/10/50 sites), charged when the free months end. Every site gets the **same features** (1 GB, every plugin in the market, its own Mody with 5 million tokens a month, a custom domain, a way out), with no discount for more sites. [membership-tiers.md](membership-tiers.md).
3. The member creates sites (subdomains) through a short **choice-based brand wizard**; each site is provisioned automatically: FSE theme `theme` + Spectra, per-site Global Styles from the chosen preset, and an AI-generated home page.
4. Everything is edited in the **native Site Editor** (Appearance → Editor). Every edit entry point — dashboard, agent, emails — links to the Site Editor URL.
5. The **agent** (Qwen via Infomaniak AI Services) is the encouraged control channel: change a color, generate a page, switch a plugin on, open the editor for a page, and so on. It decides by itself whether to act, hand over to the editor, or ask a follow-up question.
6. Members have **full control of their site** except: no plugin/theme install or upload, no theme switching, no file editing. They switch on, for their own site, the (free) plugins the platform installs, on core's Plugins screen; platform plugins and any a super admin hides never appear there (section 10).

**Non-goals (next iteration, deliberately):**

- Custom domains per site, Caddy sidecar, domain resale (Caddy is the decided future approach; no code now). **Changed 2026-11-10:** the home page offers custom domains as "soon", so they are needed by the launch (domain resale stays out).
- Newsletter / Infomaniak sending caps (confirmed out of scope).
- Dedicated VPS tier, analytics. (The space per site is enforced since 2026-11-11: 1 GB since 2026-11-13.)
- A font step in the wizard (members install fonts in the Site Editor since M2-07); style variations beyond the six presets.
- Multi-language UI (rule 8: English everywhere). Sites in several languages are a separate thing, decided 2026-11-10 (decision 12).

## 2. Decisions recorded (2026-11-01 review)

| # | Decision | Value |
| --- | --- | --- |
| 1 | AI model & provider | `Qwen/Qwen3.8-27B-FP8` (project-scoped build) via Infomaniak AI Services, OpenAI-compatible API. Supersedes the Ministral decision in [ai-engine.md](ai-engine.md). |
| 2 | Payment provider | **Mollie** (EU). Supersedes the open question in [architecture.md](architecture.md#open-questions). |
| 3 | Trial | ~~30 days, 3 sites~~ Replaced on 2026-11-13 by the **launch offer**: the first 50 accounts in the first three months, one site free for six months (section 4). **No card at signup** (changed by the maintainer on 2026-11-08, [M1-03](../tickets/M1-03-chat-signup-and-editor-tour.md); it was "Card required at signup"). The card is asked for before the free months end. |
| 4 | Expired accounts | Past-due sites go **read-only with a banner**; billing retried daily; after **60 days** the sites are deleted. |
| 5 | Plan site counts | **1 / 3 / 10** (changed by the maintainer on 2026-11-10; it was 3 / 10 / 50). Monthly or yearly. More than 10 sites is not offered. [membership-tiers.md](membership-tiers.md). |
| 6 | Custom domains | Per **site** (not per page). Caddy sidecar is the decided mechanism. No domain resale yet. Was "next iteration"; since 2026-11-10 every plan includes them and the home page says "soon". |
| 7 | Plugin market | **Everything is preinstalled via Composer; members only activate**, on core's Plugins screen of their own site (redesigned 2026-11-08). Every installed plugin appears there unless hidden: platform plugins always (fixed in code), and any plugin a super admin hides. **No plugin is offered yet**: Redirection, the first seed, was dropped on 2026-11-08 (it came from the old stack). Spectra is network-active instead (decision 14). All plugins free. Each plugin is checked against rule 1 before it goes into Composer. New sites get none switched on. Section 10. |
| 8 | Editor redirect | **All** content editing (pages and posts) redirects to the Site Editor on member sites. |
| 9 | Brand wizard | Choice-based, no raw inputs (section 6). |
| 10 | Newsletter | Out of MVP scope (confirmed). |
| 11 | Member dashboard | Custom "Mody" menu in the **Network Admin** (`/wp-admin/network/`). |
| 12 | Languages | UI English only; generated content language follows the member's prompt. **Added 2026-11-10:** every site can have other languages (the `mody-languages` MU plugin, [tickets/site-languages.md](../tickets/site-languages.md)): translations as pages, `/es/` addresses, a language switcher and the translation matrix on Settings → Languages; Mody translates pages as drafts. |
| 13 | Agent actions ("Option C", decided 2026-11-06) | Agent actions are **WordPress abilities** (Abilities API, category `mody`), used by the in-site agent through `using_abilities()` and by MCP clients through mcp-adapter. The site's brand (its settings, M2-08) goes into every Mody prompt, and into the `ai` plugin's writing features (through its `wpai_system_instruction` filter; which features, decided 2026-11-06, in section 8). Members get a curated set of `ai` features. M4 locks the plugin's settings, connector approvals and MCP on member sites. The plugin's request log is the basis for per-member cost tracking. Section 8. |
| 14 | Spectra (decided 2026-11-06, changed 2026-11-08) | The platform's block library: a **MU plugin** since 2026-11-08 (network-activated before), not a market toggle. `SpectraGuard` in the MU plugin switches off every part that connects outside Europe (rule 1). It stays: the fork into a `mody/*` library is set aside, and the agent will use every Spectra block but the popup builder (ticket M3-11). Section 10. |
| 15 | wp-admin colors (decided 2026-11-08) | **Ocean** is every user's admin color scheme by default: new users get it, and users on WordPress's default (Modern) were moved to it once. Anyone may choose another scheme. The agent chat follows the user's scheme: buttons and borders in its accent, the user's own messages in the second color of its palette. [tickets/admin-colors.md](../tickets/admin-colors.md). |

## 3. Actors and access model

| Actor | Access |
| --- | --- |
| **Prospect** | Public pages on the main site (site 1): the home page, which is the landing page (what Mody Cloud is, how it works, the agent, sovereignty, the plans, questions) and holds the signup chat; and `/register/`. The home page is built by `scripts/home-page.php` (2026-11-10), which also sets the header menu to its sections. |
| **Member** | One network-wide WordPress account (shared login, cookies cover all subdomains via `COOKIE_DOMAIN`). Is `administrator` of each own subdomain site. Sees the custom **Mody** menu in the Network Admin: dashboard, new site, billing. |
| **Platform admin** | Super admin of the network, plus the list of plugins hidden from members and exception handling. |
| **Agent** | A server-side tool loop in the MU plugin, using the WordPress AI Client. Tools are WordPress abilities with permission callbacks (decision 13). They run in-process for the agent, and for authenticated users through the REST abilities endpoint and MCP. Never anonymously. |

Network-admin access for members: the standard network admin stays super-admin-only. The MU plugin adds its own `network_admin_menu` items with a custom capability (`mody_access_dashboard`) that is granted to every user with a `mody_members` row. Since 2026-11-06 (M2-03) the **Mody → New site** wizard checks it. Since 2026-11-08 (M1-01 part 1, `Members\Members::grant_dashboard()`) every user with a `mody_members` row has it, whatever the account's status. Nothing creates rows yet except tests; registration is part 2. Network Admin has no global super-admin check, so such a member opens the Mody pages and sees only the Mody menu (checked over HTTP). All data shown is scoped to `get_current_user_id()`.

Member site URLs: `slug.mody.cloud` (local: `slug.mody.cloud.local`). Dashboard: `mody.cloud/wp-admin/network/`.

## 4. Price, launch offer and billing

| | Per site | VAT |
| --- | --- | --- |
| Monthly | 7.99 EUR | included |
| Yearly | 79.99 EUR (ten months for twelve) | included |
| Launch offer | free for six months, one site, no card | – |

Decided by the maintainer on 2026-11-13 ([membership-tiers.md](membership-tiers.md), [tickets/pricing-per-site.md](../tickets/pricing-per-site.md)). It replaced the plans of 2026-11-10 (1, 3 and 10 sites) and the 30-day trial with 3 sites. The launch offer: during the first three months, the first 50 accounts, one site each, free for six months (`Members::start_free()`); once the places are taken or the months have passed, the signup says so and refuses until paid signups open with Mollie. An account pays the price times its sites, up to 10 sites (`Members::SITE_LIMITS`). In the table (section 11) `plan` is `free` or `paid`, the free months end at `free_until`, and `billing_period` is monthly or yearly.

### Signup flow (a chat, no card; decided 2026-11-08)

Built 2026-11-08 ([M1-03](../tickets/M1-03-chat-signup-and-editor-tour.md), on top of [M1-01](../tickets/M1-01-registration-trial-billing.md) parts 1 and 2; tests `scripts/members-test.php`, `scripts/registration-test.php`, `scripts/onboarding-test.php`). It replaces the card-first flow agreed on 2026-11-01 (full name, email, password and a plan, then a 0.01 EUR Mollie check before the trial).

1. **`mody.cloud/register/`**, a page on the main site holding the PHP-only block `mody/register` (`Members\Registration`). Mody asks, one question at a time: the visitor's name, their email, the name of their project or website, and its address, shown as `<slug>.mody.cloud` (suggested from the project's name, with **Keep it**). Each answer is checked on the server before the next question (`POST mody-platform/v1/register/check`). A summary, then **Create my site** (`POST mody-platform/v1/register`). Without JavaScript the block is a form with the same four fields.
2. **The server** validates again and creates, in the same request:
   - the WP user, with a random password and no role on the main site (decided 2026-11-08: the `mody_members` row is the membership, not a `mody_member` role);
   - a place of the launch offer: `plan = free`, `status = active`, `free_until = billing_due_at = now + 6 months` (`Members::start_free()`; it was a 30-day trial with 3 sites until 2026-11-13). Once the offer has ended and Mollie is set up, a paid sign-up instead: `plan = paid`, `status = pending_payment`, no site, and an email with a link to pay the first month; the site and the welcome email follow the payment (M1-01 part 3b, 2026-11-13);
   - the first site, through the provisioning pipeline as a **starter** site: no model call (the visitor is anonymous, and the brand has no mission yet), the default preset and tone, an empty tagline, and a starter front page;
   - the welcome email: the site's address, the last free day and the price after it, and a link to choose a password (WordPress's own reset screen, valid 24 hours).
3. **The first password** logs the member in and opens their site's **Identity** screen (Appearance → Editor → Identity), where the **editor tour** runs: the agent, two requests to it, then the Site Editor's parts (`Onboarding\Onboarding`, `Onboarding\EditorTour`). Later password resets behave as in WordPress.
4. **Closed until opened:** the network option `mody_registration_open`. Before opening it in production: a rate limit (M5-01; the bot check is ALTCHA since 2026-11-10, [tickets/antibot-altcha.md](../tickets/antibot-altcha.md)), the SMTP relay (M5-02; the welcome email is the only way in), the wildcard DNS and TLS (M5-02).
5. **Not built yet:** the card before the free months end (M1-01 part 3, reworked); removing accounts that never set a password, with their site (needs the WP-Cron decision).

The statuses `pending_payment` and `expired_signup` stay in the table for the card step that comes before the free months end. A member in either sees "Finish your payment" on the dashboard and creates no site (decided 2026-11-08); since the change, nothing puts a member in them.

### Billing lifecycle

- **Before the free months end** (decided 2026-11-13, built with M1-01 part 3): an email a month before and another a week before; the member chooses monthly or yearly on the dashboard and gives a card (a Mollie checkout). **Without a card at the end:** the account is `past_due`, like a failed charge below (read-only for 60 days, then deleted).
- **The end of the free months:** charge the price times the account's sites via the stored Mollie payment method. Success → recurring monthly or yearly (`billing_due_at` + 1 month or + 1 year).
- **A new site on a paying account:** counted from the next billing date (the charge counts the sites then). **Monthly or yearly:** chosen in the dashboard; takes effect at the next billing date. **Cancel** (built 2026-11-13): the plan ends at the end of the paid period, nothing more is charged, then read-only and deletion as below.
- **Failed charge:** account `past_due`. All member sites become **read-only** (capability filter denies content/style mutation; front end and reading still work) with a persistent banner in wp-admin and on the dashboard: "Update your payment to keep editing". Daily retry for 60 days.
- **Day 60 past due (or canceled):** sites go to the network's trash (WordPress's `deleted` flag, restorable by a super admin) after a final warning email at day 53 with the download link; the account is `expired`. Built 2026-11-13 (`Billing\Lifecycle`, M1-02); the daily run is an Ofelia job on production.

**Acceptance criteria**

- A new visitor reaches their first site, free for six months, in under 2 minutes, with no card; a duplicate email or a taken address is rejected (M1-03, checked by `scripts/registration-test.php`).
- Simulating a failed charge makes every site read-only (editor save, REST `wp/v2` update, and agent tools are all denied) and shows the banner.
- The 51st signup of the launch offer is refused, and so is any signup after its three months (`scripts/members-test.php`, `scripts/registration-test.php`).
- All Mollie webhook handling is idempotent (replayed webhook changes nothing). Mollie's webhook carries only a payment ID, so instead of a signature the platform asks Mollie for the payment with its key and acts only on payments it started (built 2026-11-13, `Billing\Billing`, `scripts/billing-test.php`).

## 5. Member dashboard (Network Admin)

> **Changed on 2026-11-11** ([tickets/mody-dashboard.md](../tickets/mody-dashboard.md)): the dashboard is every site's own Dashboard (`admin.php?page=mody-dashboard`, replacing core's), not a Network Admin page. Overview and storage; Visitors (the `mody-analytics` MU plugin); Mody's work (messages and tokens on design and configuration); Your sites and Plan & billing for members. The wizard stays in Network Admin as **New site**. Deleting a site and payments are not built yet. What follows is the original plan.

A `Mody` top-level menu in `/wp-admin/network/` for members:

- **My sites:** card per site (name, subdomain, status, created), buttons: *Open*, *Site editor*, *wp-admin*, *Delete* (soft; requires typing the slug; 30-day trash). Prominent **+ New site** (starts section 6) and a disabled state with the remaining quota ("2 of 3 sites used").
- **Plan & billing:** the free months left or what the account pays (its sites times the price), the next charge date, *How you pay* (monthly or yearly; since 2026-11-13), *Update card* (new Mollie checkout with the stored method), *Cancel* (effective end of cycle).
- Past-due: banner at the top of every dashboard page.

The dashboard is the only place where cross-site actions (create/delete site, billing) happen. Per-site actions always link into that site's own wp-admin / Site Editor.

## 6. Brand wizard

**Design principle: the member never types raw values** — no hex codes, no font names, no CSS. Every step is a **choice** from a small number of beautiful, prefilled options, with a live preview. The whole wizard can be completed in three clicks.

Entry: **+ New site** on the dashboard. Until M1 builds the dashboard, the wizard lives under Network Admin → **Mody**, for super admins (decided 2026-11-06, [M2-03](../tickets/M2-03-brand-wizard.md)). Built 2026-11-06 ([M2-03](../tickets/M2-03-brand-wizard.md)), on top of the provisioning pipeline ([M2-01](../tickets/M2-01-site-provisioning.md)). How it works: [brand-profile.md](brand-profile.md#wizard-steps). The logo upload moved to [M2-05](../tickets/M2-05-wizard-logo.md) (decided 2026-11-06, rule 5), built 2026-11-07.

**A member's first site comes from the signup, not from the wizard** (2026-11-08, [M1-03](../tickets/M1-03-chat-signup-and-editor-tour.md)): the signup chat asks only for the site's name and address, and the member gives the rest (mission, tone, colors, pages) to the agent or in the Site Editor, guided by the editor tour. The wizard creates the member's further sites.

### Step 1 — Your brand (identity)

- **Brand name** — prefilled from the chosen site name; editable.
- **What do you do?** — one sentence (becomes the site tagline and part of the generation context). Placeholder example: "We bake sourdough bread for offices in Zurich."
- **Tone** — three cards, each showing the same sentence written in that tone:
  - *Formal* — "We provide professional sourdough services."
  - *Friendly* — "We bake for your team. Come hungry!"
  - *Technical* — "Precise levain management. Documented processes."

### Step 2 — Your design (visual)

- **Six curated Mody presets**, shown as cards: preset name, three swatches (primary / secondary / accent), and a mini mock (a heading + a button rendered in those colors). Selecting one updates a **live preview** of the site header and hero below.
- **Style variations** (decided 2026-11-07, [M2-06](../tickets/M2-06-presets-as-style-variations.md)): the six presets become the theme's style variations (`themes/theme/styles/*.json`), shown in the Site Editor's Styles and editable per site; the wizard reads them. **Typography** (built 2026-11-07, [M2-07](../tickets/M2-07-font-repository.md)): members install fonts in the Site Editor's Font Library from the Mody fonts collection, ten free families (SIL OFL) served by the network from the MU plugin; the styles keep the theme's Inter, which is now real Inter. Core's Google Fonts collection is removed from the Font Library on every site (rule 1, 2026-11-07).
- **Logo upload** — optional drag & drop into the media library. Built 2026-11-07 as a file field ([M2-05](../tickets/M2-05-wizard-logo.md)): PNG, JPEG, GIF or WebP, at most 2 MB, shown in the live preview; the pipeline copies it into the new site's media library as its site logo.
- The wizard sets no font: every site starts with **Inter**. Members change fonts later in the Site Editor (Styles → Typography), from the Mody fonts collection (M2-07).
- Fine-tuning is deliberately *not* in the wizard: members can later say "make the primary color a bit warmer" to the agent or adjust Styles in the Site Editor.

The presets (kept as they are for M2, decided 2026-11-06). Since 2026-11-07 (M2-06) they are the theme's style variations: `theme.json` is Mody Teal, and `themes/theme/styles/forest.json`, `graphite.json`, `ocean.json`, `plum.json` and `sand.json` the others. `src/Design/Presets.php` reads them and is the single source for the wizard, provisioning and the agent's `mody/update-design`. The Site Editor lists them under Styles → Browse styles. On 2026-11-07 four colors were darkened, keeping their hue, so every pair passes WCAG AA (theme-development.md, "Contrast of the presets"): Forest, Ocean and Plum's accents, and Sand's primary.

| Preset | Primary | Secondary | Accent |
| --- | --- | --- | --- |
| Mody Teal (default) | `#005f6b` | `#333333` | `#b50ba4` |
| Forest | `#2f5d3a` | `#333333` | `#9c6224` |
| Ocean | `#0b4f6c` | `#22333b` | `#2075a9` |
| Plum | `#5b2a86` | `#333333` | `#c92c75` |
| Graphite | `#374151` | `#1f2937` | `#005f6b` |
| Sand | `#926630` | `#333333` | `#0b4f6c` |

### Step 3 — Your first page

- One textarea: **"Describe the first page of your site."** with three suggestion chips ("Home page for my business", "About me", "Product page").
- **[Create my site]** → provisioning (section 7).

### What happens behind the scenes

- Preset colors are written to the site's **Global Styles** as the `primary` / `secondary` / `accent` palette overrides; generated content always references the *slugs*, never the hex (constraint 2/3).
- Name (the site title), one sentence (the tagline and the mission setting) and tone (a site setting) are the site's brand and the **system prompt of every future generation** (constraint 4). Since 2026-11-08 they live in the site's own settings (M2-08).
- The step-3 prompt is the user prompt for the home page generation.

**Acceptance criteria**

- A first-time user can create a site without reading any help text (3 steps, all prefilled).
- The preview in step 2 uses the real theme (header + hero rendered with the preset palette). Built 2026-11-07 ([M2-04](../tickets/M2-04-wizard-live-preview.md)).
- The wizard validates everything server-side (hex `^#[0-9a-fA-F]{6}$`, tone enum, lengths, logo via media library).

## 7. Site provisioning pipeline

Runs **in-process** in the MU plugin (no REST, no Application Passwords — that simplifies the old flow in [brand-profile.md](brand-profile.md#site-creation-flow)). Each step writes to a `mody_provisioning_jobs` row, is idempotent, and is resumable; a site is never left unthemed.

**Starter sites** (the signup's, 2026-11-08, [M1-03](../tickets/M1-03-chat-signup-and-editor-tour.md)): the job's input has `starter`, with only a slug and a title. The mission and the prompt may be empty, the `page` step publishes a starter front page (the site's name and "Our new website is on its way") without a model call, and the `email` step sends nothing, because the signup's welcome email replaces it. **Every new site** (signup and wizard) also refuses the reserved addresses in `Pipeline::RESERVED_SLUGS` (`www`, `mail`, `api`, `admin`, `plugins` and other infrastructure names), besides core's `illegal_names`.

**Built 2026-11-06** ([M2-01](../tickets/M2-01-site-provisioning.md)): `Provisioning\Pipeline` in the MU plugin, tested by `scripts/provisioning-test.php`. How it works: [brand-profile.md](brand-profile.md#site-creation-flow). The profile row needs the new site's ID, so `profile` runs after `site`. The table below is in the order the steps run.

| Step | Action |
| --- | --- |
| 1 `quota` | `can_create_site()` (section 4) and slug uniqueness (network-wide). |
| 2 `site` | `wpmu_create_blog(slug, slug.mody.cloud, '/', user_id)`; member added as `administrator` of the new site. Built: created public, with the theme, the tagline and `https://` addresses already set; WordPress's sample post and page removed. |
| 3 `profile` | The brand in the site's own settings (M2-08): the logo as `site_logo`, and the `mody_mission` and `mody_tone` site settings. The title and tagline were set with the site, the colors go to Global Styles in step 5. (Until 2026-11-08: a brand profile row.) |
| 4 `theme` | Activate theme `theme` on the new site. Spectra is a MU plugin, on every site already (section 10). Built: the theme is set in step 2 and checked here. **No plugin is switched on** (decided 2026-11-08, M4-02): new sites start lean, and members switch plugins on from their Plugins screen or through the agent. |
| 5 `styles` | Write the site's **Global Styles** (`wp_global_styles`) with the preset palette and fonts. Built: the whole preset (`SiteDesign::apply_preset()`, M2-06); Inter is the theme's default, served by the network (M2-07). |
| 6 `page` | Call the agent (WordPress AI Client): SYSTEM = the site's brand (its settings), USER = step-3 prompt → model → **validator** (balanced blocks, valid JSON, whitelist from the live block registry, palette slugs only) → publish as front page. Up to 2 retries; on final failure publish a fallback "Hello, I'm {brand}" page and surface a **Retry generation** action. Built: `PageGenerator::generate_page()` in the new site's context; the page uses the theme's untitled template (`page-blank`); `Pipeline::retry_generation()` is the retry. The layout, the fallback page's too, is Spectra containers (2026-11-07, [ai-engine.md](ai-engine.md#layout-spectra-containers)). |
| 7 `email` | Welcome email: site URL + "Open your site in the editor" link (the member logs in with their own account — no separate credentials). Built: sent at most once; a failed email does not fail the job. |

Newsletter domain registration (old step 8) is **post-MVP**.

**Acceptance criteria**

- End-to-end creation takes under 2 minutes of wall time for a member, and the result is a live subdomain with the chosen palette and a coherent generated home page.
- Killing the flow at any step (simulated) leaves a working site and lets the job resume; no step is ever run twice with side effects.
- Generation failure never blocks site creation (fallback page + retry).

## 8. The agent

The agent is the encouraged control channel: *"Change this color. Install this plugin. Open the page editor for page N."* It is a **tool-calling loop**, not a text chat: the model decides whether to act (tool), hand over (open the editor), or ask a follow-up question.

### Components

Built 2026-11-06 as the first slice of M3-01: the chat page, the endpoint, the loop with `mody/generate-page`, `mody/open-editor`, `ask_user` and `refuse_request`, and the audit table. The same day, M3-02 added `mody/update-design`, and M3-03 added `mody/publish-page` and `mody/update-site-settings`. M3-03 also sends each earlier reply's ability calls back to the model as context, server-side only. How it works: [ai-engine.md, "The agent"](ai-engine.md#the-agent).

- **UI:** a `Mody Agent` menu item in each member site's wp-admin: a simple chat page (plain HTML + fetch, no JS build toolchain) with action chips for common intents. Until M4 it appears on every site, for users with `edit_pages`. **Replaced:** by the editors' chat bubble on member sites (2026-11-07, M3-05), and removed on the main site too on 2026-11-11; the bubble is the only chat.
- **The agent in the editor** (asked for by the maintainer on 2026-11-07). A chat bubble at the bottom right of the Site Editor and the post editor ([M3-05](../tickets/M3-05-agent-editor-bubble.md), built 2026-11-07). It knows what is open and edits it in the editor, undoable and saved only with Save ([M3-06](../tickets/M3-06-agent-edit-current-content.md), built 2026-11-07, for pages and posts), including templates and parts ([M3-07](../tickets/M3-07-agent-templates-parts.md), built 2026-11-07). On member sites the bubble replaces the wp-admin page; on the main site super admins keep the page.
- **Endpoint:** the page posts to the MU plugin's REST endpoint `mody-platform/v1/chat` (logged-in user + nonce). Each message is a background job (decided 2026-11-06): the POST answers at once with a job ID, the turn runs after the response, and the page polls a checker (`GET …/chat/jobs/<id>`) until it is done. No cron involved. See [ai-engine.md, "Background jobs"](ai-engine.md#background-jobs).
- **Settings:** Network Admin → Settings → Mody Agent, super admins only: how many days agent events are kept (default 90).
- **Agent loop** (in the MU plugin, decided 2026-11-04, replacing the Python agent service): assembles the system prompt with the site's brand (its settings, M2-08), calls the model through `wp_ai_client_prompt()` with the `mody` abilities offered as tools (declared the way core's `using_abilities()` does, decision 13), executes the tool calls in PHP through core's ability resolver, and returns the final message plus any actions. See [ai-engine.md](ai-engine.md).
- **Model:** `Qwen/Qwen3.8-27B-FP8` via Infomaniak AI Services (`https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`, all identifiers from environment variables). Native function calling (verified on Infomaniak 2026-11-04). Low temperature (0.1).

### Tools (all executed server-side, all guarded)

Each acting tool is an ability in the `mody` category (decision 13). Its permission callback and input schema run on every call, whichever channel calls it: agent, REST or MCP. `mody/generate-page`, `mody/open-editor`, `mody/update-design`, `mody/update-styles`, `mody/publish-page` and `mody/update-site-settings` exist. The other ability names are proposals.

| Tool | Ability | Arguments | Effect | Guard |
| --- | --- | --- | --- | --- |
| `generate_page` | `mody/generate-page` (built 2026-11-06) | `prompt`, `title?` | Brand-profile generation → validator → **draft** page. Returns the page ID and its Site Editor URL; in the Site Editor the new draft opens at once (decided 2026-11-08, M3-09), elsewhere it is a button. The validated markup is saved directly and never passes back through the model. Publishing and the front page belong to the caller: provisioning (section 7), and otherwise the member, **on demand** (decided 2026-11-06): by asking the agent (`mody/publish-page`, below) or with the Site Editor's Publish button. |
| `publish_page` | `mody/publish-page` (built 2026-11-06, M3-03) | `page_id` or `page_title` | Publishes a draft or pending page **as it is now**, without the validator (the member may have edited it by hand; the Publish button does not validate either). Only when the member's message asks for it, never in the same turn as a generation unless the message asked for both; after a draft, the agent says it can be published but never asks "shall I publish it?". Returns the page and two buttons: View and the Site Editor. | `publish_pages`, and `edit_post` on the page. Refuses other post types, and pages already published, private, scheduled or in the trash. | `edit_pages`. Quota-independent. The site's brand is its settings (M2-08), which every site has. Validator pipeline mandatory. |
| `update_design` | `mody/update-design` (built 2026-11-06, M3-02) | `preset` (one of the six presets of section 6, decided 2026-11-06) or `{primary, secondary, accent}` | Patches the site Global Styles (the theme palette, as the Site Editor saves it; each change is a revision). The palette is the site's brand: no brand question, no copy anywhere else (M2-08). Returns the old and new colors and opens Styles → Colors, or Browse styles for a preset. | Hex regex; maps to palette slugs only (`base` and `contrast` never change). `edit_theme_options`. |
| `update_styles` | `mody/update-styles` (built 2026-11-08, M3-10 parts A and B) | `changes` (a partial theme.json object: `styles`, `settings`) | Merges the changes into the site's Global Styles (a revision), then opens the Styles screen that shows them. | `edit_theme_options`; the guard in [ai-engine.md](ai-engine.md#changing-the-styles): no CSS or URLs, the theme's preset slugs fixed, colors by palette slug, fonts only from the Font Library |
| `open_editor` | `mody/open-editor` (built 2026-11-06; every screen since 2026-11-08, M3-09) | `target` (`page` \| `template` \| `part` \| `list` \| `styles` \| `navigation` \| `patterns` \| `home` \| `identity` \| `brand`), `page_id?`, `page_title?`, `template?`, `list?`, `section?`, `block?` | Opens a Site Editor screen (no mutation). In the Site Editor the bubble switches to it in place; elsewhere it is a button. The model never sees the URL. A `list` opens the template that really shows it (the site's registered post types and taxonomies, through the template hierarchy). A page opens by its exact title, or by a partial one when only one page fits; otherwise the agent asks with the likely titles until one is picked. | URL built from the real site URL. Pages: `edit_pages` and `edit_post`. Everything else: `edit_theme_options`. Opening what is already open in the editor is refused |
| `activate_plugin` / `deactivate_plugin` | `mody/activate-plugin` / `mody/deactivate-plugin` (proposed, M4-02 part B) | `plugin` (slug or name) | Switches a plugin on or off on this site, with core's `activate_plugin()` / `deactivate_plugins()` | Core's `activate_plugin` / `deactivate_plugin` capability on the plugin: the same rule as the Plugins screen, so hidden plugins are refused and never named |
| `update_site_settings` | `mody/update-site-settings` (built 2026-11-06, M3-03; mission and tone since 2026-11-08, M2-08) | `title?` (at most 120 characters), `tagline?` (at most 200), `mission?` (at most 200), `tone?` (formal, friendly, technical) | Updates the site's identity settings: `blogname`, `blogdescription`, `mody_mission`, `mody_tone`, sanitized. Returns the old and new values. The logo and icon are changed on the Identity screen, which `open_editor` opens (target `identity`). | Fixed allowlist: the schema refuses any other property. `manage_options` |
| `ask_user` | none: a plain function declaration (built 2026-11-06; it only shapes the chat reply and acts on nothing) | `question`, `choices?` (up to 4) | Renders a follow-up question in the chat, with the choices as buttons. Ends the turn. | — |
| `refuse_request` | none: a plain function declaration (added 2026-11-06) | `reason` | Shows the reason and ends the turn. Its only purpose is to flag refusals in `mody_agent_events`. | — |

No delete tools, no arbitrary option writes, no file or DB access. Any tool call not in the schema is refused and explained: nothing runs, the model gets the error `mody_unknown_tool`, and the call is logged.

### The `ai` plugin's features on member sites (decision 13)

- **Brand context in the writing features** (built 2026-11-06, M3-04). The plugin's features build their own system instructions. Through the plugin's `wpai_system_instruction` filter, `PluginBrandContext` appends `SystemPrompt::for_ai_feature()` (one sentence saying the feature's own rules come first, then `brand_context()`). Decided by the maintainer on 2026-11-06:
  - **Which features:** the eight that write text in the site's voice: titles, excerpts, meta descriptions, content resizing, type-ahead, suggested replies, editorial updates and image prompts. Summaries (the plugin asks for neutral text), translation (it keeps the source's voice) and the analysis features (alt text, slugs, block labels, comment analysis, classification, editorial notes, revision summaries) are left as they are. These are exceptions to rule 4, agreed for output that brand tone would only bend.
  - **Every site has a brand** (its settings, M2-08), so every site's writing features get it.
  - Details and the test: [ai-engine.md](ai-engine.md#the-ai-plugins-features).
- **Curated features** (decided 2026-11-07, built in [M4-03](../tickets/M4-03-ai-plugin-lockdown.md)). Members get every text feature: on-demand writing, type-ahead, comments, organizing. Image generation, text to speech, the developer tools and two experiments stay off. The switches and the connector approval are pinned on member sites, so a new site works at once and members cannot change them ([ai-engine.md](ai-engine.md#on-member-sites)).
- **Locked configuration.** The plugin's settings, connector approvals and MCP access are locked on member sites: its screens and routes refused to members, its values pinned (built 2026-11-07, [M4-03](../tickets/M4-03-ai-plugin-lockdown.md)).
- **Cost tracking.** The plugin's request log (its AI Request Logging experiment) is the basis for per-member cost tracking. It hooks core's `wp_ai_client_before/after_generate_result` events, so it records every request made through the AI Client, Mody's own included (from its source, 2026-11-06; not yet seen in a log). It keeps prompt and response previews. Retention is set with `wpai_request_log_retention_days`.

### Safety and audit

- Every request carries the site's brand (its settings) in the system prompt (constraint 4) — there is no code path that calls the model without it.
- Every tool call is logged in `mody_agent_events` (user, site, input, tool, args, result, duration), and so is every chat request with its reply (built 2026-11-06). Since 2026-11-11 each message also writes a `turn` row of counts (model calls, tokens, cache, the page writer's answers, the outcome), never text ([ai-engine.md](ai-engine.md#performance-and-measurement-2026-11-11)).
- A past-due account gets no tool execution (read-only, section 4). Not built yet: it needs `mody_members` (M1).
- Tools run in-process with the member's capabilities checked on every call (the ability's permission callback). There is no internal endpoint or shared secret (constraint 6).

**Acceptance criteria**

- "Make the primary color coral" → Global Styles updated at once (no brand question, M2-08), Styles → Colors opened, event logged. The same for "use the Forest preset" (Browse styles).
- "Create an about page" → validated **draft** (or a clear failure with retry). It is published only when the member asks: "publish the about page" to the agent, or the Publish button in the Site Editor (decided 2026-11-06). Met on 2026-11-06 (M3-03), together with "publish it" later in the same conversation, and a draft edited by hand published as it is.
- "Change the tagline to …" → tagline updated, event logged, and no other option can be written (M3-03, 2026-11-06).
- "Open the editor for page 3" → a button that opens `…/wp-admin/site-editor.php?p=%2Fpage%2F3&canvas=edit` on the right subdomain. (WordPress 7.1 sends the older `?postType=page&postId=3`, without `canvas`, to the page list instead. `PageGenerator::site_editor_url()` builds the working form.)
- "Activate the X plugin" → works if the plugin is visible on the site, refused with an explanation if it is hidden or not installed. Moved to M4-02 (2026-11-06), part B.
- A prompt-injection-style request ("delete all pages", "show me the database") is refused and logged.

## 9. Editor-first rules

On **member sites only** (never the main site):

- `post.php?post=N&action=edit` (page **or** post) → redirects to the Site Editor for that item. For pages that is `site-editor.php?p=%2Fpage%2FN&canvas=edit`. The form `?postType=<type>&postId=N` without `canvas=edit` lands on the list in WordPress 7.1 (`wp-admin/site-editor.php`). **Built 2026-11-07 ([M4-01](../tickets/M4-01-editor-redirects-gating.md)), with one decided exception: posts.** WordPress 7.1's Site Editor has routes for pages, patterns (`/wp_block/:id`) and navigation menus (`/wp_navigation/:id`), but none for posts, so posts keep the block editor (`post.php`) until it does (decided by the maintainer on 2026-11-07). Pages, patterns and menus open in the Site Editor; `post-new.php` for pages goes to the Site Editor's pages (`?p=/page`). Member sites are every site but the main one; super admins are not redirected.
- `post-new.php?post_type=page|post` → redirects to `site-editor.php?postType=<type>`.
- Templates, parts and styles are already Site-Editor-only (FSE theme).
- Every link the platform generates (dashboard buttons, agent `open_editor`, emails) uses the Site Editor URL. The agent's editor buttons open it in the same tab with `?mody-chat=open`, so the chat bubble opens there and the conversation carries on; the wizard's last screen and the welcome email link to the new site's front page in the Site Editor with the chat open too (decided 2026-11-07).

**Acceptance criterion:** a member cannot reach a classic block-editor page for any content type on their site; every editing entry point lands in the Site Editor.

## 10. Plugin market and capability gating

**Patterns: the design market (built 2026-11-08, [ticket](../tickets/design-market-themes-patterns.md), test `scripts/patterns-test.php`).** Every site offers the Mody collection: 63 patterns rewritten from the WordPress.org Pattern Directory and core's bundled patterns (GPL), with palette colors, the theme's fonts and the platform's placeholder images. They show on the Site Editor's Patterns screen and in the inserter. Nothing is fetched from WordPress.org: remote patterns are off, core's bundled patterns are replaced by their copies, and the Directory proxy is refused to members. Mody is the only theme provider; which themes members see is not decided yet (tech debt).

**No Plugins screen for anyone since 2026-11-11** ([hide-plugins-and-connectors.md](../tickets/hide-plugins-and-connectors.md)): the maintainer decided that nobody, super admins included, sees Plugins or Settings → Connectors; plugins change only through Composer, `./bin/deploy` and WP-CLI (`Gating\HiddenScreens`). The paragraph below describes the design of 2026-11-08, kept for when plugins are offered to members again.

**Plugins on member sites (redesigned 2026-11-08, [M4-02](../tickets/M4-02-plugin-market.md)):** every plugin is **preinstalled** via Composer (`app/web/plugins/`, network-visible), and members only **activate/deactivate** it, for their own site, on **core's Plugins screen** (`/wp-admin/plugins.php`). A plugin added to `app/composer.json` and deployed appears for every member with no other step, unless it is hidden. The "market" is the list of what members never see:

- **Platform plugins, fixed in code** (`Gating\MemberPlugins::PLATFORM_PLUGINS`): `spectra-blocks`, `ai`, `ai-provider-for-ollama`, `mcp-adapter`, `wordfence`, and the dev tools `query-monitor` and `user-switching`. Network-active plugins are hidden too.
- **The super admins' list** (part A2, to build): the network option `mody_hidden_plugins`, edited in Network Admin → Mody → Plugins.

Mody does nothing plugin-specific: activation is core's, and what a plugin does when activated is its own business (decided 2026-11-08). A plugin hidden while active stays active; members can no longer see or switch it (decided 2026-11-08). **No plugin is offered yet.** Redirection, the MVP seed, was dropped on 2026-11-08: it came from the old stack (`config/bin/install.config`), and its admin screen called a US host. Each plugin is checked against rule 1 before it goes into Composer. Spectra was a seed until 2026-11-06, then network-active, and a MU plugin since 2026-11-08 (decision 14). All plugins free — no per-plugin billing. Spectra is the `spectra-blocks` package, Brainstorm Force's current Spectra, in Composer since 2026-11-06. `config/bin/install.config` still names `ultimate-addons-for-gutenberg`, which is now "Spectra Legacy".

**Spectra is the platform's block library, a MU plugin** (network-activated on 2026-11-06; a MU plugin since 2026-11-08, [M3-11](../tickets/M3-11-spectra-mu-plugin-all-blocks.md)). Every site has it, provisioning does not activate it, and nobody can switch it off and break the Spectra blocks. It is on no Plugins screen. Composer installs it into `app/web/mu-plugins/spectra-blocks/` (an `installer-paths` entry). `mu-plugins/spectra-blocks.php` loads it after `mody-platform.php`, so `SpectraGuard` is in place first. `Spectra\SpectraLoader` hides the old network activation from WordPress and removed it from the stored option once, so a leftover copy in `plugins/` never loads beside it. Spectra's activation hook, which MU plugins never run, only sets defaults: network activation never ran it for new sites either.

**Spectra and rule 1: `SpectraGuard`** (`app/web/mu-plugins/mody-platform/src/Spectra/SpectraGuard.php`, 2026-11-06). Spectra's code calls services outside Europe. The guard switches those parts off from the MU plugin, without touching the plugin's files. It was checked against the 1.0.13 source; **recheck it on every Spectra update** with `scripts/spectra-guard-test.php`.

| Spectra part | Connects to | How the guard stops it |
| --- | --- | --- |
| Block `spectra/google-map` | Google Maps (an iframe saved in the content) | Unregistered on the server and in the editor. `pre_render_block` renders nothing, even for saved content. |
| Design library and the `gutenberg-templates/spectra-ai` block | websitedemos.net, ZipWP AI | `ast_block_templates_disable`, and the option `spectra_blocks_enable_templates_button` pinned to `no` |
| ZipWP images (media tab), NPS survey | ZipWP, Brainstorm Force | Never loaded. The guard claims a higher version in the BSF version-negotiation globals (`$zipwp_images_version`, `$nps_survey_version`) with no file. |
| BSF analytics and deactivation survey, onboarding signup | metrics.brainstormforce.com | `bsf_usage_tracking_enabled` off, `uds_survey_allowed_screens` empty, onboarding redirect off, `spectra_blocks_usage_optin` pinned to `no` |
| Google Fonts in Spectra's global styles | fonts.googleapis.com, fonts.gstatic.com | `spectra_blocks_load_select_font_globally` and `spectra_blocks_load_gfonts_locally` pinned to `disabled`. Spectra's default fonts (Inter, Cardo) are local files. |
| Editor nudges | YouTube (autoplay video), ZipWP AI and Pro upsells | Scripts and styles dequeued |
| Spectra dashboard (Learn videos, AI features, Free vs Pro), onboarding wizard | YouTube, Google Fonts, ZipWP links | Menus removed, direct visits redirected to the dashboard. Its settings are pinned, as above. |
| Abilities `spectra-blocks/create-google-map`, `add-google-font`, `list-available-google-fonts`, `remove-google-font` | Google Fonts, Google Maps | Unregistered |
| All of Spectra's abilities and its MCP server (`spectra-blocks/v1/mcp`) | Not a connection, but weak permissions (see below) | `spectra_blocks_enable_abilities` and `spectra_blocks_enable_mcp_server` pinned to `disabled` (2026-11-06) |
| Anything missed | the hosts above | **Safety net:** `pre_http_request` refuses requests to `brainstormforce.com`, `zipwp.com`, `startertemplates.com`, `websitedemos.net`, `wpspectra.com`, `wpastra.com` and Google's font and map hosts. Each refusal is logged as `[mody-platform] refused a request to …`. |

Plain links to vendor sites in Spectra's UI are not connections until someone clicks them. Spectra's popup builder (`edit.php?post_type=spectra-blocks-popup`) stays reachable by URL; its menu went with the dashboard. Spectra stays (decided 2026-11-08): the fork into our own blocks ([tickets/mody-blocks-from-spectra.md](../tickets/mody-blocks-from-spectra.md)) is set aside.

**Spectra's abilities (decided 2026-11-06).** Spectra 1.0.13 ships 51 `spectra-blocks/*` abilities, plus `spectra/create-container` and `spectra/create-content`, which its own MCP server exposes. They are off by default (both options default to `disabled`), but any site administrator could switch them on, and their permissions are weak: read-only abilities need `edit_posts`, writing ones (including `update-plugin-setting`) need `edit_others_posts`, and writes go straight into posts. The guard pins both options to `disabled`, so none is registered on any site.

If Mody uses them, it is only as hidden machinery behind its own `mody/*` abilities:

- made non-public with `wp_register_ability_args`, with tightened permissions (`wp_ability_permission_result`), hex colors and outside URLs rejected in their inputs, and `BlockValidator` run on their results;
- for: rich blocks from JSON (`create-accordion`, tabs, counter, …), editing existing pages (`get-post-content`, `update-block-attributes`, `move-block`, `duplicate-block`), syncing the brand palette (`set-active-colors`; keep `spectra_blocks_theme_color_override_enabled` off, it overwrites the theme palette);
- never: settings, popups, `remove-block` and `delete-popup` (section 8: no delete tools), `apply-image-mask` (takes a `custom_url`), Spectra's MCP server.

Using them needs its own ticket; until then they stay off.

**Built 2026-11-08** (part A1 of [M4-02](../tickets/M4-02-plugin-market.md); test `scripts/member-plugins-test.php`):

- **The rule** (`Gating\MemberSite::map_meta_cap()`, for everyone but super admins, on member sites): `activate_plugins` and `deactivate_plugins` (the screen, its menu, bulk actions) are allowed to the site's administrators without core's network switch ("Enable administration menus: Plugins"). `activate_plugin` and `deactivate_plugin` for one plugin, which core checks for single links, each plugin of a bulk action and REST, are allowed only for plugins that are not hidden.
- **Hidden plugins** (`Gating\MemberPlugins`) are left out of the screen and its counts (`all_plugins`) and of the REST list (`rest_request_after_callbacks`, so internal requests too). Their REST routes answer "Plugin not found" (404) to members, whatever the method.
- **Any other code path** (`MemberPlugins::keep_hidden()` on `pre_update_option_active_plugins`): during a logged-in member's request, a hidden plugin keeps its state whatever code writes `active_plugins`. Needed because other plugins' own routes check only `activate_plugins` and call `activate_plugin()` themselves. Spectra's onboarding API, `one-onboarding/v1/activate-plugin`, activates any installed plugin by slug; without the guard it switched `mcp-adapter` on for a member (checked 2026-11-08). WP-CLI, cron without a user and super admins are not affected.
- Core's Must-Use and Drop-ins tabs show only to super admins in Network Admin. The main site and super admins are unchanged.

**Capabilities removed on member sites** (capability filter scoped to the member's own sites):

- `install_plugins`, `upload_plugins`, `update_plugins`, `delete_plugins`, `edit_plugins`, `resume_plugin`. `activate_plugins` was removed too until 2026-11-08; since M4-02 members switch visible plugins on core's Plugins screen. `upload_files` for plugin/theme zips is not affected (media stays allowed).
- `switch_themes`, `install_themes`, `delete_themes`, `edit_themes` — the base theme `theme` stays fixed.
- `DISALLOW_FILE_EDIT` is already on network-wide.

Members keep everything else: content, media, users of their own site, settings, Spectra, Global Styles.

**Updates only through the repository** (decided 2026-11-08, built the same day, [ticket](../tickets/updates-through-composer-only.md), test `scripts/updates-test.php`). Nobody updates WordPress, a plugin, a theme or a translation from wp-admin. Core changes with `./bin/version` and plugins in `app/composer.json`, and both reach production through `./bin/deploy`. `Gating\Updates` enforces this on every site and environment:
- **Capabilities:** `update_core`, `update_plugins`, `update_themes` and `update_languages` are refused to every user, super admins included. That removes the Updates screen, update notices, update counts and auto-update controls, and refuses the update requests.
- **No automatic checks:** core's own triggers of the WordPress.org update checks are removed (admin page loads, the Plugins, Themes and Updates screens, the cron events), and the cron events are unscheduled on each site's next request.
- **Left alone:** code that calls the checks directly: Wordfence's scans and activity report (its outdated and vulnerable plugin warnings), Site Health → Info, and WP-CLI run by hand.

**Built 2026-11-07** ([M4-01](../tickets/M4-01-editor-redirects-gating.md)): `Gating\MemberSite` refuses the plugin and theme capabilities (install, upload, activate, deactivate, resume, update, delete, edit; switch, install, upload, update, delete, edit themes) through `map_meta_cap`, on every site but the main one, to everyone but super admins (decided 2026-11-07). Since M4-02 (2026-11-08) activating and deactivating are allowed again for plugins members may see, and the planned `mody_manage_market` capability was dropped. The `ai` plugin's lock-down is [M4-03](../tickets/M4-03-ai-plugin-lockdown.md).

**Acceptance criteria:** a member site admin cannot install, upload, update, delete or edit plugins or switch themes by any UI or REST path; the Plugins screen activates a visible plugin and never shows or switches a hidden one; the front end and editor are unaffected.

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

-- mody_brand_profiles was dropped on 2026-11-08 (M2-08): a site's brand is its own
-- WordPress settings (title, tagline, logo, icon, and the site settings mody_mission
-- and mody_tone) and its Global Styles. See brand-profile.md.

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

`mody_custom_domains` is **not** created yet (next iteration with the Caddy work). `mody_market_plugins` was dropped from this model on 2026-11-08: the plugins hidden from members are a network option, `mody_hidden_plugins` (M4-02).

**Built so far:**

- `mody_members` (2026-11-08, [M1-01](../tickets/M1-01-registration-trial-billing.md) part 1). `Members\Members::install()` creates it on `init` (`mody_members_db_version`). It matches the definition above, with three differences:
  - an unsigned `bigint(20)` `user_id`;
  - an index on `status`;
  - a fifth status, `expired_signup`. Section 4's signup flow uses it for "no payment within 24 hours", while the enum above lists only `expired`, which is kept for "sites deleted after 60 days past due".

  A row gives the dashboard capability and the plan's site limit through `mody_site_limit`. Table version 2 (part 2, 2026-11-08) added `next_plan`; version 3 (2026-11-11) the 2026-11-10 plans and `billing_period`. **Version 4 (2026-11-13):** `plan` is `free` or `paid` (site limits 1 and 10), `trial_ends_at` became `free_until` (old trials: six months after signup), and `next_plan` is gone: there is one price, and the member chooses only monthly or yearly.
- ~~`mody_brand_profiles`~~ (2026-11-06, M2-02): dropped on 2026-11-08 by `Brand\BrandSettings::migrate()`, which first moved each site's mission and tone into its settings (M2-08).
- `mody_provisioning_jobs` (2026-11-06, M2-01). `Provisioning\Jobs::install()` creates it the same way (`mody_provisioning_jobs_db_version`). It matches the definition above, with these additions: unsigned `bigint(20)` IDs; a `queued` status (a job not run yet); `input`, the validated wizard answers, and `result`, what the steps produced (`profile_id`, `page_id`, `fallback`, `generation_error`, `email`), both JSON in `LONGTEXT`; indexes on `user_id` and `status`. The `step` enum lists the steps in the order they run (`profile` after `site`).
- `mody_agent_events` (2026-11-06, M3-01). `Agent\AgentEvents::install()` creates it the same way (`mody_agent_events_db_version`). It matches the definition above, with these additions:
  - `duration_ms` (section 8 asks for the duration);
  - unsigned `bigint(20)` IDs;
  - indexes on `(site_id, created_at)`, `user_id` and `created_at` (for the purge).

  `args` is MariaDB's `JSON` (a `LONGTEXT` checked with `json_valid`). It holds one row per chat request (`tool` NULL) and one per tool call ([ai-engine.md](ai-engine.md#the-agent)). **Retention** (decided 2026-11-06): rows are kept for the number of days set in Network Admin → Settings → Mody Agent (network option `mody_agent_settings`, default 90). They are purged once a day, without cron.

## 12. Architecture deltas

Everything else in the current stack is unchanged ([architecture.md](architecture.md)).

| Delta | Detail |
| --- | --- |
| Multisite on | `WORDPRESS_ALLOW_MULTISITE` / `WORDPRESS_MULTISITE` / `WORDPRESS_SUBDOMAIN_INSTALL` = `true` (subdomain mode). `wp-config.php` already handles the constants. |
| Wildcard TLS | `APP_DOMAINS` gains `*.${HOST_NAME}` (local: mkcert wildcard; production: upstream wildcard cert for `*.mody.cloud`). `compose.local.yml` already wires the wildcard `server_name`. |
| MU plugin | Project-owned `app/web/mu-plugins/mody-platform/` (tracked, always active, **not** Composer-managed), loaded by `app/web/mu-plugins/mody-platform.php`. Holds: the AI provider and agent, registration, billing, dashboard, provisioning, wizard, market, gating, editor redirects. Resolves the "where gating lives" open question. Outgoing mail is the one exception: its own MU plugin, `app/web/mu-plugins/mody-mail/` (decided by the maintainer on 2026-11-10). |
| AI | WordPress AI Client (core) with the Infomaniak provider in the MU plugin. No extra service. Env: `INFOMANIAK_API_KEY`, `INFOMANIAK_AI_BASE_URL`, optional `INFOMANIAK_AI_MODEL`. Decided 2026-11-04, replacing the Python agent service. |
| Abilities | Agent actions are abilities in the `mody` category, registered by the MU plugin on `wp_abilities_api_init` (decision 13). Public, so authenticated users reach them through REST and MCP as well as the agent. |
| Cron | WP-Cron (already enabled): hourly jobs — due billing, past-due sweep (banner, day-53 warning, day-60 deletion), job retry, expired-signup cleanup. |
| Env additions | `MOLLIE_API_KEY`, `MOLLIE_WEBHOOK_KEY`, `INFOMANIAK_*` as above. Nothing committed; `.env` stays untracked (constraint 6). |
| Nginx | No change beyond the existing wildcard block; custom-domain `server_name` generation is next iteration. |

## 13. Milestones

Ordered; M1 and M2 can partially overlap after M0. Estimates are rough (rule 5: if a task exceeds ~2 days, split it).

| # | Milestone | Scope (section) | Exit criterion |
| --- | --- | --- | --- |
| **M0** | Multisite foundation | 3, 12 | Flip the multisite env switches, add the wildcard to `APP_DOMAINS`, document the network activation step; two subdomain sites reachable over HTTPS locally, both running theme `theme`, owned by one non-super-admin user. |
| **M1** | Accounts, trial and billing | 4, 5 | Register → Mollie 0.01 checkout → webhook → active trial with dashboard (My sites, Plan & billing). Simulated failed charge → read-only + banner + 60-day deletion job verified. |
| **M2** | Site creation | 6, 7, 11 | Wizard (3 choice steps) → provisioned subdomain with Global Styles and a generated, validated home page; job table resumable; fallback page on generation failure. First real model call through Infomaniak (verify model slug + function-calling support here). **Done locally 2026-11-07:** M2-02 (AI engine), M2-01 (the provisioning pipeline) and M2-03 (the wizard) on 2026-11-06, M2-04 (its live preview) and M2-05 (the logo upload) on 2026-11-07. Not provable in production yet: new sites there need the wildcard DNS and TLS for `*.mody.cloud` (section 16, question 3; M5). |
| **M3** | Agent | 8 | Chat in wp-admin; all six tools work end-to-end; injection attempts refused and logged; every event in `mody_agent_events`. **Done 2026-11-06** (M3-01 to M3-04), except three items moved on: the plugin toggle tools to M4-02 (they need `mody_market_plugins`), the past-due check to M1-02 (it needs `mody_members`), and limiting the agent menu to member sites to M4-01. |
| **M4** | Gating, market and editor-first | 9, 10 | No plugin/theme upload or theme switch possible on member sites; members switch visible plugins on core's Plugins screen; every editing entry point lands in the Site Editor. **In progress:** M4-01 (redirects and gating) and M4-03 (the `ai` plugin) done 2026-11-07, posts excepted; M4-02 (plugins, redesigned 2026-11-08): part A1 (core's Plugins screen for members) done 2026-11-08, A2 (the super admins' hidden list) and B (the agent's abilities) to do. |
| **M5** | Hardening and launch | all | Production compose (no Xdebug/DB ports), wildcard cert at the upstream, backup/restore drill, security pass (nonces, sanitization, webhook signatures), monitoring via Query Monitor, launch checklist green for 100 founding members. |

## 14. Next iteration (out of MVP, decided direction)

- **Custom domains per site** (DNS-instruction flow: member requests → platform replies with the exact record → cron verifies → cert → activate). No domain resale yet. Needed by the launch since 2026-11-10: every plan includes it. **Built 2026-11-13** with Nginx Proxy Manager's API instead of a Caddy sidecar (the maintainer's choice), a CNAME to `sites.mody.cloud`, and the admin kept on `*.mody.cloud` ([tickets/custom-domains.md](../tickets/custom-domains.md)).
- **Newsletter** (Infomaniak; per-plan sending caps, undecided) per [newsletter.md](newsletter.md).
- **Dedicated VPS** tier (not in the plans since 2026-11-10); AI volume limits.
- A font choice in the wizard, more presets. (Fonts in the Site Editor and the presets as style variations are built: M2-06, M2-07.)
- Analytics (EU-hosted, GDPR-coherent, undecided).

## 15. Risks and mitigations

| Risk | Mitigation |
| --- | --- |
| Model output breaks block syntax | Validator pipeline + 2 retries + fallback page; provisioning never blocks on the model (section 7). |
| Function calling unsupported by the model | JSON action protocol fallback with identical server-side guard (section 8). |
| Mollie webhook loss / duplicates | Signature check + idempotency + 24 h expiry cron + manual retry from dashboard. |
| Network admin exposed to non-members | Custom capability on the custom menu only; all queries user-scoped; standard network admin untouched. |
| Agent performs unsafe actions | Whitelisted tools only, fixed option allowlist, hidden plugins refused, audit log, no delete tools. |
| Stale Tamarind scripts | Untouched by the MVP; cleanup scheduled after M5 (see [local-development.md](local-development.md#known-stale-items)). |

## 16. Open questions (remaining)

1. **Mollie account** — test-mode keys available? (Needed at M1 start.)
2. **Production SMTP** — which relay for real mail (Infomaniak Mail or self-hosted)? Needed by M5. **Answered on 2026-11-09, for now:** Brevo's API (through the `mody-mail` MU plugin since 2026-11-10; WP Mail SMTP before), a temporary exception to rule 1 ([architecture.md](architecture.md#temporary-exceptions-to-rule-1)). An EU relay replaces it later.
3. **Upstream wildcard TLS** — confirm the production front end can terminate a wildcard cert for `*.mody.cloud` (infra task, M5).
4. ~~**Infomaniak AI** — exact model slug + product ID + function-calling support.~~ Resolved 2026-11-04: slug, base URL and function calling verified ([ai-engine.md](ai-engine.md#verification-2026-11-04)). Still to confirm: Infomaniak lists the model as `coming_soon`, and its price.
5. ~~**Final market membership** — beyond Redirection.~~ Decided 2026-11-08: **no plugin for now.** Redirection was dropped; any plugin added later through Composer (after a rule 1 check) and deployed appears on members' Plugins screens unless a super admin hides it. Spectra is network-active, not a market plugin (decision 14).
6. ~~**Fee confirmation** — 5/15/50 EUR unchanged for 3/10/50 sites (assumed).~~ Answered by the maintainer on 2026-11-10 (1 / 3 / 10 sites), then on 2026-11-13: 7.99 EUR a month or 79.99 a year per site, VAT included, and the launch offer ([membership-tiers.md](membership-tiers.md)). Still open there: switching between monthly and yearly, invoices and the right of withdrawal.
7. ~~**The end of a trial without a card**~~ Answered by the maintainer on 2026-11-13 for the launch offer: reminders a month and a week before the free months end, then read-only for 60 days like `past_due`, then deletion (section 4).
8. **Accounts that never set a password** (M1-03): after how many days the account and its starter site are removed. Needs the WP-Cron decision.
