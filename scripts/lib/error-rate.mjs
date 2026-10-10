/**
 * FR-073's error-rate figure, and the two classes of response that must not enter it.
 *
 * ## What the figure is, and what it is not
 *
 * **5xx responses over all HTTP requests the acceptance suites issued**, excluding two classes that are
 * not defects. It is a *release-readiness* figure — the only success criterion measured during V1 — and
 * **not** an SLO and **not** an assertion that the application never fails. `quickstart.md` §8.2 is the
 * authoritative procedure; this module is the arithmetic it describes, separated out so it can be tested
 * without running a suite.
 *
 * ## The two excluded classes, and why they are different things
 *
 * 1. **Deliberately provoked** 4xx/409/422. The contract suite asserts refusals, so these are the suite
 *    working. Counting them would measure the assertion count, not the health of the application.
 *
 * 2. **Environment-induced**: a brute-force `429`, an infrastructure or platform `5xx`, a transport
 *    failure. This one is contamination the run did not intend, and it is the class FR-073 (clarified
 *    2026-10-07) added.
 *
 * They are kept apart in the output because the second is a *finding about the run* and the first is a
 * fact about the test plan. Reporting only their sum would let a clean ratio hide a run that was mostly
 * throttled.
 *
 * ## Why the second class is a 1% cliff rather than a smoothing term
 *
 * A `429` storm inflates the denominator with traffic that never exercised application code while
 * suppressing the successful requests that would have. Leaving those requests in the denominator *rewards*
 * a broken run — the ratio improves as the instance gets worse. So above the threshold the figure is not
 * reported at all, and the reason is published. This matches the treatment SC-008 and SC-010 already
 * receive (see `quickstart.md` §8.1): an unmeasured criterion is an honest answer; a badly measured one is
 * not.
 */

/** A response the contract suite provoked on purpose and which asserts nothing about health. */
export const PROVOKED_MIN = 400
export const PROVOKED_MAX = 499

/** The share of excluded responses above which the figure is not reported at all. */
export const EXCLUSION_THRESHOLD = 0.01

/**
 * Classify one recorded request.
 *
 * @param {{status?: number|null, error?: string|null}} request
 * @returns {'provoked'|'environment'|'counted'} which bucket the response falls into
 */
export function classify(request) {
	const status = request?.status ?? null
	const errored = Boolean(request?.error)

	// A transport failure never reached application code, so it is contamination rather than a defect —
	// and it has no status to classify by, which is exactly why this check comes first.
	if (errored || status === null) {
		return 'environment'
	}

	if (status === 429) {
		return 'environment'
	}

	// A 5xx is the defect the figure exists to count, **unless** it came from the platform rather than
	// from the application. There is no way to tell those apart from a status code alone, which is why
	// FR-073 requires the excluded count to be published: a reader needs to see how much of the run was
	// not attributable before trusting the ratio. Callers that *can* tell — the suites record
	// `error`/`platform` hints from the transport — pass them through; this function treats an
	// unclassifiable 5xx as counted, because under-counting a real fault is the worse error.
	if (status >= 500) {
		return request?.platform ? 'environment' : 'counted'
	}

	if (status >= PROVOKED_MIN && status <= PROVOKED_MAX) {
		return 'provoked'
	}

	return 'counted'
}

/**
 * Reduce a run's recorded requests to the figure, with the exclusions published beside it.
 *
 * @param {Array<{status?: number|null, error?: string|null, platform?: boolean}>} requests
 */
