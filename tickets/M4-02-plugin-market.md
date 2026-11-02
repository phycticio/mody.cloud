# M4-02 · Plugin market (preinstall, activate-only)

**Milestone:** M4 · **Spec:** documentation/mvp-spec.md §10, §11 · **Status:** todo

## Goal
The whole plugin market is preinstalled via Composer; members only **activate/deactivate** listed plugins from a clean market page. All plugins free, no upload/install-from-URL.

## Deliverables
- **Composer:** add the market to `app/composer.json` (wpackagist): `ultimate-addons-for-gutenberg` (Spectra) and `redirection` as the MVP seed (final membership TBD — spec §16.5). They install into `app/web/plugins/` and are network-visible.
- Table `mody_market_plugins` (spec §11): slug, name, description, `listed`, sort.
- **Market admin page** (super admin): add/list/delist market plugins (managed data only; the actual install is via Composer).
- **Market page** in member-site wp-admin (capability `mody_manage_market`): lists `listed` plugins with **Activate / Deactivate** (server-side). No upload, no install-from-URL, no search beyond the market.
- Wire the agent `activate_plugin`/`deactivate_plugin` tools (M3-01) to this same allowlist so chat and UI enforce the same rule.

## Acceptance criteria
- A member sees only the listed market plugins and can toggle them; activation works.
- There is no path (UI or REST) to upload a plugin or install from a URL on a member site.
- A delisted plugin disappears from member sites' market page.

## Open / blocking
- **Final market membership** beyond Spectra + Redirection is TBD (spec §16.5). Ship with the seed and add plugins via the admin page as they are chosen.
