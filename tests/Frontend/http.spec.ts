import { describe, it, expect, vi, beforeEach } from 'vitest'

// The real generateOcsUrl owns the /ocs/v2.php/apps/<app> prefix, so the mock stays an identity
// function and the test asserts on the path http.ts asks it to resolve.
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

import { ocs, mutate, commandId } from '@/services/http'
import { ApiError } from '@/types/api'

/** An OCS response the server would legitimately return. */
function ocsOk<T>(data: T) {
	return { data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

/** A response that is not OCS-shaped, as a proxy or a session-expiry HTML page would be. */
function notOcs(body: unknown, status = 200) {
	return { status, data: body }
}

describe('ocs()', () => {
	beforeEach(() => {
		request.mockReset()
	})

	it('unwraps the OCS envelope', async () => {
		request.mockResolvedValue(ocsOk({ id: 'sheet-1' }))
		await expect(ocs<{ id: string }>('/sheets/sheet-1')).resolves.toEqual({ id: 'sheet-1' })
	})

	it('sends the OCS-APIRequest header and the resolved URL', async () => {
		request.mockResolvedValue(ocsOk(null))
		await ocs('/me')
		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				url: '/ocs/v2.php/apps/pettycash/api/v1/me',				headers: expect.objectContaining({ 'OCS-APIRequest': 'true' })
			})
		)
	})

	// The unwrap below is a three-level dereference on a body whose shape the client never checks.
	// Anything that answers 2xx without the OCS envelope used to raise a raw TypeError that the
	// catch block did not recognise, so the caller received a TypeError instead of an ApiError.
	describe('rejects a response that is not OCS-shaped as an ApiError', () => {
		const cases: Array<[string, unknown, number]> = [
			['an undefined body', undefined, 200],
			['an empty object', {}, 200],
			['an HTML page from a session expiry', '<html><body>login</body></html>', 200],
			['a bare array', [], 200],
			['an object whose ocs member is not an object', { ocs: 'nope' }, 200],
			['an object whose ocs member has no data', { ocs: { meta: {} } }, 200],
			['a proxy error page', '<html>502 Bad Gateway</html>', 502]
		]

		for (const [label, body, status] of cases) {
			it(label, async () => {
				request.mockResolvedValue(notOcs(body, status))
				const error = await ocs('/sheets').catch((e: unknown) => e)

				expect(error).toBeInstanceOf(ApiError)
				expect(error).not.toBeInstanceOf(TypeError)
				expect((error as ApiError).status).toBe(status)
				expect((error as ApiError).body.code).toBe('MALFORMED_RESPONSE')
				expect((error as ApiError).body.message).not.toBe('')
			})
		}
	})

	describe('maps an OCS error envelope onto ApiError', () => {
		it('keeps the server code, message, commandId, and fieldErrors', async () => {
			const body = {
				code: 'VALIDATION_FAILED',
				message: 'Cost center not found',
				commandId: 'cmd-1',
				fieldErrors: { costCenterId: ['unknown cost center'] }
			}
			request.mockRejectedValue(
				Object.assign(new Error('Request failed'), {
					isAxiosError: true,
					response: { status: 422, data: { ocs: { data: { error: body } } } }
				})
			)

			const error = (await ocs('/sheets').catch((e: unknown) => e)) as ApiError

			expect(error).toBeInstanceOf(ApiError)
			expect(error.status).toBe(422)
			expect(error.body).toEqual(body)
		})

		it('falls back to a network failure when the error carries no OCS envelope', async () => {
			request.mockRejectedValue(
				Object.assign(new Error('Network Error'), { isAxiosError: true, response: undefined })
			)

			const error = (await ocs('/sheets').catch((e: unknown) => e)) as ApiError

			expect(error).toBeInstanceOf(ApiError)
			expect(error.status).toBe(0)
			expect(error.body.code).toBe('NETWORK_FAILURE')
		})
	})

	it('rethrows a non-axios rejection unchanged', async () => {
		const boom = new Error('boom')
		request.mockRejectedValue(boom)
		await expect(ocs('/sheets')).rejects.toBe(boom)
	})
})

describe('mutate()', () => {
	beforeEach(() => {
		request.mockReset()
	})

	it('sends the generated command id and the method', async () => {
		request.mockResolvedValue(ocsOk(null))

		await mutate('/sheets/sheet-1/submit', 'POST', { expectedVersion: 3 })

		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				method: 'POST',
				data: { expectedVersion: 3 },
				headers: expect.objectContaining({ 'X-PettyCash-Command-ID': expect.any(String) })
			})
		)
	})

	it('uses a caller-supplied command id so a retry replays the same command', async () => {
		request.mockResolvedValue(ocsOk(null))

		await mutate('/sheets/sheet-1/submit', 'POST', {}, 'cmd-fixed')

		expect(request).toHaveBeenCalledWith(
			expect.objectContaining({
				headers: expect.objectContaining({ 'X-PettyCash-Command-ID': 'cmd-fixed' })
			})
		)
	})
})

describe('commandId()', () => {
	it('returns a distinct UUID per call', () => {
		expect(commandId()).not.toBe(commandId())
		expect(commandId()).toMatch(/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/)
	})
})
