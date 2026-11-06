# M3-03 · Agent: publish a draft, and the site title and tagline

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** done (2026-11-06)

## Goal
Pages are published **on demand** (decided by the maintainer on 2026-11-06): when the member asks the agent ("publish the about page"), or with the Publish button in the Site Editor. The agent never publishes by itself. The agent can also change the site title and tagline.

Split from M3-01 on 2026-11-06.

## Deliverables
- [x] **Ability `mody/publish-page`** (`src/Site/PagePublisher.php`):
  - Input: `page_id`, or `page_title` (exact, case-insensitive, the most recently changed draft first). Publishes a draft (or pending) page of this site, as it is now.
  - Permission: `publish_pages`, and `edit_post` on that page.
  - Refuses anything that is not a page of this site, and pages already published, private, scheduled or in the trash. An unknown page's error lists the site's drafts.
  - Publishes hand-edited drafts as they are: the member asked for it, and the Site Editor's Publish button does not validate either. No `BlockValidator` run here (it guards generated content, which `mody/generate-page` already validated).
  - Returns the page and two buttons: View (the page's URL) and the Site Editor.
- [x] **On demand only** in `SystemPrompt::for_agent()` (rules 3 and 8):
  - The agent calls `mody/publish-page` only when the member asks to publish. It never does so in the same turn as a generation, unless the member's message asked for both.
  - After creating a draft, the reply gives its title and says the member can publish it by asking or with the Publish button. The agent does not ask "shall I publish it?".
  - When it is unclear which page to publish, or the tool cannot find it, it asks with `ask_user`. The page lookup is shared with `mody/open-editor` (`SiteEditor::find_page()`).
- [x] **Ability `mody/update-site-settings`** (`src/Site/SiteSettings.php`): `title?` and `tagline?` only (`blogname`, `blogdescription`), sanitized, at most 120 and 200 characters. Permission: `manage_options`. Returns the old and new values.
- [x] Both abilities in `Agent::ABILITIES`, with one-sentence declarations (tools JSON: 3,782 bytes). `Agent::take_link()` turns `url` and `edit_url` into buttons, front-end URLs under `home_url()` included.
- [x] Tests in `scripts/ai-agent-chat-test.php` (fake answers plus real runs). Docs: ai-engine.md, mvp-spec §8, AGENTS.md, roadmap.md.

## Found while building it
- **"Done" without a tool call.** The conversation sent the model only the text of earlier replies. "Create a page about our sourdough", then "Publish it", got "Done, "Our Sourdough" is now live." with no call, 10 times in 10. Prompt changes alone reached 5 or 6 in 10. Fix: each assistant message is stored with the abilities it ran (server-side only, never sent to the browser), and the next turns send them to the model as tool calls with their results. After that, "Publish it" was 10 in 10 `{"page_id": N}`, and a third message on that history was 20 in 20 correct. See ai-engine.md, "Publishing and site settings".
- **A model error after a tool ran** now says that part of the request may already be done, so the member checks before retrying and does not get a second page. Buttons are shown once each in that case too. Seen for real once: "create a contact page and publish it" did both, then the last model request timed out while connecting (cURL error 28).
- **Rate limit window:** `x-ratelimit-remaining` went from 34 back to 45 within about four minutes. Inference: a one-minute window.

## Acceptance criteria (spec §8)
- [x] "Create an about page" → validated draft, not published. "Publish the about page" → published, every step logged. Five real runs passed (the last two with "Publish it" over HTTP, using the stored conversation).
- [x] A draft edited by hand in the Site Editor is published as it is when the member asks (offline test, through kses, with content the validator refuses).
- [x] "Change the tagline to …" → tagline updated, event logged. No other option can be written (schema allowlist, tested with `admin_email`, `blogname` and `siteurl`).

## Found after (2026-11-06)
- **A failed publish led to the wrong page.** In the maintainer's manual test, "Publish it" targeted a draft deleted meanwhile (page 259). After the "not found" error, the model published an older "About" draft (page 29) without asking: 5 times in 5 when replayed. Fixed in `Agent::handle()`: after a failed `mody/publish-page` (other than a schema error), no other publish runs in that turn, and the error tells the model to ask. Replayed: it asked 5 times in 5, nothing published. Test: "page gone" in `scripts/ai-agent-chat-test.php`. Details: ai-engine.md, "Publishing and site settings".
