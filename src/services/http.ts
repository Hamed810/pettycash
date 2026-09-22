import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import type { AxiosRequestConfig } from 'axios'
import { ApiError, type ApiErrorBody } from '../types/api'

export function commandId(): string { return crypto.randomUUID() }

export async function ocs<T>(path: string, config: AxiosRequestConfig = {}): Promise<T> {
	try {
		const response = await axios.request<{ ocs: { data: T } }>({
			...config,
			url: generateOcsUrl(`/apps/pettycash/api/v1${path}`),
			headers: { 'OCS-APIRequest': 'true', ...config.headers },
		})
		return response.data.ocs.data
	} catch (error: unknown) {
		if (axios.isAxiosError(error)) {
			const data = error.response?.data as { ocs?: { data?: { error?: ApiErrorBody } } } | undefined
			throw new ApiError(error.response?.status ?? 0, data?.ocs?.data?.error ?? { code: 'NETWORK_FAILURE', message: 'pettycash.error.network' })
		}
		throw error
	}
}

export async function mutate<T>(path: string, method: 'POST' | 'PUT' | 'DELETE', data: unknown, id = commandId()): Promise<T> {
	return ocs<T>(path, { method, data, headers: { 'X-PettyCash-Command-ID': id } })
}
