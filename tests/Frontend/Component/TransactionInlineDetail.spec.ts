// Shared Inline Expanded Transaction detail surface — Vue Component Tests (UXC-V2-05 to UXC-V2-08)
//
// The former `TransactionDetailDrawer.vue` content assertions, mirrored onto the inline surface at
// the UXC-V2-05 migration and kept green through the drawer's UXC-V2-13 removal: read-state
// honesty, Transaction Details fields with their unavailable states, Evidence summary,
// stage-separated Previous Decisions in the compact v2 presentation, the adapter-gated action
// surface, no mutation controls, and stage neutrality. The l10n double renders catalogue keys
// verbatim, so assertions target keys.

import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import TransactionInlineDetail, {
	type TransactionInlineDetailStageConfig,
} from '@/components/review/TransactionInlineDetail.vue'
import { formatSheetDateTime } from '@/utils/date'
import type {
	AllowedActions,
	Decision,
	Evidence,
	TransactionDetail,
} from '@/types/api'

vi.mock('@nextcloud/l10n', () => ({
	t: (_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (match, key: string) => String(params?.[key] ?? match)),
	n: (_app: string, text: string) => text,
	getLanguage: () => 'en',
	isRTL: () => false,
	imagePath: (path: string) => `/apps/pettycash/${path}`
}))

vi.mock('@/services/evidenceApi', () => ({
	getEvidenceContent: vi.fn().mockRejectedValue(new Error('no content in this spec')),
}))

function decision(stage: Decision['stage'], kind: Decision['decision'], overrides: Partial<Decision> = {}): Decision {
	return {
		id: `decision-${stage.toLowerCase()}`,
		stage,
		decision: kind,
		explanation: 'Receipt matches the amount.',
		actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
		decidedAt: '2026-09-21T10:30:00Z',
		...overrides
	}
}

function evidence(overrides: Partial<Evidence> = {}): Evidence {
	return {
		id: 'ev-1',
		transactionId: 'tx-1',
		revisionId: 'rev-1',
		name: 'receipt.png',
		mediaType: 'image/png',
		sizeBytes: 2048,
		active: true,
		uploadedBy: 'user1',
		uploadedAt: '2026-09-20T10:05:00Z',
		...overrides
	}
}

function detail(overrides: Record<string, unknown> = {}): TransactionDetail {
	return {
		id: 'tx-1',
		sheetId: 'sheet-1',
		stage: 'COST_CENTER',
		status: 'AWAITING_COST_CENTER_REVIEW',
		finalOutcome: null,
		evidenceRequired: false,
		evidenceCount: 1,
		version: 3,
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
		allowedActions: { canApprove: true, canReject: true, canReturn: false },
		decisions: [decision('COST_CENTER', 'APPROVE')],
		evidence: [evidence()],
		...overrides
	} as unknown as TransactionDetail
}

const STAGE_ACTIONS: TransactionInlineDetailStageConfig = {
	actionsVisible: true,
	actions: ['REJECT', 'RETURN', 'APPROVE'],
}

function mountDetail(opts: {
	transaction?: TransactionDetail | null
	readState?: 'loading' | 'loaded' | 'forbidden' | 'notFound' | 'unavailable'
	sheet?: { title?: string | null; ownerDisplayName?: string | null; submittedAt?: string | null } | null
	stage?: TransactionInlineDetailStageConfig | null
	busy?: boolean
} = {}) {
	return mount(TransactionInlineDetail, {
		props: {
			// An explicit null is a real answer (the read failed) — only absence falls back to a fixture.
			transaction: opts.transaction !== undefined ? opts.transaction : detail(),
			readState: opts.readState ?? 'loaded',
			sheet: opts.sheet ?? null,
			stage: opts.stage ?? null,
			busy: opts.busy ?? false,
		},
		attachTo: document.body,
	})
}

afterEach(() => {
	document.body.innerHTML = ''
})

