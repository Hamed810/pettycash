// UXC-7-01: sheet-level approved monetary aggregates per workflow stage.
//
// Pure presentation/read-model arithmetic for the shared Approvals tab. It never posts, settles,
// or re-derives workflow state: it only sums amounts whose authoritative stage decision qualifies
// as approved, and reports every stage the role's loaded data cannot prove as honestly unknown.
//
// UXC-7F-05 (owner decision, 2026-11-20): the Approvals tab's primary source is now the server's
// whole-sheet `stageApprovedTotals` aggregate, decoded and validated by
// `decodeStageApprovedTotals`/`authoritativeStageApprovals` below. The client-side
// `aggregateStageApprovals` arithmetic remains for surfaces that legitimately sum their own proven
// scope; it is never a substitute for the whole-sheet aggregate.
//
// Currency safety (006 FR-006-014): amounts are grouped per currency and never summed across
// currencies. No conversion, no cross-currency arithmetic.
import type { Decision, DecisionValue, Money, StageApprovedTotals } from '@/types/api'

/** The three workflow stages the Approvals tab reports, in canonical order. */
export type ApprovalsStageKey = 'costCenter' | 'senior' | 'finance'

export const APPROVALS_STAGE_KEYS: readonly ApprovalsStageKey[] = ['costCenter', 'senior', 'finance']

/**
 * One stage's aggregate knowledge for the Approvals tab.
 *
 * - `known: true` — the role's authoritative data proves every transaction's decision at this
 *   stage, so the per-currency approved total is derivable. An empty `totals` array is a real
 *   answer ("nothing approved at this stage yet"), not a gap.
 * - `known: false` — honest unavailable: at least one transaction's stage decision is not proven
 *   by the loaded data, so no partial or estimated total may be shown.
 */
export interface StageApprovalAggregate {
	known: boolean
	totals: Money[]
}

/**
 * One transaction's contribution to the stage aggregates.
 *
 * `amount` is the transaction's active-revision amount. Each stage decision is the latest
 * authoritative decision at that stage: the `DecisionValue` when one was recorded, `null` when the
 * transaction provably has none, `undefined` when this role's data cannot prove it (unknown
 * propagates and renders the whole stage unavailable — a partial sum would present a lower bound
 * as the total).
 */
export interface StageApprovalsTransactionInput {
	amount: Money | null
	stageDecisions: Record<ApprovalsStageKey, DecisionValue | null | undefined>
}

/**
 * The latest authoritative decision at one stage from a transaction's `decisions[]`.
 *
 * `decisions` is the detail read's full decision history; the last match is the latest, matching
 * the per-stage indicator convention in the review views. `undefined` (decisions unknown — the
 * detail read has not loaded) is distinguished from `null` (history loaded, no decision at this
 * stage): only the latter proves "no decision yet".
 */
export function latestDecisionForStage(
	decisions: readonly Decision[] | undefined,
	stage: Decision['stage']
): Decision | null | undefined {
	if (!Array.isArray(decisions)) return undefined
	const matches = decisions.filter((decision) => decision.stage === stage)
	return matches.length > 0 ? matches[matches.length - 1]! : null
}

/**
 * The same latest-per-stage decision as `latestDecisionForStage`, collapsed to its value — the
 * shape `StageApprovalsTransactionInput` consumes (`undefined` stays unknown, `null` stays
 * proven-none).
 */
export function latestDecisionValueForStage(
	decisions: readonly Decision[] | undefined,
	stage: Decision['stage']
): DecisionValue | null | undefined {
	const decision = latestDecisionForStage(decisions, stage)
	return decision === undefined ? undefined : decision?.decision ?? null
}

function totalsKey(amount: Money): string {
	return `${amount.currency}:${amount.minorUnit}`
}

/**
 * Aggregate approved amounts per workflow stage over the given transactions.
 *
 * A stage's total includes only amounts whose latest authoritative decision at that stage is
 * APPROVE. Rejected, returned and undecided transactions never increase a total. Any transaction
 * whose stage decision is unknown — or whose approved amount is missing, which would understate
 * the total — renders that whole stage honestly unavailable.
 */
