# M3-04 · Brand context in the `ai` plugin's features

**Milestone:** M3 · **Spec:** documentation/mvp-spec.md §8 ("The `ai` plugin's features on member sites"), decision 13 · **Status:** todo

## Goal
The `ai` plugin's features (titles, excerpts, alt text, …) write in the site's brand, like the Mody agent does (rule 4).

Split from M3-01 on 2026-11-06.

## Deliverables
- A `wpai_system_instruction` filter in the MU plugin. The plugin applies it in `Abstract_Ability` with `( $instruction, $ability_name, $data )`. On sites with a brand profile, it appends `SystemPrompt::brand_context()` to the instruction.
- Sites without a profile are left as they are. **Open question:** whether the plugin's features should refuse on member sites without a profile, as the agent does.
- Test: a script, or a section of an existing one, that runs one feature (title generation) with a fake model answer through `pre_http_request` and checks that the brand context is in the system message. Then one real call.
- Docs: ai-engine.md, mvp-spec §8.

## Acceptance criteria
- With a brand profile, every `ai` feature request carries the brand context in its system instruction.
- Without one, the request is unchanged.
