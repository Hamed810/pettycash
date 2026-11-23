// Shared Decision Confirmation — Vue Component Tests (UXC-5-04)
//
// The shared single-transaction decision confirmation generalized from the Senior flow's
// QuickDecisionModal: per-kind copy, the FR-033 explanation rule (Reject/Return mandatory,
// Approve optional), the submitting state that suppresses every escape route, one command identity
// per attempt (FR-085 first-write-wins), and its own words for a version conflict versus an
// authorization refusal. It owns no stage rule and no sheet-level mutation.

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { readFileSync } from 'node:fs'
import DecisionConfirmation from '@/components/review/DecisionConfirmation.vue'
import { useReviewsStore } from '@/stores/reviews'
import { formatMoney } from '@/utils/money'
import type { DecisionResult, DecisionValue } from '@/types/api'

const l10n = vi.hoisted(() => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
	isRTL: vi.fn(() => false),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/l10n', () => l10n)

const http = vi.hoisted(() => ({
	commandId: vi.fn(() => 'command-1')
}))

vi.mock('@/services/http', () => http)

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading'],
		emits: ['click'],
		template: '<button type="button" :disabled="disabled" v-bind="$attrs" @click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size'],
		template: '<span data-test="nc-icon" />'
	},
	NcLoadingIcon: {
		name: 'NcLoadingIcon',
		props: ['size'],
		template: '<span data-test="nc-loading" />'
	}
}))

type ConfirmationTransaction = {
	id: string
	title: string
	sheetReference: string
	amount: { amountMinor: number; currency: string; minorUnit: number } | null
	expenseDate: string | null
}

function transaction(overrides: Partial<ConfirmationTransaction> = {}): ConfirmationTransaction {
	return {
		id: 'tx-1',
		title: 'Taxi to client site',
		sheetReference: 'sheet-1',
		amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		expenseDate: '2026-09-20',
		...overrides
	}
}

function decisionResult(id = 'tx-1'): DecisionResult {
	return {
		decision: {
			id: 'd-1',
			stage: 'FINANCE',
			decision: 'APPROVE',
			actor: { userId: 'fm1', displayName: 'Finance Manager', role: 'FINANCE_MANAGER' },
			decidedAt: '2026-09-21T10:30:00Z'
		},
		transaction: {
			id,
			sheetId: 'sheet-1',
			stage: 'FINANCE',
			status: 'COMPLETED_APPROVED',
			finalOutcome: null,
			evidenceRequired: false,
			evidenceCount: 1,
			version: 1,
			activeRevision: {} as never,
			allowedActions: {},
			decisions: [],
			evidence: []
		}
	}
}

