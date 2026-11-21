# The language switcher: a dropdown, and flags

**Builds on:** [site-languages.md](site-languages.md) part 5 (the `mody/language-switcher` block) · **Status:** planned and built on 2026-11-21 on `feature/#35-language-switcher-settings`, Mody taught the settings the same day; merged (#36) and deployed 2026-11-21 in `8a4966d` (Results below) · **Issue:** [#35](https://github.com/phycticio/mody.cloud/issues/35)

## Goal (maintainer, 2026-11-21)

> Let's add some settings to the language switcher.
> - It should have the option to be a dropdown
> - It should have the option to show just text, just flags or both per language

## What exists today (checked 2026-11-21)

- `mody/language-switcher` (Mody Languages, `blocks/language-switcher/`, `src/Switcher.php`) is rendered on the server: a `<nav>` with a row of links, one per language of the site, to the current page's translation or that language's home page. It has one setting, **Show the current language**, plus colors, spacing and typography.
- A block hook puts it after every navigation block on a site with more than one language. On phones the names become short codes (ES, DE), with the names kept for screen readers.
- Each language can have its own copy of the header (`header-es`, `Parts`), edited on its own in the Site Editor.

## Plan

1. **Two settings** in the block's sidebar, saved as attributes:
   - **Layout** (`layout`): *Links* (`list`, the default and today's look) or *Dropdown* (`dropdown`).
   - **Show** (`display`): *Name* (`text`, the default), *Flag* (`flag`) or *Flag and name* (`both`).
   An unknown value falls back to the default, so existing blocks and the hooked ones look as before.
2. **The dropdown** is a `<details>` element: its `<summary>` shows the current language and a chevron, its list the languages (all of them, or the others when Show the current language is off). It opens and closes without JavaScript; a small front-end script (`view.js`, loaded only where the block is) closes it on Escape or a click outside. The list uses the site's `base` and `contrast` colors, or the block's own background and text colors when it has them.
3. **The flags** are small SVGs drawn for the plugin (`blocks/language-switcher/flags/<code>.svg`), inlined in the markup: no request, nothing from outside (rule 1). Emoji flags were set aside: Catalonia has none, and Windows shows two letters instead.
   - English: the United Kingdom's flag; Spanish: Spain's (without the coat of arms, as its civil flag); Portuguese: Portugal's (the arms simplified); Catalan: the senyera; French, German, Italian and Dutch: their country's flag.
   - **Accessibility:** a flag is decoration (`aria-hidden`); with *Flag* alone the name stays for screen readers and appears as the link's tooltip, and `lang` stays on every link.
4. **Phones:** with *Name*, the short codes as today; with a flag, the flag alone.
5. **Tests** in `scripts/languages-test.php`; a real-browser check of both layouts on the front end and in the Site Editor.

## Open questions

- **Q1.** The flag for English: the United Kingdom's (built), or the United States' (the language is `en-US`)? A flag stands for a country, not a language; that's why *Name* stays the default.
- **Q2.** Each language's header copy has its own switcher, so a dropdown chosen in the English header does not reach `header-es`: set it in each copy, or should one choice apply to every copy?
- ~~**Q3.** Mody (the agent) does not know the two settings yet.~~ Answered by the maintainer on 2026-11-21 ("Teach mody the new settings"): built, see Results.

## Results (2026-11-21)

- **Built:**
  - `block.json`: the attributes `layout` and `display`, and `view.js`;
  - `Switcher::render()`: the dropdown and the flags (`Switcher::flag()`; the wrapper's classes `is-dropdown` and `is-display-<display>`);
  - the eight flags in `blocks/language-switcher/flags/` (generated from one script; plain 3:2 SVGs, no ids, about 300 bytes each);
  - `style.css`: the dropdown's list, the flags, a ring on the current language's flag where the name is hidden;
  - the sidebar's Layout and Each language shows (`assets/language-switcher-block.js`).
- **Found on the way:**
  - With flags only, the current language was marked only by its name's underline, which is hidden. Its flag now has a ring.
  - A block wider than its button (in a page's content) opened its list at its far edge. The list now hangs from the button: from its end without JavaScript (safe at the end of a header), from its start when that fits on the screen (`view.js`).
- **Tests:**
  - `scripts/languages-test.php`: 86 checks, all passed. 8 are new: the default unchanged, the dropdown and its list, without the current language, flags only with the name kept, unknown values, every flag's file, the script loaded with the block only.
  - **Browser** (headless Chromium, the main site's header on `/es/`, at 1280 and 390 px; the settings set by a throwaway local filter, removed after):
    - every layout and display;
    - the dropdown opens, and closes on a click outside and on Escape, with the focus back on its button;
    - a link leads to `/de/`;
    - no horizontal scroll on a phone.

    In the block editor: the sidebar's settings change the preview and save only what differs from the defaults (`<!-- wp:mody/language-switcher {"display":"flag"} /-->`). No console errors, no request outside the network.
- **Not checked:** the Site Editor's header (the same block; its hooked copy takes the settings once the header is saved), Firefox and Safari (`<details>` and `color-mix()` are supported in both), a right-to-left language (none is offered).
- **Mody knows the settings** (asked by the maintainer the same day, Q3):
  - **The template prompt** (`SystemPrompt::for_edit()`, rule 9) names the switcher's attributes and their values. No reference card: cards are for blocks in pages, and every page prompt lists them, while the switcher belongs in templates only.
  - **The editor's chat prompt** (`SystemPrompt::for_editor()`, with a template open) said that adding, moving or removing a block is done with the edit tool; "make the language switcher a dropdown" was then declined 1 time in 7 ("I can only add, move or remove blocks"). It now names changing a block's settings too, inside the same sentence: as a separate sentence it made the footer case fail 3 times in 11 (an empty answer), against 16 in 16 without it.
  - **The validator** already checks every block's attributes against its block.json, enums included, so a wrong value is refused with the allowed ones and the writer retries.
  - **Tests:** `scripts/agent-template-test.php` has three real cases on a site with other languages, each from a header whose switcher it sets itself: a dropdown with flags and names, flags only (asked in Spanish), and back to names in a row. Before the prompt change they failed 3 of 3 (an invented `"style":{"display":"dropdown","showFlags":true}`, the flags kept). With the final wording: 12 of 12 over four runs, and the footer case 12 of 12. The script's three offline failures and the tagline case read this site's header, which has no site title; they are known since 2026-11-17 (independent-mody-plugins.md).
  - **The evaluation set** (`scripts/agent-eval.php`), after: 30 of 31, as the 2026-11-20 baseline. The miss, "Make the headings bigger" (Mody asked how much), has no editor open, so neither changed sentence was in its prompt; the styles group then passed 12 of 12 over three runs.
- **Not done:** the open questions above (Q1, Q2).
