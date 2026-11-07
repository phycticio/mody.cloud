# M2-03 · Brand wizard

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6, §7 · **Status:** done (2026-11-06; the browser walk-through is the maintainer's, see "Results")

Split from M2-01 on 2026-11-06. It drives the provisioning pipeline, `Provisioning\Pipeline` ([M2-01](M2-01-site-provisioning.md)).

## Goal
The 3-step, choice-based wizard that creates a site: a first-time user completes it without help text.

## Decided (2026-11-06)
- **Where:** Network Admin → **Mody** → **New site**, for super admins until M1. M1 grants the `mody_access_dashboard` capability to members (spec §3) and adds the dashboard's **+ New site** button.
- **Presets:** the six in `src/Design/Presets.php`, as they are.
- **Quota:** `Quota::can_create_site()`, 3 sites per user until M1, shown as "N of 3 sites used".
- **The logo upload is split out** into [M2-05](M2-05-wizard-logo.md) (rule 5). Members can add a logo later in the Site Editor.

## Deliverables
- [x] **Page** (`src/Wizard/WizardPage.php`, `assets/wizard.js` and `assets/wizard.css`; plain script, no build step, like the agent chat). All text is inserted with `textContent`.
  - **Step 1, your brand:**
    - site name;
    - address (the subdomain, filled in from the name until edited, lowercase letters and numbers only);
    - brand name (the site name until edited);
    - "What do you do?" (at most 200 characters);
    - three tone cards showing the same sentence in each tone (friendly checked).
  - **Step 2, your design:** six preset cards with three swatches and a mini mock (a heading in the primary color, a button in the accent color), with Mody Teal checked. A note says colors and the logo can be changed later. The live preview is [M2-04](M2-04-wizard-live-preview.md).
  - **Step 3, your first page:** a textarea with three suggestion chips, then **Create my site**.
  - **Next** checks the step's required fields, and Enter in a field means Next until the last step.
- [x] **REST endpoint** `mody-platform/v1/sites` (`src/Wizard/WizardController.php`). Logged-in users with `mody_access_dashboard`, REST nonce, own jobs only.
  - `POST sites`: validates through `Pipeline::start()`. Errors: 400 with `fields` (one message per field, shown next to it, and the wizard goes back to the first step with an error), 403 at the site limit, 409 while another creation of the user is in progress (with its job). Otherwise 202, and the job runs after the response (`fastcgi_finish_request()` on `shutdown`, like the agent chat). Without PHP-FPM (WP-CLI) it runs at once, and the answer is 200 with the finished job.
  - `GET sites/jobs/<id>`: the job as the wizard shows it: status, step, error message (without the step and code), the site's title, address, Site Editor link to the front page and dashboard link, `fallback`, `retrying`. A running job not updated for 5 minutes is shown as failed ("stopped").
  - `POST sites/jobs/<id>/run`: **Try again**, for a failed or stopped job (409 otherwise). It resumes from the step it stopped at.
  - `POST sites/jobs/<id>/retry-generation`: **Retry generation**, for a site with the fallback page (409 otherwise, or while a retry runs).
- [x] **Following the job:** the page polls every 1.5 s and ticks the steps off ("Writing your first page (this can take a minute)"). It then shows buttons to the site, the Site Editor and the site's dashboard, or the error with **Try again** and **Create a different site**. With the fallback page, it shows a note and **Retry generation**. After a reload it picks up the user's unfinished creation from the last day (`Jobs::unfinished_for_user()`).
- [x] `scripts/wizard-test.php`.
- [x] Docs: brand-profile.md ("Wizard steps"), mvp-spec §3, §6, §13, roadmap.md, AGENTS.md.

## Acceptance criteria (spec §6, §7)
- [ ] A first-time user can create a site without reading any help text (3 steps, all prefilled): **to check in a browser** (see "Results").
- [x] Every field is validated server-side; errors show next to their field (400 with `fields`, tested).
- [x] Reloading the page while a site is being created picks the job up again (the page's settings carry it, tested).
- [x] A failed generation shows the site with its fallback page and a working **Retry generation** (tested).

## Results (2026-11-06, local)
- `scripts/wizard-test.php`: all checks pass.
  - **The page:** steps, fields, cards, quota, settings, who may open it.
  - **The endpoint:** permissions (401 logged out, 403 without the capability), field errors, a full creation with the Plum preset, the site's links, another user's job (404), a creation in progress (409), the site limit (403), Retry generation, Try again on a failed and on a stopped job.
  - **Over HTTP:** the page loads in Network Admin (200). One real creation: POST answered 202 in 0.3 s, and the site was ready 14.4 s later with a generated page.
- The other suites pass: `provisioning-test.php`, `ai-agent-chat-test.php` (offline), `spectra-guard-test.php`.
- **Not checked by the tests:** clicking through the page itself. There is no browser in the test setup, and the script was only checked with `node --check` and read through. Walk through it once in a browser: Network Admin → Mody → New site.
