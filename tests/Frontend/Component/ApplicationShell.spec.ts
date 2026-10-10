// Application shell - routes, role-union navigation, capability gating, focus (T115).
//
// T115 is a test-writing task: most of what it names already exists (route table,
// role-aware nav, session bootstrap), and those cases document the inventory. The
// focus-movement case is intentionally RED until T118 implements route-heading
// focus: today nothing moves focus on navigation, so a keyboard user who follows
// a link is left with focus on `body` while the content changes elsewhere.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import AppRoot from '@/AppRoot.vue'
import AppNavigation from '@/components/navigation/AppNavigation.vue'
import { routes } from '@/router'
import { useAuthStore } from '@/stores/auth'
import type { Role } from '@/types/permissions'

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return {
		...actual,
		t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
			text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	}
})

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
	imagePath: (_app: string, file: string) => `/apps/pettycash/img/${file}`,
}))

const EXPECTED_ROUTE_NAMES = [
	'dashboard', 'sheets', 'sheet-create', 'sheet-detail', 'sheet-edit',
	'transactions', 'transaction-create', 'transaction-detail', 'transaction-edit',
	'transaction-audit', 'review-queue-cost-center', 'review-queue-senior',
	'review-queue-finance', 'review-queue-returns', 'review-detail', 'finance-review',
	'accounts', 'admin-configuration', 'admin-funding', 'not-found',
]

function signInAs(roles: Role[], canAdminister: boolean) {
	const auth = useAuthStore()
	auth.setUser({ userId: 'test-user', displayName: 'Test User', roles })
	auth.setCapabilities({ canAdminister })
	return auth
}

describe('ApplicationShell', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('declares every primary hash route by name', () => {
		const names = routes.map((r) => String(r.name))
		for (const expected of EXPECTED_ROUTE_NAMES) {
			expect(names, `route table must declare ${expected}`).toContain(expected)
		}
		for (const route of routes) {
			expect(String(route.path).length, `route ${String(route.name)} needs a path`).toBeGreaterThan(0)
		}
	})

	it('renders the union of entries for a multi-role session', async () => {
		signInAs(['PURCHASER', 'COST_CENTER_MANAGER', 'SENIOR_MANAGER'], false)
		const router = createRouter({ history: createWebHashHistory(), routes })
		router.push('/')
		await router.isReady()
		const wrapper = mount(AppNavigation, { global: { plugins: [router] } })
		await flushPromises()
		for (const id of ['nav-dashboard', 'nav-sheets', 'nav-review-cost-center', 'nav-review-senior', 'nav-returns', 'nav-accounts']) {
			expect(wrapper.find(`[data-test="${id}"]`).exists(), `union must include ${id}`).toBe(true)
		}
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-review-finance"]').exists()).toBe(false)
	})

	it('hides administration from a non-administrator at the shell', async () => {
		signInAs(['PURCHASER'], false)
		const router = createRouter({ history: createWebHashHistory(), routes })
		router.push('/')
		await router.isReady()
		const wrapper = mount(AppRoot, { global: { plugins: [router] } })
		await flushPromises()
		expect(wrapper.find('[data-test="app-navigation"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-admin-funding"]').exists()).toBe(false)
	})

	it('moves focus into the routed view on navigation (T118)', async () => {
		signInAs(['PURCHASER'], false)
		// Real route names with inert components: the focus behavior lives in
		// the shell, and mounting full views would drag their stores along.
		const router = createRouter({
			history: createWebHashHistory(),
			routes: routes.map(({ path, name }) => ({
				path: Array.isArray(path) ? path[0] : String(path),
				name,
				component: { template: '<section><h2>stub</h2></section>' },
			})),
		})
		router.push('/')
		await router.isReady()
		// Attached: `focus()` is a no-op on detached nodes, and this test is
		// precisely about where focus lands.
		const wrapper = mount(AppRoot, { attachTo: document.body, global: { plugins: [router] } })
		await flushPromises()
		await router.push('/sheets')
		await flushPromises()
		const active = document.activeElement as HTMLElement | null
		expect(
			active !== null && active !== document.body && wrapper.element.contains(active),
			'after navigation, focus must sit inside the routed view, not on body'
		).toBe(true)
		wrapper.unmount()
	})
})
