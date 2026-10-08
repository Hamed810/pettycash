# Quickstart and Validation Guide: PettyCash V1 Core Workflow

**Date**: 2026-09-20

This guide defines the repeatable validation path the implementation must support. Commands that
refer to scripts not yet present are planned interfaces for the implementation tasks; they are not
evidence that code already exists.

## 1. Environment Prerequisites

- Project mounted from `/home/ubuntu/projects/pettycash` to
  `/var/www/html/custom_apps/pettycash`.
- Nextcloud target: 34.x.
- PHP target: 8.2–8.5. Do not install dependencies while the runtime reports PHP 8.1.
- MariaDB is the existing shared Nextcloud database.
- Node.js 20 LTS or a later version verified against the committed lockfile.
- The shared Nextcloud and database volumes must not be deleted or recreated.

The Docker container was stopped during plan creation. Start the existing environment through its
normal compose workflow before executing runtime checks; do not create a replacement environment
or remove volumes.

Verify the active runtime:

```bash
docker exec --user www-data docker-nextcloud-1 php -v
docker exec --user www-data docker-nextcloud-1 php occ status
docker exec --user www-data docker-nextcloud-1 php occ app:list | grep pettycash
```

Expected result: Nextcloud reports 34.x, PHP is within 8.2–8.5, and the `pettycash` application is
visible.

## 2. Schema Namespace and Migration Safety Gate

PettyCash V1 is a fresh application. All V1 tables use the logical app-scoped `pettycash_` prefix;
the instance database prefix is applied by the Nextcloud database abstraction and is never hardcoded
in application code, migrations, tests, or documentation. Indexes, unique constraints, foreign keys,
and check constraints use `pettycash_<table>_<subject>_<kind>` (`idx`, `uniq`, `fk`, `chk`).

Before verifying the V1 baseline:

1. Confirm every V1 table is named `pettycash_*` and no `pc_*` table or index name remains in
   migrations, repositories, fixtures, or tests.
2. Confirm the baseline migrations create the `pettycash_*` schema from an empty database; no
   rename migration and no mixed namespace exist.
3. Confirm no migration reads, writes, renames, or drops prototype tables that may exist in the
   shared database, and that no code references a `pc_` or `oc_pcash_*` name.
4. Confirm identifiers stay within the declared V1 backends' limits (PostgreSQL 63 bytes, MySQL 64
   characters).

Acceptance covers a clean installation on an empty database and the forward application of the full
V1 migration chain in version order. It does not cover any upgrade path from a pre-V1 database.

### Development-only reset (approval required)

The current development migrations have already run, and the discarded prototype shared the
`pettycash` app ID, so stale migration-state rows can make Nextcloud skip the rewritten baseline.
After the repository rename, reset PettyCash state in the development instance so the rewritten
baseline executes from scratch. This is development-environment work and MUST satisfy all of:

- explicit human approval before any deletion;
- affects PettyCash only, preserving every unrelated Nextcloud app and its data;
- never deletes Docker volumes or the shared Nextcloud database;
- clears the `pettycash` app-ID migration state as part of the reset;
- discards current `pc_*` development data, which is disposable by design;
- leaves residual prototype tables such as `oc_pcash_*` untouched, as unmanaged artifacts.

## 3. Planned Dependency and Build Commands

From the repository root after the implementation establishes manifests and lockfiles:

```bash
composer install
npm ci
npm run typecheck
npm run lint
npm run stylelint
npm run build
```

Expected result:

- Composer resolves against the verified PHP/Nextcloud 34 baseline.
- TypeScript and Vue compile without errors.
- Deployable assets are emitted under `js/` and `css/`.
- The build does not write into other Nextcloud applications or shared volumes.

## 4. Planned Automated Quality Gates

```bash
composer run lint
composer run psalm
composer run test:unit
composer run test:integration
composer run test:contract
npm run test:unit
npm run test:e2e
```

Minimum expected coverage by suite:

- Unit: money, validation, state transitions, latest-effective totals, sheet status aggregation,
  authorization policy, immutable revision rules, export escaping.
- Integration: migrations, database indexes and locks, idempotency, AppData evidence lifecycle,
  workflow/audit atomicity, account movement reconciliation.
- Contract: every OCS route, error mapping, paging/filtering, permission denial, and streaming
  response described in `contracts/ocs-api.yaml`.
- Frontend: stores, service error mapping, validation, permission-driven presentation, RTL
  formatting, correction flow, evidence state, audit views.
- End-to-end: real Nextcloud authentication, CSRF/OCS behavior, all role journeys, exports, and
  direct unauthorized requests.

Any temporarily unavailable integration harness must be documented with repeatable manual steps
and a tracked task; it is not silently skipped.

## 5. Deterministic Acceptance Dataset

