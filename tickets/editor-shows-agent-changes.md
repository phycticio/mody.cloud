# The editors show the agent's style and settings changes at once

**Milestone:** agent follow-up (M3) · **Spec:** documentation/ai-engine.md ("Where the agent appears") · **Status:** done (2026-11-09): part 1, the editors show the change; part 2, the owner decides when their unsaved changes are involved

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

## Part 2: the owner decides (built 2026-11-09)
The open question was whether the agent should refuse, or warn before, changing styles or settings the owner has unsaved changes to. The maintainer answered on 2026-11-09:

> make the user decide when sensitive changes are being done.

They chose the scope and the choices:
- **Scope:** only conflicts. The agent asks only when its change touches styles or site settings with unsaved changes in the editor. Everything else still runs at once (colors, publishing), as decided on 2026-11-08.
- **Choices:** save my changes then apply, discard my changes then apply, or cancel. The change the agent planned waits, as it was made, and runs without a second model call.

### Built
- **`assets/agent-bubble.js`:**
  - `config.unsaved()` lists the records with unsaved changes (`styles`, `site`). `agent-chat.js` sends them with each message.
  - `config.settle()` saves those changes (`saveEditedEntityRecord`) or discards them (each edit set back to its saved value, which core-data drops).
- **`Agent`:**
  - `hold()`: a call to `mody/update-design`, `mody/update-styles` or `mody/update-site-settings` whose record is in `unsaved` does not run. It is checked like `execute()` would check it (input, permissions); an invalid one goes back to the model as an error. Then it is kept in the user option `mody_agent_held_change` (per site, at most 30 minutes), and the turn ends with the question (`describe()` names the change) and `decision` (`id`, `record`). The model gets `held: true`, and the conversation keeps that.
  - `decide()` runs the held call as it was made, or drops it.
  - `decision_labels()` holds the three labels.
- **`ChatController`:**
  - `/chat` accepts `unsaved` (only `styles` and `site`, otherwise 400).
  - A new message, or a new conversation, drops a held change.
  - `POST /chat/decision` (`id`, `choice`: `save`, `discard` or `cancel`) answers 410 when nothing waits under that ID. It keeps the choice and the result in the conversation.
- **`assets/agent-chat.js`:** the three buttons under the question (`decide()`): the editor saves or discards first, then the server runs the change, and the turn goes to the bubble, which opens the screen and reloads the records. If the save fails, nothing runs and the reply says so.
- **The note from part 1 stays** for changes the owner makes while the agent works.

### Results (2026-11-09)
- **`scripts/ai-agent-chat-test.php` (REST section), all offline, 142 checks passing:**
  - Unsaved styles hold a color change: nothing is saved, it takes one model request, the owner is asked with the change named, and it is logged as held and stored for the model.
  - A decision with another ID gets 410.
  - "save" runs the held change once, with the refresh and the screen, and the conversation keeps the choice and the call.
  - "cancel" changes nothing.
  - A new message drops the held change.
  - Unsaved site settings don't hold a color change.
  - An invalid color is not held.
  - An unknown `unsaved` name gets 400.
- **Browser, the real agent in the Site Editor on a local member site:**
  - **Save:** with an unsaved font size, "Set the primary color to green" was held (the server unchanged), with the three buttons. "Save my changes, then apply" saved the font size, then made the primary `#008000`. Both are kept, nothing is unsaved, and Styles → Colors shows it.
  - **Cancel:** with another unsaved font size, "dark blue" was held. Cancel changed nothing, and the unsaved edit stayed.
  - **Discard:** on Identity, with an unsaved tagline, 'Change the tagline to "A modern website builder"' was held. "Discard my changes, then apply" dropped the unsaved tagline and set the new one, on the screen and in the canvas.
  - No errors.
- **Other suites, offline:** `agent-bubble`, `editor-navigation`, `brand-settings`, `agent-edit` and `agent-template` pass.
- **`site-styles`:** its revision-count check fails when it runs right after the browser checks changed that site's styles without a revision. Once the site was restored, it passed twice.
