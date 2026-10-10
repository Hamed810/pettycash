import { ocs } from './http'
import type { SessionContext } from '@/types/api'

export async function fetchSessionContext(): Promise<SessionContext> {
	return ocs<SessionContext>('/me')
}
