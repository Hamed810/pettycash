import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'

// `generateOcsUrl` only prefixes the OCS base path; the caller supplies the app route, exactly as
// the real helper does, so the requested URL can be asserted end to end.
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: vi.fn(),
		post: vi.fn()
	}
}))

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((app: string, key: string, vars?: Record<string, string>) =>
		vars ? `${key} ${Object.values(vars).join(' ')}` : key),
	n: vi.fn((key: string) => key),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true)
}))

// The reporting components import the Nextcloud components by their deep specifiers, so each one is
// mocked at that specifier. The mocks keep the props the components bind and let every undeclared
// attribute (`data-testid`) fall through to the root element.
vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size', 'ariaPressed'],
		template: '<button :type="type" :disabled="disabled" :aria-pressed="ariaPressed"><slot /></button>'
	}
}))

vi.mock('@nextcloud/vue/components/NcSelect', () => ({
	default: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label', 'error', 'disabled', 'loading', 'name'],
		emits: ['update:modelValue'],
		template: '<select :aria-label="label" :disabled="disabled" :value="modelValue" @change="$emit(\'update:modelValue\', $event.target.value)"><option v-for="opt in options" :key="opt.value" :value="opt.value">{{ opt.label }}</option></select>'
	}
}))

vi.mock('@nextcloud/vue/components/NcTextField', () => ({
	default: {
		name: 'NcTextField',
		props: ['modelValue', 'label', 'type', 'error', 'required', 'disabled', 'readonly', 'helperText'],
		emits: ['update:modelValue'],
		template: '<input :type="type" :aria-label="label" :disabled="disabled" :value="modelValue" @input="$emit(\'update:modelValue\', $event.target.value)">'
	}
}))

import axios from '@nextcloud/axios'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import PeriodDisclosure from '@/components/reports/PeriodDisclosure.vue'
import ExportControls from '@/components/reports/ExportControls.vue'
import ReportView from '@/views/reports/ReportView.vue'
import { routes } from '@/router'
import { REPORT_PAGE_SIZE_MAX, fetchReport, pruneFilters, reportQuery } from '@/services/reportsApi'
import { readPeriodDisclosure } from '@/types/reporting'
import { ApiError } from '@/types/api'
import { useReportsStore } from '@/stores/reports'
import type { ReportFilters, ReportPage, ReportRow } from '@/types/reporting'

const requestMock = vi.mocked(axios.request)
const postMock = vi.mocked(axios.post)

const SHEETS_PAGE: ReportPage = {
	page: 1,
	pageSize: 25,
	total: 1,
	columns: ['title', 'status', 'currentTotal'],
	rows: [{ title: 'January petty cash', status: 'COMPLETED', currentTotal: 1250000 }],
	summary: { currentTotal: 1250000, rowCount: 1 }
}

function ocsResponse(data: unknown) {
	return { data: { ocs: { meta: { status: 'ok' }, data } } }
}

function reportResponse(overrides: Partial<ReportPage> = {}): ReportPage {
	return { ...SHEETS_PAGE, ...overrides }
}

function createTestRouter() {
	// The application's own route table, components replaced with inert placeholders (T359). This
	// file declared a single synthetic route for `dashboard`, which meant any control under test that
	// linked by name was resolved against a table the application does not have.
	return createRouter({
		history: createWebHashHistory(),
		routes: routes.map(({ path, name, props }) => ({
			path,
			name,
			props,
			component: { template: '<div />' }
		}))
	})
}

function mountView() {
	const pinia = createPinia()
	setActivePinia(pinia)
	return mount(ReportView, {
		global: {
			plugins: [pinia, createTestRouter()],
			stubs: { 'router-link': true, 'router-view': true }
		}
	})
}

function requestedUrl(call = 0): string {
	return String(requestMock.mock.calls[call]?.[0]?.url ?? '')
}

/**
 * The bytes the exporter wrote, and the `ExportResult` envelope the server actually returns.
 *
 * Nextcloud's OCS layer wraps every body as JSON, so `ExportController` carries the artifact
 * base64-encoded in `data` and states the artifact's own `mediaType` beside it. A mock that answered
 * a bare `Blob` is what let the client believe the response body *was* the artifact, and it is the
 * reason the saved file would have contained the JSON envelope instead of a CSV.
 */
const ARTIFACT_BYTES = 'id,status\n1,COMPLETED\n'

function exportResultBody(disclosure: Record<string, unknown> = {}): unknown {
	return {
		ocs: {
			meta: { status: 'ok', statuscode: 200 },
			data: {
				artifactName: 'pettycash-export-abc123',
				mediaType: 'text/csv',
				format: 'csv',
				byteSize: ARTIFACT_BYTES.length,
				rowCount: 1,
				data: btoa(ARTIFACT_BYTES),
				artifactRemoved: true,
				// FR-068: present only when the request resolved a period. Spread last so a test can add it
				// without restating the artifact fields, and so the default really is "absent" rather than
				// four undefined keys -- which would read as a disclosure the server never sent.
				...disclosure,
			},
		},
	}
}

/** The disclosure `POST /exports` answers for `period=month`, exactly as the server sends it. */
const RESOLVED_MONTH = {
	period: 'month',
	resolvedFrom: '2026-07-01',
	resolvedTo: '2026-07-30',
	resolvedTimeZone: 'Asia/Tehran',
}

/**
 * A report `summary` shaped exactly as `ReportTable.vue` receives it.
 *
 * FR-068 puts the four disclosure keys **inside** `summary`, beside the report's real totals — so the
 * report carrier is one object holding both, and the reader must reach the disclosure without being
 * handed the response root. Typed as the component's own prop (`Record<string, unknown>`) rather than
 * as an inline literal: TypeScript's excess-property check rejects an object literal carrying keys
 * `PeriodDisclosureSource` does not declare, and the fix for that is to hold the value in a typed
 * variable — which is what production does — rather than to weaken the type so a test literal
 * compiles.
 *
 * `ReportSummary` is `ReportTable.vue`'s own `summary?: Record<string, unknown>` prop, restated rather
 * than imported: deriving it needs `vue-component-type-helpers`, and adding a dependency to name one
 * type is a worse trade than restating the shape this file is testing against. If the prop's declared
 * type changes, `readPeriodDisclosure(summary)` stops compiling here, which is the coupling worth
 * having.
 */
type ReportSummary = Record<string, unknown>

function reportSummary(summary: ReportSummary): ReportSummary {
	return summary
}

/**
 * `ReportFilterFactory::ACCEPTED_FILTERS`, read out of the PHP source.
 *
 * The filter vocabulary is **closed**, so this list is the only set of names a client may send: anything
 * else is a 422 naming an unknown filter, not an ignored key. Reading it from the server's own source is
 * what makes a front-end vocabulary assertion meaningful — comparing against `src/types/reporting.ts`
 * would only prove the client agrees with itself, which is the failure mode this repository's contract
 * gates exist to prevent.
 *
 * Parsed as text rather than evaluated, because PHP cannot be loaded in a jsdom test. Two details, both
 * found by getting them wrong first:
 *
 * - **Several names share a line.** `'from', 'to', 'statuses', …` is two lines for six entries, so an
 *   anchor requiring a trailing comma at end-of-line matched only `'period'` — the one entry that happens
 *   to sit alone on its line. The result was a one-element list that still satisfied a non-emptiness check,
 *   which is the worst failure shape: it looked parsed and silently agreed with nothing.
 * - **Comments are dropped before matching.** The constant carries prose explaining why `period` is a
 *   member in its own right, and that prose names filters. Those names are backtick-quoted, so stripping
 *   `//` lines is what keeps the explanation from reading as a second, invented entry.
 */
