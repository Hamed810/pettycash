// FR-086's interface affordance — the delete control on the sheet list and the sheet detail.
//
// T423, US1/AC8 and US1/AC8a. US1 is a P1 journey and FR-086 (clarified 2026-10-08) settled that the
// capability must be reachable from the interface, on **both** surfaces, behind a confirmation that names the
// sheet, inside FR-071's accessibility floor.
//
// ## What is deliberately asserted here, and what is not
//
// The **cascade** — transactions, revisions and stored evidence files — is server-side and is proven by
// `tests/Contract/Controller/DraftSheetDeletionTest.php`. Repeating it through the UI would assert the same
// rows a second time at four times the cost. This file covers what only the interface can be wrong about:
//
// - the control exists on both surfaces, and is **absent** where deletion is refused;
// - the confirmation **names** the sheet and states that the deletion is permanent;
// - cancelling deletes nothing and returns focus to the control that opened the dialog;
// - confirming issues **one** DELETE carrying the sheet's version and the command id, and reloads the list.
//
// ## Why `@nextcloud/vue` is mocked rather than rendered
//
// The same mechanical reason recorded in `TransactionCorrection.spec.ts`: `vitest.config.ts` sets
// `ssr.noExternal: ['@nextcloud/vue']`, so the real package loads and pulls `.css` assets Node cannot
// transform, and the file dies with `Unknown file extension ".css"` before a single assertion runs.
//
// The `NcDialog` stub renders on `open` rather than `modelValue`, because `DeleteSheetDialog` drives it with the
// `open` prop and emits `update:open`. A stub keyed on the wrong prop would render nothing and make the whole
// file pass vacuously.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import SheetListView from '@/views/sheets/SheetListView.vue'
import SheetDetailView from '@/views/sheets/SheetDetailView.vue'
import { useSheetStore } from '@/stores/sheets'
import { routes } from '@/router'
import type { SheetSummary, SheetDetail, SheetStatus } from '@/types/api'

const ocsRequest = vi.hoisted(() => vi.fn())

vi.mock('@nextcloud/axios', () => ({
	default: {
		request: (...args: unknown[]) => ocsRequest(...args),
		isAxiosError: (error: unknown) =>
			Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
	}
}))

vi.mock('@nextcloud/router', () => ({
	generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
	imagePath: (path: string) => `/apps/pettycash/${path}`,
	appName: 'pettycash',
	appVersion: '1.0.0'
}))

/**
 * `getLanguage`, `isRTL` and `imagePath` are required in addition to `t`: `vitest.config.ts` sets
 * `ssr.noExternal: ['@nextcloud/vue']`, so the real package's chunks are still evaluated, and they call these
 * at module scope. **Both were found by running** — a first mock with only `t`/`n` failed to load the file at
 * all, and a second with `getLanguage` failed on `isRTL`, each before a single assertion ran. The shape is
 * copied from `PurchaserFlow.spec.ts`, which already carries the full set.
 *
 * `t` returns the **message**, not the app id: a mock returning its first argument renders every string as the
 * literal `pettycash`, which makes an assertion about which message a control carries indistinguishable from an
 * assertion about any message.
 */
vi.mock('@nextcloud/l10n', () => ({
	t: vi.fn((_app: string, text: string, params?: Record<string, string | number>) =>
		text.replace(/\{(\w+)\}/g, (_match, key: string) => String(params?.[key] ?? `{${key}}`))),
	n: vi.fn((_app: string, text: string) => text),
	getLanguage: vi.fn(() => 'fa'),
	isRTL: vi.fn(() => true),
	imagePath: vi.fn((path: string) => `/apps/pettycash/${path}`)
}))

