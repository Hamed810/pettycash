import { describe, it, expect, vi, beforeEach } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'

// The store's workflow boundary is the existing charge client: mock it so tests assert the
// store's state machine and not the wire. `parseMoneyInput` is intentionally NOT mocked — it is
// part of the workflow and its real behaviour must be exercised.
vi.mock('@/services/accountsApi', () => ({
	chargeAccount: vi.fn()
}))

import { chargeAccount } from '@/services/accountsApi'
import { useFinanceStore } from '@/stores/finance'
import type { AccountMutationResult, HolderAccountSummary } from '@/types/api'
import { ApiError } from '@/types/api'
import { parseMoneyInput } from '@/utils/moneyInput'

const chargeAccountMock = vi.mocked(chargeAccount)

function account(overrides: Partial<HolderAccountSummary> = {}): HolderAccountSummary {
	return {
		id: 'acc-1',
		userId: 'alice',
		currency: 'IRR',
		minorUnit: 2,
		balance: { amountMinor: 150000, currency: 'IRR', minorUnit: 2 },
		active: true,
		version: 3,
		movementCount: 2,
		lastMovementAt: '2026-11-15T10:00:00+00:00',
		...overrides
	}
}

function chargeResult(overrides: Partial<AccountMutationResult> = {}): AccountMutationResult {
	return {
		account: {
			...account(),
			balance: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 },
			version: 4
		},
		movement: {
			id: 'mv-1',
			type: 'CHARGE',
			amount: { amountMinor: 500000, currency: 'IRR', minorUnit: 2 },
			balanceAfter: { amountMinor: 650000, currency: 'IRR', minorUnit: 2 },
			description: 'Recharge',
			occurredAt: '2026-11-16T10:00:00+00:00',
			sourceRevisionId: null
		},
		...overrides
	}
}

beforeEach(() => {
	setActivePinia(createPinia())
	chargeAccountMock.mockReset()
})

describe('draft amount validation', () => {
	it('accepts a valid positive amount and enables confirmation', () => {
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('10.25')
		expect(store.rechargeAmountMinor).toBe(1025)
		expect(store.rechargeValidationReason).toBeNull()
		expect(store.canConfirm).toBe(true)
	})

	it('rejects a non-positive amount and blocks confirmation', () => {
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('0')
		expect(store.rechargeAmountMinor).toBeNull()
		expect(store.rechargeValidationReason).toBe('non_positive')
		expect(store.canConfirm).toBe(false)
	})

	it('rejects an invalid amount and blocks confirmation', () => {
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('abc')
		expect(store.rechargeAmountMinor).toBeNull()
		expect(store.rechargeValidationReason).toBe('malformed')
		expect(store.canConfirm).toBe(false)
	})

	it('rejects excess precision instead of rounding', () => {
		const store = useFinanceStore()
		store.startRecharge(account({ minorUnit: 2 }), 'Alice')
		store.setRechargeAmount('10.251')
		expect(parseMoneyInput('10.251', 2)).toEqual({ ok: false, reason: 'excess_precision' })
		expect(store.rechargeAmountMinor).toBeNull()
		expect(store.canConfirm).toBe(false)
	})
})

describe('review contains authoritative account context', () => {
	it('populates the review projection from the account payload', () => {
		const store = useFinanceStore()
		const acc = account()
		store.startRecharge(acc, 'Alice')
		expect(store.rechargeAccountId).toBe('acc-1')
		expect(store.rechargeHolderName).toBe('Alice')
		expect(store.rechargeCurrency).toBe('IRR')
		expect(store.rechargeMinorUnit).toBe(2)
		expect(store.rechargeBalance).toEqual(acc.balance)
		expect(store.rechargeExpectedVersion).toBe(3)
	})

	it('does not derive any financial value client-side', () => {
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		// balanceAfter comes only from the account payload, never from amount + balance.
		expect(store.rechargeBalance?.amountMinor).toBe(150000)
	})
})

describe('no request before explicit confirmation', () => {
	it('does not call chargeAccount on amount entry or review entry', () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		expect(chargeAccountMock).not.toHaveBeenCalled()
		expect(store.rechargeStatus).toBe('review')
	})
})

