# Checking card payments in Mollie's sandbox

The PHP test (`scripts/billing-test.php`) answers for Mollie. This is the check against Mollie's real test mode, done by hand. No real money moves with a `test_` key.

## Once

1. In Mollie's dashboard, in **Test mode**: turn **Cards** on, and copy the test API key (`test_…`).
2. Put it in `.env` as `MOLLIE_API_KEY=test_…`. Never commit it, and never paste it into a chat.
3. Run `docker compose up -d wordpress`, so the container reads it, then `docker compose restart nginx`: the new container has a new address, and Nginx answers 502 until it restarts.

## Add a card

1. Log in as a member (not a super admin) and open **Dashboard → Plan & billing** on their site. It says "Test mode: no real money moves."
2. Tick the terms, then **Add your card**. Mollie's test checkout opens.
3. Pay with a test card (Mollie's dashboard lists them, for example `4111 1111 1111 1111`, any future date, any CVC), and choose **Paid** as the outcome.
4. Back on Plan & billing: "Your card is added", the card's brand and last digits, and the terms email in Mailpit (`https://mail.mody.local`).
5. Also try **Failed** and **Canceled** at the checkout: no card is added, and the page says so.

Locally Mollie cannot call the webhook (`mody.local` is not on the internet), so the page asks Mollie when the member comes back, and the daily run asks about anything still waiting.

## Charge it

1. Make the account due now:
   `./bin/wp eval 'Mody\Platform\Members\Members::update( <user ID>, array( "billing_due_at" => gmdate( "Y-m-d H:i:s", time() - 60 ) ) );'`
2. `./bin/wp mody-billing run`: one charge, `charged: 1`.
3. A few minutes later, `./bin/wp mody-billing run` again (it asks Mollie about payments waiting more than 5 minutes), then `./bin/wp mody-billing status <user ID or email>`: the plan is `paid`, `billing_due_at` is a month on, and the charge is in Mollie's dashboard under the customer.

## Withdraw

On Plan & billing, **Withdraw from the contract** (tick, then the button): the charge is refunded in full in Mollie's dashboard, the account is `canceled`, and Mailpit has the email.

## On production, once before launch

With the test key as `MOLLIE_API_KEY` in production's `.env`, members are offered nothing (a sandbox key on production is for this check only). To see Mollie's real webhook:

1. On the server, make a throwaway member (a user with a `mody_members` row), then `wp mody-billing card-check <their email>`: it prints Mollie's test checkout link and says Mollie will call `https://mody.cloud/wp-json/mody-platform/v1/mollie/webhook`.
2. Pay at the link with a test card, outcome **Paid**. Do not come back to the site from the checkout.
3. `wp mody-billing status <their email>`: the card check is `paid, applied`, applied by the webhook alone. Nginx's log shows Mollie's `POST /wp-json/mody-platform/v1/mollie/webhook`.
4. In Mollie's dashboard, refund the 0.01 EUR: the webhook comes again, and `status` shows it refunded.
5. Remove the throwaway member, then put the live key in `.env` (`docker compose up -d wordpress`, then `docker compose restart nginx`).

## A paid sign-up (after the launch offer)

1. Mark the 50 places as taken: `./bin/wp site option update mody_promo_given 50` (note the value before, and put it back after; delete the option if there was none).
2. Sign up on `https://mody.local/register/` with a new email. The chat asks the same four questions; Mailpit gets "pay and create your site".
3. Open the link: the pay page shows the site, 7.99 EUR a month, the terms to tick, and **Pay with card**. Pay with a test card, outcome **Paid**.
4. Back on the page: "Paid, thank you! Your site is ready". Mailpit has the welcome email (password link) and the terms; `./bin/wp mody-billing status <email>` shows `paid`, `active`, a charge next month.

