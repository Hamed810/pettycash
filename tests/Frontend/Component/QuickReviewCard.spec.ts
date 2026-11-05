// QuickReviewCard (T024): the compact per-transaction card used inside an expanded sheet.
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import QuickReviewCard from '@/components/review/QuickReviewCard.vue'
import { formatMoney } from '@/utils/money'
import type { Decision, ManagerTransactionState, TransactionSummary } from '@/types/api'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (match, key: string) => String(params?.[key] ?? match)),
	n: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
	imagePath: (path: string) => `/apps/pettycash/${path}`
}))

function transaction(overrides: Record<string, unknown> = {}): TransactionSummary {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 1,
		version: 0,
		activeRevision: {
			id: 'rev-1',
			number: 1,
			expenseDate: '2026-09-20',
			amount: { amountMinor: 123456, currency: 'USD', minorUnit: 2 },
			description: 'Taxi to client site',
			costCenter: { id: 'cc-1', code: 'CC-42', name: 'Operations' },
			expenseType: { id: 'et-1', name: 'Travel' },
			createdBy: 'user1',
			createdAt: '2026-09-20T10:00:00Z',
			active: true
		},
		...overrides
	} as TransactionSummary
}

function decision(overrides: Partial<Decision> = {}): Decision {
	return {
		id: 'decision-1',
		stage: 'COST_CENTER',
		decision: 'APPROVE',
		explanation: 'Receipt matches the amount.',
		actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-09-21T10:30:00Z',
		...overrides
	}
}

function mountCard(
	managerState: ManagerTransactionState = 'AWAITING_MANAGER_DECISION',
	managerDecision: Decision | null = null,
	overrides: Record<string, unknown> = {}
) {
	return mount(QuickReviewCard, {
		props: {
			transaction: transaction(overrides),
			managerState,
			managerDecision
		}
	})
}

