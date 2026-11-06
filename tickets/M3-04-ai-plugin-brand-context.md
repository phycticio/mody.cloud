# M3-04 · Brand context in the `ai` plugin's features

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 ("The `ai` plugin's features on member sites"), decision 13 · **Status:** done (2026-11-06)

## Goal
The `ai` plugin's writing features (titles, excerpts, …) write in the site's brand, like the Mody agent does (rule 4).

Split from M3-01 on 2026-11-06.

## Decisions (maintainer, 2026-11-06)
- **Which features:** the eight writing ones: `ai/title-generation`, `ai/excerpt-generation`, `ai/meta-description`, `ai/content-resizing`, `ai/type-ahead`, `ai/suggest-reply`, `ai/editorial-updates`, `ai/image-prompt-generation`.
  - Left out: summaries (the plugin asks for neutral, non-stylistic text), translation (it keeps the source's voice), and the analysis features (alt text, slugs, block labels, comment analysis, classification, editorial notes, revision summaries).
  - Image generation, image import and speech have no system instruction.
- **Sites without a brand profile:** unchanged, with no brand context. Whether they should refuse, as the agent does, moves to M4-01.

## Deliverables
- [x] `src/AI/PluginBrandContext.php`, hooked in `mody-platform.php` on `wpai_system_instruction` (the plugin applies it in `Abstract_Ability::get_system_instruction()`). For a feature in `FEATURES`, on a site with a brand profile, it appends `SystemPrompt::for_ai_feature()`:
  - one sentence: the feature's own rules come first (format, length, language), and the brand's tone applies where they leave the tone open;
  - then `SystemPrompt::brand_context()`.
  - Anything else is returned unchanged: other features, an empty instruction, other values. The plugin's source is not edited.
- [x] `scripts/ai-plugin-brand-context-test.php`:
  - the filter, with and without a profile;
  - every `ai/` feature the plugin registers, with its real instruction (a feature the test does not know fails it);
  - title generation with a fake model answer through `pre_http_request`, checking the request's system message;
  - one real title generation.
- [x] Docs: ai-engine.md ("The `ai` plugin's features", testing step 5), mvp-spec §8 and decision 13, AGENTS.md, roadmap.md, the M4-01 ticket.

## Acceptance criteria
- [x] With a brand profile, every request of the eight writing features carries the brand context in its system instruction. The test checks each feature's real instruction, plus a title generation request.
- [x] Without one, the request is unchanged. So are the other features' requests.

## Results (2026-11-06, local)
- New test: 37 checks pass with the real call (35 offline); one part skipped (the run on `alpha`, see below).
- Existing tests still pass: `ai-agent-chat-test.php` (offline), `spectra-guard-test.php` and `ai-generate-page-test.php`.
- A real title for the fixture profile ("Ogia Bakery", friendly): "Fresh Sourdough, Delivered to Your Zurich Office", in 3.5 s.
- The professional and casual suggested replies to the same comment read clearly differently, so the requested tone wins over the brand's.
- **Found:** the plugin's feature toggles and connector approvals are options of each site. On `alpha` no feature is on and nothing is approved, so the test's run there, under `switch_to_blog()`, fails with "unsupported model" and is skipped. M4-01 now lists setting them on member sites.
- **Production** (deployed 2026-11-06, commit `1f6bd44`, after a backup in `backups/2026-11-06-pre-m3-04/`): the new test passes in full there, the real title generation included ("Fresh Sourdough at Your Zurich Desk Before 9am", with a fixture profile removed afterwards). The offline agent test and the Spectra test pass too. The previous handoff said the plugin's features were off in production; they are not: the plugin (1.5.0) is active on mody.cloud with every feature on, and `ai/ai.php` is approved for `infomaniak`. mody.cloud has no brand profile, so its features run without brand context for now.
- **Note for cost tracking:** test runs land in the plugin's request log (`wp_wpai_request_logs`) like real requests. A fake answer is logged with the requested model, so test rows cannot be told apart.
