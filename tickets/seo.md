# SEO: search appearance on every site, and an agent that knows it

**Builds on:** the agent (M3), site languages ([site-languages.md](site-languages.md)), the `ai` plugin lockdown (M4) · **Feeds:** [M5-01](M5-01-hardening-launch.md) · **Status:** planned on 2026-11-10, not built. The maintainer answered Q1 to Q4 the same day: our own build, in its own `mody-seo` MU plugin, our own description field, and signup sites start hidden (done ahead of this ticket, see Q4).

## Goal (maintainer, 2026-11-10)
> let's plan a simple SEO plugin that should be available for everyone and that the agent should be aware about so it can suggest SEO improvements or change SEO settings general or from a page.

"Everyone" means every site on the network, the main site included, with no switch, like Spectra and Antispam Bee.

## What a site has today (checked 2026-11-10)
- **What core gives every site:**
  - a `<title>`;
  - a canonical on single pages;
  - a robots tag (`max-image-preview:large`, plus `noindex` when `blog_public` is 0);
  - `wp-sitemap.xml`, which lists posts, pages and the category archives;
  - a `robots.txt`.

  Core 7.1.4 no longer prints `Disallow: /` for a hidden site, so hiding a site works only through the robots tag.
- **What nobody prints:** Open Graph and Twitter tags. A meta description comes only from the `ai` plugin (next item). A page has no title, description or noindex of its own.
- **The `ai` plugin's Meta Description feature** is on for members. It stores `wpai_meta_description` and prints its own tag at `wp_head` priority 1. Its panel and button load only in the post editor (`post.php`). Members edit pages in the Site Editor, so they cannot set a page's description today.
- **Pages have no excerpt:** `post_type_supports( 'page', 'excerpt' )` is false. Only `scripts/home-page.php` sets one.
- **New sites from the signup are public** (`Pipeline.php`, `'public' => 1`). They are thin starter sites: an empty tagline and "Our new website is on its way. Come back soon!". Registration has been open in production since 2026-11-10.
- **Wizard sites:** their tagline is the mission, up to 200 characters, so it fills the front page's `<title>`.
- **The production main site:**
  - Its `<title>` is "Mody Cloud - A golden hour of showers in the sea". The tagline is an old database value that no script sets.
  - Its head has no description and no Open Graph tags.
  - `robots.txt` is Cloudflare's managed file, which has no `Sitemap:` line.
  - The sitemap is at `/wp/wp-sitemap.xml`; `/wp-sitemap.xml` is a 404.
- **The agent:**
  - It has no SEO knowledge and no SEO tool.
  - Its tool declarations are 3,859 bytes without an open editor and 4,250 bytes with one (`edit_content` is 390). The test checks only the first against 4,000 bytes (`scripts/ai-agent-chat-test.php:308`). ai-engine.md's "3,887 bytes with edit_content" is out of date.
  - It makes at most 3 tool calls per message.

## The base: our own small build (recommended)
**Where it lives (decided by the maintainer on 2026-11-10):** its own MU plugin, `app/web/mu-plugins/mody-seo/`, loaded by `mody-seo.php`, like `mody-languages`. It is the third exception to "platform logic lives in one MU plugin".
- **`mody-seo` holds:** the fields, the head, the sitemap, the audit and the panel.
- **The agent's side stays in `mody-platform`,** behind a `class_exists` check, as `mody-languages` is used: the SEO context in the prompt, the `mody/update-seo` ability and the editor's refresh.

It needs no new Composer or npm dependency. It makes no outside call, so rule 1 holds by construction.

| Option | Rule 1 | For the agent and the Site Editor | Verdict |
| --- | --- | --- | --- |
| **Our own** | No outside call | Built for it | **Recommended** |
| The SEO Framework 5.2.0 (CyberWire, NL), a MU plugin like Antispam Bee | No outside call found in its source | No REST fields, no abilities. Its meta box does not show in the Site Editor. Its settings sanitizer does not run on REST requests. | Runner-up |
| Slim SEO 4.11.5 (Vietnam) | AI calls to US providers, a jsDelivr script, a free-text head-code field | Has abilities, public to MCP | No |
| SEOPress (FR), SureRank | A promotions feed, IndexNow, ZipWP, Google Fonts | Their own MCP server | No |
| Yoast, Rank Math, All in One SEO | US owners, cloud accounts | Their own tables | Ruled out by rule 1 |

