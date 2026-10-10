# PettyCash Nextcloud App V1

# PettyCash Nextcloud App V1

PettyCash is a Nextcloud-native petty-cash capture, review, settlement, audit, and reporting
application. The application is implemented as one modular monolith and preserves the existing
`pettycash` app ID and `OCA\\PettyCash` namespace.

## Supported environment

- Nextcloud 34.x
- PHP 8.2–8.5
- MariaDB
- Node.js 20.11, 22, or 24
- Vue 3 and TypeScript 5

## Safe local workflow

This project has its own Nextcloud development lab, Compose project `pettycash-lab`, served on
<http://localhost:8081> (`~/projects/nextcloud-lab`). The sibling PTS application has a
separate lab on port 8082; the two share no database, volumes, network, users or test data.

The project never owns the lab's Nextcloud volumes or legacy `oc_pcash_*` tables. Do not reset,
drop, rename, or rewrite either while developing this application.

```bash
./scripts/preflight.sh  # read-only environment and legacy-table inventory
composer install
npm ci
make check
npm run build
```

The preflight expects this project's own lab — containers `pettycash-lab-nextcloud-1` and
`pettycash-lab-db-1`, served on <http://localhost:8081> — and fails if they are not running.
Start it with `~/projects/nextcloud-lab/bin/lab pettycash up`. Container names can be
overridden with `PETTYCASH_NC_CONTAINER` and `PETTYCASH_DB_CONTAINER`, but they must still
identify THIS project's lab: the PTS lab is a separate Nextcloud on port 8082 that does not
carry this app. Browser tests use `PETTYCASH_BASE_URL` (default
`http://localhost:8081`) and are always run explicitly with `npm run test:e2e`.

## Architecture

- `lib/Domain`: deterministic business rules and immutable value objects
- `lib/Application`: commands, queries, authorization, and ports
- `lib/Infrastructure`: Nextcloud/database/storage/export adapters
- `lib/Controller`: thin page and OCS transport adapters
- `src`: Vue application; generated deployable assets are written to `js/` and `css/`
- `specs/001-pettycash-v1-core`: specification, plan, contracts, and implementation tasks

All money is stored as integer minor units. Mutating requests use caller-supplied command UUIDs;
business audit and submitted revisions are append-only. See `docs/architecture.md` and
`docs/operations.md` for operational constraints.
