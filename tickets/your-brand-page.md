# Your brand: a Brand settings page that Mody reads with every request

**Builds on:** [M2-08](M2-08-brand-profile-wordpress-settings.md) (the brand is the site's own settings), the agent (M3), [login-branding.md](login-branding.md) (Settings → Login's tabs layout) · **Status:** built and deployed on 2026-11-11 (parts 1 to 6). The maintainer answered questions 1 to 4 the same day, all as recommended. Question 5 is a follow-up. Question 6 is built as recommended: the main site gets the page too.

## Asked for
By the maintainer on 2026-11-11:

> The agent needs more context from the user to understand their branding. In the identity tab could be helpful to have a button to a page called your brand and that takes you to a settings page brand with an interface like the one on the screenshot where users can manage the identity of their brand and the agent reads it with every prompt so they know exactly what to design for the user when they ask for a page, a block or anything.

The screenshot is an admin screen from another product. Only its layout is taken:
- a list of sections on the left;
- on the right, a card with the subject's name and its basics;
- a notice after saving;
- a panel with tabs: one tab shows the values as label and value rows, another edits them.

## What Mody knows today (checked 2026-11-11)
Every prompt gets `SystemPrompt::brand_context()`: the agent, the page writer, the edit writer and the template writer. The `ai` plugin's writing features get it through `PluginBrandContext`. It carries only four things:
- the name (`blogname`);
- the tagline (`blogdescription`);
- the mission: one sentence, at most 200 characters (`mody_mission`);
- the tone: formal, friendly or technical (`mody_tone`).

That is all Mody knows about a business. Some consequences:
- Pages are written for a business it knows in one sentence.
- Every phone number, address and opening hour is a `[placeholder]`: page rule 8 forbids inventing them, and there is nowhere to read the real ones from.
- In Spanish, German, French, Italian, Portuguese, Dutch and Catalan, Mody has to guess whether to use the formal or the informal "you" (`usted`/`tú`, `Sie`/`du`).

## The plan

### Where things live (rule: WordPress first, never keep a copy)
- **WordPress's own fields stay where they are.** The name, tagline, logo and icon are already settings, so the Brand page shows them and edits the same options. The mission and the tone keep their own settings too.
- **Everything new is one site setting:** `mody_brand`, an object registered with `register_setting()`.
  - **REST:** its schema lists every field with a length cap, so `/wp/v2/settings` checks it, and it belongs to the Site Editor's `root/site` entity.
  - **Saving:** a sanitize callback keeps only known keys, as plain text, with the caps applied.
  - **Who can change it:** `manage_options`, so members on their own sites and super admins (decision 2).
- **Per site,** like the rest of the brand (decided 2026-11-08). A member with three sites has three brands.

### The sections (decision 1)
| Section | Fields | Stored in |
| --- | --- | --- |
| **Overview** | How much is filled in, and the exact text Mody receives (read only) | — |
| **Identity** | Name, tagline, logo and icon (shown, with a link to the Identity screen); mission; vision (one sentence: where the business is going) | WordPress's settings, `mody_mission`, `mody_brand.vision` |
| **Voice** | Tone (the three); how we sound (free text: "short sentences, no jargon, a bit of humor"); how we address people (formal or informal "you"); words we use; words we avoid | `mody_tone`, `mody_brand.voice.*` |
| **Audience** | Who our customers are; what they need, or the problem we solve | `mody_brand.audience.*` |
| **Offer** | Products or services, up to 12, each a name and one line; what makes us different | `mody_brand.offer.*` |
| **Story and values** | Our story (a short paragraph); values, up to 6, each a name and one line | `mody_brand.story`, `mody_brand.values` |
| **Facts** | Address, phone, email, opening hours, the area served | `mody_brand.facts.*` |
| **Visual style** | Palette, fonts and gradients, read from Global Styles, with a link to Styles. Nothing to type here. | the site's Global Styles |

