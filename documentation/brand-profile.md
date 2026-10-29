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

The briefing's enum values for `tone_of_voice` are `'formal','cercano','tecnico'` with default `'cercano'`. They are translated here (`friendly`, `technical`) because everything in the code base is in English. Nothing is implemented yet, so this is free to change.

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

## Site creation flow

1. A registered user passes the `can_create_site()` check ([membership-tiers.md](membership-tiers.md#gating-logic-pseudocode-from-the-briefing-translated)).
2. If they have quota, the brand profile wizard runs (saved in `mody_brand_profiles`).
3. A script creates the user and the site in Multisite through the REST API (Application Password).
4. It writes the site's styles with the profile's palette and fonts.
5. It builds SYSTEM (profile) plus USER (home prompt) and calls Ministral.
6. The validator checks the block syntax and retries if the JSON is broken ([ai-engine.md](ai-engine.md#validation-pipeline)).
7. It inserts the content into the new site's home page.
8. It registers the Newsletter domain and sets the role's monthly sending cap ([newsletter.md](newsletter.md)).
9. It sends a welcome email with credentials.
10. The "Add page" button reuses the profile without asking again.

## Open questions

- **Table scope in Multisite.** The row carries `site_id`, so the table is network-wide. Use `$wpdb->base_prefix`, not the per-site prefix. Whether `site_id` should be unique is not specified.
- **Per-site styles.** See [architecture.md](architecture.md#open-questions).
- **Fonts.** `Inter` is the default. Loading it from a third-party CDN conflicts with the sovereignty rule and GDPR, so font files must be served locally (`fontFace` in `theme.json`, see [theme-development.md](theme-development.md#design-tokens)). Other fonts a member picks need the same treatment.
- **Content language.** The system prompt is in English. The language of the generated page content is not specified. It presumably follows the member's prompt (**Inference**).
- **Input sanitization (rule 6).** Validate every wizard field server-side. Suggested: hex colors against `^#[0-9a-fA-F]{6}$`, tone against the enum, lengths against the column sizes, logo as a media-library upload. **Inference.**
