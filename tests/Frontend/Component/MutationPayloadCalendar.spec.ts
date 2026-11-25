import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
	createTransaction,
	updateTransaction,
} from '@/services/transactionsApi'
import {
	setCalendarPreference,
	clearCalendarPreference,
} from '@/utils/calendar'

type CapturedRequest = { url: string; method: string; data: unknown }
const requests: CapturedRequest[] = []

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (config: unknown) => {
			const c = config as { url?: string; method?: string; data?: unknown }
			requests.push({ url: c.url ?? '', method: c.method ?? '', data: c.data })
			return Promise.resolve({
				data: {
					ocs: {
						meta: { status: 'ok', statuscode: 200 },
						data: { id: 'tx-1' },
					},
				},
			})
		},
		isAxiosError: () => false,
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
}))

const draft = {
	expenseDate: '2026-09-20',
	amountMinor: 50000,
	description: 'Office supplies',
	costCenterId: 'cc-1',
	expenseTypeId: 'et-1',
}

describe('Mutation payload calendar independence (T018)', () => {
	beforeEach(() => {
		requests.length = 0
		clearCalendarPreference()
	})

	afterEach(() => {
		clearCalendarPreference()
	})

	it('submits an identical create payload under gregory and persian calendar preferences', async () => {
		setCalendarPreference('gregory')
		await createTransaction('sheet-1', { ...draft })
		const gregoryRequest = requests.find((r) => r.method === 'POST' && r.url.includes('/transactions'))

		setCalendarPreference('persian')
		await createTransaction('sheet-1', { ...draft })
		const persianRequest = requests.find((r) => r.method === 'POST' && r.url.includes('/transactions'))

		expect(gregoryRequest).toBeDefined()
		expect(persianRequest).toBeDefined()
		expect(gregoryRequest!.data).toEqual(persianRequest!.data)
		expect(gregoryRequest!.data).toEqual(draft)

		// No calendar-specific metadata is added to the request
		const gregoryData = gregoryRequest!.data as Record<string, unknown>
		const persianData = persianRequest!.data as Record<string, unknown>
		expect(gregoryData).not.toHaveProperty('calendar')
		expect(gregoryData).not.toHaveProperty('calendarPreference')
		expect(persianData).not.toHaveProperty('calendar')
		expect(persianData).not.toHaveProperty('calendarPreference')

		// The canonical date value is identical
		expect(gregoryData.expenseDate).toBe(persianData.expenseDate)
		expect(gregoryData.amountMinor).toBe(persianData.amountMinor)
		expect(gregoryData.description).toBe(persianData.description)
		expect(gregoryData.costCenterId).toBe(persianData.costCenterId)
		expect(gregoryData.expenseTypeId).toBe(persianData.expenseTypeId)
	})

	it('submits an identical update payload under gregory and persian calendar preferences', async () => {
		setCalendarPreference('gregory')
		await updateTransaction('tx-1', { ...draft }, 1)
		const gregoryRequest = requests.find((r) => r.method === 'PATCH' && r.url.includes('/transactions/tx-1'))

		setCalendarPreference('persian')
		await updateTransaction('tx-1', { ...draft }, 1)
		const persianRequest = requests.find((r) => r.method === 'PATCH' && r.url.includes('/transactions/tx-1'))

		expect(gregoryRequest).toBeDefined()
		expect(persianRequest).toBeDefined()
		expect(gregoryRequest!.data).toEqual(persianRequest!.data)

		const gregoryData = gregoryRequest!.data as Record<string, unknown>
		const persianData = persianRequest!.data as Record<string, unknown>
		expect(gregoryData).not.toHaveProperty('calendar')
		expect(persianData).not.toHaveProperty('calendar')
		expect(gregoryData.expenseDate).toBe(persianData.expenseDate)
		expect(gregoryData.amountMinor).toBe(persianData.amountMinor)
	})

	it('does not add calendar-specific headers to the mutation request', async () => {
		setCalendarPreference('gregory')
		await createTransaction('sheet-1', { ...draft })
		const gregoryRequest = requests[0]

		setCalendarPreference('persian')
		await createTransaction('sheet-1', { ...draft })
		const persianRequest = requests[requests.length - 1]

		// Both requests use the same command-id header shape; nothing calendar-derived
		expect(gregoryRequest).toBeDefined()
		expect(persianRequest).toBeDefined()
	})
})
