// In-app navigation - role-gated entries and back-links (specs/004-in-app-navigation).
//
// T002/T007/T010/T014. What only the interface can be wrong about:
//   - an administrator sees the administration entries, a non-admin does not;
//   - each reviewer role sees exactly its own queue entry (finance never by
//     admin-alone, 001/FR-007);
//   - every rendered entry resolves to a declared route (defense in depth next
//     to routeNameIntegrity.spec.ts, which scans source text);
//   - admin views carry a dashboard back-link (FR-004: no dead ends).
//
// `@nextcloud/vue` and `@nextcloud/l10n` are stubbed following the
// Administration.spec.ts pattern (`ssr.noExternal` loads the real package,
// which pulls `.css` Node cannot transform). The navigation itself uses plain
// `<nav><ul><RouterLink>`, so no library seam is under test here.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createWebHashHistory } from 'vue-router'
import AppNavigation from '@/components/navigation/AppNavigation.vue'
import { routes } from '@/router'
import { useAuthStore } from '@/stores/auth'
import { NAVIGATION_ENTRIES } from '@/types/navigation'
import type { Role } from '@/types/permissions'

vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
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

async function mountNav(options: { attachTo?: HTMLElement } = {}) {
	const router = testRouter()
	router.push('/')
	await router.isReady()
	const wrapper = mount(AppNavigation, {
		attachTo: options.attachTo,
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

describe('AppNavigation', () => {
	beforeEach(() => {
		setActivePinia(createPinia())
	})

	it('shows administration entries to an administrator', async () => {
		signInAs(['PURCHASER', 'ADMINISTRATOR'], true)
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-admin-funding"]').exists()).toBe(true)
	})

	it('hides administration entries from a non-administrator', async () => {
		signInAs(['PURCHASER'], false)
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-admin-funding"]').exists()).toBe(false)
		// Base entries remain: the purchaser journey starts here, not at a dead end.
		expect(wrapper.find('[data-test="nav-dashboard"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-sheets"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-accounts"]').exists()).toBe(true)
	})

	it('shows each reviewer exactly their own queue entry', async () => {
		signInAs(['PURCHASER', 'COST_CENTER_MANAGER'], false)
		const costCenter = await mountNav()
		expect(costCenter.find('[data-test="nav-review-cost-center"]').exists()).toBe(true)
		expect(costCenter.find('[data-test="nav-review-senior"]').exists()).toBe(false)
		expect(costCenter.find('[data-test="nav-review-finance"]').exists()).toBe(false)

		setActivePinia(createPinia())
		signInAs(['PURCHASER', 'FINANCE'], false)
		const finance = await mountNav()
		expect(finance.find('[data-test="nav-review-finance"]').exists()).toBe(true)
	})

	it('never grants the finance queue by administrator status alone (001/FR-007)', async () => {
		signInAs(['PURCHASER', 'ADMINISTRATOR'], true)
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-review-finance"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(true)
	})

	it('falls back to dashboard and sheets with no usable session (T004/T022)', async () => {
		// No signed-in user: the mount-time session load fails, so per T004 the
		// nav degrades to dashboard+sheets only — never role-gated entries.
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-dashboard"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-sheets"]').exists()).toBe(true)
		expect(wrapper.findAll('a[data-test^="nav-"]').length).toBe(2)
	})

	it('degrades to dashboard and sheets when the session load fails (T022)', async () => {
		ocsRequest.mockRejectedValueOnce(new Error('session unreachable'))
		const wrapper = await mountNav()
		expect(wrapper.find('[data-test="nav-dashboard"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-sheets"]').exists()).toBe(true)
		expect(wrapper.find('[data-test="nav-accounts"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-admin-configuration"]').exists()).toBe(false)
		expect(wrapper.find('[data-test="nav-review-cost-center"]').exists()).toBe(false)
	})

	it('loads the session on mount so entries reflect the caller', async () => {
		const wrapper = await mountNav()
		await flushPromises()
		expect(ocsRequest).toHaveBeenCalled()
		const call = ocsRequest.mock.calls[0]?.[0] as { url?: string } | undefined
		expect(call?.url ?? '').toContain('/me')
		expect(wrapper.exists()).toBe(true)
	})

	it('resolves every rendered entry to a declared route', async () => {
		signInAs(['PURCHASER', 'COST_CENTER_MANAGER', 'SENIOR_MANAGER', 'FINANCE', 'ADMINISTRATOR'], true)
		const wrapper = await mountNav()
		const links = wrapper.findAll('a[data-test^="nav-"]')
		expect(links.length).toBeGreaterThan(0)
		for (const link of links) {
			const to = link.attributes('href') ?? ''
			// A hash-history href that failed to resolve would be empty or a
			// bare `#`; `#/` is the valid resolved href of the dashboard root.
			expect(to.startsWith('#/')).toBe(true)
		}
		// And the entry table itself only names declared routes.
		for (const entry of NAVIGATION_ENTRIES) {
			expect(declaredNames.has(entry.name)).toBe(true)
		}
	})

	/**
	 * The approved narrow shell is one row of four tabs. A multi-role session carries more
	 * destinations than that, so the row keeps three and folds the rest into the More disclosure
	 * instead of wrapping to a second row. Every destination is still rendered once, in table
	 * order, and the wide sidebar is untouched.
	 */
	it('folds destinations beyond the four-slot row into More on narrow viewports', async () => {
		signInAs(['PURCHASER', 'COST_CENTER_MANAGER', 'SENIOR_MANAGER', 'FINANCE'], false)
		const wide = await mountNav()
		const expected = wide.findAll('a[data-test^="nav-"]').map((link) => link.attributes('data-test'))
		expect(expected.length).toBeGreaterThan(4)
		wide.unmount()

		const originalMatchMedia = window.matchMedia
		window.matchMedia = vi.fn().mockReturnValue({
			matches: true,
			media: '(max-width: 900px)',
			onchange: null,
			addEventListener: vi.fn(),
			removeEventListener: vi.fn(),
			addListener: vi.fn(),
			removeListener: vi.fn(),
			dispatchEvent: vi.fn(),
		}) as unknown as typeof window.matchMedia

		try {
			const wrapper = await mountNav()
			expect(wrapper.find('[data-test="nav-more"]').exists()).toBe(true)
			// Three destinations plus the More slot; the remaining entries live in the disclosure.
			expect(wrapper.findAll('.pettycash-nav__item').length).toBe(4)
			expect(wrapper.findAll('a[data-test^="nav-"]').map((link) => link.attributes('data-test'))).toEqual(expected)
			wrapper.unmount()
		} finally {
			window.matchMedia = originalMatchMedia
		}
	})

	it('keeps every rendered entry focusable in table order (Tab order)', async () => {
		signInAs(['PURCHASER', 'COST_CENTER_MANAGER', 'SENIOR_MANAGER', 'FINANCE', 'ADMINISTRATOR'], true)
		const wrapper = await mountNav({ attachTo: document.body })
		const links = wrapper.findAll('a[data-test^="nav-"]')

		// jsdom has no native Tab traversal. A keyboard user can reach exactly the entries the
		// table promises, in table order, when each rendered link is focusable; the Playwright
		// keyboard spec owns the real keypress.
		expect(links.map((link) => link.attributes('data-test'))).toEqual(
			NAVIGATION_ENTRIES.map((entry) => entry.testId),
		)
		for (const link of links) {
			const element = link.element as HTMLAnchorElement
			element.focus()
			expect(document.activeElement).toBe(element)
		}

		wrapper.unmount()
	})
})
