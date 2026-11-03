# Bug Assessment: Transaction creation fails when `crypto.randomUUID` is unavailable

- **Slug**: transaction-uuid
- **Created**: 2026-10-24
- **Source**: pasted text (no URL supplied; no URL fetch performed)
- **Verdict**: valid
- **Severity**: high

## Report (verbatim or summarized)

> "Pettycash transaction creation fails with crypto.randomUUID is not a function. Browser evidence: window.isSecureContext=false and typeof crypto.randomUUID=undefined. Required compatibility: HTTP and HTTPS, both IP addresses and domain names. Inspect the actual source code, identify all affected call sites, determine the root cause, and propose a minimal secure fix. Do not modify production code during assessment." slug=transaction-uuid

No URL was supplied, so the URL trust policy did not apply and nothing was fetched.

The report's browser evidence was independently confirmed in Chromium against a temporary plain-HTTP origin on a non-loopback host:

```json
{
  "isSecureContext": false,
  "randomUUID": "undefined",
  "getRandomValues": "function"
}
```

The same probe confirmed that the proposed `getRandomValues` fallback produces a 36-character UUID v4 on that origin.

## Symptom

On a plain-HTTP origin whose host is not a loopback address, saving a new transaction throws `TypeError: crypto.randomUUID is not a function` and no transaction is created. The expected behaviour is that transaction creation works identically over HTTP and HTTPS, whether the origin is an IP address or a domain name.

The form surfaces the raw browser error through `transactionStore.error`, but no HTTP request is sent and no saved row appears. `TransactionFormView.persist()` catches the error and returns `false`; only `ApiError` status 409 receives special handling.

## Reproduction

1. Serve Nextcloud/PettyCash over plain HTTP on a non-loopback origin, for example `http://192.168.1.20:8081` or `http://pettycash.example.test`. Confirm in the browser console that `window.isSecureContext === false` and `typeof crypto.randomUUID === 'undefined'`.
2. Sign in, open a sheet, and start creating a transaction.
3. Fill the required fields and press Save.
4. Observe that no `POST /ocs/v2.php/apps/pettycash/api/v1/sheets/{sheetId}/transactions` request is sent, a `crypto.randomUUID is not a function` error occurs, and the transaction is not created.
5. Repeat over HTTPS, or over `http://localhost` / `http://127.0.0.1`, and observe that it succeeds because those origins are secure contexts and expose `crypto.randomUUID`.

The same failure also affects transaction updates/deletes, sheet mutations, evidence upload, decisions, configuration changes, account changes, and export requests, because they all use the same command-ID helper. See the call-site list below.

## Suspected Code Paths

### Direct `crypto.randomUUID()` calls in production source

- `src/services/http.ts:6` — `export function commandId(): string { return crypto.randomUUID() }`. This is the central command-ID generator. It is called unconditionally and is the first failure point for transaction creation.
- `src/services/http.ts:72` — `mutate()` has `id = commandId()` as a default parameter, so every mutation that does not supply an explicit command ID triggers the same failure.
- `src/components/review/DecisionForm.vue:144` — `const commandId = crypto.randomUUID()` runs before `reviewsStore.recordDecision`; recording a decision fails on an insecure origin even if the shared helper were fixed.
- `src/components/evidence/EvidenceUploader.vue:80` — `uploadEvidence(..., { commandId: crypto.randomUUID() })` runs before the upload request; evidence upload fails on an insecure origin independently of the shared helper.

### Transaction-creation path

- `src/views/transactions/TransactionFormView.vue:348` — `transactionStore.createNewTransaction(sheetId.value, draft.value)`.
- `src/views/transactions/TransactionFormView.vue:328-360` — `persist()` catches the thrown error; the store has already recorded it in `transactionStore.error`, and only `ApiError` status 409 receives special handling. The raw `TypeError` is rendered by the form's error note at lines 806-812.
- `src/stores/transactions.ts:82-97` — `createNewTransaction()` delegates to `createTransaction()`.
- `src/services/transactionsApi.ts:16` — `createTransaction()` calls `mutate(..., options.commandId ?? commandId())`.
- `src/services/http.ts:72` — `mutate()` uses the command ID on the `X-PettyCash-Command-ID` header.

### Indirect call sites that inherit the same failure through `commandId()`

- `src/services/transactionsApi.ts:16,29,33,55,59,63` — create, update, delete, decision, correction, and resubmit.
- `src/services/sheetsApi.ts:38,51,55,76` — create, update, submit, and delete sheets.
- `src/services/evidenceApi.ts:24,48,64,68` — upload evidence, delete evidence, create evidence links, deactivate evidence links.
- `src/services/reviewsApi.ts:43` — decision recording through `mutate()`'s default command ID.
- `src/services/configurationApi.ts:70,83,88,101,111,124,141` — currency, cost-centre, and expense-type mutations.
- `src/services/accountsApi.ts:62,74,88` — account mutations.
- `src/services/reportsApi.ts:149` — export requests call `commandId()` in the default argument.

