# The legal pages: legal notice, terms of service, privacy policy

**Status:** built and deployed 2026-11-13 (`1039407`); **published on production the same day** (pages 274, 275 and 276), with the home page's links in all four languages. All four seller options are set on production and locally. **Asked by** the maintainer on 2026-11-13 ("tell me what's next and let's go to it"), after card payments: real money needs these pages first.

## What
- `scripts/legal-pages.php` (run on the main site, like `scripts/home-page.php`; it replaces the pages, so edit the script) makes three pages:
  - **Legal notice** (`/legal-notice/`): Spain's LSSI-CE, art. 10: name, tax ID (NIF), address, email, activity.
  - **Terms of service** (`/terms/`): the service and its real limits, the price per site with VAT, the launch offer, payments, read-only then deletion, cancelling, the 14-day right of withdrawal (in full, from the first payment) with the model withdrawal form, content, acceptable use, the members' visitors' data (GDPR art. 28), availability, liability, changes, Spanish law.
  - **Privacy policy** (`/privacy/`): GDPR art. 13: the controller, what is kept about members and why, visitors (no cookies, no address kept, country and city, 13 months), cookies, where data is and the providers (Infomaniak, Mollie, Brevo, Cloudflare, Wordfence), how long, rights and the AEPD.
- **The seller's details come from network options, never the repository:** `mody_seller_name`, `mody_seller_address` (set 2026-11-13), `mody_seller_tax_id` and `mody_seller_email` (still to set). Until all four exist the pages are drafts; then the script publishes them. The numbers (price, space, tokens, days, retention) come from the code.
- **Links, once published:** a line at the end of the home page (all four languages: `Legal notice`, `Terms of service`, `Privacy policy` in `scripts/home-page-translations.json`; the translation script skips a line made only of links), under the signup chat ("By creating your site you accept…"), on the pay page and the Add your card form (`Billing::legal_links()`), and in the terms email (`Billing::legal_url()`).
- **Also:** the home page's note on services outside Europe no longer names Gravatar (gone since 2026-11-11): three things, in all four languages.

## Reviewed
- **The texts were reviewed** (the maintainer, 2026-11-14: "Legal review is done").

## Open
- **Cloudflare hides the email address** in the pages' HTML (its email obfuscation): visitors with JavaScript see it, a page read without JavaScript shows "[email protected]". Turn it off in Cloudflare (Scrape Shield) if the address must be readable everywhere.
- **The pages in Spanish, German and Italian:** English only for now; the translated home pages link to them.
- **To check:** whether Cloudflare and Wordfence (Defiant) are certified under the EU–US Data Privacy Framework (the privacy policy says "where the provider is certified, or standard contractual clauses").
