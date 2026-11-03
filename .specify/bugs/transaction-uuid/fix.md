# Bug Fix: Transaction creation fails when `crypto.randomUUID` is unavailable

- **Slug**: transaction-uuid
- **Fixed**: 2026-10-24
- **Assessment**: ./assessment.md
- **Status**: applied

## Summary

`commandId()` now feature-detects `crypto.randomUUID` and, when it is absent (plain-HTTP
non-secure contexts), builds a UUID v4 from `crypto.getRandomValues()` instead of throwing.
Both component-level raw calls were removed, and `DecisionForm` now threads the identity it
emits through the reviews store and service so the emitted command ID is the one actually sent
in `X-PettyCash-Command-ID`.

## Changes

| File | Change | Notes |
|------|--------|-------|
| `src/services/http.ts` | modified | `commandId()` keeps `randomUUID()` when available; otherwise fills 16 CSPRNG bytes, sets the v4 version/variant bits, and formats a 36-character UUID. Throws if no secure random source exists. |
| `src/components/evidence/EvidenceUploader.vue` | modified | Drops the raw `crypto.randomUUID()` call; `uploadEvidence()` already supplies `options.commandId ?? commandId()`. |
| `src/components/review/DecisionForm.vue` | modified | Imports the helper as `newCommandId`, passes the generated ID to `reviewsStore.recordDecision()`, and emits that same ID. |
| `src/services/reviewsApi.ts` | modified | `recordDecision()` accepts `CommandOptions = {}` and forwards `options.commandId` to `mutate()`. |
| `src/stores/reviews.ts` | modified | `recordDecision()` accepts and forwards `CommandOptions`. |
| `tests/Frontend/http.spec.ts` | tests added | Fallback UUID shape, CSPRNG-only guarantee, and mutation-header coverage. |
| `tests/Frontend/Component/ManagementReview.spec.ts` | test added | Decision submission with `randomUUID` absent; asserts the emitted ID equals the wire header. |
| `tests/Frontend/Component/PurchaserFlow.spec.ts` | test added | Evidence upload with `randomUUID` absent. |
| `js/*.mjs`, `js/*.license`, `css/*` | regenerated | `npm run build`; content-hashed bundle names rotated as expected. No asset was hand-edited. |

## Diff Highlights

```ts
// src/services/http.ts
export function commandId(): string {
	const c = globalThis.crypto
	if (c && typeof c.randomUUID === 'function') {
		return c.randomUUID()
	}
	if (!c || typeof c.getRandomValues !== 'function') {
		throw new Error('A secure random source is required to generate a command ID')
	}

	const bytes = c.getRandomValues(new Uint8Array(16))
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
	// UUID v4: version 4 and RFC 4122 variant bits.
	view.setUint8(6, (view.getUint8(6) & 0x0f) | 0x40)
	view.setUint8(8, (view.getUint8(8) & 0x3f) | 0x80)
	const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')

	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}
```

```ts
// src/components/review/DecisionForm.vue
const decisionCommandId = newCommandId()
await reviewsStore.recordDecision(props.transactionId, {
	decision: selectedDecision.value,
	explanation: explanation.value.trim(),
	expectedVersion: props.expectedVersion ?? 0,
}, { commandId: decisionCommandId })
emit('submit-decision', {
	decision: selectedDecision.value,
	explanation: explanation.value.trim(),
	commandId: decisionCommandId,
})
```

## Tests Added or Updated

- `tests/Frontend/http.spec.ts::commandId() > builds a UUID v4 from getRandomValues when randomUUID is unavailable` — asserts version `4`, RFC 4122 variant, 36-character length, and per-call uniqueness.
- `tests/Frontend/http.spec.ts::commandId() > does not weaken the fallback with Math.random` — asserts `Math.random` is never consulted.
- `tests/Frontend/http.spec.ts::mutate() > still sends a valid command id when crypto.randomUUID is unavailable` — asserts the generated header matches the v4 pattern.
- `tests/Frontend/Component/ManagementReview.spec.ts::DecisionForm > records a decision when crypto.randomUUID is unavailable` — asserts the form completes, emits a valid UUID, and sends that same value in `X-PettyCash-Command-ID`.
- `tests/Frontend/Component/PurchaserFlow.spec.ts::Evidence Upload > should start an upload when crypto.randomUUID is unavailable` — asserts the uploader delegates to `uploadEvidence()` without a browser exception.

## Local Verification

- Commands run: `npx vitest run tests/Frontend/http.spec.ts` → 22 passed (1 file).
- Commands run: `npx vitest run tests/Frontend/Component/ManagementReview.spec.ts` → 41 passed (1 file).
- Commands run: `npx vitest run tests/Frontend/Component/PurchaserFlow.spec.ts` → 148 passed (1 file).
- Commands run: `npx vitest run` → 26 files passed, 541 tests passed.
- Commands run: `npm run typecheck` (`vue-tsc --noEmit`) → passed with no diagnostics.
- Commands run: `npx eslint` on the eight changed source/test files → 0 errors, 14 warnings; all warnings are pre-existing template style findings in `EvidenceUploader.vue` and `DecisionForm.vue` (prop optionality, attribute order, bracket newline) and none are on changed lines.
- Commands run: `npm run build` → success; tracked `js/` and `css/` assets regenerated.
- Manual checks: `grep -l randomUUID js/*.mjs` reports only `js/pettycash-main.mjs`; both occurrences are inside the bundled `commandId()` helper (the `typeof` feature check and the guarded return). No unguarded call remains in the shipped bundles.
- The working tree already contained unrelated modifications before this fix; no unrelated file was staged, reverted, or cleaned.

## Deviations from Assessment

- The assessment sketch used non-null index assertions for the version/variant bytes; the fix uses a `DataView` over the same buffer instead. The assessment explicitly allowed "an equivalent `DataView`/`Uint32Array` formulation", and it avoids assertions under `noUncheckedIndexedAccess`.
- The evidence-uploader regression lives in the existing `Evidence Upload` suite in `tests/Frontend/Component/PurchaserFlow.spec.ts` rather than in `StagedReceipts.spec.ts`. `StagedReceipts.spec.ts` does not exist in this repository, and the assessment allowed "an evidence-uploader-focused spec".
- The assessment listed threading the `DecisionForm` command ID as a "prefer also" item; it was implemented, including the store/service signature change, because the test now pins the emitted ID to the transmitted header.

No finding contradicted the assessment's root cause, and no server-side file was changed.

## Follow-ups

- Add a Playwright regression that loads a plain-HTTP non-loopback origin and removes `crypto.randomUUID` via `addInitScript`; the current `http://localhost:8081` E2E origin is a secure context and cannot expose this defect.
- Consider replacing the raw browser `TypeError` surfaced through `transactionStore.error` with a translated, actionable message when no secure random source exists.
- Codify plain-HTTP support as an explicit deployment requirement if it is genuinely required; the assessment's open question about production transport remains unanswered in repository artifacts.
- Expect content-hashed `js/` and `css/` filenames to change again on the next build; verify cache-busting during deployment rather than treating the asset churn as reviewable logic.
