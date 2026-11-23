// UI-PUR-001 / T018 — Calendar mutation payload negative test.
//
// Proves that the calendar preference (Gregorian vs Persian) never alters a mutation request
// payload: the same transaction form produces byte-identical request bodies under both
// calendar choices, and no calendar-specific metadata is transmitted.
//
// The mutation boundary is the actual `createTransaction` call through `transactionsApi`;
// this test does not merely assert that the date formatter ignores the calendar.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import {
	clearCalendarPreference,
	setCalendarPreference,
} from '@/utils/calendar'
import { useTransactionStore } from '@/stores/transactions'
import type { TransactionDraftWrite } from '@/types/api'

const createTransaction = vi.hoisted(() => vi.fn())

vi.mock('@/services/transactionsApi', () => ({
	createTransaction: createTransaction,
	getTransaction: vi.fn(),
	updateTransaction: vi.fn(),
	deleteTransaction: vi.fn(),
	submitTransactionDecision: vi.fn(),
	createCorrection: vi.fn(),
	resubmitCorrection: vi.fn(),
	listRevisions: vi.fn(),
	listAudit: vi.fn(),
}))

const FIXED_DRAFT: TransactionDraftWrite = {
	expenseDate: '2024-03-20',
	amountMinor: 150_000,
	description: 'Office supplies',
	costCenterId: 'cc-1',
	expenseTypeId: 'et-1',
}

function capturedPayload(): unknown {
	return (createTransaction.mock.calls[createTransaction.mock.calls.length - 1]?.[1] ??
		undefined) as unknown
}

describe('T018: calendar preference does not alter mutation payloads', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
		clearCalendarPreference()
		createTransaction.mockReset()
		createTransaction.mockResolvedValue({} as never)
	})

	it('sends an identical payload under Gregorian and Persian preferences', async () => {
		const store = useTransactionStore()

		// Gregorian preference
		setCalendarPreference('gregory')
		await store.createNewTransaction('sheet-1', { ...FIXED_DRAFT })
		const gregoryPayload = capturedPayload()

		// Persian preference — same fixed draft
		setCalendarPreference('persian')
		await store.createNewTransaction('sheet-1', { ...FIXED_DRAFT })
		const persianPayload = capturedPayload()

		expect(gregoryPayload).toEqual(persianPayload)
		expect(gregoryPayload).toMatchObject({
			expenseDate: '2024-03-20',
			amountMinor: 150_000,
			description: 'Office supplies',
			costCenterId: 'cc-1',
			expenseTypeId: 'et-1',
		})
	})

	it('transmits no calendar-specific metadata in the request body', async () => {
		const store = useTransactionStore()

		setCalendarPreference('gregory')
		await store.createNewTransaction('sheet-1', { ...FIXED_DRAFT })
		const gregory = JSON.stringify(capturedPayload())

		setCalendarPreference('persian')
		await store.createNewTransaction('sheet-1', { ...FIXED_DRAFT })
		const persian = JSON.stringify(capturedPayload())

		expect(gregory).toBe(persian)
		expect(gregory).not.toContain('calendar')
		expect(gregory).not.toContain('gregory')
		expect(gregory).not.toContain('persian')
		expect(gregory).not.toContain('jalali')
		expect(gregory).not.toContain('hijri')
	})
})
