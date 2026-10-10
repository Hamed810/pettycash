#!/usr/bin/env node
/**
 * FR-073: produce the one error-rate figure V1 measures, or refuse to.
 *
 * The procedure is `quickstart.md` §8.2. This script is the arithmetic and the two guards it requires;
 * the recorded figure and its exclusions travel together in the output so the release-readiness record
 * cannot quote a number without them.
 *
 * ## Why a script and not a paragraph in the checklist
 *
 * `quickstart.md` already described the calculation and nothing computed it — the same shape as T397's
 * "the safety property exists but the specified behaviour does not", one layer out. The reason it stayed
 * undescribed work is that the calculation is easy to do *wrong in four separate ways*, and each wrong
 * way produces a number that looks fine:
 *
 * - counting provoked 4xx as defects, so the ratio tracks the assertion count;
 * - leaving throttled responses in the denominator, which **improves** the ratio as the instance worsens;
 * - reporting a ratio for a run that was mostly contamination;
 * - reporting the exclusions in a footnote rather than beside the figure, so a reader cannot tell how
 *   much of the run was unmeasured.
 *
 * ## Usage
 *
 *   scripts/measure-error-rate.mjs --self-test      # classification + aggregation (no network)
 *   scripts/measure-error-rate.mjs <request-log>…   # measure recorded runs
 *
 * A request log is newline-delimited JSON, one object per request: `{"status":404}` or
 * `{"error":"ECONNRESET"}`, optionally `{"status":502,"platform":true}`. `PETTYCASH_REQUEST_LOG`
 * selects where `scripts/dev/phpunit` and the Playwright reporter append to it.
 *
 * ## The pre-flight guard
 *
 * The run is refused outright unless the instance answers a probe **without** a `429`. This is not a
 * nicety: FR-073's clarification exists because "a contended run is not evidence in either direction",
 * and measuring anyway would publish a number derived from a run the script already knows is
 * contaminated. Refusing before reading a single log line is the only point at which the refusal is free.
 */

import { readFileSync } from 'node:fs'
import { summarise, report, selfTest } from './lib/error-rate.mjs'

const EXIT_REFUSED = 2
const EXIT_NOT_MEASURED = 3

function usage(message) {
	if (message) {
		console.error(`measure-error-rate: ${message}`)
	}
	console.error('')
	console.error('usage:')
	console.error('  scripts/measure-error-rate.mjs --self-test        verify the classification and aggregation')
	console.error('  scripts/measure-error-rate.mjs <log> [<log>…]     measure one or more recorded runs')
	console.error('')
	console.error('  --base-url=<url>   where this host reaches the instance (default: $PETTYCASH_BASE_URL,')
	console.error('                     else http://localhost:8081 — this project\'s own pettycash-lab)')
	console.error('')
	console.error('  Produce a log with PETTYCASH_REQUEST_LOG=<path> scripts/dev/phpunit [suite] and, for the')
	console.error('  browser half, PETTYCASH_REQUEST_LOG=<path> npm run test:e2e.')
	return EXIT_REFUSED
}

/**
 * Probe the instance before measuring.
 *
 * A single unauthenticated `GET` of the app's OCS base. It is answered `401` or `404` — both fine, both
 * proof the instance is up and both proof it is *not* throttling us. Only `429` and a transport failure
 * stop the run, because both mean the log we are about to read would be contaminated by the limiter.
 */
async function probeInstance(baseUrl) {
	let response
	try {
		response = await fetch(`${baseUrl}/ocs/v2.php/apps/pettycash/api/v1/me`, {
			headers: { 'OCS-APIRequest': 'true', Accept: 'application/json' },
			redirect: 'manual',
		})
	} catch (error) {
		return { ok: false, reason: `the instance did not answer: ${error.message}` }
	}

	if (response.status === 429) {
		return {
			ok: false,
			reason: 'the instance answered 429. Its brute-force limiter is still throttling, and this '
				+ 'project shares one instance with other applications, so a run now would be mostly '
				+ 'throttled traffic. Wait for the window to lapse and re-run.',
		}
	}

	if (response.status >= 500) {
		return {
			ok: false,
			reason: `the instance answered ${response.status}. An infrastructure or platform fault makes `
				+ 'every response in the run unattributable to this application.',
		}
	}

	return { ok: true }
}

