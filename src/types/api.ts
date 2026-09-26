export interface ApiErrorBody { code: string; message: string; commandId?: string; fieldErrors?: Record<string, string[]> }
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
	allowedActions: CapabilitySet
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
	deactivatedAt?: string
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
	sourceRevisionId?: string
	occurredAt: string
}

export interface AccountMutationResult {
	account: Account
	movement: Movement
}

// Capability types
export type CapabilitySet = Record<string, boolean>
export interface SessionContext {
	userId: string
	displayName: string
	roles: Role[]
	capabilities: CapabilitySet
}

export type Role = 'PURCHASER' | 'COST_CENTER_MANAGER' | 'SENIOR_MANAGER' | 'FINANCE' | 'ADMINISTRATOR'

// Export types
export interface ExportRequest {
	resource: 'sheet' | 'sheets' | 'transactions' | 'report' | 'audit' | 'evidence'
	format: 'csv' | 'xlsx' | 'pdf' | 'zip'
	ids?: string[]
	reportType?: string
	filters?: Record<string, unknown>
}