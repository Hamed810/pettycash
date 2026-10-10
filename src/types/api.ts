// Type-only, and therefore erased at compile time: `reporting.ts` imports `ExportRequest` from this file
// the same way, so the two reference each other without a runtime cycle. `ReportPeriodName` is imported
// rather than restated because a period union written twice is a vocabulary that can disagree with itself —
// and a disagreement here would let a client send `period: 'week'` past a type that no longer matches the
// one `ReportFilters` enforces.
import type { ReportPeriodName } from './reporting'

export interface ApiErrorBody { code: string; message: string; commandId?: string; fieldErrors?: Record<string, string[]> }
/**
 * The `ocs.data` value of every error response, as `ErrorEnvelope` declares it.
 *
 * `OcsErrorMapper` nests the `Error` schema under `error`, so the client reads
 * `ocs.data.error.code` rather than `ocs.data.code`. Naming the wrapper here — instead of the
 * inline shape `http.ts` used to carry — is what keeps the third side of the same wire from being
 * written against the implementation instead of against the contract (T334).
 */
export interface ApiErrorEnvelope { error: ApiErrorBody }
export interface Page<T> { items: T[]; page: number; pageSize: number; total: number }
export interface CommandOptions { commandId?: string; expectedVersion?: number }

export class ApiError extends Error {
	public constructor(public readonly status: number, public readonly body: ApiErrorBody) {
		super(body.message)
	}
}

// Filter types
export interface SheetFilters {
	status?: string
	ownerId?: string
	currency?: string
	from?: string
	to?: string
}

export interface TransactionFilters {
	status?: string
	costCenterId?: string
	expenseTypeId?: string
	dateFrom?: string
	dateTo?: string
	amountMin?: number
	amountMax?: number
}

// Sheet types
/**
 * `POST /sheets`'s request body.
 *
 * **`accountId` is optional, and its absence is meaningful rather than merely unchecked** (T358). FR-020
 * resolves the account from the caller's own active accounts: none means the default rial account is
 * provisioned on this attempt and used, exactly one means it is used automatically, and several means the
 * caller must send this field. A zero-account purchaser therefore has no `accountId` to send — which is why
 * making it required made FR-020 unreachable from the interface, and why `contracts/ocs-api.yaml`'s
 * `SheetCreate.required` now names only `title`.
 *
 * Kept optional here in step with the contract rather than asserted against it: the generated
 * `src/generated/api.ts` derives the same optionality, and `check:openapi` is what holds this one to it.
 */
export interface SheetCreate { title: string; accountId?: string }

export interface SheetSummary {
	id: string
	title: string
	ownerId: string
	ownerDisplayName: string
	currency: string
	status: SheetStatus
	transactionCount: number
	totals: SheetTotals
	createdAt: string
	submittedAt: string | null
	completedAt: string | null
	firstExpenseDate: string | null
	lastExpenseDate: string | null
	version: number
	/**
	 * FR-063's latest active transaction revision number: the highest revision number among the active
	 * revisions of this sheet's transactions, and **0** when the sheet holds none.
	 *
	 * Added 2026-10-06 (T385). The requirement named *"the active transaction revision"*, which is a member
	 * of {@link TransactionSummary} — one per transaction — so a sheet has as many as it has transactions
	 * and a list row carries none at all. A count would be redundant with {@link SheetSummary.transactionCount},
	 * so the requirement is amended to name this value, which is a single figure both surfaces can render.
	 *
	 * Optional rather than required because the contract declares it `nullable`: `null` means the payload did
	 * not carry it, while `0` is the real answer for a sheet with no transactions.
	 */
	activeRevisionNumber?: number | null
}

export interface SheetTotals {
	current: Money
	submitted: Money
	effectiveApproved: Money
	effectiveRejected: Money
	effectivePending: Money
	costCenterApproved: Money
	seniorApproved: Money
	financeApproved: Money
}

export type SheetStatus =
	| 'OPEN'
	| 'COST_CENTER'
	| 'CORRECTION'
	| 'SENIOR'
	| 'FINANCE'
	| 'COMPLETED'
	| 'COMPLETED_WITH_REJECTIONS'

/**
 * Every `SheetStatus`, for controls that must offer the whole vocabulary.
 *
 * **Declared from the union rather than written beside it**, and typed as
 * `readonly SheetStatus[]`, for the same reason `ALLOWED_ACTION_KEYS` below is typed: a hand-written list
 * beside a union is two statements of one rule, and adding a member to the union without adding it here
 * produces a picker that is quietly missing an option — which on a filter is a *wrong answer*, because
 * the user narrows to a status they can see and the server has one the control does not offer.
 *
 * The `satisfies` clause means a member that is not a `SheetStatus` is a **compile** error rather than a
 * runtime surprise, and the element type widens the literal to the union so callers get `SheetStatus[]`
 * rather than a seven-element tuple.
 */
