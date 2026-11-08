# M3-09 · The agent moves the Site Editor to what the member asks for

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 (tool table), documentation/ai-engine.md (The agent: `mody/open-editor`, "Where the agent appears", "The agent's buttons") · **Status:** parts A to C built 2026-11-08 (Results below); the browser check is pending; D later.

## Goal
In the Site Editor, the chat bubble takes the member to the screen they mean, in place: no page load, the chat stays open, as if the agent were part of the editor.
- "I want to change the colors of the site": **Styles → Colors** opens.
- "I want to edit the list of posts": the template that shows the posts opens in the canvas.
- The same goes for every section of the Site Editor: pages, templates, template parts, patterns, navigation, and each Styles screen.

Asked for by the maintainer on 2026-11-08.

## What exists (M3-01, M3-05 to M3-07)
- **`mody/open-editor`** returns a Site Editor link for a page, a template (by slug) or the Styles overview. The agent shows it as a button.
- **The buttons:** in the editors, a button to this site's wp-admin opens in the **same tab** with `?mody-chat=open`, so it is a full page load that reopens the bubble.
- **The bubble** sends what is open with each message (`post_type`, `post_id`, the selected block, the content) and applies the agent's edits to the open document (M3-06, M3-07).

## How it works (checked in WordPress 7.1's source, 2026-11-08)
- **The Site Editor is one page** (`site-editor.php`) with a client-side router (`@wordpress/router`). The screen is in the URL: `?p=<route>`, plus `canvas=edit` for the editing canvas and `section=<screen>` inside Styles.
- **The router is a single `history` object listening to the browser's `popstate` event.** That is also how Back and Forward work. So the bubble navigates in place:
  1. push the new URL with `history.pushState()`, using the state shape the history library uses (`usr`, `key`, `idx` + 1) so Back stays consistent;
  2. fire a `popstate` event. The router reads the new URL and renders that screen.

  The router's own `navigate()` is a private API that plugins cannot use; this path is the public browser one.
- **Unsaved changes survive:** the editor keeps edited entities in its store across screens, and **Save** lists them all. A full page load would lose them, or ask first. So navigating in place is also the safer path.
- **Outside the Site Editor** (the post editor for posts, other wp-admin screens), an in-place move is impossible. The button with a full load stays there.

## Destinations
| Screen | Route (`site-editor.php?…`) | Example request |
| --- | --- | --- |
| Editor home | `p=/` | "Go back to the start" |
| Pages / a page | `p=/page` / `p=/page/<id>&canvas=edit` | "Edit the About page" (exists) |
| Templates / a template | `p=/template` / `p=/wp_template/<theme>//<slug>&canvas=edit` | "Edit the list of posts", "Edit how single posts look" |
| Template parts / a part | `p=/pattern&postType=wp_template_part&categoryId=all-parts` (to confirm) / `p=/wp_template_part/<theme>//<slug>&canvas=edit` | "Edit the header", "Change the footer" |
| Patterns / a synced pattern | `p=/pattern` / `p=/wp_block/<id>&canvas=edit` | "Show my patterns" |
| Navigation / a menu | `p=/navigation` / `p=/wp_navigation/<id>` | "Edit the menu" |
| Styles overview | `p=/styles` | "Open the styles" (exists) |
| Browse styles (the presets) | `p=/styles&section=/variations` | "Show me other designs" |
| Colors / palette | `section=/colors` / `section=/colors/palette` | "I want to change the colors" |
| Typography | `section=/typography` | "Change the fonts" |
| Layout, background, shadows | `section=/layout`, `/background`, `/shadows` | "Make the content wider" |
| One block type's styles | `section=/blocks/<encoded name>`, e.g. `core%2Fbutton` | "Change how buttons look" |
| Style revisions | `section=/revisions` | "Undo my style changes" |
| Style Book | `p=/styles&preview=stylebook` | "Show every block in my styles" |
| Site identity | `p=/identity` (WordPress 7.x; contents to confirm) | "Change the site icon" |

**Not offered:**
- Additional CSS (`section=/css`): members have no `unfiltered_html` on Multisite, so they cannot use it.
- Posts: WordPress 7.1's Site Editor has no route for them (M4-01). "Edit my latest post" keeps a button to the post editor.

**Templates by meaning, not file:** the server resolves the requested template through WordPress's template hierarchy, so the agent opens what actually renders that view:
- `home` → `home`, else `index`;
- `category` → `category`, `archive`, `index`;
- `page` → `page`, `singular`, `index`; and so on.

On both local sites the front page shows the latest posts and the theme has no `home.html`, so "the list of posts" is the **Index** template, while category, tag, date and author lists use **Archive**. See decision 1.

## Plan (rule 5: three parts of 1 to 2 hours, plus a later one)