### Test-only direct calls affected by the same secure-context rule

- `tests/E2E/audit-evidence.spec.ts:144` and `tests/E2E/audit-evidence.spec.ts:465` — `page.evaluate` code calls `crypto.randomUUID()` in the browser. The current lab uses `http://localhost:8081`, which is a secure context, so these pass today. They would fail if the E2E suite were pointed at a plain-HTTP non-loopback origin.

### Generated assets

The generated bundles under `js/` contain compiled copies of the same calls (for example the current `js/TransactionHeader-*.chunk.mjs`, `js/transactions-*.chunk.mjs`, and the main bundle). A source-only fix will not change the served application until `npm run build` regenerates them.

## Root Cause Hypothesis

`crypto.randomUUID()` is a secure-context-only Web Crypto API. On an insecure origin (`http://` with a non-loopback IP address or domain name), browsers set `isSecureContext = false` and do not expose `randomUUID`; the code calls it unconditionally, so ID generation throws before any HTTP request is constructed. The error is a browser compatibility/secure-context issue, not a Nextcloud route, controller, or server-side problem.

`crypto.getRandomValues()` remains available in insecure contexts, so the same command ID can be generated securely as a UUID v4 by filling 16 bytes from the CSPRNG and setting the version and variant bits. Confidence: **high**.

## Severity Rationale

**High**. The defect blocks the primary write workflow (creating a transaction) for every user on a plain-HTTP non-loopback origin, and because all mutating services share `commandId()`, it also breaks most other writes: sheet changes, evidence, decisions, configuration, accounts, and exports. It fails before the request is sent, so there is no data corruption or partial write, and HTTPS/localhost users are unaffected; that keeps it below critical. If plain HTTP is a mandatory production target rather than a compatibility requirement, the effective impact is a complete write outage and the severity should be treated as critical. The unconditional call has been present since the command-ID helper was introduced, so this is a long-standing compatibility defect rather than a new regression.

## Proposed Remediation

**Preferred**: Replace the unconditional `crypto.randomUUID()` in `src/services/http.ts` with a feature-detected, CSPRNG-backed UUID v4 generator, and route the two component-level direct calls through the shared helper.

Shape of the change:

```ts
export function commandId(): string {
	const c = globalThis.crypto
	if (c && typeof c.randomUUID === 'function') {
		return c.randomUUID()
	}
	if (!c || typeof c.getRandomValues !== 'function') {
		throw new Error('A secure random source is required to generate a command ID')
	}

	const bytes = c.getRandomValues(new Uint8Array(16))
	// UUID v4: version 4 and RFC 4122 variant bits.
	bytes[6] = (bytes[6]! & 0x0f) | 0x40
	bytes[8] = (bytes[8]! & 0x3f) | 0x80
	const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')

	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
```

Notes on the sketch:

- Feature detection is deliberately on `randomUUID`, not on `window.isSecureContext`. That covers both insecure contexts and older browsers that never shipped `randomUUID`.
- Do **not** use `Math.random()` or a timestamp fallback. Command IDs are idempotency keys; predictability would weaken replay protection and is unnecessary because `getRandomValues` is available in insecure contexts.
- `noUncheckedIndexedAccess` is enabled in `tsconfig.json`, hence the non-null assertions on `bytes[6]` and `bytes[8]` (or an equivalent `DataView`/`Uint32Array` formulation).
- Keep the output lowercase hexadecimal and exactly 36 characters. The contract declares `format: uuid`, and the server column is `string(36)`.

Then:

- `src/components/evidence/EvidenceUploader.vue:80` — the simplest change is to call `uploadEvidence(props.transactionId, file.value)` and let `evidenceApi.uploadEvidence()` supply the command ID through its existing `options.commandId ?? commandId()` path. Alternatively import and call `commandId()` explicitly.
- `src/components/review/DecisionForm.vue:144` — import the helper under a non-conflicting name, e.g. `import { commandId as newCommandId } from '@/services/http'`, and replace the raw call. Avoid `const commandId = commandId()`, which would hit the temporal dead zone through shadowing.
- Prefer also threading the generated decision ID into `reviewsStore.recordDecision` / `reviewsApi.recordDecision` / `mutate` so the ID emitted by the component is the one actually sent in `X-PettyCash-Command-ID`. Today the component generates an ID, emits it, and then discards it; `mutate()` generates a different one. That is an adjacent idempotency/traceability inconsistency, not the crash itself.
- Run `npm run build` so the tracked `js/` bundles are regenerated. Do not hand-edit minified assets.

**Alternatives**:

- Add a UUID library such as `uuid` and call `v4()`. It would work, but adds a dependency and bundle cost for a small, well-defined fallback. Not preferred.
- Have the server generate a command ID when the header is missing. Rejected: the contract requires a client-supplied ID, and a server-generated ID cannot let a retry replay the original command. `TransactionController` already documents that fallback as the cause of duplicate corrections.
- Declare plain HTTP unsupported and require HTTPS. Rejected by the report's stated compatibility requirement, and it does not help domain names served over HTTP.

**Files likely to change**:

- `src/services/http.ts` — add the fallback generator.
- `src/components/review/DecisionForm.vue` — remove the raw `crypto.randomUUID()` call.
- `src/components/evidence/EvidenceUploader.vue` — remove the raw `crypto.randomUUID()` call and rely on the service helper.
- `src/services/reviewsApi.ts` and `src/stores/reviews.ts` — only if the emitted decision command ID is threaded through to the request.
- `tests/Frontend/http.spec.ts` — fallback and mutation-header coverage.
- `tests/Frontend/Component/ManagementReview.spec.ts` — decision submission with `randomUUID` unavailable.
- `tests/Frontend/Component/StagedReceipts.spec.ts` or an evidence-uploader-focused spec — upload with `randomUUID` unavailable.
- Generated `js/*.mjs` and `js/*.license` — rebuilt by `npm run build`, not edited by hand.

**Tests to add or update**:

1. In `tests/Frontend/http.spec.ts`, temporarily replace `globalThis.crypto` with an object that has `getRandomValues` but no `randomUUID`, call `commandId()`, and assert:
   - the result matches the UUID v4 pattern (including version `4` and variant `[89ab]`);
   - the result is exactly 36 characters;
   - `Math.random` is never used;
   - two calls produce different values.
2. In the same file, call `mutate()` without an explicit command ID under the same stub and assert the request carries a valid `X-PettyCash-Command-ID` header.
3. In `ManagementReview.spec.ts` (or a dedicated `DecisionForm` spec), make `crypto.randomUUID` unavailable and submit a decision; assert the form completes and emits a valid UUID rather than throwing.
4. In `StagedReceipts.spec.ts` or an `EvidenceUploader` spec, make `crypto.randomUUID` unavailable, trigger an upload, and assert `uploadEvidence` is called with a valid command ID and no exception escapes.
5. Keep the existing `commandId()` test at `tests/Frontend/http.spec.ts:249-253`; it covers the secure-context path.
6. Consider a small Playwright regression that loads a plain-HTTP non-loopback origin and deletes `crypto.randomUUID` via `addInitScript`. The current localhost E2E origin cannot expose this bug because localhost is a secure context.

## Risks & Considerations

- **Security**: `getRandomValues` is a CSPRNG and is allowed in insecure contexts. A `Math.random()` or timestamp fallback would be weaker and should not be accepted merely to keep old browsers working. If no secure random source exists, failing closed is preferable.
- **Contract shape**: The fallback must set the v4 version/variant bits and remain 36 characters. The server currently enforces only the length bound, but the OpenAPI contract declares `format: uuid`, and existing tests expect a UUID shape.
- **Generated assets**: The application serves `js/` bundles. Fixing `src/` without rebuilding leaves the deployed app broken. Browser cache-busting hashes change on rebuild, so stale cached bundles should be considered during verification.
- **Raw technical error in the UI**: `transactionStore.error` is rendered directly, so the user sees the browser's English `crypto.randomUUID is not a function` message rather than an actionable, translated explanation. Improving that is outside the minimal compatibility fix but worth considering.
- **DecisionForm ID mismatch**: The component emits a command ID that is not the one sent on the wire. Threading it through is a small API change but touches the reviews store/service and needs its own test to avoid changing idempotency behaviour accidentally.
- **E2E coverage gap**: Existing E2E runs against `http://localhost:8081`, which is a secure context. A green E2E suite therefore does not prove HTTP/IP compatibility for this bug; unit tests must explicitly remove `randomUUID`.
- **No server change required**: Do not relax `CommandId` validation or make the header optional. The client must continue to send a valid ID so FR-084 idempotency and FR-085 superseded-attempt tracing remain intact.

## Open Questions

- [NEEDS CLARIFICATION: Is plain-HTTP support an explicit deployment requirement for this app, or is HTTPS expected in production? The report states HTTP and HTTPS are required, but no repository artifact was found that codifies plain-HTTP support as an acceptance criterion.]
- [NEEDS CLARIFICATION: Should `DecisionForm`'s emitted `commandId` be the same value sent in `X-PettyCash-Command-ID`? The current code generates one and discards it while `mutate()` generates another.]
- [NEEDS CLARIFICATION: Which exact browser matrix must be supported? `crypto.getRandomValues` is available in all current Nextcloud-class browsers, but a browser without it would need a different strategy rather than an insecure fallback.]
- [NEEDS CLARIFICATION: Should a secure-randomness failure be shown to the user as a translated, actionable error instead of the raw browser `TypeError` currently rendered by the form?]