**Why ours over The SEO Framework (TSF):**
- **TSF would save little.** The hard parts are ours either way: the abilities, the audit, the panel, the overlap with the `ai` plugin and languages. TSF would replace only the head and the sitemap (parts 1 and 2, about 4 of 18 hours).
- **TSF would need bridges, and each is a risk:**
  - it takes over the `<title>`, so the title of a translated front page stops working;
  - `og:locale` on `/es/`;
  - it replaces `wp-sitemap.xml`;
  - our abilities would call its internal classes;
  - an "SEO" menu and a first-run notice to hide.
- **Ours stores only what a person changed** ("WordPress first, never keep a copy"). Every default is read from WordPress.

**When to switch to TSF:** if members should get SEO for archives, terms and authors, and JSON-LD, without our upkeep. Parts 4 to 10 sit behind `Fields`, the one class that reads and writes the values, so they would carry over (**Inference**).

## Data model
Three post meta fields on pages and posts:
- **Registered:** with `register_post_meta`, `show_in_rest` and `single`; `auth_callback` is `edit_post`.
- **Hidden** from the Custom Fields box (`is_protected_meta`).
- **Registered at `init` priority 20,** after the `ai` plugin (15), so our REST schema wins. That plugin also registers its key on every REST post type once it is pointed at ours. That is harmless, and tested.

| Field | Holds | Limits |
| --- | --- | --- |
| `mody_seo_title` | Only the page-title part of `<title>`; core adds the site name | Plain text, cap 120, advice about 60 |
| `mody_seo_description` | The meta description, also used by Open Graph | Plain text, cap 300, advice 70 to 160 |
| `mody_seo_noindex` | Ask search engines to leave the page out | Boolean |

- **Saving:** `sanitize_text_field`, line breaks collapsed. Output is escaped. Empty means "automatic".
- **Defaults, read from WordPress:**
  - **The description**, in this order:
    1. our field;
    2. the old `wpai_meta_description`, read only;
    3. the raw `post_excerpt`, never `get_the_excerpt()`, cut at about 160 characters;
    4. on the front page, the tagline.

    With none of them, there is no tag. For a translation, an excerpt identical to its source's is ignored: the copy is in the wrong language.
  - **The image:** the featured image, then the site logo, then the site icon.
  - **The title:** the field, else the page title.
  - **Site visibility:** core's `blog_public`, not a new setting.
- **Password-protected pages:** no description, `og:description` or `og:image`.
- **Translations** are ordinary pages with their own three fields. `Translations::create()` does not copy them, which is right: a description is per language.
- **No site option and no table.**
- **The way out:** the keys travel in the WXR export (core skips only `_edit_lock`). membership-tiers.md's way-out item names them, so another host can map them. The theme zip carries no SEO data.

## How it works
- **The head (`Mody\Seo\Head`):**
  - The description.
  - Open Graph: `og:title`, `og:description`, `og:url` (the canonical), `og:type`, `og:site_name`, `og:image` with its size when the media ID is known (no fetch), and `og:locale` with alternates. The language comes from `Translations::language( $post )`; bare `ca` maps to `ca_ES`.
  - `twitter:card`.
  - A page's noindex goes through the `wp_robots` filter, so there is still one robots tag.
  - **The title** (`document_title_parts`, priority 20) replaces only the page-title part. On the front page:
    - an SEO title replaces the "site - tagline" pair;
    - otherwise a tagline over about 60 characters is left out of the title.

    That fixes the 200-character missions without asking anyone.
  - Canonical and hreflang stay core's and `mody-languages`'.
- **No second description:**
  - the `ai` plugin's key is pointed at ours (`wpai_meta_description_meta_key`), and its own tag is blanked (`wpai_meta_description`);
  - in the post editor, its "Meta Description" panel is replaced by ours (part 9).
