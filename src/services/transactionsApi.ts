import { ocs, mutate, commandId } from './http'
import type {
	TransactionDraftWrite,
	TransactionDetail,
	DecisionResult,
	Revision,
	Page,
	CommandOptions
} from '../types/api'

export async function createTransaction(
	sheetId: string,
	data: TransactionDraftWrite,
	options: CommandOptions = {}
): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/sheets/${sheetId}/transactions`, 'POST', data, options.commandId ?? commandId())
}

export async function getTransaction(transactionId: string): Promise<TransactionDetail> {
	return ocs(`/transactions/${transactionId}`)
}

export async function updateTransaction(
	transactionId: string,
	data: TransactionDraftWrite,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/transactions/${transactionId}`, 'PATCH', { ...data, expectedVersion }, options.commandId ?? commandId())
}

export async function deleteTransaction(transactionId: string, expectedVersion: number, options: CommandOptions = {}): Promise<void> {
	await mutate<void>(`/transactions/${transactionId}`, 'DELETE', { expectedVersion }, options.commandId ?? commandId())
}

/**
 * `POST /transactions/{id}/decisions`, answered with the contract's `DecisionResult`.
 *
 * **This was typed `Promise<TransactionDetail>` and was wrong (T344).** `review#decide` — the route that
 * actually serves this path — returns `{decision, transaction, accountMutation?}`, and `ReviewController`
 * omits `accountMutation` for every outcome where no money moved rather than emitting an empty one
 * (FR-040). The old type said a bare `TransactionDetail`, which is what the **unrouted, dead**
 * `TransactionController::decide()` returned; the client was therefore written against a duplicate of an
 * endpoint that no route reached, and reading `decision` or `accountMutation` off the result was a
 * compile error while `transaction` alone type-checked. Both the PHP action and this type were wrong in
 * the same direction, which is the clearest evidence that the dead action was the origin.
 */
export async function submitTransactionDecision(
	transactionId: string,
	decision: 'APPROVE' | 'REJECT' | 'RETURN',
	explanation: string | undefined,
	expectedVersion: number,
	options: CommandOptions = {}
): Promise<DecisionResult> {
	return mutate<DecisionResult>(`/transactions/${transactionId}/decisions`, 'POST', { decision, explanation, expectedVersion }, options.commandId ?? commandId())
}

export async function createCorrection(transactionId: string, options: CommandOptions = {}): Promise<Revision> {
	return mutate<Revision>(`/transactions/${transactionId}/correction`, 'POST', {}, options.commandId ?? commandId())
}

export async function resubmitCorrection(transactionId: string, expectedVersion: number, options: CommandOptions = {}): Promise<TransactionDetail> {
	return mutate<TransactionDetail>(`/transactions/${transactionId}/resubmit`, 'POST', { expectedVersion }, options.commandId ?? commandId())
}

export async function listRevisions(transactionId: string): Promise<Revision[]> {
	return ocs(`/transactions/${transactionId}/revisions`)
}

export async function listAudit(transactionId: string, page = 1, pageSize = 25): Promise<Page<unknown>> {
	const query = new URLSearchParams()
	query.set('page', String(page))
	query.set('pageSize', String(pageSize))
	return ocs(`/transactions/${transactionId}/audit?${query.toString()}`)
}