vi.mock('@nextcloud/vue', () => ({
	NcButton: {
		name: 'NcButton',
		props: ['variant', 'disabled', 'loading', 'type', 'size'],
		emits: ['click'],
		template:
			'<button :type="type" :disabled="disabled" :class="[variant, size]" v-bind="$attrs" ' +
			'@click="$emit(\'click\', $event)"><slot /></button>'
	},
	NcDialog: {
		name: 'NcDialog',
		props: ['name', 'size', 'open', 'canClose'],
		emits: ['update:open'],
		slots: ['default'],
		template:
			'<div v-if="open" class="nc-dialog" role="dialog" data-test="nc-dialog"><slot /></div>'
	},
	NcNoteCard: {
		name: 'NcNoteCard',
		props: ['heading'],
		slots: ['header', 'default'],
		template:
			'<div data-test="nc-note-card"><div v-if="$slots.header"><slot name="header" /></div><slot /></div>'
	},
	NcChip: {
		name: 'NcChip',
		props: ['color', 'size', 'variant'],
		template: '<span data-test="nc-chip"><slot /></span>'
	},
	NcIconSvgWrapper: {
		name: 'NcIconSvgWrapper',
		props: ['path', 'size', 'color'],
		template: '<span data-test="nc-icon" />'
	},
	NcBreadcrumbs: {
		name: 'NcBreadcrumbs',
		props: [],
		slots: ['default'],
		template: '<nav data-test="nc-breadcrumbs"><slot /></nav>'
	},
	NcSelect: {
		name: 'NcSelect',
		props: ['modelValue', 'options', 'label'],
		emits: ['update:modelValue'],
		template: '<select data-test="nc-select"><option v-for="o in options" :key="o.value">{{ o.label }}</option></select>'
	},
	NcTextField: {
		name: 'NcTextField',
		props: ['modelValue', 'label'],
		emits: ['update:modelValue'],
		template: '<input data-test="nc-text-field" />'
	}
}))

/**
 * `ResponsiveTable` is stubbed to render the `cell(row, column)` function the view supplies, which is where the
 * delete control is produced — it is a render function, not template markup.
 *
 * **Two traps here, both found by running.**
 *
 * The prop is named `cell`, **not** `listCell`: `SheetListView` passes `:cell="openSheetCell"` for the
 * open-sheets region and `:cell="listCell"` for the main table. A stub reading the wrong prop name renders
 * nothing and makes every assertion in this file pass vacuously.
 *
 * And the cell function must be **called directly**, not bound with `<component :is="cell">`: a functional
 * component receives its props as a single object, so `<component :is>` handed `listCell` one argument —
 * `{item, column}` — while the signature takes two. `item` came through `undefined`, every cell rendered
 * empty, and the four "offers the control" cases failed while the two "does not offer" cases passed
 * **vacuously**. Hence the `setup()` + `h()` below, which calls `props.cell(row, column)` with two arguments.
 */
vi.mock('@/components/shared/ResponsiveTable.vue', async () => {
	const { h } = await import('vue')
	return {
		default: {
			name: 'ResponsiveTable',
			props: ['columns', 'rows', 'cell'],
			setup(props: Record<string, any>) {
				return () =>
					h(
						'div',
						{ 'data-test': 'responsive-table' },
						(props.rows ?? []).flatMap((row: unknown) =>
							(props.columns ?? []).map((column: { key: string }) =>
								h('div', { key: `${String((row as { id?: string })?.id)}-${column.key}`, 'data-test': `cell-${column.key}` }, [
									props.cell(row, column)
								])
							)
						)
					)
			}
		}
	}
})

const OPEN_SHEET: SheetSummary = {
	id: 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
	title: 'March fuel receipts',
	ownerDisplayName: 'Purchaser One',
	ownerId: 'purchaser-1',
	status: 'OPEN',
	currency: 'IRR',
	transactionCount: 2,
	activeRevisionNumber: 1,
	createdAt: '2026-03-01T00:00:00+00:00',
	updatedAt: '2026-03-02T00:00:00+00:00',
	submittedAt: null,
	completedAt: null,
	version: 7,
	totals: {
		current: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
		submitted: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectiveApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectiveRejected: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
		effectivePending: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 }
	}
} as unknown as SheetSummary

/**
 * `status` is annotated rather than left to be inferred: a plain object literal widens the field to `string`,
 * which `SheetSummary` rejects, and the cast is confined here rather than spread across the fixtures.
 */
const SUBMITTED_SHEET: SheetSummary = {
	...OPEN_SHEET,
	id: 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
	status: 'SUBMITTED' as SheetStatus,
	submittedAt: '2026-03-03T00:00:00+00:00'
}

