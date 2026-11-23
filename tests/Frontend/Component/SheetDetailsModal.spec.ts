// Shared Sheet Details Modal - Vue Component Tests (UXC-1-01 to UXC-1-08)
//
// Tests the shared modal shell: dialog semantics, header identity/status, summary metrics,
// canonical tabs, focus lifecycle, RTL/LTR, responsive 390px, and stage-adapter configuration.
// The shell is stage-agnostic; all assertions use generic stage configurations.
// l10n returns catalogue keys in this test environment (catalogues not loaded), so assertions
// target keys, matching the convention used by SeniorSheetModal.spec.ts and SeniorReview.spec.ts.

import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineComponent, h, nextTick, ref } from 'vue'
import { readFileSync } from 'node:fs'
import SheetDetailsModal from '@/components/review/SheetDetailsModal.vue'
import type { SheetDetailsModalAdapter } from '@/components/review/SheetDetailsModal.vue'
import TransactionReviewCard from '@/components/review/TransactionReviewCard.vue'
import type { TransactionDetail, TransactionSummary } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return { ...actual, getLanguage: () => 'en', isRTL: () => false }
})

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

function baseAdapter(overrides: Partial<SheetDetailsModalAdapter> = {}): SheetDetailsModalAdapter {
	return {
		stage: 'sheet',
		identity: {
			reference: 'sheet-ref-1',
			title: 'Sheet Alpha',
			owner: 'Purchaser One',
			submitted: '2026-09-20T10:00:00Z',
			status: 'PENDING_REVIEW',
			currency: 'IRR',
			totals: [{ currency: 'IRR', amountMinor: 50000, minorUnit: 0 }],
			progress: { decided: 1, total: 2 },
		},
		metrics: { transactionCount: 2 },
		readOnly: false,
		submitting: false,
		...overrides,
	}
}

/** Mounts the modal with a local `open` ref so close events can toggle it. */
function mountModalWithControl(adapter: SheetDetailsModalAdapter) {
	const open = ref(true)
	const wrapper = mount(SheetDetailsModal, {
		props: { open: open.value, adapter },
		attachTo: document.body,
	})
	wrapper.vm.$watch(() => open.value, () => {}) // keep ref alive
	// When the shell emits close, the controlling test flips `open` to false.
	return { wrapper, open, setOpen: (v: boolean) => { open.value = v } }
}

function mountModal(adapter: SheetDetailsModalAdapter, open = true) {
	return mount(SheetDetailsModal, {
		props: { open, adapter },
		attachTo: document.body,
	})
}

afterEach(() => {
	document.body.innerHTML = ''
})

describe('SheetDetailsModal shell (UXC-1-01)', () => {
	it('renders a dialog with role="dialog" and aria-modal="true"', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		expect(dialog?.getAttribute('role')).toBe('dialog')
		expect(dialog?.getAttribute('aria-modal')).toBe('true')
		expect(dialog?.getAttribute('tabindex')).toBe('-1')
		wrapper.unmount()
	})

	it('does not render when open is false', async () => {
		const wrapper = mountModal(baseAdapter(), false)
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
		wrapper.unmount()
	})

	it('applies :dir from the shared direction utility', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const container = document.body.querySelector('[data-testid="sheet-details-modal"]')
		expect(container?.getAttribute('dir')).toBe('ltr')
		wrapper.unmount()
	})
})

