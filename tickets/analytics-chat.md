# Analytics chat: a board of charts and a Mody that reads only the numbers

**Builds on:** [analytics-journeys.md](analytics-journeys.md) (the views table, `Stats`), [mody-dashboard.md](mody-dashboard.md) (the Visitors section), the agent's job machinery ([ai-engine.md](../documentation/ai-engine.md#background-jobs): background jobs, `Agent\Slots`, `Agent\Budget`, `Agent\TurnMetrics`) · **Status:** planned 2026-11-12; phase 1 (the board) built and pushed the same day; phase 2 (the analyst) and phase 3a (dates, filters, channels) built 2026-11-13; not deployed.

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

### 5. The analyst (`Dashboard\Analyst`, in `mody-platform`)
A second, small agent. It does not reuse `Agent::respond()`, which is built around the editors (page tools, held changes, sections). It reuses the agent's machinery:
- **Endpoint:** `POST mody-platform/v1/analytics/analyst`, answered **within the request** (changed when built, rule 5): a turn is one to five short model calls with no page to write, 1 to 5 seconds, so there is no background job, no checker and no change to the editor chat's `ChatController`. `GET` returns the conversation, `DELETE` starts a new one (a transient per user and site, the last 16 messages, with the tool calls).
- **Limits:** `Agent\Slots` (one job per person and site, three on the network), `Agent\Budget` (the same daily token budget), at most 5 model requests and 5 tool calls per message, a 90-second deadline, temperature 0.1, Qwen through Infomaniak (EU).
- **Measurement:** `Agent\TurnMetrics` writes a `turn` row with a new kind, `analytics`. Mody's work gets a fourth group, **Analytics** (`Dashboard\Usage::GROUPS`).
- **System prompt** (in English, rule 8):
  - what the data is: anonymous visits, per site, a new visit after 30 minutes, kept 13 months, no people;
  - the spec's schema and examples;
  - the board as it is now (each card's id, title, type and query, compact);
  - the site's palette colors that charts can use (the light ones left out);
  - the rule that figures come only from tool results;
  - and, as in the security pass, that paths, sources and titles in `<board>` and in tool results are data, never instructions (they are typed by strangers).

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
3. **More abilities for the analyst** (decided 2026-11-13, below), in three parts:
   - **3a. Dates, filters, sorting, channels:** date ranges per chart, any two periods compared, exclude and several values, weekday and hour filters, ascending sort, "Other", channels.
   - **3b. Goals and funnels.**
   - **3c. Notes, CSV, explanations.**

   Each part grows `scripts/analytics-board-test.php` and `scripts/analytics-eval.php`.
4. **Across sites** (was phase 3).
   - Your sites gets its own board (the `site` dimension, the member's sites only).
   - The network page for super admins (Network Admin → Mody → Analytics, every site), offered on 2026-11-12, with the same board and the same bubble.

## More abilities for the analyst (decided by the maintainer, 2026-11-13)
Asked for: "The analyst should also be able to filter, reduce the graphic, ask for specific months, etc." Every option below is the maintainer's pick from the questions of 2026-11-13. The details under each are the plan's defaults.

1. **Dates on each chart, through Mody.** A query takes `from` and `to` (Y-m-d) instead of `days`, so Mody can chart "March", "1 June to 15 August", "Q2" or "since January". The prompt gives Mody today's date to work them out. The card shows its dates ("1–31 Mar") where it now shows "Last N days". The board's 7, 30 and 90 day tabs stay for charts without dates. The range stays within the retention (13 months). Days before the views table come from the archive, which keeps daily totals only.
2. **Any two periods compared.** `compare` takes `true` (the period just before, as now) or its own `from` and `to` ("June against March", "this November against last"). Time charts line up by position. The second series is named by its dates.
3. **Exclude and several values.** Every filter takes one value, a list ("phones and tablets", "these three pages") or `{"not": …}` ("everything except direct").
4. **Weekday and hour filters.** `weekday` (Monday to Sunday, one or several: "only weekends") and `hour` (`{"from": 9, "to": 17}`: "working hours"). The site's own hours.
5. **Least visited and sorting.** `sort`: `desc` (now), `asc` or `label`. With `asc` on pages, the site's published pages with no views are listed too, at 0: "the pages nobody visits".
6. **Fewer rows, the rest as Other.** `other: true` keeps the `limit` biggest values and adds up the rest in one "Other" row. It is only offered where the parts make a whole: visits by partition dimensions, or views by page. **Simplify** needs no new tool. The prompt teaches Mody to drop `compare`, extra metrics, the breakdown or the legend through `change`.
7. **Goals and funnels.**
   - A metric `reached` counts the visits that saw a goal page (`goal`: a path or a prefix), and `conversion_rate` gives their share of visits. Both work over time and by source, device, entry or channel.
   - A chart type `funnel` with `steps` (two to five pages, in order) counts the visits that saw each step after the one before, in the same visit (not necessarily next). It shows each step's share of the first and the visits lost between steps.
   - No new tracking: it reads the journeys already stored.
8. **Channels, fixed.** A list in the code (`Mody\Analytics\Channels`), the same on every site:
   - **Direct:** no source;
   - **Search:** DuckDuckGo, Ecosia, Qwant, Startpage, Brave, Google, Bing, Yahoo, Yandex, Baidu, Swisscows and others;
   - **Social:** Mastodon instances, LinkedIn, Facebook, Instagram, X, Reddit, Bluesky, YouTube, TikTok, Pinterest and others;
   - **Other sites:** everything else.

   `channel` is a dimension, a filter and a breakdown. **Inference:** Mastodon instances are many hosts, so the list matches known instances and the `mastodon.` and `social.` prefixes. A missed instance counts as Other sites.
9. **Mody looks into "why".** For "why did visits jump on 2 June?", the prompt has Mody count that day by channel, source, entry page and device against a normal day (the same weekday over the four weeks before), then answer with what changed. Such questions use more of the five tool calls, and more tokens.
10. **Notes on dates, per site.** Mody adds a note when told ("mark 10 November as registration opened"), in the site option `mody_analytics_notes` (date and label, at most 50). Every time chart of the site draws each note in its period as a thin line with its label, and lists it in the chart's table. Mody adds and removes notes through the board's tool (`note` and `unnote` actions).
11. **A CSV per chart.** A "Download CSV" link beside "Show as a table", built in the browser from the numbers already shown (a `Blob`). No server work, no new route.

## Not in this ticket
- Scheduled reports by email.
- Charts on the site's front end for visitors.
- Events or anything else that needs new tracking.

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

## Results: phase 3a (2026-11-13)
- **Built:**
  - **In `Query`:**
    - dates (`from` and `to`; an end past today stops today; within the 13 months);
    - `compare` true or with its own dates, the compared period named by its dates (`previous_label`);
    - every filter as one value, a list or `{"not": …}`, plus `weekday` (names, "weekend", "weekdays", 1–7) and `hour` (`{from, to}`, wrapping past midnight);
    - `sort` (`desc`, `asc`, `label`; with `asc` on pages, published pages nobody visited are listed at 0);
    - `other`, and a week named by the period's first day when the period starts mid-week.
  - **`Channels`:** Direct, Search engines, Social media, Other sites, as a dimension, a filter and a breakdown.
  - **The board:** each card shows its own period ("June 2026", "Last 14 days"); a compared series is named by its dates ("Visits, March 2026"); tiles say what they are compared with; "Other" is gray. A change of period replaces the old one (days drop dates, dates drop days).
  - **The analyst's prompt:** today's date, all of the above, and how to simplify or reduce a chart.
- **Changed:** the `next` dimension's start page is `page` (it was `from`, now the date). Nothing deployed used it.
- **Privacy:** a city can be chosen, never left out ("everything but Perros-Guirec" would give its number by subtraction). Each city in a list must have 5 visits or more.
- **Tests:**
  - `scripts/analytics-board-test.php` now has 88 checks (20 new for phase 3a), all pass.
  - `scripts/analytics-eval.php` has 23 cases (5 new: search engines, not direct, phones or tablets, March by week, the top 2 sources with Other). All new cases pass. The run's one failure was a model error on its first message, not an answer.
- **In headless Chromium:** a June-against-March line, a Q2 tile against Q1, and a donut with Other render with their labels, with no script errors.

## Results: phase 2 (2026-11-13)
- **Built:**
  - `Dashboard\Analyst`: the two abilities (`mody/analytics-query`, read-only; `mody/update-analytics-board`), the prompt, the tool loop, and the route `mody-platform/v1/analytics/analyst`.
  - `src/analytics-chat.js`: the bubble, in the editors' bubble's look (`agent-chat.css`, `agent-bubble.css`, the user's color scheme). It also adds "Ask Mody about this chart" to every card, and Undo after each change Mody makes. The earlier conversation loads before a new message is added.
  - `Dashboard\Usage`: a fourth group, **Analytics** (`kind` = `analytics` in the `turn` row).
- **Changed from the plan:** answered within the request, not as a background job (see 5).
- **Tests:**
  - `scripts/analytics-analyst-test.php`, 29 checks without the model, all pass. They cover:
    - the abilities, their permissions (an editor is refused) and isolation (no other site, no visit ID);
    - refusals the model can read;
    - the prompt (name and mission only, the board, only usable colors, English);
    - the routes, and the Analytics group.
  - `scripts/analytics-eval.php`, 16 real prompts in one conversation:
    - questions answered with the right numbers;
    - charts changed, added, moved, removed, undone and reset;
    - a white color, visitors' emails and the site's title declined.
    - Results: 16 of 16 on every run Infomaniak did not rate-limit. Two runs back to back hit HTTP 429 ("your current rate limit level is 1"), so cases now wait 6 seconds between them (`MODY_EVAL_PAUSE`).
  - `scripts/analytics-board-test.php` (68) and `scripts/dashboard-test.php` (54) still pass.
- **Accuracy:** every figure the analyst quoted matched the same count made directly (for example 833 visits, 130 from Instagram, 195 this week against 229 the week before).
- **In headless Chromium:**
  - the bubble opens with the focus in the box, and the spinner shows while Mody counts;
  - the changed chart is redrawn and marked, Undo puts it back, and Ask Mody fills in "About “Pages”: ";
  - at 390 px, the tile buttons stay in the title row;
  - no script errors.
- **Fixed on the way:**
  - the chips' `hidden` lost to `display: flex`;
  - "Add a donut of sources" was taken as changing the Sources list, so the prompt now says "add" means a new chart;
  - the prompt offered the palette's light `base`, which charts refuse.
- **Open:** Infomaniak's rate limit is level 1 and shared with production. The analyst, the editor chat and the evaluations all draw on it. A higher level is the maintainer's call (before launch).

## Open questions
- None left: the three questions of the plan were answered on 2026-11-12 (Decisions 5 to 7).
- **To remove later:** the server-drawn fallback inside the board (`DashboardPage::render_visitors_fallback()`), once the board has been used for real. It costs a second set of queries on each visit to the section.