export const SHEET_STATUSES = [
	'OPEN',
	'COST_CENTER',
	'CORRECTION',
	'SENIOR',
	'FINANCE',
	'COMPLETED',
	'COMPLETED_WITH_REJECTIONS',
] as const satisfies readonly SheetStatus[]

export interface SheetDetail extends SheetSummary {
	allowedActions: CapabilitySet
	transactions: TransactionSummary[]
}

export interface Money {
	amountMinor: number
	currency: string
	minorUnit: number
}

// Transaction types
export interface TransactionDraftWrite {
	expenseDate: string
	amountMinor: number
	description?: string
	costCenterId?: string
	expenseTypeId?: string
}

export interface TransactionSummary {
	id: string
	sheetId: string
	activeRevision: Revision
	stage: TransactionStage
	status: TransactionStatus
	finalOutcome: 'APPROVED' | 'REJECTED' | null
	/**
	 * Absent until a decision exists.
	 *
	 * `contracts/ocs-api.yaml` does not list `latestDecision` among `TransactionSummary`'s required
	 * properties, so a fresh draft has none. Declaring it non-optional is what forces every reader to
	 * either lie about it or cast, and a reader that lies renders a blank cell where a draft's "no
	 * decision yet" belongs.
	 */
	latestDecision?: Decision
	evidenceCount: number
	version: number
}

export interface TransactionDetail extends TransactionSummary {
	allowedActions: AllowedActions
	decisions: Decision[]
	evidence: Evidence[]
}

export interface Revision {
	id: string
	number: number
	expenseDate: string
	amount: Money
	description?: string
	costCenter: EntitySnapshot
	expenseType: EntitySnapshot
	createdBy: string
	createdAt: string
	active: boolean
}

export interface EntitySnapshot {
	id: string
	code?: string
	name: string
}

export type TransactionStage =
	| 'DRAFT'
	| 'COST_CENTER'
	| 'CORRECTION'
	| 'SENIOR'
	| 'FINANCE'
	| 'FINAL'

export type TransactionStatus =
	| 'DRAFT'
	| 'AWAITING_COST_CENTER_REVIEW'
	| 'COST_CENTER_APPROVED'
	| 'COST_CENTER_REJECTED'
	| 'AWAITING_SENIOR_REVIEW'
	| 'SENIOR_APPROVED'
	| 'SENIOR_REJECTED'
	| 'AWAITING_FINANCE_REVIEW'
	| 'AWAITING_PURCHASER_CORRECTION'
	| 'COMPLETED_APPROVED'
	| 'COMPLETED_REJECTED'

export interface DecisionWrite {
	decision: 'APPROVE' | 'REJECT' | 'RETURN'
	explanation?: string
	expectedVersion: number
}

/**
 * One formal audit event, as `GET /transactions/{id}/audit` emits it
 * (T093/T098). The actor snapshot is historical — the identity and role at the
 * time — so a disabled or deleted user reads unchanged (FR-054).
 */
export interface AuditEvent {
	id: string
	eventType: string
	stage: string
	decision: string | null
	explanation?: string | null
	actor: ActorSnapshot
	before?: unknown
	after?: unknown
	occurredAt: string
}

/** The three decisions a review stage can record. */
export type DecisionValue = 'APPROVE' | 'REJECT' | 'RETURN'

export interface Decision {
	id: string
	stage: 'COST_CENTER' | 'SENIOR' | 'FINANCE'
	decision: 'APPROVE' | 'REJECT' | 'RETURN'
	explanation?: string
	actor: ActorSnapshot
	decidedAt: string
}

export interface ActorSnapshot {
	userId: string
	displayName: string
	role: string
}

export interface DecisionResult {
	decision: Decision
	transaction: TransactionDetail
	accountMutation?: AccountMutationResult
}

export interface Evidence {
	id: string
	transactionId: string
	revisionId: string
	name: string
	mediaType: string
	sizeBytes: number
	active: boolean
	uploadedBy: string
	uploadedAt: string
	deactivatedAt?: string | null
	/**
	 * Why the evidence was deactivated.
	 *
	 * Declared by `contracts/ocs-api.yaml` in T339 and emitted by the single `Evidence` formatter. It
	 * is the only observable of whether a file was *marked inactive* (FR-058, after submission) or
	 * deleted with its draft transaction, so a client that renders the difference has to have it.
	 */
	deactivationReason?: string | null
}

