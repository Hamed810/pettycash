import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import AppNavigation from '@/components/navigation/AppNavigation.vue'
import { routes } from '@/router'
import { useAuthStore } from '@/stores/auth'
import { NAVIGATION_ENTRIES } from '@/types/navigation'
import type { Role } from '@/types/permissions'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'en'),
}))

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) =>
			Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError),
	},
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
}))

function testRouter() {
	return createRouter({ history: createWebHashHistory(), routes })
}

async function mountNav() {
	const router = testRouter()
	router.push('/')
	await router.isReady()
	const wrapper = mount(AppNavigation, {
		global: { plugins: [router] },
	})
	await flushPromises()
	return wrapper
}

function signInAs(roles: Role[], canAdminister: boolean) {
	const auth = useAuthStore()
	auth.setUser({ userId: 'test-user', displayName: 'Test User', roles })
	auth.setCapabilities({ canAdminister })
	return auth
}

const declaredNames = new Set(routes.map((r) => String(r.name)))

describe('Finance routes and navigation (T028)', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('declares finance-holders route', () => {
		expect(declaredNames.has('finance-holders')).toBe(true)
	})

	it('declares finance-completed-sheets route', () => {
		expect(declaredNames.has('finance-completed-sheets')).toBe(true)
	})

	it('declares finance-completed-sheet-detail route', () => {
		expect(declaredNames.has('finance-completed-sheet-detail')).toBe(true)
	})

	it('finance-holders route resolves by name', () => {
		const router = testRouter()
		expect(router.hasRoute('finance-holders')).toBe(true)
		expect(router.hasRoute('finance-completed-sheets')).toBe(true)
		expect(router.hasRoute('finance-completed-sheet-detail')).toBe(true)
	})

	it('renders finance nav entry for FINANCE role', async () => {
		signInAs(['PURCHASER', 'FINANCE'], false)
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-finance-holders"]').exists()).toBe(true)
		wrapper.unmount()
	})

	it('does not render finance nav entry for non-FINANCE role', async () => {
		signInAs(['PURCHASER'], false)
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-finance-holders"]').exists()).toBe(false)
		wrapper.unmount()
	})

	it('does not regress non-finance routes', () => {
		expect(declaredNames.has('dashboard')).toBe(true)
		expect(declaredNames.has('sheets')).toBe(true)
		expect(declaredNames.has('accounts')).toBe(true)
	})

	it('every nav entry testId resolves to a declared route', () => {
		for (const entry of NAVIGATION_ENTRIES) {
			expect(declaredNames.has(entry.name)).toBe(true)
		}
	})
})
