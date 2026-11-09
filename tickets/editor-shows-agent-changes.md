# The editors show the agent's style and settings changes at once

**Milestone:** agent follow-up (M3) · **Spec:** documentation/ai-engine.md ("Where the agent appears") · **Status:** done (2026-11-09)

Reported by the maintainer on 2026-11-09:

> Story: User tells Mody to change the color palette and set the primary color to green. The message opens the styles colors tab but the primary color keeps looking as it was. User refreshes page, then see changes.
>
> Expected: A user says change primary color to green, the color palette opens the primary color is green. User says change tagline to "A modern website builder" the tagline changes to "A modern website builder" without having to refresh the website.

## Acceptance criteria
- In the Site Editor, "set the primary color to green" opens Styles → Colors with the new green in the palette and on the canvas, without a reload.
- "Change the tagline to …" shows the new tagline on the Identity screen and in the canvas's Site Tagline block, without a reload.
- Nothing in the editor turns unsaved because of it.

## Cause
The abilities (`mody/update-design`, `mody/update-styles`, `mody/update-site-settings`) save on the server. The Site Editor and the post editor keep their own copy of the site's Global Styles (`root/globalStyles`) and settings (`root/site`) in core-data's store, loaded when the page opened, and nothing told them to load them again. Opening Styles → Colors in place (M3-09) showed that old copy. A reload showed the change because it loads everything again.

Reproduced on 2026-11-09 in a headless browser on a local member site: after the server change, the Styles screen and the canvas kept `#005f6b`, and Identity kept the old tagline, also after opening it in place.

## Fix
- **`Agent\Agent`:** each turn has `refresh`, the list of what it changed that an editor keeps a copy of. It holds `styles` after `mody/update-design` or `mody/update-styles`, and `site` after `mody/update-site-settings`, only when the ability succeeded (`Agent::REFRESH`).
- **`assets/agent-bubble.js`:** after the turn, `refresh()` loads those records again with core-data's own calls (`invalidateResolution`, then `getEntityRecord`). It covers the site's Global Styles record, `root/site`, and `root/__unstableBase` (the title in the Site Editor's sidebar). It only touches records the editor has already loaded. That's three GET requests.
- **Unsaved changes:** when the owner has unsaved changes on the same record, the editor still shows the saved record with those edits on top. Where they overlap, the owner's edits win, and Save would write them over the agent's change. In that case the bubble adds a note under the reply (`unsaved` string in `EditorBubble::settings()`).

## Results (2026-11-09)
- **Browser, the abilities run on the server, then the turn event:**
  - In the Site Editor, Styles → Colors and the canvas went from `#005f6b` to `#2e7d32`, and the footer's tagline block showed the new tagline. Identity, opened in place, showed it in its field. The editor still said "Saved".
  - In the post editor the canvas had the same stale copy, and it updates too.
  - With an unsaved style edit, the note appears.
- **Browser, the real agent in the Site Editor:**
  - "Change the color palette and set the primary color to green" opened Styles → Colors with `#2e8b57` in the palette and on the canvas (6 s).
  - 'Change the tagline to "A modern website builder"' changed the canvas's tagline (10 s).
  - No reload, no unsaved changes, no errors.
- **Tests:** `scripts/ai-agent-chat-test.php` checks `refresh` after a color change, a preset, a refused style change and a tagline change, and after a plain answer. The offline suites pass: `ai-agent-chat`, `agent-bubble`, `editor-navigation`, `site-styles` and `brand-settings`. One `site-styles` revision-count check failed once and passed on two reruns. It counts revisions on the same test site the browser checks had just changed.

## Open question
Should the agent refuse, or warn before, changing styles or settings the owner has unsaved changes to? The bubble could send that with the message. Today the note comes after the change.