// Configuration types
/**
 * A currency as `contracts/ocs-api.yaml`'s `Currency` describes it: the write fields plus the
 * optimistic-lock `version`.
 *
 * `minorUnit` is part of the type rather than a separate lookup because every amount the client
 * formats is divided by it — and a client that assumed 2 (as four views once did) printed rial
 * amounts a hundred times too small.
 */
export interface Currency {
	code: string
	name: string
	minorUnit: number
	active: boolean
	version: number
}

export interface CurrencyWrite {
	code: string
	name: string
	minorUnit: number
	active: boolean
}
/**
 * A cost center as `contracts/ocs-api.yaml`'s `CostCenter` describes it.
 *
 * A transaction is routed to the manager the server holds for this cost center (FR-032), so the
 * client sends the identifier and never the manager: an invented or stale manager assignment would
 * route the transaction to nobody.
 */
export interface CostCenter {
	id: string
	code: string
	name: string
	validFrom: string
	validTo: string | null
	active: boolean
	version: number
}

/**
 * An expense type as `contracts/ocs-api.yaml`'s `ExpenseType` describes it.
 *
 * `evidenceRequired` is what decides whether FR-023's evidence rule applies to a transaction, so it
 * is part of the type rather than a separate lookup: reading it from anywhere else would let the
 * form demand evidence the configuration does not require, or omit evidence it does.
 */
export interface ExpenseType {
	id: string
	title: string
	evidenceRequired: boolean
	active: boolean
	version: number
}

export interface ExpenseTypeWrite {
	title: string
	description?: string | null
	evidenceRequired: boolean
	active: boolean
	changeReason: string
}

export interface CostCenterWrite {
	code: string
	name: string
	description?: string | null
	validFrom: string
	validTo?: string | null
	active: boolean
	managerUserId: string
	seniorManagerUserId: string
	changeReason: string
}

/**
 * A finance-officer assignment as the contract's `RoleAssignment` describes it.
 *
 * The role travels on the row (rather than being implied by the endpoint) because the roster this
 * renders lists who may settle expenses — and FR-016's shared queue means every officer sees every
 * finance-stage transaction, so the assignment is the fact under review, not the person alone.
 */
export interface FinanceAssignment {
	id: string
	role: string
	userId: string
	validFrom: string
	validTo: string | null
	active: boolean
	version: number
}

export interface FinanceAssignmentWrite {
	userId: string
	validFrom: string
	validTo?: string | null
	active: boolean
	changeReason: string
}

// Account types
export interface Account {
	id: string
	userId: string
	balance: Money
	active: boolean
	version: number
}

export interface AccountCreate {
	userId: string
	currency: string
}

export interface AccountCharge {
	amountMinor: number
	description: string
	occurredAt: string
	expectedVersion: number
}

/**
 * T409's active-state change: deactivation or reactivation of one account by an administrator.
 *
 * `active` is required and `expectedVersion` travels in `CommandOptions`, mirroring the charge
 * shape — the version guard is what makes two administrators acting at once safe rather than
 * last-write-wins.
 */
export interface AccountActiveUpdate {
	active: boolean
	explanation?: string | null
}

export interface Movement {
	id: string
	type: 'CHARGE' | 'CONSUMPTION'
	amount: Money
	balanceAfter: Money
	description: string
	/**
	 * The public UUID of the revision this movement settled, or `null` for a charge.
	 *
	 * Nullable rather than merely optional because `contracts/ocs-api.yaml` declares it
	 * `nullable: true` and the endpoint sends it on every row: an absent property and an explicit
	 * `null` are different answers here, because the first would leave "a charge, which no revision
	 * justifies" indistinguishable from a projection that forgot the field.
	 */
	sourceRevisionId?: string | null
	occurredAt: string
}

export interface AccountMutationResult {
	account: Account
	movement: Movement
}

// Capability types
export type CapabilitySet = Record<string, boolean>

/**
 * The keys `TransactionPayloadFormatter::allowedActions()` emits, and no others.
 *
 * `CapabilitySet` is a free-form `Record<string, boolean>`, which is what let T335 ship: the filter
 * looked up `allowedActions[item.value.toLowerCase()]` — `approve`, `reject`, `return` — while the
 * server emits `canApprove`, `canReject`, `canReturn`. `undefined !== false`, so **every** option
 * survived for every role, and a free-form record cannot make a wrong key a compile error. Naming the
 * six keys here is what turns that lookup into a checked one: a key that is not in this union cannot
 * be indexed without a cast.
 */
