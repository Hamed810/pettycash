import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { isAxiosError, type AxiosRequestConfig } from 'axios'
import { ApiError, type ApiErrorBody } from '../types/api'

export function commandId(): string { return crypto.randomUUID() }

/** The only shape `contracts/ocs-api.yaml` describes: `ocs.data` carries the schema value. */
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
	// as a rejection so a caller cannot mistake a business failure for a successful payload.
	if (typeof data === 'object' && data !== null && 'error' in data && (data as { error?: unknown }).error) {
		return { ok: false, error: (data as { error: ApiErrorBody }).error }
	}

	return { ok: true, data: data as T }
}

function malformed(): ApiErrorBody {
	return { code: 'MALFORMED_RESPONSE', message: 'pettycash.error.malformedResponse' }
}

export async function ocs<T>(path: string, config: AxiosRequestConfig = {}): Promise<T> {
	try {
		const response = await axios.request<OcsEnvelope<T>>({
			...config,
			url: generateOcsUrl(`/apps/pettycash/api/v1${path}`),
			headers: { 'OCS-APIRequest': 'true', ...config.headers },
		})
		const result = unwrap<T>(response?.data)
		if (!result.ok) throw new ApiError(response?.status ?? 0, result.error)
		return result.data
	} catch (error: unknown) {
		if (error instanceof ApiError) throw error
		if (isAxiosError(error)) {
			const data = error.response?.data as { ocs?: { data?: { error?: ApiErrorBody } } } | undefined
			throw new ApiError(error.response?.status ?? 0, data?.ocs?.data?.error ?? { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' })
		}
		throw error
	}
}

export async function mutate<T>(path: string, method: 'POST' | 'PUT' | 'PATCH' | 'DELETE', data: unknown, id = commandId()): Promise<T> {
	return ocs<T>(path, { method, data, headers: { 'X-PettyCash-Command-ID': id } })
}
