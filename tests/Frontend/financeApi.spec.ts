import { describe, it, expect, vi, beforeEach } from 'vitest'

// The real generateOcsUrl owns the /ocs/v2.php/apps/<app> prefix, so the mock is an identity
// function and the assertions read the app-relative path financeApi asked it to resolve — the
// same arrangement `tests/Frontend/http.spec.ts` uses for the shared client.
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

const request = vi.fn()

vi.mock('@nextcloud/axios', () => ({
	default: { request: (...args: unknown[]) => request(...args) }
}))

import {
	listHolders,
	listHolderAccounts,
	fetchLedger,
	listCompletedSheets,
	getCompletedSheet
} from '@/services/financeApi'
import * as accountsApi from '@/services/accountsApi'
import { ApiError } from '@/types/api'

/** An OCS success body the server would legitimately return for one of the Finance reads. */
function ocsOk<T>(data: T) {
	return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

/** The OCS error envelope the contract declares, as the axios layer would surface it. */
function ocsError(status: number, code: string) {
	return {
		status,
		data: { ocs: { meta: { status: 'failure', statuscode: status }, data: { error: { code, message: 'pettycash.error.test' } } } }
	}
}

beforeEach(() => {
	request.mockReset()
})

describe('financeApi paths and parameters', () => {
	it('lists holders with page/pageSize and no search when none given', async () => {
		request.mockResolvedValue(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 }))
		await listHolders()
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/finance/holders?page=1&pageSize=25'
			})
		)
	})

	it('sends a trimmed search parameter on the holder directory', async () => {
		request.mockResolvedValue(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 }))
		await listHolders({ page: 2, pageSize: 50, search: '  alice  ' })
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/finance/holders?page=2&pageSize=50&search=alice'
			})
		)
	})

	it('omits the search parameter when it is blank after trimming', async () => {
		request.mockResolvedValue(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 }))
		await listHolders({ search: '   ' })
		const url = request.mock.calls.at(0)?.[0]?.url ?? ''
		expect(url).toContain('/finance/holders?')
		expect(url).not.toContain('search')
	})

	it('encodes the holder uid in the accounts path and sends pagination', async () => {
		request.mockResolvedValue(
			ocsOk({ holder: { userId: 'u', displayName: 'U' }, items: [], page: 1, pageSize: 25, total: 0 })
		)
		await listHolderAccounts('user/uid?x', { page: 3, pageSize: 10 })
		const url = request.mock.calls.at(0)?.[0]?.url ?? ''
		expect(url).toContain('/finance/holders/user%2Fuid%3Fx/accounts?page=3&pageSize=10')
	})

	it('requests the account ledger with pagination', async () => {
		request.mockResolvedValue(
			ocsOk({ account: {}, items: [], page: 1, pageSize: 25, total: 0 })
		)
		await fetchLedger('0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d', { page: 2, pageSize: 100 })
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/finance/accounts/0b1f5c1e-6f6a-4a1e-9f0c-2f0d6a7b8c9d/ledger?page=2&pageSize=100'
			})
		)
	})

	it('requests the completed-sheet list with pagination', async () => {
		request.mockResolvedValue(ocsOk({ items: [], page: 1, pageSize: 25, total: 0 }))
		await listCompletedSheets({ page: 4, pageSize: 5 })
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/finance/completed-sheets?page=4&pageSize=5'
			})
		)
	})

	it('requests one completed sheet detail without query parameters', async () => {
		request.mockResolvedValue(ocsOk({ id: 'sheet-1' }))
		await getCompletedSheet('5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a')
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/finance/completed-sheets/5d4c3b2a-1f0e-4d9c-8b7a-6f5e4d3c2b1a'
			})
		)
	})
})

describe('financeApi preserves server error semantics', () => {
	it.each([
		[403, 'FORBIDDEN'],
		[404, 'NOT_FOUND'],
		[422, 'VALIDATION_FAILED']
	])('rejects %i as an ApiError carrying the server envelope', async (status, code) => {
		request.mockResolvedValue(ocsError(status, code))
		await expect(listHolders()).rejects.toMatchObject({
			status,
			body: expect.objectContaining({ code })
		})
	})

	it('rejects a non-OCS success body as an ApiError rather than fabricating a page', async () => {
		request.mockResolvedValue({ status: 200, data: '<html>session expired</html>' })
		await expect(fetchLedger('account-1')).rejects.toBeInstanceOf(ApiError)
	})

	it('never converts a failure into a resolved page: every read rejects', async () => {
		request.mockResolvedValue(ocsError(403, 'FORBIDDEN'))
		await expect(listHolderAccounts('alice')).rejects.toBeInstanceOf(ApiError)
		await expect(getCompletedSheet('sheet-1')).rejects.toBeInstanceOf(ApiError)
	})
})

describe('the recharge boundary', () => {
	it('exposes no Finance recharge or charge write of its own', async () => {
		const financeApi = await import('@/services/financeApi')
		const exports = Object.keys(financeApi)
		expect(exports).toEqual(
			expect.arrayContaining(['listHolders', 'listHolderAccounts', 'fetchLedger', 'listCompletedSheets', 'getCompletedSheet'])
		)
		for (const name of exports) {
			expect(name.toLowerCase()).not.toContain('charge')
			expect(name.toLowerCase()).not.toContain('recharge')
		}
	})

	it('recharge preparation keeps pointing at the existing charge client, unchanged', () => {
		// T027 composes `chargeAccount()`; no second endpoint is created anywhere in this batch.
		expect(typeof accountsApi.chargeAccount).toBe('function')
	})
})
