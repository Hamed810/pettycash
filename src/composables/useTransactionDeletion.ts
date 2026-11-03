import { ref } from 'vue'
import { ApiError } from '@/types/api'
import { commandId } from '@/services/http'
import { useTransactionStore } from '@/stores/transactions'

/** The three answers a confirmed draft deletion can have. */
export type DeletionOutcome = 'deleted' | 'already-deleted' | 'failed'

export interface DeletionResult {
	outcome: DeletionOutcome
	/** The HTTP status when the server answered; `null` for a transport failure. */
	status: number | null
	error: Error | null
}

/**
 * One draft-deletion intent, with the command identity the server's replay rule needs (005 T065).
 *
 * ## Why the command id cannot be minted per attempt
 *
 * `DELETE /transactions/{id}` is replay-gated by 001 `T436`: the same command id and payload return
 * the original `204` **even after the row is gone**, a different payload under the same id is `409`,
 * and a new id after the deletion is `404`. A retry after an unknown outcome is exactly the case
 * where the first request may have succeeded without its answer reaching the browser. Minting a new
 * id for the retry would turn a recovered success into "not found"; keeping the id makes the retry
 * prove what happened.
 *
 * The identity is keyed by `(transactionId, expectedVersion)`:
 *
 * - the same pair reuses the id for as long as the intent is unresolved, including after a network
 *   failure or a 409/422;
 * - a different pair is a different command — a stale `expectedVersion` cannot be replayed against
 *   the new state — so it gets a fresh id.
 *
 * ## Single flight
 *
 * A second confirmation for the same intent returns the first promise instead of issuing a second
 * `DELETE`. The store also de-duplicates per transaction; this layer is what makes the *UI* unable
 * to create a second intent, because it is where the confirmation control lives.
 */
export function useTransactionDeletion() {
	const transactionStore = useTransactionStore()

	const pending = ref(false)
	const lastResult = ref<DeletionResult | null>(null)

	let intent: { key: string; commandId: string } | null = null
	let inFlight: { key: string; promise: Promise<DeletionResult> } | null = null

	function keyFor(transactionId: string, expectedVersion: number): string {
		return `${transactionId}:${expectedVersion}`
	}

	async function run(
		key: string,
		transactionId: string,
		expectedVersion: number,
		command: string
	): Promise<DeletionResult> {
		pending.value = true
		try {
			await transactionStore.deleteExistingTransaction(transactionId, expectedVersion, { commandId: command })
			intent = null
			lastResult.value = { outcome: 'deleted', status: 204, error: null }
			return lastResult.value
		} catch (error: unknown) {
			const status = error instanceof ApiError ? error.status : null
			// A different command id reaching a deleted row answers `404`. The desired state already
			// holds, so this is reported as such rather than as a failed delete — and the local row is
			// already dropped by the store for this exact status.
			if (status === 404) {
				intent = null
				lastResult.value = { outcome: 'already-deleted', status, error: error instanceof Error ? error : null }
				return lastResult.value
			}
			// Keep the intent: an unknown outcome (network failure) must be retryable under the same
			// command id. A definite 409/422 keeps it too, which is harmless because a retry with the
			// same stale version would be refused identically, and the surface refetches before any
			// further action anyway.
			intent = { key, commandId: command }
			lastResult.value = { outcome: 'failed', status, error: error instanceof Error ? error : null }
			return lastResult.value
		} finally {
			pending.value = false
		}
	}

	/** Delete one draft at most once per in-flight intent. */
	async function remove(transactionId: string, expectedVersion: number): Promise<DeletionResult> {
		const key = keyFor(transactionId, expectedVersion)
		if (inFlight?.key === key) return inFlight.promise

		if (intent?.key !== key) {
			intent = { key, commandId: commandId() }
		}
		const command = intent.commandId
		const promise = run(key, transactionId, expectedVersion, command)
		inFlight = { key, promise }
		try {
			return await promise
		} finally {
			if (inFlight?.promise === promise) inFlight = null
		}
	}

	/**
	 * Abandon a failed intent. The surface calls this when the confirmation is dismissed *without*
	 * retrying, so a later delete of the same row is a new intent rather than a replay of an
	 * identity the user walked away from. It does not affect an in-flight request.
	 */
	function reset(): void {
		if (inFlight === null) intent = null
	}

	return { pending, lastResult, remove, reset }
}