- **The sitemap (`Sitemap`):**
  - pages and posts with noindex are left out (`wp_sitemaps_posts_query_args`, which core also uses for page counts);
  - the users provider is removed (author names);
  - the category archives depend on Q5.

  A tuned sitemap does not make the main site's sitemap findable. That needs Cloudflare's managed `robots.txt` and the `/wp/` in `home` fixed (M5-02 step 5), or a search console.
- **Honesty:** hiding a page or a site only *asks* search engines. The panel and the agent say "asks", never "blocks".

## How the agent learns it
- **The site:** `SystemPrompt::seo_context()`, added in `for_agent()` like `styles_context()`, about 500 to 700 bytes. It holds:
  - search visibility;
  - the tagline and its length, the logo and the icon;
  - counts of published pages per issue;
  - at most 8 pages, with their IDs and issue codes.

  The audit is capped at 100 pages.
- **The open page:** `EditorContext` already gives its ID, title and unsaved content. `for_editor()` adds a short `<seo>` block:
  - the search title;
  - the description, or "automatic: …";
  - noindex;
  - the open page's issue codes, computed from the unsaved content.

  Text inside it is data, with quotes and line breaks stripped.
- **One prompt rule:**
  - descriptions are 70 to 160 characters, in the page's language; titles are up to about 60;
  - suggest first, and change only when asked;
  - confirm before hiding the front page or the site;
  - write descriptions with `generate`, never from the title alone, because the agent never sees another page's text;
  - to work on another page, open it with `open_editor`.
- **One tool, `mody/update-seo`, declared only when a page or post is open** (like `edit_content`, `Agent::declarations( $editing )`).
  - The declaration is about 500 bytes, so the editor request becomes about 4.75 KB.
  - The test measures that variant too, and checks it with real requests (Q6).
  - Changes to the site go through `mody/update-site-settings`, which is always declared.
- **Limits:** with 3 tool calls per message, "fix all my descriptions" fixes three pages at a time. The reply says how many are left.

## The audit ("suggest improvements")
`Audit` is plain PHP over saved markup (`WP_HTML_Tag_Processor`). It calls no model and makes no request. The model never judges quality; it turns codes into advice and writes text.

| Level | Codes |
| --- | --- |
| A page | `no_description`; `short_description` and `long_description` (outside 70 to 160); `long_title`; `noindex` on a published page; `no_h1` and `many_h1`; `image_no_alt` (`alt` missing; an empty `alt` is valid); `placeholder_text`; `duplicate_title` and `duplicate_description` |
| The site | `hidden_from_search`; `no_tagline`; `long_tagline`; `no_logo`; `no_icon` |

- **Headings:** the h1 count adds one when the page's template actually prints `core/post-title` at level 1. That depends on the template the site uses, since members can change `page.html`. Generated pages use `page-blank`.
- **Placeholders:**
  - A bracketed token is flagged when its first word is not a registered shortcode (`shortcode_exists`) and it has no `=` or quotes. So `[phone number]` and `[address]`, which the prompts ask the model to write, are flagged; `[gallery ids="1"]` is not.
- **What it cannot see:** it reads saved markup, so it misses dynamic blocks, template parts and synced patterns.
- **Later:** heading order, slugs, internal links.

## A description when Mody writes a page
The page writer still returns only block markup (rule 2).
- **After `PageGenerator::create_draft()`:** one best-effort call writes the description. A failure is logged and never blocks the page.
- **Recommended:** call the `ai` plugin's `ai/meta-description` ability (input `post_id`; it checks `edit_post`; 140 to 160 characters in the content's language; the brand is already in it through `PluginBrandContext`), then clean the answer: no quotes or line breaks, cut at a word. The same call is the tool's `generate`. About 0.5 h, one path to the model.
- **If that ability is missing** (the feature is off, as it can be on the main site), our own small call. It uses `using_provider( 'infomaniak' )` and the timeout, like `Agent` and `PageGenerator` (rule 1), with the page text as data inside `<page>` tags and no tools. About 1 h more. It is built only if the maintainer wants it.

