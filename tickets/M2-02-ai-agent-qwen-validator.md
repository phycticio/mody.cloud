# M2-02 · AI agent core: Qwen via Infomaniak + block validator

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §8, §12 · **Status:** todo

## Goal
A Python agent service in `agent/` calls the model `Qwen/Qwen3.8-27B-FP8` through Infomaniak AI Services (OpenAI-compatible), assembles the system prompt from a site's brand profile, and returns **validated** serialized Gutenberg blocks.

## Deliverables
- **`agent/`** at the repo root (new): Python 3.11-slim, `requests` only. `agent/Dockerfile`. New compose service, **no published ports**, on the internal network.
- Env (never committed): `MODY_AI_BASE_URL` (`https://api.infomaniak.com/2/ai/{PRODUCT_ID}/openai/v1`), `MODY_AI_MODEL`, `MODY_AI_TOKEN`, `MODY_INTERNAL_SECRET`, `WORDPRESS_INTERNAL_URL`.
- **System prompt assembly** from the `mody_brand_profiles` row (spec §8, §12): name, mission, tone, palette (named slugs), logo. **No code path calls the model without a brand profile** (constraint 4).
- **Function calling** if supported, else a strict JSON action protocol (spec §8). Low temperature (0.1).
- **Block validator** (port of the pipeline in `ai-engine.md`): balanced `<!-- wp:* -->` comments, valid inner JSON, block names against the **live block registry** (not a copied list — resolves the Spectra `uagb/` open question), palette slugs only (reject loose hex). Retry loop (2 attempts) then failure.
- Internal **tool endpoint** contract the PHP side calls (initially just `generate_page`): authenticates with `MODY_INTERNAL_SECRET` via `hash_equals`, non-public network only.

## Acceptance criteria
- A `generate_page` request returns a validated, balanced, whitelisted block payload for a sample brand profile.
- A deliberately broken model response is rejected and retried, then reported — never inserted.
- There is no reachable path that calls the model without a brand profile.
- **Verify against Infomaniak:** exact model slug + product ID + function-calling support via `GET /1/ai/models` (spec §16.4). Record the confirmed values in `documentation/ai-engine.md`.

## Open / blocking
- Infomaniak AI product ID + token must be provisioned (spec §16.4).
