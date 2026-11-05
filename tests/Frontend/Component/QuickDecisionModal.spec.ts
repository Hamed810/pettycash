// T027/T029 — the shared single-transaction decision confirmation.
//
// Both Batch 4 entry points (the quick-review card and the details drawer) open this one modal, so
// the rules it enforces cannot drift between them: the FR-033 explanation guard on Reject/Return,
// the submitting state that suppresses every escape route, one command identity per attempt
// (FR-085 first-write-wins), and its own words for a version conflict versus an authorization
// refusal. The modal owns no sheet-level mutation — there is no such unit — and posts exactly one
// transaction decision through the reviews store.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { nextTick } from 'vue'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import QuickDecisionModal from '@/components/review/QuickDecisionModal.vue'
import { useReviewsStore } from '@/stores/reviews'
import type { DecisionResult } from '@/types/api'

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

type ModalTransaction = {
	id: string
	title: string
	sheetReference: string
	amount: { amountMinor: number; currency: string; minorUnit: number } | null
	expenseDate: string | null
}

function modalTransaction(overrides: Partial<ModalTransaction> = {}): ModalTransaction {
	return {
		id: 'tx-1',
		title: 'Taxi to client site',
		sheetReference: 'sheet-1',
		amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		expenseDate: '2026-09-20',
		...overrides
	}
}

