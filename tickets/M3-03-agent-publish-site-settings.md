# M3-03 · Agent: publish a draft, and the site title and tagline

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
Pages are published **on demand** (decided by the maintainer on 2026-11-06): when the member asks the agent ("publish the about page"), or with the Publish button in the Site Editor. The agent never publishes by itself. The agent can also change the site title and tagline.

Split from M3-01 on 2026-11-06.

## Deliverables
- **Ability `mody/publish-page`:**
  - Input: `page_id`. Publishes a draft (or pending) page of this site, as it is now.
  - Permission: `publish_pages`, and `edit_post` on that page.
  - Refuses anything that is not a page of this site, and pages already published or in the trash.
  - Publishes hand-edited drafts as they are: the member asked for it, and the Site Editor's Publish button does not validate either. No `BlockValidator` run here (it guards generated content, which `mody/generate-page` already validated).
  - Returns the page URL and its Site Editor URL (shown as buttons).
- **On demand only** in `SystemPrompt::for_agent()`:
  - The agent calls `mody/publish-page` only when the member asks to publish. It never does so in the same turn as a generation, unless the member's message asked for both.
  - After creating a draft, the reply says the member can publish it by asking or with the Publish button. The agent does not ask "shall I publish it?".
  - When it is unclear which page to publish, it asks with `ask_user`. `mody/open-editor`'s page lookup (ID or title) shows how to resolve a page.
- **Ability `mody/update-site-settings`:** `title?` and `tagline?` only (`blogname`, `blogdescription`), sanitized, at most 120 and 200 characters. Permission: `manage_options`. Returns the old and new values.
- Offer both abilities in `Agent::ABILITIES`. Tests in `scripts/ai-agent-chat-test.php` (fake answers plus one real run). Docs: ai-engine.md, mvp-spec §8.

## Acceptance criteria (spec §8)
- "Create an about page" → validated draft, not published. "Publish the about page" → published, every step logged.
- A draft edited by hand in the Site Editor is published as it is when the member asks.
- "Change the tagline to …" → tagline updated, event logged. No other option can be written.