The test fixture must create, through approved application fixtures or APIs rather than direct
production-like database editing:

- an administrator who is not a finance officer;
- a purchaser-only user;
- a cost center manager who is also the purchaser for one self-review case;
- a senior manager;
- two finance officers sharing the finance queue;
- at least two cost centers with distinct manager assignments;
- rial plus one foreign currency with appropriate minor units;
- active and inactive expense types, including one that requires evidence;
- multiple petty-cash accounts, including an account whose valid consumption produces a negative
  balance;
- transactions spanning draft, each review stage, correction, approved, and rejected outcomes;
- inactive evidence, multiple revisions, disabled historical users, and complete audit history.

For performance acceptance, generate a separate non-production fixture approximating 100 active
users and 10,000 annual transactions.

## 6. End-to-End Business Validation

### Scenario A: Configuration and Funding

1. As administrator, create currencies, cost centers, manager assignments, and expense types.
2. Create or activate user petty-cash accounts.
3. As finance, charge a selected account.
4. Verify the movement, resulting balance, and configuration audit.
5. Verify the administrator cannot make a finance decision unless separately assigned finance.

### Scenario B: Purchaser Capture and Submission

1. Create two open sheets simultaneously.
2. Verify a user with one active account receives its fixed currency.
3. Verify a user with multiple accounts can select only active account currencies.
4. Save a transaction using only expense date and positive amount.
5. Attempt submission with missing description, cost center, expense type, or required evidence;
   verify field-level errors and no state change.
6. Complete the transaction, attach multiple evidence files, and submit.
7. Verify submission succeeds without requiring a positive account balance, creates the first
   formal audit event, and prevents new transactions in the submitted sheet.

### Scenario C: Management Review and Allowed Self-Review

1. Verify cost center managers see only assigned transactions.
2. Verify an authorized manager may decide their own transaction when scope and stage match.
3. Record approve, reject, and return decisions; verify reject/return require explanations.
4. Verify manager rejection remains visible and still routes to senior review.
5. Verify the senior manager may disagree without changing the earlier decision.
6. Verify sheet status remains the earliest outstanding stage while individual transactions may
   progress.

### Scenario D: Correction and Immutable Revision

1. Return a transaction from a management or finance stage.
2. Verify only the purchaser can edit the correction draft.
3. Change amount, date, description, classification, and evidence as permitted.
4. Resubmit and verify exactly one new revision becomes active at cost-center review.
5. Verify every earlier revision, decision, evidence state, actor, and audit value remains visible.

### Scenario E: Shared Finance Queue and Atomic Settlement

1. Verify both finance officers see the same finance-stage queue.
2. Verify a senior-approved transaction may be approved, rejected, or returned.
3. Verify a senior-rejected transaction cannot be approved by finance.
4. Approve an eligible transaction and verify exactly one consumption movement and balance change.
5. Replay the same command ID and verify the original result is returned without duplication.
6. Retry with the same command ID and different payload and verify conflict.
7. Force a failure between decision and movement persistence and verify no partial effect commits.
8. Submit simultaneous approvals and verify one succeeds while the other safely conflicts or
   replays.
9. **FR-085 — superseded attempt.** Record an approval, then submit a *second, differently-identified*
   rejection against the same transaction. Verify: the approval still stands; **no** second decision,
   revision, workflow transition, or balance movement is created; the response is `409` naming that a
   decision is already recorded; and exactly one `SUPERSEDED_ATTEMPT` audit event exists, carrying both
   the attempted rejection and the approval in force. Verify the event is **not** typed as a rejection.
10. **FR-085 — revision changed underneath.** Return a transaction, correct it, and submit a decision
    composed against the pre-correction revision. Verify it is refused on the same terms and that the
    active revision in the audit event is the current one.
11. **FR-040 — unsettleable settlement.** Deactivate the purchaser's active account in the sheet's
    currency, then attempt final finance approval. Verify the approval is refused `422` **naming the
    account**, the transaction remains at the finance stage, no balance movement or consumption record
    is created, **no account is auto-provisioned as a side effect**, and the sheet does not complete.
    Restore the account and verify the approval then succeeds normally.

### Scenario F: Evidence, Audit, and Disabled Users

1. Delete draft evidence and verify its metadata and binary are removed before submission.
2. Deactivate submitted evidence and verify the binary/history remain accessible to authorized
   users.
3. Verify unauthorized users cannot list, preview, download, link, or export the evidence.
4. Disable a historical actor and verify the stored UID, display name, role, decisions, and events
   remain unchanged.
5. Attempt to edit/delete audit events as administrator and verify no supported operation exists.
6. If public links are enabled, verify only administrators create/deactivate them, and create/deactivate
   events are audited.