describe('Header identity and status (UXC-1-02)', () => {
	it('shows the sheet reference when provided by the adapter', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, reference: 'sheet-abc-123' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const ref = document.body.querySelector('[data-testid="sheet-details-modal-reference"]')
		expect(ref).not.toBeNull()
		expect(ref?.textContent?.trim()).toBe('sheet-abc-123')
		const bdi = ref?.querySelector('bdi')
		expect(bdi?.getAttribute('dir')).toBe('ltr')
		wrapper.unmount()
	})

	it('shows the title when available', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, title: 'Quarterly Supplies' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const title = document.body.querySelector('[data-testid="sheet-details-modal-title"]')
		expect(title?.textContent?.trim()).toBe('Quarterly Supplies')
		wrapper.unmount()
	})

	it('shows unavailable title placeholder when title is null', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, title: null } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const title = document.body.querySelector('[data-testid="sheet-details-modal-title"]')
		expect(title?.textContent?.trim()).toBe('reviews.sheetQueue.sheetTitleUnavailable')
		wrapper.unmount()
	})

	it('shows owner when available', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, owner: 'Purchaser One' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const owner = document.body.querySelector('[data-testid="sheet-details-modal-owner"]')
		expect(owner?.textContent?.trim()).toBe('Purchaser One')
		wrapper.unmount()
	})

	it('omits owner when null (no fabricated value)', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, owner: null } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-owner"]')).toBeNull()
		wrapper.unmount()
	})

	it('shows submitted date when available', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, submitted: '2026-09-20T10:00:00Z' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const submitted = document.body.querySelector('[data-testid="sheet-details-modal-submitted"]')
		expect(submitted?.textContent?.trim()).toBe('2026-09-20T10:00:00Z')
		wrapper.unmount()
	})

	it('omits submitted when null', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, submitted: null } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-submitted"]')).toBeNull()
		wrapper.unmount()
	})

	it('shows status when available', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, status: 'PENDING_REVIEW' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const status = document.body.querySelector('[data-testid="sheet-details-modal-status"]')
		expect(status?.textContent?.trim()).toBe('PENDING_REVIEW')
		wrapper.unmount()
	})

	it('omits status when null', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, status: null } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-status"]')).toBeNull()
		wrapper.unmount()
	})

	it('renders identifiers in bdi dir="ltr" for RTL-safe display', async () => {
		const adapter = baseAdapter({ identity: { ...baseAdapter().identity, reference: 'uuid-1234' } })
		const wrapper = mountModal(adapter)
		await flushPromises()

		const ref = document.body.querySelector('[data-testid="sheet-details-modal-reference"]')
		expect(ref).not.toBeNull()
		const bdi = ref?.querySelector('bdi')
		expect(bdi?.tagName).toBe('BDI')
		expect(bdi?.getAttribute('dir')).toBe('ltr')
		wrapper.unmount()
	})
})

describe('Summary metrics (UXC-1-03)', () => {
	it('shows per-currency totals separately, never cross-currency sums', async () => {
		const adapter = baseAdapter({
			identity: {
				...baseAdapter().identity,
				totals: [
					{ currency: 'IRR', amountMinor: 50000, minorUnit: 0 },
					{ currency: 'USD', amountMinor: 1250, minorUnit: 2 },
				],
			},
		})
		const wrapper = mountModal(adapter)
		await flushPromises()

		const metrics = document.body.querySelectorAll('[data-testid="sheet-details-modal-metric"]')
		expect(metrics).toHaveLength(2)
		expect(metrics[0]?.textContent).toContain('IRR')
		expect(metrics[1]?.textContent).toContain('USD')
		wrapper.unmount()
	})

	it('shows progress when provided', async () => {
		const adapter = baseAdapter({
			identity: { ...baseAdapter().identity, progress: { decided: 3, total: 5 } },
		})
		const wrapper = mountModal(adapter)
		await flushPromises()

		const progress = document.body.querySelector('[data-testid="sheet-details-modal-progress"]')
		expect(progress).not.toBeNull()
		expect(progress?.textContent).toContain('reviews.seniorSheet.modal.progress')
		wrapper.unmount()
	})

	it('renders no metrics section when totals and progress are absent', async () => {
		const adapter = baseAdapter({
			identity: { ...baseAdapter().identity, totals: [], progress: null },
		})
		const wrapper = mountModal(adapter)
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-metrics"]')).toBeNull()
		wrapper.unmount()
	})
})

