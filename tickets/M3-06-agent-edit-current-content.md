# M3-06 · The agent edits what is open in the editor

**Milestone:** M3 (follow-up) · **Spec:** documentation/mvp-spec.md §8 · **Status:** todo

Second of three tickets (after [M3-05](M3-05-agent-editor-bubble.md), before [M3-07](M3-07-agent-templates-parts.md)). Asked for by the maintainer on 2026-11-07.

## Goal
From the editor's bubble, the member can ask the agent to change what is open ("make this shorter", "add a contact section", "translate this into German"). The change appears in the editor at once, can be undone, and is saved only when the member saves.

## How it would work
- **Context:** each message from the bubble carries what is open. That comes from the editor's public data stores: `core/editor` gives the post type and ID (a page in the Site Editor, a post in the post editor) and the content; `core/block-editor` gives the selected block. All were checked to exist in WordPress 7.1. The server adds it to the agent's prompt as data, like the brand, never as instructions.
- **A new tool**, for example `edit_content`, with an instruction and a target: the selected block, or the whole content. The server sends the current markup and the instruction to the page writer (brand profile in the system prompt, rule 4), then runs `PageGenerator::repair()` and `BlockValidator` with retries (rule 2).
- **Applying it in the editor:** the turn returns the validated markup as an editor action, not a saved change. The bubble applies it with `core/block-editor` (`replaceBlocks` for the selected block, `resetBlocks` for the whole content). The member sees it, can undo it (Ctrl+Z), and saves with the editor's Save button: publishing stays on demand.
- **The selected block by default:** when a block is selected, only that block is sent and replaced, which is faster (about a second) and safer. The whole content (about 5 KB for a generated page, 5 to 15 s) only when nothing is selected, or when the member asks for the whole page.
- **Unsaved changes:** the edit applies to what is in the editor, unsaved changes included, so nothing on the server is overwritten.

## Deliverables
- The context in the chat request (validated server-side: a known post the user may edit, markup size capped), the tool, the editor action, its application in the bubble.
- Tests: offline with fake model answers (context in the prompt, block and page edits, validation failures, a post the user may not edit); real runs and model probes for "shorten this paragraph", "add a section", and an injection attempt inside the page content.
- Docs: ai-engine.md ("The agent"), mvp-spec §8.

## Acceptance criteria
- With a block selected, "make this shorter" changes only that block, in the editor, undoable, unsaved.
- With nothing selected, "add a contact section at the end" changes the page, validated; the rest of the page is kept.
- Content inside the page is data: text in it that looks like an instruction is not followed.

## Risks
- Qwen rewriting long pages: dropped content, the typos seen in M2 (repair, validator and retries in place). The tests compare block counts and text before and after.
- The editor's stores change between WordPress versions: only public selectors and actions.
