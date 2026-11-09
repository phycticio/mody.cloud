# M1-04 · The registration chat as a block you can style and place anywhere

**Milestone:** M1 · **Builds on:** [M1-03](M1-03-chat-signup-and-editor-tour.md) (the chat signup) · **Spec:** documentation/mvp-spec.md §4, §6; documentation/theme-development.md (design tokens) · **Status:** planned and confirmed 2026-11-09. Part 0 done: M1-03 committed (`b46c427`), merged into `main` (`6046d68`) and deployed (`f6b7001`). Part A done on 2026-11-09 (Results). Next: part B.

## Goal (maintainer, 2026-11-09)
> The registration wizard should be a gutenberg block and it should have all the style editions possible from margin, padding, background-color, background image, background gradient, font size, button colors, font color, etc, all color pickers should show only the color palette colors including the custom colors created by the user. We then can create any link and insert the registration block and have it working everywhere. The other settings the block should have is the fields required and the mapping of the fields. These fields will be the questions asked to the user.

## Where it starts
M1-03 built the chat as `mody/register`, but as a server-only block:
- no editor script;
- hidden from the inserter (`inserter: false`);
- no style supports;
- always the same four questions: name, email, the project's name and its address.

`Registration::ensure_page()` puts it on `/register/`. Its colors come from `assets/register.css`, and its buttons already carry WordPress's `wp-element-button` class. The theme has no gradient presets and still offers a free color picker (`settings.color.custom` is not set).

