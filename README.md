# FourBag

FourBag is a backyard family game and scalable league network for bars, breweries, restaurants, Stonefellow's locations, and FourBag-hosted events.

## Current platform

The repository contains a real PHP/MySQL FourBag application with league operations, account-based venue access, FourBag product checkout, and host-fee billing:

- PHP 8.2 application
- MySQL/MariaDB schema + ordered migration runner
- venue and league-season records
- individual player registration with capacity enforcement
- solo / friends / full-team join intent
- automatic team formation with requested-group preservation where capacity allows
- 7-week round-robin schedule generation for the standard 8-team league
- live/final score entry with score audit history
- calculated standings and tie-break ordering
- Week 8 top-four championship bracket
- automatic semifinal-winner advancement into the final
- automatic champion + completed-season state after the final
- secure user accounts with password hashing
- hashed 14-day login sessions stored server-side
- FourBag system roles: user, crew, admin
- venue roles: owner, manager, scorekeeper
- venue/season authorization for roster, team, schedule, scoring, championship, and billing operations
- authenticated venue/operator dashboard at `public/operator.php`
- network administration at `public/admin.php`
- venue host-fee billing portal at `public/billing.php`
- FourBag host-fee administration at `public/host-fees.php`
- Stripe-hosted checkout for FourBag board purchases and host-fee invoices
- signed Stripe webhook verification with a 5-minute timestamp tolerance
- payment-attempt and webhook-event persistence
- duplicate-webhook protection and order/payment idempotency
- one-time hashed public checkout tokens for board orders
- board purchase registration credit activated only after verified payment
- host-fee invoice creation with duplicate active-invoice prevention
- admin-only, opt-in manual board-payment completion for emergency/development use
- equipment kits and venue assignments
- sponsors and sponsorship campaigns
- FourBag Live broadcast records + audience metrics
- public player-facing league page
- JSON API with public and role-protected actions
- GitHub Actions lint, smoke, migration, league, access-control, and payment/billing integration gates

## League contract represented

- Standard league: 8 weeks, 8 teams, 4 players per team, 4 boards
- Seven round-robin weeks followed by a Week 8 championship
- Host venue keeps player registration revenue
- Host venue keeps food and drink revenue
- Weekly beer sponsor may donate a keg; host keeps $6 sponsored-pint revenue
- Host venue pays for league shirts
- Host venue pays FourBag a league host fee
- FourBag provides league format, software, and equipment
- FourBag owns league sponsorship revenue
- FourBag may supply referees, hosts, and production crew for live broadcasts
- FourBag set: $199, one board + four bags
- Board cost assumption: $49
- Bag cost assumption: $3 each
- Replacement four-bag set retail: $24.99
- A completed FourBag set purchase includes the purchaser's individual league registration

Direct $50 league registration is currently represented as venue revenue and remains `pending` until handled by the venue. FourBag does not currently route that $50 through the FourBag Stripe account. This preserves the operating model in which the host venue owns player-registration revenue. A future connected-account/payment-routing phase can automate venue-collected registration payments without changing that business rule.

## Payment flow

### FourBag set + included registration

When a player selects the $199 FourBag set during league registration:

1. FourBag creates or reuses a pending `fourbag_set` order.
2. The league registration is saved as `awaiting_board_payment` and is **not** treated as paid.
3. The browser receives a one-time checkout token; only its SHA-256 hash is stored.
4. `checkout.create` exchanges that token for a Stripe-hosted Checkout Session.
5. Stripe posts a signed event to `public/webhook-stripe.php`.
6. FourBag verifies the Stripe signature, timestamp, session, order, amount, and currency.
7. Only then is the order marked paid and the registration changed to `included_with_board`.

Reloading or repeating checkout does not intentionally create multiple active payment paths for the same pending order. Duplicate Stripe events are recorded once and handled idempotently.

### Venue host fees

FourBag administrators create a host-fee invoice per league season from `public/host-fees.php`. A season cannot have a second active host-fee invoice while its prior invoice is open or paid.

Venue owners/managers can open `public/billing.php`, review host-fee invoices, and launch secure hosted checkout. Successful verified payment updates both the backing `league_host_fee` order and the venue invoice.