function decisionResult(): DecisionResult {
	return {
		decision: {
			id: 'd-1',
			stage: 'COST_CENTER',
			decision: 'APPROVE',
			actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
			decidedAt: '2026-09-21T10:30:00Z'
		},
		transaction: {
			id: 'tx-1',
			sheetId: 'sheet-1',
			stage: 'COST_CENTER',
			status: 'COST_CENTER_APPROVED',
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

describe('QuickDecisionModal (T027)', () => {
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

	function mountModal(props: Record<string, unknown> = {}) {
		return mount(QuickDecisionModal, {
			props: {
				open: true,
				decision: 'APPROVE',
				transaction: modalTransaction(),
				expectedVersion: 3,
				allowedActions: { canApprove: true, canReject: true, canReturn: true },
				...props
			},
			global: { plugins: [pinia] },
			attachTo: document.body
		})
	}

	it('renders the per-kind copy, the real amount and the transaction identity', () => {
		const wrapper = mountModal()

		expect(wrapper.find('[data-testid="decision-modal-title"]').text()).toBe('reviews.quickReview.decision.approveTitle')
		expect(wrapper.find('[data-testid="decision-modal-warning"]').text()).toContain('reviews.quickReview.decision.approveWarning')
		expect(wrapper.find('[data-testid="decision-modal-amount"]').text()).toContain('50,000')
		expect(wrapper.find('[data-testid="decision-modal-transaction-id"]').text()).toBe('tx-1')
		expect(wrapper.find('[data-testid="decision-confirm-btn"]').text()).toContain('reviews.quickReview.decision.approveConfirm')
	})

	it('gates the confirm control on an explicit server-granted capability', () => {
		const wrapper = mountModal({ allowedActions: { canApprove: false } })

		expect((wrapper.find('[data-testid="decision-confirm-btn"]').element as HTMLButtonElement).disabled).toBe(true)
	})

	it('posts an approval without requiring an explanation, carrying the expected version and a command id', async () => {
		recordDecision.mockResolvedValue(decisionResult())
		const wrapper = mountModal()

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'APPROVE',
			explanation: '',
			expectedVersion: 3
		}, { commandId: 'command-1' })
		expect(wrapper.emitted('decided')).toEqual([[decisionResult(), 'APPROVE', '']])
	})

	it('blocks a reject without a note, flags the field and posts nothing (FR-033)', async () => {
		const wrapper = mountModal({ decision: 'REJECT' })

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')

		expect(wrapper.find('[data-testid="decision-guard-error"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="decision-guard-error"]').text())
			.toContain('reviews.quickReview.decision.rejectGuard')
		expect(wrapper.find('[data-testid="decision-note-input"]').attributes('aria-invalid')).toBe('true')
		expect(recordDecision).not.toHaveBeenCalled()
	})

	it('posts a reject once the note is written and the guard is cleared', async () => {
		recordDecision.mockResolvedValue(decisionResult())
		const wrapper = mountModal({ decision: 'REJECT' })

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		expect(wrapper.find('[data-testid="decision-guard-error"]').exists()).toBe(true)

		await wrapper.find('[data-testid="decision-note-input"]').setValue('Amount does not match the receipt.')
		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(recordDecision).toHaveBeenCalledWith('tx-1', {
			decision: 'REJECT',
			explanation: 'Amount does not match the receipt.',
			expectedVersion: 3
		}, { commandId: 'command-1' })
		expect(wrapper.emitted('decided')).toEqual([[decisionResult(), 'REJECT', 'Amount does not match the receipt.']])
	})

	it('applies the same explanation rule to a return', async () => {
		const wrapper = mountModal({ decision: 'RETURN' })

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')

		expect(wrapper.find('[data-testid="decision-guard-error"]').text())
			.toContain('reviews.quickReview.decision.returnGuard')
		expect(recordDecision).not.toHaveBeenCalled()
	})

	it('keeps itself and the note intact while submitting, and suppresses every escape route', async () => {
		let resolveSubmission: (result: DecisionResult) => void = () => undefined
		recordDecision.mockImplementation(() => new Promise<DecisionResult>((resolve) => { resolveSubmission = resolve }))
		const wrapper = mountModal({ decision: 'REJECT' })
		await wrapper.find('[data-testid="decision-note-input"]').setValue('Wrong amount.')

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')

		expect(recordDecision).toHaveBeenCalledTimes(1)
		expect(wrapper.find('[data-testid="decision-submit-error"]').exists()).toBe(false)
		expect(wrapper.find('[data-testid="decision-guard-error"]').exists()).toBe(false)
		// The in-flight attempt locks the form: cancel, scrim and Escape all stop answering.
		expect((wrapper.find('[data-testid="decision-cancel-btn"]').element as HTMLButtonElement).disabled).toBe(true)
		await wrapper.find('[data-testid="decision-modal"]').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toBeUndefined()
		await wrapper.find('[data-testid="decision-modal-scrim"]').trigger('click')
		expect(wrapper.emitted('close')).toBeUndefined()

		resolveSubmission(decisionResult())
		await flushPromises()
		expect(wrapper.emitted('decided')).toHaveLength(1)
	})

	it('maps a version conflict to its own words and keeps the typed explanation for a retry', async () => {
		recordDecision.mockRejectedValue({ status: 409, body: { code: 'TRANSACTION_VERSION_CONFLICT' } })
		const wrapper = mountModal({ decision: 'REJECT' })
		await wrapper.find('[data-testid="decision-note-input"]').setValue('Wrong amount.')

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(wrapper.emitted('decided')).toBeUndefined()
		expect(wrapper.emitted('close')).toBeUndefined()
		const error = wrapper.find('[data-testid="decision-submit-error"]')
		expect(error.exists()).toBe(true)
		expect(error.text()).toContain('reviews.quickReview.decision.versionConflict')
		expect((wrapper.find('[data-testid="decision-note-input"]').element as HTMLTextAreaElement).value).toBe('Wrong amount.')
	})

	it('maps an authorization refusal to its own words instead of a retry hint', async () => {
		recordDecision.mockRejectedValue({ status: 403, body: { code: 'FORBIDDEN' } })
		const wrapper = mountModal({ decision: 'APPROVE' })

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(wrapper.find('[data-testid="decision-submit-error"]').text())
			.toContain('reviews.quickReview.decision.forbidden')
	})

	it('uses a fresh command identity per attempt, not per open', async () => {
		// An opaque failure (no message to show) renders the generic words; the retry gets a new id.
		recordDecision.mockRejectedValueOnce({ body: { code: 'STATE_CONFLICT' } })
		.mockResolvedValueOnce(decisionResult())
		const wrapper = mountModal({ decision: 'APPROVE' })

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()
		expect(wrapper.find('[data-testid="decision-submit-error"]').text())
			.toContain('reviews.quickReview.decision.failedGeneric')

		await wrapper.find('[data-testid="decision-confirm-btn"]').trigger('click')
		await flushPromises()

		expect(http.commandId).toHaveBeenCalledTimes(2)
		expect(wrapper.emitted('decided')).toHaveLength(1)
	})

	it('closes on Escape and cancel while idle', async () => {
		const wrapper = mountModal()

		await wrapper.find('[data-testid="decision-modal"]').trigger('keydown', { key: 'Escape' })
		expect(wrapper.emitted('close')).toHaveLength(1)

		await wrapper.find('[data-testid="decision-cancel-btn"]').trigger('click')
		expect(wrapper.emitted('close')).toHaveLength(2)
	})

	it('renders nothing while closed', () => {
		const wrapper = mountModal({ open: false })

		expect(wrapper.find('[data-testid="decision-modal-layer"]').exists()).toBe(false)
	})

	it('traps initial focus in the modal and restores it to the opener on close (T036)', async () => {
		const trigger = document.createElement('button')
		trigger.type = 'button'
		trigger.textContent = 'Open decision'
		document.body.appendChild(trigger)
		trigger.focus()

		const wrapper = mountModal()
		await nextTick()
		// The dialog receives the focus it traps; the trigger is not still focused.
		expect(document.activeElement).toBe(wrapper.find('[data-testid="decision-modal"]').element)
		expect(document.activeElement).not.toBe(trigger)

		await wrapper.setProps({ open: false })
		await nextTick()
		expect(document.activeElement).toBe(trigger)

		trigger.remove()
	})
})