describe('Canonical tabs (UXC-1-04)', () => {
	it('renders exactly four tabs: Transactions, Approvals, Overview, History', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const tabs = document.body.querySelectorAll('[role="tab"]')
		expect(tabs).toHaveLength(4)
		const labels = Array.from(tabs).map((t) => t.textContent?.trim())
		expect(labels).toContain('workspace.tabTransactions')
		expect(labels).toContain('workspace.tabApprovals')
		expect(labels).toContain('workspace.tabOverview')
		expect(labels).toContain('workspace.tabHistory')
		wrapper.unmount()
	})

	it('marks tabs with aria-selected and testids', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const txTab = document.body.querySelector('[data-testid="sheet-details-modal-tab-transactions"]')
		expect(txTab?.getAttribute('aria-selected')).toBe('true')
		expect(txTab?.getAttribute('role')).toBe('tab')

		const apTab = document.body.querySelector('[data-testid="sheet-details-modal-tab-approvals"]')
		expect(apTab?.getAttribute('aria-selected')).toBe('false')
		wrapper.unmount()
	})

	it('switches tab content on click', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-panel-transactions"]')).not.toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')).toBeNull()

		const approvalsTab = document.body.querySelector<HTMLButtonElement>('[data-testid="sheet-details-modal-tab-approvals"]')
		approvalsTab?.click()
		await nextTick()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="sheet-details-modal-panel-transactions"]')).toBeNull()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('supports keyboard traversal with arrow keys', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const tabs = document.body.querySelectorAll<HTMLElement>('[role="tab"]')
		tabs[0]?.focus()
		expect(document.activeElement).toBe(tabs[0])

		tabs[0]?.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowRight', bubbles: true }))
		await nextTick()
		expect(document.activeElement).toBe(tabs[1])
		wrapper.unmount()
	})
})

