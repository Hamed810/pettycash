import { describe, it, expect } from 'vitest'
import {
	aggregateStageApprovals,
	authoritativeStageApprovals,
	decodeStageApprovedTotals,
	unprovenApprovalsStages,
	latestDecisionForStage,
	latestDecisionValueForStage,
	type StageApprovalsTransactionInput,
} from '@/utils/stageApprovals'
import type { Decision, Money, StageApprovedTotals } from '@/types/api'

/**
 * UXC-7-01 — the sheet-level Approvals aggregation over authoritative stage decisions.
 *
 * The aggregation is display-only arithmetic over decision states the role's reads have already
 * proven: approved amounts sum per currency, rejected/returned/undecided transactions never
 * increase a total, and any transaction whose stage decision is not proven renders that stage
 * honestly unavailable instead of a partial or estimated total.
 *
 * UXC-7F-05 — the Approvals tab's primary source is the server's whole-sheet
 * `stageApprovedTotals`: the decode helpers here validate that field as one unit and map it to
 * the summary's stage states, so a zero total stays a known zero and an absent/malformed field
 * renders every stage honestly unavailable — never a partial or client-derived total.
 */

function money(amountMinor: number, currency = 'EUR', minorUnit = 2): Money {
	return { amountMinor, currency, minorUnit }
}

function input(overrides: {
	amount?: Money | null
	costCenter?: DecisionValue | null
	senior?: DecisionValue | null
	finance?: DecisionValue | null
}): StageApprovalsTransactionInput {
	return {
		amount: overrides.amount === undefined ? money(10_00) : overrides.amount,
		stageDecisions: {
			costCenter: overrides.costCenter,
			senior: overrides.senior,
			finance: overrides.finance,
		},
	}
}

type DecisionValue = 'APPROVE' | 'REJECT' | 'RETURN'

describe('aggregateStageApprovals()', () => {
	it('sums approved amounts per stage', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE' }),
			input({ costCenter: 'APPROVE' }),
		])
		expect(result.costCenter).toEqual({ known: true, totals: [money(20_00)] })
	})

	it('keeps different currencies separate (never a cross-currency sum)', () => {
		const result = aggregateStageApprovals([
			input({ amount: money(12_00, 'EUR'), costCenter: 'APPROVE' }),
			input({ amount: money(8_00, 'USD'), costCenter: 'APPROVE' }),
			input({ amount: money(1_00, 'EUR'), costCenter: 'APPROVE' }),
		])
		expect(result.costCenter).toEqual({
			known: true,
			totals: [money(13_00, 'EUR'), money(8_00, 'USD')],
		})
	})

	it('does not count rejected transactions', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE' }),
			input({ costCenter: 'REJECT', amount: money(99_00) }),
		])
		expect(result.costCenter).toEqual({ known: true, totals: [money(10_00)] })
	})

	it('does not count returned transactions', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE' }),
			input({ costCenter: 'RETURN', amount: money(99_00) }),
		])
		expect(result.costCenter).toEqual({ known: true, totals: [money(10_00)] })
	})

	it('does not count undecided transactions (proven-none stays a real answer)', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE' }),
			input({ costCenter: null, amount: money(99_00) }),
		])
		expect(result.costCenter).toEqual({ known: true, totals: [money(10_00)] })
	})

	it('renders a stage with no approvals as known-empty, not unavailable', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE', senior: null, finance: null }),
		])
		expect(result.costCenter).toEqual({ known: true, totals: [money(10_00)] })
		expect(result.senior).toEqual({ known: true, totals: [] })
		expect(result.finance).toEqual({ known: true, totals: [] })
	})

	it('renders a stage unavailable when one transaction\'s stage decision is unknown', () => {
		const result = aggregateStageApprovals([
			input({ senior: 'APPROVE' }),
			input({ senior: undefined }),
		])
		expect(result.senior).toEqual({ known: false, totals: [] })
	})

	it('unknown is per-stage: other stages still aggregate', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE', senior: 'APPROVE', finance: undefined }),
		])
		expect(result.costCenter.known).toBe(true)
		expect(result.senior.known).toBe(true)
		expect(result.finance).toEqual({ known: false, totals: [] })
	})

	it('an approved transaction with a missing amount renders the stage unavailable', () => {
		const result = aggregateStageApprovals([
			input({ costCenter: 'APPROVE' }),
			input({ costCenter: 'APPROVE', amount: null }),
		])
		// Summing only the known amount would present a lower bound as the total.
		expect(result.costCenter).toEqual({ known: false, totals: [] })
	})

	it('groups amounts by currency and minor unit scale', () => {
		const result = aggregateStageApprovals([
			input({ amount: money(10_00, 'EUR', 2), costCenter: 'APPROVE' }),
			input({ amount: money(5_0000, 'EUR', 4), costCenter: 'APPROVE' }),
		])
		expect(result.costCenter).toEqual({
			known: true,
			totals: [money(10_00, 'EUR', 2), money(5_0000, 'EUR', 4)],
		})
	})

	it('aggregates an empty set as known-empty for every stage', () => {
		const result = aggregateStageApprovals([])
		for (const stage of ['costCenter', 'senior', 'finance'] as const) {
			expect(result[stage]).toEqual({ known: true, totals: [] })
		}
	})
})

