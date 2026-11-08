# M3-08 · Generated layouts are Spectra containers

**Milestone:** M3 (follow-up) · **Spec:** documentation/ai-engine.md ("Layout: Spectra containers"), mvp-spec §7, §8 · **Status:** done (2026-11-07; the browser walk-through is the maintainer's)

Asked for by the maintainer on 2026-11-07: "Every layout should use the spectra/container block with corresponding responsive settings. That way we make it consistent on all sites. Everything that is part of the layout should be a Spectra container."

## Goal
The page writer (new pages, edits, templates and parts) builds every section, row and column with `spectra/container`, with the same responsive settings on every site.

## How it is built
- **`AI\SpectraLayout`** holds the recipe:
  - **section:** a top-level container, full width, content at the theme's content width, padding 70/40, then 50/30 on phones;
  - **row:** a grid of 2 to 4 columns, gap 40; 3 or 4 columns become 2 on tablets, every row 1 column on phones;
  - **column:** a plain container.
  - The per-device values are in core's `style` attribute under `@tablet` and `@mobile`, where WordPress 7.1 and Spectra both read them. Breakpoints are core's: 480px and 782px.
- **The prompts:** `SpectraLayout::rules()` goes into the page writer's and the edit writer's prompts. The few-shot example is rebuilt from the recipe: four sections, a row of three, a row of two.
- **The validator:**
  - a container saves no HTML of its own (its save is its inner blocks);
  - a new page may not use group, columns or column;
  - a new page's containers use only the recipe's attributes, with spacing from the theme's presets.
  - Edits and templates keep members' own blocks and containers as they are.
- **After validation:** `SpectraLayout::normalize()` sets what Spectra's editor sets on load: `variationSelected`; on root containers, `isBlockRootParent` and `align` full.
- **On save without the editor:** `insert_page()` and the provisioning retry add a `spectraId` per container (`with_ids()`).
- **Provisioning's fallback page** is one section.

## Acceptance criteria
- [x] A generated page is a sequence of section containers, with rows of column containers, and no group, columns or column. 3 of 3 real pages, each valid at the first request.
- [x] Sections and rows respond to tablets and phones as the recipe says. Checked in the rendered CSS of a member site.
- [x] Edits add new layout as containers and keep existing blocks. A real whole-page edit added a section with a row of three.

## Results (2026-11-07, local)
- **`scripts/spectra-layout-test.php`: all checks pass**, offline and with real requests.
  - **The recipe and the example:** valid JSON; the example uses every shape and passes the validator; the prompts carry the rules; the fallback page is one section.
  - **The validator:**
    - HTML inside a container, a group or columns in a new page, an attribute off the recipe, a non-preset spacing, 6 columns, a "flow" layout, a hex or unknown color, and outside background media are all refused;
    - an edited group and a template's group pass;
    - so does a member's own unchanged container with extra attributes.
  - **normalize() and with_ids():**
    - roots and nested containers are set as the editor sets them, and a container under a core group (a template part) is still a root;
    - a fragment gets only `variationSelected`;
    - the output is stable on a second pass;
    - one unique ID per container.
  - **Rendered on `alpha`:**
    - four `<section>`s, full width with constrained content, and the hero in the palette colors;
    - the row: 3 columns, then 2 on tablets and 1 on phones;
    - section padding 70/40, then 50/30 on phones.
  - **Real runs (main site, Ogia Bakery):**
    - three pages valid at the first request, 4.8 to 6.2 s each, laid out by the recipe;
    - a whole-page edit adding three tips side by side: a new section with a row of three (6.6 s).
- **Other suites:**
  - every suite passes offline;
  - with real requests, `ai-generate-page-test.php` and `agent-edit-test.php` pass;
  - `provisioning-test.php` now checks the saved page with its Spectra IDs.
- **Not checked by the tests:** the pages in a real browser and in the Site Editor (no layout picker, no "invalid content", and the responsive settings visible in Spectra's device switcher).

## Notes
- **The model copies the example's structure** (a row of three, a row of two) when the request fits it, and adapts it otherwise (a contact page got one row of two).
- **`agent-template-test.php`'s real header request is failing (3 in 4 runs).** The agent answers "I can't move or add blocks in the header myself" instead of calling `edit_content`.
  - This is not caused by this ticket: the agent's prompt and tools are unchanged, and the failure comes before the page writer runs.
  - The same wording passed 10 of 10 on the same day (M3-07). The footer and page-template requests still pass.
- **The theme's templates and parts still use core groups.** Converting them to containers is a separate decision.
- **Spectra's CSS writes one invalid declaration** for a row's gap (`gap: var:preset|spacing|40`). Browsers ignore it, and valid gap rules for each device are emitted next to it.
