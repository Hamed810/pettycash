import { ocs } from './http'
import type { SessionContext } from '@/types/api'

export type RoleScopedDashboard = {
	purchaser?: Record<string, unknown>
	costCenter?: Record<string, unknown>
	senior?: Record<string, unknown>
	finance?: Record<string, unknown>
	admin?: Record<string, unknown>
}

export async function fetchSessionContext(): Promise<SessionContext> {
	return ocs<SessionContext>('/me')
}

export async function fetchDashboard(): Promise<RoleScopedDashboard> {
	return ocs<RoleScopedDashboard>('/dashboard')
}
