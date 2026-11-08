# Next steps

The ordered list of what comes next. Each item links to its ticket, and the details live there. Update this list whenever a ticket is finished or the order changes.

**Last updated:** 2026-11-08, after M4-02 part A1 and the admin colors were deployed, and [updates only through the repository](updates-through-composer-only.md) was built.

## Owed by the maintainer (browser checks the tests cannot do)
- **M4-02 part A1:** log in as `member` on `https://alpha.mody.local/wp/wp-admin/plugins.php`. The screen is empty today, because every installed plugin is a platform plugin. To see a row, follow the fixture note in [M4-02](M4-02-plugin-market.md) ("Not checked by the tests").
- **Admin colors:** open the agent bubble in the Site Editor and send a message. Then change your scheme in the profile, reload, and check that the message color follows ([admin-colors.md](admin-colors.md)).

## Queue
1. **[M4-02](M4-02-plugin-market.md) part A2: the super admins' hidden list.** Network Admin → Mody → Plugins, stored in the network option `mody_hidden_plugins`. The maintainer postponed it on 2026-11-08 ("work on this later").
2. **[M4-02](M4-02-plugin-market.md) part B: the agent's plugin abilities**, `mody/activate-plugin` and `mody/deactivate-plugin`. It follows A2, because the agent applies the same hidden rule.
3. **Stale checks in `scripts/ai-agent-chat-test.php`** (found 2026-11-08). In the real-request part, three checks compare the agent's button URLs with editor URLs that lack `mody-chat=open`. `Agent.php` adds that parameter to every admin button. Wrap the expected URLs in `EditorBubble::open_url()`, then rerun the real requests once.
4. **[M1-01](M1-01-registration-trial-billing.md): registration, trial and Mollie billing.** Blocked until:
   - the **WP-Cron on subsites** decision is made ([architecture.md](../documentation/architecture.md#open-questions), Open questions). M1's hourly billing and sweep jobs depend on it;
   - **Mollie test keys** exist;
   - **outgoing mail** works in production. Registration and billing emails need an EU SMTP relay, not chosen yet ([architecture.md](../documentation/architecture.md#deviations-to-resolve), deviation 2).
5. **[M1-02](M1-02-past-due-readonly-deletion.md): past-due sites read-only, then deleted.** It also adds the agent's past-due check.
6. **The remaining WordPress.org calls.** Decide whether Wordfence's scans, Site Health → Info and WP-CLI keep calling the update checks, and how you hear about security releases. Also decide on the Dashboard's other WordPress.org calls: the Events and News widget, and the browser and PHP version checks. The details are in [updates-through-composer-only.md](updates-through-composer-only.md), Open questions.
7. **[M5-01](M5-01-hardening-launch.md): hardening and launch.** It includes production subsites (wildcard DNS, certificate and proxy host), the `home` URL without `/wp`, a backup and restore drill, and the production host question ([architecture.md](../documentation/architecture.md#deviations-to-resolve)).

## Not scheduled
- [mody-blocks-from-spectra.md](mody-blocks-from-spectra.md): our own `mody/*` blocks to replace Spectra. No milestone yet.
- [move-local-docker-to-colima.md](move-local-docker-to-colima.md): developer environment, in progress since 2026-11-03.