## Local setup

Required database variables:

```bash
export FOURBAG_DB_DSN='mysql:host=127.0.0.1;dbname=fourbag;charset=utf8mb4'
export FOURBAG_DB_USER='fourbag'
export FOURBAG_DB_PASSWORD='replace-me'
```

For Stripe-hosted checkout:

```bash
export FOURBAG_BASE_URL='http://127.0.0.1:8080'
export FOURBAG_STRIPE_SECRET_KEY='sk_test_replace_me'
export FOURBAG_STRIPE_WEBHOOK_SECRET='whsec_replace_me'
```

Optional transition/development variables:

```bash
export FOURBAG_OPERATOR_KEY='long-random-legacy-key'
export FOURBAG_ALLOW_MANUAL_PAYMENT_COMPLETION='0'
```

`deploy.env.example` contains the complete environment template. The application reads process/web-server environment variables directly; it does not automatically parse that example file.

Then run:

```bash
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public
```

Useful pages:

- `/` — public league discovery, registration, and FourBag set checkout
- `/operator.php` — venue league operations
- `/billing.php` — venue owner/manager host-fee invoices and payment
- `/admin.php` — FourBag network account/venue administration
- `/host-fees.php` — FourBag administrator host-fee creation and invoice review
- `/checkout-complete.php` — payment confirmation landing page
- `/webhook-stripe.php` — Stripe webhook endpoint

## Stripe webhook setup

Configure the Stripe endpoint to post to:

```text
https://YOUR-DOMAIN/webhook-stripe.php
```

The payment service handles paid Checkout Session events, asynchronous payment success, and checkout expiration. Store the Stripe webhook signing secret in `FOURBAG_STRIPE_WEBHOOK_SECRET` and never expose the Stripe secret key or webhook secret to browser JavaScript.

The browser success page is intentionally not trusted as proof of payment. Order state changes come from the verified webhook.

## Bootstrap the first administrator

Do not hard-code administrator credentials. Set temporary environment variables and run the bootstrap script once:

```bash
export FOURBAG_BOOTSTRAP_EMAIL='admin@example.com'
export FOURBAG_BOOTSTRAP_PASSWORD='use-a-long-unique-password'
export FOURBAG_BOOTSTRAP_NAME='FourBag Administrator'
php scripts/create-admin.php
```

After the administrator exists, remove the bootstrap password from the environment where practical. The script refuses to silently promote an existing non-admin account.

## Authentication and access

Passwords use PHP `password_hash()` / `password_verify()`. Login creates a cryptographically random session token; only its SHA-256 hash is stored in the database. Browser sessions use an HttpOnly, SameSite=Strict cookie and automatically add the Secure flag under HTTPS.

Roles:

- `admin` — FourBag network administration, host-fee issuance, and all venue access
- `crew` — network-wide scoring access for FourBag production/official crews
- venue `owner` / `manager` — manage the assigned venue, its seasons, and venue invoices
- venue `scorekeeper` — score access for the assigned venue, without roster/billing management
- `user` — normal account without operator privileges

`FOURBAG_OPERATOR_KEY` remains only as a temporary compatibility bridge for selected legacy league-operation actions. It cannot administer accounts, venue roles, host-fee invoices, or billing.

`order.board_paid` is no longer available through the legacy key. It is admin-only and additionally disabled unless `FOURBAG_ALLOW_MANUAL_PAYMENT_COMPLETION=1`. Production should leave that flag off and use verified payment webhooks as the authority.

## CI / validation

The GitHub Actions workflow validates:

- PHP syntax
- application contract smoke checks
- ordered MySQL migrations
- complete league lifecycle integration
- account/session/venue access control
- Stripe/payment-provider contract behavior
- board-order payment activation
- duplicate/idempotent webhook behavior
- host-fee invoice creation and payment

The workflow also builds a deployable production ZIP after all validation gates pass.

## Next phases

The strongest next product phases are player profiles and registration-history linking, venue onboarding/invitations, connected-account routing for venue-collected registration payments, sponsor/advertiser self-service, equipment logistics, crew scheduling, and production-grade FourBag Live broadcast integrations.