export const ALLOWED_ACTION_KEYS = [
	'canApprove',
	'canReject',
	'canReturn',
	'canEdit',
	'canDelete',
	'canCorrect',
] as const

export type AllowedActionKey = typeof ALLOWED_ACTION_KEYS[number]

/**
 * All members optional because the contract's `CapabilitySet` names no properties, so a strict
 * validator accepts a payload that omits any of them.
 */
export type AllowedActions = Partial<Record<AllowedActionKey, boolean>>

/**
 * Which capability decides each decision, in one place.
 *
 * The mapping is the whole point of this constant: deriving the key from the decision value with
 * `toLowerCase()`/`capitalize()` is what produced `approve` instead of `canApprove`. Written out, a
 * decision added without its capability is a compile error rather than an option that silently
 * survives the filter.
 */
export const DECISION_CAPABILITY: Record<DecisionValue, AllowedActionKey> = {
	APPROVE: 'canApprove',
	REJECT: 'canReject',
	RETURN: 'canReturn',
}
export interface SessionContext {
	userId: string
	displayName: string
	roles: Role[]
	capabilities: CapabilitySet
	/**
	 * The cost centres this caller is assigned to review (FR-004, FR-005).
	 *
	 * Required by the contract, so the store may read it without a `?? []` that would hide a server
	 * which stopped sending it. The codes — not public ids — because this is the vocabulary
	 * `ManagementReviewService::getManagedCostCenterCodes()` authorizes with and the one a transaction
	 * row carries as `costCenter.code` (T336).
	 */
	costCenterCodes: string[]
}

export type Role = 'PURCHASER' | 'COST_CENTER_MANAGER' | 'SENIOR_MANAGER' | 'FINANCE' | 'ADMINISTRATOR'

// Export types
/**
 * `POST /exports` request body, exactly as `contracts/ocs-api.yaml` names it.
 *
 * **The field is `fileFormat`, not `format`, and that is not a preference.** Nextcloud's OCS layer
 * reserves the request parameter `format` for envelope selection: `Request::getFormat()` reads
 * `getParam('format')` *before* the `Accept` header, and `OCSMiddleware` falls back to `xml` for any
 * value that is not a registered responder. A body field literally called `format` therefore both
 * fails the endpoint's own required-field check — the controller binds `fileFormat` and answers
 * `422` with `fieldErrors.fileFormat` — and forces the wrong response envelope. The contract documents
 * this at `ocs-api.yaml:1287-1293`; this interface carried `format` anyway, which is why every export
 * was refused.
 */
export interface ExportRequest {
	resource: 'sheet' | 'sheets' | 'transactions' | 'report' | 'audit' | 'evidence'
	fileFormat: 'csv' | 'xlsx' | 'pdf' | 'zip'
	ids?: string[]
	reportType?: string
	filters?: Record<string, unknown>
}

/**
 * `POST /exports` response — the contract's `ExportResult`.
 *
 * `data` is the artifact, base64-encoded, because Nextcloud's OCS layer wraps every body as JSON and
 * so cannot return raw artifact bytes. `mediaType` is the artifact's own type, not the envelope's,
 * which is what the client re-wraps the decoded bytes in: saving the envelope instead would produce a
 * file named `.csv` whose contents are `{"ocs":{"data":{…,"data":"<base64>"}}}`.
 */
export interface ExportResult {
	artifactName: string
	mediaType: 'text/csv' | 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' | 'application/pdf' | 'application/zip'
	format: 'csv' | 'xlsx' | 'pdf' | 'zip'
	byteSize: number
	rowCount: number
	/**
	 * FR-068: the period this artifact covers, and the range and zone it resolved to.
	 *
	 * **Present only when the request resolved a predefined `period`** — the contract makes the disclosure
	 * absent otherwise rather than present and null, so every field is optional here for that reason and not
	 * because the values are uncertain.
	 *
	 * Declared because `POST /exports` sends them. Without these four fields a client could not read the
	 * artifact's own period even if it wanted to, so the exported file's numbers would travel with no
	 * statement of the range or zone they cover — which is exactly what two users reconcile against each
	 * other. See `DownloadableExport.disclosure`.
	 */
	period?: ReportPeriodName
	resolvedFrom?: string
	resolvedTo?: string
	resolvedTimeZone?: string
	data: string
	artifactRemoved: boolean
}