// Normalized async states (T121): the composable transitions and the shared surface.
import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import AsyncState from '@/components/shared/AsyncState.vue'
import { useAsyncAction } from '@/composables/useAsyncAction'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string) => text),
}))

describe('useAsyncAction', () => {
	it('runs idle → loading → idle on success', async () => {
		const action = useAsyncAction(async (x: number) => x * 2)
		expect(action.status.value).toBe('idle')
		const pending = action.run(21)
		expect(action.status.value).toBe('loading')
		expect(action.isLoading.value).toBe(true)
		expect(await pending).toBe(42)
		expect(action.status.value).toBe('idle')
		expect(action.error.value).toBeNull()
	})

	it('captures the error message and reports error status on failure', async () => {
		const action = useAsyncAction(async (): Promise<number> => {
			throw new Error('downstream refused')
		})
		expect(await action.run()).toBeUndefined()
		expect(action.status.value).toBe('error')
		expect(action.hasError.value).toBe(true)
		expect(action.error.value).toBe('downstream refused')
	})

	it('retries explicitly with the last arguments and never automatically', async () => {
		const fn = vi.fn()
			.mockRejectedValueOnce(new Error('first attempt fails'))
			.mockResolvedValueOnce('second attempt wins')
		const action = useAsyncAction(fn)
		expect(await action.run('same-args')).toBeUndefined()
		expect(fn).toHaveBeenCalledTimes(1)
		// No automatic second attempt happened above; retry is a deliberate call.
		expect(await action.retry()).toBe('second attempt wins')
		expect(fn).toHaveBeenCalledTimes(2)
		expect(fn.mock.calls[1]).toEqual(['same-args'])
		expect(action.status.value).toBe('idle')
	})

	it('retry without a prior run resolves quietly', async () => {
		const fn = vi.fn()
		const action = useAsyncAction(fn)
		expect(await action.retry()).toBeUndefined()
		expect(fn).not.toHaveBeenCalled()
	})

	it('reset clears error state for a fresh attempt', async () => {
		const action = useAsyncAction(async (): Promise<number> => {
			throw new Error('boom')
		})
		await action.run()
		action.reset()
		expect(action.status.value).toBe('idle')
		expect(action.error.value).toBeNull()
	})
})

describe('AsyncState', () => {
	it('shows loading state while loading', () => {
		const wrapper = mount(AsyncState, { props: { status: 'loading' } })
		expect(wrapper.find('[data-testid="async-loading"]').exists()).toBe(true)
		expect(wrapper.find('[data-testid="async-error"]').exists()).toBe(false)
	})

	it('shows the error with role=alert and emits retry on click', async () => {
		const wrapper = mount(AsyncState, { props: { status: 'error', error: 'gone wrong' } })
		const alert = wrapper.find('[data-testid="async-error"]')
		expect(alert.exists()).toBe(true)
		expect(alert.attributes('role')).toBe('alert')
		expect(alert.text()).toContain('gone wrong')
		await wrapper.find('[data-testid="async-retry"]').trigger('click')
		expect(wrapper.emitted('retry')).toHaveLength(1)
	})

	it('shows the empty message when empty', () => {
		const wrapper = mount(AsyncState, { props: { status: 'idle', empty: true, emptyMessage: 'nothing here' } })
		expect(wrapper.find('[data-testid="async-empty"]').exists()).toBe(true)
		expect(wrapper.text()).toContain('nothing here')
	})

	it('renders content when idle and not empty', () => {
		const wrapper = mount(AsyncState, {
			props: { status: 'idle' },
			slots: { default: '<p>real content</p>' },
		})
		expect(wrapper.text()).toContain('real content')
		expect(wrapper.find('[data-testid="async-loading"]').exists()).toBe(false)
	})
})