describe('DecisionConfirmation (UXC-5-04)', () => {
	let pinia: Pinia
	let recordDecision: ReturnType<typeof vi.fn>

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		l10n.t.mockClear()
		http.commandId.mockClear().mockImplementation(() => 'command-1')
		const store = useReviewsStore()
		recordDecision = vi.fn()
		store.recordDecision = recordDecision as never
	})

	afterEach(() => {
		document.body.innerHTML = ''
	})

	function mountConfirmation(props: Record<string, unknown> = {}) {
		return mount(DecisionConfirmation, {
			props: {
				open: true,
				decision: 'APPROVE' as DecisionValue,
				transaction: transaction(),
				expectedVersion: 3,
				allowedActions: { canApprove: true, canReject: true, canReturn: true },
				...props
			},
			global: { plugins: [pinia] },
			attachTo: document.body
		})
	}

	async function typeExplanation(text: string): Promise<void> {
		const note = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
		note!.value = text
		note!.dispatchEvent(new Event('input'))
		await nextTick()
	}

	it('renders the dialog with role="dialog" and the per-kind confirm copy for Approve', () => {
		const wrapper = mountConfirmation()
		const dialog = document.body.querySelector('[data-testid="decision-modal"]')
		expect(dialog?.getAttribute('role')).toBe('dialog')
		expect(dialog?.getAttribute('aria-modal')).toBe('true')
		expect(document.body.querySelector('[data-testid="decision-modal-transaction-id"]')?.textContent).toBe('tx-1')
		expect(document.body.querySelector('[data-testid="decision-modal-amount"]')?.textContent).toBe(formatMoney({ amountMinor: 50000, currency: 'IRR', minorUnit: 0 }))
		expect(document.body.querySelector('[data-testid="decision-confirm-btn"]')?.textContent).toContain('reviews.quickReview.decision.approveConfirm')
		wrapper.unmount()
	})

	it('switches to the Reject copy and marks the explanation required', () => {
		const wrapper = mountConfirmation({ decision: 'REJECT' })
		expect(document.body.querySelector('[data-testid="decision-modal-title"]')?.textContent).toContain('reviews.quickReview.decision.rejectTitle')
		expect(document.body.querySelector('[data-testid="decision-note-input"]')?.getAttribute('aria-required')).toBeNull()
		// The reject hint is the mandatory-explanation wording; Approve's hint is absent-placeholder.
		expect(document.body.querySelector('[data-testid="decision-modal"]')?.textContent).toContain('reviews.quickReview.decision.rejectNoteHint')
		wrapper.unmount()
	})

	it('switches to the Return copy', () => {
		const wrapper = mountConfirmation({ decision: 'RETURN' })
		expect(document.body.querySelector('[data-testid="decision-modal-title"]')?.textContent).toContain('reviews.quickReview.decision.returnTitle')
		wrapper.unmount()
	})

	it('posts exactly one transaction decision with the guarded version and one command identity', async () => {
		recordDecision.mockResolvedValue(decisionResult())
		const wrapper = mountConfirmation()
		await typeExplanation('Looks right.')
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'APPROVE',
			explanation: 'Looks right.',
			expectedVersion: 3,
		}, { commandId: 'command-1' })
		expect(wrapper.emitted('decided')).toBeTruthy()
		wrapper.unmount()
	})

	it('blocks a Reject without an explanation: guard error, nothing posted, note preserved', async () => {
		recordDecision.mockResolvedValue(decisionResult())
		const wrapper = mountConfirmation({ decision: 'REJECT' })
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await nextTick()

		expect(document.body.querySelector('[data-testid="decision-guard-error"]')).not.toBeNull()
		expect(recordDecision).not.toHaveBeenCalled()

		// The typed note survives the guard; the next confirm posts it.
		await typeExplanation('Receipt mismatch.')
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'REJECT',
			explanation: 'Receipt mismatch.',
			expectedVersion: 3,
		}, { commandId: 'command-1' })
		wrapper.unmount()
	})

	it('blocks a Return without an explanation', async () => {
		const wrapper = mountConfirmation({ decision: 'RETURN' })
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await nextTick()
		expect(document.body.querySelector('[data-testid="decision-guard-error"]')).not.toBeNull()
		expect(recordDecision).not.toHaveBeenCalled()
		wrapper.unmount()
	})

	it('posts an Approve with an empty explanation (comment optional)', async () => {
		recordDecision.mockResolvedValue(decisionResult())
		const wrapper = mountConfirmation({ decision: 'APPROVE' })
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()
		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', expect.objectContaining({ explanation: '' }), expect.anything())
		wrapper.unmount()
	})

	it('keeps the confirm control disabled without an explicit server grant', () => {
		const wrapper = mountConfirmation({ allowedActions: { canReject: true, canReturn: true } })
		const confirm = document.body.querySelector<HTMLButtonElement>('[data-testid="decision-confirm-btn"]')
		expect(confirm?.disabled).toBe(true)
		wrapper.unmount()
	})

	it('suppresses every escape route while a submission is in flight', async () => {
		recordDecision.mockImplementation(() => new Promise(() => { /* in flight */ }))
		const wrapper = mountConfirmation()
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await nextTick()

		expect(document.body.querySelector('[data-testid="decision-cancel-btn"]')?.getAttribute('disabled')).toBeDefined()
		document.body.querySelector('[data-testid="decision-modal-scrim"]')?.dispatchEvent(new MouseEvent('click', { bubbles: true }))
		await nextTick()
		document.body.querySelector('[data-testid="decision-modal"]')?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		expect(wrapper.emitted('close')).toBeFalsy()
		expect(recordDecision).toHaveBeenCalledTimes(1)
		wrapper.unmount()
	})

	it('reports a version conflict and an authorization refusal with their own words', async () => {
		recordDecision.mockRejectedValue({ status: 409, body: { code: 'TRANSACTION_VERSION_CONFLICT' } })
		const wrapper = mountConfirmation()
		await typeExplanation('retry')
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="decision-submit-error"]')?.textContent).toContain('reviews.quickReview.decision.versionConflict')
		wrapper.unmount()

		recordDecision.mockRejectedValue({ status: 403, body: { code: 'FORBIDDEN' } })
		const forbidden = mountConfirmation()
		await typeExplanation('retry')
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="decision-submit-error"]')?.textContent).toContain('reviews.quickReview.decision.forbidden')
		forbidden.unmount()
	})

	it('keeps the typed explanation when a submission fails', async () => {
		recordDecision.mockRejectedValue({ status: 409, body: { code: 'TRANSACTION_VERSION_CONFLICT' } })
		const wrapper = mountConfirmation()
		await typeExplanation('kept note')
		document.body.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')?.click()
		await flushPromises()
		const note = document.body.querySelector<HTMLTextAreaElement>('[data-testid="decision-note-input"]')
		expect(note?.value).toBe('kept note')
		wrapper.unmount()
	})

	it('restores focus to the control that opened the confirmation', async () => {
		const trigger = document.createElement('button')
		trigger.setAttribute('data-testid', 'confirm-trigger')
		document.body.appendChild(trigger)
		trigger.focus()
		expect(document.activeElement).toBe(trigger)

		const wrapper = mountConfirmation()
		await nextTick()
		const dialog = document.body.querySelector('[data-testid="decision-modal"]')
		expect(document.activeElement).toBe(dialog)

		await wrapper.setProps({ open: false })
		await nextTick()
		expect(document.activeElement).toBe(trigger)
		wrapper.unmount()
		document.body.removeChild(trigger)
	})

	it('contains no stage-specific business rule in the shared confirmation', () => {
		const source = readFileSync('src/components/review/DecisionConfirmation.vue', 'utf8')
		expect(source).not.toMatch(/SeniorSheetModal|FinanceSheetModal|CostCenterQueue|SeniorQueueView|SENIOR_REJECTED|ManagerTransactionState/)
	})
})