describe('Read-state honesty (UXC-V2-05)', () => {
	it('shows the loading state while the detail read is in flight', () => {
		const wrapper = mountDetail({ readState: 'loading', transaction: null })
		expect(wrapper.find('[data-testid="inline-loading"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('renders refusal, absence and failure as the different answers they are', () => {
		const forbidden = mountDetail({ readState: 'forbidden', transaction: null })
		expect(forbidden.find('[data-testid="inline-forbidden"]').exists()).toBe(true)
		forbidden.unmount()

		const notFound = mountDetail({ readState: 'notFound', transaction: null })
		expect(notFound.find('[data-testid="inline-not-found"]').exists()).toBe(true)
		notFound.unmount()

		const unavailable = mountDetail({ readState: 'unavailable', transaction: null })
		expect(unavailable.find('[data-testid="inline-unavailable"]').exists()).toBe(true)
		unavailable.unmount()
	})
})

describe('Transaction Details section (UXC-V2-05)', () => {
	it('renders the complementary detail fields from the payload', () => {
		const wrapper = mountDetail({ sheet: { title: 'Sheet Alpha', ownerDisplayName: 'Purchaser One', submittedAt: '2026-09-19T08:00:00Z' } })
		// First-view fields (amount, currency, date, type, cost center, transaction id) live on the
		// card above and are intentionally not repeated in the expanded detail (UXC-V2-FU-02).
		expect(wrapper.find('[data-testid="inline-field-sheet"]').text()).toContain('Sheet Alpha')
		expect(wrapper.find('[data-testid="inline-field-purchaser"]').text()).toContain('Purchaser One')
		expect(wrapper.find('[data-testid="inline-field-submitted"]').text()).toBe(formatSheetDateTime('2026-09-19T08:00:00Z'))
		wrapper.unmount()
	})

	it('renders the honest unavailable states when the sheet header proves nothing', () => {
		const wrapper = mountDetail()
		expect(wrapper.find('[data-testid="inline-field-sheet"]').text()).toContain('reviews.sheetQueue.sheetTitleUnavailable')
		expect(wrapper.find('[data-testid="inline-field-purchaser"]').text()).toBe('reviews.sheetQueue.purchaserUnavailable')
		expect(wrapper.find('[data-testid="inline-field-submitted"]').text()).toBe('reviews.sheetQueue.submittedUnavailable')
		wrapper.unmount()
	})

	it('keeps merchant, paid-on and payment method honest when out of the payload scope', () => {
		const wrapper = mountDetail()
		expect(wrapper.find('[data-testid="inline-field-merchant"]').text()).toBe('reviews.sheetQueue.unavailableField')
		expect(wrapper.find('[data-testid="inline-field-paid-on"]').text()).toBe('reviews.sheetQueue.unavailableField')
		expect(wrapper.find('[data-testid="inline-field-method"]').text()).toBe('reviews.sheetQueue.unavailableField')
		wrapper.unmount()
	})

	it('renders the description with the not-provided placeholder rather than fabricating one', () => {
		const wrapper = mountDetail({ transaction: detail({ activeRevision: { description: '   ' } }) })
		expect(wrapper.find('[data-testid="inline-description"]').text()).toBe('common.notProvided')
		wrapper.unmount()
	})
})

describe('Evidence section (UXC-V2-05/06)', () => {
	it('renders the transaction\'s returned attachments with their metadata', () => {
		const wrapper = mountDetail({ transaction: detail({
			evidence: [
				evidence(),
				evidence({ id: 'ev-2', name: 'invoice.pdf', mediaType: 'application/pdf', sizeBytes: 1048576 }),
			]
		}) })
		expect(wrapper.find('[data-testid="inline-evidence-count"]').text()).not.toBe('0')
		expect(wrapper.find('[data-testid="inline-attachment-ev-1"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="inline-attachment-ev-2"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="inline-attachment-ev-2"]').text()).toContain('invoice.pdf')
		wrapper.unmount()
	})

	it('shows the explicit no-attachments state instead of an empty list', () => {
		const wrapper = mountDetail({ transaction: detail({ evidence: [] }) })
		expect(wrapper.find('[data-testid="inline-no-attachments"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="inline-attachments"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('emits preview for the exact evidence object so the shared EvidencePreview layer opens', async () => {
		const item = evidence()
		const wrapper = mountDetail({ transaction: detail({ evidence: [item] }) })
		await wrapper.find('[data-testid="inline-attachment-ev-1"]').trigger('click')
		expect(wrapper.emitted('preview')).toEqual([[item]])
		wrapper.unmount()
	})

	it('offers no evidence mutation controls', () => {
		const wrapper = mountDetail()
		expect(wrapper.find('input[type="file"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="inline-upload"]').exists()).toBe(false)
		wrapper.unmount()
	})
})

describe('Previous Decisions section (UXC-V2-08)', () => {
	it('renders the three workflow stages separately in canonical order', () => {
		const wrapper = mountDetail({ transaction: detail({
			decisions: [
				decision('COST_CENTER', 'APPROVE'),
				decision('SENIOR', 'REJECT'),
				decision('FINANCE', 'RETURN'),
			]
		}) })
		const groups = wrapper.findAll('[data-testid^="inline-decisions-"]')
		expect(groups).toHaveLength(3)
		expect(groups[0]?.attributes('data-testid')).toBe('inline-decisions-cost-center')
		expect(groups[1]?.attributes('data-testid')).toBe('inline-decisions-senior')
		expect(groups[2]?.attributes('data-testid')).toBe('inline-decisions-finance')
		expect(groups[0]?.text()).toContain('reviews.decisionStage.costCenter')
		expect(groups[1]?.text()).toContain('reviews.decisionStage.senior')
		expect(groups[2]?.text()).toContain('reviews.decisionStage.finance')
		wrapper.unmount()
	})

	it('keeps each stage\'s decision in its own group, never collapsed', () => {
		const cc = decision('COST_CENTER', 'APPROVE')
		const senior = decision('SENIOR', 'REJECT', { id: 'decision-senior', actor: { userId: 's1', displayName: 'Senior One', role: 'SENIOR_MANAGER' } })
		const wrapper = mountDetail({ transaction: detail({ decisions: [cc, senior] }) })
		expect(wrapper.find('[data-testid="inline-decisions-cost-center"] [data-testid="decision-item-decision-cost_center"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="inline-decisions-senior"] [data-testid="decision-item-decision-senior"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="inline-decisions-finance"] .decision-item').exists()).toBe(false)
		wrapper.unmount()
	})

	it('shows actor, timestamp and comment for a recorded decision', () => {
		const wrapper = mountDetail({ transaction: detail({
			decisions: [decision('COST_CENTER', 'APPROVE', { explanation: 'Receipts match.' })]
		}) })
		const group = wrapper.find('[data-testid="inline-decisions-cost-center"]')
		expect(group.text()).toContain('Manager One')
		expect(group.text()).toContain('Receipts match.')
		// The decided instant renders through the shared date helper; assert the fixed date part.
		expect(group.text()).toContain('2026/09/21')
		wrapper.unmount()
	})

	it('renders "No decision yet" for a stage with no recorded decision', () => {
		const wrapper = mountDetail({ transaction: detail({ decisions: [] }) })
		for (const key of ['cost-center', 'senior', 'finance']) {
			const group = wrapper.find(`[data-testid="inline-decisions-${key}"]`)
			expect(group.find('[data-testid="prior-decision-history-empty"]').text()).toBe('reviews.decisionStage.noDecision')
		}
		wrapper.unmount()
	})

	it('presents the compact v2 hierarchy: decision leads, actor and time share one secondary line', () => {
		const wrapper = mountDetail({ transaction: detail({
			decisions: [decision('COST_CENTER', 'APPROVE', { explanation: 'Receipts match.' })]
		}) })
		const item = wrapper.find('[data-testid="decision-item-decision-cost_center"]')
		expect(item.classes()).toContain('decision-item--compact')
		// Decision first, on its own leading line.
		expect(item.find('[data-testid="decision-type"]').text()).toBe('reviews.decision.approve')
		// Actor and time on one combined secondary line.
		const actorLine = item.find('.decision-actor--compact')
		expect(actorLine.exists()).toBe(true)
		expect(actorLine.text()).toContain('Manager One')
		expect(actorLine.text()).toContain('2026/09/21')
		// The per-item stage chip is dropped in compact — the group heading already names the stage.
		expect(item.find('[data-testid="decision-stage"]').exists()).toBe(false)
		// Explanation present only because the fixture carries one.
		expect(item.find('.decision-explanation').exists()).toBe(true)
		wrapper.unmount()
	})

	it('omits the explanation element when the decision carries none', () => {
		const wrapper = mountDetail({ transaction: detail({
			decisions: [decision('COST_CENTER', 'APPROVE', { explanation: undefined })]
		}) })
		expect(wrapper.find('[data-testid="decision-item-decision-cost_center"] .decision-explanation').exists()).toBe(false)
		wrapper.unmount()
	})

	it('keeps Previous Decisions transaction-level: this transaction\'s own history, never the sheet Approvals aggregate (UXC-7-02)', () => {
		const wrapper = mountDetail({ transaction: detail({
			decisions: [decision('COST_CENTER', 'APPROVE', { explanation: 'Receipts match.' })]
		}) })
		const section = wrapper.find('[data-testid="inline-section-decisions"]')
		expect(section.exists()).toBe(true)
		expect(section.find('[data-testid="inline-decisions-cost-center"]').exists()).toBe(true)
		// It carries no sheet-level aggregate surface: no approvals summary, no stage totals —
		// the Approvals tab's sheet-level aggregate is a different component's contract.
		expect(section.find('[data-testid="sheet-approvals-summary"]').exists()).toBe(false)
		expect(section.findAll('[data-testid$="-total"]')).toHaveLength(0)
		expect(section.findAll('[data-testid$="-unavailable"]')).toHaveLength(0)
		wrapper.unmount()
	})
})

describe('Current Stage Actions (UXC-V2-05/07)', () => {
	it('renders the stage adapter\'s offered actions as the decide surface', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS })
		for (const kind of ['reject', 'return', 'approve']) {
			expect(wrapper.find(`[data-testid="inline-decide-${kind}"]`).exists()).toBe(true)
		}
		expect(wrapper.find('[data-testid="inline-decide-label"]').text()).toBe('reviews.quickReview.drawer.decideLabel')
		wrapper.unmount()
	})

	it('emits decide with the exact kind', async () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS })
		await wrapper.find('[data-testid="inline-decide-approve"]').trigger('click')
		expect(wrapper.emitted('decide')).toEqual([['APPROVE']])
		wrapper.unmount()
	})

	it('gates every action on the server\'s own capability grant, adding no rule of its own', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS, transaction: detail({ allowedActions: { canApprove: true } as AllowedActions }) })
		expect(wrapper.find('[data-testid="inline-decide-approve"]').attributes('disabled')).toBeUndefined()
		expect(wrapper.find('[data-testid="inline-decide-reject"]').attributes('disabled')).toBeDefined()
		expect(wrapper.find('[data-testid="inline-decide-return"]').attributes('disabled')).toBeDefined()
		wrapper.unmount()
	})

	it('blocks a second submission while one is in flight', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS, busy: true })
		for (const kind of ['approve', 'reject', 'return']) {
			expect(wrapper.find(`[data-testid="inline-decide-${kind}"]`).attributes('disabled')).toBeDefined()
		}
		wrapper.unmount()
	})

	it('renders no decide surface without a stage configuration', () => {
		const wrapper = mountDetail({ stage: null })
		expect(wrapper.find('[data-testid="inline-decide-approve"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('renders no decide surface when the stage config withholds actions', () => {
		const wrapper = mountDetail({ stage: { actionsVisible: false, actions: ['APPROVE'] } })
		expect(wrapper.find('[data-testid="inline-decide-approve"]').exists()).toBe(false)
		// The note tells the reader they can decide from here or from the card; on a read-only
		// completed sheet neither surface offers an action, so the note must be withheld too.
		expect(wrapper.find('[data-testid="inline-decide-note"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('honours the stage adapter\'s action label override', () => {
		const wrapper = mountDetail({ stage: { ...STAGE_ACTIONS, actionsLabel: 'Finance decision' } })
		expect(wrapper.find('[data-testid="inline-decide-label"]').text()).toBe('Finance decision')
		wrapper.unmount()
	})

	it('keeps the decide note about the card and the expanded row sharing one workflow', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS })
		expect(wrapper.find('[data-testid="inline-decide-note"]').text())
			.toBe('reviews.transactionDrawer.decideNote')
		wrapper.unmount()
	})
})