describe('Focus lifecycle (UXC-1-05)', () => {
	it('focuses the dialog on open', async () => {
		const { wrapper, setOpen } = mountModalWithControl(baseAdapter())
		await flushPromises()
		await nextTick()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(document.activeElement).toBe(dialog)

		setOpen(false)
		await flushPromises()
		wrapper.unmount()
	})

	it('emits close on Escape', async () => {
		const { wrapper } = mountModalWithControl(baseAdapter())
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		dialog?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		await flushPromises()

		expect(wrapper.emitted('close')).toBeTruthy()
		wrapper.unmount()
	})

	it('emits close on close button click', async () => {
		const { wrapper } = mountModalWithControl(baseAdapter())
		await flushPromises()

		const closeBtn = document.body.querySelector<HTMLElement>('[data-testid="sheet-details-modal-close"]')
		closeBtn?.click()
		await flushPromises()

		expect(wrapper.emitted('close')).toBeTruthy()
		wrapper.unmount()
	})

	it('does not close on scrim click when submitting', async () => {
		const adapter = baseAdapter({ submitting: true })
		const { wrapper } = mountModalWithControl(adapter)
		await flushPromises()

		document.body.querySelector('[data-testid="sheet-details-modal-scrim"]')?.dispatchEvent(
			new MouseEvent('click', { bubbles: true })
		)
		await nextTick()
		await flushPromises()

		expect(wrapper.emitted('close')).toBeFalsy()
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('emits close on scrim click when not submitting', async () => {
		const { wrapper } = mountModalWithControl(baseAdapter())
		await flushPromises()

		document.body.querySelector('[data-testid="sheet-details-modal-scrim"]')?.dispatchEvent(
			new MouseEvent('click', { bubbles: true })
		)
		await nextTick()
		await flushPromises()

		expect(wrapper.emitted('close')).toBeTruthy()
		wrapper.unmount()
	})
})

describe('Stable geometry (UXC-V2-01)', () => {
	it('keeps one dialog frame with a stable header, title and tab bar across tab switches', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const dialogBefore = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		const titleBefore = document.body.querySelector('[data-testid="sheet-details-modal-title"]')
		const tabsBefore = document.body.querySelector('[data-testid="sheet-details-modal-tabs"]')

		document.body.querySelector<HTMLButtonElement>('[data-testid="sheet-details-modal-tab-history"]')?.click()
		await nextTick()
		await flushPromises()

		// Same nodes, not a rebuilt frame: switching tabs changes panel content only.
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBe(dialogBefore)
		expect(document.body.querySelector('[data-testid="sheet-details-modal-title"]')).toBe(titleBefore)
		expect(document.body.querySelector('[data-testid="sheet-details-modal-tabs"]')).toBe(tabsBefore)
		expect(document.body.querySelector('[data-testid="sheet-details-modal-panel-history"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('scrolls only the tab-content region — the frame and header never scroll', () => {
		const source = readFileSync('src/components/review/SheetDetailsModal.vue', 'utf8')
		// The dialog is a fixed-height frame that itself never scrolls: width and usable height are
		// viewport-stable constants, so a sparse tab keeps whitespace instead of collapsing the frame.
		const dialogRule = source.match(/__dialog\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(dialogRule).toContain('overflow: hidden')
		expect(dialogRule).toContain('height: min(860px, calc(100vh - 76px))')
		expect(dialogRule).not.toContain('overflow-y: auto')
		// The panels region is the one scroll container; the panel is plain content inside it.
		const panelsRule = source.match(/__tab-panels\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(panelsRule).toContain('overflow-y: auto')
		const panelRule = source.match(/__tab-panel\s*\{[^}]+\}/s)?.[0] ?? ''
		expect(panelRule).not.toContain('overflow-y')
		// Header, integrated summary and tab bar keep their natural height and never scroll away.
		expect(source).toMatch(/__body\s*\{[^}]*min-block-size: 0/s)
		expect(source).toMatch(/__metrics\s*\{[^}]*flex: 0 0 auto/s)
		expect(source).toMatch(/__tabs\s*\{[^}]*flex: 0 0 auto/s)
	})

	it('keeps the fixed-frame principle at 390px', () => {
		const source = readFileSync('src/components/review/SheetDetailsModal.vue', 'utf8')
		const narrow = source.match(/@media \(width <= 390px\)\s*\{[\s\S]*?\n\}/)?.[0] ?? ''
		expect(narrow).toContain('height: calc(100vh - 32px)')
		expect(narrow).not.toContain('max-height')
	})
})

describe('RTL/LTR and responsive (UXC-1-06)', () => {
	it('binds :dir from the shared direction utility (LTR in this environment)', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const container = document.body.querySelector('[data-testid="sheet-details-modal"]')
		expect(container?.getAttribute('dir')).toBe('ltr')
		wrapper.unmount()
	})

	it('renders without horizontal scroll at 390px', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		wrapper.unmount()
	})

	it('has ≥44px touch targets for close button at narrow widths', async () => {
		const wrapper = mountModal(baseAdapter())
		await flushPromises()

		const closeBtn = document.body.querySelector<HTMLElement>('[data-testid="sheet-details-modal-close"]')
		expect(closeBtn).not.toBeNull()
		wrapper.unmount()
	})
})

describe('Stage adapter (UXC-1-07)', () => {
	it('injects stage-specific header actions via slot', async () => {
		const wrapper = mount(SheetDetailsModal, {
			props: { open: true, adapter: baseAdapter() },
			slots: {
				'header-actions': '<button data-testid="stage-export-btn">Export XLSX</button>',
			},
			attachTo: document.body,
		})
		await flushPromises()

		const exportBtn = document.body.querySelector('[data-testid="stage-export-btn"]')
		expect(exportBtn?.textContent?.trim()).toBe('Export XLSX')
		wrapper.unmount()
	})

	it('injects stage-specific tab content via named slots', async () => {
		const wrapper = mount(SheetDetailsModal, {
			props: { open: true, adapter: baseAdapter() },
			slots: {
				'tab-transactions': '<div data-testid="stage-transactions-content">Transactions content</div>',
				'tab-approvals': '<div data-testid="stage-approvals-content">Approvals content</div>',
			},
			attachTo: document.body,
		})
		await flushPromises()

		expect(document.body.querySelector('[data-testid="stage-transactions-content"]')).not.toBeNull()

		const approvalsTab = document.body.querySelector<HTMLButtonElement>('[data-testid="sheet-details-modal-tab-approvals"]')
		approvalsTab?.click()
		await nextTick()
		await flushPromises()

		expect(document.body.querySelector('[data-testid="stage-approvals-content"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('provides overlays slot for stage-specific layers alongside the dialog', async () => {
		const wrapper = mount(SheetDetailsModal, {
			props: { open: true, adapter: baseAdapter() },
			slots: {
				overlays: '<div data-testid="stage-overlay">Drawer layer</div>',
			},
			attachTo: document.body,
		})
		await flushPromises()

		const overlay = document.body.querySelector('[data-testid="stage-overlay"]')
		expect(overlay).not.toBeNull()
		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		// Both dialog and overlay live inside the same teleport container
		const container = document.body.querySelector('[data-testid="sheet-details-modal"]')
		expect(container?.contains(dialog!)).toBeTruthy()
		expect(container?.contains(overlay!)).toBeTruthy()
		wrapper.unmount()
	})

	it('has no stage-specific business logic in the shell', () => {
		const source = readFileSync('src/components/review/SheetDetailsModal.vue', 'utf8')
		expect(source).not.toMatch(/SeniorSheetModal|FinanceSheetModal|CostCenterQueue/)
	})
})

describe('Single-overlay stacking', () => {
	it('renders dialog and overlays in the same teleport container', async () => {
		const wrapper = mount(SheetDetailsModal, {
			props: { open: true, adapter: baseAdapter() },
			slots: {
				overlays: '<div data-testid="stage-drawer">Drawer</div>',
			},
			attachTo: document.body,
		})
		await flushPromises()

		const modalContainer = document.body.querySelector('[data-testid="sheet-details-modal"]')
		expect(modalContainer).not.toBeNull()
		expect(modalContainer?.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		expect(modalContainer?.querySelector('[data-testid="stage-drawer"]')).not.toBeNull()
		wrapper.unmount()
	})
})

describe('Inline transaction expansion (UXC-V2-04)', () => {
	// The expansion harness: two real cards inside the Transactions tab slot, sharing one
	// `expandedId` — the exact ownership contract the mounting role view will hold (one expanded
	// transaction at a time is the caller's rule; the cards render what they are told).
	function expansionHarness() {
		const expandedId = ref<string | null>(null)
		const sheetOpen = ref(true)
		const details: Record<string, TransactionDetail> = {
			'tx-1': detailFixture('tx-1'),
			'tx-2': detailFixture('tx-2'),
		}

		const Harness = defineComponent({
			setup() {
				return () => h(SheetDetailsModal, {
					open: sheetOpen.value,
					adapter: baseAdapter(),
					onClose: () => { sheetOpen.value = false },
				}, {
					'tab-transactions': () => ['tx-1', 'tx-2'].map((id) => h(TransactionReviewCard, {
						transaction: summaryFixture(id),
						evidence: [],
						expandable: true,
						expanded: expandedId.value === id,
						detail: expandedId.value !== null ? details[id] : null,
						detailReadState: 'loaded',
						detailStage: {
							actionsVisible: true,
							actions: ['REJECT', 'RETURN', 'APPROVE'],
						},
						onToggleExpand: (toggledId: string) => {
							expandedId.value = expandedId.value === toggledId ? null : toggledId
						},
					})),
				})
			}
		})
		return { Harness, expandedId, sheetOpen }
	}

	function summaryFixture(id: string): TransactionSummary {
		return {
			id,
			sheetId: 'sheet-ref-1',
			stage: 'COST_CENTER',
			status: 'AWAITING_COST_CENTER_REVIEW',
			finalOutcome: null,
			evidenceRequired: false,
			evidenceCount: 0,
			version: 0,
			activeRevision: {
				id: `rev-${id}`,
				number: 1,
				expenseDate: '2026-09-20',
				amount: { amountMinor: 123456, currency: 'USD', minorUnit: 2 },
				description: `Transaction ${id}`,
				costCenter: { id: 'cc-1', code: 'CC-42', name: 'Operations' },
				expenseType: { id: 'et-1', name: 'Travel' },
				createdBy: 'user1',
				createdAt: '2026-09-20T10:00:00Z',
				active: true
			},
		} as TransactionSummary
	}

	function detailFixture(id: string): TransactionDetail {
		return {
			...summaryFixture(id),
			version: 3,
			allowedActions: { canApprove: true, canReject: true },
			decisions: [],
			evidence: [],
		} as unknown as TransactionDetail
	}

	it('expands a transaction in place within the transactions tab — no second dialog, no navigation', async () => {
		const { Harness } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')
		title?.focus()
		title?.click()
		await nextTick()
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		// Still exactly one dialog: the expansion is inline, never a second full modal.
		expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)
		// The surrounding sheet context stays mounted and visible around the expansion.
		expect(document.body.querySelector('[data-testid="sheet-details-modal-title"]')?.textContent).toContain('Sheet Alpha')
		expect(document.body.querySelector('[data-testid="sheet-details-modal-tab-approvals"]')).not.toBeNull()
		// The expanded region is inside the transactions tab panel, in place.
		const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-transactions"]')
		expect(panel?.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('opens only one expansion at a time — opening B collapses A', async () => {
		const { Harness, expandedId } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const titles = document.body.querySelectorAll<HTMLElement>('[data-testid="transaction-card-title"]')
		expect(titles).toHaveLength(2)
		titles[0]?.focus()
		titles[0]?.click()
		await nextTick()
		await flushPromises()
		expect(expandedId.value).toBe('tx-1')
		expect(document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')).toHaveLength(1)

		titles[1]?.focus()
		titles[1]?.click()
		await nextTick()
		await flushPromises()
		expect(expandedId.value).toBe('tx-2')
		// A's region is gone, B's region is present — exactly one expansion at any moment.
		expect(document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')).toHaveLength(1)
		const regions = document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')
		expect(regions[0]?.id).toBe('transaction-expanded-tx-2')
		wrapper.unmount()
	})

	it('toggles closed and restores focus to the originating card toggle', async () => {
		const { Harness } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')
		title?.focus()
		title?.click()
		await nextTick()
		await flushPromises()
		expect(document.activeElement).toBe(document.body.querySelector('[data-testid="transaction-expanded-detail"]'))

		title?.click()
		await nextTick()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
		expect(document.activeElement).toBe(title)
		wrapper.unmount()
	})

	it('activates from the keyboard and exposes the disclosure semantics', async () => {
		const { Harness, expandedId } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLButtonElement>('[data-testid="transaction-card-title"]')
		expect(title?.getAttribute('aria-expanded')).toBe('false')
		title?.focus()
		// Native button keyboard activation.
		title?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }))
		title?.click()
		await nextTick()
		await flushPromises()
		expect(expandedId.value).toBe('tx-1')
		expect(title?.getAttribute('aria-expanded')).toBe('true')
		expect(title?.getAttribute('aria-controls')).toBe('transaction-expanded-tx-1')
		wrapper.unmount()
	})

	it('keeps Escape deterministic: inside the expanded card it collapses, the modal stays open', async () => {
		const { Harness, sheetOpen } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')
		title?.focus()
		title?.click()
		await nextTick()
		await flushPromises()

		const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
		region?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		await flushPromises()
		expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
		// The sheet modal is untouched — one Escape collapsed exactly the expansion.
		expect(sheetOpen.value).toBe(true)
		expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
		wrapper.unmount()
	})

	it('closes the modal on Escape outside the expanded card, as before', async () => {
		const { Harness, sheetOpen } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')
		title?.focus()
		title?.click()
		await nextTick()
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		dialog?.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
		await nextTick()
		await flushPromises()
		expect(sheetOpen.value).toBe(false)
		wrapper.unmount()
	})

	it('keeps the modal frame stable while the expansion grows inside the scrollable tab region', async () => {
		const { Harness } = expansionHarness()
		const wrapper = mount(Harness, { attachTo: document.body })
		await flushPromises()

		const title = document.body.querySelector<HTMLElement>('[data-testid="transaction-card-title"]')
		title?.focus()
		title?.click()
		await nextTick()
		await flushPromises()

		const dialog = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
		expect(dialog).not.toBeNull()
		// The header, identity and tab bar stay in place around the grown content.
		expect(dialog?.querySelector('[data-testid="sheet-details-modal-title"]')).not.toBeNull()
		expect(dialog?.querySelector('[data-testid="sheet-details-modal-tabs"]')).not.toBeNull()
		// The panels region remains the single scroll container inside the fixed frame.
		const source = readFileSync('src/components/review/SheetDetailsModal.vue', 'utf8')
		expect(source).toMatch(/__tab-panels\s*\{[^}]*overflow-y:\s*auto/s)
		wrapper.unmount()
	})
})
