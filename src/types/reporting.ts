import type { ExportRequest } from './api'

/**
 * Report subjects from `contracts/ocs-api.yaml` (`/reports/{reportType}`). The set is fixed by the
 * contract: a client that invented a subject would request a report the server does not scope.
 */
export type ReportType =
	| 'sheets'
	| 'transactions'
	| 'cost-centers'
	| 'accounts'
	| 'process'
	| 'evidence'
	| 'audit'

export const REPORT_TYPES: readonly ReportType[] = [
	'sheets',
	'transactions',
	'cost-centers',
	'accounts',
	'process',
	'evidence',
	'audit',
]

/**
 * The four formats FR-066 supports, read off the **request** type's `fileFormat`.
 *
 * Derived rather than restated so the store's format union and the field the endpoint binds cannot
 * drift apart: when this was `ExportRequest['format']` it tracked a field the server does not read.
 */
export type ExportFormat = ExportRequest['fileFormat']

/**
 * The periods FR-068 names as **wire** values — the vocabulary `ReportingPeriod::NAMES` accepts and
 * `ReportFilterFactory::ACCEPTED_FILTERS` admits.
 *
 * ## Why `custom` is not here
 *
 * `custom` is a *selection* in the filter bar, not a period the server can resolve: it means "use the
 * explicit range I typed", and it is expressed by **omitting** `period` and sending `from`/`to`. Putting
 * it in this union would let a client send `{"period": "custom"}`, which the server refuses as an
 * unknown period. So the wire vocabulary and the picker's vocabulary are different types, and conflating
 * them is how that 422 gets written in the first place.
 *
 * `period` is a member in its own right, not a parameterisation of `from`/`to`: FR-068 presents a period
 * and a custom range as **alternatives** and makes them mutually exclusive, which a parameterisation and
 * its own host cannot be. FR-064 was amended to say so on 2026-09-30.
 */
export type ReportPeriodName = 'month' | 'quarter' | 'year'

export const REPORT_PERIOD_NAMES: readonly ReportPeriodName[] = ['month', 'quarter', 'year']

/**
 * What the period picker offers: the three resolvable periods, plus `custom` for the explicit range.
 *
 * `custom` is deliberately a superset of {@link ReportPeriodName}. The picker must be able to say "no
 * period, use my dates", and that choice is expressed by the **absence** of `period` from the request —
 * so it has a name here and no name on the wire.
 */
export type ReportPeriodSelection = ReportPeriodName | 'custom'

export const REPORT_PERIOD_SELECTIONS: readonly ReportPeriodSelection[] = [
	...REPORT_PERIOD_NAMES,
	'custom',
]

/**
 * FR-068's disclosure: the range and zone a `period` actually resolved to.
 *
 * **These come back from the server, not from the client.** A period is resolved in the requesting
 * user's own time zone at query time, so the client cannot compute the resolved range — and a client
 * that guessed it would disagree with the server by a day at the boundary, which for a financial period
 * is a wrong answer rather than a wrong display. The `period` the user picked is an intent; this is what
 * it meant.
 */
export interface ReportPeriodDisclosure {
	period?: ReportPeriodName
	/** First day of the resolved period, inclusive, as `YYYY-MM-DD`. */
	resolvedFrom?: string
	/** Last day of the resolved period, inclusive, as `YYYY-MM-DD`. */
	resolvedTo?: string
	/** The zone the period was resolved in — a zone name, not a UTC offset. */
	resolvedTimeZone?: string
}

/**
 * The keys the disclosure occupies inside a report's `summary`.
 *
 * **Declared once, here, because two components need it and the response shape is the reason.** FR-068
 * puts the disclosure *inside* `summary` rather than beside it, so `ReportTable` renders that object as
 * totals and would otherwise show `resolvedFrom` as though it were a figure alongside real sums — and
 * `ReportPeriodDisclosure` needs to read the same four keys. One list here is what stops the disclosure
 * being rendered twice, once as a period and once as a number that looks like money.
 *
 * Typed against {@link ReportPeriodDisclosure} so a renamed field is a type error rather than a key
 * that quietly stops matching.
 */
