# Mody sees the whole open page, and changes long pages section by section

**Builds on:** [M3-06](M3-06-agent-edit-current-content.md) (the agent edits what is open) · **Status:** built and deployed on 2026-11-11. The review of the same day added limits: at most 30 sections per message, sections over 19.5 KB left for the owner to narrow down, classic HTML kept, and never an empty page.

## Asked for
By the maintainer on 2026-11-11, after every message on the main site's 56 KB landing page failed with "This page is too long for the assistant to change at once":

> What if I don't want the limit I would like the open page to be part of the content no matter the size of it.

## Decisions (the maintainer, 2026-11-11)
1. **No limit on what Mody sees.** Mody sees the whole open page. A long page is changed section by section, not rewritten at once.
2. **A change to every section** ("make the whole page more formal") runs section after section in the same message. The chat shows a spinner and which section is being changed.
3. **No note about images.** Mody knows every image by ID and alt text, and gets the full block when a change involves it.

## Why rewriting was the limit
The page is 7.4 KB of text and no images; its 56 KB are mostly block settings. The limit came from the writer returning the whole page:
- about 19,000 output tokens, over the 8,000-token cap;
- minutes per change;
- a risk of changing sections nobody asked about.

## Built
Details: [ai-engine.md, Long pages](../documentation/ai-engine.md#long-pages-section-by-section-2026-11-11).
- **`AI\PageOutline`:** the compact view (about 9.5 KB for the landing page), the sections, and putting them back.
- **The agent gets the view** for every page or post open in the editor (rule 12). `edit_content` takes `sections`.
- **`Agent::edit_sections()` and `PageGenerator::edit_section()`:** each section with the whole page as context. A section can be changed, kept, followed by a new one, or removed.
- **Progress:** `Agent::on_progress()`, the job's `progress` and its sign of life, then a spinner and the line in `agent-chat.js` and `agent-chat.css`.
- **Templates and parts over 20 KB** are still changed only through a selected block.

## Results
- `scripts/agent-edit-test.php`: offline, every check passes.
- **On the real landing page:**
  - a question about it was answered from the view, with no tool call;
  - "shorten the FAQ" rewrote section 8 only, in 15 s;
  - "make the whole page more formal" went through nine sections in 50 s one after another, every result valid; about 30 s three at a time since [agent-performance.md](agent-performance.md).
- **In headless Chromium, in the Site Editor:**
  - the spinner and "Changing section 1 (1 of 2): …" while it worked (now "Changing 2 sections, 2 at a time…" and "1 of 2 sections done…");
  - the change in the editor with Undo, unsaved.
