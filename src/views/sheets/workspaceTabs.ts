/**
 * The workspace tab a purchaser last had open, remembered for the lifetime of the page.
 *
 * UI-PUR-005: drilling into a transaction and returning must restore the workspace the purchaser
 * left, not reset it to its first tab. A module-level map (rather than component state) is what
 * survives the overlay being unmounted by the drill-down route.
 */
export const WORKSPACE_TABS = ['transactions', 'approvals', 'overview', 'history'] as const

export type WorkspaceTab = typeof WORKSPACE_TABS[number]

const remembered = new Map<string, WorkspaceTab>()

export function rememberedTab(sheetId: string | undefined): WorkspaceTab {
	if (!sheetId) return 'transactions'
	return remembered.get(sheetId) ?? 'transactions'
}

export function rememberTab(sheetId: string | undefined, tab: WorkspaceTab): void {
	if (!sheetId) return
	remembered.set(sheetId, tab)
}

/** Test seam: a module map outlives a single mount by design. */
export function forgetRememberedTabs(): void {
	remembered.clear()
}
