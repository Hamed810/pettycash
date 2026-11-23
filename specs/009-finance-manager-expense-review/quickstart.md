# Quickstart: Finance Manager Expense Review (Feature 009)

**Feature**: `009-finance-manager-expense-review` | **Date**: 2026-11-19 | **Spec**: [spec.md](./spec.md) | **Plan**: [plan.md](./plan.md) | **Data model**: [data-model.md](./data-model.md)

**Purpose**: Phase 1 dev/run/gate quickstart — how to run, typecheck, lint, and verify the frontend-only adaptation. No backend commands, no `phpunit`, no `occ`, no Composer. Planning phase only — no code, no `tasks.md`, no commits.

---

## 1. Runtime Mount (for reference only)

- Repository: `/home/ubuntu/projects/pettycash`
- Runtime mount: `/var/www/html/custom_apps/pettycash`
- Lab URL: `http://localhost:8081`
- Live-HTTP checks require `scripts/dev/opcache-settle` after `lib/`/`appinfo/` PHP edits (none at this stage — frontend only).

This feature changes **frontend TypeScript/Vue only**. No `lib/`, `appinfo/`, or `tests/Backend/` changes at this stage, so no opcode/route settling is required by this plan.

---

## 2. Frontend Scripts (preferred, from repository root)

```bash
# Typecheck (TypeScript + vue-tsc)
npm run typecheck

# Lint (ESLint)
npm run lint

# Stylelint
npm run stylelint

# Build (Vite)
npm run build

# Frontend unit tests (Vitest)
npm test -- --run

# Targeted unit test
npm test -- --run tests/Frontend/Composable/FinanceSheetQueue.spec.ts
npm test -- --run tests/Frontend/Component/FinanceQueue.spec.ts
npm test -- --run tests/Frontend/Component/FinanceSheetModal.spec.ts

# Combined frontend gate (covers frontend only; does NOT run PHP tests)
npm run check

# E2E (Playwright, official Google Chrome channel only)
npm run test:e2e
```

`npm run check` covers frontend gates only; it does not run PHP tests. No PHP tests are added or changed by this plan.

---

## 3. Wrapper Rules (constitution §5)

Never run host PHP/Composer/PHPUnit/OCC. Use wrappers only if a backend change is later authorized:

```bash
scripts/dev/phpunit <test>
scripts/dev/occ <args>
scripts/dev/composer <args>
scripts/dev/in-container <cmd>
scripts/dev/opcache-settle
scripts/dev/route-settle
scripts/dev/route-cache-clear
```

None are needed for this planning phase.

---

## 4. Gate Boundaries

| Gate | Command | Applies to this plan? |
|---|---|---|
| Typecheck | `npm run typecheck` | Yes — new TS interfaces and components |
| Lint | `npm run lint` | Yes — new Vue/TS files |
| Stylelint | `npm run stylelint` | Yes — any new Vue `<style>` |
| Build | `npm run build` | Yes — Vite build must pass |
| Unit tests | `npm test -- --run <path>` | Yes — new composable/component specs |
| E2E | `npm run test:e2e` | Yes — official Chrome channel only |
| PHP tests | `scripts/dev/phpunit` | **No** — no backend change at this stage |

Run focused checks at the smallest useful boundary (component, composable) during implementation; run `npm run check` at stage boundaries. Do not run broad suites for reassurance.

---

## 5. Visual Evidence (constitution §VIII / §V)

- Screenshots under `artifacts/visual-review/<task-id>/<iteration>/`, cited by repository-relative path.
- Official Google Chrome via Playwright `chrome` channel (`{ browserName: 'chromium', channel: 'chrome' }`) only. Bundled Chromium/Firefox/WebKit/Edge prohibited; missing Chrome is a reported blocker.
- Viewports: desktop 1440×900 English LTR; mobile 390×844 Persian RTL; dark theme spot-check.
- A visual result without a recorded owner approval is never reported as accepted.

---

## 6. Localization / RTL Checks (per spec)

- English primary; Persian required. New `reviews.financeSheet.*` keys added to `l10n/en.json` and `l10n/fa.json`.
- Persian RTL; English LTR. `:dir="direction"` binding (never hardcoded `direction: rtl`).
- UUIDs and currency codes direction-isolated (LTR, `<bdi dir="ltr">`) inside RTL pages.
- 390px desktop floor, no horizontal scroll (`scrollWidth <= clientWidth`).

---

## 7. Information Still Required Before Task Generation

- Consent to proceed from planning to task generation (`/speckit-tasks`) and to create `tasks.md` under this feature directory.
- Confirmation that current working-tree user changes are preserved and reused as the baseline.
- Confirmation that `CostCenterQueueView.vue` + `FinanceReviewView.vue` are the agreed visual baseline.

---

*Prepared by Spec Kit planning workflow. Planning phase only — no code, no `tasks.md`, no commits, no backend commands.*