export function summarise(requests) {
	let counted = 0
	let defects = 0
	let provoked = 0
	let environment = 0
	let platform = 0

	for (const request of requests ?? []) {
		switch (classify(request)) {
			case 'counted':
				counted += 1
				if ((request.status ?? 0) >= 500) {
					defects += 1
				}
				break
			case 'provoked':
				provoked += 1
				break
			case 'environment':
				environment += 1
				if (request?.platform) {
					platform += 1
				}
				break
		}
	}

	const issued = counted + provoked + environment
	// **The ceiling applies to the environment-induced share alone.**
	//
	// The first version applied it to both excluded classes together, and the self-test caught it on the
	// very first scenario: one deliberately provoked 4xx in four requests is 25% excluded, so a contract
	// suite — whose entire job is asserting refusals — could never produce a figure at all.
	//
	// FR-073's own reasoning settles it. The rationale given for the 1% cliff is that "a `429` storm
	// inflates the denominator with traffic that never exercised application code while suppressing the
	// successful requests that would have", which is true of environment-induced responses and false of
	// provoked ones: a provoked 4xx *did* exercise application code, and its share measures how thorough
	// the suite is rather than how healthy the instance is. Applying one threshold to both would penalise
	// the more thorough suite, and would have made the figure unproducible for any real contract run.
	//
	// Provoked responses are still excluded and still published, on the same reasoning as the 2026-10-02
	// answer: a request the application correctly refused is not a defect.
	const excludedShare = issued === 0 ? 1 : environment / issued
	// With nothing issued there is no figure to report. Treating 0/0 as 0% or 100% would both be a claim
	// about a run that did not happen, so it takes the "not measured" branch for the same reason a
	// throttled run does.
	const contaminated = issued === 0 || excludedShare > EXCLUSION_THRESHOLD

	return {
		issued,
		counted,
		defects,
		provoked,
		environment,
		platform,
		excludedShare,
		/** Provoked responses are excluded from the ratio but do not make the run unmeasurable. */
		provokedShare: issued === 0 ? 0 : provoked / issued,
		contaminated,
		// `null` when contaminated. Never 0 and never NaN: a number here would be read as a measurement.
		errorRate: contaminated ? null : defects / counted,
	}
}

/**
 * The text the release-readiness record carries.
 *
 * Kept next to the arithmetic so the wording and the number cannot drift apart — the recorded figure is
 * the deliverable, and a sentence that promises a ratio the module refuses to produce is a false record.
 */
export function report(summary) {
	const lines = [
		'FR-073 error rate (V1 release readiness)',
		'',
		`  requests issued ........ ${summary.issued}`,
		`  counted (in the ratio) . ${summary.counted}`,
		`  deliberately provoked . ${summary.provoked}  (4xx/409/422 the contract suite asserts)`,
		`  environment-induced .... ${summary.environment}  (brute-force 429, platform 5xx, transport failure)`,
	]

	if (summary.environment > 0) {
		lines.push(`    of which platform .... ${summary.platform}`)
	}

	lines.push(
		'',
		`  environment share ...... ${(summary.excludedShare * 100).toFixed(3)}%  `
		+ '(the 1% ceiling FR-073 sets)',
		`  provoked share ......... ${(summary.provokedShare * 100).toFixed(3)}%  `
		+ '(excluded from the ratio; does not affect measurability)',
	)

	if (summary.contaminated) {
		lines.push(
			'',
			'  ERROR RATE: not measured',
			`  Reason: ${summary.issued === 0
				? 'the run issued no requests, so there is nothing to measure'
				: `environment contamination — environment-induced responses are `
					+ `${(summary.excludedShare * 100).toFixed(3)}% of the requests issued, above the 1% `
					+ 'ceiling FR-073 sets'}`,
			'',
			'  A contended run is not evidence in either direction: throttled responses inflate the',
			"  denominator with traffic that never exercised application code while suppressing the",
			'  successful requests that would have. Re-run in one session with no concurrent traffic.',
		)
	} else {
		lines.push(
			'',
			`  5xx responses ........... ${summary.defects}`,
			`  ERROR RATE ............. ${(summary.errorRate * 100).toFixed(4)}%`,
		)
	}

	lines.push(
		'',
		'  Note: this is a release-readiness figure, not an SLO. Zero data loss is a separate claim,',
		'  established by the atomicity and failure-injection suites and never inferred from this number.',
	)

	return lines.join('\n')
}

// --- repeatable regression procedure ---------------------------------------------------------
//
// `scripts/preflight.test.sh` is the precedent for verifying a script without a test runner, and
// Constitution V accepts a repeatable procedure as well as a test. This table is the classifier's whole
// specification, asserted rather than described — each case is a decision FR-073 or its clarification
// makes, and a change to any of them fails here.
const SELF_TEST_CASES = [
	// [label, request, expected bucket]
	['a 200 is counted', { status: 200 }, 'counted'],
	['a 201 is counted', { status: 201 }, 'counted'],
	['a 304 is counted', { status: 304 }, 'counted'],
	['an application 500 is the defect', { status: 500 }, 'counted'],
	['an application 503 is the defect', { status: 503 }, 'counted'],
	['a platform 5xx is excluded', { status: 502, platform: true }, 'environment'],
	['a brute-force 429 is excluded', { status: 429 }, 'environment'],
	['a transport failure is excluded', { status: null, error: 'ECONNRESET' }, 'environment'],
	['a 400 is provoked', { status: 400 }, 'provoked'],
	['a 403 is provoked', { status: 403 }, 'provoked'],
	['a 404 is provoked', { status: 404 }, 'provoked'],
	['a 409 is provoked', { status: 409 }, 'provoked'],
	['a 422 is provoked', { status: 422 }, 'provoked'],
]