## Decided by the maintainer (2026-11-09)
1. **A real block, on the main site only.** `mody/register` can be inserted on any page, template or pattern of `mody.cloud`, by super admins, and it works wherever it is placed. It is not in member sites' inserter, and it renders nothing there (as today).
2. **Every style WordPress offers a block:** margin, padding, background color, image and gradient, font size, text color, button colors, and the rest of the list in part B.
3. **Color pickers show only palette colors, on every block of every site.** That is the theme palette plus the site's own custom palette colors. Checked in WordPress 7.1 (`editor.js`, the global styles palette panel): the **Custom** palette under Styles → Colors → Palette is shown whether or not the free picker is on, so people can still add colors there, and every picker then offers them.
4. **The block's settings: the questions, which are required, and what each one fills (the mapping).** A question can fill:
   - **account and site:** name (the account's name), email, the project's name (the site's title), the address (the subdomain);
   - **brand:** tagline, mission, tone of voice, which go into the new site's settings, so the agent knows the brand from the first request (rule 4);
   - **custom answers:** free questions ("How did you hear about us?"), kept on the member for super admins to read.
5. **M1-03 is committed first,** on its own branch, before this ticket's work starts (part 0).
6. **Usernames are the email, always** (maintainer, 2026-11-09: "Set the usernames to be the same as the email. Always."). This holds for every new account, whatever creates it: the signup, Network Admin's Add User, WP-CLI. Today the signup derives the username from the part of the email before `@` (`Registration::unique_login()`).
7. **The username follows the email** (maintainer, 2026-11-09, D1: "Yes username changes everytime email address changes."). WordPress has no API to rename a login, so part C updates `user_login` in the database whenever an account's email changes.

## Choices made while planning (confirmed by the maintainer on 2026-11-09)
1. **Always asked:** the email (the account needs it) and the project's name (the site's title). These two can be reworded and moved, but not removed or made optional.
   - **The address:** when the block doesn't ask for it, it comes from the project's name (`suggest_slug()`), and the summary shows it.
   - **The name:** when not asked, the account's display name is the part of the email before `@`. The username is the whole email (decision 6).
   - **Every other question** can be optional; an optional question gets a **Skip** button in the chat.
2. **One question per destination, and at most 10 questions,** of which at most 5 are custom answers.
   - **Each question has its own wording**, which defaults to today's. `{name}` in a question is replaced with the visitor's name, as the email question does today.
   - **Tone** is asked with three buttons (Formal, Friendly, Technical). Without that question, the site starts Friendly, as today.
3. **The configuration travels signed.** The server renders the block's questions with a signature (`wp_hash()` of the normalized configuration). The chat sends both back with each request, and the REST routes accept only a configuration they signed.
   - This works the same in a page, a template or a pattern, without looking up where the block is stored.
   - A visitor cannot drop a required question or send an answer to another destination.
   - The destinations are a fixed list in PHP; nothing from the block is ever used as an option or meta name.
4. **One block per page** (`multiple: false`, as today). The chat uses fixed element IDs, and a page with two signups has no use.
5. **The chat's own texts:** the intro and the closing message ("check your email") are block settings. The rest (summary, buttons, errors) keeps today's wording.
6. **The chat follows the block's styles.** `register.css` is rewritten to inherit: text and links use the block's colors, the buttons use the button colors (`wp-element-button`), and fonts and sizes come from the block. Today's colors become the defaults that the theme's styles give the block.
   - **The two kinds of message:** Mody's messages get a light tint of the text color. The visitor's messages use the button colors by default, plus a **Messages** panel with two palette-only color settings (background and text) for the visitor's messages.
7. **The editor shows a still preview** of the chat inside `useBlockProps()`: the intro, the first question, a sample answer, the input and its button. Every style change shows at once. The real chat runs only on the front end.
8. **Plain script, no build step,** like the MU plugin's other scripts:
   - `blocks/register/block.json` in the MU plugin;
   - `assets/register-block.js` for the editor (`wp.element.createElement`, `wp.blockEditor`, `wp.components`).
9. **The `/register/` page keeps working unchanged.** A block without attributes gets today's four questions.
10. **Custom answers** are stored as user meta (`mody_signup_answers`: each question as it was shown, with its answer). Super admins see them on the user's profile in Network Admin. Nothing else reads them.
11. **Palette-only, site-wide** (decision 3) is set in `theme.json`: `settings.color.custom: false` and `customGradient: false`.
    - **Gradient presets:** about four, built from the palette's CSS variables (for example primary to accent), so they follow each site's own colors and its style variation.
    - **Existing colors:** content that already has a custom hex color keeps it; nobody can pick a new one.
    - **The agent** already writes colors only by palette slug (`Design\SiteStyles`), so it is unaffected.

## Parts (each 1 to 2 hours)

### Part 0 · Commit M1-03 on its own
- Rerun `registration-test`, `onboarding-test`, `members-test` and `provisioning-test`.
- Commit M1-03's files and hunks on `feature/m1-03-chat-signup`, and merge into `main`. Deploy only when the maintainer says so.
- Its browser check is still the maintainer's (M1-03, Results).

### Part A · Palette-only color pickers on every site
- **`theme.json`:**
  - `settings.color.custom: false` and `customGradient: false`;
  - gradient presets from palette variables;
  - `defaultPalette` and `defaultGradients` stay off.
- **Check the style variations** (`styles/*.json`): the gradients follow their palettes.
- **Check in a browser:** a paragraph's and a group's color pickers show the theme palette and a custom palette color added under Styles → Colors → Palette, with no free picker. The gradient picker shows the presets.
- **Docs:** theme-development.md (design tokens, rules).

### Part B · The block and its styles
- **`blocks/register/block.json`** (API version 3), registered from it.
  - **Supports:**
    - `color`: background, text, gradients, link, button, heading;
    - `background`: backgroundImage, backgroundSize;
    - `spacing`: margin, padding, blockGap;
    - `dimensions`: minHeight;
    - `typography`: fontSize, lineHeight, fontFamily, fontWeight, fontStyle, letterSpacing, textTransform, textDecoration;
    - `border`: color, radius, style, width;
    - `shadow`;
    - `align`: wide and full;
    - `anchor`.
  - **In the inserter on the main site only.**
- **`Registration::render()`** wraps its output in `get_block_wrapper_attributes()`, so every support applies on the front end, including the button colors that WordPress scopes to `.wp-element-button` inside the block.
- **`register.css`** rewritten to inherit from the block (choice 6).
- **`assets/register-block.js`:** the editor preview (choice 7).

### Part C · Questions and mapping (server and chat)
- **Usernames are the email (decision 6):**
  - the signup sets `user_login` to the email;
  - a `wp_pre_insert_user_data` filter does the same for every new user, whatever creates it, and Network Admin's Add User form accepts it (multisite allows only lowercase letters and digits there);
  - emails longer than 60 characters, the `user_login` column, are refused with a clear message;
  - `user_nicename` comes from the name, not the email, because author URLs show it and would reveal the address.
- **The username follows the email (decision 7):**
  - when an email change takes effect (`profile_update` with a new `user_email`: the member's confirmation link in Multisite, or a super admin's edit), `user_login` becomes the new email through `$wpdb`, and the user cache is cleared;
  - for the current user, the login cookie is set again, because it carries the username and would otherwise log them out;
  - a new email over 60 characters is refused, as at signup.
- **Attributes:** `questions` (a list: destination, wording, required), `intro`, `done`.
- **`Registration::config()`** normalizes them:
  - only known destinations, each once;
  - email and the project's name always present and required;
  - at most 10 questions;
  - lengths capped.
  It also signs the configuration (choice 3).
- **The REST routes `register/check` and `register`** take the configuration and its signature, and answers keyed by destination. They refuse an unsigned or changed configuration, and a missing required answer.
- **`Registration::register()`** takes the answers by destination:
  - mission and tone go into the starter site's `Pipeline` input, which already takes both;
  - the tagline goes into the new site's `blogdescription` once the site exists;
  - custom answers go into the user meta (choice 10).
- **`assets/register.js`** runs the chat from the configuration:
  - the order and wording;
  - **Skip** on optional questions;
  - the tone's three buttons;
  - the summary lists what was asked.
- **The no-JS form** shows the same questions.

### Part D · The block's settings in the editor
- **A Questions panel:**
  - each question's wording, destination (only the unused ones) and required toggle (locked for email and the project's name);
  - move up, move down and remove;
  - **Add question** (an unused destination, or a custom answer).
