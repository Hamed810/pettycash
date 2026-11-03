import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isAxiosError, type AxiosRequestConfig } from 'axios'
import { ApiError, type ApiErrorBody, type ApiErrorEnvelope } from '../types/api'

/**
 * A client-generated command identity for idempotent mutation replay.
 *
 * `crypto.randomUUID()` is exposed only in secure contexts. A Nextcloud instance served over
 * plain HTTP on a LAN IP address or a domain name is not a secure context, so the method is
 * absent even in current browsers. `crypto.getRandomValues()` is available there and is a
 * CSPRNG, so build the v4 UUID from 16 random bytes rather than weakening the command identity
 * with `Math.random()` or a timestamp.
 */
export function commandId(): string {
	const c = globalThis.crypto
	if (c && typeof c.randomUUID === 'function') {
		return c.randomUUID()
	}
	if (!c || typeof c.getRandomValues !== 'function') {
		throw new Error('A secure random source is required to generate a command ID')
	}

	const bytes = c.getRandomValues(new Uint8Array(16))
	const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength)
	// UUID v4: version 4 and RFC 4122 variant bits.
	view.setUint8(6, (view.getUint8(6) & 0x0f) | 0x40)
	view.setUint8(8, (view.getUint8(8) & 0x3f) | 0x80)
	const hex = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('')

	return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`
}

/**
 * The only shape `contracts/ocs-api.yaml` describes: `ocs.data` carries the schema value, and for
 * an error response that value is the `ErrorEnvelope` wrapper — `{ error: Error }`, so the error
 * lives at `ocs.data.error` and nowhere else (T334).
 */
interface OcsEnvelope<T> { ocs?: { data?: T } }

/**
 * Reads `ocs.data` only when the body really is an OCS envelope.
 *
 * A 2xx response is not automatically OCS: a session expiry answers with Nextcloud's HTML login
 * page, a reverse proxy answers with its own error page, and a route that returns no content
 * answers with an empty body. Dereferencing `response.data.ocs.data` blindly turned each of those
 * into a `TypeError`, which the `catch` below cannot classify because it is not an `AxiosError`,
 * so the caller received a raw `TypeError` instead of the `Error` envelope `T030` is accountable
 * for and had nothing to render.
 */
function unwrap<T>(body: unknown): { ok: true; data: T } | { ok: false; error: ApiErrorBody } {
	if (typeof body !== 'object' || body === null || Array.isArray(body)) {
		return { ok: false, error: malformed() }
	}

	const { ocs } = body as OcsEnvelope<T>
	if (typeof ocs !== 'object' || ocs === null || !('data' in ocs)) {
		return { ok: false, error: malformed() }
	}

	const data = ocs.data

	// OCS permits a failure to arrive as HTTP 200 with the error inside the envelope. Surface it
	// as a rejection so a caller cannot mistake a business failure for a successful payload. The
	// `error` key is what makes that check possible at all, which is why the contract wraps `Error`
	// rather than putting `code`/`message` straight into `ocs.data`.
	if (typeof data === 'object' && data !== null && 'error' in data && (data as { error?: unknown }).error) {
		return { ok: false, error: (data as ApiErrorEnvelope).error }
	}

	return { ok: true, data: data as T }
}

function malformed(): ApiErrorBody {
	return { code: 'MALFORMED_RESPONSE', message: 'pettycash.error.malformedResponse' }
}

/**
 * The request shape every OCS call uses.
 *
 * The error mapping is shared below so `ocs()` and `ocsVoid()` cannot drift: a 422 that is readable
 * through one and opaque through the other would be a defect the caller cannot see coming.
 */
function requestConfig(path: string, config: AxiosRequestConfig): AxiosRequestConfig {
	return {
		...config,
		url: generateOcsUrl(`/apps/pettycash/api/v1${path}`),
		headers: { 'OCS-APIRequest': 'true', ...config.headers },
	}
}

/** Every failure as an `ApiError`. Typed `never` because it always throws. */
function toApiError(error: unknown): never {
	if (error instanceof ApiError) throw error
	if (isAxiosError(error)) {
		const data = error.response?.data as { ocs?: { data?: ApiErrorEnvelope } } | undefined
		throw new ApiError(error.response?.status ?? 0, data?.ocs?.data?.error ?? { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' })
	}
	throw error
}

export async function ocs<T>(path: string, config: AxiosRequestConfig = {}): Promise<T> {
	try {
		// Awaited directly rather than through a second async helper: the extra microtask boundary
		// was observable to callers that flush once, and there is no need to pay for it.
		const response = await axios.request<unknown>(requestConfig(path, config))
		const result = unwrap<T>(response?.data)
		if (!result.ok) throw new ApiError(response?.status ?? 0, result.error)
		return result.data
	} catch (error: unknown) {
		return toApiError(error)
	}
}

/**
 * A command whose success answer is **bodyless** — `204 No Content`.
 *
 * ## Why this is separate from `ocs()` and not a flag on it
 *
 * `ocs<T>()` promises a value and therefore treats an empty body as unreadable. That is correct for
 * every read and for every mutation that answers the created/updated resource; relaxing it globally
 * would silently turn a truncated `200` into `undefined` for all of them. A `DELETE` is the opposite
 * contract: `contracts/ocs-api.yaml` declares `204` with no content, so there is no envelope to
 * unwrap and no value to return.
 *
 * The 204 case is matched on **both** the status and an actually empty body. A 204 that carries
 * bytes is not the declared answer, and a `200` with an empty body is still malformed — the
 * acceptance is deliberately narrow so that "the server returned nothing" stays distinguishable
 * from "the server's answer was lost".
 */
export async function ocsVoid(path: string, config: AxiosRequestConfig = {}): Promise<void> {
	try {
		const response = await axios.request<unknown>(requestConfig(path, config))
		const status = response?.status ?? 0
		const body = response?.data
		if (status === 204 && (body === undefined || body === null || body === '')) return

		const result = unwrap<unknown>(body)
		if (!result.ok) throw new ApiError(status, result.error)
	} catch (error: unknown) {
		return toApiError(error)
	}
}

export async function mutate<T>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', data: unknown, id = commandId()): Promise<T> {
	return ocs<T>(path, { method, data, headers: { 'X-PettyCash-Command-ID': id } })
}

/**
 * `mutate()` for a bodyless command. The command id is required by the replay-gated deletes, and is
 * sent by every caller so a retry of one intent can reuse the identity of the first attempt (T065).
 */
export async function mutateVoid(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', data: unknown, id = commandId()): Promise<void> {
	return ocsVoid(path, { method, data, headers: { 'X-PettyCash-Command-ID': id } })
}
