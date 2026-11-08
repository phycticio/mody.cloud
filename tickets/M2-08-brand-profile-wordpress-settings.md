# M2-08 · The brand lives in each site's WordPress settings and styles; the profile table goes

**Milestone:** M2 (wizard), with M3 (agent) · **Spec:** documentation/brand-profile.md, documentation/mvp-spec.md §6, §7, §8, §11, documentation/ai-engine.md ("Changing the colors", "Publishing and site settings", "Opening Site Editor screens") · **Status:** built 2026-11-08 (Results below), merged the same day. The maintainer approved the production deploy, which drops the table there, after a database backup. Related: [M3-10](M3-10-agent-edits-global-styles.md), where the agent edits every part of the site's styles.

## The decision (maintainer, 2026-11-08)
> The branding manual is not connected to the styles of the site in the editor. When a user says they want to change their brand image or their color palette, these changes always refer to the already defined Editor → Styles → Palette. If the user writes "I want to change this site's name, logo, slogan", etc., it refers to the WordPress site settings, icon and logo, or anything in `site-editor.php?p=/identity`. Whenever we think of a change, we need to be aware of whether there is a default WordPress setting or solution, and we prefer that.

> The profile is redundant, since we will be storing all the styles and branding per site of the multisite, so that should be their branding. The goal of the agent in the sites is that the users are able to edit every part of their theme.json database stored per site, and all changes are managed there.

**The principle, for every feature from now on:** when WordPress has a setting or a screen for something, that is the only place the value lives. Mody reads it from there, and changes it there or sends the member there. A site's brand is its WordPress settings (Identity) plus its styles (the site's Global Styles, the theme.json data stored per site). There is no separate brand profile.

## Where it stands (2026-11-08)
The brand profile (`mody_brand_profiles`, `Brand\BrandProfile`) duplicates what WordPress has, and holds two things it lacks:

| Profile column | WordPress already has | Today |
| --- | --- | --- |
| `brand_name` | the site title (`blogname`) | The wizard asks both; prompts use `brand_name`; the fallback page says "Hello, I'm {brand_name}". |
| `logo_url` | `site_logo`, `site_icon` | Creation sets `site_logo` and copies the URL; prompts send it. |
| `color_primary`, `color_secondary`, `color_accent` | the palette in the site's Global Styles | Creation writes both. `mody/update-design` asks "are these colors part of your brand?" (rule 7), and with yes it writes the profile too. |
| `mission`, `tone_of_voice` | no setting | Prompts use them. The mission also becomes the tagline at creation. |

**WordPress 7.1's Identity screen** (`site-editor.php?p=/identity`, read in core) has four fields, **Site Title, Site Tagline, Site Logo, Site Icon**, in a fixed list (`fields` and `form` constants in `edit-site`). It has no filter or slot to add fields. It edits the `root/site` entity, that is `/wp/v2/settings`, and the Site Editor's **Save** saves it. Every option registered with `register_setting( …, array( 'show_in_rest' => true ) )` joins that entity.

## Decisions (maintainer, 2026-11-08)
1. **Tagline and mission:** one wizard sentence ("What do you do?") seeds both at creation; afterwards they are independent. (Accepted.)
2. **The brand name is the site title.** The wizard's brand-name field goes, and prompts use `blogname`. (Correct.)
3. **No site icon in the wizard;** the agent opens Identity when asked. (Accepted.)
4. **No logo in prompts.** (Accepted.)
5. **Logo and icon: the agent opens Identity,** with no ability that sets them. (Accepted.)
6. **Mission and tone of voice on the Identity screen** (asked by the maintainer).
   - **Storage, the WordPress way:** they become two **site settings**, `mody_mission` (text, at most 200 characters) and `mody_tone` (`formal`, `friendly`, `technical`), registered with `register_setting()` and shown in REST. They are saved like the title and tagline.
   - **On the Identity screen:** a small script (plain JS, like the chat bubble; `wp.element` and `wp.components`) adds the two fields under WordPress's four on that route. It reads and writes the same `root/site` entity, so the Site Editor's own **Save** saves them, with the title and tagline.
   - **Not a public extension point:** WordPress gives none for this screen, so it depends on its markup. Like M3-09's in-place navigation, it gets a browser check after every WordPress update.
   - **The supported fallback:** the same two fields in **Settings → General** (`add_settings_field()`), which never breaks.
7. **The profile table goes.** A site's brand is its settings and its styles.
   - Mission and tone move to the two settings above; name, tagline, logo and icon are WordPress's; colors and fonts are Global Styles.
   - "No brand profile, no call" goes too: every site has a title, a palette and these settings. Empty mission or tone mean "not given" and `friendly`.

