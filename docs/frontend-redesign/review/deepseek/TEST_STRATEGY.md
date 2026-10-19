# TEST_STRATEGY — verification criteria for the purchaser frontend work

**Baseline:** `main` @ `6006f28400e8962909afab0744460152353c12fe` · **Status of this document:** `PROPOSED`
**Purpose:** give Bunny (and the reviewer of Bunny's work) a precise way to distinguish what was *inspected*, what was *executed*, and what is merely *assumed* — for every area the owner asked to have verified. It is written against the queue in `BUNNY_READY_QUEUE.md`.

---

## 1. Evidence levels — and where this package stands

| Level | Meaning | Applies to |
|---|---|---|
| **Source-confirmed** | The claim is proven by an exact file-and-line reading of the implementation, the contract, or the specification at the baseline SHA. | **All 49 audit findings** are stated at this level, per their classification. None is described as reproduced. |
| **Runtime-reproduced** | The claim was observed executing. | **Zero.** No test suite, browser session, HTTP request, or container command was run in this package's preparation. Any text implying otherwise would be false. |
| **Untested assumption** | A reasonable expectation that must be verified by executing the card's tests, or by a reviewer looking at the screen. | The acceptance criteria of all 16 cards, all visual/token judgements (`VISUAL_REVIEW_NEEDED`), and the three runtime-only checks in §3.16. |

**Consequence for Bunny:** a green unit/component run proves the *code path*, not the *rendered screen*. Layout, colour and token parity against the references remain the reviewer's job until Browser verification is run; and `GAP-005`, `GAP-022`, `GAP-029` stay "source-confirmed, not reproduced" until a live environment says otherwise.

---

## 2. Tooling inventory (verified, real)

| Level | Command | Environment |
|---|---|---|
| Type/compile | `npm run typecheck` (`vue-tsc --noEmit`) | host |
| Unit / static analysis over the frontend | `npm test` / `npm run test:unit` (`vitest run`) | host |
| Component specs (jsdom + `@nextcloud/vue`) | included in `npm test`, files under `tests/Frontend/Component/` | host |
| Style / lint | `npm run lint`, `npm run stylelint` | host |
| Contract validation (OpenAPI) | `npm run check:openapi` (`scripts/validate-openapi.sh`) | host |
| Full frontend gate | `npm run check` = openapi + portability + error-rate + typecheck + lint + stylelint + `npm test` + build + notices | host |
| PHP suites | `scripts/dev/phpunit` → unit + integration + contract (`phpunit.xml` testsuites) | **container** (wrapper only) |
| Browser E2E | `npm run test:e2e` (Playwright, `testDir: tests/E2E`) | host, **lab must be up** — `pretest:e2e` runs `scripts/dev/opcache-settle`; base URL `http://localhost:8081` |
| Browser matrix | Playwright projects `chromium-desktop`, `firefox-desktop`, `edge-desktop`, `chromium-mobile` (Pixel 7 = 412px) | as above; FR-071's 390px assertion sets its **own** viewport (`tests/Frontend/fr071NarrowViewport.spec.ts`) |

Existing pinning suites a reviewer should expect to stay green (31 spec files total; the ones the purchaser work can touch):

- **List surface:** `tests/Frontend/unrenderedColumnFallback.spec.ts`, `tests/Frontend/fr071NarrowViewport.spec.ts`, `tests/Frontend/Component/PurchaserFlow.spec.ts`, `tests/Frontend/Component/SheetDeletion.spec.ts`
- **Forms / transactions:** `tests/Frontend/Component/TransactionCorrection.spec.ts`, `tests/Frontend/Component/NextcloudVueContract.spec.ts`, `tests/Frontend/routeNameIntegrity.spec.ts`
- **Cross-cutting:** `tests/Frontend/l10nCatalog.spec.ts` (fa completeness gate), `tests/Frontend/Component/RtlAccessibility.spec.ts`, `tests/Frontend/keyboard-accessibility.spec.ts`, `tests/Frontend/Component/AsyncState.spec.ts`, `tests/Frontend/typography.spec.ts`
- **Evidence / history:** `tests/Frontend/Component/AuditEvidence.spec.ts`
- **Browser:** `tests/E2E/purchaser-submit.spec.ts`, `tests/E2E/transaction-correction.spec.ts`, `tests/E2E/keyboard-accessibility.spec.ts`, `tests/E2E/browser-matrix.spec.ts`, `tests/E2E/audit-evidence.spec.ts`

**Verified absence to preserve in the design of the work:** no existing spec references `beforeunload`, `onBeforeRouteLeave`, `isDirty`, or an unsaved-changes dialog anywhere under `tests/Frontend/` — the guard in `UI-PUR-012` has no test today, so it must bring its own.

---

## 3. The verification areas the owner named

Status column vocabulary: **pin** = an existing suite must stay green · **new** = the card must add assertions · **review** = a human must look at the rendered result · **live** = needs a running lab.

| # | Area | How it is verified | Level | Status today |
|---|---|---|---|---|
| 1 | **Vue / Nextcloud compatibility** | `npm run typecheck` + `tests/Frontend/Component/NextcloudVueContract.spec.ts` (component/API contract with `@nextcloud/vue` 9.x) + `npm run build` in the aggregate gate. No new framework may be introduced; a diff touching Vuetify or a new UI dependency is a defect by definition | unit + build | pin; **new** assertions only if a card adds a new `@nextcloud/vue` component (allowed set: `NcActions`, `NcModal`-family, `NcDialog` as used today) |
| 2 | **Existing API contracts** | No frontend card may change a request/response shape. Verify by reading the wrappers against `specs/001-pettycash-v1-core/contracts/ocs-api.yaml` and by `npm run check:openapi` staying green; a card that needs a field the contract lacks is `BLOCKED_BY_BACKEND`, not a workaround | static + contract | pin (`check:openapi`); every card's "Backend impact" line states NONE except the three blocked ones |
| 3 | **Authorization and role restrictions** | The client renders only what the payload's own permission projection allows (`allowedActions`), hides rather than fake-disables, and never gates a write by a guess. Reviewer inspects that no card derives a permission rule; the server remains authoritative | component + review | **new** assertions in the affected card specs (list menu, workspace actions) |
| 4 | **Financial totals** | Integer minor units displayed unrounded; FR-062's four figures (effective outcome + three stage-approved) each keep their own label and cell; `current` vs `effectiveApproved` must not be conflated; no client-side arithmetic that invents a total | component + review | pin `unrenderedColumnFallback.spec.ts`; **new** assertions in `UI-PUR-002` |
| 5 | **Draft validation** | Submitting an incomplete draft must never appear to succeed: mirror the **server-enforced** preconditions only (`description`, cost centre, expense type, ≥1 transaction), keep the server 422 as the backstop, and show field-level errors (`aria-invalid` where the app already uses it) | component + E2E | **new** in `UI-PUR-016`; pin `purchaser-submit.spec.ts` |
| 6 | **Review transitions** | Out of scope for the purchaser cards. Regression only: the purchaser's view must show the stage/status the server publishes and must not re-derive it (`BE-CR-005` stays a backend fix) | regression | pin `Component/PurchaserFlow.spec.ts`, `tests/E2E/management-review.spec.ts` |
| 7 | **Returned transaction correction** | Correction from a returned transaction reaches the form with the correct revision semantics, resubmission restarts at Cost Center, and the history shows the sequence. **Canonical path resolved to the existing PATCH flow** (`REC-04`) | component + E2E | pin `Component/TransactionCorrection.spec.ts` and `tests/E2E/transaction-correction.spec.ts` — these pin today's behaviour and must stay green |
| 8 | **Evidence uploads** | The affordance exists only once the transaction exists; the 100 MB per-file ceiling is stated accurately; multiple attachments remain possible; **no MIME allowlist may be invented**; a failed upload is surfaced, never swallowed | component | **new** in `UI-PUR-010`; pin `Component/AuditEvidence.spec.ts` |
| 9 | **Unsaved Changes guard** | Dirty forms raise the approved three-way dialog on Cancel, Escape, route change, browser back and unload; "Stay" keeps the input, "Discard" leaves, "Save" runs the real save; the guard never fires on a pristine form | component + E2E | **new** in `UI-PUR-012` — **no coverage exists today**; needs its own spec plus a keyboard-path assertion |
| 10 | **English LTR** | Direction follows the locale; no hardcoded direction; every new string has a key. Rendered English is **`TO_VERIFY` until `REC-01` is decided**, because no `en` catalog exists | static + review | **blocked on `REC-01` for rendered proof**; the *mechanism* is verifiable now (no `dir="rtl"` additions; direction derived from locale) |
| 11 | **Future Persian RTL compatibility** | The mechanism must survive the switch back: no LTR-only CSS assumptions, no `dir` hardcoding, mirrored layouts where needed, and existing RTL specs stay green | unit + component | pin `Component/RtlAccessibility.spec.ts`; **new** assertions for every new component |
| 12 | **Gregorian / Jalali preference** | The display calendar becomes a preference independent of language; both calendars format through the same helper; dates must not be fabricated in either calendar | unit | **new** in `UI-PUR-001` (+ its existing type-level coverage) |
| 13 | **Light / Dark mode** | `@nextcloud/vue` theming variables only; new pill/menu/card styles must not hardcode colours; contrast checked in both themes | review + component | **new** in `UI-PUR-015`; **review** for contrast |
| 14 | **Desktop / mobile responsive layouts** | 390px floor holds (no horizontal scroll) on the new list, overlay and form; the overlay is full-screen on mobile; long Persian **and** long English labels do not break the layout | unit + E2E + review | pin `fr071NarrowViewport.spec.ts`; **new** narrow-viewport assertions per redesigned surface; **review** against the 9 mobile references |
| 15 | **Accessibility** | Focus entry/trap/return on the overlay and menus; the prototype's missing focus trap must not be ported (`GAP-073`); tab order sane; `aria-invalid` on validation; reduced-motion honesty (the app has **no** `prefers-reduced-motion` handling today — do not claim it) | component + E2E + review | pin `keyboard-accessibility.spec.ts` (both levels); **new** per new component |
| 16 | **Playwright E2E tests** | Run at batch boundaries when a browser-visible flow changed, with the lab up. Three findings are reproducible **only** here: `GAP-005` (pagination ties need a dataset with identical sort keys), `GAP-022` (a decision on a correction-stage transaction), `GAP-029` (a live 409 conflict) | E2E + live | **not run in this package**; must not be described as reproduced until it is |
| 17 | **Regression against existing flows** | Every batch keeps the whole existing suite green — purchaser, management-review, finance-settlement and reporting specs are all touched by shared files (router, l10n, shared components) | aggregate | pin, run at batch exit |

---

## 4. Per-card test requirements

| Card | Must stay green (existing) | Must add | Browser |
|---|---|---|---|
| `UI-PUR-001` | `Component/RtlAccessibility.spec.ts`, `l10nCatalog.spec.ts`, `typography.spec.ts`, `unrenderedColumnFallback.spec.ts` | Direction-follows-locale assertion; one formatting helper covering both calendars; no-hardcoded-`fa-IR` assertion (grep-level) | — |
| `UI-PUR-002` | `unrenderedColumnFallback.spec.ts`, `fr071NarrowViewport.spec.ts`, `Component/PurchaserFlow.spec.ts`, `Component/SheetDeletion.spec.ts` | Six-column structure incl. title-above-ID; **each FR-062/FR-063 figure present and labelled**; date-filter label names the creation date; long-label no-overflow | 390px + desktop visual review |
| `UI-PUR-003` | — (blocked) | On unblock: OPEN-first across page boundaries (E2E with a dataset), direction honoured, deterministic tie-breaker | E2E required |
| `UI-PUR-004` | `Component/PurchaserFlow.spec.ts` | Page/filters/scroll restored after return; post-edit refetch does not reset the page; emptied last page falls back | — |
| `UI-PUR-005` | `Component/RtlAccessibility.spec.ts`, `keyboard-accessibility.spec.ts` | Overlay focus entry/trap/return-to-row; Escape semantics; deep link still resolves; mobile full-screen | keyboard E2E |
| `UI-PUR-006` | `Component/AuditEvidence.spec.ts`, `Component/PurchaserFlow.spec.ts` | Table from payload only; row actions from `allowedActions`; History tab assembled per transaction; the two impossible statuses are never rendered | audit-evidence E2E |
| `UI-PUR-007` | — (blocked) | On unblock: no `accounts[0]` substitution anywhere; account echoed correctly; currency/permission refusals surfaced | — |
| `UI-PUR-008` | `Component/SheetDeletion.spec.ts` | Menu open/keyboard/close; delete still routes through the named confirmation; no fake-disabled actions | keyboard E2E |
| `UI-PUR-009` | `Component/PurchaserFlow.spec.ts` | Preselect from `activeRevision.createdAt` ordering; fallback when no transaction exists; explicitly not array order / not expense date | — |
| `UI-PUR-010` | `Component/AuditEvidence.spec.ts` | No evidence affordance before the transaction exists; nothing silently discarded; 100 MB statement accurate; no MIME claim | audit-evidence E2E |
| `UI-PUR-011` | `Component/PurchaserFlow.spec.ts`, `Component/SheetDeletion.spec.ts` | Draft delete with confirmation; 409 on stale delete surfaces the conflict path, not a generic error | — |
| `UI-PUR-012` | `Component/NextcloudVueContract.spec.ts` | The three-way dialog on every exit path; pristine forms untouched; "Stay" preserves input; 409 save path explains and offers reload | keyboard E2E |
| `UI-PUR-013` | `Component/TransactionCorrection.spec.ts` (**pins today's PATCH flow**), `tests/E2E/transaction-correction.spec.ts` | Exactly one correction path in the code; the dead wrapper is gone or contract-correct; revision sequence unchanged | correction E2E |
| `UI-PUR-014` | — (blocked) | On unblock: stage counts/amounts match seeded decisions; Returned shown only when applicable; never presented as finance totals | — |
| `UI-PUR-015` | `keyboard-accessibility.spec.ts`, `Component/RtlAccessibility.spec.ts`, `typography.spec.ts`, `Component/NextcloudVueContract.spec.ts` | Focus visibility and contrast on new components; dark-theme parity assertions; no hardcoded colours | browser matrix |
| `UI-PUR-016` | `tests/E2E/purchaser-submit.spec.ts` | Blockers mirror server-enforced preconditions only; the evidence item is **advisory** (no claim the server refuses); 422 backstop surfaced readably | purchaser-submit E2E |

---

## 5. Batch gates

| When | Run |
|---|---|
| During a card | The card's targeted Vitest spec(s) + `npm run typecheck` on the touched area |
| End of Batch A / B / C / D | `npm run check` on a clean tree |
| Milestone (after Batch B and after the final batch, or when a PHP-visible behaviour is involved) | `scripts/dev/phpunit` |
| When a browser-visible flow changed and the lab is up | `npm run test:e2e` (all four projects) |
| Before the reviewer sees a card | Re-run **that card's** tests and paste the actual command + result summary into the card's evidence section |

Do not re-run an unchanged full gate to obtain the same evidence twice; do not run the PHP suite after a purely frontend edit; do not claim an E2E result without the lab having been up.

---

## 6. Claim discipline (what a Bunny report may and may not say)

- **May say:** "source-confirmed at `<file>:<lines>`", "component spec `<name>` passed (command shown)", "`npm run check` passed on batch exit".
- **May not say:** "verified in the browser" (unless the browser run is shown), "the bug is fixed" (unless the card's acceptance criteria were executed), "RTL/English rendering verified" while `REC-01` is open, or "runtime reproduced" for `GAP-005`/`GAP-022`/`GAP-029` without a live reproduction.
- **Must say instead:** `TO_VERIFY` / `VISUAL_REVIEW_NEEDED` / `BLOCKED_BY_*` with the reason.
- **Never:** present a passing test from the wrong runtime as evidence (host tests do not prove container behaviour, and vice versa).
