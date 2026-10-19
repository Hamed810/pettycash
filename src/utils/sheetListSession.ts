import type { Router } from 'vue-router'

/**
 * UI-PUR-004: the sheet list remembers the context a sheet visit came from.
 *
 * ## The rule, stated once
 *
 * Leaving the list for a sheet and coming back must return the reader to the same place — same filters,
 * same page, same scroll — while a *fresh* visit to the list (from the dashboard, the sidebar, a reload)
 * starts clean: page 1, no filters, top of the page. "Back" therefore has to be distinguished from
 * "arrived", and that distinction is a navigation fact, not something a component can infer from its own
 * state.
 *
 * ## Why the router records it
 *
 * The list is remounted on return (the detail view replaces it), so anything it remembers in component
 * state is gone. The decision depends on the pair (destination, source), which only the router sees:
 * `to.name === 'sheets'` arriving from `sheet-detail`, `sheet-edit` or `sheet-create` is a return; every
 * other arrival is fresh. A `beforeEach` hook records that one boolean, and the list reads it in
 * `onMounted`. The hook is installed from `createAppRouter()`, not in the view, so a route change cannot
 * silently stop recording it.
 *
 * ## Why the list is refetched rather than cached
 *
 * A "restored" list that shows the rows the browser happened to keep would show amounts and statuses
 * that are minutes old — and this screen is where a purchaser decides whether a sheet can still be
 * submitted or edited. The page and filters are restored; the rows are always the server's current ones.
 *
 * ## Why the scroll container is resolved, not assumed
 *
 * The obvious container is wrong. In Nextcloud 34, `#content` is `overflow: clip`; the element that
 * actually scrolls is the app's own mount wrapper (`#pettycash-app`, `overflow-y: auto`), with the
 * window itself never moving. Writing `window.scrollTo` would scroll nothing, and writing
 * `#content.scrollTop` would silently do nothing too — a failure that a jsdom test cannot see, because
 * jsdom has no layout and lets any element's `scrollTop` hold a number. The container is therefore
 * resolved from the list's own root element (nearest scrollable ancestor), with the document as the
 * last resort, and only its `scrollTop` is written.
 */

/** The routes a reader can reach from the sheet list and reasonably expect to return from. */
export const SHEET_CONTEXT_ROUTES = new Set(['sheet-detail', 'sheet-edit', 'sheet-create'])

/** `true` when the current navigation into the list is a return from a sheet surface. */
let returning = false

/** The scroll offset captured when the reader left for a sheet surface. */
let scrollOffset = 0

/**
 * Records, for the lifetime of the router, whether an arrival at the list is a return.
 *
 * The guard never redirects or cancels: it only records the pair. Navigation behaviour is unchanged.
 */
export function installSheetListReturnTracking(router: Router): void {
	router.beforeEach((to, from) => {
		returning = to.name === 'sheets'
			&& typeof from.name === 'string'
			&& SHEET_CONTEXT_ROUTES.has(from.name)
	})
}

/** Whether the list was entered from a sheet surface (restore) or from anywhere else (fresh). */
export function isReturningToSheetList(): boolean {
	return returning
}

/**
 * Captures the offset the reader left the list at.
 *
 * `0` is stored for an absent/negative/non-finite value so a failed read cannot scroll the restored
 * list to a nonsense position.
 */
export function rememberSheetListScroll(offset: number): void {
	scrollOffset = Number.isFinite(offset) && offset > 0 ? Math.round(offset) : 0
}

/** Consumes the captured offset: a return restores once, and a second mount does not replay it. */
export function takeSheetListScroll(): number {
	const value = scrollOffset
	scrollOffset = 0
	return value
}

/**
 * Forgets everything this module remembers.
 *
 * For tests, which mount views directly and must not inherit a previous test's return decision.
 */
export function resetSheetListSession(): void {
	returning = false
	scrollOffset = 0
}

/**
 * The element that actually scrolls the list.
 *
 * Walks up from the list's root and returns the first ancestor whose computed `overflow-y` allows
 * scrolling **and** which currently has content taller than its box. The second condition matters: an
 * `overflow: auto` ancestor with nothing to scroll is not the container, and the first page render may
 * briefly have neither. The ids are fallbacks for the moment after a remount when the list is shorter
 * than the viewport (nothing overflows, but the app wrapper is still the element whose `scrollTop` this
 * screen owns), and `document.scrollingElement` is the last resort for a layout without an app wrapper.
 */
function scrollContainer(from: Element | null): HTMLElement | null {
	if (typeof document === 'undefined') return null

	let node = from?.parentElement ?? null
	while (node) {
		const overflowY = getComputedStyle(node).overflowY
		if ((overflowY === 'auto' || overflowY === 'scroll') && node.scrollHeight > node.clientHeight) {
			return node
		}
		node = node.parentElement
	}

	return (document.getElementById('pettycash-app')
		?? document.getElementById('content')
		?? document.scrollingElement) as HTMLElement | null
}

/** The current list scroll offset, or `0` when nothing is scrollable yet. */
export function readScrollOffset(from: Element | null = null): number {
	const container = scrollContainer(from)
	if (!container) return 0
	return Number.isFinite(container.scrollTop) ? Math.max(0, container.scrollTop) : 0
}

/**
 * Applies a captured offset on the resolved container.
 *
 * Only `scrollTop` is written: the horizontal axis and the window position are not this screen's to
 * change, and the offset is applied after the rows have rendered, so the document is tall enough for it.
 * `0` is a real instruction (a fresh visit starts at the top), not "nothing to do": the container is
 * shared by the whole application, so a previous page may have left it scrolled.
 */
export function restoreScrollOffset(offset: number, from: Element | null = null): void {
	if (!Number.isFinite(offset)) return
	const container = scrollContainer(from)
	if (container) container.scrollTop = Math.max(0, offset)
}
