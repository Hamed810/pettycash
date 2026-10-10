import { commandId } from './http'
import type { Evidence, CommandOptions } from '../types/api'

export interface EvidenceFilters {
	activeOnly?: boolean
	transactionId?: string
	revisionId?: string
}

export interface EvidencePageParams {
	page?: number
	pageSize?: number
	filters?: EvidenceFilters
}

export async function uploadEvidence(
	transactionId: string,
	file: File,
	options: CommandOptions = {}
): Promise<Evidence> {
	const formData = new FormData()
	formData.append('file', file)

	const id = options.commandId ?? commandId()
	const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${transactionId}/evidence`, {
		method: 'POST',
		headers: {
			'OCS-APIRequest': 'true',
			'X-PettyCash-Command-ID': id
		},
		body: formData
	})

	if (!response.ok) {
		const error = await response.json()
		throw new Error(error.ocs?.data?.error?.message ?? 'Upload failed')
	}

	const data = await response.json()
	return data.ocs.data
}

export async function deleteEvidence(evidenceId: string, options: CommandOptions = {}): Promise<Evidence> {
	return mutate<Evidence>(`/evidence/${evidenceId}`, 'DELETE', {}, options.commandId ?? commandId())
}

export async function getEvidenceContent(evidenceId: string, disposition: 'inline' | 'attachment' = 'inline'): Promise<Blob> {
	const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/evidence/${evidenceId}/content?disposition=${disposition}`, {
		headers: { 'OCS-APIRequest': 'true' }
	})

	if (!response.ok) {
		throw new Error('Failed to fetch evidence content')
	}

	return response.blob()
}

export async function createPublicLink(evidenceId: string, expiresAt?: string, options: CommandOptions = {}): Promise<{ id: string; url: string }> {
	return mutate<{ id: string; url: string }>(`/evidence/${evidenceId}/links`, 'POST', { expiresAt }, options.commandId ?? commandId())
}

export async function deactivatePublicLink(linkId: string, options: CommandOptions = {}): Promise<{ id: string }> {
	return mutate<{ id: string }>(`/evidence-links/${linkId}/deactivate`, 'POST', {}, options.commandId ?? commandId())
}

// Re-export mutate for use in this module
import { mutate } from './http'