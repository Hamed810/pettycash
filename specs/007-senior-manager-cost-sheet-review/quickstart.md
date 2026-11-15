# Quickstart — Senior Manager Cost Sheet Review (Phase 1)

**Feature**: `specs/007-senior-manager-cost-sheet-review/` | **Status**: planning only — nothing here is implemented or runnable yet.

## What this feature is

A **plan** (spec + plan + research + data model + contract notes) for replacing the Senior Manager's flat transaction queue with a Cost-Sheet-based queue that opens sheets in a modal, reusing the Feature 006 review workspace components, and (after separate authorization) additive backend reads and a Senior-scoped export.

## Where the artifacts live

```text
specs/007-senior-manager-cost-sheet-review/
├── spec.md          # feature specification (owner requirements + workflow reconciliation)
├── plan.md          # implementation plan (all sections, risks, sequence, gates)
├── research.md      # Phase 0 research notes and evidence anchors
├── data-model.md    # Phase 1 data model / TS interfaces / invariants
├── quickstart.md    # this file
└── contracts/senior-review-api.md  # proposed additive API surface (not ratified)
```

## Governance constraints

- Planning phase only: **no code, no `tasks.md`, no commits, no backend/contract changes**.
- Any backend batch requires a **separate owner authorization**; the narrow 001 `FR-037` amendment the sheet gate requires was **recorded in Feature 001 on 2026-11-08** (see spec Workflow Reconciliation).
- No Git writes are authorized; the existing dirty working tree (modified review/evidence components) is preserved.
- No new dependencies; `@nextcloud/vue` 9.x only; no Vuetify.

## Commands that WILL apply in Phase B (not now)

Frontend gates (host):

```bash
npm run typecheck
npm run lint
npm run stylelint
npm run build
npm run check
npx vitest run tests/Frontend/Composable/SeniorSheetQueue.spec.ts   # targeted, when the test exists
```

Backend (only through wrappers, only when a backend batch is authorized):

```bash
scripts/dev/phpunit tests/Backend/...   # e.g. ManagementReviewService / ReviewController Senior tests
scripts/dev/opcache-settle              # after lib/ appinfo/ PHP edits, for direct HTTP checks
```

Route cache (only after `appinfo/routes.php` changes, in an authorized backend batch):

```bash
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

Lab: `http://localhost:8081` (`nextcloud-lab`), runtime `pettycash-lab-nextcloud-1`. Evidence: official Google Chrome via Playwright `chrome` channel only, screenshots under `artifacts/visual-review/<task-id>/<iteration>/`.

## Next steps (to move past planning)

1. Owner decisions D1–D9 were recorded on **2026-11-08** (`plan.md` §Open Questions), including the 001 `FR-037` gate, which was recorded in Feature 001 on the same date — no open question remains there. Vendor and export scope are also resolved (D3/D7).
2. Owner records the separate backend authorization (if the additive reads/export are approved).
3. Run `/speckit-tasks` to generate `tasks.md` under this directory before any implementation.
