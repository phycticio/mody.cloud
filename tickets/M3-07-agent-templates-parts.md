# M3-07 · The agent edits templates and template parts

**Milestone:** M3 (follow-up) · **Spec:** documentation/mvp-spec.md §8, §9 · **Status:** todo

Third of three tickets (after [M3-05](M3-05-agent-editor-bubble.md) and [M3-06](M3-06-agent-edit-current-content.md)). Asked for by the maintainer on 2026-11-07.

## Goal
In the Site Editor, the bubble's agent can also change the template or template part that is open: "put the logo on the right", "add a footer with our address".

## Deliverables
- **The validator for theme blocks:** templates and parts contain blocks the page writer never uses: `template-part`, `post-content`, `post-title`, `query` and its inner blocks, `navigation`, `site-title`, `site-logo`, `site-tagline`. `BlockValidator` gets a template mode with that whitelist, and the rule that a template must keep its `post-content` (or `query`) block, so the site's pages still show.
- **The context:** `wp_template` and `wp_template_part` from the Site Editor, with their slug and area (header, footer).
- **A prompt for templates:** what each theme block does, and what must stay (the header's site title, logo and navigation unless asked).
- **Undo and save as in M3-06:** the change is applied in the editor and saved only with Save. The Site Editor keeps template revisions, so a saved change can also be reverted there.
- Tests and real probes, as in M3-06.

## Acceptance criteria
- "Add our address to the footer" changes the footer part in the editor, validated, undoable.
- A change that would remove the page content from a template is refused, with the reason shown.

## Risk
A broken template breaks every page that uses it. The validator's template rules and the editor's undo and revisions are the safety nets; keep the first version to small, local changes.
