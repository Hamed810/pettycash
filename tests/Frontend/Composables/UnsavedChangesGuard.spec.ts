import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { defineComponent, h, ref } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard'

/**
 * UI-PUR-012's exit guard, exercised on its own.
 *
 * The forms are tested through their own wiring elsewhere; this file owns the branches that are
 * easy to get wrong and hard to see in a mounted form: Escape, `beforeunload`, an in-app route
 * change, a refused save, and the second dialog that must own Escape while it is open.
 */
const Host = defineComponent({
	props: {
		dirty: { type: Boolean, default: false },
		blocked: { type: Boolean, default: false },
		saveResult: { type: Boolean, default: true }
	},
	setup(props) {
		const save = vi.fn(async () => props.saveResult)
		const guard = useUnsavedChangesGuard({
			isDirty: () => props.dirty,
			save,
			fallback: () => ({ name: 'fallback' }),
			isBlocked: () => props.blocked
		})
		return { ...guard, save, ask: guard.ask }
	},
	template: `
		<div>
			<button type="button" data-test="cancel" @click="ask()">cancel</button>
			<div v-if="open" data-test="dialog">
				<button type="button" data-test="stay" @click="stay">stay</button>
				<button type="button" data-test="discard" @click="discard">discard</button>
				<button type="button" data-test="save" @click="saveAndLeave">save</button>
				<p v-if="saveFailed" data-test="failed">failed</p>
			</div>
		</div>
	`
})

describe('useUnsavedChangesGuard', () => {
	let pinia = createPinia()
	let router: Router
	let wrapper: VueWrapper

	function mountHost(props: Record<string, unknown> = {}): VueWrapper {
		wrapper = mount(Host, {
			props,
			global: { plugins: [pinia, router] }
		})
		return wrapper
	}

	beforeEach(async () => {
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({
			history: createWebHashHistory(),
			routes: [
				{ path: '/form', name: 'form', component: { template: '<div />' } },
				{ path: '/fallback', name: 'fallback', component: { template: '<div />' } },
				{ path: '/other', name: 'other', component: { template: '<div />' } }
			]
		})
		await router.push('/form')
		await router.isReady()
	})

	afterEach(() => {
		wrapper?.unmount()
		document.body.innerHTML = ''
	})

	it('leaves without a dialog when nothing has changed', async () => {
		const host = mountHost({ dirty: false })
		await host.find('[data-test="cancel"]').trigger('click')
		await flushPromises()

		expect(host.find('[data-test="dialog"]').exists()).toBe(false)
		expect(router.currentRoute.value.name).toBe('fallback')
	})

	it('asks before leaving a dirty form and stays on request', async () => {
		const host = mountHost({ dirty: true })
		await host.find('[data-test="cancel"]').trigger('click')
		await flushPromises()

		expect(host.find('[data-test="dialog"]').exists()).toBe(true)
		expect(router.currentRoute.value.name).toBe('form')
		expect((host.vm as unknown as { save: { mock: { calls: unknown[] } } }).save.mock.calls).toHaveLength(0)

		await host.find('[data-test="stay"]').trigger('click')
		expect(host.find('[data-test="dialog"]').exists()).toBe(false)
		expect(router.currentRoute.value.name).toBe('form')
	})

	it('discards without saving and leaves', async () => {
		const host = mountHost({ dirty: true })
		await host.find('[data-test="cancel"]').trigger('click')
		await flushPromises()
		await host.find('[data-test="discard"]').trigger('click')
		await flushPromises()

		expect((host.vm as unknown as { save: { mock: { calls: unknown[] } } }).save.mock.calls).toHaveLength(0)
		expect(router.currentRoute.value.name).toBe('fallback')
	})

	it('saves first and then leaves', async () => {
		const host = mountHost({ dirty: true })
		await host.find('[data-test="cancel"]').trigger('click')
		await flushPromises()
		await host.find('[data-test="save"]').trigger('click')
		await flushPromises()

		expect((host.vm as unknown as { save: { mock: { calls: unknown[] } } }).save.mock.calls).toHaveLength(1)
		expect(router.currentRoute.value.name).toBe('fallback')
	})

	it('stays and reports a refused save', async () => {
		const host = mountHost({ dirty: true, saveResult: false })
		await host.find('[data-test="cancel"]').trigger('click')
		await flushPromises()
		await host.find('[data-test="save"]').trigger('click')
		await flushPromises()

		expect(host.find('[data-test="dialog"]').exists()).toBe(true)
		expect(host.find('[data-test="failed"]').exists()).toBe(true)
		expect(router.currentRoute.value.name).toBe('form')
	})

	it('opens the guard on Escape and treats a second Escape as Stay', async () => {
		const host = mountHost({ dirty: true })

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await host.vm.$nextTick()
		expect(host.find('[data-test="dialog"]').exists()).toBe(true)

		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await host.vm.$nextTick()
		expect(host.find('[data-test="dialog"]').exists()).toBe(false)
		expect(router.currentRoute.value.name).toBe('form')
	})

	it('leaves Escape to the delete confirmation while that dialog is open', async () => {
		const host = mountHost({ dirty: true, blocked: true })
		window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
		await host.vm.$nextTick()
		expect(host.find('[data-test="dialog"]').exists()).toBe(false)
	})

	it('warns the browser only while the form is dirty', async () => {
		const host = mountHost({ dirty: false })

		const clean = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(clean)
		expect(clean.defaultPrevented).toBe(false)

		await host.setProps({ dirty: true })
		const dirty = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(dirty)
		expect(dirty.defaultPrevented).toBe(true)
	})

	it('blocks an in-app route change and resumes it on Discard', async () => {
		// A leave guard only registers for a component rendered under a matched route, so this branch
		// mounts the host through `router-view` rather than directly.
		const dirty = ref(true)
		const FormRoute = defineComponent({ name: 'FormRoute', render: () => h(Host, { dirty: dirty.value }) })
		const localRouter = createRouter({
			history: createWebHashHistory(),
			routes: [
				{ path: '/form', name: 'form', component: FormRoute },
				{ path: '/fallback', name: 'fallback', component: { template: '<div />' } },
				{ path: '/other', name: 'other', component: { template: '<div />' } }
			]
		})
		await localRouter.push('/form')
		await localRouter.isReady()
		const root = mount(defineComponent({ template: '<router-view />' }), {
			global: { plugins: [pinia, localRouter] }
		})
		await flushPromises()

		await localRouter.push('/other')
		await flushPromises()

		expect(localRouter.currentRoute.value.name).toBe('form')
		expect(root.find('[data-test="dialog"]').exists()).toBe(true)

		await root.find('[data-test="discard"]').trigger('click')
		await flushPromises()
		expect(localRouter.currentRoute.value.name).toBe('other')
		root.unmount()
	})

	it('removes its listeners when the form unmounts', async () => {
		const host = mountHost({ dirty: true })
		host.unmount()

		const event = new Event('beforeunload', { cancelable: true })
		window.dispatchEvent(event)
		expect(event.defaultPrevented).toBe(false)
	})
})