describe('confirmation and submission', () => {
	it('confirm calls chargeAccount exactly once with expectedVersion', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)
		const call = chargeAccountMock.mock.calls[0]!
		const [accountId, data, options] = call
		expect(accountId).toBe('acc-1')
		expect(data.amountMinor).toBe(500000)
		expect(data.expectedVersion).toBe(3)
		expect(options!.expectedVersion).toBe(3)
		expect(options!.commandId).toBeDefined()
	})

	it('success uses server-returned state, not a client-side sum', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		expect(store.rechargeStatus).toBe('success')
		// Balance comes from the server response: 650000, never 150000 + 500000 locally.
		expect(store.rechargeBalance?.amountMinor).toBe(650000)
		expect(store.rechargeExpectedVersion).toBe(4)
	})

	it('failed request does not pretend success', async () => {
		chargeAccountMock.mockRejectedValue(new Error('pettycash.error.forbidden'))
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		expect(store.rechargeStatus).toBe('error')
		expect(store.rechargeError).toBe('pettycash.error.forbidden')
		expect(store.rechargeBalance?.amountMinor).toBe(150000)
	})

	it('version conflict does not overwrite authoritative data', async () => {
		chargeAccountMock.mockRejectedValue(new Error('pettycash.error.conflict'))
		const store = useFinanceStore()
		store.startRecharge(account({ version: 3 }), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		expect(store.rechargeStatus).toBe('error')
		expect(store.rechargeExpectedVersion).toBe(3)
		expect(store.rechargeBalance?.amountMinor).toBe(150000)
	})
})

describe('idempotency key behavior', () => {
	it('uses a stable commandId for retry of the same logical submission', async () => {
		// First call: unknown (network) outcome — status becomes 'unknown', command id retained.
		chargeAccountMock.mockRejectedValueOnce(
			new ApiError(0, { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' })
		)

		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		expect(store.rechargeStatus).toBe('unknown')
		const firstCommandId = chargeAccountMock.mock.calls[0]![2]?.commandId
		expect(firstCommandId).toBeDefined()

		// Retry reuses the same command id.
		chargeAccountMock.mockResolvedValue(chargeResult())
		await store.retryRecharge()
		const secondCommandId = chargeAccountMock.mock.calls[1]![2]?.commandId
		expect(secondCommandId).toBe(firstCommandId)
		expect(chargeAccountMock).toHaveBeenCalledTimes(2)
	})

	it('a new intended recharge gets a new commandId', async () => {
		chargeAccountMock.mockResolvedValue(chargeResult())
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		const firstKey = chargeAccountMock.mock.calls[0]![2]?.commandId

		store.newIntendedRecharge()
		store.setRechargeAmount('3000.00')
		store.confirmRecharge()
		await store.submitRecharge()
		const secondKey = chargeAccountMock.mock.calls[1]![2]?.commandId

		expect(secondKey).toBeDefined()
		expect(secondKey).not.toBe(firstKey)
	})
})

describe('duplicate submit while in flight', () => {
	it('does not create a second logical charge while submitting', async () => {
		let resolveCharge!: (value: AccountMutationResult) => void
		const pending = new Promise<AccountMutationResult>((res) => {
			resolveCharge = res
		})
		chargeAccountMock.mockReturnValue(pending)

		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		const first = store.submitRecharge()

		// A second submit while the first is in flight must not issue another request.
		const second = store.submitRecharge()
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)

		resolveCharge(chargeResult())
		await first
		await second
		expect(chargeAccountMock).toHaveBeenCalledTimes(1)
	})
})

describe('account switching clears recharge state', () => {
	it('switching account clears the recharge draft', () => {
		const store = useFinanceStore()
		store.startRecharge(account(), 'Alice')
		store.setRechargeAmount('5000.00')
		store.confirmRecharge()
		expect(store.rechargeAccountId).toBe('acc-1')
		expect(store.rechargeStatus).toBe('review')

		store.clearRechargeOnAccountSwitch()
		expect(store.rechargeAccountId).toBeNull()
		expect(store.rechargeAmountText).toBe('')
		expect(store.rechargeAmountMinor).toBeNull()
		expect(store.rechargeStatus).toBe('idle')
		expect(store.rechargeCommandId).toBeNull()
	})
})