export const REPORT_PERIOD_DISCLOSURE_KEYS = Object.freeze([
	'period',
	'resolvedFrom',
	'resolvedTo',
	'resolvedTimeZone',
] as const satisfies readonly (keyof ReportPeriodDisclosure)[])

/**
 * Reads FR-068's disclosure out of whichever object carries it, or `null` when none was sent.
 *
 * **Two carriers, one rule.** `GET /reports` nests the four keys inside `summary`; `POST /exports` returns
 * them at the response root. The *relative* position is the same — the keys sit together at one level of an
 * object the server built — so one reader serves both, and a component that rendered the disclosure does
 * not have to know which endpoint it came from.
 *
 * **`null` means absent, and that is not the same as empty.** The contract makes the disclosure absent when
 * no period was resolved, rather than present and null, because a key describing a request the response
 * did not use is a claim about the request rather than about the answer. A caller that substituted an
 * empty disclosure here would put "no period resolved" on screen over a report filtered by an explicit
 * range.
 *
 * Values are passed through as the server sent them and never reformatted — a period boundary is what two
 * users reconcile exports against, and a locally-rendered copy would disagree with the authoritative one by
 * a day at the edge.
 */
/**
 * The shape the reader needs — the four disclosure keys, and nothing more.
 *
 * **Structural, so both carriers satisfy it without being widened to `Record<string, unknown>`.**
 * `ExportResult` is an interface with named fields and no index signature, and demanding an index
 * signature of it would weaken the one type that describes every export response in order to read four
 * optional keys. A parameter typed as this shape accepts `ExportResult`, a report `summary`, and any future
 * carrier that carries the keys — while rejecting an object that has none of them.
 */
export interface PeriodDisclosureSource {
	period?: unknown
	resolvedFrom?: unknown
	resolvedTo?: unknown
	resolvedTimeZone?: unknown
}

export function readPeriodDisclosure(
	source: PeriodDisclosureSource | undefined | null,
): ReportPeriodDisclosure | null {
	if (!source || source.period === undefined || source.period === null) return null

	const read = (key: (typeof REPORT_PERIOD_DISCLOSURE_KEYS)[number]): string | undefined => {
		const value = source[key]
		return value === undefined || value === null ? undefined : String(value)
	}

	return {
		period: read('period') as ReportPeriodDisclosure['period'],
		resolvedFrom: read('resolvedFrom'),
		resolvedTo: read('resolvedTo'),
		resolvedTimeZone: read('resolvedTimeZone'),
	}
}

/**
 * FR-064 and FR-065 filters, in **the server's vocabulary**.
 *
 * ## Why the names are these and not readable ones
 *
 * This interface used to declare `status`, `ownerId`, `costCenterId`, `dateFrom`, `amountMin` and ten
 * more, and the client wrote each one as its own query parameter. The endpoint reads exactly one
 * parameter — `filters`, JSON-decoded — and validates it against a **closed** vocabulary
 * (`ReportFilterFactory::ACCEPTED_FILTERS`). Not one of the two sets overlapped: not even `dateFrom`
 * against `from`. So the request carried no filter the server recognised, the server returned the
 * unfiltered report, and the UI rendered it as though the user's filter had been applied. Fixing the
 * encoding alone would have turned that silent wrong answer into a 422.
 *
 * The names are therefore the server's, and the mapping is the identity. A client-side alias layer
 * would reintroduce exactly the drift this removes: two vocabularies that can disagree, and nothing
 * to say which one is right when they do.
 *
 * ## The shape is the server's too
 *
 * `statuses`, `currencyCodes`, `stages`, `eventTypes`, `actorUids`, `costCenterIds` and
 * `expenseTypeIds` are **lists** on the wire, because `ReportFilterFactory` refuses a non-array for
 * any of them. A single-select dropdown therefore sends a one-element list, not a bare string.
 *
 * Amounts are `amountMinMinor`/`amountMaxMinor` — integer minor units, not the major-unit numbers a
 * text field collects. There is no fiscal or display rounding anywhere on this path, and a filter that
 * silently multiplied or divided by 1000 would be a wrong financial answer rather than a wrong display.
 */