function decision(stage: Decision['stage'], kind: DecisionValue, id = 'd-1'): Decision {
	return {
		id,
		stage,
		decision: kind,
		actor: { userId: 'u1', displayName: 'U', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-09-20T10:00:00Z',
	}
}

// UXC-7F-05 — the server's whole-sheet stage-approved aggregate. A Cost Sheet is single-currency,
// so each stage carries exactly one `Money`; zero is a real derived answer.
function stageTotals(overrides: {
	costCenter?: Money
	senior?: Money
	finance?: Money
} = {}): StageApprovedTotals {
	return {
		costCenterApproved: overrides.costCenter ?? money(100_000, 'IRR', 0),
		seniorApproved: overrides.senior ?? money(50_000, 'IRR', 0),
		financeApproved: overrides.finance ?? money(0, 'IRR', 0),
	}
}

describe('decodeStageApprovedTotals()', () => {
	it('decodes a well-formed aggregate unchanged', () => {
		const totals = stageTotals()
		expect(decodeStageApprovedTotals(totals)).toEqual(totals)
	})

	it('accepts zero totals as real answers, not gaps', () => {
		const totals = stageTotals({
			costCenter: money(0, 'IRR', 0),
			senior: money(0, 'IRR', 0),
			finance: money(0, 'IRR', 0),
		})
		expect(decodeStageApprovedTotals(totals)).toEqual(totals)
	})

	it('rejects an absent payload', () => {
		expect(decodeStageApprovedTotals(undefined)).toBeNull()
		expect(decodeStageApprovedTotals(null)).toBeNull()
	})

	it('rejects a malformed aggregate as one unit — no partial reconstruction', () => {
		expect(decodeStageApprovedTotals('not-an-object')).toBeNull()
		expect(decodeStageApprovedTotals({})).toBeNull()
		// One malformed stage figure makes the whole aggregate undecodable: a partial aggregate
		// reconstructed from whichever figures happen to parse would present a lower bound as the
		// whole-sheet total.
		expect(decodeStageApprovedTotals({ ...stageTotals(), seniorApproved: null })).toBeNull()
		expect(decodeStageApprovedTotals({
			...stageTotals(),
			financeApproved: { amountMinor: Number.NaN, currency: 'IRR', minorUnit: 0 },
		})).toBeNull()
		expect(decodeStageApprovedTotals({
			...stageTotals(),
			costCenterApproved: { amountMinor: 1, currency: '', minorUnit: 0 },
		})).toBeNull()
	})
})

describe('authoritativeStageApprovals()', () => {
	it('maps the server aggregate to three known stages in canonical order', () => {
		const stages = authoritativeStageApprovals(stageTotals())
		expect(stages).not.toBeNull()
		expect(stages!.map((stage) => stage.key)).toEqual(['costCenter', 'senior', 'finance'])
		expect(stages!.map((stage) => stage.state)).toEqual([
			{ known: true, totals: [money(100_000, 'IRR', 0)] },
			{ known: true, totals: [money(50_000, 'IRR', 0)] },
			{ known: true, totals: [money(0, 'IRR', 0)] },
		])
	})

	it('renders a zero aggregate as known zero, distinct from unavailable', () => {
		const stages = authoritativeStageApprovals(stageTotals({
			costCenter: money(0, 'IRR', 0),
			senior: money(0, 'IRR', 0),
			finance: money(0, 'IRR', 0),
		}))
		expect(stages).not.toBeNull()
		for (const stage of stages!) {
			expect(stage.state.known).toBe(true)
			expect(stage.state.totals).toHaveLength(1)
			expect(stage.state.totals[0]!.amountMinor).toBe(0)
		}
	})

	it('returns null for an absent or malformed payload, so every stage renders unavailable', () => {
		expect(authoritativeStageApprovals(undefined)).toBeNull()
		expect(authoritativeStageApprovals({ costCenterApproved: money(1), seniorApproved: money(2) })).toBeNull()
	})
})

describe('unprovenApprovalsStages()', () => {
	it('renders every stage honestly unavailable with no fabricated total', () => {
		const stages = unprovenApprovalsStages()
		expect(stages.map((stage) => stage.key)).toEqual(['costCenter', 'senior', 'finance'])
		for (const stage of stages) {
			expect(stage.state).toEqual({ known: false, totals: [] })
		}
	})
})

describe('latestDecisionForStage()', () => {
	it('distinguishes unknown decisions[] (undefined) from loaded-but-empty (null)', () => {
		expect(latestDecisionForStage(undefined, 'SENIOR')).toBeUndefined()
		expect(latestDecisionForStage([], 'SENIOR')).toBeNull()
	})

	it('returns the last matching decision as the latest for the stage', () => {
		const decisions = [
			decision('COST_CENTER', 'APPROVE', 'd-1'),
			decision('COST_CENTER', 'REJECT', 'd-2'),
		]
		expect(latestDecisionForStage(decisions, 'COST_CENTER')?.id).toBe('d-2')
		expect(latestDecisionForStage(decisions, 'FINANCE')).toBeNull()
	})

	it('collapses to the decision value, preserving unknown-ness', () => {
		const decisions = [decision('COST_CENTER', 'RETURN')]
		expect(latestDecisionValueForStage(decisions, 'COST_CENTER')).toBe('RETURN')
		expect(latestDecisionValueForStage(undefined, 'COST_CENTER')).toBeUndefined()
		expect(latestDecisionValueForStage([], 'COST_CENTER')).toBeNull()
	})
})
