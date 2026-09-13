# FourBag

FourBag is a backyard family game and scalable league network for bars, breweries, restaurants, Stonefellow's locations, and FourBag-hosted events.

## Phase 1 foundation

This repository now includes the first real database-backed FourBag application layer:

- PHP 8.2 application
- MySQL/MariaDB schema + migration runner
- venue and league-season records
- individual player registration
- solo / friends / full-team join intent
- FourBag board-purchase registration inclusion
- teams, team membership and match data model
- equipment kits and venue assignments
- sponsors and sponsorship campaigns
- FourBag Live broadcast records + audience metrics
- FourBag product orders
- public player-facing league page
- JSON API
- GitHub Actions PHP lint + smoke checks

## Current business rules represented

- Standard league: 8 weeks, 8 teams, 4 players per team, 4 boards
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
- A FourBag set purchase includes the purchaser's individual league registration

## Local setup

Set these environment variables for the database connection:

- `FOURBAG_DB_DSN` — for example `mysql:host=127.0.0.1;dbname=fourbag;charset=utf8mb4`
- `FOURBAG_DB_USER`
- `FOURBAG_DB_PASSWORD`

Then run:

```bash
php scripts/migrate.php
php -S 127.0.0.1:8080 -t public
```

Open `http://127.0.0.1:8080`.

Payment processing, authentication, automated team formation, round-robin schedule generation, standings calculation, venue billing and broadcast crew scheduling are intentionally left for the next phases.
