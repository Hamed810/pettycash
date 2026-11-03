# Bug Verification: Transaction creation on a plain-HTTP non-loopback origin

- **Slug**: transaction-uuid
- **Tested**: 2026-10-24
- **Closed**: 2026-10-24
- **Assessment**: ./assessment.md
- **Fix**: ./fix.md
- **Result**: verified
- **Status**: closed — verified

## Summary

The original failure was reproduced in Chromium against this project's own lab over plain HTTP at
`http://192.168.183.129:8081`: `isSecureContext` is `false`, `crypto.randomUUID` is `undefined`, and
a direct call throws `TypeError: crypto.randomUUID is not a function`. After the fix, the real
purchaser journey created a draft transaction through the UI on that same origin, and the served
`js/pettycash-main.mjs` contains the guarded CSPRNG fallback. Changed-area tests, type-check, and
lint pass; no related regression was found.

## Checks Performed

| Check | Command / Action | Result | Notes |
|-------|------------------|--------|-------|
| Reproduction, pre-fix failure mode | Playwright/Chromium probe of `http://192.168.183.129:8081/login` | pass (symptom confirmed) | Real non-loopback plain-HTTP origin: `isSecureContext=false`, `randomUUID=undefined`, direct call throws. `getRandomValues` is available. |
| Reproduction, post-fix behaviour | `PETTYCASH_BASE_URL=http://192.168.183.129:8081 npx playwright test tests/E2E/purchaser-submit.spec.ts -g "should add a draft transaction with minimum data" --project=chromium-desktop` | pass | 1 test passed in 58.6s. A sheet and a draft transaction were created through the UI; the row appeared, so the `POST .../transactions` request was sent and accepted. |
| Served bundle contains the fix | `curl http://192.168.183.129:8081/custom_apps/pettycash/js/pettycash-main.mjs` then inspect `randomUUID` occurrences | pass | Only the guarded feature check and guarded return inside the bundled `commandId()` helper are present. |
| New / updated tests | `npx vitest run tests/Frontend/http.spec.ts tests/Frontend/Component/ManagementReview.spec.ts tests/Frontend/Component/PurchaserFlow.spec.ts --testTimeout=20000` | pass | 3 files, 211 tests passed, including all five tests added by the fix. |
| Regression suite, initial post-fix run | `npx vitest run` | pass | 26 files, 541 tests passed (52s) on the unchanged tree, before the verification reruns. |
| Regression suite, repeat under host load | `npx vitest run --maxWorkers=2 --testTimeout=30000 --reporter=json` | fail (environmental) | 2 unrelated failures in `NextcloudVueContract.spec.ts` at collection (`STACK_TRACE_ERROR`); that spec passes 6/6 when run alone. Earlier repeat under load produced 4 timeouts in other unrelated specs. Host load average was 6–11 on 4 CPUs. |
| Lint | `npx eslint` on the eight changed source/test files | pass | 0 errors; 14 pre-existing template warnings, none on changed lines. |
| Type-check | `npm run typecheck` | pass | No diagnostics. |

## Output Excerpts

Pre-fix probe on the insecure origin:

```json
{
  "origin": "http://192.168.183.129:8081",
  "httpStatus": 200,
  "isSecureContext": false,
  "randomUUID": "undefined",
  "getRandomValues": "function",
  "oldCall": "TypeError: crypto.randomUUID is not a function"
}
```

Post-fix transaction journey on the same origin:

```text
Running 1 test using 1 worker
[1/1] [chromium-desktop] › tests/E2E/purchaser-submit.spec.ts:229:2 › Purchaser Capture and Submission › should add a draft transaction with minimum data
  1 passed (58.6s)
```

Changed-area unit tests:

```text
Test Files  3 passed (3)
     Tests  211 passed (211)
```

Initial full-suite run after the fix (unchanged tree):

```text
Test Files  26 passed (26)
     Tests  541 passed (541)
```

Repeat full-suite run under host load, and the failing spec in isolation:

```text
Test Files  1 failed | 25 passed (26)
     Tests  2 failed | 539 passed (541)
NextcloudVueContract.spec.ts :: ... :: Error: STACK_TRACE_ERROR

# same spec alone:
Test Files  1 passed (1)
     Tests  6 passed (6)
```

Served bundle:

```js
function kv(){const e=globalThis.crypto;if(e&&typeof e.randomUUID=="function")return e.randomUUID();
if(!e||typeof e.getRandomValues!="function")throw new Error("A secure random source is required to generate a command ID");
const t=e.getRandomValues(new Uint8Array(16)),n=new DataView(t.buffer,t.byteOffset,...
```

## Residual Risks

- E2E was exercised in Chromium desktop only, for the transaction-creation journey. Other browsers and other mutation journeys were not repeated on the insecure origin; they share the same `commandId()` helper and are covered by unit tests.
- The origin used was a plain-HTTP IP address, not a plain-HTTP domain name. Nextcloud's trusted-domain configuration only permits the lab IP, so a domain origin could not be loaded without changing server configuration. The fix is host-agnostic feature detection, and the unit tests simulate the missing `randomUUID` directly.
- `tests/E2E/audit-evidence.spec.ts` still calls `crypto.randomUUID()` inside `page.evaluate`; those test-only calls would fail if that suite were pointed at a plain-HTTP origin. No product code does this any more.
- Full-suite reliability on this host is load-sensitive: heavy component specs can exceed the default 5s timeout or fail during collection when several other runtimes compete for 4 CPUs. The failures moved between runs and each failing spec passed in isolation.
- If a browser exposes neither `randomUUID` nor `getRandomValues`, `commandId()` fails closed; the user still sees a raw error rather than a translated explanation (an assessment follow-up, outside this fix).
- The Playwright run created one sheet and one draft transaction in the development lab as its normal fixture data.

## Recommendation

Close the bug as verified. The original failure was reproduced on a genuine plain-HTTP non-loopback
origin, and the same transaction-creation journey now completes there against the served, rebuilt
bundle. The intermittent full-suite failures are unrelated load-induced timeouts in
`NextcloudVueContract.spec.ts`, not regressions from this fix. Consider adding a dedicated Playwright
regression that removes `crypto.randomUUID` via `addInitScript` so the insecure-context path is
exercised without relying on an operator-supplied LAN URL.

## Closure

- **Disposition**: closed — verified
- **Evidence**: `./assessment.md`, `./fix.md`, and the checks recorded above.

### Verified

- Real-browser plain-HTTP IPv4 transaction creation: in Chromium at `http://192.168.183.129:8081`,
  `isSecureContext=false` and `crypto.randomUUID` is `undefined`, yet the post-fix purchaser journey
  created a draft transaction through the UI, and the served bundle contains the guarded CSPRNG
  fallback.
- Focused tests: 211/211 passed across `http.spec.ts`, `ManagementReview.spec.ts`, and
  `PurchaserFlow.spec.ts`.
- Initial post-fix full suite: 541/541 passed across 26 files on the unchanged tree.

### NOT TESTED — remaining deployment combinations

- Plain HTTP with a domain-name origin.
- HTTPS with an IP-address origin.
- HTTPS with a domain-name origin.
- Firefox, Edge, WebKit/Safari, and mobile browsers.
- Mutation journeys other than transaction creation on the insecure origin (sheet, evidence,
  decision, configuration, account, export).

No claim is made that all deployment modes were tested. The verified combination is plain HTTP +
IPv4 literal + Chromium desktop.

### Separate follow-up concern — host-load test instability

- Later full-suite reruns under high host load produced unrelated failures in
  `NextcloudVueContract.spec.ts` (collection-stage `STACK_TRACE_ERROR`) and timeouts in heavy
  component specs; the failing specs passed in isolation.
- This is a separate test-environment stability concern, not a regression from this fix. It does not
  block closure of the bug.