## The target
| What the member talks about | Where it lives (the only place) | What the agent does |
| --- | --- | --- |
| Colors, palette, "my brand colors" | Global Styles palette | Named: `mody/update-design`, with no brand question. Otherwise it opens Styles → Colors or Palette. Everything else in the styles: [M3-10](M3-10-agent-edits-global-styles.md). |
| Fonts, sizes, spacing, buttons, any style | Global Styles | M3-10, or it opens the matching Styles screen (M3-09). |
| Site name, slogan or tagline | `blogname`, `blogdescription` | `mody/update-site-settings` with the new words; otherwise it opens Identity. |
| Logo, site icon | `site_logo`, `site_icon` | Opens Identity. |
| What the business does, tone of voice | `mody_mission`, `mody_tone` (site settings) | `mody/update-site-settings` (it gains `mission` and `tone`), or it opens Identity. |

## Plan (rule 5: four parts of 1 to 2 hours)

### Part A · Brand settings, and the prompts read WordPress
- **`Brand\BrandSettings`** (replaces `BrandProfile`):
  - registers `mody_mission` and `mody_tone` (`register_setting()`, `show_in_rest`, sanitizing: plain text up to 200 characters; tone from the enum);
  - reads the brand for prompts: title, tagline, mission, tone;
  - two fields in Settings → General.
- **`SystemPrompt::brand_context()`:** built from `BrandSettings`, with title, tagline and mission as `<brand>` data. Tone and the palette slugs stay as now, the logo goes. `for_page()`, `for_agent()`, the editor prompts and `PluginBrandContext` follow.
- **`mody/update-site-settings`:** adds `mission` and `tone` to `title` and `tagline`.
- **Tests:** a title changed through WordPress shows in the next prompt; the settings in `/wp/v2/settings`, sanitized; the ability's two new fields.

### Part B · The agent follows the principle
- **`mody/update-design`:** no `brand` property, no `brand_profile_updated`, no `BrandProfile::update_colors()`. Prompt rule 7 (the brand question) goes.
- **`mody/open-editor`:** an `identity` target (`p=/identity`), described as "Identity".
- **A prompt rule:** "The site's name, tagline, logo, icon, mission and tone are its WordPress settings (Identity); its colors, fonts and every other style are its Styles. Change them there, or open the screen. Never keep a copy elsewhere."
- **No profile, no call goes:** `Agent`, `PageGenerator`, `SiteDesign`, the chat page's banner and the bubble's `hasProfile`.
- **Tests and real runs:**
  - "change my brand colors to coral" → `update-design` at once;
  - "I want to change my logo" → Identity;
  - "change the slogan to …" → `update-site-settings`;
  - "change the site name" (no name given) → asks or opens Identity;
  - "we now sell to schools, not offices" → `update-site-settings` with `mission`.

### Part C · The wizard and site creation
- **Step 1:** site name (it is the brand name), address, "What do you do?" (seeds tagline and mission), tone. The separate brand-name field goes.
- **Step 2:** unchanged for the member. The preset goes to Global Styles only, the logo to `site_logo` only.
- **`Provisioning\Pipeline`:**
  - `validate()` without `brand_name`;
  - the `site` step sets `blogname` and `blogdescription`;
  - the `profile` step writes `mody_mission` and `mody_tone` (the step keeps its name in `mody_provisioning_jobs`);
  - `copy_logo()` keeps `site_logo` only;
  - the fallback page uses the site title.
- **`WizardController`, `WizardPage`, `assets/wizard.js`, `WizardPreview`:** the field change.
- **Tests:** `wizard-test.php` and `provisioning-test.php`, plus a real creation where the site's settings hold everything and no profile row exists.

### Part D · Identity fields, the table, and the docs
- **`assets/identity-fields.js`, loaded in the Site Editor:**
  - on the `/identity` route, it renders Mission (a textarea) and Tone of voice (radio choices) under WordPress's form;
  - it uses `editEntityRecord( 'root', 'site', undefined, { mody_mission, mody_tone } )`, so the Site Editor's Save saves them;
  - when the screen's markup is not found, it does nothing; Settings → General still works.
- **Migration** (`BrandSettings::migrate()`, once per network):
  1. for each site, copy the profile's mission and tone into the site's options, and set an empty title from `brand_name`;
  2. then drop `mody_brand_profiles` and its version option.

  Nothing else is lost: titles, `site_logo` and Global Styles already hold the rest. Production has one member site (`phycticio`). Dropping the table cannot be undone, so the deploy that carries it needs your go-ahead.
- **Docs:**
  - brand-profile.md becomes "The brand", or is folded into the docs that keep it;
  - ai-engine.md (colors, site settings, screens);
  - mvp-spec §6, §7, §8, §11 (the table leaves the data model);
  - AGENTS.md: the principle among the decisions, and the Where to work row;
  - constraints.md rule 4: "the brand context", meaning settings plus styles;
  - superseded: the 2026-11-06 decision "the colors are the brand's".
- **Tests:**
  - `ai-agent-chat-test`, `ai-generate-page-test`, `ai-plugin-brand-context-test`, `agent-edit-test`, `agent-template-test`, `editor-navigation-test`, `wizard-test` and `provisioning-test` updated;
  - the migration on a copy of a site;
  - the Identity fields in a browser: shown, saved by Save, read back.

