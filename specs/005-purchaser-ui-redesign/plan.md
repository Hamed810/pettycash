# Implementation Plan: Purchaser UI Redesign

**Branch**: `005-purchaser-ui-redesign` (logical feature directory) — the working branch stays `feat/pettycash-purchaser-ui-batch-a`; no branch operation is authorized | **Date**: 2026-10-19 | **Spec**: [spec.md](spec.md)

**Input**: Feature specification from `/specs/005-purchaser-ui-redesign/spec.md`

---

## Summary

Bring the in-flight Purchaser UI Redesign under Spec Kit management without changing application behavior. The redesign's 16 `UI-PUR-*` frontend cards and 6 `BE-CR-*` backend change requests are imported with their original identifiers, mapped to `FR-005-nnn` requirements and to a canonical `T001…T052` task ledger, and given an explicit status and gate. Batch A (`UI-PUR-001`, `002`, `008`, `004`) is recorded as it exists in the working tree: two cards `DONE_VERIFIED` (technical), two `PARTIAL` with named residuals. All six `BE-CR` items stay `BLOCKED_PENDING_OWNER_APPROVAL`; the three cards that depend on them stay `BLOCKED_BY_BACKEND`. Batch B is not started and is not authorized. The technical approach for everything already built is "reuse what Batch A shipped"; the approach for everything not built yet is "do not build it until the owner approves the card and, where applicable, the backend request".

The measurable output of this feature is **management integrity**: 22/22 cards mapped, zero backend changes, zero contract edits, and one approval-gated Git plan.

---

## Technical Context

**Language/Version**: TypeScript 5, Vue 3 (script setup), Vite build — unchanged, per constitution Principle III and the compatibility-targets list

**Primary Dependencies**: `@nextcloud/vue` 9.x (approved stack), `vue-router` (hash history), `pinia`, `@nextcloud/l10n` `t()` / `isRTL()`. **No new dependency** is introduced by this feature (`FR-005-029`)

**Storage**: No persistence change. The only stored artifact is the device-scoped calendar preference `pettycash.calendarPreference` in localStorage, which already exists in the working tree and carries no account/server counterpart (`FR-005-004`)

**Testing**: Vitest (`npm test` → `vitest run`) for component/unit; repository guards `l10nCatalog`, `RtlAccessibility`, `fr071NarrowViewport`, `NextcloudVueContract`, `routeNameIntegrity`, `keyboard-accessibility`, `typography`, `localeFormatting`, `PurchaserFlow`, `SheetDeletion`; Playwright against the pettycash lab for browser evidence at batch boundaries. PHP suites are container-only (`scripts/dev/phpunit`) and are required only if a future change touches `lib/`/`appinfo/` — none does here

**Target Platform**: Nextcloud 34 app page (`/apps/pettycash/` → hash routes); Chrome, Edge, Firefox, desktop and mobile; Persian RTL and English LTR

**Project Type**: Nextcloud app frontend (Vue SPA mounted at `#pettycash-app` inside `templates/main.php`), with the PHP backend explicitly out of scope

**Performance Goals**: No performance target is introduced. The N+1 list-totals observation (`GAP-008`) is a deferred performance item, not part of this feature (the 001 spec itself defers SC-008)

**Constraints**:
- Persian RTL and the 390px no-horizontal-scroll floor MUST remain at least as strong as FR-071 requires (`FR-005-005`); the repository bans `overflow-x: auto|scroll` in `src/`
- No Vuetify, no `v-*` components, no second UI framework, no focus-trap library
- No edit to `lib/`, `appinfo/`, `specs/001-pettycash-v1-core/**`, the OpenAPI contract, or the workflow contract
- No Git write without an approved operation plan
- Evidence tier discipline: report-recorded results are never presented as re-executed results

**Scale/Scope**: The purchaser redesign's own surface. Batch A already touched ~50 tracked files plus 6 new files; this feature's *management* scope is 8 new artifacts under `specs/005-purchaser-ui-redesign/`, one pointer file, four governance documents, and one report. Remaining (unapproved) implementation scope: 12 frontend cards and 6 blocked backend requests. V1 scale (<100 users, <10k transactions/year) is unaffected