7. **FR-031 — the token is the whole authority.** Request `GET /public/evidence/{token}` with **no
   session and no credentials**. Verify the evidence is returned, and that the holder holds **no** role
   or scope on the transaction — the link grants access without a session.
8. **Uniform refusal.** Compare three responses byte-for-byte: an unknown token, a **deactivated**
   token, and an **expired** token. All three must be identical `404`s, disclosing neither that a link
   ever existed nor when it lapsed.
9. **Anonymous audit.** Verify each access wrote a configuration-audit event whose viewer is recorded as
   `anonymous` with timestamp and IP, and that the viewer field is neither null nor absent.
10. **Media-type policy holds on the public path.** Upload inline-safe media (an image, a PDF, plain
    text) and verify each renders inline; upload a scriptable type and verify it is served as
    `application/octet-stream` with `attachment`, never rendered inline. FR-060 must not be relaxed here
    — this is the only route reachable by a viewer who never authenticated.
11. **No second mechanism.** Verify the public route serves no login challenge, no CAPTCHA, and no
    application-level rate limiter, and that brute-force protection comes from the platform limiter.

### Scenario G: Reports and Exports

1. Verify purchaser, manager, senior, finance, and administrator reports contain exactly their
   permitted records.
2. Verify headline totals follow the latest effective outcome and stage totals remain separate.
3. Generate CSV, XLSX, PDF, and grouped evidence ZIP exports.
4. Verify spreadsheet formula injection is escaped.
5. Verify the XLSX sheet layout and ZIP manifest match `contracts/exports.md`.
6. Verify Persian RTL text, mixed-direction identifiers, amounts, and dates render correctly in
   PDF.

## 7. Browser, RTL, Responsive, and Accessibility Matrix

Run each primary role journey at minimum in:

| Browser | Desktop viewport | Mobile-sized viewport |
|---------|------------------|-----------------------|
| Chrome | 1440 × 900 | 390 × 844 |
| Edge | 1440 × 900 | 390 × 844 |
| Firefox | 1440 × 900 | 390 × 844 |

Validate:

- Persian strings and right-to-left direction;
- logical alignment of navigation, tables/cards, dialogs, filters, and actions;
- no page-level horizontal scrolling or clipped primary action;
- readable mixed-direction IDs, filenames, currencies, and numbers;
- keyboard-only completion of each primary journey (User Stories 1–7), driven without a pointer, with a
  visible focus indicator on every interactive element reached. A focus indicator the active theme
  renders as absent **fails** this check — the journey must be repeated under dark and high-contrast,
  not only under the default theme;
- prohibited actions are absent in the UI and rejected by direct API calls.

Run the keyboard-only pass under light, dark, and high-contrast host themes.

**What enforces it.** The indicator is declared once in `src/styles/app.scss` (`:focus-visible`, with an
explicit `outline-style`/`outline-width`/`outline-offset`, restated in system colour keywords under
`@media (forced-colors: active)`), and is verified in two places:

- `tests/E2E/keyboard-accessibility.spec.ts` — the real check. Drives the journey with `Tab`/`Enter` only,
  reads the computed `outline-style`/`outline-width`/`outline-color` of every element reached, and runs the
  whole thing once per theme. The theme is set on the **context** (`colorScheme` / `forcedColors`), which
  is why each theme is its own `describe` block rather than a loop: a loop would measure whichever theme
  happened to be active and name no theme in the failure.
- `tests/Frontend/keyboard-accessibility.spec.ts` — the floor under it, in `npm run check`. Asserts the
  declaration exists with an explicit style and width, is scoped to `:focus-visible`, restates itself for
  forced colours, and that no component removes it. A declaration is not a rendering and this file does
  not claim to be one; it exists so deleting the rule fails `npm run check` rather than waiting for a
  manual pass, which is how this suite's Playwright projects went unused in the first place.

Record browser/version, viewport, role, dataset, theme, result, and failure evidence. Automated Playwright
Firefox results do not replace the final manual smoke in the current installed Firefox browser.

**Out of scope for V1 (FR-076, clarified 2026-10-07):** form control label association, error-message
association with the offending field, and announced (live-region) status for mutations are deferred to
V2. Do not record these as met, and do not report them in V1 release readiness.

## 8. Performance Validation

### 8.1 Latency (SC-008 — informational in V1, not an acceptance gate)

Using the target-volume fixture:

1. Measure navigation-to-usable time for sheet list, sheet detail, transaction detail, audit
   history, and evidence list.
2. Exercise common filters and pagination.
3. Record at least 20 runs per view after warm-up and calculate the 95th percentile.
4. Pass when at least 95% of these routine views are usable within 3 seconds under normal local
   operating conditions.
5. Profile any failure before adding caches; first verify query scope, indexes, bounded pages, and
   avoidance of per-row queries.

