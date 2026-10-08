import { describe, expect, it } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * FR-071 / SC-009's keyboard-operability floor, asserted at the level jsdom can reach.
 *
 * ## Why this file exists when a browser spec exists
 *
 * `tests/E2E/keyboard-accessibility.spec.ts` is the real verification: it drives a journey with the
 * keyboard and measures the indicator as the browser paints it, under all three themes. It cannot run in
 * `npm run check`, because `check` does not include `test:e2e` — which is exactly how this suite's
 * Playwright projects went unused and a spec shipped that had never been run.
 *
 * So this file asserts the half a static check *can* prove: that the indicator is declared, that it is
 * declared with a style and a width rather than a colour alone, and that nothing in the app suppresses
 * it. A declaration is not a rendering and this file does not claim to be one; it is the floor under the
 * browser suite, so that removing the rule is a failure someone sees in `npm run check` rather than one
 * discovered by a manual pass.
 */
const APP_STYLES = join(__dirname, '..', '..', 'src', 'styles', 'app.scss')

describe('FR-071 / SC-009 keyboard-operability floor', () => {
	it('declares a focus indicator with an explicit style and width, not colour alone', () => {
		const css = readFileSync(APP_STYLES, 'utf8')

		// The three properties, each of which is a way the indicator disappears when left to a default:
		// `outline: none` inherited from a theme, a ring drawn on the border and read as a border, and a
		// `box-shadow` that a forced-colours mode discards.
		expect(css, 'outline-style must be set explicitly: a theme\'s `*:focus { outline: none }` is the '
			+ 'most common way an indicator vanishes, and colour alone leaves the style at the cascade\'s '
			+ 'discretion').toMatch(/outline-style:\s*solid/)
		expect(css, 'outline-width must be set explicitly, or `outline-color` alone draws nothing.').toMatch(
			/outline-width:\s*\d/,
		)
		expect(css, 'outline-offset must be set: without it the ring sits on the element\'s own border and '
			+ 'reads as a border change, which is invisible against every bordered row and card here.').toMatch(
			/outline-offset:\s*\d/,
		)
	})

	it('scopes the indicator to :focus-visible, with a :focus fallback that cannot double-apply', () => {
		const css = readFileSync(APP_STYLES, 'utf8')

		expect(css, ':focus-visible is what "reached by keyboard" means; a bare :focus also fires on a '
			+ 'mouse click, which is why apps historically removed focus styling entirely.').toMatch(
			/:focus-visible/,
		)
		expect(css, 'the fallback must exclude :focus-visible so the two arms cannot both apply.').toMatch(
			/:focus:not\(:focus-visible\)/,
		)
	})

	it('restates the indicator in system colours for forced-colours mode', () => {
		const css = readFileSync(APP_STYLES, 'utf8')

		expect(css, 'FR-071 says a focus indicator the active theme makes invisible is a defect. Under '
			+ '`forced-colors: active` every author colour is replaced, so an indicator that only names a '
			+ 'theme variable is erased in exactly the mode FR-071\'s theme clause is about.').toMatch(
			/@media\s*\(forced-colors:\s*active\)/,
		)
		expect(css, 'a forced-colours indicator must be drawn with a system colour keyword.').toMatch(
			/outline-color:\s*Highlight/,
		)
	})

	it('has nothing in the app that suppresses the indicator', () => {
		// The failure this catches is a component's own scoped style doing `outline: none` for a reason
		// that looked reasonable in isolation — a `<select>`, a bordered row. That is not visible in the
		// app.scss declaration and is invisible in a declaration-level check that only reads one file.
		const offenders: string[] = []

		for (const file of walk(join(__dirname, '..', '..', 'src'))) {
			// Comments are stripped first, and that is not cosmetic. The rule being checked here is
			// documented at length **in the stylesheet that implements it**, and its docblock names every
			// property and the exact declaration `outline: none` that it exists to prevent — so the first
			// version of this scanner reported the documentation of the fix as the defect. A scan that reads
			// prose will produce that result for any rule worth documenting, which makes it useless.
			const source = readFileSync(file, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')

			// Scoped to focus declarations only: a general `outline: none` on a non-focus rule is not what
			// this test is about, and flagging one would train people to ignore it.
			if (/(?::focus|:focus-visible)[^{]*\{[^}]*outline:\s*none/.test(source)) {
				offenders.push(file.replace(join(__dirname, '..', '..'), ''))
			}
		}

		expect(
			offenders,
			'FR-071: these remove the focus indicator on a focused element:\n  ' + offenders.join('\n  '),
		).toEqual([])
	})
})

/** @returns {string[]} */
function walk(directory: string): string[] {
	const files: string[] = []

	for (const entry of readdirSync(directory)) {
		const path = join(directory, entry)
		if (statSync(path).isDirectory()) {
			files.push(...walk(path))
			continue
		}
		if (/\.(vue|scss|css)$/.test(path)) {
			files.push(path)
		}
	}

	return files
}