import { ocs } from './http'
import type { AuditEvent, Page, Revision } from '../types/api'

/**
 * The audit-domain read client (T097): one transaction's formal history.
 *
 * Transport only — no business-rule authority (stores never decide, components
 * never call HTTP directly). The endpoints are role-scoped server-side
 * (FR-051/FR-055): an out-of-scope caller is answered 404, which surfaces here
 * as an `ApiError` the store records rather than a second client-side gate.
 */
export async function listTransactionAudit(
	transactionId: string,
	page = 1,
	pageSize = 25
): Promise<Page<AuditEvent>> {
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return ocs(`/transactions/${transactionId}/audit?${query.toString()}`)
}

export async function listTransactionRevisions(transactionId: string): Promise<Revision[]> {
	return ocs(`/transactions/${transactionId}/revisions`)
}
