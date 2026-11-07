# M2-01 · Site provisioning pipeline

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §7, §11 · **Status:** done (2026-11-06)

## Goal
From the wizard's answers, create a member's subdomain site that is fully provisioned: theme `theme`, Global Styles from the chosen preset, a brand profile, and a generated, validated home page. The job is resumable and never leaves an unthemed site.

## Split (decided 2026-11-06)
The original M2-01 ("brand wizard + site provisioning pipeline") was too big for one ticket (rule 5). It is now:
- **M2-01 · Site provisioning pipeline** (this ticket): the backend, with no UI.
- **[M2-03](M2-03-brand-wizard.md) · Brand wizard:** the 3 steps, which start a job and follow it.
- **[M2-04](M2-04-wizard-live-preview.md) · Wizard live preview:** step 2's header and hero, rendered with the real theme.

## Decisions (maintainer, 2026-11-06)
- **Quota until M1:** 3 sites per user, the trial and Collaborator limit. A user's sites are the network sites they administer (not the main site), plus their jobs that have not created a site yet. M1 swaps in the plan's limit (3, 10 or 50) through the `mody_site_limit` filter, or in `Quota::limit()`.
- **The wizard's home until M1:** Network Admin → Mody, for super admins (M2-03). M1 opens it to members through `mody_access_dashboard`.
- **Presets:** the six in `src/Design/Presets.php`, as they are.
- **Page generation** (found here, decided the same day, see "Results"): repair the `<Packed` typo before validating, and run the retries at a higher temperature.

## Deliverables
- [x] **Table `mody_provisioning_jobs`** (`src/Provisioning/Jobs.php`, installed on `init` like the other tables). Additions to the spec's definition:
  - a `queued` status;
  - `input` (the validated answers) and `result` (what the steps produced: `profile_id`, `page_id`, `fallback`, `generation_error`, `email`), both JSON.
- [x] **`Quota::can_create_site( $user_id )`** (`src/Provisioning/Quota.php`), with the stub above.
- [x] **`Pipeline`** (`src/Provisioning/Pipeline.php`), in-process:
  - `start( $user_id, $input )`: validates the answers (`validate()`), checks the quota, queues a job;
  - `run( $job_id )`: runs the steps;
  - `retry_generation( $job_id )`: generates the front page again for a site that got the fallback page.
  - Callers check permissions (the wizard, M2-03).
  - **Steps:** `quota → site → profile → theme → styles → page → email → done`. The profile row needs the new site's ID, so `profile` runs after `site` (the spec lists it before).
    - `quota`: the quota, and that the address is still free.
    - `site`: `wpmu_create_blog()`, with the member as administrator. It is created public, with the theme already set (never unthemed), the tagline (the "what do you do?" sentence) and `https://` addresses shaped like the main site's (`siteurl` with Bedrock's `/wp`). WordPress's sample post and page are deleted.
    - `profile`: the brand profile row, with the preset's colors as the brand colors (`BrandProfile::insert()`).
    - `theme`: checks the theme. The market plugins join this step with M4-02. Spectra is network-active.
    - `styles`: the preset's colors in the site's Global Styles (`SiteDesign::apply()`).
    - `page`: `PageGenerator::generate_page()` in the new site's context. The result is published as the front page, with the theme's untitled template (`page-blank`), by the member. On any generation error, the fallback page ("Hello, I'm {brand}" and the mission) is published instead.
    - `email`: the welcome email (site address, Site Editor link to the front page), at most once. A failed email does not fail the job.
  - **Resumable:** each step's outcome and the next step are written in one update, after the step's side effects. A run killed mid-step resumes there, and the step does not repeat its work:
    - the site records its job (option `mody_provisioning_job`), so a resumed job reuses it;
    - the front page records its job too (post meta `_mody_provisioning_job`);
    - the profile is looked up by site;
    - the email is marked before it is sent.
  - **One run at a time:** a single `UPDATE` claims the job. A running job counts as killed after 5 minutes without an update (`Jobs::STALE_AFTER`), and can then run again.
- [x] `PageGenerator::insert_page()`: the shared way to save generated markup. Agent drafts now use the untitled template too, so the page title no longer shows twice above the generated level-1 heading.
- [x] `scripts/provisioning-test.php`: validation, quota, a full run, a run killed at each step and resumed, generation failure and Retry generation, one real run.
- [x] Docs: mvp-spec §4, §6, §7, §11, §13; brand-profile.md; ai-engine.md; membership-tiers.md; architecture.md; local-development.md; roadmap.md; AGENTS.md.

## Acceptance criteria (spec §7)
- [x] End-to-end creation under 2 minutes: 5 to 15 s locally in real runs. The result is a live subdomain with the chosen palette and a coherent generated home page. Checked over HTTPS: HTTP 200, one level-1 heading, the preset's primary color in the CSS, the mission as the tagline.
- [x] Killing the flow at any step leaves a working site, the job resumes, and no step runs twice with side effects. The test kills a run right after each step's side effects, then resumes it: one site, one profile, one front page, one email and one model request each time.
- [x] Generation failure never blocks site creation: the fallback page is published, and Retry generation replaces it.
- [ ] A first-time user can complete the wizard without help text: M2-03.

## Results (2026-11-06, local)
- `scripts/provisioning-test.php`: all checks pass offline and with the real run.
- Real runs: 5.1 s to 15.1 s.
- **Generation failures found and fixed.** Before the fix, one real run in two ended on the fallback page. Then 1 in 6 direct generations for the same home page prompt failed all three attempts.
  - The cause was mostly one typo: `<p>Packed with seeds…` written as `<Packed with seeds…`, in 4 of 5 raw first answers.
  - The retries, at temperature 0.1, repeated it.
  - After the repair and the retries at 0.4: 6 of 6 generations were valid (two needed one retry), and the next real runs used one request.
- Other suites pass: `ai-generate-page-test.php` (real), `ai-agent-chat-test.php` (real), `ai-plugin-brand-context-test.php`, `spectra-guard-test.php`.

## Production
Deployed 2026-11-06 (commit `754e6e8`, together with the publish guard in `Agent`), after a backup in `backups/2026-11-06-pre-m2-01/`.
- The jobs table was created.
- The offline agent test, the Spectra test and the `ai` plugin brand context test pass.
- No PHP warnings, and nothing else changed (one site, its pages, title and tagline).
- `scripts/provisioning-test.php` was **not** run there, because it creates and deletes sites and a user on the production network. Ask the maintainer first.

## Open
- **Production addresses** (seen 2026-11-07): production's main site has `home` = `siteurl` = `https://mody.cloud/wp`, while locally `home` has no `/wp`. So `Pipeline::urls()` finds no suffix there, and new production sites get `siteurl` without `/wp` (`phycticio.mody.cloud`). It works: nginx rewrites `/wp-admin/…`, `/wp-includes/…` and `wp-*.php` into `/wp/` on every host. See the open question in architecture.md.
- **Production sites:** nothing starts provisioning there yet (no wizard). New sites also need the wildcard DNS and TLS for `*.mody.cloud` (mvp-spec §16, question 3).
- **The `ai` plugin on new sites:** its features and connector approvals are options of each site, so a provisioned site starts with every feature off (M3-04). Setting them is left to M4-01, with the curated list.
- **Deleting a site** does not delete its brand profile row yet (the dashboard's Delete is M1; the 60-day deletion is M1-02).
