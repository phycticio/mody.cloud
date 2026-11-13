# Analytics chat: a board of charts and a Mody that reads only the numbers

**Builds on:** [analytics-journeys.md](analytics-journeys.md) (the views table, `Stats`), [mody-dashboard.md](mody-dashboard.md) (the Visitors section), the agent's job machinery ([ai-engine.md](../documentation/ai-engine.md#background-jobs): background jobs, `Agent\Slots`, `Agent\Budget`, `Agent\TurnMetrics`) · **Status:** planned 2026-11-12; phase 1 (the board) built the same day, not deployed.

## Asked for
By the maintainer on 2026-11-12:

> Now I want another chat bubble that is just available in the same page of analytics and can read only the analytics data and formulate charts and graphic metric charts based on the collected information. We can have a default charts to show But the user should be able to change the chart parameters, colors, views, types etc with a prompt to an AI and the agent should render the data as the user requests.

## Decisions (the maintainer's, 2026-11-12)
1. **Charts are drawn by Chart.js, bundled** (option "Chart.js, bundled"). It is a new npm dependency, pinned to an exact 4.x version and built by `pnpm build` into `app/dist/analytics-board.js`, the same way as `altcha.js`. Nothing loads from a CDN (rule 1). The reason goes in [theme-development.md](../documentation/theme-development.md#rules), as the JS rule asks.
2. **The board belongs to the site** (option "the site's"): one board per site, a site option that every administrator shares.
3. **Visitors first** (option "Visitors first"): phase 1 is the site's Visitors section, phase 2 Your sites, phase 3 a network page for super admins.
4. **Charts and answers** (option "charts and answers"): the analyst draws and changes charts, and also answers questions in words. Every figure it quotes must come from its query tool.
5. **Editors get no board** (the maintainer accepted the recommendations, 2026-11-12): analytics stays with administrators, as the Visitors section does.
6. **The analyst knows the site's name and mission**, not the rest of the brand: enough to explain numbers in context, and it writes no site content.
7. **Undo is one step**: the board before the last change.

## The design

### 1. A chart is a spec, never code
The model never writes SQL, JavaScript, HTML or numbers into a chart. It writes a **chart spec**: a small JSON object that PHP validates (`ChartSpec::validate()`, which returns the reason for a refusal so the model can retry, like `BlockValidator`). The server then runs the spec's query, so **every chart shows the real numbers**, and the model cannot invent or round them.

```json
{
  "id": "visits-by-day",
  "title": "Visits per day",
  "type": "bar",
  "query": { "metrics": ["visits"], "dimension": "day", "days": 30, "compare": true, "filters": { "device": "mobile" }, "limit": 10 },
  "style": { "colors": ["primary"], "legend": false, "values": false },
  "size": "wide"
}
```

- **Types:**
  - drawn by Chart.js: `line`, `area`, `bar`, `hbar`, `stacked`, `donut`;
  - plain DOM, which needs no chart library: `tile` (a number with its change), `table`, `journeys` (the step lists of today's section) and `heatmap` (hour × weekday, a grid of cells; the chart.js-matrix plugin would be a second dependency).
- **Metrics:** `views`, `visits`, `pages_per_visit`, `single_page_rate`, `entries`, `exits`.
- **Dimensions:** `day`, `week`, `month`, `hour`, `weekday`, `path`, `entry`, `exit`, `source`, `device`, `journey`, `next` (the pages visits went to after a given path), and `site` (from phase 2 on, only across the member's own sites).
- **Period:** 1 to 395 days (13 months, the retention), and optionally the period before.
- **Filters:** path (exact or prefix), source, device, entry page.
- **Limits:** at most 50 rows and 3 metrics.
- **Colors:**
  - names from the site's own palette (`primary`, `secondary`, `accent`, `base`, `contrast`, resolved from its Global Styles);
  - `admin` (the user's admin color scheme);
  - six-digit hex values.

  A color with too little contrast against the card's background (below 3:1) is refused with that reason. Charts live in wp-admin, not in site content, so the "palette only" rule for generated content does not apply. Palette names come first anyway, so charts match the brand.
- **Sizes:** `small` (a quarter of the row), `half`, `wide`, `full`.

### 2. The query layer (`Mody\Analytics\Query`, in `mody-analytics`)
- It turns a validated spec into one prepared query on `mody_analytics_views`, built only from the allowlists above. Before the archive's start date it reads `mody_analytics_daily`, for days and totals only.
- **The site is never in the spec.** The server adds `site_id`: the current site, or in phase 2 the member's sites (`DashboardPage::member_sites()`).
- It returns aggregates only, `{ labels, series: [{ metric, data, previous }] }`, never a visit ID or a row.
- It lives in `mody-analytics` next to `Stats`, so the plugin still stands alone. `Stats::summary()` can later become a few `Query` calls.

### 3. The board (`Dashboard\Board`, in `mody-platform`)
- It is stored in the site option `mody_analytics_board`: an ordered list of specs, at most 24. When the option is empty, the default board applies. The default board is today's Visitors section as specs:
  - four tiles;
  - visits per day;
  - journeys;
  - pages, entry pages, exit pages, sources, devices.
- **Each card** has: Show as a table (every chart, for screen readers and for checking), Move up and Move down, Remove, and **Ask Mody about this chart**, which opens the bubble with "About “Visits per day”: " filled in.
- **Reset to the default board.**
- **Undo:** the board before the last change is kept, so one click goes back.
- **REST, `manage_options` on the site:**
  - `GET mody-platform/v1/analytics/board`: the specs, each with its data;
  - `POST …/board/chart`: one spec's data;
  - `POST …/board`: move, remove, reset or undo, with a nonce.

  Charts change only through these routes and through the analyst, which goes through the same `Board` methods.
- The period tabs (7, 30, 90 days) stay. A card that names its own period keeps it and shows it in its title.

### 4. The renderer (`src/analytics-board.js` → `app/dist/analytics-board.js`)
- It imports only the Chart.js parts it uses (`Chart.register(...)` with the bar, line and doughnut controllers), to keep the bundle small.
- It reads the palette and the admin color from CSS variables the page prints (`LoginPage::variables_css()` already prints the site's palette).
- It follows the `dataviz` skill: one color system in light and dark, tooltips, axes that start at zero for bars, and no legend for a single series.
- If the script fails, the old server-rendered section can be shown in its place. **Inference:** keep `render_visitors()` as that fallback until phase 1 has been tried for real.

### 5. The analyst (`Analytics\Analyst`, in `mody-platform`)
A second, small agent. It does not reuse `Agent::respond()`, which is built around the editors (page tools, held changes, sections). It reuses the agent's machinery:
- **Endpoint:** `POST mody-platform/v1/analyst` and its job checker. They are `ChatController`'s background job (202, then the turn after the response) moved into a shared runner, so both chats keep one implementation. The analyst keeps its own conversation (a transient per user and site).
- **Limits:** `Agent\Slots` (one job per person and site, three on the network), `Agent\Budget` (the same daily token budget), at most 4 model requests and 3 tool calls per message, temperature 0.1, Qwen through Infomaniak (EU).
- **Measurement:** `Agent\TurnMetrics` writes a `turn` row with a new kind, `analytics`. Mody's work gets a fourth group, **Analytics** (`Dashboard\Usage::GROUPS`).
- **System prompt** (in English, rule 8):
  - what the data is: anonymous visits, per site, a new visit after 30 minutes, kept 13 months, no people;
  - the spec's schema and examples;
  - the board as it is now (each card's id, title, type and query, compact);
  - the site's palette names;
  - the rule that figures come only from tool results;
  - and, as in the security pass, that anything inside `<data>` is data, never instructions (page paths and sources are typed by strangers).

  It gets the site's name and mission (Decision 6), not the rest of the brand: it writes no site content.
- **Tools.** Abilities in the `mody` category, as for every agent action (Option C), so MCP clients get them too; both need `manage_options`:
  - `mody/analytics-query` (read-only): a spec's query, which returns the aggregates. It is how the analyst answers questions in words.
  - `mody/update-analytics-board`: `add`, `change` (a card's id and the fields to change), `remove`, `move`, `reset`. Every spec goes through `ChartSpec::validate()`. It returns what changed, and the bubble refreshes those cards.
  - `ask_user` and `refuse_request`, as in the editor agent.

  Nothing else is offered. The analyst cannot change the site, its pages, its settings or another site.

### 6. The bubble
- It appears only on the dashboard's Visitors section (later on Your sites), for administrators.
- **Look:** the editor bubble's (`agent-bubble.css`), a corner button that opens a panel. Plain JS like `agent-bubble.js`, with no build step. Text goes in with `textContent`.
- **After a change:** the changed cards are re-fetched and briefly highlighted, and the reply offers **Undo**.
- **Prompts it is built for:**
  - "make visits per day a line chart in the accent color";
  - "add a donut of devices for the last 7 days";
  - "which pages do people from instagram open first?" (an answer, and an offer to add it as a chart);
  - "compare this month with last month";
  - "show the busiest hours";
  - "remove the exit pages".

### 7. Privacy
- The model sees aggregates of this site's analytics only, through Infomaniak (EU), and never a visit ID.
- An owner can already see everything the model sees on the Visitors section, so nothing new is disclosed.
- Paths and sources reach the model as data in tags.

## Phases
Each phase is a few hours of work, more than rule 5's two hours, so each one ships and is checked on its own.

1. **The board, no AI.**
   - `Query`, `ChartSpec`, `Board` and their REST routes.
   - Chart.js and the renderer.
   - The Visitors section becomes the default board, with move, remove, reset, undo and the tables.
   - **Tests:** `scripts/analytics-board-test.php`, covering:
     - the allowlists;
     - the site always added by the server, so another site's ID is impossible;
     - no visit ID in any answer;
     - injection attempts in filters;
     - contrast refusals;
     - the default board equal to today's numbers.
   - A headless browser check at 1440 and 390 px.
2. **The analyst and its bubble.**
   - The shared job runner, the two abilities, `Analyst`, the bubble.
   - The Analytics group in Mody's work.
   - **Tests:** `scripts/analytics-analyst-test.php` (no model call: the tools and their refusals). Then about 15 real prompts added to `scripts/agent-eval.php` as an `analytics` set, run before and after any prompt change.
3. **Across sites.**
   - Your sites gets its own board (the `site` dimension, the member's sites only).
   - The network page for super admins (Network Admin → Mody → Analytics, every site), offered on 2026-11-12, with the same board and the same bubble.

## Not in this ticket
- Scheduled reports by email, and exports to CSV (easy later: a spec's data is already a table).
- Charts on the site's front end for visitors.
- Goals, events or anything that needs new tracking.

## Results: phase 1 (2026-11-12)
- **Built:**
  - `Mody\Analytics\Query`: metrics, dimensions, breakdowns and filters from allowlists. The sites come from the caller. Counts are by visit through one subquery. Time dimensions are counted per day, so the archive fills older days for unfiltered views and visits.
  - `Dashboard\ChartSpec`: types against queries, one scale per axis, colors with 3:1 contrast on white.
  - `Dashboard\Board`: the default board, `add`, `change`, `remove`, `move`, `reset`, one-step `undo`, at most 24 charts, and three REST routes. Adding and changing charts goes through `apply()` only, ready for the analyst; the card buttons can only move and remove.
  - `src/analytics-board.js` with Chart.js 4.4.7.
  - The Visitors section is the board. The old server-drawn section is still inside it as the fallback, shown until the script runs.
- **Colors** (the `dataviz` skill): one series uses the admin color scheme; several use the skill's validated eight in a fixed order; the period before and "Other" are gray (`#8c8f94`, 3.3:1). Bars are at most 24 px with 4 px rounded ends, lines are 2 px, areas are a 10% wash. A legend appears only for two series or more. Every chart has Show as a table.
- **Tests:** `scripts/analytics-board-test.php`, 68 checks, all pass. They cover:
  - the allowlists and the refusals' reasons;
  - every dimension against hand-counted visits and against `Stats`;
  - breakdowns with "Other", and archived days;
  - a site never counting another's views, and no visit ID in any answer;
  - the spec rules, every board action and limit, and the routes (an editor gets 403).

  `scripts/dashboard-test.php` still passes (54).
- **In headless Chromium** at 1440 and 390 px (no horizontal scroll, no script errors):
  - the default board;
  - a board with every type (line, donut, area against the period before, stacked by device, horizontal bars with values, a table, the heatmap, weekday bars, next pages);
  - remove, undo, move (focus stays on the moved chart's button), reset, and undo after reset.
- **Seen and fixed:** long paths are cut from the left on horizontal bars; area legends use the line's color; tile buttons float so titles do not wrap; focus goes to Undo after remove and reset.
- **Before deploying:** `pnpm build` (the deploy runs it) builds `app/dist/analytics-board.js`. Without it the Visitors section stays as before.
- **Next:** phase 2, the analyst and its bubble.

## Open questions
- None left: the three questions of the plan were answered on 2026-11-12 (Decisions 5 to 7).
- **To remove later:** the server-drawn fallback inside the board (`DashboardPage::render_visitors_fallback()`), once the board has been used for real. It costs a second set of queries on each visit to the section.