export function aggregateStageApprovals(
	transactions: readonly StageApprovalsTransactionInput[]
): Record<ApprovalsStageKey, StageApprovalAggregate> {
	const result = {} as Record<ApprovalsStageKey, StageApprovalAggregate>
	for (const stage of APPROVALS_STAGE_KEYS) {
		let known = true
		const byCurrency = new Map<string, Money>()
		for (const transaction of transactions) {
			const decision = transaction.stageDecisions[stage]
			if (decision === undefined) {
				known = false
				break
			}
			if (decision !== 'APPROVE') continue
			const amount = transaction.amount
			if (amount === null || amount === undefined) {
				known = false
				break
			}
			const key = totalsKey(amount)
			const existing = byCurrency.get(key)
			byCurrency.set(key, {
				currency: amount.currency,
				minorUnit: amount.minorUnit,
				amountMinor: (existing?.amountMinor ?? 0) + amount.amountMinor,
			})
		}
		if (!known) {
			result[stage] = { known: false, totals: [] }
			continue
		}
		const totals = Array.from(byCurrency.values()).sort((a, b) =>
			a.currency === b.currency
				? a.minorUnit - b.minorUnit
				: a.currency.localeCompare(b.currency)
		)
		result[stage] = { known: true, totals }
	}
	return result
}

function isMoney(value: unknown): value is Money {
	if (value === null || typeof value !== 'object') return false
	const money = value as Record<string, unknown>
	return Number.isFinite(money.amountMinor)
		&& typeof money.currency === 'string' && money.currency !== ''
		&& Number.isFinite(money.minorUnit)
}

/**
 * Decode the server's whole-sheet `stageApprovedTotals` aggregate, or `null` when the payload
 * does not carry a well-formed one (UXC-7F-05).
 *
 * The three stage figures arrive as one required object, so the field is judged as a unit: any
 * missing or malformed `Money` makes the whole aggregate undecodable and the caller renders every
 * stage honestly unavailable — never a partial aggregate reconstructed from whichever figures
 * happen to parse. Zero amounts are real answers and pass validation unchanged.
 */
export function decodeStageApprovedTotals(raw: unknown): StageApprovedTotals | null {
	if (raw === null || typeof raw !== 'object') return null
	const candidate = raw as Record<string, unknown>
	if (!isMoney(candidate.costCenterApproved)) return null
	if (!isMoney(candidate.seniorApproved)) return null
	if (!isMoney(candidate.financeApproved)) return null
	return {
		costCenterApproved: candidate.costCenterApproved,
		seniorApproved: candidate.seniorApproved,
		financeApproved: candidate.financeApproved,
	}
}

/**
 * The `stages` prop shape `SheetApprovalsSummary` consumes, in canonical stage order.
 */
export type ApprovalsStageList = readonly [
	{ key: 'costCenter'; state: StageApprovalAggregate },
	{ key: 'senior'; state: StageApprovalAggregate },
	{ key: 'finance'; state: StageApprovalAggregate },
]

/**
 * The Approvals-tab stage states for the server's whole-sheet `stageApprovedTotals` (UXC-7F-05).
 *
 * The raw payload is validated first: `null` — and therefore an honest unavailable rendering —
 * for an absent or malformed field. A decoded aggregate maps one-to-one: each stage is known and
 * carries exactly the server's single-currency `Money` for it, with a zero total staying a known
 * zero, distinct from an unprovable stage.
 */
export function authoritativeStageApprovals(raw: unknown): ApprovalsStageList | null {
	const totals = decodeStageApprovedTotals(raw)
	if (totals === null) return null
	return [
		{ key: 'costCenter', state: { known: true, totals: [totals.costCenterApproved] } },
		{ key: 'senior', state: { known: true, totals: [totals.seniorApproved] } },
		{ key: 'finance', state: { known: true, totals: [totals.financeApproved] } },
	]
}

/**
 * The honest-unavailable state for every stage: the role's loaded data carries no authoritative
 * whole-sheet aggregate, and no client-side derivation may stand in for one.
 */
export function unprovenApprovalsStages(): ApprovalsStageList {
	return [
		{ key: 'costCenter', state: { known: false, totals: [] } },
		{ key: 'senior', state: { known: false, totals: [] } },
		{ key: 'finance', state: { known: false, totals: [] } },
	]
}
