# Infomaniak Newsletter integration

Related: [membership-tiers.md](membership-tiers.md) (where the per-tier caps come from) and [brand-profile.md](brand-profile.md#site-creation-flow) (the step that registers the domain).

## Credit model

- 1 send = 1 newsletter to 1 subscriber.
- Infomaniak products include free monthly sends. Purchased packs do not expire.
- Infomaniak Newsletter has an API to automate list management and sending.

## Legal requirement

Double opt-in and explicit consent are mandatory. Purchased lists are forbidden. This reinforces the GDPR message of the association.

## Control per tier

Use Infomaniak's **"Set a sending limit"** function (a monthly cap per domain) with the role's `newsletter_sends_per_month` value:

| Tier | `newsletter_sends_per_month` |
| --- | --- |
| Collaborator | 30 |
| Member | 500 |
| Patron | 1,000 |
| Dedicated VPS | By consumption |

During site creation the platform registers the site's Newsletter domain and sets this cap (step 8 of the flow in [brand-profile.md](brand-profile.md#site-creation-flow)).

## Open questions

- The exact API calls for registering a domain and setting the cap are not in the briefing. Confirm them against Infomaniak's API reference.
- Where the API token is stored. It must come from the environment or a secret store, never from the repository ([constraints.md](constraints.md), rule 6).
- Which sign-up form the sites embed, and how double opt-in is verified, is not specified. Milestone 0.1 needs an email form on the "under construction" page ([roadmap.md](roadmap.md)), so this is the first place it matters.
