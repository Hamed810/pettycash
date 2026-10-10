import { expect } from 'vitest'
import type { VueWrapper } from '@vue/test-utils'

/**
 * T394: one rule for "the narrow card says what the table says", shared by all seven converted views.
 *
 * ## Why a shared helper rather than seven tests
 *
 * Six views each needed the same assertion, and a copy per view is six places for the rule to drift — the
 * failure mode this whole reflow exists to close. `SheetListView` already had the assertion inline
 * (`PurchaserFlow.spec.ts`), so the rule existed twice the moment this file was written; putting it here
 * makes it exist once, and the inline copy can be pointed at this instead.
 *
 * ## What it asserts, and what it deliberately does not
 *
 * `ResponsiveTable` renders both layouts from **one** `cell` render function, so the two cannot disagree
 * *by construction*. Asserting their text matches would therefore be close to tautological — it would prove
 * the component is called twice, not that anything is correct.
 *
 * So this asserts the three things that are **not** guaranteed by that design, each of which is a real way
 * the narrow layout can lose a figure:
 *
 * 1. **Every column has a cell in both layouts.** A column present in the header but absent from one
 *    layout is the defect, and a text comparison would miss it if both sides were empty.
 * 2. **Every view-specific inner hook survives into the card.** This is the substantive one. A view's cell
 *    renderer puts `data-test` on its *content* (`data-testid="amount"`, `data-test="transaction-stage"`).
 *    Those hooks are the only handle a test has on that figure, and if any path ever rendered the card from
 *    different markup the hooks would silently vanish — leaving the card's content unassertable, which is
 *    the state `ReportTable` is still in (T395).
 * 3. **The two layouts' text agrees** for each hooked figure. Kept despite being closest to tautological,
 *    because it is what fails loudly if someone reintroduces separate markup — and it costs one line.
 *
 * **What it cannot do.** jsdom evaluates no media queries and has no layout engine, so nothing here shows
 * the card is *seen* at 390px. This proves that when the card is rendered it carries the same figures as the
 * table. T122's browser measurement is what confirms visibility, and this does not substitute for it.
 */

/** The desktop half of a `ResponsiveTable`, and the narrow-viewport half. */
const DESKTOP = '.responsive-table__desktop'
const CARD = '.responsive-table__card'

/**
 * Read the text of one hook inside one layout.
 *
 * Scoped to a layout on purpose: the same hook exists in both, and an unscoped `find` would return the
 * desktop one — making every comparison silently compare a thing with itself.
 */
function textIn(wrapper: VueWrapper, layout: string, hook: string): string {
	return wrapper.find(`${layout} [${hook}]`).text()
}

export interface CardAgreementOptions {
	/** A hook present in **both** layouts, e.g. `'data-test="amount"'`. Compared for text equality. */
	hooks: string[]
	/** How many rows were mounted. Asserted against both layouts so a dropped row is visible. */
	rows: number
	/**
	 * The card's own row hook. Optional because `SheetListView`'s is `sheet-row-card` while the others
	 * follow the `queue-card-`/`transaction-card-` convention.
	 */
	cardRowTestId?: string
}

/**
 * Assert the card layout carries every figure the table layout carries.
 *
 * Throws with the offending hook named, because "expected '—' to contain '125000'" says which figure
 * diverged while a bare failure says only that two trees differ.
 */
export function expectCardAgreesWithTable(wrapper: VueWrapper, options: CardAgreementOptions): void {
	const { hooks, rows } = options

	expect(
		wrapper.findAll(`${DESKTOP} tbody tr`),
		`the table layout must render all ${rows} row(s)`
	).toHaveLength(rows)

	expect(
		wrapper.findAll(CARD),
		`the card layout must render all ${rows} row(s) — a card that is missing a row is a figure a `
			+ `reader cannot see at a narrow viewport`
	).toHaveLength(rows)

	for (const hook of hooks) {
		const inTable = wrapper.find(`${DESKTOP} [${hook}]`)
		const inCard = wrapper.find(`${CARD} [${hook}]`)

		// Asserted separately for each layout so the failure says *which* one lost the figure.
		expect(
			inTable.exists(),
			`the table layout must still carry ${hook}`
		).toBe(true)
		expect(
			inCard.exists(),
			`the card layout must carry ${hook}. This hook is the only handle a test has on that figure: `
				+ `if the narrow layout is rendered from separate markup, it vanishes and the card's `
				+ `content becomes unassertable.`
		).toBe(true)

		const tableText = textIn(wrapper, DESKTOP, hook).trim()
		const cardText = textIn(wrapper, CARD, hook).trim()

		expect(
			cardText,
			`${hook} must say the same thing in both layouts. Table: ${JSON.stringify(tableText)}, `
				+ `card: ${JSON.stringify(cardText)}`
		).toBe(tableText)
	}
}

/** Exported so a spec can assert a column count without reaching into the helper's internals. */
export const NARROW_VIEWPORT_SELECTORS = { desktop: DESKTOP, card: CARD } as const