# Visitor location: country and city, looked up on our own server

**Builds on:** [analytics-journeys.md](analytics-journeys.md) (the views table, the beacon), [analytics-chat.md](analytics-chat.md) (the board and the analyst) · **Status:** planned and built 2026-11-13; not deployed.

## Asked for
By the maintainer on 2026-11-13:

> We need to capture the location of the user without invading their privacy. We need only the City and Country of the user.

## Decisions (the maintainer's, 2026-11-13: every recommendation accepted)
1. **The database: DB-IP City Lite.**
   - **Who:** Eris Networks S.A.S., Perros-Guirec, France, so rule 1 is met. MaxMind's GeoLite2 is left out: a US company, and an account to download.
   - **Licence:** CC BY 4.0, free, with credit to DB-IP required.
   - **The file:** monthly, MMDB format (about 130 MB), about 8.1 million ranges. Accuracy is lower than paid sets (77 against 96 on DB-IP's own index), and good enough for countries and larger cities.
2. **Our own MMDB reader:** a small PHP class in `mody-analytics` (`Mody\Analytics\Geo\Reader`). MMDB is a documented binary format (a search tree, then a data section), so there is no new dependency. It is tested against known addresses.
3. **Updates through a script before each deploy:**
   - **The script:** `bin/geoip` downloads the month's file from DB-IP on the maintainer's machine, checks it (it opens, and it knows a few test addresses), and puts it at `app/data/geoip/dbip-city-lite.mmdb`.
   - **Getting it to the server:** the path is ignored by git (too big), and `./bin/deploy` carries it with the rest of the build (it rsyncs the working tree).
   - **The server calls nobody.** Without the file, location is simply "Unknown", and nothing breaks.
4. **Small towns stay hidden: cities with 5 visits or more only.**
   - In the period shown, a city with fewer than 5 visits is counted as "Other places in <country>". Countries are always shown.
   - The rule is in the query layer, so the board, the tables, the CSV and the analyst all get the same numbers.
   - A filter on such a city answers "fewer than 5", never the number.

## How it works
- **When:** the beacon's route already has the visitor's address in memory for the daily hash (`Collector`).
- **The lookup:** it happens in-process, in the local file, and gives a country (ISO code) and a city (its English name).
- **What is kept:** both go on the view row, beside the device and the source. The address is still never stored, and no outside service ever sees it.
- **The table:** `mody_analytics_views` gets `country char(2)` and `city varchar(80)` (Store version 4). Earlier visits are not filled in: they show as "Unknown".
- **Logged-in users and bots** are treated as now: logged-in users counted anonymously, bots not counted.
- **Query:**
  - `country` and `city` become dimensions, filters (one, several, or `not`, as in phase 3a of the analytics chat) and breakdowns.
  - City rows carry their country ("Barcelona, Spain"), and country names come in the dashboard's language (PHP's intl, else English).
- **The board:** two new cards on the default board, Countries and Cities, as ranked lists. A site that changed its board adds them by asking Mody.
- **The analyst:** knows the two dimensions, and the 5-visit rule, so it can explain why a town does not show.
- **Credit:** a line under the Visitors section ("Locations: DB-IP, CC BY 4.0", a link), as the licence asks.
- **Nginx:** `app/data/` is outside the web root's allow-list. A check confirms the file is never served.
- **Legal pages, when written:** visitors' country and city are worked out from their address and kept, never the address.

## Parts
1. The reader and `bin/geoip`, with tests against known addresses (no download in tests: a small test MMDB built by the test).
2. The collector, the table and the query: dimensions, filters, the 5-visit rule.
3. The board's two cards, the credit, the analyst's prompt, the Nginx check, and the demo data on mody.local given locations.

## Not in this ticket
- Regions or provinces, coordinates and maps (a map would need shapes of countries: a later decision).
- Filling in locations for visits counted before this.

## Results (2026-11-13)
- **Built:**
  - `Geo\Reader`: MMDB, record sizes 24, 28 and 32, IPv4 and IPv6, read with seeks.
  - `Geo\Locator`: country and city only; a district in brackets is cut; private addresses are skipped.
  - `Collector` stores both on each view (Store version 4: `country`, `city`). The archive keeps countries after 13 months, not cities.
  - `Query`: `country` and `city` as dimensions, `country` as a breakdown (no breakdown by city: it would show small towns over time), `country` and `city` as filters. Cities under 5 visits fold into "Other places in <country>", counted before the top rows are picked. A city filter under 5 visits is refused with the reason.
  - The default board's Countries and Cities cards, the credit line, the analyst's prompt.
  - `bin/geoip`, the deploy's warning, Nginx's `/data/` deny.
- **The real file** (DB-IP City Lite 2026-11, 127 MB, 28-bit records, an IPv6 tree): about 1 ms per lookup. 8.8.8.8 is Mountain View, 80.58.61.250 Madrid, an IPv6 address Frankfurt.
- **End to end:** a beacon through Nginx, with a Madrid address in `X-Forwarded-For`, stored Spain and Madrid. Earlier views stay "Unknown".
- **Tests:**
  - `scripts/visitor-location-test.php`, 26 checks, all pass. It writes its own small MMDB to check the reader without the real file, then checks the locator, what is stored (never the address), the 5-visit rule, the archive, the board, the analyst's prompt, the file never served (403), and the real file when present.
  - `scripts/analytics-eval.php` gained two cases: the top country, and a town of one visit refused with the reason. 18 of 18 pass.
  - The board, analyst and dashboard tests still pass.
- **Demo data on mody.local:** the fake visits have places: Spain first, the rest of Europe, a few from the Americas, and five small towns that never show by name.
- **On deploy:** run `./bin/geoip` first, then deploy, then restart Nginx on the server (`docker compose restart nginx`) for the `/data/` deny. The database moves to version 4 by itself.
- **Open:** the legal pages, when written, should say that visitors' country and city are worked out from their address and kept, never the address. DB-IP's credit is on every Visitors section.
