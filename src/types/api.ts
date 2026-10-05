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
export interface SheetCreate { title: string; accountId: string }

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
	| 'AWAITING_COST_CENTER_REVIEW'
	| 'AWAITING_SENIOR_REVIEW'
	| 'AWAITING_FINANCE_REVIEW'
	| 'COMPLETED'
	| 'COMPLETED_WITH_REJECTIONS'

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
	data: string
	artifactRemoved: boolean
}