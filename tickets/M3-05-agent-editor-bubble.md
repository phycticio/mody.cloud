# M3-05 · The agent as a chat bubble in the editor

**Milestone:** M3 (follow-up) · **Spec:** documentation/mvp-spec.md §8 · **Status:** done (2026-11-07; the browser walk-through is the maintainer's)

Asked for by the maintainer on 2026-11-07, while deciding where the `Mody Agent` menu should appear (M4-01). First of three tickets: M3-05 (this), [M3-06](M3-06-agent-edit-current-content.md), [M3-07](M3-07-agent-templates-parts.md).

## Goal
The Mody agent lives where the member edits: a chat bubble at the bottom right of the Site Editor and the post editor. It works as the chat page does today: same endpoint, same tools, same conversation.

## How it is built
- **`Agent\EditorBubble`** (`src/Agent/EditorBubble.php`), on `enqueue_block_editor_assets`.
  - It loads only on the Site Editor (`site-editor`) and the post editor (`post`) screens, for users with `Agent::CAPABILITY` (`edit_pages`).
  - Its settings are the chat page's (`ChatPage::settings()`: endpoint, nonce, stored conversation, a running job, texts), plus the chips, a brand-profile flag and the bubble's texts.
- **`assets/agent-bubble.js`** (plain script, no build step) builds a round button at the bottom right, and a panel with the chat page's elements (`#mody-agent-log`, `#mody-agent-form`, `#mody-agent-input`, `#mody-agent-reset`, the chips).
  - **`assets/agent-chat.js`**, the chat page's own script, loads right after it and runs the conversation in them. So the endpoint, background jobs, polling, stored conversation and buttons are the page's.
  - The only change to `agent-chat.js`: it focuses its input only when visible, so a closed bubble never takes the focus from the editor.
  - The panel closes with × or Escape. Escape is not passed on to the editor. The panel reopens after a reload if it was open (`sessionStorage`), or if the agent is still working.
  - Without a brand profile, the panel shows the same notice as the page.
  - The panel sits above the editor's panels and below its modals. It is outside the editor's own elements, so the editor's keyboard shortcuts (undo and so on) do not fire while typing in it.
- **`assets/agent-bubble.css`** fits the chat's styles to a 380 × 560 panel.
- **The `Mody Agent` wp-admin page** (decided 2026-11-07, `ChatPage::shows_menu()`): gone on member sites, super admins too, because the bubble replaces it. On the main site of a network, super admins only.
- Buttons from the agent open in a new tab, as on the page.

## Acceptance criteria
- [x] In the Site Editor and the post editor of a member site, the bubble is there, with the chat page's endpoint and conversation (tested over HTTP: the editors carry the bubble's script and settings). **To check in a browser:** creating a page, changing the colors and publishing from the bubble.
- [x] The conversation is the same as on the chat page (same settings, same endpoint, same stored conversation).
- [x] On member sites the wp-admin page is gone (403 over HTTP); on the main site super admins still have it.

## Results (2026-11-07, local)
- `scripts/agent-bubble-test.php`:
  - who gets the page (four cases);
  - the bubble's settings (the page's plus its own, the profile flag on a site with and one without a profile);
  - where it loads (Site Editor, post editor, not the dashboard, not for a subscriber; the chat script after the bubble);
  - over HTTP: the Site Editor on the main site, the Site Editor and the post editor on a member site (through the `Host` header), not the dashboard, and the page refused on the member site (403).
- The agent, gating, wizard and Spectra tests pass.
- **Not checked by the tests:** the bubble in a real browser (the panel, typing, a conversation). The scripts were checked with `node --check`.

## Open
- Whether the bubble also appears on other wp-admin screens (the dashboard, the Pages list). For now only the editors.
- Editing what is open: built in [M3-06](M3-06-agent-edit-current-content.md) (2026-11-07).