**Record the result as informational, not as SC-008 satisfied.** Verification of SC-008 at the stated
scale is deferred to post-V1, so V1 release readiness records the criterion as **not measured** with
that reason, and a run below the stated scale does not become acceptance evidence by being reported.
A usable view is one that has finished loading and is showing the rows the user is entitled to see,
with no error or empty state standing in for them — measured end to end as a user sees it.

### 8.2 Error rate (FR-073 — a V1 release-readiness figure owned by T135)

**Producer**: `scripts/measure-error-rate.mjs`. The arithmetic and the two excluded classes are
`scripts/lib/error-rate.mjs`; `npm run check:error-rate` verifies the classification and the aggregation
(21 cases) so the figure's *definition* is gated even when the figure is not measured.

```bash
# One session, no concurrent traffic, and nothing else hitting the instance.
PETTYCASH_REQUEST_LOG=test-results/fr073-php.ndjson   scripts/dev/phpunit tests/Contract
PETTYCASH_REQUEST_LOG=test-results/fr073-e2e.ndjson   npm run test:e2e
npm run measure:error-rate -- test-results/fr073-php.ndjson test-results/fr073-e2e.ndjson
```

Both suites record every response to the log — the PHP half through `OcsTestClient`'s `on_stats`, the
browser half through `page.on('response')` in `tests/E2E/record-responses.ts`. The unit and integration
suites issue no HTTP requests and so contribute nothing; that is stated by the script rather than assumed.
`scripts/dev/phpunit` writes the log inside the container and copies it out, because the app tree is not
writable by the identity PHPUnit runs as.

1. The figure is **5xx over all requests issued**, excluding two classes that are not defects.
2. **Exclude deliberately provoked 4xx/409/422** from both numerator and denominator: a request the
   application correctly refused is not a defect. These are still **published**, because their share
   measures how thorough the suite is rather than how healthy the instance is.
3. **Exclude environment-induced responses** from both — a Nextcloud brute-force `429`, an
   infrastructure or platform `5xx`, a transport failure.
4. The figure must be **published with the excluded count**.
5. **If environment-induced responses exceed 1% of all requests issued, record the figure as *not
   measured*,** naming environment contamination as the reason.

**The 1% ceiling applies to the environment-induced class alone, not to both classes together.** A
contract suite whose job is asserting refusals provokes 4xx constantly — a threshold over the combined
share would make the figure unproducible for any real contract run, and would penalise the more thorough
suite. This was not a theoretical concern: the first implementation applied it to both and its own
self-test failed on the very first scenario.

**Two environment facts that decide whether a run is evidence at all**, both found on 2026-10-07:

- **The measurement run throttles itself.** `OcsTestClient` authenticates with HTTP basic auth, so every
  contract request performs a login attempt, and Nextcloud's brute-force limiter is IP-scoped. A full
  contract suite is hundreds of requests. On this shared instance the limiter answers `429` partway
  through, after which every remaining response is contamination. Measured: 387 requests issued, 0 counted,
  100% environment-induced, figure **not measured**.
- **The probe must run from the same vantage point as the run.** The suites reach the app on
  `http://localhost` *inside* the container; a host-side script reaches it on a different port. The script
  uses `PETTYCASH_BASE_URL` (or `--base-url=`) and prints what it probed, so a wrong address reads as an
  address rather than as a dead instance.

Run the measurement in **one session with no concurrent traffic**, and let the limiter's window lapse
first. This project shares one Nextcloud instance with other applications, so a contended run is an
expected operational condition, not a rare accident.

**Why step 5 exists.** A contended run is not evidence *in either direction*: throttled responses
inflate the denominator with traffic that never exercised application code while suppressing the
successful requests that would have exercised it, so a mostly-throttled run yields a ratio that looks
better than a clean one. Treat `429`-stormed full-suite output as **not evidence**, not as a noisy pass.

**Zero data loss** is a separate claim, established by the atomicity and failure-injection suites and
never inferred from either figure above.

## 9. Recovery and Non-Destructive Operations

- Never remove the shared Nextcloud or database volumes as part of testing.
- Migrations are forward-only and are tested by clean installation on an empty database plus
  forward application of the full V1 migration chain in version order. No test requires, or may
  depend on, legacy table names or a pre-V1 database.
- Prototype tables left in a shared development database are unmanaged: no migration, test, or tool
  may read, write, rename, or drop them.
- If evidence write succeeds but database persistence fails, compensation removes the new orphan.
- A reconciliation diagnostic reports orphan AppData files and missing binaries without deleting
  anything automatically.
- Technical failures are written through host logging; they never substitute for business audit.
- Rollback of an application release means restoring the prior app package and compatible schema
  backup according to the host's established process, not rewriting audit or revision history.
