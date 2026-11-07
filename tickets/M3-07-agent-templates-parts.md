# M3-07 · The agent edits templates and template parts

**Milestone:** M3 (follow-up) · **Spec:** documentation/mvp-spec.md §8, §9 · **Status:** done (2026-11-07; the browser walk-through is the maintainer's)

Third of three tickets (after [M3-05](M3-05-agent-editor-bubble.md) and [M3-06](M3-06-agent-edit-current-content.md)). Asked for by the maintainer on 2026-11-07.

## Goal
In the Site Editor, the bubble's agent can also change the template or template part that is open: "put the logo on the right", "add a footer with our address".

## How it is built
- **The context** (`Agent\EditorContext`) accepts `wp_template` and `wp_template_part`.
  - Their IDs are strings (`theme//header`), checked against `get_block_template()`.
  - The user needs `edit_theme_options` (403 otherwise); a malformed or unknown ID gets 400.
  - The title and, for a part, its area (header, footer) are kept. The bubble compares IDs as strings when it applies an edit.
- **The agent** (`SystemPrompt::for_editor()`) is told it is the site's layout: every page that uses it shows a change, and its blocks can be moved, added or removed only by calling `edit_content`. Probed (5 runs each):
  - without the second sentence, "add the tagline under the site title" in the header was declined 2 times in 3;
  - with a wording that also said "say so after an edit", the model answered "Done" with no call 4 times in 5;
  - with the built wording, 10 of 10 called `edit_content` (header and page template), "change the tagline to …" still picked `update-site-settings` (5 of 5), and "create an About page" `generate-page` (5 of 5).
- **The writer** (`SystemPrompt::for_edit( …, true )`) gets two more rules: the theme blocks may be used, and theme blocks carry the site's own data (the site title, tagline, logo and navigation, never typed text); keep template parts, post content and query loops, and never remove post content.
- **The validator's template mode** (`BlockValidator::validate( $markup, $original, true )`):
  - it also allows `TEMPLATE_BLOCKS`: template-part, post-content, post-title, post-date, post-excerpt, post-featured-image, post-terms, the query loop's blocks, query-title, term-description, search, navigation links and submenus, page-list;
  - it refuses an edit that drops a `post-content` or `query` block the template had.
  - Every template and part of the theme passes it.
- Undo and save work as in M3-06: the change is applied in the editor and saved only with Save, and the Site Editor keeps template revisions.

## Acceptance criteria
- [x] "Add our address to the footer" changes the footer part in the editor, validated, undoable. Real: "add a short line at the bottom of the footer" added it, with the site title kept (3.3 s).
- [x] A change that would remove the page content from a template is refused, with the reason shown. A writer that drops `post-content` is refused 3 times, and the agent is told "Keep the core/post-content block…".

## Results (2026-11-07, local)
- `scripts/agent-template-test.php`: all checks pass offline and with the real requests.
  - **The validator:** every theme template and part passes the template mode; page mode refuses the page template; dropping post-content or the query loop is refused; a block outside both lists is refused.
  - **`EditorContext`:** the header part (ID, title, area), the page template, four bad IDs (400), an editor without `edit_theme_options` (403).
  - **The agent with fake answers:** the header edit carries `theme//header` and a whole-content edit; the agent's and the writer's prompts carry the template wording.
  - **Real runs:**
    - header: the tagline under the site title, with the logo, title and menu kept (2.3 s);
    - footer: a line added (3.3 s);
    - page template: the title removed, the content kept (1.8 s).
- `agent-edit-test.php` (real) and every other suite pass.
- **Not checked by the tests:** applying the edit in a real Site Editor. Open the header part in the Site Editor (Patterns → Header, or a template), open the bubble, and ask "add the tagline under the site title".

## Note
- **Editing a page shows its template around it:** blocks of the template there (the header, say) count as no selection, as in M3-06. To change the header, open the header part or a template.
