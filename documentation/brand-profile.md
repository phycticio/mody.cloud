# Brand profile

The briefing calls this the "Expediente de Marca". Before generating any page, the member completes a 3-step **identity wizard**. The answers are stored as the brand profile, the source of truth the agent reads on EVERY generation. In an FSE theme, the palette and typography are applied by writing the site's styles (`theme.json`).

Related: [ai-engine.md](ai-engine.md) (how the profile reaches the model), [theme-development.md](theme-development.md#design-tokens) (the palette the profile overrides), [membership-tiers.md](membership-tiers.md) (the quota check that starts the flow).

## Wizard steps

1. **Identity:** name, slogan, mission, vision, tone (`formal`, `friendly`, `technical`).
2. **Visual:** palette (Mody presets as a suggestion), logo upload, heading font and body font.
3. **First page:** free prompt. It is generated and published as the home page.

## Data schema (custom WordPress table)

```sql
CREATE TABLE mody_brand_profiles (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT NOT NULL,
  site_id         BIGINT NOT NULL,
  brand_name      VARCHAR(120) NOT NULL,
  slogan          VARCHAR(160),
  mission         TEXT,
  vision          TEXT,
  tone_of_voice   ENUM('formal','friendly','technical') DEFAULT 'friendly',
  color_primary   VARCHAR(7) DEFAULT '#005f6b',
  color_secondary VARCHAR(7) DEFAULT '#333333',
  color_accent    VARCHAR(7) DEFAULT '#b50ba4',
  logo_url        VARCHAR(255),
  font_heading    VARCHAR(60) DEFAULT 'Inter',
  font_body       VARCHAR(60) DEFAULT 'Inter',
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

The briefing's enum values for `tone_of_voice` are `'formal','cercano','tecnico'` with default `'cercano'`. They are translated here (`friendly`, `technical`) because everything in the code base is in English.

**What is built (2026-11-06, M2-02 and M3-02).** The table exists with the MVP schema from [mvp-spec.md §11](mvp-spec.md#11-data-model-new-network-wide-tables-base_prefix), which drops `slogan`, `vision` and the font columns shown above (Inter is fixed for the MVP). `Mody\Platform\Brand\BrandProfile` creates it and reads a site's profile, including its row ID and the three colors; a color that is not six-digit hex reads as the default preset's (Mody Teal). Prompts use only `brand_name`, `mission`, `tone_of_voice` and `logo_url`. The colors reach the site through Global Styles, not the prompt.

**The colors are the brand's, not only the first site's** (decided 2026-11-06). When the member asks the agent for new colors, it first asks whether they are part of the brand. If they are, `mody/update-design` changes the site's Global Styles **and** the profile's colors (`BrandProfile::update_colors()`); if not, only the site's Global Styles change. See [ai-engine.md, "Changing the colors"](ai-engine.md#changing-the-colors).

**Design decision:** the profile lives IN the WordPress database, next to the site. When the site is created, the agent writes the palette and fonts of the profile into the site's styles. The whole site (templates, blocks, editor) then obeys that identity automatically.

How the profile fields map to the theme's named palette:

| Profile field | Theme palette slug |
| --- | --- |
| `color_primary` | `primary` |
| `color_secondary` | `secondary` |
| `color_accent` | `accent` |
| n/a | `base` and `contrast` stay fixed (light background, deep black) |

## Message to the model

Each page combines the profile (system prompt) with the member's prompt for that page (user prompt). The system prompt and every instruction in it are written in English.

```text
SYSTEM (assembled from the brand profile):
  You are an expert in WordPress Gutenberg (FSE theme). Generate ONLY the
  serialized block format (<!-- wp:... --> comments). No Markdown, no
  explanations. Only blocks from the whitelist (core/* and spectra/*).
  Mandatory brand identity:
    Name: {brand_name} | Slogan: {slogan}
    Mission: {mission} | Vision: {vision} | Tone: {tone_of_voice}
    Palette (defined in theme.json): {color_primary} / {color_secondary} / {color_accent}
    Fonts: headings {font_heading}, body {font_body}
    Logo: {logo_url}
  Use the theme's palette colors (named colors), not loose hex values.

USER (the member's prompt for this page):
  {user_free_prompt}
```

The master prompt rules, whitelist and validation are in [ai-engine.md](ai-engine.md#master-prompt-rules).

**As built (`SystemPrompt`, 2026-11-06).** The message follows this shape, with three deliberate changes:

- **Slugs, not hex.** The palette is named by slug only (`primary`, `secondary`, `accent`, `base`, `contrast`), so the model never sees a hex value it could copy.
- **Member text as data.** The member's fields sit between `<brand>` tags, declared as data and not instructions. This guards against text in a brand name or mission being read as a command.
- **No fonts.** The prompt says nothing about fonts: the theme sets them, and the model must not.

`SystemPrompt::brand_context()` is the shared part that every prompt reuses (rule 4).

## Site creation flow

Built 2026-11-06 as `Mody\Platform\Provisioning\Pipeline` in the MU plugin ([M2-01](../tickets/M2-01-site-provisioning.md), [mvp-spec.md §7](mvp-spec.md#7-site-provisioning-pipeline)). It runs in-process: no REST, no Application Passwords, and no credentials in the welcome email (the member logs in with their own account).

1. **`Pipeline::start( $user_id, $input )`** validates the wizard's answers (`Pipeline::validate()`), checks the quota and queues a job in `mody_provisioning_jobs`.
   - `slug`: the subdomain, checked with WordPress's own site-name rules (lowercase letters and numbers, at least 4 characters, not reserved, not taken).
   - `title`: the site title, at most 120 characters.
   - `brand_name`: at most 120 characters; the title by default.
   - `mission`: the "what do you do?" sentence, required, at most 200 characters (it becomes the tagline).
   - `tone`: formal, friendly (default) or technical.
   - `preset`: one of the six design presets (`Design\Presets`), Mody Teal by default.
   - `prompt`: the description of the first page, required, at most 2,000 characters.

   Errors come back as `mody_invalid_input`, with one message per field in `fields`.
2. **`Pipeline::run( $job_id )`** runs the steps in order: `quota`, `site`, `profile`, `theme`, `styles`, `page`, `email`. What each step does is in the mvp-spec table. In short: the quota, the site (public, themed, with the tagline and `https://` addresses), the brand profile row, the theme check, the preset in Global Styles, the generated front page (or the fallback page), and the welcome email.
3. **Resuming.** Each step's outcome and the next step are written in one update, after the step's side effects. A run killed in the middle of a step starts that step again, and the step does not repeat its work: the site and its front page record their job, the profile is looked up by site, and the email is marked before it is sent. A running job not updated for 5 minutes counts as killed. `run()` takes it up again, and refuses (`mody_job_busy`) while another request runs it.
4. **Generation failure** never blocks the site: the step publishes a fallback page ("Hello, I'm {brand}" and the mission). `Pipeline::retry_generation( $job_id )` generates the page again and replaces it.
5. **The quota** until M1: `Provisioning\Quota::can_create_site()` allows 3 sites per user (see [membership-tiers.md](membership-tiers.md#open-questions)).

Callers check permissions: the wizard (M2-03) for its user. Newsletter domain registration (the briefing's step 8) is post-MVP.

**Test:** `scripts/provisioning-test.php`. It covers validation, the quota, a full run with a fake model answer, a run killed after each step and resumed, generation failure and Retry generation, and one real run. Add `-e MODY_TEST_OFFLINE=1` after `docker exec` to skip the real run, or `-e MODY_TEST_KEEP_SITE=1` to keep its site. Sites, users, jobs and profiles made by the test are removed at the end.

```bash
docker exec -i -u www-data mody_cloud_app \
  wp --path=/var/www/html/wp --url=https://mody.local eval-file - < scripts/provisioning-test.php
```

**The briefing's flow** (for reference, superseded): a script created the user and the site through the REST API with an Application Password, wrote the site's styles, called the model, validated, inserted the home page, registered the Newsletter domain and emailed the credentials. "Add page" reused the profile; in the MVP that is the agent's `mody/generate-page`.

## Open questions

- **Table scope in Multisite.** The row carries `site_id`, so the table is network-wide. It uses `$wpdb->base_prefix`, not the per-site prefix (built that way, 2026-11-06). The MVP spec makes `(user_id, site_id)` unique, not `site_id` alone. Until that is settled, a site's profile is its newest row.
- ~~**Per-site styles.**~~ Resolved 2026-11-06 (M2-01): each site's colors live in its own Global Styles (`wp_global_styles`), written by `Design\SiteDesign::apply()`. See [architecture.md](architecture.md#open-questions).
- **Fonts.** `Inter` is the default. Loading it from a third-party CDN conflicts with the sovereignty rule and GDPR, so font files must be served locally (`fontFace` in `theme.json`, see [theme-development.md](theme-development.md#design-tokens)). Other fonts a member picks need the same treatment.
- **Content language.** The system prompt is in English. The language of the generated page content is not specified. It presumably follows the member's prompt (**Inference**).
- **Input sanitization (rule 6).** Validate every wizard field server-side. Built 2026-11-06 in `Pipeline::validate()` (see above): no hex is typed by the member (the colors come from a preset), the tone against the enum, lengths against the column sizes. The logo upload comes with M2-03.
