# Membership tiers and role gating

Four tiers. Each fee covers the **real cost** of serving that profile (Hetzner hosting, Infomaniak Newsletter credits, AI and maintenance) with no profit margin. See [project-overview.md](project-overview.md#economics) for the economics.

| Tier | Role slug | Fee per month | Sites | Newsletter sends per month | Disk |
| --- | --- | --- | --- | --- | --- |
| Collaborator | `collaborator` | 5 EUR | 1 | 30 | 1 GB |
| Member | `member` | 15 EUR | 10 | 500 | 5 GB |
| Patron | `patron` | 50 EUR | 30 | 1,000 | 8 GB |
| Dedicated VPS | `dedicated_vps` | At cost | Per VPS resources | By consumption | Per VPS |

The briefing calls these tiers Colaborador, Socio, Patrono and VPS dedicado. The role slugs are translated from the briefing's Spanish identifiers (**Inference**, change them freely before implementation).

## Gating logic (pseudocode from the briefing, translated)

```python
ROLES = {
  "collaborator": {"max_sites": 1,  "newsletter_sends_per_month": 30,   "disk_gb": 1},
  "member":       {"max_sites": 10, "newsletter_sends_per_month": 500,  "disk_gb": 5},
  "patron":       {"max_sites": 30, "newsletter_sends_per_month": 1000, "disk_gb": 8},
  "dedicated_vps": {"limits": "by_vps_resources"}   # no fixed caps
}

def can_create_site(user) -> bool:
    if user.role == "dedicated_vps":
        return vps_resources_available(user.vps)   # real measurement
    limit = ROLES[user.role]["max_sites"]
    return count_sites(user.id) < limit

# Newsletter sends: set a monthly cap per domain via the Infomaniak API
# to the role's "newsletter_sends_per_month" value (see newsletter.md).
```

This is pseudocode and not a language decision. The code that enforces it has not been placed yet (**open question**, see [architecture.md](architecture.md#open-questions)).

## Dedicated VPS

- Never describe this tier as "unlimited". Limits are defined by the VPS resources.
- The member or collective manages sites inside their own Hetzner VPS.
- The system measures consumption (CPU, RAM, disk, bandwidth). When a threshold is reached (for example 80%) it raises an alert to negotiate an expansion at real cost.
- Any proxy admin interface on that VPS must not be public. Restrict it by IP allowlist or basic auth.

## Open questions

- How disk quotas are measured and enforced is not specified. In Multisite there is a per-site upload quota that could be a starting point (**inference**).
- Sending caps are enforced on the Infomaniak side. See [newsletter.md](newsletter.md).
- The `can_create_site` check is the first step of the site creation flow in [brand-profile.md](brand-profile.md#site-creation-flow).
