# M2-02 · AI agent core: Qwen via the WordPress AI Client + block validator

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §8, §12, documentation/ai-engine.md · **Status:** in progress (provider done 2026-11-04)

## Goal
The MU plugin generates pages with `Qwen/Qwen3.8-27B-FP8` through Infomaniak AI Services, using the WordPress AI Client in core. It assembles the system prompt from a site's brand profile and returns **validated** serialized Gutenberg blocks.

Decided 2026-11-04: this replaces the Python agent service (`agent/`, internal endpoint, `MODY_INTERNAL_SECRET`) first planned for this ticket.

## Done
- **Infomaniak provider** for the WordPress AI Client: `app/web/mu-plugins/mody-platform/src/AI/` (provider, model directory, text model), registered on `init` only when `INFOMANIAK_AI_BASE_URL` is set.
- Env (never committed): `INFOMANIAK_API_KEY` (read by core), `INFOMANIAK_AI_BASE_URL`, optional `INFOMANIAK_AI_MODEL`.
- Verified against Infomaniak through `wp_ai_client_prompt()`: model slug, base URL, plain completion, **function calling**, JSON-schema output, and valid block markup. Ministral fails the block test. Results in `documentation/ai-engine.md`.

## Deliverables (remaining)
- **System prompt assembly** from the `mody_brand_profiles` row (spec §8, §12): name, mission, tone, palette (named slugs), logo. **No code path calls the model without a brand profile** (constraint 4): one helper builds every generation prompt.
- **Generation helper** `generate_page( $site_id, $prompt )`: thinking off (`chat_template_kwargs.enable_thinking = false`), temperature 0.1, few-shot examples.
- **Block validator** in PHP (ai-engine.md, "Validation pipeline"): `parse_blocks()` plus a `serialize_blocks()` round trip, block names against the whitelist and the live `WP_Block_Type_Registry` (resolves the Spectra `uagb/` open question), palette slugs only (reject loose hex). Retry loop (2 attempts), then failure.
- Errors from the AI Client arrive as `WP_Error`. Log them, and never insert partial output.

## Acceptance criteria
- `generate_page` returns a validated, balanced, whitelisted block payload for a sample brand profile.
- A deliberately broken model response is rejected and retried, then reported. It is never inserted.
- There is no reachable path that calls the model without a brand profile.

## Open
- Infomaniak lists `Qwen/Qwen3.8-27B-FP8` as `coming_soon`, although it answers. Confirm it is supported for production use, and its price.
- Escalation to a larger model before giving up: undecided (ai-engine.md).
