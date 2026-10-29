# Epic #72 Search Refactor - Quick Check

## Setup
1. `git checkout epic/#72-search-refactor && git pull --ff-only origin epic/#72-search-refactor`
2. `pnpm install && pnpm build:search`
3. `./bin/wp site list --fields=blog_id,url --format=table`

## Search Index
1. All sites: `./bin/wp tamarind index all --network`
2. One site: `./bin/wp --url=https://app.ecigintelligence.local/ tamarind index all`
3. Verify app index: `./bin/wp db query "SELECT COUNT(*) AS rows_count FROM wp_3_tamarind_search_index;"`

## Functional QA
1. Open `https://app.ecigintelligence.local/?s=test`
2. Date range is single-select radio behavior.
3. Geography, Topic, and Content type are multi-select checkboxes.
4. Filter changes update the URL and refresh results without full page reload.

## Pagination QA
1. Create enough local results: `for i in {01..90}; do ./bin/wp --url=https://app.ecigintelligence.local/ post create --post_type=post --post_status=publish --post_title="Pagination QA 125 $i" --post_content="pagination-qa-125 local pagination styling test result $i" --porcelain; done`
2. Reindex: `./bin/wp --url=https://app.ecigintelligence.local/ tamarind index all`
3. Open `https://app.ecigintelligence.local/?s=pagination-qa-125`
4. Pagination appears at the bottom-right with compact page controls, ellipsis, and Next state.