export interface ReportFilters {
	/**
	 * FR-068: a predefined Jalali period, resolved server-side in the user's own time zone.
	 *
	 * **Mutually exclusive with `from`/`to` within one request.** The server refuses the combination
	 * with a 422 rather than honouring one while discarding the other, so a request must carry a period
	 * *or* an explicit range — never both, and never one silently dropped. A client that sends both gets
	 * an error naming the conflict rather than a filtered answer to a different question than it asked.
	 *
	 * The union is {@link ReportPeriodName}, which excludes `custom`: choosing a custom range means
	 * omitting this field, not sending `'custom'`.
	 */
	period?: ReportPeriodName
	from?: string
	to?: string
	statuses?: string[]
	currencyCodes?: string[]
	/** Cost centres by **public UUID**. A numeric database id is an internal join key and is not accepted. */
	costCenterIds?: string[]
	/** Expense types by **public UUID**, for the same reason. */
	expenseTypeIds?: string[]
	actorUids?: string[]
	eventTypes?: string[]
	createdBy?: string
	stages?: string[]
	amountMinMinor?: number
	amountMaxMinor?: number
}

export type ReportRow = Record<string, unknown>

/** `ReportPage` from `contracts/ocs-api.yaml`: page metadata plus `columns`, `rows`, `summary`. */
export interface ReportPage {
	page?: number
	pageSize?: number
	total?: number
	columns?: string[]
	rows?: ReportRow[]
	summary?: Record<string, unknown>
}

export interface ReportPageParams {
	page?: number
	pageSize?: number
	filters?: ReportFilters
}

/**
 * FR-066 exports permitted reports in the formats `contracts/exports.md` assigns to them. Group
 * evidence is the ZIP subject, so only the evidence report offers `zip`; the tabular and history
 * subjects offer the three document formats. The client never offers a format the subject does not
 * have, and never silently substitutes one.
 *
 * **The three subjects with no exporter offer nothing, and that is the point of T342.** `cost-centers`,
 * `accounts` and `process` all map onto the generic `report` resource, and the server resolves rows for
 * `sheet`, `sheets` and `transactions` only — it used to answer the rest with an empty artifact, so the
 * button produced a well-formed CSV with a header row, no rows, and a `rowCount: 0` that read as a
 * **measured** zero. Offering three formats for a subject whose exporter does not exist is the client
 * half of that failure, and the server now refuses those requests with a 422 that says why.
 *
 * **`audit` and `evidence` keep their formats deliberately, and the difference is who is asking.** Their
 * resources are refused by the same rule, so offering them would offer something that cannot be
 * produced — but a refusal the server explains on click ("not supported yet", naming the resources that
 * do work) tells a user something a silently disabled control does not, and both subjects are required
 * V1 exports (FR-066's audit export, FR-074/FR-082's grouped evidence ZIP) whose row projections are
 * outstanding work rather than absent intent. **The three subjects above get no formats because the
 * client itself invents their `report` resource** — there is no per-subject server capability for them to
 * correspond to, so nothing about offering an export for them is honest.
 */
export const REPORT_EXPORT_FORMATS: Record<ReportType, readonly ExportFormat[]> = {
	sheets: ['csv', 'xlsx', 'pdf'],
	transactions: ['csv', 'xlsx', 'pdf'],
	'cost-centers': [],
	accounts: [],
	process: [],
	audit: ['csv', 'xlsx', 'pdf'],
	evidence: ['csv', 'zip'],
}

/**
 * Maps a report subject onto the export `resource` the contract declares. Only the four subjects
 * that have their own resource name are mapped; the rest are exported through the generic `report`
 * resource, which is how a cost-center, account, or process report is requested.
 */
export const REPORT_EXPORT_RESOURCE: Record<ReportType, ExportRequest['resource']> = {
	sheets: 'sheets',
	transactions: 'transactions',
	evidence: 'evidence',
	audit: 'audit',
	'cost-centers': 'report',
	accounts: 'report',
	process: 'report',
}
