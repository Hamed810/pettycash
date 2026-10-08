import { appendFileSync, mkdirSync } from 'node:fs'
import { dirname } from 'node:path'
import type { Page } from '@playwright/test'

/**
 * FR-073: record every HTTP response the browser sees, for the error-rate figure.
 *
 * ## Why the browser half is recorded at `page.on('response')`
 *
 * Playwright's own reporting is a pass/fail record per test. It cannot say which responses were `429` or
 * `5xx`, which is the entire measurement — and the three ways this figure can be wrong all live in
 * responses a test retried, swallowed, or simply passed over. So the responses are captured at the one
 * place they all pass through.
 *
 * `on('response')` rather than `on('requestfinished')` because a response is what carries a status, and
 * a request that never completed has none. A failed navigation produces no `response` event, so the
 * accompanying `requestfailed` listener is what makes a transport failure countable at all — and FR-073
 * excludes those from the ratio rather than letting them vanish from the denominator.
 *
 * ## Opt-in, and opt-in *only*
 *
 * `PETTYCASH_REQUEST_LOG` unset writes nothing. The figure is measured in a dedicated session; a file
 * appearing on every test run would be a side effect of running the tests rather than of measuring them.
 *
 * The listener is attached per page by a spec that calls this, not installed globally by `global-setup`.
 * A global listener would have to reach every page through `context.on('page')` and would then be
 * invisible at each call site, so a reader of a spec could not tell that its requests were being counted.
 */
export function recordResponses(page: Page): void {
	const path = process.env.PETTYCASH_REQUEST_LOG
	if (!path) {
		return
	}

	try {
		mkdirSync(dirname(path), { recursive: true })
	} catch {
		// A measurement must not break the thing it measures. A spec that cannot open the log runs
		// normally, and the resulting figure covers fewer requests rather than being wrong about them.
		return
	}

	const write = (line: Record<string, unknown>): void => {
		try {
			appendFileSync(path, `${JSON.stringify(line)}\n`)
		} catch {
			// Same reasoning as above.
		}
	}

	page.on('response', (response) => {
		write({ status: response.status() })
	})

	page.on('requestfailed', (request) => {
		write({
			status: null,
			// `failure()?.errorText` is Playwright's description of the transport failure — the class
			// FR-073 excludes as a "transport failure".
			error: request.failure()?.errorText ?? 'request failed',
		})
	})
}