export function selfTest() {
	const failures = []

	for (const [label, request, expected] of SELF_TEST_CASES) {
		const actual = classify(request)
		if (actual !== expected) {
			failures.push(`  ${label}: expected ${expected}, got ${actual}`)
		}
	}

	// The aggregation, not just the per-request classification. A classifier that buckets correctly but
	// divides wrongly still produces a plausible number, which is the failure mode this figure has.
	const scenarios = [
		{
			label: 'a clean run reports the ratio',
			requests: [{ status: 200 }, { status: 200 }, { status: 500 }, { status: 422 }],
			expect: (s) =>
				s.issued === 4 && s.counted === 3 && s.defects === 1 && s.provoked === 1 && s.environment === 0
				&& s.contaminated === false && s.errorRate === 1 / 3,
		},
		{
			// The case the first version got wrong: a suite that asserts refusals on purpose. One
			// provoked response in four is 25% "excluded" and must still produce a figure, because those
			// requests did exercise application code.
			label: 'a high provoked share still reports',
			requests: [{ status: 422 }, { status: 422 }, { status: 404 }, { status: 200 }],
			expect: (s) => s.provokedShare === 0.75 && s.contaminated === false && s.errorRate === 0,
		},
		{
			label: 'a 429 storm is not measured',
			requests: [{ status: 200 }, { status: 429 }, { status: 429 }],
			expect: (s) => s.issued === 3 && s.environment === 2 && s.contaminated === true && s.errorRate === null,
		},
		{
			label: 'a run with no requests is not measured',
			requests: [],
			expect: (s) => s.issued === 0 && s.contaminated === true && s.errorRate === null,
		},
		{
			label: 'exactly 1% environment-induced still reports',
			// 2 of 200 is exactly the ceiling, and the requirement is "exceeds 1%", so the boundary itself
			// must still be measured — otherwise the comparison is untested on both sides.
			requests: [...Array.from({ length: 198 }, () => ({ status: 200 })), { status: 429 }, { status: 429 }],
			expect: (s) => s.contaminated === false && s.errorRate === 0,
		},
		{
			label: 'just above the 1% ceiling is not measured',
			requests: [...Array.from({ length: 197 }, () => ({ status: 200 })), { status: 429 }, { status: 429 }, { status: 429 }],
			expect: (s) => s.contaminated === true && s.errorRate === null,
		},
		{
			// A single platform 5xx is *attribution*, not contamination: 1 in 200 is 0.5%, under the
			// ceiling, so the figure is still reported — and it is reported as zero defects, which is the
			// point. Counting it as a defect would blame the application for an infrastructure fault.
			label: 'a platform 5xx under the ceiling is not the defect',
			requests: [...Array.from({ length: 199 }, () => ({ status: 200 })), { status: 502, platform: true }],
			expect: (s) => s.defects === 0 && s.environment === 1 && s.platform === 1
				&& s.contaminated === false && s.errorRate === 0,
		},
		{
			// The same single platform fault, in a run short enough that 1 in 10 crosses the ceiling. It is
			// then not attributable to a measurement at all, and the reason must name contamination rather
			// than report a flattering 0%.
			label: 'a platform 5xx above the ceiling is not measured',
			requests: [...Array.from({ length: 9 }, () => ({ status: 200 })), { status: 502, platform: true }],
			expect: (s) => s.defects === 0 && s.environment === 1 && s.contaminated === true && s.errorRate === null,
		},
	]

	for (const { label, requests, expect } of scenarios) {
		const summary = summarise(requests)
		if (!expect(summary)) {
			failures.push(`  ${label}: got ${JSON.stringify(summary)}`)
		}
	}

	if (failures.length > 0) {
		console.error('measure-error-rate: SELF-TEST FAILED')
		for (const failure of failures) {
			console.error(failure)
		}
		return 1
	}

	console.log(
		`measure-error-rate: self-test OK (${SELF_TEST_CASES.length} classification cases, `
		+ `${scenarios.length} aggregation cases).`,
	)
	return 0
}