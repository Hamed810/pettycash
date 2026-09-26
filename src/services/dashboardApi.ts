import type { RoleScopedDashboard } from '@/types/dashboard'
import { ocs } from './http'

/**
 * FR-061's projection: `GET /dashboard`, which answers with one bucket per role.
 *
 * A read, so no command identifier is sent: the OCS helper's `X-PettyCash-Command-ID` header is for
 * state-changing requests, and FR-042's replay window has nothing to replay here. Authorization is
 * the server's — `plan.md` fixes it as server-authoritative — so nothing about the caller's role is
 * sent and the client cannot widen the answer by asking for more.
 */
export async function fetchDashboard(): Promise<RoleScopedDashboard> {
	return ocs<RoleScopedDashboard>('/dashboard')
}
