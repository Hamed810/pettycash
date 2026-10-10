import { describe, it, expect } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'

/**
 * T389: FR-071's narrow-viewport answer, asserted across every view rather than one at a time.
 *
 * ## What this asserts, and why it is a repository-wide check
 *
 * FR-071 requires all listed elements to be "visible **without horizontal scroll** at 390px viewport".
 * `src/styles/app.scss` states the consequence in its own words: that is a requirement to *fix* the
 * overflow, not to clip it. The eight surfaces that listed their rows originally answered it with
 * `overflow-x: auto` on a table container, which produces the scrollbar the requirement rules out.
 *
 * A per-view test is the wrong shape for that. Each one was written when its own view was converted, which
 * is exactly how seven of the eight existed at once — every assertion green, seven views still clipping.
 * This walks `src/` instead, so a *new* view that answers a narrow viewport with a scrollbar fails here
 * rather than in whichever suite happens to cover it.
 *
 * ## What it cannot do, stated rather than implied
 *
 * **This is a declaration assertion, not a measurement.** jsdom does not evaluate media queries and has no
 * layout engine, so nothing here can observe that a card is actually *shown* at 390px. Deleting a
 * `@media` block leaves these tests green — measured, not assumed, when the same limit was found on
 * `SheetListView`. What this file proves is that the *clipping* declaration is absent and that every table
 * surface declares a narrow-viewport answer. T122's browser measurement remains what confirms the answer is
 * seen; this is the same proxy T116 documents for `min-width`, and it is labelled as one rather than
 * presented as a rendering.
 */

const SRC_ROOT = join(process.cwd(), 'src')

/** Every `.vue` file under `src/`, recursively. */
function sourceFiles(dir = SRC_ROOT): string[] {
	return readdirSync(dir).flatMap((entry) => {
		const path = join(dir, entry)

		if (statSync(path).isDirectory()) return sourceFiles(path)

		return path.endsWith('.vue') ? [path] : []
	})
}

/**
 * Style blocks with their comments removed.
 *
 * **Comment stripping is not optional.** Every converted view carries a comment recording that it *used*
 * to declare `overflow-x: auto` and why it does not now. Matching the raw text fails on the very record of
 * the fix — an earlier version of this check in `PurchaserFlow.spec.ts` did exactly that.
 */
function declarationsIn(source: string): string[] {
	const styles = source.match(/<style[^>]*>([\s\S]*?)<\/style>/g) ?? []

	return styles
		.map((block) => block.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, ''))
		.flatMap((css) => css.split('\n'))
		.filter((line) => /^\s*overflow-x:\s*(auto|scroll)/.test(line))
}

describe('FR-071 narrow-viewport reflow', () => {
	it('no view in src/ answers a narrow viewport with a clipping overflow', () => {
		const offenders: string[] = []

		for (const file of sourceFiles()) {
			for (const line of declarationsIn(readFileSync(file, 'utf8'))) {
				offenders.push(`${relative(process.cwd(), file)}: ${line.trim()}`)
			}
		}

		expect(
			offenders,
			'FR-071 requires visibility *without horizontal scroll* at 390px, and `src/styles/app.scss` says '
				+ 'that is a requirement to fix the overflow rather than to clip it. `overflow-x: auto` on a '
				+ 'container produces the scrollbar the requirement rules out, so it may not be how a table '
				+ 'surface answers a narrow viewport.\nOffenders:\n' + offenders.join('\n')
		).toEqual([])
	})

	/**
	 * No view renders its own `<table>` without a narrow-viewport answer.
	 *
	 * A view that emits `<table>` and declares **no** `@media` has one layout, and it is the desktop one —
	 * which is the state all eight surfaces were in before T389, and the state a future view lands in by
	 * default if this assertion does not exist.
	 *
	 * **Delegation counts as an answer.** `SheetListView` and `TransactionListView` emit no `<table>` at
	 * all: they hand their rows to `ResponsiveTable`, which holds the breakpoint. Requiring each view to
	 * declare its own would push the pattern back into seven copies.
	 *
	 * Two things this had to get right, both found by running it:
	 *
	 * - **Comments are stripped first.** The first version matched `<table` on raw source and flagged
	 *   `SheetListView` and `TransactionListView` — both of which mention `<table>` *in the comment
	 *   explaining why they no longer render one*.
	 * - **`@media` is counted in the whole file, style block included.** A view may answer with a stylesheet
	 *   the file imports rather than a block it declares.
	 */
	it('no view renders a table of its own without a narrow-viewport rule', () => {
		const silent: string[] = []

		for (const file of sourceFiles()) {
			const source = readFileSync(file, 'utf8')
			const withoutComments = source.replace(/<!--[\s\S]*?-->/g, '').replace(/\/\*[\s\S]*?\*\//g, '')

			if (!/<table[\s>]/.test(withoutComments)) continue
			if (/@media/.test(withoutComments)) continue

			silent.push(relative(process.cwd(), file))
		}

		expect(
			silent,
			'a view that renders its own <table> and declares no @media has exactly one layout, and it is '
				+ 'the desktop one. FR-071 requires the listed elements to adapt to mobile-sized screens, '
				+ 'which needs a declared narrow-viewport answer — either a media query or a hand-off to '
				+ 'ResponsiveTable.\n' + silent.join('\n')
		).toEqual([])
	})

	/**
	 * The pattern is carried by exactly one component, so there is one place to get it right.
	 *
	 * Asserting the *count* rather than the presence: the seventh hand-written copy of a reflow is the
	 * failure this whole change exists to prevent, and it looks exactly like the sixth passing test.
	 */
	it('the narrow-viewport switch lives in one shared component', () => {
		const withCards = sourceFiles().filter((file) =>
			/<ResponsiveTable[\s>]/.test(readFileSync(file, 'utf8').replace(/<!--[\s\S]*?-->/g, ''))
		)

		expect(
			withCards.length,
			'ResponsiveTable is the single copy of the desktop/card switch. A view that grew its own table '
				+ 'instead of adopting it would reintroduce the drift this replaced.\n'
				+ withCards.map((f) => relative(process.cwd(), f)).join('\n')
		).toBeGreaterThanOrEqual(7)
	})
})