function readLog(path) {
	const lines = readFileSync(path, 'utf8').split('\n').filter((line) => line.trim() !== '')
	const requests = []
	const unparseable = []

	lines.forEach((line, index) => {
		try {
			requests.push(JSON.parse(line))
		} catch {
			// Recorded rather than dropped. A log line that cannot be read is a request whose response is
			// unaccounted for, and silently skipping it would understate the denominator.
			unparseable.push(`${path}:${index + 1}`)
		}
	})

	return { requests, unparseable }
}

async function main() {
	const args = process.argv.slice(2)

	if (args.includes('--self-test')) {
		return selfTest()
	}

	const explicitBase = args.find((arg) => arg.startsWith('--base-url='))
	const logs = args.filter((arg) => !arg.startsWith('--'))
	if (logs.length === 0) {
		return usage('at least one request log is required.')
	}

	// `PETTYCASH_BASE_URL`, not `PETTYCASH_TEST_BASE_URL`. The two name **different vantage points**:
	// the contract suite runs *inside* the container and reaches the app on `localhost`, while this
	// script runs on the host, where this project's own lab is published on 8081. Preferring the
	// container-facing variable made the probe fail to connect at all — indistinguishable from a dead
	// instance, and for a reason that has nothing to do with FR-073.
	//
	// The default tracks `playwright.config.ts` and must move with the lab. It was left at 8080 after
	// this project got its own `pettycash-lab`, and the symptom was the same as a dead instance: every
	// measurement refused with "fetch failed", which reads as a network problem rather than as a
	// default naming a lab that no longer runs.
	const baseUrl = (
		explicitBase?.slice('--base-url='.length)
		?? process.env.PETTYCASH_BASE_URL
		?? 'http://localhost:8081'
	).replace(/\/$/, '')

	console.log(`measure-error-rate: probing ${baseUrl} before measuring...`)
	const probe = await probeInstance(baseUrl)
	if (!probe.ok) {
		console.error('measure-error-rate: REFUSED before measuring.')
		console.error(`  ${probe.reason}`)
		console.error('  Set PETTYCASH_BASE_URL (or pass --base-url=…) if that is not where this host')
		console.error('  reaches the instance the suites just ran against.')
		return EXIT_REFUSED
	}

	let requests = []
	const unparseable = []
	for (const path of logs) {
		const read = readLog(path)
		requests = requests.concat(read.requests)
		unparseable.push(...read.unparseable)
	}

	if (requests.length === 0) {
		console.error(`measure-error-rate: REFUSED — ${logs.join(', ')} contains no requests.`)
		console.error('  Instrument PETTYCASH_REQUEST_LOG for the suites you intend to measure, or pass')
		console.error('  the wrong file. A figure for a run that did not happen is not a figure.')
		return EXIT_REFUSED
	}

	const summary = summarise(requests)
	console.log(report(summary))

	if (unparseable.length > 0) {
		console.log('')
		console.log(`  WARNING: ${unparseable.length} log line(s) could not be read and are excluded from`)
		console.log(`  both numerator and denominator: ${unparseable.slice(0, 10).join(', ')}`)
		console.log('  Each one is a request whose response is unaccounted for.')
	}

	console.log(`  sources ................ ${logs.join(', ')}`)

	if (summary.contaminated) {
		// Distinct from the refusal above: the run happened, and the honest output is the number of
		// requests issued plus the reason no figure can be drawn from them.
		return EXIT_NOT_MEASURED
	}

	return 0
}

process.exit(await main())