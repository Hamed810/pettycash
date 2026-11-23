import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import SheetApprovalsSummary from '@/components/review/SheetApprovalsSummary.vue'
import type { StageApprovalAggregate } from '@/utils/stageApprovals'

/**
 * UXC-7-01 / UXC-7-03 — the shared Approvals-tab presentation used by all three management roles.
 *
 * The component renders one stage row per workflow stage with the canonical stage labels, keeps
 * currencies separate, and never fabricates: a known-empty stage says "nothing approved yet", an
 * unprovable stage says unavailable. Per-transaction decision history belongs to the drawer, never
 * to this summary. l10n returns catalogue keys in this test environment (catalogues not loaded),
 * so assertions target keys — matching SheetDetailsModal.spec.ts's convention.
 */

const known = (totals: Array<{ amountMinor: number; currency: string; minorUnit: number }>): StageApprovalAggregate => ({ known: true, totals })
const unknown: StageApprovalAggregate = { known: false, totals: [] }

function money(amountMinor: number, currency: string, minorUnit = 2) {
	return { amountMinor, currency, minorUnit }
}

function stages(overrides: {
	costCenter?: StageApprovalAggregate
	senior?: StageApprovalAggregate
	finance?: StageApprovalAggregate
}) {
	return [
		{ key: 'costCenter' as const, state: overrides.costCenter ?? unknown },
		{ key: 'senior' as const, state: overrides.senior ?? unknown },
		{ key: 'finance' as const, state: overrides.finance ?? unknown },
	]
}

describe('SheetApprovalsSummary', () => {
	it('renders one row per workflow stage with the canonical stage labels', () => {
		const wrapper = mount(SheetApprovalsSummary, { props: { stages: stages({}) } })
		expect(wrapper.find('[data-testid="sheet-approvals-stage-costCenter"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sheet-approvals-stage-senior"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="sheet-approvals-stage-finance"]').exists()).toBe(true)
		const text = wrapper.text()
		expect(text).toContain('reviews.decisionStage.costCenter')
		expect(text).toContain('reviews.decisionStage.senior')
		expect(text).toContain('reviews.decisionStage.finance')
	})

	it('renders per-currency approved totals separately', () => {
		const wrapper = mount(SheetApprovalsSummary, {
			props: { stages: stages({ costCenter: known([money(12_00, 'EUR'), money(8_00, 'USD')]) }) },
		})
		const totals = wrapper.findAll('[data-testid="sheet-approvals-stage-costCenter-total"]')
		expect(totals).toHaveLength(2)
		expect(totals[0]!.text()).toContain('12.00')
		expect(totals[1]!.text()).toContain('8.00')
		// Currency codes are direction-isolated inside the money row.
		expect(totals[0]!.find('bdi').exists()).toBe(true)
	})

	it('renders a known stage with no approvals as "nothing approved yet"', () => {
		const wrapper = mount(SheetApprovalsSummary, {
			props: { stages: stages({ senior: known([]) }) },
		})
		expect(wrapper.find('[data-testid="sheet-approvals-stage-senior-none"]').text())
			.toContain('reviews.approvals.noneYet')
		expect(wrapper.find('[data-testid="sheet-approvals-stage-senior-total"]').exists()).toBe(false)
	})

	it('renders an unprovable stage as honestly unavailable', () => {
		const wrapper = mount(SheetApprovalsSummary, {
			props: { stages: stages({ finance: unknown }) },
		})
		expect(wrapper.find('[data-testid="sheet-approvals-stage-finance-unavailable"]').text())
			.toContain('reviews.approvals.unavailable')
	})

	it('states the review-scope derivation instead of claiming a whole-sheet total', () => {
		const wrapper = mount(SheetApprovalsSummary, { props: { stages: stages({}) } })
		expect(wrapper.find('[data-testid="sheet-approvals-scope-note"]').text())
			.toContain('reviews.approvals.scopeNote')
	})
})
