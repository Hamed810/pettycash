/**
 * Rendering for the sheet dates FR-063 asks both surfaces to show.
 *
 * ## Why these live here rather than in a view
 *
 * `SheetListView` and `SheetDetailView` both render `createdAt`, `submittedAt`, `completedAt` and the
 * `firstExpenseDate`/`lastExpenseDate` range from the same `SheetSummary` payload, and both must agree on
 * what a **missing** date looks like. A helper copied into each view is two copies of one rule, and the
 * failure this repository keeps finding is a rule that drifted between the copy and the thing it mirrors:
 * `AccountView`'s display name, the account payload projection, the report row projection.
 *
 * ## Why a missing date is a dash and never an epoch
 *
 * `new Date(null).toLocaleDateString('fa-IR')` is **1 January 1970**. Every one of these fields is
 * nullable, and they are null for every sheet that has not reached that stage — `submittedAt` and
 * `completedAt` for most sheets in the application. An unguarded render therefore puts a confident date
 * on a sheet that was never submitted, which is worse than an empty cell: 1970 reads as data.
 *
 * The dash is the same marker the report table uses for a value the projection did not supply, so a
 * reader learns one convention rather than two.
 *
 * ## Why a range shows both bounds
 *
 * `firstExpenseDate` alone cannot distinguish "the sheet's first expense fell on this day" from "this
 * sheet covers only this day". Either bound missing leaves just the other, and both missing is a dash — so
 * the cell never shows a bare dash for a sheet that has any expense at all.
 */
export const ABSENT_MARKER = '—'

/**
 * A date the server sent, or {@link ABSENT_MARKER} when it sent none.
 *
 * `''` is treated as absent alongside `null` and `undefined`: an empty string is how an omitted field
 * arrives from a client that sent an empty input, and rendering it as 1970 would be the same defect.
 */
export function formatSheetDate(value: string | null | undefined): string {
	if (value === null || value === undefined || value === '') return ABSENT_MARKER
	return new Date(value).toLocaleDateString('fa-IR')
}

/**
 * The expense date range as one cell, from the two nullable bounds.
 *
 * @param sheet anything carrying the range's two fields — a `SheetSummary`, or the partial shape a test
 *              fixture builds
 */
export function formatSheetDateRange(sheet: {
	firstExpenseDate?: string | null
	lastExpenseDate?: string | null
}): string {
	const first = formatSheetDate(sheet.firstExpenseDate)
	const last = formatSheetDate(sheet.lastExpenseDate)

	if (first === ABSENT_MARKER && last === ABSENT_MARKER) return ABSENT_MARKER
	if (first === ABSENT_MARKER) return `… ${last}`
	if (last === ABSENT_MARKER) return `${first} …`

	return `${first} – ${last}`
}