function detailOf(sheet: SheetSummary): SheetDetail {
	return {
		...sheet,
		transactions: [],
		allowedActions: {}
	} as unknown as SheetDetail
}

/** Answer the list request with the given sheets, and everything else with a `SheetDetail`. */
function stubTransport(sheets: SheetSummary[]) {
	ocsRequest.mockImplementation((config: { url?: string; method?: string }) => {
		const url = String(config?.url ?? '')
		const method = String(config?.method ?? 'get').toUpperCase()

		if (method === 'DELETE') return Promise.resolve({ data: { ocs: { data: [] } } })

		if (url.includes('/sheets?')) {
			return Promise.resolve({
				data: { ocs: { data: { items: sheets, total: sheets.length, page: 1, pageSize: 25 } } }
			})
		}

		return Promise.resolve({ data: { ocs: { data: detailOf(sheets[0] ?? OPEN_SHEET) } } })
	})
}

describe('FR-086 — deleting a draft sheet from the interface', () => {
	let pinia: Pinia
	let router: Router

	beforeEach(() => {
		pinia = createPinia()
		setActivePinia(pinia)
		router = createRouter({ history: createWebHashHistory(), routes })
		ocsRequest.mockReset()
	})

	async function mountList(sheets: SheetSummary[]) {
		stubTransport(sheets)
		const wrapper = mount(SheetListView, { global: { plugins: [pinia, router] } })
		await flushPromises()
		return wrapper
	}

	async function mountDetail(sheet: SheetSummary) {
		stubTransport([sheet])
		await router.push({ name: 'sheet-detail', params: { id: sheet.id } })
		await router.isReady()
		const wrapper = mount(SheetDetailView, { global: { plugins: [pinia, router] } })
		await flushPromises()
		return wrapper
	}

	// ===== the control exists where deletion is allowed =====

	it('offers the delete control on an open sheet in the list', async () => {
		const wrapper = await mountList([OPEN_SHEET])

		expect(wrapper.find('[data-test="delete-sheet"]').exists()).toBe(true)
	})

	it('offers the delete control on an open sheet in the detail view', async () => {
		const wrapper = await mountDetail(OPEN_SHEET)

		expect(wrapper.find('[data-test="delete-sheet-btn"]').exists()).toBe(true)
	})

	// ===== and is absent where the server refuses =====

	/**
	 * FR-086 refuses a submitted sheet (FR-026) and a completed one (FR-044), and FR-071 says an action not
	 * permitted for the current record state is not offered. A control that is always refused is a defect
	 * against both: it teaches the purchaser the application does not know its own state. The server-side
	 * refusal stays the enforcement under FR-009.
	 */
	it('does not offer the control for a submitted sheet', async () => {
		const wrapper = await mountList([SUBMITTED_SHEET])

		expect(wrapper.find('[data-test="delete-sheet"]').exists()).toBe(false)
	})

	it('does not offer the control for a completed sheet', async () => {
		const wrapper = await mountList([
			{
				...OPEN_SHEET,
				id: 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
				status: 'COMPLETED' as SheetStatus
			}
		])

		expect(wrapper.find('[data-test="delete-sheet"]').exists()).toBe(false)
	})

	// ===== the confirmation =====

	it('opens a confirmation naming the sheet and stating the deletion is permanent', async () => {
		const wrapper = await mountList([OPEN_SHEET])

		await wrapper.find('[data-test="delete-sheet"]').trigger('click')
		await flushPromises()

		const dialog = wrapper.find('[data-test="nc-dialog"]')
		expect(dialog.exists()).toBe(true)

		const text = dialog.text()
		expect(text).toContain('March fuel receipts')
		expect(text).toMatch(/permanently deleted|cannot be undone/i)
	})

	/**
	 * The cascade deletes stored evidence **files**, and a never-submitted sheet has no audit trail to restore
	 * from — so the confirmation is the only thing between a mis-click and irreversible loss. A click on the
	 * control must therefore issue **no** request at all.
	 */
	it('issues no request until the confirmation is accepted', async () => {
		const wrapper = await mountList([OPEN_SHEET])
		ocsRequest.mockClear()

		await wrapper.find('[data-test="delete-sheet"]').trigger('click')
		await flushPromises()

		expect(ocsRequest).not.toHaveBeenCalled()
	})

	it('cancelling deletes nothing and closes the dialog', async () => {
		const wrapper = await mountList([OPEN_SHEET])
		ocsRequest.mockClear()

		await wrapper.find('[data-test="delete-sheet"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-test="confirm-delete-sheet"]').exists()
		// The dialog names its own cancel control, so the assertion is that one exists rather than a
		// non-null assertion: `find` returning `undefined` would otherwise throw inside `trigger` and read as
		// an unrelated failure.
		const cancel = wrapper.findAll('button').find(b => b.text() === 'Cancel')
		expect(cancel).toBeDefined()
		await cancel!.trigger('click')
		await flushPromises()

		expect(ocsRequest).not.toHaveBeenCalled()
		expect(wrapper.find('[data-test="nc-dialog"]').exists()).toBe(false)
	})

	// ===== confirming =====

	it('confirming issues one DELETE carrying the sheet version, and reloads the list', async () => {
		const wrapper = await mountList([OPEN_SHEET])

		await wrapper.find('[data-test="delete-sheet"]').trigger('click')
		await flushPromises()
		ocsRequest.mockClear()

		await wrapper.find('[data-test="confirm-delete-sheet"]').trigger('click')
		await flushPromises()

		// Typed as a loose array rather than a 1-tuple: a tuple annotation makes `filter` reject the callback,
		// because the mock's `calls` is `any[][]` and TypeScript will not narrow an element to one element.
		// Found by running `npm run typecheck`, not by reading.
		const deletes = ocsRequest.mock.calls.filter(
			(call: unknown[]) => String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'DELETE'
		)
		expect(deletes).toHaveLength(1)

		// `toHaveLength` does not narrow the element type, so the call is read defensively rather than through
		// a non-null assertion that would hide a genuinely absent call. Both were found by running
		// `npm run typecheck`, not by reading.
		const config = (deletes[0] ?? [])[0] as { url?: string; data?: unknown; headers?: Record<string, string> }
		expect(config).toBeDefined()
		expect(String(config?.url)).toContain(OPEN_SHEET.id)
		// The version must be sent: FR-086 refuses a stale delete with a 409, and sending a stale or missing
		// version would make every confirmation fail for reasons the purchaser cannot act on.
		expect(config?.data).toMatchObject({ expectedVersion: OPEN_SHEET.version })
		// FR-084/FR-086: the command id is what makes a retry return the first outcome rather than a not-found.
		expect(config?.headers?.["X-PettyCash-Command-ID"]).toBeTruthy()
	})

	/**
	 * Deleting from the detail view leaves the viewer on a record that no longer exists, which is why the view
	 * navigates to the list and clears the store's `currentSheet`.
	 *
	 * The clearing is asserted **after unmount** on purpose. `confirmDeleteFromDetail()` navigates, and this
	 * component is mounted standalone with no router-view parent — so it is not unmounted by the navigation.
	 * The `onUnmounted` hook that clears the sheet is the real protection, and it is what a re-entry to this
	 * route would otherwise defeat: `loadSheet()` would re-fetch a sheet the server no longer has.
	 */
	it('clears the stored sheet when the detail view goes away, so a deleted sheet cannot be re-read', async () => {
		const wrapper = await mountDetail(OPEN_SHEET)

		await wrapper.find('[data-test="delete-sheet-btn"]').trigger('click')
		await flushPromises()
		await wrapper.find('[data-test="confirm-delete-sheet"]').trigger('click')
		await flushPromises()

		// The DELETE happened, so the sheet really is gone from the server's point of view.
		const deletes = ocsRequest.mock.calls.filter(
			(call: unknown[]) => String((call[0] as { method?: string })?.method ?? '').toUpperCase() === 'DELETE'
		)
		expect(deletes).toHaveLength(1)

		wrapper.unmount()
		await flushPromises()

		expect(useSheetStore().currentSheet).toBeNull()
	})
})