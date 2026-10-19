# Quickstart: Purchaser UI Redesign

**Feature**: `005-purchaser-ui-redesign` | **Date**: 2026-10-19

This quickstart validates the **management baseline** (this feature) and, separately, re-validates the **Batch A working-tree implementation** on demand. It does not authorize Batch B, any `BE-CR`, or any Git write.

---

## 1. Automated validation — management baseline (host, ~1 minute)

The management artifacts are documents plus one pointer file. No application source changed in this pass.

```bash
# 1. Confirm the feature pointer resolves
cat .specify/feature.json          # must be {"feature_directory":"specs/005-purchaser-ui-redesign"}

# 2. Confirm every artifact exists
ls specs/005-purchaser-ui-redesign/
#   spec.md plan.md research.md data-model.md quickstart.md tasks.md
#   contracts/purchaser-ui-contract.md checklists/requirements.md

# 3. Confirm no application source changed in the migration
git status --short | grep -E '^( M|A |\?\?) (lib|appinfo)/' # expected: no output
git status --short | grep -c '\.php$'                        # expected: 0
```

Expected: the pointer resolves, all eight artifacts exist, no PHP is touched.

---

## 2. Automated validation — Batch A surface (host, targeted)

Run only when the Batch A surface itself needs re-verification (e.g. before a commit of it, or after touching `src/`). Do **not** run this to re-prove the management migration.

```bash
npx vitest run \
  tests/Frontend/localeFormatting.spec.ts \
  tests/Frontend/Component/PurchaserFlow.spec.ts \
  tests/Frontend/Component/SheetDeletion.spec.ts \
  tests/Frontend/Component/RtlAccessibility.spec.ts
```

Expected (verified 2026-10-19): **4 files / 168 tests passed** —
`PurchaserFlow` 137, `localeFormatting` 13, `SheetDeletion` 9, `RtlAccessibility` 9.

Supporting guards, cheap to run together:

```bash
npx vitest run \
  tests/Frontend/l10nCatalog.spec.ts \
  tests/Frontend/Component/NextcloudVueContract.spec.ts \
  tests/Frontend/fr071NarrowViewport.spec.ts \
  tests/Frontend/routeNameIntegrity.spec.ts \
  tests/Frontend/keyboard-accessibility.spec.ts
```

### Batch-boundary gate (do **not** run per edit)

```bash
npm run check
```

`npm run check` is portability + typecheck + lint + stylelint + vitest + build. Run it at a batch/milestone boundary, not after every local edit. The Batch A report records it green on its final tree (23 files / 481 tests); this management pass did **not** re-run it, and its result is quoted only as report evidence.

PHP is untouched by this feature. If a future change touches `lib/` or `appinfo/`, PHP verification is **container-only**:

```bash
scripts/dev/phpunit <test>     # never host PHP
scripts/dev/opcache-settle     # before any live-HTTP probe after lib/appinfo edits
```

---

## 3. Manual / browser validation (lab required)

Prerequisites: the pettycash lab is running (`/home/ubuntu/projects/nextcloud-lab/bin/lab pettycash up`, `http://localhost:8081`), and a purchaser account exists. Browser evidence is **technical evidence, not product-owner acceptance**.

1. **Locale + calendar matrix** — sign in as a purchaser; render the sheet list and a transaction form under `fa`, then `en`:
   - `fa` → RTL, Jalali by default, Persian digits;
   - `en` → LTR, Gregorian by default, Latin digits;
   - a calendar preference change re-renders dates with no reload and no request.
   - **Outstanding**: a visible way to change the preference while mounted (card AC2), and a payload-diff under both preferences (card AC5).
2. **Sheet list** — six columns in the accepted order, title above the ID, `—` for a never-submitted sheet, `0` for a genuine zero, `0` transactions for an empty sheet, one accessible action menu per row; no horizontal scroll at 390px.
   - **Outstanding**: English labels (blocked by `REC-01` — raw keys will render); the date-filter labels do not yet name the creation date (`REC-06`); owner visual acceptance.
3. **Row actions** — an `OPEN` row offers View/Edit/Delete; a submitted row offers View only; Delete opens a confirmation that names the sheet; **cancel issues no request**.
4. **List context** — open a sheet from page N with a filter, then browser-Back: page N, filter, and scroll are restored and the list refetches; a fresh visit resets to page 1; a delete on the last page clamps.
5. **Backend-blocked journeys** (must fail today, by design): no OPEN-first ordering across pages; no inline account edit; no per-stage counts; evidence is not asserted as a submission blocker. Do not "fix" any of these client-side.

---

## 4. Explicit prohibitions for any follow-up pass

- No Git write (`add`, `commit`, `push`, `merge`, `rebase`, `reset`, `clean`, branch/tag/config) without an approved `GIT-PLAN-*`.
- No Batch B card started without per-card owner approval.
- No edit to `lib/`, `appinfo/`, `specs/001-*`, the OpenAPI contract, or the workflow contract.
- No English catalog authored (`REC-01` is an owner decision, not an agent task).
- No new dependency, no Vuetify, no UI framework change.
- No host PHP, no manual `docker exec`, no container-side frontend tooling.
- No staging outside an approved `GIT-PLAN-*` allowlist. `GIT-PLAN-001-R1` isolates generated `js/`/`css/` output in its build-sync commit (C6), includes only inspected design references and the bounded Manus prototype subset, and excludes `_archives/`, `review/bunny/screenshots/`, `test-results/`, `.env.testing`, and pre-existing user changes (`AGENTS.md`, `CLAUDE.md`, `.claude/`, `.env*`, `docs/Review uploaded pasted content.zip`, Feature 004 files).

---

## 5. How to read completion status

| Claim | Requires |
|---|---|
| `DONE_VERIFIED` | Re-executed tests **or** recorded browser evidence naming the surface and viewport |
| aggregate green | An executed `npm run check` with its own file/test counts |
| product-owner acceptance | An explicit owner statement — never inferred from a passing test |
| blocked | The named `BE-CR` approval **and** its open answers |

A passing test from the wrong runtime, a report-recorded result quoted as re-executed, or a "technically verified" card reported as "visually accepted" are all completion-claim violations (`FR-005-034`, `SC-005-008`, `SC-005-009`).
