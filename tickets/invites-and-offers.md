# Invite friends, and an Offers page

**Builds on:** [pricing-per-site.md](pricing-per-site.md) (the launch offer), [M1-03](M1-03-chat-signup-and-editor-tour.md) (the signup chat), [mody-dashboard.md](mody-dashboard.md) · **Docs:** [membership-tiers.md](../documentation/membership-tiers.md) · **Status:** planned and built 2026-11-14 on `feature/invites-and-offers`.

## Asked for
By the maintainer on 2026-11-14:

> I would like to offer the first spots to my current clients offer them a free website. For six months so they do what they want with that page.

Then:

> In the dashboard of every user there should be an invite friends to mody. Inviting links should store the reference as a point for the user who invited the new one.

> Let's create a settings page for the administration of offers like this.

## Decisions (the maintainer's, 2026-11-14)
1. **Every member's Dashboard gets an Invite friends section** with a personal link (`mody.cloud/register/?ref=<code>`). A signup through it records who invited them.
2. **A point counts when the invited friend first pays**: their first card charge, or the first month of a paid sign-up. Fake signups can't farm points. Until then the friend shows as "joined".
3. **A point gives nothing yet.** Points are counted and shown, and the Dashboard says so (rule 7: nothing is promised). The reward is decided later.
4. **An invited friend gets what every signup gets**: a place of the launch offer while it has places, otherwise a paid sign-up.
5. **The Offers page** (Network Admin → Mody → Offers, super admins only) manages the launch offer and invites. It replaces the constants (50 places, 6 free months, 3 months to take them), keeping those values as defaults.

## How the maintainer's clients get the first places
The Offers page can set the launch offer to **"invited people only"**. Signups without an invite link then can't take a place; they pay or read that paid signups open soon. The maintainer sends his own invite link (on his Dashboard) to his clients. When they have joined, he switches the offer to **everyone**. The three months start with the first place given, unless a start date is set on the page.

## What the Offers page sets
- **The launch offer:**
  - On or off.
  - Who can take a place: everyone, or invited people only.
  - Places, free months, and how many months it takes accounts.
  - Its start date (empty: the first place given starts it).
  - The places given so far (read only).
- **Invites:** on or off. Off hides the Invite friends section, and links stop recording who invited. Points already counted stay.

A change applies to new signups only: accounts already given keep their `free_until`. The home page and the legal pages state the numbers in their text, so after a change, the page says to run `scripts/home-page.php` and `scripts/legal-pages.php` again.

## How it works
- **Settings:** the network option `mody_offers` (`Members\Offers`). Members reads its numbers from there; the constants stay as defaults.
- **The code:** each user's invite code is the user meta `mody_invite_code`: 10 random lowercase letters and digits, made the first time their section shows. It is not their name or email.
- **The reference:** `mody_members` gains `invited_by` (the inviter's user ID) and `invite_point_at` (when the point was earned). It's table version 7.
  - The chat and the form carry the code from the page's address into the signup. No cookie is used: a visitor who leaves the page without signing up loses the link.
  - An unknown code is ignored.
  - The inviter must still exist and have a member row, or be a super admin.
- **The point:** Billing gives it when a friend's `signup` or `charge` payment is paid, once. A withdrawal (the 14-day full refund) takes it back.
- **The section** shows the link with a Copy button, how many joined through it, and the points. It never shows who joined: that would tell whether an email has an account.

## Not done (open questions)
- **What a point is worth.** It might be free months, or a place for a friend.
- **A chargeback** doesn't take the point back. Only the withdrawal does.
- **More offers** than the launch offer, such as a named offer for a group with its own link.

## Results (2026-11-14, locally)
- `scripts/invites-test.php`: 41 checks pass.
  - **Offers:** the defaults, saved values within bounds, and Members reading them.
  - **The offer off, or for invited people only:** the messages.
  - **Codes:** random and kept. Members and super admins can invite; unknown codes and invites off are ignored.
  - **Signups:** with a link the account takes a place and records its inviter, even when the offer is for invited people only. Without a link, or with a wrong one, it is refused. New free months apply only to new accounts.
  - **The code travels:** the page carries it in the chat and the form, and the REST check uses it.
  - **Points:** given once, taken back by a withdrawal, and counted.
  - **The Dashboard section** never shows who joined. **The Offers page** is for super admins only.
- `scripts/billing-test.php` gains three checks:
  - an invited account's first paid charge gives its inviter the point (through the fake Mollie's webhook);
  - its withdrawal takes the point back;
  - an invited paid sign-up records its inviter, and its first month gives the point.
- Regression checks pass: `members-test` (the two new columns), `registration-test`, `billing-test`, `dashboard-test` (Invite friends among the sections, table version 7), `onboarding-test` and `security-signup-test`.
- **In a browser:** the Offers page, the Invite friends section on the main site's Dashboard, and `/register/?ref=<code>` with the chat.

## On production (to do, after the deploy)
1. Table version 7 installs on the first request: the two columns are added.
2. **For the maintainer's clients first:** in Network Admin → Mody → Offers, choose "Invited people only" and save. Then copy the link from Dashboard → Invite friends on mody.cloud and send it to the clients.
3. Once they have joined, choose "Everyone". If the three months should count from the public launch rather than the first client, set **Start** to the launch day.