## Abilities and permissions
All are in the `mody` category, run on the current site only, and are `meta.public` like the others.

| Ability | Input | Who | Output |
| --- | --- | --- | --- |
| `mody/update-seo` (new) | `page_id` or `page_title`; `title` and `description` (`""` goes back to automatic; left out means unchanged); `noindex`; `generate` (not together with `description`) | Logged in with `edit_pages`, then `edit_post` on that page or post | Old and new pairs, the page's links. Not destructive, idempotent. |
| `mody/update-site-settings` (one new field) | `hide_from_search` (true sets `blog_public` to 0) | `manage_options`, as now | Old and new as the strings "visible" and "hidden" (the output schema is strings) |

- **Pages and posts both:** `SiteEditor::find_page()` knows only pages, so `update-seo` gets its own small resolver: by ID with a type check, or by exact title among pages and posts.
- **`update_option( 'blog_public' )`** also updates `wp_blogs.public` through core, so multisite needs nothing more.
- **Changes are live at once:** a change to a published page needs no save, because meta has no draft. The old value comes back so the change can be undone.
- **Nothing outside Mody:** the agent gets no third-party SEO ability, and MCP stays refused to members.

## Rule 1
The feature makes no outbound request; its only model call goes through Infomaniak. The guard is a test and a "do not add" list.
- **Do not add:** IndexNow, sitemap pings, remote image fetches, preview services, or any tie to Search Console or Analytics.
- **For the maintainer:** a verification-tag field (Q8), and Cloudflare's managed `robots.txt` (Q9).
- **Tested in `scripts/seo-test.php`:**
  - `pre_http_request` records requests while the front page, a page, the sitemap and `robots.txt` render, while the audit runs and while the panel's REST save runs. There must be none. With a fake model answer, the only host is `api.infomaniak.com`.
  - A grep of `mody-seo/` for `wp_remote_`, `curl_`, `fsockopen` and `http` literals.
  - Every URL in the rendered head is on the site's own host.

## Parts, in order
About 18 hours in all, so rule 5 applies.
- **The smallest useful cut** is parts 1, 2 and 9, about 6.5 h: every site gets link previews and a tuned sitemap, and members can set a page's title, description and noindex.
- **An even cheaper stopgap** is to switch on excerpts for pages (one line) and use the excerpt as the description. It gives no title, no noindex and no agent, and the field is labelled "Excerpt".

Without parts 9 or 6, nobody can set a value, so parts 1 to 3 alone give almost no descriptions.

Tests go in `scripts/seo-test.php`, in the style of `brand-settings-test.php`:
- numbered sections and `$check`;
- throwaway users, and everything restored afterwards;
- `blog_public` set and restored, because the local main site has it at 0;
- HTTP checks on alpha, as `languages-test.php` does them.

No part needs `pnpm build`.