- **A Chat texts panel:** the intro and the closing message.
- **A Messages panel:** the visitor's message colors, palette-only (choice 6).

### Part E · Tests, docs and the browser check
- **`scripts/registration-test.php`:**
  - a block without attributes gives today's chat;
  - normalizing (unknown destinations dropped, the two required ones forced back, the limits);
  - the signature (a changed configuration refused);
  - a required answer missing;
  - a signup with brand questions (the site's tagline, mission and tone set);
  - custom answers stored;
  - the block's supports registered;
  - the inserter on the main site only.
- **A check of `theme.json`:** color pickers are palette-only and the gradient presets exist.
- **Browser (headless, scripted, as for M1-03):**
  - insert the block on a new page and change each style; see it in the editor and on the front end, at 1440 px and 390 px;
  - add a brand question and a custom one, then sign up on that page;
  - a member site's inserter has no registration block.
- **Docs:**
  - mvp-spec.md §4 (the signup);
  - AGENTS.md (the Members row);
  - theme-development.md (part A);
  - brand-profile.md (signup answers fill the brand);
  - this ticket's Results;
  - next-steps.md.

## Results

### Part A · Palette-only color pickers (2026-11-09)
- **`theme.json`:** `color.custom` and `customGradient` are `false`, with four gradient presets built from the palette's variables. The table and rules are in [theme-development.md](../documentation/theme-development.md#design-tokens). No style variation defines gradients, so all six presets use the same four, in their own colors.
- **Found in the browser: Spectra draws some pickers itself, whatever the settings say.** Spectra's color fields (38 of them) follow `color.custom`, but these do not:
  - the Advanced Gradient's Color 1 and Color 2 (container, slider, slider-child) have a free picker above the palette. Switching it on also starts from Spectra's own two blues (`#06558a`, `#0063A1`) until the owner picks palette colors;
  - five color lists have a custom color button: the container's shadow and border hover colors, the button's border hover and shadow colors, and the content block's text shadow color.
- **`Design\PaletteOnly`** (new) adds one editor style, hooked to `enqueue_block_editor_assets`, that hides those free parts and keeps their palettes. The selectors leave core's palette editor alone, and core's shadow editor (Styles → Shadows), which has no palette, keeps its picker.
- **Stale wording fixed:** the validator's gradient message to the model, `mody/update-styles`' gradient error, `PatternImport`'s comment and ai-engine.md (all said "the theme defines no gradients").
- **Tests:**
  - `scripts/style-variations-test.php`, new section 5: the settings, gradients made only of palette variables, no variation overriding them, the settings each site's editor gets, and the style;
  - `scripts/spectra-guard-test.php`, two checks: Spectra's color fields still take the site's settings, and its own pickers are still where the style hides them.
- **Browser check** (headless, phycticio, which has a custom palette color "Red"; a draft page with a paragraph, a group and a container):
  - **core blocks:** the paragraph's and group's text, background and gradient pickers show the five theme colors plus Red, with no custom color button. Their gradient picker shows exactly the four presets, drawn in phycticio's own colors;
  - **the container:** its color, text hover, background, background hover and divider fields are the same;
  - **Spectra's own pickers, with the style:** Advanced BG's Color 1 shows only the palette, and the content block's text shadow has no custom color button. The test page was deleted.
- **Quirks left as they are:**
  - the text shadow list shows only the site's own colors when it has any (here only Red), not the theme's five; that list comes from Spectra;
  - Spectra's starting blues stay until the owner picks palette colors.

## Not in this ticket
- **The block on member sites** (decision 1): a member's own signup page would create platform accounts, and needs rules and abuse limits first.
- **Before opening registration in production** (M1-03, "Not in this ticket"): a rate limit and a spam check (M5-01), mail (M5-02 step 6), and the wildcard DNS and TLS.
- **Hover colors for the buttons:** WordPress 7.1 adds per-block state styles (`block-supports/states.php`, `:hover`). It is new, and the editor's controls for it were not checked. Inference: leave it until it is stable.
- **The agent inserting the block:** the page writer's block list does not include it, and generated pages never sign anyone up.
- **Editing the chat's other texts** (summary, buttons, errors): only the intro and the closing message are settings (choice 5).
- **Renaming existing accounts at once:** they keep their usernames until their email next changes (decision 7). So far the only accounts are local test accounts and production's super admin.