function acceptedFilterNames(): string[] {
	const path = join(process.cwd(), 'lib/Application/Reporting/ReportFilterFactory.php')
	const source = readFileSync(path, 'utf8')
	const block = source.match(/ACCEPTED_FILTERS\s*=\s*\[([^\]]*)\]/)

	if (block === null) {
		throw new Error(`ReportFilterFactory::ACCEPTED_FILTERS was not found in ${path}`)
	}

	// The one capture group is the array body: this pattern has exactly one, so `match[1]` is it rather
	// than an optional one. Said rather than assumed with `!`, because under
	// `noUncheckedIndexedAccess` the index is typed `string | undefined` whatever the pattern is, and an
	// assumed non-null assertion here would hide a pattern change that added a second group.
	const body = block.slice(1).join('')

	const entries = body
		.split('\n')
		.filter((line) => !line.trimStart().startsWith('//'))
		.join('\n')

	// `matchAll` yields a `RegExpExecArray`, whose first element is the whole match rather than a group,
	// so the name is at index 1 — narrowed here rather than at each use, since `RegExpMatchArray`'s
	// element type stays optional under `noUncheckedIndexedAccess`.
	return [...entries.matchAll(/'([A-Za-z][A-Za-z0-9]*)'/g)]
		.map((match) => match[1])
		.filter((name): name is string => name !== undefined)
}

/**
 * Reads a `Blob`'s bytes.
 *
 * `Blob.prototype.text()` and `arrayBuffer()` are **absent in this environment** — jsdom's `Blob`
 * exposes only `slice`, `size` and `type` — so the usual one-liners throw `is not a function` and a
 * byte-level assertion cannot be written at all. `FileReader` is the API jsdom does implement, so
 * that is what reads the bytes here.
 */
function blobBytes(blob: Blob): Promise<Uint8Array> {
	return new Promise((resolve, reject) => {
		const reader = new FileReader()
		reader.onload = () => resolve(new Uint8Array(reader.result as ArrayBuffer))
		reader.onerror = () => reject(reader.error)
		reader.readAsArrayBuffer(blob)
	})
}

