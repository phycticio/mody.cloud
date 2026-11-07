# M3-05 · The agent as a chat bubble in the editor

**Milestone:** M3 (follow-up) · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

Asked for by the maintainer on 2026-11-07, while deciding where the `Mody Agent` menu should appear (M4-01). First of three tickets: M3-05 (this), [M3-06](M3-06-agent-edit-current-content.md), [M3-07](M3-07-agent-templates-parts.md).

## Goal
The Mody agent lives where the member edits: a chat bubble at the bottom right of the Site Editor and the post editor. It works as the chat page does today: same endpoint, same tools, same conversation.

## Deliverables
- **The bubble:** a script loaded on `enqueue_block_editor_assets`, which fires in the Site Editor and the post editor (checked in WordPress 7.1). It shows a round button at the bottom right that opens a chat panel. Plain script, no build step, like `assets/agent-chat.js`; reuse its message rendering (`textContent` only, buttons for links and choices).
- **Same backend:** `mody-platform/v1/chat` (background jobs, polling, stored conversation). The panel shows the stored conversation, so a member moving between the chat page and the editor keeps one thread.
- **Who sees it:** users with `Agent::CAPABILITY` on sites with a brand profile. Without a profile, the bubble says so, as the page's banner does.
- **The `Mody Agent` wp-admin page** (decided 2026-11-07): on member sites, the bubble replaces it (the menu item goes); on the main site, super admins keep it.
- Buttons from the agent (open the editor, view a page) keep working from inside the editor. "Open the editor" for the item already open does nothing.
- Nothing loads from outside the network (rule 1); the bubble does not cover the editor's own controls (Save, the block inspector).

## Acceptance criteria
- In the Site Editor and the post editor of a member site, the bubble opens a working agent chat: create a page, change the colors, publish.
- The conversation is the same as on the chat page.
- On member sites the wp-admin page is gone; on the main site super admins still have it.

## Open
- Whether the bubble also appears on other wp-admin screens (the dashboard, the Pages list). Start with the editors.