| Part | What | Hours |
| --- | --- | --- |
| 1 | `Fields` and `Head`: registration at priority 20, the defaults, the head tags, the title rules, the `ai` plugin's two filters, password-protected pages | 3 |
| 2 | `Sitemap`: noindex left out, users removed, categories per Q5 | 0.75 |
| 3 | Languages: `og:locale` and alternates, a translation's own fields, the copied excerpt ignored, no duplicate hreflang; a line in site-languages.md part 8 (`mody/translate-page` translates the two text fields) | 1 |
| 4 | `Audit`: the codes, the template-aware h1, placeholders through `shortcode_exists`, the 100-page cap and its time | 1.75 |
| 5 | The agent reads: `seo_context()`, the `<seo>` block, the rule; real requests (ai-engine.md's protocol) | 1.5 |
| 6 | `mody/update-seo`: the resolver, the ability, editor-only declaration, the test for both request sizes and its tool count; real requests | 2.5 |
| 7 | Descriptions through `ai/meta-description` after page generation and for `generate` | 0.5 (own call: +1) |
| 8 | The open editor stays in step: the bubble's refresh for an `seo` change reloads and saves only `meta` of the open post, never its content; `describe()` and the "held change" texts learn it | 1.5 |
| 9 | The "Search appearance" panel (`Panel`, `assets/seo-panel.js`, plain JS): `PluginDocumentSettingPanel` in the Site Editor's page sidebar and the post editor (confirmed in core's source); nothing for templates (checked in JS from `core/editor`); counters; Generate; it replaces the `ai` plugin's own panel in the post editor; a headless Chrome check first | 3 |
| 10 | `hide_from_search` in `mody/update-site-settings`, and `describe()` | 0.75 |
| 11 | Docs: AGENTS.md (a decision and a Where to work row), ai-engine.md (the agent's SEO, the size figures), mvp-spec §8 and §10, membership-tiers.md (the way out), next-steps.md, this ticket's Results. A WXR export test. | 1 |

**Also run:** `ai-agent-chat-test.php`, `agent-bubble-test.php`, `languages-test.php`, `ai-plugin-lockdown-test.php`, `ai-plugin-brand-context-test.php`, `gating-test.php` and `brand-settings-test.php`.

**Rollout:**
- Nothing to migrate or activate: the fields exist on every site once deployed.
- In production, canonical, `og:url` and the sitemap carry `/wp/` until M5-02 step 5 fixes `home`.

## Not in version 1
- JSON-LD (an Organization with name, logo and address, about 0.75 h; the first to add);
- SEO for terms, archives and authors;
- canonical and image overrides;
- verification tags;
- a search-result preview;
- redirects, breadcrumbs and our own `robots.txt` policy;
- IndexNow;
- keyphrase scores.

## Open questions
**Before part 1:**
1. ~~The base.~~ **Answered on 2026-11-10: our own build.** The SEO Framework was the runner-up.
2. ~~Where it lives.~~ **Answered: its own `mody-seo` MU plugin** (the recommendation was `mody-platform/src/Seo/`). It is the third exception, after `mody-mail` and `mody-languages`.
3. ~~Where the description is stored.~~ **Answered: `mody_seo_description`,** with the `ai` plugin pointed at it.
4. ~~Do new sites start hidden from search?~~ **Answered: signup sites start hidden.**
   - **Why it was urgent:** registration is open, and each signup added a public "Our new website is on its way" site under `mody.cloud`. Many thin pages could harm the whole domain in search results (**Inference**).
   - **Built 2026-11-10, ahead of this ticket:** `Pipeline` creates a starter site with `public` 0, so `blog_public` is 0 and core prints `noindex`. `registration-test.php` checks it (58 checks).
   - **Wizard sites,** which get real pages, stay public.
   - **Opening it:** the member switches it in Settings → Reading. Mody and the audit offer it once the site has real pages (parts 4, 5 and 10).

**Later, at the part that needs it:**

5. **The category archives in the sitemap (part 2).** Recommended: remove them while terms have no SEO of their own; the "Uncategorized" archive is thin.
6. **Tool size (part 6).** Recommended: declare `update-seo` only when a page is open, and accept the editor request at about 4.75 KB if real requests pass. If they fail, shorten `open-editor` (702 bytes) and `generate-page` (643). As a last resort, ship without the tool: the agent suggests and points at the panel.
7. **May the agent hide the whole site (part 10)?** Recommended: yes, only when asked, after the owner confirms, saying it can be undone and is only a request.
8. **Verification tags** (Google, Bing). Recommended: not in version 1. A pasted string involves no request from us, but it ties the site to a US service. A member on a subdomain has no other way until custom domains exist. If added: one strictly checked setting per engine, never a free head-code field.
9. **`robots.txt` in production.** Cloudflare serves its managed file (AI crawlers disallowed, no `Sitemap:` line). Recommended: leave it while Cloudflare stays (a temporary exception), and check in its dashboard whether WordPress's `Sitemap:` line can show. That is not code.
10. **A description for every generated page (part 7).** Recommended: yes, through the `ai` plugin's ability. Our own call where it is missing is optional.

## Results
None yet.