Every free-text field has a cap (between 80 and 1,000 characters). The whole brand, filled in completely, stays under about 3,000 characters in the prompt (**Inference**: enough for Qwen's context with the block cards).

### The page: Settings → Brand
- **Where:** `options-general.php?page=mody-brand`, titled **Your brand**. Its class is `Brand\BrandPage`, next to `BrandSettings`.
- **The layout follows the screenshot:**
  - the sections on the left; on narrow screens they become a row above the panel, like Settings → Login;
  - a header card: logo, name, tagline, "5 of 7 sections filled in", and a link to the Identity screen;
  - after saving, a notice: "Saved. Mody uses it from your next request.";
  - the section's panel, with two tabs. **Overview** shows the values as label and value rows (empty ones read "Not filled in yet"). **Edit** is the form.
- **Saving:** one form per section, posted to core's `options.php` like Settings → Login. Plain PHP and CSS (`assets/brand-page.css`), with no build step. Saving one section never empties another.
- **Lists** (offer, values, words): rows with Add and Remove, through a small script (`assets/brand-page.js`). Without the script, the page shows a fixed number of empty rows.

### The button on the Identity screen
`assets/identity-fields.js` adds a **Your brand** button under Mission and Tone, linking to the page. Settings → General gets the same link. If the screen has unsaved changes, the Site Editor's own "leave the page?" warning applies (**Inference**, to check in the browser).

### Mody reads it with every request
- **The context:** `brand_context()` adds every filled-in field inside the existing `<brand>` tags. That text is declared as data entered by the owner, not instructions. Empty fields are left out, so a new site's prompt stays as short as today. The four prompts and `PluginBrandContext` get it with no other change.
- **Facts instead of placeholders:** page rule 8 changes to "use the facts in the brand; write a placeholder only for a fact that is not there". Prices stay placeholders.
- **The form of "you":** the brand's choice goes into the tone line. Without a choice, the rule stays as it is.

### Mody fills it in
- **What it can change:** `mody/update-settings` gets the brand's fields, in the direct tier, because the owner is telling Mody about their own business. A list is sent whole (all services, all values). Decision 3.
- **Size:** settings are described in the prompt, not in the tool declarations, so the 4,000-byte tool budget holds (**Inference**, to measure).
- **Opening the page:** `mody/open-editor` gets the target `brand`, so "show me my brand" opens it.
- **A thin brand:** when the brand is mostly empty and a request needs it (a services page with no services), Mody writes the page with placeholders. Then it suggests filling in the brand, with a link to the page.

## Parts (each under 2 hours, rule 5)
1. **The setting:** `mody_brand` with its schema, caps and sanitizing; `BrandSettings::brand()` returns it. Test: REST, caps, unknown keys, no `manage_options`.
2. **The page:** Settings → Brand, its sections, the Overview and Edit tabs, saving, and the narrow layout.
3. **The buttons:** on the Identity screen and in Settings → General.
4. **Mody reads it:** `brand_context()`, the rule 8 change, and the form of "you". Tests: the prompt for an empty brand and a full one, and real requests. A contact page must use the real phone number and hours. A page in German with the informal choice must use "du".
5. **Mody writes it:** the brand fields in `mody/update-settings`, and the `brand` target in `mody/open-editor`. Tests: "our services are…" saves the list, and "show me my brand" opens the page.
6. **The docs:**
   - brand-profile.md: the table and the brand context;
   - ai-engine.md: rule 8;
   - AGENTS.md: the Where to work row.

   Then a browser check of the page on a member site, desktop and phone.

Test file: `scripts/brand-page-test.php`, offline with `MODY_TEST_OFFLINE=1`.

## Decisions (the maintainer, 2026-11-11)
1. **The sections:** as proposed in the table above, with no additions.
2. **Who edits it:** administrators only (`manage_options`).
3. **Mody writes the brand directly** when the owner tells it, like the mission and tone today. Nothing is held for Apply.
4. **One brand per site**, written once in any language. Mody translates what it uses into the language of the page.

## Open questions
5. **The signup and the wizard:** should the editor tour send new members to Your brand instead of asking Mody "what do you do"? Recommended as a follow-up, not part of this ticket.
6. **The main site:** it gets the page too (recommended), and its brand is Mody Cloud's.

## Built (2026-11-11)
All six parts. What changed from the plan:
- **Flat keys.** `mody_brand` holds 16 fields by flat name (`voice`, `address_form`, `words_to_use`…), not nested sections. `BrandSettings::brand()` is unchanged; `BrandBook::get()` reads the rest.
- **The thin-brand suggestion was not built.** Mody writes placeholders for missing facts, as before, and does not yet suggest filling in the brand. It is left for later, with question 5.
- **Lists for the agent** are `{name, description}` objects, and the names are quoted in `<brand>`. The review of 2026-11-11 found that names with a colon ("Q&A: support") were split when Mody re-sent the list; 21 of 21 real requests then passed in three runs, adding a service next to such a name included.
- **Saving goes to `admin-post.php`, not `options.php`.** `options.php` writes every option of a settings group on each post, and empties the ones a form leaves out. A section's form would then have emptied the name or the tone. `BrandPage::save()` writes only the posted section's values, with a nonce per section.
- **Limits** (`BrandBook::FIELDS`):
  - vision 200 characters;
  - how we sound, audience, needs and difference 400 each;
  - story 600;
  - address 200;
  - opening hours 300;
  - words 15 per list, 30 characters each;
  - products and services 12, values 6: each a name of 80 or 60 characters and a line of 120.
- **Size, measured** (it replaces the 3,000-character **Inference**): the example brand in the test adds 956 characters to a prompt. With every field at its limit it adds 8,155 characters, about 2,000 tokens. The test keeps it under 8,500.
- **Words** are one per line, or separated by commas, in a text box, not rows. Products and values are rows with Add and Remove.
- **The agent** first wrote its own one-line description for each service the owner named. Agent rule 7 now says "in the owner's words, adding nothing they did not say", and the test checks it.

Code:
- `src/Brand/BrandBook.php` (the setting), `src/Brand/BrandPage.php` (the page);
- `assets/brand-page.css` and `assets/brand-page.js`;
- the button in `assets/identity-fields.js`, the Settings → General row in `BrandSettings::general_fields()`;
- `SystemPrompt::brand_context()`, page rule 8, edit rule 7, agent rule 7;
- the `brand_` settings in `Site\Settings`;
- the `brand` target in `SiteEditor`.

## Results
- **`scripts/brand-page-test.php`:**
  - 47 offline checks pass;
  - the seven real requests passed in each of three runs (21 of 21): a contact page with the real phone and hours, a German page with "du", the services saved with nothing added, the audience and the hours saved, "show me my brand", and a service added next to "Q&A: support".
- **In a browser** (headless Chromium on the `alpha` member site, as a throwaway administrator):
  - the page and its sections;
  - Add and Remove on the products;
  - saving and the notice;
  - no horizontal scroll at 390 px;
  - the Identity screen's **Your brand** button opening the page;
  - no console errors;
  - after the review: "Add another" hidden at 12 products, and a click there adds nothing.
- **Related suites:**
  - `update-settings-test.php` (offline and the agent for real), `brand-settings-test.php` and `ai-agent-chat-test.php` pass. The tools JSON is 3,919 bytes, under 4,000.
  - `editor-navigation-test.php` still has its three list failures from before this work.

Still to do: the maintainer's own look at the page.

