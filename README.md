# FourBag

FourBag is a backyard family game and scalable league network for bars, breweries, restaurants, Stonefellow's locations, and FourBag-hosted events.

## Current platform

The repository contains a real PHP/MySQL FourBag application with league operations, product-registration credit, accounts, and venue access control:

- PHP 8.2 application
- MySQL/MariaDB schema + ordered migration runner
- venue and league-season records
- individual player registration with capacity enforcement
- solo / friends / full-team join intent
- FourBag board-purchase checkout intent with payment-safe registration credit
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
- venue/season authorization for roster, team, schedule, scoring, and championship operations
- admin bootstrap script
- authenticated venue/operator dashboard at `public/operator.php`
- legacy operator-key fallback during migration to account-based access
- equipment kits and venue assignments
- sponsors and sponsorship campaigns
- FourBag Live broadcast records + audience metrics
- FourBag product orders
- public player-facing league page
- JSON API with public and role-protected actions
- GitHub Actions PHP lint, smoke checks, MySQL migrations, league integration, and access-control integration tests

## League contract represented

- Standard league: 8 weeks, 8 teams, 4 players per team, 4 boards
- Seven round-robin weeks followed by a Week 8 championship
- Host venue keeps player registration revenue
- Host venue keeps food and drink revenue
- Weekly beer sponsor may donate a keg; host keeps $6 sponsored-pint revenue
- Host venue pays for league shirts
- FourBag provides league format, software, and equipment
- FourBag owns league sponsorship revenue
- FourBag may supply referees, hosts, and production crew for live broadcasts
- FourBag set: $199, one board + four bags
- Board cost assumption: $49
- Bag cost assumption: $3 each
- Replacement four-bag set retail: $24.99
- A completed FourBag set purchase includes the purchaser's individual league registration
- Selecting the board-purchase option creates a pending $199 order; the registration remains `awaiting_board_payment` until the order is marked paid, then becomes `included_with_board`

## Local setup

Set:

- `FOURBAG_DB_DSN` — for example `mysql:host=127.0.0.1;dbname=fourbag;charset=utf8mb4`
- `FOURBAG_DB_USER`
- `FOURBAG_DB_PASSWORD`
- `FOURBAG_OPERATOR_KEY` — optional legacy operator fallback during the account migration

Then run:

```bash
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080` for the public league page or `http://127.0.0.1:8080/operator.php` for authenticated venue league operations.

## Bootstrap the first administrator

Do not hard-code administrator credentials. Set temporary environment variables and run the bootstrap script once:

```bash
export FOURBAG_BOOTSTRAP_EMAIL='admin@example.com'
export FOURBAG_BOOTSTRAP_PASSWORD='use-a-long-unique-password'
export FOURBAG_BOOTSTRAP_NAME='FourBag Administrator'
php scripts/create-admin.php
```

After the administrator exists, remove the bootstrap password from the shell/environment where practical. The script refuses to silently promote an existing non-admin account.

## Authentication and access

Passwords use PHP `password_hash()` / `password_verify()`. Login creates a cryptographically random session token; only its SHA-256 hash is stored in the database. Browser sessions use an HttpOnly, SameSite=Strict cookie and use the Secure flag automatically under HTTPS.

Roles:

- `admin` — FourBag network administration and all venue access
- `crew` — network-wide scoring access for FourBag production/official crews
- venue `owner` / `manager` — manage the assigned venue and its seasons
- venue `scorekeeper` — record scores for the assigned venue
- `user` — normal account without operator privileges

`FOURBAG_OPERATOR_KEY` remains supported only as a temporary compatibility fallback for existing operator tooling and should be removed after deployment/account migration is complete.

## API split

Public actions include season discovery, schedules, standings, account registration/login, and player registration while a season is `registration_open`.

Role-protected actions include roster access, team building, schedule generation, score entry, championship seeding, venue/season creation, venue-role administration, and the temporary `order.board_paid` completion bridge.

The `order.board_paid` action is a protected development/manual-payment bridge. A production payment provider/webhook should replace manual completion in the payment phase.

## Next phases

Production payments and host-fee billing, player account/profile linking, venue invitations and account management UI, sponsor/advertiser self-service, equipment logistics, crew scheduling, and production-grade FourBag Live integrations remain for subsequent phases.
