# M2-02 · AI agent core: Qwen via the WordPress AI Client + block validator

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §8, §12, documentation/ai-engine.md · **Status:** done (2026-11-06; provider 2026-11-04)

## Goal
The MU plugin generates pages with `Qwen/Qwen3.8-27B-FP8` through Infomaniak AI Services, using the WordPress AI Client in core. It assembles the system prompt from a site's brand profile and returns **validated** serialized Gutenberg blocks.

Decided 2026-11-04: this replaces the Python agent service (`agent/`, internal endpoint, `MODY_INTERNAL_SECRET`) first planned for this ticket. Decided 2026-11-06 ("Option C", mvp-spec decision 13): agent actions are WordPress abilities, so generation is the ability `mody/generate-page`, and it saves a **draft** page.

## Done
- **Infomaniak provider** for the WordPress AI Client: `app/web/mu-plugins/mody-platform/src/AI/` (provider, model directory, text model), registered on `init` only when `INFOMANIAK_AI_BASE_URL` is set.
- Env (never committed): `INFOMANIAK_API_KEY` (read by core), `INFOMANIAK_AI_BASE_URL`, optional `INFOMANIAK_AI_MODEL`.
- Verified against Infomaniak through `wp_ai_client_prompt()`: model slug, base URL, plain completion, **function calling**, JSON-schema output, and valid block markup. Ministral fails the block test. Results in `documentation/ai-engine.md`.
- **Brand profile table** `mody_brand_profiles` (spec §11), created by the MU plugin on `init` (`src/Brand/BrandProfile.php`). The loader sanitizes every field again, because the fields end up in a prompt.
- **System prompt assembly** (`src/AI/SystemPrompt.php`). `brand_context()` is the one helper that turns a profile into prompt text, and every prompt must reuse it. `for_page()` adds the output rules and one few-shot example. Palette by slug only, member fields marked as data.
- **Generation** (`src/AI/PageGenerator.php`): `generate_page( $site_id, $prompt )` returns validated markup or `WP_Error`. Temperature 0.1, thinking off (provider default), 4,000 output tokens, 60-second timeout. It refuses without a brand profile (`mody_no_brand_profile`) before any request. 3 attempts in all, each retry told why the last answer was rejected, then `mody_generation_failed`. AI Client errors are returned at once. Failures go to the PHP error log, without prompts or tokens.
- **Block validator** (`src/AI/BlockValidator.php`). It checks:
  - the `parse_blocks()`/`serialize_blocks()` round trip, and no text outside blocks;
  - the whitelist plus the live `WP_Block_Type_Registry`;
  - each block's HTML: known tags, balanced, the right outer tag;
  - palette slugs only (attributes, inline styles, classes);
  - no media from outside the network.
- **Ability** `mody/generate-page` (`src/Abilities.php`, category `mody`): `create_draft()` saves a draft page and returns `page_id`, `title`, `status` and the Site Editor `edit_url`. Needs `edit_pages`. It is public, for REST, MCP and the agent, and anonymous calls get 401.
- **Tests:** `scripts/ai-generate-page-test.php` (39 checks) covers:
  - the validator against the few-shot example, 3 other valid samples and 20 broken ones;
  - the refusal without a profile, with no request sent;
  - the retry loop, with fake answers through `pre_http_request`;
  - nothing inserted on failure;
  - one real generation through the ability.

  `scripts/ai-smoke-test.php` still passes.

## Acceptance criteria
- [x] `generate_page` returns a validated, balanced, whitelisted block payload for a sample brand profile. Six real runs on 2026-11-06, in English, French and German, about 5 s each.
- [x] A deliberately broken model response is rejected and retried, then reported. It is never inserted.
- [x] There is no reachable path that calls the model without a brand profile. `generate_page()` is the only generation path. Later prompts (agent, `ai` plugin features) must use `SystemPrompt::brand_context()`.

## Notes for the next tickets
- **M2-01** calls `PageGenerator::generate_page()` in the new site's context, publishes the result as the front page, and falls back to the "Hello, I'm {brand}" page on `mody_generation_failed`.
- **M3-01** offers `mody/generate-page` to the model with `using_abilities()`, and adds the other abilities.
- **Spectra** (`spectra-blocks`, in Composer since 2026-11-06) registers `spectra/*` blocks. Choose which join the whitelist once it is active. Never `spectra/google-map` or the AI builder block (rule 1, ai-engine.md).

## Open
- Infomaniak lists `Qwen/Qwen3.8-27B-FP8` as `coming_soon`, although it answers. Confirm it is supported for production use, and its price.
- Escalation to a larger model before giving up: undecided (ai-engine.md).
- PHP cannot check that each block's HTML matches its editor save function exactly (ai-engine.md, "Validation pipeline").