describe('QuickReviewCard', () => {
	it('renders the connected description, reference, cost centre and currency-correct amount', () => {
		const wrapper = mountCard()

		expect(wrapper.find('[data-testid="quick-review-description"]').text()).toBe('Taxi to client site')
		expect(wrapper.find('[data-testid="quick-review-id"]').text()).toBe('tx-1')
		expect(wrapper.find('[data-testid="quick-review-cost-center"]').text()).toBe('CC-42')
		// The payload's own minorUnit and currency drive the figure, not a fixed divisor or symbol.
		expect(wrapper.find('[data-testid="quick-review-amount"]').text()).toBe(formatMoney({
			amountMinor: 123456,
			currency: 'USD',
			minorUnit: 2
		}))
		expect(wrapper.find('[data-testid="quick-review-amount"] bdi').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-id"]').element.tagName).toBe('BDI')
		expect(wrapper.find('[data-testid="quick-review-meta-unavailable"]').text())
			.toContain('reviews.quickReview.card.metaUnavailable')
	})

	it('shows the awaiting state without inventing a decision note', () => {
		const wrapper = mountCard()

		expect(wrapper.find('[data-testid="quick-review-status"]').text()).toContain('reviews.quickReview.card.awaiting')
		expect(wrapper.find('[data-testid="quick-review-decision-note"]').exists()).toBe(false)
		expect(wrapper.find('.quick-review-card--decided').exists()).toBe(false)
	})

	it('shows an approved decision with its real explanation and metadata', () => {
		const wrapper = mountCard('DECIDED_BY_MANAGER', decision())

		expect(wrapper.find('[data-testid="quick-review-status"]').text()).toContain('reviews.quickReview.card.approvedByYou')
		const note = wrapper.find('[data-testid="quick-review-decision-note"]')
		expect(note.exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-decision-explanation"]').text())
			.toBe('Receipt matches the amount.')
		expect(wrapper.find('[data-testid="quick-review-decision-meta"]').text()).toContain('reviews.quickReview.card.decidedMeta')
		expect(wrapper.find('.quick-review-card--decided').exists()).toBe(true)
	})

	it('shows a rejected decision as rejected rather than approved', () => {
		const wrapper = mountCard('DECIDED_BY_MANAGER', decision({
			decision: 'REJECT',
			explanation: 'The amount exceeds the approved limit.'
		}))

		expect(wrapper.find('[data-testid="quick-review-status"]').text()).toContain('reviews.quickReview.card.rejectedByYou')
		expect(wrapper.find('[data-testid="quick-review-status"]').text()).not.toContain('reviews.quickReview.card.approvedByYou')
		expect(wrapper.find('.quick-review-card--rejected').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-decision-explanation"]').text())
			.toBe('The amount exceeds the approved limit.')
	})

	it('shows a returned transaction as returned for correction', () => {
		const wrapper = mountCard('RETURNED_FOR_CORRECTION', decision({
			decision: 'RETURN',
			explanation: 'Attach the missing receipt.'
		}))

		expect(wrapper.find('[data-testid="quick-review-status"]').text()).toContain('reviews.quickReview.card.returned')
		expect(wrapper.find('.quick-review-card--returned').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-decision-explanation"]').text())
			.toBe('Attach the missing receipt.')
	})

	it('states that decision details are unavailable instead of guessing them', () => {
		const wrapper = mountCard('DECIDED_BY_MANAGER', null)

		expect(wrapper.find('[data-testid="quick-review-status"]').text()).toContain('reviews.quickReview.card.decidedByYou')
		expect(wrapper.find('[data-testid="quick-review-decision-note"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-decision-meta"]').text())
			.toContain('reviews.quickReview.card.decisionUnavailable')
		expect(wrapper.find('[data-testid="quick-review-decision-explanation"]').exists()).toBe(false)
	})

	it('falls back honestly when description, cost centre or amount are missing', () => {
		const wrapper = mountCard('AWAITING_MANAGER_DECISION', null, {
			activeRevision: {
				id: 'rev-2',
				number: 2,
				expenseDate: '2026-09-20',
				amount: null,
				description: '   ',
				costCenter: { id: 'cc-2', name: '' },
				expenseType: { id: 'et-1', name: 'Travel' },
				createdBy: 'user1',
				createdAt: '2026-09-20T10:00:00Z',
				active: true
			}
		})

		expect(wrapper.find('[data-testid="quick-review-description"]').text()).toBe('reviews.quickReview.card.noDescription')
		expect(wrapper.find('[data-testid="quick-review-cost-center"]').text()).toBe('reviews.quickReview.card.unavailable')
		expect(wrapper.find('[data-testid="quick-review-amount"]').text()).toBe('')
	})

	it('emits openReview with the transaction id from its review action', async () => {
		const wrapper = mountCard()

		await wrapper.find('[data-testid="review-transaction-tx-1"]').trigger('click')

		expect(wrapper.emitted('openReview')).toEqual([['tx-1']])
	})
})

// T033 — the evidence tiles and the transaction-level action bar. The tiles are type-art
// affordances into the preview overlay (never fetched image blobs), capped at two plus a `+N more`
// tile, and the decision buttons are gated on the server's explicit capability grant (T026) with
// `busy` blocking a second submission while one is in flight.
describe('QuickReviewCard evidence tiles and decision bar (T033)', () => {
	function evidenceItem(id: string, name: string): Record<string, unknown> {
		return {
			id, transactionId: 'tx-1', revisionId: 'rev-1', name,
			mediaType: name.endsWith('.pdf') ? 'application/pdf' : 'image/png',
			sizeBytes: 2048, active: true, uploadedBy: 'user1', uploadedAt: '2026-09-20T10:00:00Z'
		}
	}

	function mountWithEvidence(evidence: Record<string, unknown>[], extra: Record<string, unknown> = {}) {
		return mount(QuickReviewCard, {
			props: {
				transaction: transaction(),
				managerState: 'AWAITING_MANAGER_DECISION',
				managerDecision: null,
				evidence: evidence as never,
				...extra
			}
		})
	}

	it('renders at most two thumbnail tiles plus a `+N more` tile from the returned evidence', () => {
		const items = [evidenceItem('ev-1', 'receipt.png'), evidenceItem('ev-2', 'invoice.png'), evidenceItem('ev-3', 'total.png')]
		const wrapper = mountWithEvidence(items)

		const row = wrapper.find('[data-testid="quick-review-attachments"]')
		expect(row.exists()).toBe(true)
		expect(row.text()).toContain('reviews.quickReview.card.attachments')
		expect(wrapper.find('[data-testid="quick-review-thumb-ev-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-thumb-ev-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-thumb-ev-3"]').exists()).toBe(false)
		expect(wrapper.find(`[data-testid="quick-review-thumb-more-tx-1"]`).exists()).toBe(true)
		expect(wrapper.find(`[data-testid="quick-review-thumb-more-tx-1"]`).attributes('aria-label'))
			.toBe('reviews.quickReview.card.moreNamed')
	})

	it('opens the preview at the tile document and the more tile at the first attachment', async () => {
		const items = [evidenceItem('ev-1', 'receipt.png'), evidenceItem('ev-2', 'invoice.png'), evidenceItem('ev-3', 'total.png')]
		const wrapper = mountWithEvidence(items)

		await wrapper.find('[data-testid="quick-review-thumb-ev-2"]').trigger('click')
		expect(wrapper.emitted('openPreview')).toEqual([['ev-2']])

		await wrapper.find('[data-testid="quick-review-thumb-more-tx-1"]').trigger('click')
		expect(wrapper.emitted('openPreview')).toEqual([['ev-2'], ['ev-1']])
	})

	it('renders no attachments row when the detail read returned none', () => {
		const wrapper = mountWithEvidence([])

		expect(wrapper.find('[data-testid="quick-review-attachments"]').exists()).toBe(false)
	})

	it('gates every decision button on an explicit server-granted capability', () => {
		const wrapper = mountWithEvidence([], {
			allowedActions: { canApprove: true, canReject: false }
		})

		expect((wrapper.find('[data-testid="quick-review-decide-approve"]').element as HTMLButtonElement).disabled).toBe(false)
		expect((wrapper.find('[data-testid="quick-review-decide-reject"]').element as HTMLButtonElement).disabled).toBe(true)
		// Absence (no key at all) is a refusal too.
		expect((wrapper.find('[data-testid="quick-review-decide-return"]').element as HTMLButtonElement).disabled).toBe(true)
	})

	it('blocks a second decision while one is being submitted and emits the chosen kind', async () => {
		const wrapper = mountWithEvidence([], { allowedActions: { canApprove: true, canReject: true, canReturn: true }, busy: true })

		expect((wrapper.find('[data-testid="quick-review-decide-approve"]').element as HTMLButtonElement).disabled).toBe(true)
		expect((wrapper.find('[data-testid="quick-review-decide-reject"]').element as HTMLButtonElement).disabled).toBe(true)

		const free = mountWithEvidence([], { allowedActions: { canApprove: true, canReject: true, canReturn: true } })
		await free.find('[data-testid="quick-review-decide-return"]').trigger('click')
		expect(free.emitted('decide')).toEqual([['RETURN']])
	})

	it('shows the attachments on a decided card with no decision actions', () => {
		const items = [evidenceItem('ev-1', 'receipt.png')]
		const wrapper = mount(QuickReviewCard, {
			props: {
				transaction: transaction(),
				managerState: 'DECIDED_BY_MANAGER',
				managerDecision: decision(),
				evidence: items as never
			}
		})

		expect(wrapper.find('[data-testid="quick-review-attachments"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="quick-review-decide-approve"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="quick-review-decide-reject"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="quick-review-full-details"]').exists()).toBe(false)
	})
})
