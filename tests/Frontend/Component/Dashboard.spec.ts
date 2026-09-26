import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

// `generateOcsUrl` only prefixes the OCS base path; the caller supplies the app route, exactly as
// the real helper does, so the requested URL can be asserted end to end.
vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: vi.fn((path: string) => `/ocs/v2.php${path}`),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`),
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

vi.mock('@nextcloud/axios', () => ({
	default: { request: vi.fn(), post: vi.fn() }
}))

// The catalog does not exist yet (T119 owns it), so `t` returns the key. That is what makes an
// assertion about a *label* mean the same thing in any locale, and it is why the fallbacks in the
// components are asserted on their key rather than on an English word.
vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((app: string, key: string, vars?: Record<string, string>) =>
		vars ? `${key} ${Object.values(vars).join(' ')}` : key),
	n: vi.fn((key: string) => key),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true)
}))

vi.mock('@nextcloud/vue/components/NcButton', () => ({
	default: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size', 'ariaPressed'],
		template: '<button :type="type" :disabled="disabled" :aria-pressed="ariaPressed"><slot /></button>'
	}
}))

import axios from '@nextcloud/axios'
import { t } from '@nextcloud/l10n'
import DashboardCard from '@/components/dashboard/DashboardCard.vue'
import DashboardView from '@/views/DashboardView.vue'
import { fetchDashboard } from '@/services/dashboardApi'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'
import type { Role } from '@/types/api'
import type { DashboardMetric } from '@/types/dashboard'
import { dashboardMetrics, isMoney } from '@/utils/dashboard'

const requestMock = vi.mocked(axios.request)

/** Every source this slice adds or changes, read once so the declaration gates cannot go stale. */
const SOURCES = [
	'src/views/DashboardView.vue',
	'src/components/dashboard/DashboardCard.vue',
	'src/stores/dashboard.ts',
	'src/services/dashboardApi.ts',
	'src/utils/dashboard.ts',
	'src/types/dashboard.ts'
].map((file) => ({ file, source: readFileSync(join(process.cwd(), file), 'utf8') }))

const PROJECTION = {
	purchaser: { openSheets: 2, returnedTransactions: 1 },
	costCenter: { queueItems: 7, decisions: 3 },
	senior: { queueItems: 4 },
	finance: { queueItems: 9, balance: { amountMinor: 1250000, currency: 'IRR', minorUnit: 0 } },
	admin: { activeUsers: 12 }
}

function ocsResponse(data: unknown) {
	return { data: { ocs: { meta: { status: 'ok' }, data } } }
}

function setSessionRoles(...roles: Role[]): void {
	useAuthStore().setUser({ userId: 'u-1', displayName: 'کاربر آزمایشی', roles })
}

function requestedUrl(call = 0): string {
	return String(requestMock.mock.calls[call]?.[0]?.url ?? '')
}

function requestedHeaders(call = 0): Record<string, unknown> {
	return (requestMock.mock.calls[call]?.[0]?.headers ?? {}) as Record<string, unknown>
}

async function flushPromises(): Promise<void> {
	await Promise.resolve()
	await Promise.resolve()
	await Promise.resolve()
}

function metric(metrics: DashboardMetric[], key: string): DashboardMetric {
	const found = metrics.find((entry) => entry.key === key)
	if (!found) throw new Error(`no metric named ${key} in ${metrics.map((entry) => entry.key).join(', ')}`)
	return found
}

describe('US7 Role-aware Dashboard — Vue Component Tests', () => {
	beforeEach(() => {
		vi.clearAllMocks()
		// Restated per test: a test that names a label in the catalog overrides this, and
		// `mockClear` keeps an implementation, so the default has to be put back explicitly.
		vi.mocked(t).mockImplementation((_app, key, vars) => (vars ? `${key} ${Object.values(vars).join(' ')}` : key))
		setActivePinia(createPinia())
		requestMock.mockResolvedValue(ocsResponse(PROJECTION))
	})

	describe('dashboardApi service', () => {
		it('reads the contract path and unwraps the OCS envelope', async () => {
			const projection = await fetchDashboard()
			expect(requestedUrl()).toBe('/ocs/v2.php/apps/pettycash/api/v1/dashboard')
			expect(projection).toEqual(PROJECTION)
		})

		it('sends no command identifier for a read (FR-042 has nothing to replay)', async () => {
			await fetchDashboard()
			expect(requestedHeaders()['X-PettyCash-Command-ID']).toBeUndefined()
			expect(requestedHeaders()['OCS-APIRequest']).toBe('true')
		})
	})

	describe('metric derivation', () => {
		it('formats a count with the Persian locale digits', () => {
			expect(metric(dashboardMetrics({ openSheets: 1250 }), 'openSheets')).toEqual({
				key: 'openSheets',
				kind: 'count',
				text: '۱٬۲۵۰'
			})
		})

		it('formats an amount at the currency scale the payload carries, not a fixed divisor', () => {
			const rial = metric(dashboardMetrics({ balance: { amountMinor: 1250000, currency: 'IRR', minorUnit: 0 } }), 'balance')
			expect(rial.kind).toBe('money')
			expect(rial.text).toBe('۱٬۲۵۰٬۰۰۰ IRR')

			const dollars = metric(dashboardMetrics({ balance: { amountMinor: 123456, currency: 'USD', minorUnit: 2 } }), 'balance')
			expect(dollars.text).toBe('۱٬۲۳۴٫۵۶ USD')
		})

		it('reads a bare number as a count so an amount scale is never guessed', () => {
			expect(isMoney(1250000)).toBe(false)
			expect(isMoney({ amountMinor: 100, currency: 'IRR' })).toBe(false)
			expect(isMoney({ amountMinor: 100, currency: 'IRR', minorUnit: 0 })).toBe(true)
		})

		it('keeps a boolean for the catalog to name rather than printing true or false', () => {
			expect(metric(dashboardMetrics({ requiresEvidence: true }), 'requiresEvidence')).toEqual({
				key: 'requiresEvidence',
				kind: 'boolean',
				text: '',
				boolean: true
			})
		})

		it('marks a value the projection did not supply instead of rendering a blank', () => {
			expect(metric(dashboardMetrics({ movements: null }), 'movements').text).toBe('—')
		})

		it('shows a structured value rather than dropping it', () => {
			const raw = metric(dashboardMetrics({ recentActivity: [{ id: 'a' }] }), 'recentActivity')
			expect(raw.kind).toBe('raw')
			expect(raw.text).toBe('[{"id":"a"}]')
		})

		it('keeps the order the projection sent the entries in', () => {
			expect(dashboardMetrics({ queueItems: 1, decisions: 2, balance: { amountMinor: 0, currency: 'IRR', minorUnit: 0 } })
				.map((entry) => entry.key))
				.toEqual(['queueItems', 'decisions', 'balance'])
		})
	})

	describe('dashboard store', () => {
		it('projects the buckets into cards in the contract order', async () => {
			setSessionRoles('PURCHASER', 'COST_CENTER_MANAGER', 'FINANCE')
			const store = useDashboardStore()
			await store.loadDashboard()
			expect(store.cards.map((card) => card.role)).toEqual(['purchaser', 'costCenter', 'finance'])
			expect(store.error).toBeNull()
			expect(store.isEmpty).toBe(false)
		})

		it('leaves out a bucket the session does not hold, without claiming to enforce scope (FR-061)', async () => {
			setSessionRoles('FINANCE')
			const store = useDashboardStore()
			await store.loadDashboard()
			expect(store.cards.map((card) => card.role)).toEqual(['finance'])
			expect(store.buckets.purchaser).toEqual(PROJECTION.purchaser)
		})

		it('presents every role a multi-role user holds', async () => {
			setSessionRoles('SENIOR_MANAGER', 'ADMINISTRATOR')
			const store = useDashboardStore()
			await store.loadDashboard()
			expect(store.sessionCards).toEqual(['senior', 'admin'])
			expect(store.cards.map((card) => card.role)).toEqual(['senior', 'admin'])
		})

		it('does not second-guess the server while the session is unknown', async () => {
			const store = useDashboardStore()
			expect(useAuthStore().isAuthenticated).toBe(false)
			await store.loadDashboard()
			expect(store.cards.map((card) => card.role)).toEqual(['purchaser', 'costCenter', 'senior', 'finance', 'admin'])
		})

		it('ignores a bucket that is not an object instead of rendering it as a card', async () => {
			requestMock.mockResolvedValue(ocsResponse({ finance: 'unavailable' }))
			setSessionRoles('FINANCE')
			const store = useDashboardStore()
			await store.loadDashboard()
			expect(store.cards).toEqual([])
			expect(store.isEmpty).toBe(true)
		})

		it('records a failure and drops the previous cards instead of showing stale counts', async () => {
			setSessionRoles('PURCHASER')
			const store = useDashboardStore()
			await store.loadDashboard()
			expect(store.cards).toHaveLength(1)

			requestMock.mockRejectedValueOnce({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'dashboard failed' } } } } }
			})
			await expect(store.loadDashboard()).rejects.toThrow('dashboard failed')
			expect(store.error).toBe('dashboard failed')
			expect(store.cards).toEqual([])
			expect(store.loaded).toBe(false)
		})

		it('does not retry a failed read on its own (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 503, data: { ocs: { data: { error: { code: 'SERVER', message: 'unavailable' } } } } }
			})
			const store = useDashboardStore()
			await expect(store.loadDashboard()).rejects.toThrow('unavailable')
			expect(requestMock).toHaveBeenCalledTimes(1)
		})
	})

	describe('DashboardCard', () => {
		it('names the role and every metric through the catalog, keyed for the test hooks', () => {
			vi.mocked(t).mockImplementation((_app, key) => {
				const catalog: Record<string, string> = {
					'dashboard.role.finance': 'کارهای مالی',
					'dashboard.metric.queueItems': 'موارد صف'
				}
				return catalog[key] ?? key
			})
			const wrapper = mount(DashboardCard, {
				props: { role: 'finance', metrics: dashboardMetrics({ queueItems: 9 }) }
			})
			expect(wrapper.find('h3').text()).toBe('کارهای مالی')
			expect(wrapper.find('dt').text()).toBe('موارد صف')
			expect(wrapper.find('[data-testid="dashboard-value-queueItems"]').text()).toBe('۹')
			expect(wrapper.find('[data-testid="dashboard-metric-queueItems"]').attributes('data-metric-kind')).toBe('count')
		})

		it('keeps the contract key when the catalog does not name it yet (T119 owns the catalog)', () => {
			const wrapper = mount(DashboardCard, {
				props: { role: 'finance', metrics: dashboardMetrics({ queueItems: 9 }) }
			})
			expect(wrapper.find('h3').text()).toBe('finance')
			expect(wrapper.find('dt').text()).toBe('queueItems')
			expect(wrapper.find('[data-testid="dashboard-value-queueItems"]').text()).toBe('۹')
		})

		it('resolves a boolean through the catalog instead of printing it', () => {
			const wrapper = mount(DashboardCard, {
				props: { role: 'admin', metrics: dashboardMetrics({ hasPendingReview: false }) }
			})
			expect(wrapper.find('[data-testid="dashboard-value-hasPendingReview"]').text()).toBe('common.no')
		})

		it('states that a role has nothing to do rather than rendering an empty card', () => {
			const wrapper = mount(DashboardCard, { props: { role: 'senior', metrics: [] } })
			expect(wrapper.find('[data-testid="dashboard-card-empty"]').text()).toBe('dashboard.empty')
			expect(wrapper.find('dl').exists()).toBe(false)
		})

		it('keeps an amount at the scale the projection sent (FR-061 balances)', () => {
			const wrapper = mount(DashboardCard, {
				props: { role: 'purchaser', metrics: dashboardMetrics({ balance: { amountMinor: 45000, currency: 'IRR', minorUnit: 0 } }) }
			})
			expect(wrapper.find('[data-testid="dashboard-value-balance"]').text()).toBe('۴۵٬۰۰۰ IRR')
		})
	})

	describe('DashboardView', () => {
		function mountView() {
			const pinia = createPinia()
			setActivePinia(pinia)
			return mount(DashboardView, { global: { plugins: [pinia] } })
		}

		it('shows the loading state until the projection answers', async () => {
			let resolve: (value: unknown) => void = () => undefined
			requestMock.mockReturnValue(new Promise((r) => { resolve = r }))

			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="dashboard-loading"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="dashboard-card-purchaser"]').exists()).toBe(false)

			resolve(ocsResponse(PROJECTION))
			await flushPromises()
			expect(wrapper.find('[data-testid="dashboard-loading"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="dashboard-card-purchaser"]').exists()).toBe(true)
		})

		it('renders one card per bucket the projection sent', async () => {
			const wrapper = mountView()
			await flushPromises()
			expect(requestedUrl()).toContain('/dashboard')
			const cards = wrapper.findAll('[data-testid^="dashboard-card-"]')
			expect(cards.map((card) => card.attributes('data-testid'))).toEqual([
				'dashboard-card-purchaser',
				'dashboard-card-costCenter',
				'dashboard-card-senior',
				'dashboard-card-finance',
				'dashboard-card-admin'
			])
			expect(wrapper.find('[data-testid="dashboard-value-queueItems"]').text()).toBe('۷')
		})

		it('presents the empty state when the projection carries no bucket', async () => {
			requestMock.mockResolvedValue(ocsResponse({}))
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="dashboard-empty"]').exists()).toBe(true)
			expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(false)
		})

		it('shows the failure with a retry the user must trigger (FR-075)', async () => {
			requestMock.mockRejectedValue({
				isAxiosError: true,
				response: { status: 500, data: { ocs: { data: { error: { code: 'SERVER', message: 'no projection' } } } } }
			})
			const wrapper = mountView()
			await flushPromises()
			expect(wrapper.find('[data-testid="dashboard-error"]').text()).toContain('no projection')
			expect(requestMock).toHaveBeenCalledTimes(1)

			requestMock.mockResolvedValue(ocsResponse(PROJECTION))
			await wrapper.find('[data-testid="dashboard-retry"]').trigger('click')
			await flushPromises()
			expect(requestMock).toHaveBeenCalledTimes(2)
			expect(wrapper.find('[data-testid="dashboard-error"]').exists()).toBe(false)
			expect(wrapper.find('[data-testid="dashboard-card-purchaser"]').exists()).toBe(true)
		})
	})

	/**
	 * FR-071 and FR-072, checked where they are written.
	 *
	 * jsdom has no layout engine and no font metrics: a horizontal overflow at 390px, a rendered
	 * glyph size, and a contrast ratio over the *rendered* theme cannot be decided here, and the
	 * browser matrix (T122) and the manual matrix (T123) own those. What can be decided is that the
	 * declarations do not themselves break the requirement — the direction is right, a mobile
	 * breakpoint reaches 390px, nothing forces the viewport wider than 390px, no text size falls
	 * under the 12pt floor, and every text colour this slice declares with a fallback pair meets WCAG
	 * AA against the background it sits on. The same substitutes T283 and T286 adopted.
	 */
	describe('FR-071/FR-072 declaration gates', () => {
		it('reads the declarations it intends to check', () => {
			const scoped = SOURCES.filter((entry) => entry.source.includes('<style'))
			expect(scoped.length).toBeGreaterThan(0)
		})

		it('declares the right-to-left direction on every rendered surface', () => {
			for (const { file, source } of SOURCES.filter((entry) => entry.file.endsWith('.vue'))) {
				expect(source, `${file} must render right-to-left`).toContain('dir="rtl"')
			}
		})

		it('declares a mobile breakpoint that reaches the 390px viewport on the page surface', () => {
			// The requirement is a property of the page, not of every component: a card that reflows
			// on a wrapping flex row needs no breakpoint of its own, and requiring one would push an
			// empty media query into every file. The view is the surface the viewport applies to.
			for (const { file, source } of SOURCES.filter((entry) => entry.file.endsWith('DashboardView.vue'))) {
				const breakpoints = [...source.matchAll(/(?:max-width|width\s*<=)\s*:?\s*(\d+)px/g)].map((m) => Number(m[1]))
				expect(breakpoints.length, `${file} declares no mobile breakpoint`).toBeGreaterThan(0)
				expect(Math.max(...breakpoints), `${file} breakpoint never reaches 390px`).toBeGreaterThanOrEqual(390)
			}
		})

		it('declares no fixed or minimum width that would force a 390px viewport to scroll sideways', () => {
			for (const { file, source } of SOURCES) {
				const widths = [...source.matchAll(/(?:^|[;{\s])(?:min-)?(?:width|inline-size)\s*:\s*([\d.]+)(px|rem)/g)]
				for (const [, value, unit] of widths) {
					const px = unit === 'rem' ? Number(value) * 16 : Number(value)
					expect(px, `${file} declares width: ${value}${unit}`).toBeLessThanOrEqual(390)
				}
			}
		})

		it('declares no text size below the 12pt (16px) floor', () => {
			for (const { file, source } of SOURCES) {
				const sizes = [...source.matchAll(/font-size\s*:\s*([\d.]+)(px|rem)/g)]
				for (const [, value, unit] of sizes) {
					const px = unit === 'rem' ? Number(value) * 16 : Number(value)
					expect(px, `${file} declares font-size: ${value}${unit}`).toBeGreaterThanOrEqual(16)
				}
			}
		})

		it('keeps a status readable as text and not by colour alone', () => {
			// WCAG 1.4.1: the card states its role in a heading and every metric keeps a text label, so
			// removing every colour from these components loses no information.
			const wrapper = mount(DashboardCard, {
				props: { role: 'finance', metrics: dashboardMetrics({ queueItems: 9, balance: { amountMinor: 5, currency: 'IRR', minorUnit: 0 } }) }
			})
			expect(wrapper.find('h3').text()).not.toBe('')
			expect(wrapper.findAll('dt').map((label) => label.text())).toEqual(['queueItems', 'balance'])
			expect(wrapper.attributes('aria-labelledby')).toBe('dashboard-card-title-finance')
		})
	})

	describe('WCAG AA contrast of the declared colour fallbacks', () => {
		/**
		 * WCAG 2.x relative luminance and contrast ratio, over the hex fallbacks the components
		 * declare behind the Nextcloud theme variables. The theme variable is what a real instance
		 * renders; the fallback is what renders when the variable is absent, so the fallback is what
		 * has to pass on its own.
		 */
		function luminance(hex: string): number {
			const channel = (value: number) => {
				const srgb = value / 255
				return srgb <= 0.03928 ? srgb / 12.92 : ((srgb + 0.055) / 1.055) ** 2.4
			}
			// Stylelint's `color-hex-length` shortens `#aabbcc` to `#abc`, so both spellings are read
			// and the short one is expanded before it is measured.
			const full = /^#[0-9a-f]{3}$/i.test(hex)
				? `#${(hex[1] ?? '')}${(hex[1] ?? '')}${(hex[2] ?? '')}${(hex[2] ?? '')}${(hex[3] ?? '')}${(hex[3] ?? '')}`
				: hex
			const parts = [1, 3, 5].map((offset) => channel(Number.parseInt(full.slice(offset, offset + 2), 16) || 0))
			return 0.2126 * (parts[0] ?? 0) + 0.7152 * (parts[1] ?? 0) + 0.0722 * (parts[2] ?? 0)
		}

		function contrast(foreground: string, background: string): number {
			const a = luminance(foreground)
			const b = luminance(background)
			return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05)
		}
		it('rejects a pair it should reject, so the check is not vacuous', () => {
			expect(contrast('#cccccc', '#ffffff')).toBeLessThan(4.5)
		})

		/**
		 * Every text colour the new components declare, paired with the background it actually sits
		 * on: the rule's own `background-color` when it declares one, otherwise the surface the rule
		 * renders inside.
		 *
		 * The pairs are read out of the sources rather than restated here. A gate that lists the
		 * colours it expects proves only that the literals in the test still hold — changing a label
		 * to an AA-failing grey in the component would leave it green, which is the same
		 * "asserting something the source never promised" defect T286 catalogued.
		 */
		it('meets AA for every text colour the new components declare', () => {
			const surfaces = SOURCES.filter((entry) => entry.file.endsWith('.vue'))
			expect(surfaces.length, 'no rendered surface to check').toBeGreaterThan(0)

			const violations: string[] = []
			let checked = 0

			for (const { file, source } of surfaces) {
				const surface = /background-color\s*:\s*var\([^,]+,\s*(#[0-9a-f]{3,6})\)/i.exec(source)?.[1] ?? '#ffffff'
				for (const match of source.matchAll(/\{([^{}]*)\}/g)) {
					const body = match[1] ?? ''
					const foreground = /(?:^|[;{\s])color\s*:\s*var\([^,]+,\s*(#[0-9a-f]{3,6})\)/i.exec(body)?.[1]
					if (!foreground) continue
					const background = /background(?:-color)?\s*:\s*var\([^,]+,\s*(#[0-9a-f]{3,6})\)/i.exec(body)?.[1] ?? surface
					checked += 1
					const ratio = contrast(foreground, background)
					if (ratio < 4.5) {
						violations.push(`${file}: ${foreground} on ${background} = ${ratio.toFixed(2)}:1`)
					}
				}
			}

			expect(checked, 'no declared text colour was checked').toBeGreaterThan(0)
			expect(violations).toEqual([])
		})
	})
})