**Compatibility Target Register**: No compatibility target changes. ACC-001 (PHP 8.2+) and ACC-002 (`mysql`/`pgsql`/`sqlite`) are untouched and not contradicted by this plan. No new register entry is required or created.

---

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

- **I. Specification Before Implementation**: **PASS** — `spec.md` states the user outcomes, scope, acceptance criteria, roles, and the imported business-rule references; plan/tasks follow the spec; every implementation card is traceable to an acceptance scenario. Existing (Batch A) implementation is recorded as *evidence*, not treated as the requirement.
- **II. Preserve Contracts and Existing Behavior**: **PASS** — no route, OCS operation, schema, financial rule, authorization rule, migration, or persisted meaning changes. The working tree's Batch A changes are preserved as they are; this feature adds documents. The three cards that would require a behavior change are blocked, not implemented.
- **III. Nextcloud-Native, Boundary-Conscious Architecture**: **PASS** — Vue 3 + TS + `@nextcloud/vue` 9.x retained; `OCA\PettyCash` boundary untouched; Persian RTL and responsive behavior preserved and explicitly protected by `FR-005-005`; no new top-level layer; no new dependency; no compatibility-target change.
- **IV. Domain Integrity, Security, and Auditability**: **PASS** — all workflow, authorization, ownership, evidence, and financial enforcement remains server-side (`FR-005-017`: row actions are a subset of the server's capability set). The plan explicitly forbids re-deriving a domain rule client-side (`FR-005-020`, `BLOCKED_ITEMS.md` §6). The History tab surfaces per-transaction audit data without inventing a sheet-wide record and without altering append-only history.
- **V. Verified and Documented Delivery**: **PASS** — verification is defined before work per card (`TEST_STRATEGY.md` levels, `tasks.md` gates); this feature's own claim is bounded to re-executed targeted tests plus report-recorded Batch A evidence; documentation is updated in the same change (spec, plan, tasks, governance, report).

**Post-design re-check**: no new violations. The design adds one presentation contract and one management contract; neither crosses the backend boundary nor changes a declared target.

---

## Project Structure

### Documentation (this feature)

```text
specs/005-purchaser-ui-redesign/
├── spec.md                          # requirements, statuses, traceability matrix (created)
├── plan.md                          # this file
├── research.md                      # Phase 0: management decisions
├── data-model.md                    # Phase 1: presentation/management models
├── quickstart.md                    # Phase 1: validation procedure
├── contracts/
│   └── purchaser-ui-contract.md     # Phase 1: UI behavior + management contract
├── checklists/
│   └── requirements.md              # Phase 1: requirements-quality review
└── tasks.md                         # Phase 2: canonical task ledger (T001–T052)
```

### Source Code (repository root) — as-is, preserved; no new code in this feature

```text
src/
├── utils/
│   ├── direction.ts                 # Batch A (new): single direction derivation
│   ├── locale.ts                    # Batch A (new): throw-free locale resolution
│   ├── calendar.ts                  # Batch A (new): display-only calendar preference
│   ├── date.ts                      # Batch A (edited): formatSheetDate/DateTime, ABSENT_MARKER
│   ├── number.ts                    # Batch A (new): locale number formatting
│   ├── money.ts                     # Batch A (edited): minor-unit money formatting
│   └── sheetListSession.ts          # Batch A (new): list context capture/restore
├── views/sheets/SheetListView.vue   # Batch A (edited): six columns, overflow menu, context restore
├── AppRoot.vue                      # Batch A (edited): shell-level :dir
└── router/index.ts                  # Batch A (edited): session hook installation
tests/Frontend/                      # Batch A (new/edited specs) — see quickstart.md
l10n/fa.js, l10n/fa.json             # Batch A (edited): 362 keys, key-for-key equal

# Future (unapproved) edit surfaces, recorded for planning only — not touched by this feature
src/views/sheets/SheetDetailView.vue      # UI-PUR-005/006/007/014/016
src/views/transactions/TransactionFormView.vue  # UI-PUR-009/010/012/013
src/views/sheets/SheetFormView.vue        # UI-PUR-007/012
src/stores/sheets.ts                      # UI-PUR-003/004/016
```

**Structure Decision**: Single-project frontend slice. This feature writes **only** under `specs/005-purchaser-ui-redesign/`, plus the machine-local (gitignored) `.specify/feature.json` pointer, the four governance documents, and one report under `docs/reports/`. Application source, tests, l10n, and build output are preserved untouched. The PHP backend is out of scope in its entirety. Only the `specs/005-*` tree, the four governance documents, and the report are commit candidates; `.specify/feature.json` is excluded because `.specify/.gitignore` keeps it machine-local.

---

## Phases and Gates

> Phase names below are the *plan* phases; `tasks.md` groups the same work into its own numbered phases (tasks Phase 1 = plan Phase 0–1, tasks Phase 2 = plan Phase 1–3, and so on). Gate IDs `G-1…G-10` are shared and are the authoritative names.

### Phase 0 — Management research (complete)

Decisions 1–10 in `research.md`: feature identity, traceability model, status vocabulary, blocked-item discipline, evidence tiers, governance-update policy, Git boundary, task-ledger layout, and the meaning of "complete" for this feature.

**Exit**: no unresolved management question remains.

### Phase 1 — Management design (complete)

`data-model.md` (presentation + management entities), `contracts/purchaser-ui-contract.md` (C-01…C-12 + stack + management contract + guards), `quickstart.md` (validation), `checklists/requirements.md` (quality review).

**Exit**: every imported card has a requirement, a status vocabulary value, and a gate.

### Phase 2 — Canonical task ledger (complete)

`tasks.md` with `T001…T052`: management migration (T001–T015), Batch A closure (T016–T025), owner-gate actions (T026–T027), correctness + blocked cards (T028–T034), workspace/Batch B candidates (T035–T040), blocked backend change requests (T041–T046), verification/polish gates (T047–T052).

**Exit**: `SC-005-001` — 22/22 cards mapped with task IDs; zero unmapped.

### Phase 3 — Governance reconciliation (complete)

`CHARTER.md` (evidence-only execution addendum), `DECISIONS.md` (new entries, `Proposed` where owner input is missing), `STATUS.md` (conservative reconciliation replacing the obsolete F1 state), `ROADMAP.md` (phase statuses updated to evidence), `docs/reports/005-purchaser-ui-redesign.md`.

**Exit**: every claim in the four governance files is evidence-backed or marked proposed/blocked.

### Phase 4 — Owner gates (OPEN — not started by this feature)

| Gate | Requires | Blocks |
|---|---|---|
| G-1 | `REC-01` English catalog sourcing decision | Every English visual acceptance criterion; `UI-PUR-001`/`002` completion claims |
| G-2 | Per-card approval of the 13 `READY_FOR_OWNER_APPROVAL` cards | Batch B start |
| G-3 | `BE-CR-001` approval + currency rule + permission reading | `UI-PUR-007` |
| G-4 | `BE-CR-002` approval + intra-OPEN order + tie-breaker | `UI-PUR-003` |
| G-5 | `BE-CR-003` approval + `REC-03` semantics | `UI-PUR-014` |
| G-6 | `BE-CR-004` scope decision (in/out) + four answers | `UI-PUR-016` evidence tier |
| G-7 | `BE-CR-005` lock-version answer; `BE-CR-006` legitimacy answer | Live status derivation; decision guard |
| G-8 | Product-owner visual acceptance of Batch A surfaces | "Done" claims for `UI-PUR-001`/`002` visual work |
| G-9 | Approval of `GIT-PLAN-001-R1` | Every Git write |
| G-10 | Owner authorization for a live calendar-change surface (`P-008`) | `UI-PUR-001` AC2 |

### Phase 5 — Verification (this feature)

- Re-executed: targeted Vitest — 4 files / 168 tests (2026-10-19).
- Report-recorded (not re-executed): Batch A `npm run check` 23 files / 481 tests; Playwright 32/32, 8/8+8/8, 5/5.
- Source-verified: the `src/utils/**` Batch A surface and the test files listed in `quickstart.md`.
- Not obtained: live calendar-change surface, mutating negative test, English catalog, owner visual acceptance, any Batch B evidence.

**Exit**: a completion report that names each tier and claims nothing above it.

---

## Risks and Mitigations

| # | Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|---|
| R1 | A blocked card is "helped along" client-side (e.g. sorting the loaded page) | Medium | High — produces lying page boundaries and re-creates the defect | `FR-005-012` prohibition; `tasks.md` marks the card blocked; no workaround task exists in the ledger |
| R2 | The contract is edited to match implementation (e.g. making `accountId` required) | Low | High — silent financial/contract drift | `FR-005-031`; `specs/001-*` and its contracts are named out of scope; `BLOCKED_ITEMS.md` §6 is quoted in the plan |
| R3 | Batch A's uncommitted work is lost or mixed with unrelated changes | Medium | High — unrecoverable work loss | No Git write is part of this feature; `GIT-PLAN-001-R1` scopes staging with exact file allowlists and preserves pre-existing user changes |
| R4 | Report-recorded evidence is upgraded to "re-executed" in a later summary | Medium | Medium — false completion claim | `FR-005-034`, `SC-005-008/009`; the four evidence tiers are stated in `spec.md` and `quickstart.md` |
| R5 | Generated `js/`/`css/` churn is staged with documentation | High (if unguarded) | Medium — unreviewable diff, mechanical noise in history | `GIT-PLAN-001-R1` isolates build output in a dedicated build-sync commit (C6); `quickstart.md` §4 names the rule |
| R6 | Governance files drift back to the obsolete F1 state | Low | Medium — the owner reads a false status | `STATUS.md`/`ROADMAP.md` reconciled with an explicit "as of" date and evidence; the update protocol at the end of `STATUS.md` is retained |
| R7 | An owner decision is recorded as "accepted" without the owner | Low | High — governance integrity | Every unresolved item is `Proposed`/`Open`/`Blocked`; `DECISIONS.md` change control is respected |
| R8 | The English catalog gap (`REC-01`) is "fixed" by authoring a stub | Low | Medium — violates explicit scope and fails the completeness gate | Named out of scope in `spec.md` and `quickstart.md` §4 |
| R9 | Batch B is started because this plan exists | Low | High — unauthorized implementation | `FR-005-032`; every Batch B task in `tasks.md` is unchecked with a gate, and Phase 4 is marked OPEN |

---

## Rollback

The feature is documentation-only, so rollback is trivial and does not affect the application:

1. **Management artifacts** — delete `specs/005-purchaser-ui-redesign/` and restore the machine-local `.specify/feature.json` to its previous value (`{"feature_directory":"specs/004-in-app-navigation"}`; the file is gitignored, so this is a local edit only). No application behavior changes.
2. **Governance documents** — revert the four edited files individually; each edit is a dated, additive reconciliation, and the pre-edit text is recoverable from Git history (the files are tracked).
3. **Report** — delete `docs/reports/005-purchaser-ui-redesign-spec-kit-migration.md`.
4. **Batch A working tree** — untouched by this feature. If it is ever rolled back, that is a separate, owner-authorized operation; `git status`/`git diff` remain authoritative and no destructive command (`reset --hard`, `clean -fd`, force-push) is permitted.
5. **Git operations** — no Git write is part of this feature; the approved checkpoint is defined in `GIT-PLAN-001-R1` and requires its own approval.

---

## Complexity Tracking

> No constitution violations to justify. No entry.

The only complexity deliberately introduced is the dual identity of the work (`UI-PUR-*`/`BE-CR-*` cards **and** Spec Kit tasks). It is justified because the audit package is already the owner's working vocabulary and the repository's ADR-002 requires Spec Kit to own durable status; the mapping is the bridge, and `SC-005-001` makes an unmapped card a detectable failure rather than a silent one. A single-ID system was rejected: renumbering would break the owner's existing references, and Spec Kit-only tasks would leave the audit package as a competing status source.