### Part A · Destinations on the server (`mody/open-editor`)
- **`SiteEditor`:**
  - URL builders for every destination above;
  - `TARGETS` grows to `page`, `template`, `template_part`, `styles`, `navigation`, `patterns`, `home`;
  - a `section` for Styles (`overview`, `browse`, `colors`, `palette`, `typography`, `layout`, `background`, `shadows`, `blocks`, `revisions`, `stylebook`), and `block` for one block type's styles;
  - `slug` for a template or part, resolved through the hierarchy (a missing part's error lists the parts);
  - `menu` for one navigation menu.
- **The result** gains `route`, the path the bubble navigates to, and `navigate: true`. It keeps `url` and `label` for the button, for when no in-place move is possible.
- **The declaration stays short.** The tools JSON is 3,782 of the 4,000 bytes the test allows. Rewrite `mody/open-editor`'s declaration compactly:
  - a one-sentence description;
  - enums instead of property descriptions;
  - `template` renamed to `slug`, also for parts.

  Put the guidance in the system prompt instead. Measure again (ai-engine.md, "Prompt size").
- **Permissions:** pages `edit_pages` and `edit_post`; everything else `edit_theme_options`, as today. Members are administrators of their own sites.
- **Tests (offline):** every destination's URL and route; the hierarchy (`home` → `index` here, `category` → `archive`); an unknown part or section refused with the list; the permissions; the tools JSON size.

### Part B · Navigating in place (the bubble)
- **`assets/agent-bubble.js`:**
  - `navigate( route )` with `pushState` and `popstate`, only on `site-editor.php`;
  - on each turn, the last action flagged `navigate` moves the editor;
  - a short note under the reply ("Opened Styles → Colors") with a **Back** button (`history.back()`).

  Elsewhere the action stays a button.
- **`Agent::take_link()`:** it carries `navigate` and `route` to the client, only for this site's Site Editor.
- **What is open gets the route:** the context sent with each message adds `p`, `section` and `canvas` from the URL (`EditorContext`). The agent then knows the member is already in Styles → Colors, and does not move them again. Its next edit applies to the newly opened template, which M3-07 already handles.
- **Tests:** `scripts/agent-bubble-test.php` checks the script and settings (offline). In a real browser, every destination opens in place, Back works, unsaved changes survive, and the post editor keeps its button. These checks need a browser: yours, or Chrome driven by me if you allow it.

### Part C · The agent's prompt
- **`SystemPrompt::for_editor()`:**
  - when the member wants to go somewhere, or to change something that has its own screen (colors, fonts, the header, the posts list, the menu), open it with `mody/open-editor`, then say in one sentence what is open and what they can do there;
  - when they name the change ("make the primary coral"), act with `mody/update-design` as today, and also open Styles → Colors (decision 2);
  - when the destination is unclear (the posts list, or category pages?), ask with `ask_user`.
- **Real runs, paced** (the Infomaniak rate limit), each phrase more than once:
  - change the colors → Colors;
  - edit the list of posts → Index (or ask, decision 1);
  - edit the header → the header part;
  - change the fonts → Typography;
  - show me other designs → Browse styles;
  - edit the menu → Navigation;
  - change how buttons look → the button's styles;
  - undo my style changes → Revisions;
  - regressions: "create an about page" still generates, and "add the tagline" in the header still edits.
- **Docs:** ai-engine.md (the `open-editor` row, "The agent's buttons", "Where the agent appears"), mvp-spec §8, AGENTS.md (Where to work).

### Part D (later, decision 4) · Open and change in one message
"Make the footer say 'Made in Zurich'" from another screen: navigate, wait until the editor has loaded the footer, then apply the edit. Today the member asks the change once the footer is open.

## Decisions (maintainer, 2026-11-08)
1. **Lists: the agent works out which template shows them.** In the maintainer's words: "dig to understand which archive template should be opened based on the registered posts and taxonomies".
   - The server builds the site's lists from what is registered:
     - the latest posts (the front page or the posts page);
     - each public post type with an archive;
     - each public taxonomy (categories, tags, custom ones);
     - authors, dates and search.
   - For each list, it follows WordPress's template hierarchy (`get_template_hierarchy()`) to the template that really renders it, a template customized in the Site Editor included.
   - The agent gets this map in its prompt, with counts. It opens the right one, or asks when the request could mean more than one.
2. **What moves the editor by itself:** `mody/open-editor` always, and `mody/update-design`: Styles → Colors after a color change, Browse styles after a preset. **Changed later the same day:** a page the agent creates opens directly too. Published pages keep their View and Edit buttons.
5. **Pages by title** (later on 2026-11-08): "Edit the … page" opens the page when the title is clear. When it is not, the agent asks, with the likely pages as choices, until a page can be opened.
3. **Outside the Site Editor:** the button stays.
4. **Part D:** later, as its own ticket.

## Risks
- **The `popstate` path is an implementation detail of the Site Editor** (stable, because Back and Forward rely on it). Add a browser check to the list run after each WordPress update, like `spectra-guard-test.php` after Spectra updates.
- **Tool size:** the history of bracketed replies (ai-engine.md, "Prompt size"). Keep the declaration compact, and run the real tests more than once.
- **A wrong destination:** the agent asks when unsure, and every move has **Back**.

## Acceptance criteria
- In the Site Editor, "I want to change the colors of the site" opens Styles → Colors in place, with the chat still open and nothing reloaded.
- "I want to edit the list of posts" opens the template that renders the posts list (decision 1) in the canvas.
- Every destination in the table opens in place. Back returns to the previous screen. Unsaved changes are kept.
- In the post editor the same requests give a button, as today.
- The tools JSON stays under 4,000 bytes. The real runs pick the right destination for each phrase in the list.

## Results (parts A to C, 2026-11-08)
**Built:**
- **`src/SiteEditor.php`:**
  - every destination of the table, except site identity (its contents were not confirmed);
  - `styles_screen()` for the Styles sections;
  - `lists()`: the site's lists, decided by what is registered and resolved through `get_template_hierarchy()` against the site's templates;
  - `screen_from_input()` and `describe()` for where the owner is;
  - each result carries `place` and `navigate`.
- **`src/Abilities.php`:** `mody/open-editor`'s compact declaration (`target`, `page_id`, `page_title`, `template`, `list`, `section`, `block`). The tools JSON went from 3,782 to 3,648 bytes.
- **`src/Design/SiteDesign.php`:** `mody/update-design` opens Styles → Colors, or Browse styles after a preset.
- **`src/Agent/Agent.php`:**
  - `take_link()` passes `navigate` and `place` for Site Editor URLs;
  - `respond()` takes the screen;
  - two guards: `mody_already_open`, and the narrating reply (`narrates()`).
- **`src/Agent/ChatController.php`:** the `screen` parameter, kept in the job.
- **`src/AI/SystemPrompt.php`:**
  - the targets explained;
  - rule 9 (open the screen; lists through their key; ask when unclear);
  - the editor's rule renumbered to 10, with "never reopen what is open";
  - `lists_context()` and `for_screen()`.
- **`assets/agent-bubble.js`, `assets/agent-chat.js`, `assets/agent-bubble.css`, `EditorBubble`:**
  - the screen is sent with each message;
  - `navigate()` with `pushState` and `popstate` (a no-op when already there);
  - the note with **Go back**.

**Differences from the plan:**
- Template parts take the existing `template` property instead of a new `slug`, which saves bytes and keeps the old name.
- Menus need no `menu` property: the only menu opens directly, otherwise the Navigation screen.
- Site identity (`/identity`) is not a target yet.
- Two guards were added after real runs, recorded in ai-engine.md, "Opening Site Editor screens".

**Tests:**
- **`scripts/editor-navigation-test.php`** (new):
  - offline: every destination, the lists (with a post type, a taxonomy and a customized template registered for the test, and a posts page), the screens, the prompt, the permissions;
  - real: eleven phrases. After the final prompt, 11 of 11 in four runs.
- **`scripts/ai-agent-chat-test.php`:** in place and named; Colors and Browse styles after a design change; both guards.
- **`scripts/agent-bubble-test.php`:** the strings and the script.
- **All 20 suites pass offline.**
- **Real runs after the change:**
  - `ai-agent-chat-test` passes;
  - `agent-template-test` passed 2 runs out of 3 (the first run had two misses before the last fix);
  - `agent-edit-test` passed 1 of 2. Its miss, "add an FAQ to the whole page", called `edit_content` but returned no edit, which points at the page writer, a separate prompt this ticket did not change. 14 probes of the same request all edited.

**Not checked: the in-place switch in a real browser.** PHP tests cannot run it. To check in the Site Editor of a member site:
- ask "I want to change the colors of the site": Styles → Colors opens, the chat stays open, and **Go back** returns;
- ask "edit the list of posts": the Index template opens in the canvas;
- make an unsaved change first, then ask for another screen: the change is still listed under Save;
- in the post editor, the same request gives a button.

## Results (new pages and pages by title, 2026-11-08)
**Built:**
- `mody/generate-page`'s result carries `place` and `navigate`, so the new draft opens in place. `Agent::take_link()` switches to the first Site Editor link of such a result: `url` or, for a new page, `edit_url`.
- **`SiteEditor::match_page()`** for `mody/open-editor`'s pages:
  - the exact title first (as `find_page()`, which `mody/publish-page` keeps using);
  - else the one page whose title partly matches, compared without accents, case, punctuation, a leading "the/my/our" or a trailing "page";
  - several matches are `mody_page_ambiguous`, listing up to 8. Titles under 3 characters never match partly.
- **Rule 9:** pass the title as said; when it is unclear, or none or several match, ask with the likely titles as choices and open the one picked; ask again if needed; never guess.

**Tests:**
- `scripts/editor-navigation-test.php`:
  - offline: the exact title, one partial match, several, none;
  - real: a partial title opens; two matches ask, then open the one picked; none asks; a new page opens directly.
- `scripts/ai-agent-chat-test.php`: the new draft's action opens in place.
- The real runs of both suites pass, and all 20 suites pass offline. One check, "…a Global Styles revision", failed once offline and passed on both reruns. It is unrelated: WordPress may skip a revision whose content is unchanged.

**Not checked in a browser:** as above. Add "create a page" to the browser steps: the new draft opens in the canvas.
