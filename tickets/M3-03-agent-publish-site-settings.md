# M3-03 · Agent: publish a draft, and the site title and tagline

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

## Goal
"Create an about page" ends with a **published** page, after the member has confirmed the draft. The agent can also change the site title and tagline.

Split from M3-01 on 2026-11-06.

## Deliverables
- **Ability `mody/publish-page`:**
  - Input: `page_id`. Publishes a draft page of this site.
  - Permission: `publish_pages`, and `edit_post` on that page.
  - Refuses anything that is not a draft page, and pages whose content does not pass `BlockValidator`. A member may have edited the draft by hand in the meantime; **Open question:** refuse in that case, or publish anyway because the editor saved it.
  - Returns the page URL and its Site Editor URL (shown as buttons).
- **Confirmation flow** in `SystemPrompt::for_agent()`: after creating a draft, the agent offers to publish it. It publishes only after an explicit yes in a later message, asked with `ask_user` ("Publish it" / "Not yet"). Never in the same turn as the generation.
- **Ability `mody/update-site-settings`:** `title?` and `tagline?` only (`blogname`, `blogdescription`), sanitized, at most 120 and 200 characters. Permission: `manage_options`. Returns the old and new values.
- Offer both abilities in `Agent::ABILITIES`. Tests in `scripts/ai-agent-chat-test.php` (fake answers plus one real run). Docs: ai-engine.md, mvp-spec §8.

## Acceptance criteria (spec §8)
- "Create an about page" → validated draft → the member confirms → page published, every step logged.
- "Change the tagline to …" → tagline updated, event logged. No other option can be written.
