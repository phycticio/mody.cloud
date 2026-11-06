# M2-03 · Brand wizard

**Milestone:** M2 · **Spec:** documentation/mvp-spec.md §6, §7 · **Status:** todo

Split from M2-01 on 2026-11-06. The pipeline it calls is built: `Provisioning\Pipeline` ([M2-01](M2-01-site-provisioning.md)).

## Goal
The 3-step, choice-based wizard that creates a site: a first-time user completes it without help text.

## Decided (2026-11-06)
- **Where:** Network Admin → **Mody** → **New site**, for super admins until M1. M1 opens it to members through the `mody_access_dashboard` capability (spec §3) and adds the dashboard's **+ New site** button.
- **Presets:** the six in `src/Design/Presets.php`, as they are.
- **Quota:** `Quota::can_create_site()`, 3 sites per user until M1. Show it before step 1 ("2 of 3 sites used"), and refuse with its message.

## Deliverables
- **Step 1, identity:**
  - the site title and its address (slug, prefilled from the title);
  - the brand name, prefilled from the title;
  - "What do you do?" in one sentence (at most 200 characters; it becomes the tagline);
  - three tone cards, each showing the same sentence in that tone.
- **Step 2, design:**
  - six preset cards (name, three swatches, a mini mock);
  - an optional logo upload. The pipeline does not take a logo yet: add a step, or extend `profile`, to copy the file into the new site's media library and set `site_logo` and the profile's `logo_url`;
  - Inter, fixed.
  - The live preview is [M2-04](M2-04-wizard-live-preview.md).
- **Step 3, first page:** one textarea with three suggestion chips, then **Create my site**.
- **Validation:** server-side through `Pipeline::validate()`. Its `mody_invalid_input` error carries one message per field (`fields`), shown next to each field.
- **Running the job:** **Create my site** calls `Pipeline::start()`, then runs `Pipeline::run()` after the response, like the agent's background jobs (`fastcgi_finish_request()` on `shutdown`, ai-engine.md "Background jobs"). The page polls the job and shows the steps as they pass.
  - On `done`: the site's address, and buttons to the site and the Site Editor.
  - On `failed`: the error and a **Try again** button (`Pipeline::run()` resumes the job).
  - With the fallback page (`result.fallback`): **Retry generation** (`Pipeline::retry_generation()`).
- Nonces and capability checks on every action (rule 6).

## Acceptance criteria (spec §6, §7)
- A first-time user can create a site without reading any help text: 3 steps, all prefilled.
- Every field is validated server-side; errors show next to their field.
- Reloading the page while a site is being created picks the job up again.
- A failed generation shows the site with its fallback page and a working **Retry generation**.