## Acceptance criteria
- Changing the title, tagline, mission or tone (on Identity, in Settings → General, or through the agent) changes the next generated page's brand context, with nothing to sync.
- "Change my brand colors to coral" changes the palette at once, with no brand question.
- "I want to change my logo" (or icon) opens Identity in place. "Change the slogan to …" changes `blogdescription`.
- The Identity screen shows Mission and Tone of voice under WordPress's four fields, and the Site Editor's Save saves them.
- The wizard asks no brand name. A new site's brand is in its settings and styles; `mody_brand_profiles` no longer exists.
- All suites pass, and the real runs pick the WordPress place each time.

## Out of scope
- Editing every part of the styles through the agent: [M3-10](M3-10-agent-edits-global-styles.md).
- A brand shared by a member's several sites: each site carries its own, by decision 7.

## Results (2026-11-08)
**Built:**
- **`src/Brand/BrandSettings.php`** (replaces `BrandProfile`, deleted):
  - registers `mody_mission` and `mody_tone` (sanitized, in REST);
  - `brand()` reads title, tagline, mission and tone now;
  - `save()`; the two fields in Settings → General;
  - `enqueue_identity_fields()`;
  - `migrate()` and `migrate_from()`.
- **`assets/identity-fields.js`:** Mission (a textarea) and Tone of voice (radio choices) under the Identity screen's form, on the `root/site` entity. The Site Editor's Save saves them. If the markup is not found, the script does nothing.
- **`SystemPrompt`:**
  - `brand_context()` is built from `BrandSettings`, without the logo. `for_page()`, `for_edit()`, `for_agent()` and `for_ai_feature()` take no profile.
  - Rule 7 is the principle: the brand is the site's settings and styles; logo and icon open Identity at once; colors change at once, without a brand question.
  - The `identity` target is described.
- **`PageGenerator`:** builds prompts on the target site (`on_site()`), with no profile check. Same in `Agent`, `SiteDesign`, `PluginBrandContext` and the chat page's banner; the bubble's `hasProfile` is gone.
- **`mody/update-design`:** no `brand` and no `brand_profile_updated`.
- **`mody/update-site-settings`:** adds `mission` and `tone` (an enum).
- **`mody/open-editor`:** `identity` (needs `manage_options`).
- **The wizard:** no brand-name field; the preview uses the site name.
- **The pipeline:** `profile` writes the two settings in the new site; the logo's title and the fallback page use the site title.
- **Two more agent guards**, found in real runs and sharing the narration guard's one retry:
  - a bare offer ("I can help with that.", `Agent::stalls()`): the logo request got it 5 times in 6, and opened Identity 6 in 6 with the guard;
  - "Done" with no tool run (`Agent::claims_done()`, rule 2): the slogan request got it once in about 8 runs.
- **The migration ran locally:** the main site's mission and tone moved into its settings. Site 102's row was skipped, since the site no longer exists, and the table was dropped. The two rows were backed up first, in the session scratchpad.

**Tests:**
- **`scripts/brand-settings-test.php`** (new, 20 checks):
  - REST registration, sanitizing, refusals;
  - Settings → General; the Identity script on the Site Editor only;
  - the brand context per site;
  - the migration on a throwaway copy of the old table: newest row wins, existing settings kept, deleted sites skipped, table dropped.
- **Updated:**
  - `ai-agent-chat-test`: another site's own brand; no brand question; `brand` refused as a property; mission and tone settings; the two new guards;
  - `ai-generate-page-test`, `ai-plugin-brand-context-test`, `agent-edit-test`, `agent-template-test`, `agent-bubble-test`, `spectra-layout-test`;
  - `editor-navigation-test`: identity; real runs for coral at once, the logo, the slogan and the mission. It now restores the site's styles and identity settings;
  - `wizard-test`: no brand field; the Plum preset in the site's Global Styles; the brand in the site's settings;
  - `provisioning-test`.
- **All 22 suites pass offline.**
- **Real runs:** `ai-agent-chat-test` passes, and the navigation suite's real phrases pass (after the guards; the earlier misses are above). Several runs hit Infomaniak's rate limit (HTTP 429) and were repeated after a pause.
- **In a browser** (headless Chromium, as `member` on alpha): the Identity screen shows Mission and Tone of voice under WordPress's four fields. Typing a mission, choosing Technical and saving with the Site Editor's Save stored both options, and they show after a reload. No JavaScript errors.

**Found and fixed while testing:** the navigation suite's real "make the primary color coral" now really changes the palette, which it did not do when the agent asked first. It had left the main site coral, which made the agent suite's revision check fail. The suite now restores the styles, and the main site's palette and mission were restored.

**Not done:**
- the production deploy (the table drop there needs your go-ahead);
- M3-10 (planned and decided).
