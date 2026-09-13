# FourBag

FourBag is a backyard family game and scalable league network for bars, breweries, restaurants, Stonefellow's locations, and FourBag-hosted events.

## Current platform

The repository contains the real database-backed FourBag application foundation and the Phase 2 league-operations engine:

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
- equipment kits and venue assignments
- sponsors and sponsorship campaigns
- FourBag Live broadcast records + audience metrics
- FourBag product orders
- public player-facing league page
- venue/operator league dashboard at `public/operator.php`
- JSON API with protected operator actions
- GitHub Actions PHP lint, smoke checks, MySQL migrations, and end-to-end league integration tests

## Current business rules represented

- Standard league: 8 weeks, 8 teams, 4 players per team, 4 boards
- Seven round-robin weeks followed by a Week 8 championship
- Host venue keeps player registration revenue
- Host venue keeps food and drink revenue
- Weekly beer sponsor may donate a keg; host keeps $6 sponsored-pint revenue
- Host venue pays for league shirts
- FourBag provides league format, software and equipment
- FourBag owns league sponsorship revenue
- FourBag may supply referees, hosts and production crew for live broadcasts
- FourBag set: $199, one board + four bags
- Board cost assumption: $49
- Bag cost assumption: $3 each
- Replacement four-bag set retail: $24.99
- A completed FourBag set purchase includes the purchaser's individual league registration
- Selecting the board-purchase option creates a pending $199 order; the registration remains `awaiting_board_payment` until the order is marked paid, then becomes `included_with_board`

## Local setup

Set these environment variables:

- `FOURBAG_DB_DSN` — for example `mysql:host=127.0.0.1;dbname=fourbag;charset=utf8mb4`
- `FOURBAG_DB_USER`
- `FOURBAG_DB_PASSWORD`
- `FOURBAG_OPERATOR_KEY` — required for roster and league-management API actions

Then run:

```bash
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080` for the public page or `http://127.0.0.1:8080/operator.php` for venue league operations.

## API split

Public/read actions include season discovery, schedules, standings and player registration. Operator-only actions require the `X-FourBag-Operator-Key` request header and include roster access, team building, schedule generation, score entry, championship seeding, venue creation, season creation, and the temporary `order.board_paid` completion hook.

The `order.board_paid` action is an operator-protected bridge for development and manual payment confirmation. A production payment provider/webhook should replace manual completion in the payment phase.

## Next phases

Authentication and venue/user roles, payment processing, host-fee billing, FourBag retail checkout, sponsor/advertiser self-service, equipment logistics, crew scheduling, and production-grade broadcast integrations remain for subsequent phases.