describe('Inline structure (UXC-V2-05)', () => {
	it('is not a dialog: no teleport, no dialog role, no focus trap of its own', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS })
		const root = wrapper.find('[data-testid="transaction-inline-detail"]')
		expect(root.element.closest('[data-testid="sheet-details-modal"]')).toBeNull()
		expect(root.element.tagName).toBe('DIV')
		expect(root.attributes('role')).toBeUndefined()
		expect(root.find('[data-testid="inline-close"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('orders the 390px flow details → evidence → history → actions', () => {
		const wrapper = mountDetail({ stage: STAGE_ACTIONS })
		const sections = [
			wrapper.find('[data-testid="inline-section-details"]').element,
			wrapper.find('[data-testid="inline-section-evidence"]').element,
			wrapper.find('[data-testid="inline-section-decisions"]').element,
			wrapper.find('[data-testid="inline-section-actions"]').element,
		]
		for (let i = 1; i < sections.length; i += 1) {
			expect(sections[i - 1]!.compareDocumentPosition(sections[i]!) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
		}
		wrapper.unmount()
	})

	it('keeps the sheet reference direction-safe inside bdi (UXC-V2-12)', () => {
		const wrapper = mountDetail()
		const sheetReference = wrapper.find('[data-testid="inline-field-sheet"] bdi')
		expect(sheetReference.element.tagName).toBe('BDI')
		expect(sheetReference.attributes('dir')).toBe('ltr')
		wrapper.unmount()
	})
})

describe('Stage neutrality (UXC-V2-05 exclusions)', () => {
	it('contains no hardcoded stage business logic in the shared inline surface', () => {
		const source = readFileSync('src/components/review/TransactionInlineDetail.vue', 'utf8')
		expect(source).not.toMatch(
			/SeniorSheetModal|FinanceSheetModal|CostCenterQueue|SeniorQueueView|ManagerTransactionState|SeniorManagerTransactionState|AWAITING_MANAGER_DECISION|AWAITING_SENIOR_DECISION|SENIOR_REJECTED|readOnly/
		)
	})
})