async function flushPromises(): Promise<void> {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

describe('US7 Reporting and Export — Vue Component Tests', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		setActivePinia(createPinia())
		requestMock.mockResolvedValue(ocsResponse(reportResponse()))
		postMock.mockResolvedValue({ data: exportResultBody() })
	})

	describe('reportsApi service', () => {
		it('requests the contract path with the default page window', async () => {
			await fetchReport('sheets')
			expect(requestedUrl()).toBe('/ocs/v2.php/apps/pettycash/api/v1/reports/sheets?page=1&pageSize=25')
		})
		it('clamps pageSize to the contract maximum of 100', () => {
			expect(reportQuery({ pageSize: 5000 }).get('pageSize')).toBe(String(REPORT_PAGE_SIZE_MAX))
		})

		/**
		 * T329: the whole filter object travels as ONE `filters` parameter.
		 *
		 * This test previously asserted the opposite — that each filter became its own query parameter
		 * — and it passed, because it agreed with `reportQuery`. The endpoint binds a single
		 * `string $filters` and JSON-decodes it, so those parameters were all discarded, `$filters` was
		 * always `''`, and the user was shown the unfiltered report. A client-side unit test cannot
		 * catch that on its own, which is why the contract test
		 * `testAStatusAndADateRangeInOneRequestReturnOnlyRowsSatisfyingBoth` is the one that carries the
		 * weight; this one pins the encoding the contract expects.
		 */
		it('sends the whole filter object as one JSON `filters` parameter', () => {
			const query = reportQuery({
				filters: { statuses: ['OPEN'], costCenterIds: ['22222222-2222-4222-8222-222222222222'] },
			})

			expect(JSON.parse(query.get('filters') ?? '{}')).toEqual({
				statuses: ['OPEN'],
				costCenterIds: ['22222222-2222-4222-8222-222222222222'],
			})
			// No filter may leak out as a top-level parameter: the endpoint reads none, so one would be
			// silently discarded -- the original defect.
			expect(query.has('statuses')).toBe(false)
			expect(query.has('costCenterIds')).toBe(false)
		})

		/**
		 * An unfilled field must narrow nothing.
		 *
		 * `{"statuses":[]}` is a *recognised* key carrying a value that matches no row, and `{"from":""}`
		 * is a date the server refuses. So the empty forms are dropped rather than sent, and an entirely
		 * empty filter object is omitted rather than serialised as `{}`.
		 */
		it('omits unfilled filter values instead of narrowing the result set with empty strings', () => {
			const query = reportQuery({
				filters: {
					statuses: ['OPEN'],
					costCenterIds: [],
					actorUids: [''],
					createdBy: undefined,
					from: '',
					amountMinMinor: 0,
				} as ReportFilters,
			})

			expect(JSON.parse(query.get('filters') ?? '{}')).toEqual({ statuses: ['OPEN'], amountMinMinor: 0 })
		})

		it('sends no `filters` parameter at all when nothing is filled in', () => {
			expect(reportQuery({ filters: {} }).has('filters')).toBe(false)
			expect(reportQuery({ filters: { statuses: [] } as ReportFilters }).has('filters')).toBe(false)
			expect(reportQuery().has('filters')).toBe(false)
		})

		/**
		 * `amountMinMinor: 0` must survive. A `!value` truthiness check — the obvious way to write the
		 * prune — drops zero, and a zero lower bound is a real filter: it excludes nothing, which is
		 * different from "no lower bound" only by accident, and a negative-amount report would then
		 * include rows the user asked to exclude.
		 */
		it('keeps a zero amount bound rather than treating it as unfilled', () => {
			expect(pruneFilters({ amountMinMinor: 0 })).toEqual({ amountMinMinor: 0 })
		})
	})

	describe('reports store', () => {
		it('projects the report page into the store', async () => {
			const store = useReportsStore()
			await store.loadReport()
			expect(store.columns).toEqual(['title', 'status', 'currentTotal'])
			expect(store.rows).toHaveLength(1)
			expect(store.summary).toEqual({ currentTotal: 1250000, rowCount: 1 })
			expect(store.total).toBe(1)
			expect(store.error).toBeNull()
		})

		it('records a failure and drops the previous rows instead of showing stale results', async () => {
			const store = useReportsStore()
			await store.loadReport()
			expect(store.rows).toHaveLength(1)

			requestMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: { status: 403, data: { ocs: { data: { error: { code: 'FORBIDDEN', message: 'out of scope' } } } } }
			})
			await expect(store.loadReport()).rejects.toThrow('out of scope')
			expect(store.error).toBe('out of scope')
			expect(store.rows).toEqual([])
			expect(store.hasRows).toBe(false)
		})

		it('does not retry a failed read on its own (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'boom' } } } } }
			})
			const store = useReportsStore()
			await expect(store.loadReport()).rejects.toThrow('boom')
			expect(requestMock).toHaveBeenCalledTimes(1)
		})

		it('resets to the first page and sends only the filters the user filled in', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 3, total: 120 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.page).toBe(3)

			requestMock.mockResolvedValue(ocsResponse(reportResponse()))
			await store.applyFilters({ statuses: ['COMPLETED'], costCenterIds: [] })
			const url = new URL(requestedUrl(1), 'http://localhost')
			expect(url.searchParams.get('page')).toBe('1')
			// The filters travel inside `filters`, so the assertion reads the decoded object rather than
			// matching substrings against the whole query string.
			expect(JSON.parse(url.searchParams.get('filters') ?? '{}')).toEqual({ statuses: ['COMPLETED'] })
		})

		it('discards the previous subject filters when the report subject changes', async () => {
			const store = useReportsStore()
			await store.applyFilters({ statuses: ['COMPLETED'] })
			await store.selectReportType('audit')
			const url = new URL(requestedUrl(1), 'http://localhost')
			expect(url.pathname).toContain('/reports/audit')
			expect(url.searchParams.has('filters')).toBe(false)
			expect(store.filters).toEqual({})
		})

		it('does not offer a next page past the last page of the result set', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 1, pageSize: 25, total: 25 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.totalPages).toBe(1)
			expect(store.canGoNext).toBe(false)
			expect(store.canGoPrevious).toBe(false)
		})

		it('moves to the next page when more results exist', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ page: 1, pageSize: 25, total: 60 })))
			const store = useReportsStore()
			await store.loadReport()
			expect(store.canGoNext).toBe(true)
			await store.goToPage(2)
			expect(requestedUrl(1)).toContain('page=2')
		})
	})

	describe('report export', () => {
		it('posts the export with the mapped resource, format, filters, and command identifier', async () => {
			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			postMock.mockClear()

			const payload = await store.exportReport('xlsx')
			const [url, body, config] = postMock.mock.calls[0] ?? []
			expect(url).toBe('/ocs/v2.php/apps/pettycash/api/v1/exports')
			// `fileFormat`, not `format`. The endpoint binds `fileFormat` and answers `422` with
			// `fieldErrors.fileFormat` when it is empty, so a body carrying `format` made **every**
			// export a refusal — for every format, for every subject.
			expect(body).toMatchObject({ resource: 'sheets', fileFormat: 'xlsx', reportType: 'sheets' })
			expect(body).not.toHaveProperty('format')
			expect(config?.headers).toMatchObject({ 'X-PettyCash-Command-ID': expect.any(String) })
			// Not `responseType: 'blob'`: the body is JSON, so asking for a blob would hand the client
			// the envelope's bytes typed as the artifact's media type.
			expect(config?.responseType).toBeUndefined()
			expect(payload.filename).toMatch(/^pettycash-sheets-\d{4}-\d{2}-\d{2}\.xlsx$/)
			expect(payload.blob).toBeInstanceOf(Blob)
		})

		/**
		 * T341: the downloaded bytes must be the artifact, not the envelope that carries it.
		 *
		 * Asserting only `payload.blob instanceof Blob` is what passed before, and a `Blob` wrapping
		 * `{"ocs":{"data":{…,"data":"<base64>"}}}` satisfies it perfectly while producing a corrupt
		 * download. So the bytes themselves are read back and compared with what the exporter wrote.
		 */
		it('decodes the artifact out of the OCS envelope rather than saving the envelope', async () => {
			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			postMock.mockClear()

			const payload = await store.exportReport('csv')

			expect(new TextDecoder().decode(await blobBytes(payload.blob))).toBe(ARTIFACT_BYTES)
			expect(payload.blob.type).toBe('text/csv')
		})

		/**
		 * The UTF-8 trap `exportArtifactBlob()` exists to avoid.
		 *
		 * `atob` yields one binary string per byte; handing that string straight to `Blob` encodes each
		 * character's code point as UTF-8, which is lossless only below 0x80. A Persian CSV is above it
		 * everywhere, so the round trip is asserted with bytes that a code-point encoding would change.
		 */
		it('round-trips bytes above 0x7F unchanged', async () => {
			const binary = String.fromCharCode(0xD8, 0xA7, 0xD8, 0xB3, 0x0A) // Persian text plus LF
			postMock.mockResolvedValueOnce({
				data: {
					ocs: {
						data: {
							artifactName: 'a', mediaType: 'text/csv', format: 'csv',
							byteSize: binary.length, rowCount: 1, data: btoa(binary), artifactRemoved: true,
						},
					},
				},
			})

			const store = useReportsStore()
			await store.applyFilters({ stages: ['FINANCE'] })
			const payload = await store.exportReport('csv')

			expect(Array.from(await blobBytes(payload.blob))).toEqual([0xD8, 0xA7, 0xD8, 0xB3, 0x0A])
		})

		/**
		 * T377: the export carries FR-068's disclosure instead of dropping it at the store boundary.
		 *
		 * The server has always answered `POST /exports` with `period`, `resolvedFrom`, `resolvedTo` and
		 * `resolvedTimeZone` — T373 proved over live HTTP that they are byte-identical to the report path's
		 * — and `exportReport()` read the response for exactly one thing, the artifact bytes. So the file
		 * reached the user naming neither the period nor the zone it covered.
		 *
		 * Asserted against the values the server sent, not against a shape: a disclosure of the right
		 * *type* carrying the wrong range would satisfy anything looser.
		 */
		it('carries the resolved period onto the export rather than discarding it', async () => {
			postMock.mockResolvedValue({ data: exportResultBody(RESOLVED_MONTH) })

			const store = useReportsStore()
			await store.applyFilters({ period: 'month' })
			const payload = await store.exportReport('csv')

			expect(payload.disclosure).toEqual(RESOLVED_MONTH)
			expect(store.lastExportDisclosure).toEqual(RESOLVED_MONTH)
		})

		/**
		 * The filename states **what the file covers**, not when it was downloaded.
		 *
		 * It previously stamped `new Date().toISOString()` — the client's UTC clock — so a report covering
		 * 1–30 Mordad downloaded as a file named for the day the button was pressed. Two users exporting
		 * the same period got different names for the same numbers, which is the opposite of what makes two
		 * exports comparable once they are attached to the same reconciliation.
		 */
		it('names the file after the period it resolved, not the day it was downloaded', async () => {
			postMock.mockResolvedValue({ data: exportResultBody(RESOLVED_MONTH) })

			const store = useReportsStore()
			await store.applyFilters({ period: 'month' })
			const payload = await store.exportReport('csv')

			expect(payload.filename).toBe('pettycash-sheets-month-2026-07-01_2026-07-30.csv')
			// The download date must not leak in alongside the period: a name carrying both says the file
			// covers a day it does not.
			expect(payload.filename).not.toMatch(new Date().toISOString().slice(0, 10))
		})

		/**
		 * **The zone is deliberately not in the filename, and this is why.**
		 *
		 * A zone name contains `/` (`Asia/Tehran`), which is a path separator: a filename carrying one
		 * either becomes a directory or is silently rewritten by the browser's download handling. Asserted
		 * directly, because "we remembered not to add it" is exactly the kind of decision that gets undone
		 * by a later change that helpfully includes everything.
		 */
		it('keeps the path separator out of the filename', async () => {
			postMock.mockResolvedValue({ data: exportResultBody(RESOLVED_MONTH) })

			const store = useReportsStore()
			await store.applyFilters({ period: 'month' })
			const payload = await store.exportReport('csv')

			expect(payload.filename).not.toContain('/')
			expect(payload.filename).not.toContain(RESOLVED_MONTH.resolvedTimeZone)
			// The zone still travels — in the disclosure, where it is readable.
			expect(payload.disclosure?.resolvedTimeZone).toBe(RESOLVED_MONTH.resolvedTimeZone)
		})

		/**
		 * With no period there is nothing to state, so today's date stands in.
		 *
		 * Asserted because naming a range the request never used would be the same class of error as sending
		 * a filter the server refuses — except this one would be in the filename of a financial artifact,
		 * where nobody is going to query it.
		 */
		it('falls back to the download date when the request carried no period', async () => {
			const store = useReportsStore()
			await store.applyFilters({ from: '2026-01-01' })
			const payload = await store.exportReport('csv')

			expect(payload.disclosure).toBeNull()
			expect(payload.filename).toMatch(
				new RegExp(`^pettycash-sheets-${new Date().toISOString().slice(0, 10)}\\.csv$`)
			)
		})

		/**
		 * A failed export must not leave the previous file's period on screen.
		 *
		 * `lastExportDisclosure` is cleared when a request *starts*, not when it succeeds, so a refusal
		 * cannot leave a disclosure describing a file the user does not hold while the new attempt is in
		 * flight or has failed.
		 */
		it('clears the disclosure when a new export starts, so a failure cannot strand it', async () => {
			postMock.mockResolvedValueOnce({ data: exportResultBody(RESOLVED_MONTH) })
			const store = useReportsStore()
			await store.applyFilters({ period: 'month' })
			await store.exportReport('csv')
			expect(store.lastExportDisclosure).toEqual(RESOLVED_MONTH)

			// `ApiError`'s signature is `(status, body)` and `body.message` is what `super()` receives, so
			// the arguments are given in the order the class declares. The earlier three-argument form
			// put the message where the status belongs and did not compile — `vue-tsc` caught it, but
			// only because this test happens to run in the same file as the typed export path.
			postMock.mockRejectedValueOnce(new ApiError(422, { code: 'refused', message: 'refused' }))
			await expect(store.exportReport('csv')).rejects.toBeTruthy()

			expect(store.lastExportDisclosure).toBeNull()
		})

	/**
	 * T377: one reader, two carriers.
	 *
	 * `GET /reports` nests the four keys inside `summary`; `POST /exports` returns them at the response
	 * root. The reader is handed whichever object carries them, so the extraction rule exists once — a
	 * component that had to know which endpoint produced it would be a second place for that knowledge to
	 * drift, and the two would eventually disagree about what "the disclosure" means.
	 */
	describe('readPeriodDisclosure', () => {
		it('reads the export carrier, where the keys sit at the response root', () => {
			expect(readPeriodDisclosure(RESOLVED_MONTH)).toEqual(RESOLVED_MONTH)
		})

		it('reads the report carrier, where the same keys sit inside `summary`', () => {
			// The report path nests the disclosure **inside** `summary`, beside the report's real
			// totals, so the object the reader is handed carries both. Asserted separately from the
			// export carrier because "it worked for the export shape" would still pass if the reader
			// reached one level too deep for the other — and the extra `totalMinor` is what makes this
			// the report's shape rather than the export's.
			const summary = reportSummary({ totalMinor: 1000, ...RESOLVED_MONTH })
			expect(readPeriodDisclosure(summary)).toEqual(RESOLVED_MONTH)
		})

		it('does not reach past `summary` for a disclosure the response did not resolve', () => {
			// The sibling of the case above, and the one that would fail if the reader descended a
			// level it should not: a summary carrying only totals has no period, so nothing is
			// disclosed. A reader that dug into a nested object to find one would invent a disclosure
			// the server never sent.
			const summary = reportSummary({ totalMinor: 1000 })
			expect(readPeriodDisclosure(summary)).toBeNull()
		})

		/**
		 * Absent means `null`, and `null` is not the same as empty.
		 *
		 * The contract makes the disclosure **absent** when no period was resolved, rather than present and
		 * null, because a key describing a request the response did not use is a claim about the request
		 * rather than about the answer. A reader that returned an empty disclosure here would put "no period
		 * resolved" on screen over a report filtered by an explicit range.
		 */
		it('returns null when no period was resolved', () => {
			expect(readPeriodDisclosure(reportSummary({ totalMinor: 1000 }))).toBeNull()
			expect(readPeriodDisclosure({})).toBeNull()
			expect(readPeriodDisclosure(undefined)).toBeNull()
			expect(readPeriodDisclosure(null)).toBeNull()
			// Present-and-null is the shape the contract deliberately avoids; treat it as absent.
			expect(readPeriodDisclosure({ period: null })).toBeNull()
		})

		/**
		 * A partial disclosure is read as partial, not completed.
		 *
		 * FR-068 requires the zone so two users' exports can be reconciled. A disclosure that arrived
		 * without one must stay without one, so the component can say so — a reader that filled in a
		 * default zone would make two users' exports look reconcilable when they are not.
		 */
		it('preserves a partial disclosure rather than completing it', () => {
			expect(
				readPeriodDisclosure({ period: 'year', resolvedFrom: '2026-01-01', resolvedTo: '2026-12-30' })
			).toEqual({ period: 'year', resolvedFrom: '2026-01-01', resolvedTo: '2026-12-30', resolvedTimeZone: undefined })
		})
	})

	it('offers the grouped evidence archive only for the evidence report', async () => {
			const store = useReportsStore()
			expect(store.availableFormats).toEqual(['csv', 'xlsx', 'pdf'])
			await store.selectReportType('evidence')
			expect(store.availableFormats).toEqual(['csv', 'zip'])
		})

		it('refuses an unsupported format rather than substituting a different one', async () => {
			const store = useReportsStore()
			await expect(store.exportReport('zip')).rejects.toThrow('pettycash.error.unsupportedFormat:zip')
			expect(postMock).not.toHaveBeenCalled()
		})

		/**
		 * T342: the three subjects the client invents a `report` resource for offer no format at all.
		 *
		 * They mapped to the generic `report` resource with three formats each, and the server resolved
		 * rows for `sheet`, `sheets` and `transactions` only — so an export produced a well-formed CSV
		 * with a header row, no rows, and a `rowCount: 0` that read as a measured zero. The server now
		 * refuses those resources; this asserts the client offers nothing to click, which is the half a
		 * store test can actually see.
		 */
		it('offers no format for the subjects that have no exporter', async () => {
			const store = useReportsStore()

			for (const subject of ['cost-centers', 'accounts', 'process'] as const) {
				await store.selectReportType(subject)
				expect(store.availableFormats, subject).toEqual([])
				// And nothing is requested when a caller ignores the empty list.
				postMock.mockClear()
				await expect(store.exportReport('csv')).rejects.toThrow('pettycash.error.unsupportedFormat:csv')
				expect(postMock).not.toHaveBeenCalled()
			}
		})

		it('still offers the grouped evidence archive for the evidence report', async () => {
			// The counterpart to the case above: a blanket "nothing is exportable" would satisfy it too.
			const store = useReportsStore()
			await store.selectReportType('evidence')
			expect(store.availableFormats).toEqual(['csv', 'zip'])
		})

		it('surfaces an export failure and leaves no export in flight', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: { status: 422, data: {} }
			})
			const store = useReportsStore()
			await expect(store.exportReport('csv')).rejects.toBeTruthy()
			expect(store.exportError).toBe('pettycash.error.export')
			expect(store.exporting).toBe(false)
		})

		/**
		 * T334: a refusal must reach the form as the server stated it.
		 *
		 * `asApiError` used to replace every axios failure with a synthetic `EXPORT_FAILED` body, so
		 * a `422` naming `fileFormat` reached the user as "export failed" and `fieldErrors` — the
		 * payload a form places next to its input — was dropped on the way. The mock below is the
		 * real envelope the endpoint answers with.
		 */
		it('carries the refusal\'s field errors from the error envelope into the store', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: {
					status: 422,
					data: {
						ocs: {
							meta: { status: 'failure', statuscode: 422 },
							data: {
								error: {
									code: 'VALIDATION_FAILED',
									message: 'Required fields are missing',
									fieldErrors: { fileFormat: ['fileFormat is required'] }
								}
							}
						}
					}
				}
			})
			const store = useReportsStore()

			await expect(store.exportReport('csv')).rejects.toBeTruthy()

			// The server's message, not the synthetic one: `exportError` answers *why*.
			expect(store.exportError).toBe('Required fields are missing')
			// And `fieldErrors` answers *which*, which is the half a form can place beside an input.
			expect(store.exportFieldErrors).toEqual({ fileFormat: ['fileFormat is required'] })
		})

		it('clears the previous refusal\'s field errors when a new export starts', async () => {
			postMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: {
					status: 422,
					data: { ocs: { data: { error: { code: 'VALIDATION_FAILED', message: 'no', fieldErrors: { fileFormat: ['required'] } } } } }
				}
			})
			const store = useReportsStore()
			await expect(store.exportReport('csv')).rejects.toBeTruthy()

			postMock.mockResolvedValueOnce({ data: exportResultBody() })
			await store.exportReport('csv')

			expect(store.exportFieldErrors).toEqual({})
		})
	})

	describe('ReportView', () => {
		it('loads the default report on mount and renders the returned rows', async () => {
			const wrapper = mountView()
			await flushPromises()
			expect(requestedUrl()).toContain('/reports/sheets')
			expect(wrapper.find('[data-testid="report-table"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-row-0"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-cell-title"]').text()).toBe('January petty cash')
		})

		it('presents the empty state when the report has no rows', async () => {
			requestMock.mockResolvedValue(ocsResponse(reportResponse({ rows: [], total: 0, summary: {} })))
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="report-view-empty"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-pagination"]').exists()).toBe(false)
		})

		it('shows the failure with a retry the user must trigger (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'report failed' } } } } }
			})
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="report-view-error"]').text()).toContain('report failed')
			expect(requestMock).toHaveBeenCalledTimes(1)

			requestMock.mockResolvedValue(ocsResponse(reportResponse()))
			await wrapper.find('[data-testid="report-view-retry"]').trigger('click')
			await flushPromises()
			expect(requestMock).toHaveBeenCalledTimes(2)
			expect(wrapper.find('[data-testid="report-table"]').exists()).toBe(true)
		})

		it('switches the report subject on request', async () => {
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('[data-testid="report-subject-audit"]').trigger('click')
			await flushPromises()
			expect(requestedUrl(1)).toContain('/reports/audit')
		})

		it('paginates to the next page of the current report', async () => {
			requestMock.mockResolvedValueOnce(ocsResponse(reportResponse({ page: 1, total: 60 })))
			requestMock.mockResolvedValueOnce(ocsResponse(reportResponse({ page: 2, total: 60 })))
			const wrapper = mountView()
			await flushPromises()
			await wrapper.find('[data-testid="report-next"]').trigger('click')
			await flushPromises()
			expect(requestedUrl(1)).toContain('page=2')
			expect(wrapper.find('[data-testid="report-page-info"]').text()).toContain('2')
		})

		it('exports the current report in the format chosen by the user', async () => {
			const originalCreate = URL.createObjectURL
			const originalRevoke = URL.revokeObjectURL
			const createObjectURL = vi.fn(() => 'blob:report')
			const revokeObjectURL = vi.fn()
			URL.createObjectURL = createObjectURL as unknown as typeof URL.createObjectURL
			URL.revokeObjectURL = revokeObjectURL as unknown as typeof URL.revokeObjectURL
			try {
				const wrapper = mountView()
				await flushPromises()
				await wrapper.find('[data-testid="export-format"]').setValue('pdf')
				await wrapper.find('[data-testid="export-submit"]').trigger('click')
				await flushPromises()

				expect(postMock).toHaveBeenCalledTimes(1)
				expect(postMock.mock.calls[0]?.[1]).toMatchObject({ resource: 'sheets', fileFormat: 'pdf' })
				expect(createObjectURL).toHaveBeenCalledTimes(1)
				expect(revokeObjectURL).toHaveBeenCalledWith('blob:report')
			} finally {
				URL.createObjectURL = originalCreate
				URL.revokeObjectURL = originalRevoke
			}
		})
	})

	describe('ReportFilterBar', () => {
		function mountBar(filters: ReportFilters = {}) {
			return mount(ReportFilterBar, { props: { filters, reportType: 'sheets' } })
		}

		/**
		 * The emitted object is in the server's vocabulary, list-valued where the server refuses a
		 * scalar.
		 *
		 * This asserted `{ period: 'custom', status: 'COMPLETED' }` before T329 — two names the server
		 * has never accepted, one of which (`period`) is still not in the vocabulary. Every field would
		 * have been refused as unknown, and the user would have been shown the unfiltered report.
		 */
		it('emits the filters the user filled in under the names the server accepts', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-status"]').setValue('COMPLETED')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ statuses: ['COMPLETED'] })
		})

		/**
		 * A single-select control still sends a **list**, because `ReportFilterFactory` refuses a
		 * non-array for every list-valued name. Sending a bare string would be a 422 on a filter the
		 * user filled in correctly.
		 */
		it('wraps every single-value facet in a one-element list', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-cost-center"]').setValue('22222222-2222-4222-8222-222222222222')
			await wrapper.find('[data-testid="report-filter-stage"]').setValue('FINANCE')
			await wrapper.find('[data-testid="report-filter-event-type"]').setValue('SHEET_SUBMITTED')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({
				costCenterIds: ['22222222-2222-4222-8222-222222222222'],
				stages: ['FINANCE'],
				eventTypes: ['SHEET_SUBMITTED'],
			})
		})

		/**
		 * T376: FR-064's four facets that no control could reach — currency, creator, expense type and
		 * actor — now have one each.
		 *
		 * The requirement's own wording is *"sheets by status, date, currency, and creator; transactions by
		 * status, cost center, expense type, date, and amount; audit by actor, stage, event type, and date
		 * range"*. Currency, creator, expense type and actor were unreachable, and the clarifications log
		 * answers "Keep all twelve; they are V1 requirements, not deferred" — so a requirement the
		 * specification declines to defer with no task implementing it is a gap rather than a plan.
		 */
		it('emits all four of the facets that had no control', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-currency"]').setValue('IRR')
			await wrapper.find('[data-testid="report-filter-expense-type"]').setValue('33333333-3333-4333-8333-333333333333')
			await wrapper.find('[data-testid="report-filter-actor"]').setValue('employee1')
			await wrapper.find('[data-testid="report-filter-created-by"]').setValue('employee2')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({
				currencyCodes: ['IRR'],
				expenseTypeIds: ['33333333-3333-4333-8333-333333333333'],
				actorUids: ['employee1'],
				createdBy: 'employee2',
			})
		})

		/**
		 * `createdBy` is the one **scalar** among the four, and getting it wrong is silent.
		 *
		 * `ReportFilterFactory` reads it as `isset($decoded['createdBy']) ? (string) $decoded['createdBy'] : null`,
		 * so sending `['employee2']` does not earn a 422 — it stringifies to `"Array"`, which is a filter
		 * on a user called **`Array`**. The request succeeds and returns the wrong rows, which is why this
		 * asserts the absence of a list rather than merely the presence of the value: both spellings contain
		 * the uid somewhere.
		 */
		it('sends `createdBy` as a scalar, because the server casts it to a string', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-created-by"]').setValue('employee2')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			const filters = wrapper.emitted('apply')?.[0]?.[0] as ReportFilters
			expect(filters.createdBy).toBe('employee2')
			expect(Array.isArray(filters.createdBy)).toBe(false)
		})

		/**
		 * A field the user cleared emits **nothing**, not an empty value.
		 *
		 * The four new controls are bound through helpers that return `undefined` for a blank, so an
		 * untouched field is absent from the payload. Emitting `currencyCodes: []` instead would be a
		 * filter the server accepts and reads as "no currency matches" — an empty answer presented as a
		 * filtered one, rather than the unfiltered report the user asked for.
		 */
		it('emits no filter for a facet the user left blank', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-currency"]').setValue('IRR')
			await wrapper.find('[data-testid="report-filter-created-by"]').setValue('')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			const filters = wrapper.emitted('apply')?.[0]?.[0] as ReportFilters
			expect(filters.currencyCodes).toEqual(['IRR'])
			expect('createdBy' in filters).toBe(false)
			expect('actorUids' in filters).toBe(false)
			expect('expenseTypeIds' in filters).toBe(false)
		})

		/**
		 * The bar cannot send a name the server refuses — checked against the server's own list.
		 *
		 * This is the rule T309 established on the report side and T382 inherits for the sheet list: the
		 * filter vocabulary is **closed**, so an unrecognised key is a 422 rather than an ignored filter.
		 * A control added here with a plausible-looking name (`creator`, `expenseType`) would type-check
		 * against `ReportFilters` only if `ReportFilters` also carried it, and the failure the user would
		 * see is a refused request. So the assertion reads `ReportFilterFactory::ACCEPTED_FILTERS` out of
		 * the PHP source and requires every name the bar can emit to be in it.
		 *
		 * Parsed from source rather than generated from the contract, because the accepted list is the
		 * server's own statement; `ReportFilterVocabularyTest` already holds the contract to it, so
		 * comparing to the contract here would only re-derive what that test proves.
		 */
		it('sends only names the server accepts, so no control can earn a 422', () => {
			const emitted = [
				'period', 'from', 'to', 'statuses', 'currencyCodes', 'costCenterIds', 'expenseTypeIds',
				'actorUids', 'eventTypes', 'createdBy', 'stages', 'amountMinMinor', 'amountMaxMinor',
			]
			const accepted = acceptedFilterNames()

			// The parse itself is asserted before it is relied on. An earlier version of this helper
			// anchored on a trailing comma at end-of-line, which matched only `'period'` — one entry, on
			// its own line — and every name the bar can emit therefore looked unaccepted. `length > 0`
			// still passed, so the failure arrived as a list of thirteen refused filters rather than as a
			// parse error. Asserting the two ends and the size catches a partial parse where a size check
			// does not.
			expect(accepted).toContain('from')
			expect(accepted).toContain('period')
			expect(accepted).toContain('amountMaxMinor')
			expect(accepted.length).toBeGreaterThanOrEqual(emitted.length)

			expect(emitted.filter((name) => !accepted.includes(name))).toEqual([])
		})

		it('emits the date range under `from`/`to`, the names the factory accepts', async () => {
			const wrapper = mountBar({ from: '2026-01-01', to: '2026-01-31' })
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ from: '2026-01-01', to: '2026-01-31' })
		})

		/**
		 * FR-068: a period is a member of the accepted vocabulary in its own right, so choosing one must
		 * put it on the wire under its own name.
		 *
		 * This is the assertion the previous version of this suite got backwards. It asserted `period`
		 * was absent while T312 was pending, and it stayed green after T312 landed — a passing test
		 * holding a premise the server had stopped having.
		 */
		it.each(['month', 'quarter', 'year'] as const)('emits `period: %s` when that preset is chosen', async (preset) => {
			const wrapper = mountBar()
			await wrapper.findComponent({ name: 'NcSelect' }).setValue(preset)
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({ period: preset })
		})

		/**
		 * FR-068 makes a period and a custom range **mutually exclusive**, and
		 * `ReportFilterFactory` refuses the combination with a 422 rather than honouring one filter while
		 * discarding the other.
		 *
		 * So this is not a formatting assertion — it is the assertion that the control cannot build a
		 * request the server will reject, which is the whole reason the date fields are gated on the
		 * preset. Both directions are checked because "whichever arrived first wins" is precisely the
		 * silent discard the server refuses to perform.
		 */
		it('never sends `period` together with an explicit range', async () => {
			const withRangeThenPreset = mountBar({ from: '2026-01-01', to: '2026-01-31' })
			await withRangeThenPreset.findComponent({ name: 'NcSelect' }).setValue('month')
			await withRangeThenPreset.find('[data-testid="report-filter-bar"]').trigger('submit')

			const presetFirst = withRangeThenPreset.emitted('apply')?.[0]?.[0] as Record<string, unknown>
			expect(presetFirst).toEqual({ period: 'month' })
			expect(Object.keys(presetFirst)).not.toContain('from')
			expect(Object.keys(presetFirst)).not.toContain('to')

			// And the reverse: a draft that arrives carrying a period, then switched to `custom`, sends
			// the range alone.
			const withPeriodThenRange = mountBar({ period: 'quarter' })
			await withPeriodThenRange.findComponent({ name: 'NcSelect' }).setValue('custom')
			await withPeriodThenRange.find('[data-testid="report-filter-date-from"]').setValue('2026-02-01')
			await withPeriodThenRange.find('[data-testid="report-filter-bar"]').trigger('submit')

			const rangeFirst = withPeriodThenRange.emitted('apply')?.[0]?.[0] as Record<string, unknown>
			expect(rangeFirst).toEqual({ from: '2026-02-01' })
			expect(Object.keys(rangeFirst)).not.toContain('period')
		})

		/**
		 * An applied `period` must read back into the picker.
		 *
		 * If it did not, a report filtered by period would render an explicit-range control instead —
		 * inviting the user to submit a second, different interpretation of the view they are looking at,
		 * while the screen showed no trace of the filter that produced it.
		 */
		it('reads an applied period back into the picker', () => {
			const wrapper = mountBar({ period: 'year' })
			const select = wrapper.findComponent({ name: 'NcSelect' })

			expect(select.props('modelValue')).toBe('year')
			// The date fields belong to `custom`, so a period view must not offer them.
			expect(wrapper.find('[data-testid="report-filter-date-from"]').exists()).toBe(false)
		})

		it('clears the period on reset rather than leaving it claimed on screen', async () => {
			const wrapper = mountBar({ period: 'month' })
			await wrapper.find('[data-testid="report-filter-reset"]').trigger('click')
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			expect(wrapper.emitted('reset')).toBeTruthy()
			expect(wrapper.emitted('apply')?.[0]?.[0]).toEqual({})
		})
		it('emits no filter the server would refuse as unknown', async () => {
			const wrapper = mountBar()
			await wrapper.find('[data-testid="report-filter-bar"]').trigger('submit')

			const emitted = wrapper.emitted('apply')?.[0]?.[0] as Record<string, unknown>
			// `dateFrom` and `costCenterId` are still refused: they are not in `ACCEPTED_FILTERS`, and an
			// unrecognised filter is rejected rather than ignored — which is why this half of the test
			// survives. `period` was removed from it deliberately: it *is* accepted, so asserting its
			// absence pinned a server that no longer exists.
			expect(Object.keys(emitted)).not.toContain('dateFrom')
			expect(Object.keys(emitted)).not.toContain('costCenterId')
		})

		it('offers every period the server can resolve, plus the custom range', () => {
			const wrapper = mountBar()
			const options = wrapper.findComponent({ name: 'NcSelect' }).props('options') as { value: string }[]

			expect(options.map((option) => option.value)).toEqual(['month', 'quarter', 'year', 'custom'])
		})

		it('resets without carrying any filter into the request', async () => {
			const wrapper = mountBar({ statuses: ['COMPLETED'] })
			await wrapper.find('[data-testid="report-filter-reset"]').trigger('click')
			expect(wrapper.emitted('reset')).toBeTruthy()
		})
	})

	/**
	 * FR-068's disclosure: "the application MUST disclose the resolved period, including its resolved
	 * date range and timezone."
	 *
	 * The requirement is a **disclosure**, so these assert that the range and the zone reach the screen
	 * at all. A report filtered by `period` that renders no indication of which period it covers is not
	 * a smaller version of the feature — it is a financial answer with no stated period, which is the
	 * thing two users cannot reconcile against each other.
	 */
	describe('PeriodDisclosure', () => {
		// Mounted through the same reader the two carriers use, so the test exercises the extraction and not
		// just the markup -- `GET /reports` nests the keys in `summary`, `POST /exports` returns them at the
		// root, and `readPeriodDisclosure` is the single rule for both.
		function mountDisclosure(source: Record<string, unknown>) {
			return mount(PeriodDisclosure, { props: { disclosure: readPeriodDisclosure(source) } })
		}

		/** A complete disclosure, exactly as `ReportFilter::periodDisclosure()` returns it. */
		const resolved = {
			period: 'month',
			resolvedFrom: '2026-07-01',
			resolvedTo: '2026-07-30',
			resolvedTimeZone: 'Asia/Tehran',
		}

		it('states the period, its resolved range and the zone it was resolved in', () => {
			const wrapper = mountDisclosure(resolved)

			expect(wrapper.find('[data-testid="report-period-resolved-period"]').text()).toBe(
				'reports.period.month'
			)
			// The two endpoints in **one** span: two separate fields read as two separate figures.
			expect(wrapper.find('[data-testid="report-period-resolved-range"]').text()).toBe(
				'2026-07-01 – 2026-07-30'
			)
			expect(wrapper.find('[data-testid="report-period-resolved-zone"]').text()).toBe('Asia/Tehran')
			expect(wrapper.find('[data-testid="report-period-disclosure-incomplete"]').exists()).toBe(false)
		})

		/**
		 * The endpoints are the server's own strings, never reformatted here.
		 *
		 * A client that re-rendered the range — into a locale date, or a Jalali date computed from the
		 * wrong zone — would put a second, derived answer beside the authoritative one, and the two would
		 * disagree by a day at the boundary. FR-068 resolves the period server-side for exactly this
		 * reason; the client repeating the computation would reintroduce the bug the resolution avoids.
		 */
		it('reproduces the server dates verbatim rather than reformatting them', () => {
			const wrapper = mountDisclosure({ ...resolved, resolvedFrom: '2026-12-21', resolvedTo: '2027-03-20' })

			expect(wrapper.find('[data-testid="report-period-resolved-range"]').text()).toBe(
				'2026-12-21 – 2027-03-20'
			)
			// A Jalali rendering would have produced Persian numerals or a 1405/1406 date here.
			expect(wrapper.text()).not.toMatch(/[۰-۹]/)
			expect(wrapper.text()).not.toMatch(/14\d\d/)
		})

		it('renders nothing when no period was resolved', () => {
			// The contract makes the disclosure **absent** otherwise, so an unfiltered report — or one
			// filtered by an explicit range — must not carry a "no period resolved" line. That would be a
			// claim about the request the server never made.
			expect(mountDisclosure({ totalMinor: 1000 }).find('[data-testid="report-period-disclosure"]').exists()).toBe(false)
			expect(mountDisclosure({}).find('[data-testid="report-period-disclosure"]').exists()).toBe(false)
			expect(mountDisclosure({ period: null }).find('[data-testid="report-period-disclosure"]').exists()).toBe(false)
		})

		it('announces itself as a state change, since it arrives after the request completes', () => {
			const node = mountDisclosure(resolved).find('[data-testid="report-period-disclosure"]')

			expect(node.attributes('role')).toBe('status')
			expect(node.attributes('aria-live')).toBe('polite')
		})

		/**
		 * A partial disclosure still discloses — and says that it is partial.
		 *
		 * FR-068 requires the zone so two users' exports can be reconciled. A disclosure missing the
		 * zone, rendered as though complete, produces numbers that cannot be reconciled and give no
		 * hint why.
		 */
		it('marks a disclosure that arrived without its zone as incomplete', () => {
			const wrapper = mountDisclosure({ period: 'year', resolvedFrom: '2026-01-01', resolvedTo: '2026-12-30' })

			expect(wrapper.find('[data-testid="report-period-disclosure-incomplete"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-period-resolved-zone"]').exists()).toBe(false)
		})
	})

	/**
	 * The disclosure rides **inside** `summary`, so this is the assertion that it is not also counted as
	 * a total.
	 *
	 * Without the filter, a resolved period renders `resolvedFrom 2026-07-01` in the totals row — a date
	 * among the sums — and then again in the disclosure line above it. A date that reads as a total is
	 * worse than a missing disclosure, because it looks like a figure somebody can reconcile.
	 */
	describe('ReportTable summary', () => {
		function mountTable(summary: Record<string, unknown>) {
			return mount(ReportTable, {
				props: {
					columns: ['sheetId'],
					rows: [{ sheetId: 'SHEET-1' }],
					summary,
					loading: false,
				},
			})
		}

		it('keeps the disclosure out of the totals while leaving real totals alone', () => {
			const wrapper = mountTable({
				totalMinor: 1000,
				count: 3,
				period: 'month',
				resolvedFrom: '2026-07-01',
				resolvedTo: '2026-07-30',
				resolvedTimeZone: 'Asia/Tehran',
			})

			const totalKeys = wrapper.findAll('[data-testid^="report-summary-"]').map((node) =>
				node.attributes('data-testid')
			)
			expect(totalKeys).toEqual(['report-summary-totalMinor', 'report-summary-count'])
			// And the disclosure itself is present, once, above the totals.
			expect(wrapper.findAll('[data-testid="report-period-disclosure"]')).toHaveLength(1)
		})

		it('shows every total untouched when no period was resolved', () => {
			const wrapper = mountTable({ totalMinor: 1000, count: 3 })

			expect(wrapper.find('[data-testid="report-summary-totalMinor"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-summary-count"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="report-period-disclosure"]').exists()).toBe(false)
		})
	})

	describe('ReportTable', () => {
		const rows: ReportRow[] = [
			{ title: 'January petty cash', status: 'COMPLETED', currentTotal: 1250000 },
			{ title: 'February petty cash', status: null, currentTotal: 0 }
		]

		it('renders the summary alongside the rows', () => {
			const wrapper = mount(ReportTable, {
				props: { columns: ['title', 'status', 'currentTotal'], rows, summary: { currentTotal: 1250000 } }
			})
			expect(wrapper.find('[data-testid="report-summary-currentTotal"]').text()).toContain('۱٬۲۵۰٬۰۰۰')
		})

		it('marks a value the projection did not supply instead of rendering a blank cell', () => {
			const wrapper = mount(ReportTable, {
				props: { columns: ['title', 'status', 'currentTotal'], rows }
			})
			expect(wrapper.findAll('[data-testid="report-cell-status"]')[1]?.text()).toBe('—')
		})

		it('keeps a server column identifier the catalog does not name yet', () => {
			const wrapper = mount(ReportTable, { props: { columns: ['unmappedColumn'], rows: [{ unmappedColumn: 'x' }] } })
			expect(wrapper.find('th').text()).toBe('unmappedColumn')
		})

		it('renders a per-row card for the narrow viewport layout (FR-071)', () => {
			const wrapper = mount(ReportTable, { props: { columns: ['title'], rows } })
			expect(wrapper.find('[data-testid="report-card-0"]').exists()).toBe(true)
			expect(wrapper.findAll('.report-card')).toHaveLength(2)
		})

		/**
		 * T395: a specific column's value is reachable **inside the card**.
		 *
		 * The assertion above proves a card exists and how many there are -- never what is in one. Combined
		 * with `report-cell-<column>` existing only on the layout a narrow viewport never renders, that left
		 * every figure on this component unassertable in the layout a phone actually gets.
		 *
		 * **Per column, and compared against the desktop cell**, so the test fails if either side loses the
		 * hook and if the two ever disagree. `status: null` is in the fixture deliberately: it is the row
		 * where the two layouts would diverge if one rendered a blank where the other rendered the absent
		 * marker.
		 */
		it('addresses a specific column\'s value inside the narrow card, matching the table cell', () => {
			const wrapper = mount(ReportTable, {
				props: { columns: ['title', 'status', 'currentTotal'], rows }
			})

			for (const column of ['title', 'status', 'currentTotal']) {
				const inTable = wrapper.find(`[data-testid="report-cell-${column}"]`)
				const inCard = wrapper.find(`[data-testid="report-card-field-${column}"]`)

				expect(
					inTable.exists(),
					`the table must still carry report-cell-${column}`
				).toBe(true)
				expect(
					inCard.exists(),
					`the card must carry report-card-field-${column} -- otherwise a figure that differs only at `
						+ `a narrow viewport has no handle at all`
				).toBe(true)
				expect(
					inCard.text().replace(inCard.find('.report-card__label').text(), '').trim(),
					`${column} must say the same thing in the card as in the table`
				).toBe(inTable.text().trim())
			}
		})
	})

	describe('ExportControls', () => {
		it('offers only the formats the subject supports', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv', 'zip'] } })
			const options = wrapper.findAll('[data-testid="export-format"] option')
			expect(options.map((option) => option.attributes('value'))).toEqual(['csv', 'zip'])
		})

		it('does not start an export while one is in flight', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], exporting: true } })
			expect(wrapper.find('[data-testid="export-submit"]').attributes('disabled')).toBeDefined()
		})

		it('retries an export only when the user asks for it', async () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], error: 'export failed' } })
			expect(wrapper.find('[data-testid="export-error"]').text()).toContain('export failed')
			expect(wrapper.emitted('export')).toBeFalsy()
			await wrapper.find('[data-testid="export-retry"]').trigger('click')
			expect(wrapper.emitted('export')?.[0]?.[0]).toBe('csv')
		})

		/**
		 * T334: the field name is rendered, not only the message.
		 *
		 * A refusal that says "required" without saying *which* field is required gives the user
		 * nothing to act on, and `fileFormat` is a value of this very control.
		 */
		it('names each refused field next to the refusal', () => {
			const wrapper = mount(ExportControls, {
				props: {
					availableFormats: ['csv'],
					error: 'Required fields are missing',
					fieldErrors: { fileFormat: ['fileFormat is required'] }
				}
			})

			const row = wrapper.find('[data-testid="export-field-error-fileFormat"]')
			expect(row.exists()).toBe(true)
			expect(row.text()).toContain('fileFormat')
			expect(row.text()).toContain('fileFormat is required')
		})

		it('renders no field-error list when the refusal named no field', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: ['csv'], error: 'not allowed' } })

			expect(wrapper.find('[data-testid="export-field-errors"]').exists()).toBe(false)
		})

		/**
		 * T342: a subject with nothing to export says so.
		 *
		 * A disabled control with no explanation is the failure mode this whole task is about, one level
		 * up: the user learns nothing except that a button is grey. `role="note"` rather than `alert`
		 * because this is not a failure that just happened — it is a standing property of the subject.
		 */
		it('states why a subject cannot be exported', () => {
			const wrapper = mount(ExportControls, { props: { availableFormats: [] } })

			expect(wrapper.find('[data-testid="export-submit"]').attributes('disabled')).toBeDefined()
			const note = wrapper.find('[data-testid="export-unavailable"]')
			expect(note.exists()).toBe(true)
			expect(note.text()).toBe('reports.export.unsupportedSubject')
			expect(note.attributes('role')).toBe('note')
		})

		it('shows no availability note while an export is in flight', () => {
			// Nothing to export *and* busy cannot both be true in practice; if they ever are, the busy
			// state is the truthful one and a second message would compete with it.
			const wrapper = mount(ExportControls, { props: { availableFormats: [], exporting: true } })

			expect(wrapper.find('[data-testid="export-unavailable"]').exists()).toBe(false)
		})

		it('prefers the server\'s refusal over the availability note', () => {
			const wrapper = mount(ExportControls, {
				props: { availableFormats: [], error: 'Exporting resource \'report\' is not supported yet' }
			})

			expect(wrapper.find('[data-testid="export-error"]').text()).toContain('not supported yet')
			expect(wrapper.find('[data-testid="export-unavailable"]').exists()).toBe(false)
		})
	})
})
