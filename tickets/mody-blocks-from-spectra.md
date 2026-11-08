# Mody blocks: a clean block library cloned from Spectra

**Milestone:** not assigned yet · **Spec:** documentation/mvp-spec.md §10, documentation/ai-engine.md ("Initial block whitelist"), AGENTS.md (JS build) · **Status:** todo (created 2026-11-06)

## Goal
Our own block library, `mody/*`, forked from Spectra's GPL source, with every vendor service taken out: no telemetry, no AI builder, no upsells, no Google Fonts or Maps, no ZipWP. When it covers the blocks we use, existing content moves from `spectra/*` to `mody/*`, and Spectra and `SpectraGuard` are removed.

## Why
- **Rule 1 by construction, not by guard.** Spectra 1.0.13 bundles vendor libraries that contact Brainstorm Force, ZipWP and Google: analytics, an NPS survey, onboarding, ZipWP images and a design library with an AI block. Since 2026-11-06, `SpectraGuard` switches them off from outside the plugin (mvp-spec §10). But every Spectra update can add new ones, and the guard has to be rechecked each time (`scripts/spectra-guard-test.php`).
- **A codebase we own.** Colors from the theme's palette slugs only, our text domain, block names the validator and the agent know, and no vendor lock-in (rule 3).

## Source and license
- **Source:** [github.com/brainstormforce/wp-spectra-blocks](https://github.com/brainstormforce/wp-spectra-blocks). It is public, its default branch is `master`, and it has `src/blocks`, `src/components`, `src/extensions`, `src/helpers`, `src/hooks` and `src/styles`. The wordpress.org zip has only compiled code, so the fork has to start from this repository. Start from the commit matching the installed release (1.0.13) and record it in the fork's README.
- **License:** GPL-2.0-or-later (the plugin's readme; GitHub detects no license file). The fork is a derivative work. It stays GPL-2.0-or-later, keeps Brainstorm Force's copyright notices, and states that and when it was changed (GPL-2.0 §2a). This repository is GPL-2.0-or-later already (`package.json`).

## Scope
- **Blocks:** Spectra's top-level blocks, with their child blocks: `accordion`, `buttons`/`button`, `container`, `content`, `countdown`, `counter`, `icon`/`icons`, `list`, `modal`, `popup-builder`, `post`, `separator`, `slider` and `tabs`. **Never `google-map`.** Core already has buttons, list and separator, so those may not be worth porting.
- **Remove:**
  - every vendor library: BSF analytics, NPS survey, onboarding, design library and `spectra-ai`, ZipWP images, Astra notices;
  - the Zip AI notices, the Pro and video nudges, and the dashboard and Learn pages;
  - the Google Fonts font manager, and every remote URL.
- **Abilities:** drop Spectra's 51 `spectra-blocks/*` abilities (pinned off since 2026-11-06, mvp-spec §10). Write the few the agent needs as `mody/*` abilities ("Option C", mvp-spec decision 13).
- **Evaluate before porting:** Spectra's own CSS engine (GlobalStyles, JIT compiler, responsive controls). Prefer `theme.json` and core block supports where they cover the need (rule 5).

## Where it lives (proposal)
Consistent with the decisions already made (AGENTS.md):

- Sources in `src/blocks/<name>/`, built by `@wordpress/scripts` (`pnpm build`) into `app/dist/blocks/<name>/`. Everything is bundled, and nothing loads from a CDN.
- Registered by the MU plugin on every site with `register_block_type( <app/dist/blocks/name> )`. Namespace `mody/`, text domain `mody_cloud`.
- **Alternative:** a separate plugin repository, published as `mody/blocks` through SatisPress (`app/composer.json` already accepts `mody/*` from `plugins.mody.cloud`). Better if other projects reuse the blocks, but it adds a release step.
- **Dependencies:** review the npm packages Spectra's source needs before adding any of them. AGENTS.md says to ask before adding dependencies, and to record why in `documentation/theme-development.md`.

## Tasks
Rule 5: each task should fit in about 2 hours. If one does not, split it.

1. **Spike (go/no-go):** check out the source at the 1.0.13 commit. Build one block (`accordion`) as `mody/accordion` into `app/dist/blocks/`, register it from the MU plugin, and use it in the Site Editor. Measure how much shared code (components, helpers, CSS engine) one block pulls in. Then decide on the location and on the CSS engine question.
2. **Strip list:** grep the source for remote URLs and vendor libraries, and record what is removed and why.
3. **Port the blocks** one block or small group per pull request:
   - rename the namespace and the text domain;
   - remove the vendor code;
   - colors only as palette slugs (no hex defaults);
   - local assets only.
4. **Validator and agent:** add the `mody/*` blocks (with their children and wrapper tags) to `BlockValidator::WHITELIST`. Add them to the prompt's block list and a few-shot example if the agent should use them.
5. **Migration:** since 2026-11-07 every generated layout is `spectra/container` ([M3-08](M3-08-spectra-container-layouts.md)), so a `mody/container` must render the same attributes: `layout` (constrained, grid with `columnCount`, flex), `style.spacing`, the per-device values in `style["@tablet"]` and `style["@mobile"]`, `align`, `htmlTag`, `backgroundColor` and `textColor` as palette slugs, and `isBlockRootParent`. `SpectraLayout`, the validator and the prompts change with it.
   - editor transforms from `spectra/*` to `mody/*`;
   - a WP-CLI command that rewrites saved content across the network (`parse_blocks()`, rename, `serialize_blocks()`), with a dry run.
6. **Remove Spectra:**
   - deactivate it network-wide and remove it from `app/composer.json`;
   - delete `SpectraGuard` and `scripts/spectra-guard-test.php`;
   - update mvp-spec §10, ai-engine.md, architecture.md and AGENTS.md.

## Acceptance criteria
- Every `mody/*` block works in the Site Editor and on the front end, and makes no request to any host outside the site. Check the server side like `scripts/spectra-guard-test.php`, and the browser side by inspecting the editor and front-end HTML.
- The fork contains no vendor library, telemetry, upsell, AI builder, remote font or map code. A grep for remote URLs is clean.
- Colors come only from palette slugs. The text domain is `mody_cloud`. The GPL notices are kept.
- Existing `spectra/*` content migrates to `mody/*` without "invalid block" warnings in the editor.
- After the migration, Spectra is gone from Composer and from the network.

## Open questions
- Which blocks the MVP needs, and whether core blocks cover some of them.
- Location: this repository (`src/blocks`, registered by the MU plugin) or a separate `mody/blocks` package through SatisPress.
- Keep Spectra's CSS engine, or rely on `theme.json` and core block supports.
- Upstream fixes: how to follow and port Spectra's security fixes after the fork (watch the repository's releases).
- When: before or after launch (M5).
