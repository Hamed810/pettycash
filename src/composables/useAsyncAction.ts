import { computed, ref, type ComputedRef, type Ref } from 'vue'

/**
 * T121: one normalized async-action shape for loads and mutations.
 *
 * Every view previously hand-rolled its own `loading`/`error` flags with slightly
 * different retry semantics. This composable is the single idiom: an explicit
 * `run()` for the first attempt and an explicit user-triggered `retry()` that
 * replays the last arguments. There is deliberately **no** automatic retry —
 * re-sending a mutation on failure is how a double charge happens (FR-075), so a
 * retry only ever starts from a person's click, wired to `retry()` by the view.
 */
export type AsyncStatus = 'idle' | 'loading' | 'error'

export interface AsyncAction<TArgs extends unknown[], TResult> {
	readonly status: Ref<AsyncStatus>
	readonly error: Ref<string | null>
	readonly isLoading: ComputedRef<boolean>
	readonly hasError: ComputedRef<boolean>
	run: (...args: TArgs) => Promise<TResult | undefined>
	retry: () => Promise<TResult | undefined>
	reset: () => void
}

export function useAsyncAction<TArgs extends unknown[], TResult>(
	fn: (...args: TArgs) => Promise<TResult>
): AsyncAction<TArgs, TResult> {
	const status = ref<AsyncStatus>('idle')
	const error = ref<string | null>(null)
	const lastArgs = ref<TArgs | null>(null)

	async function run(...args: TArgs): Promise<TResult | undefined> {
		status.value = 'loading'
		error.value = null
		lastArgs.value = args
		try {
			const result = await fn(...args)
			status.value = 'idle'
			return result
		} catch (e: unknown) {
			status.value = 'error'
			error.value = e instanceof Error ? e.message : String(e)
			return undefined
		}
	}

	function retry(): Promise<TResult | undefined> {
		if (lastArgs.value === null) {
			return Promise.resolve(undefined)
		}
		return run(...lastArgs.value)
	}

	function reset(): void {
		status.value = 'idle'
		error.value = null
		lastArgs.value = null
	}

	return {
		status,
		error,
		isLoading: computed(() => status.value === 'loading'),
		hasError: computed(() => status.value === 'error'),
		run,
		retry,
		reset,
	}
}
