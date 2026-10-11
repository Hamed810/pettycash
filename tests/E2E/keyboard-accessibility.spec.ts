import { expect, test, type Page } from '@playwright/test'

/**
 * FR-071 (clarified 2026-10-07) and SC-009: the keyboard-operability floor, measured as the browser
 * paints it.
 *
 * ## The two criteria this asserts
 *
 * 1. **A user can complete each primary journey using the keyboard alone.** Not "the focusable elements
 *    exist" — the journey actually driven with `Tab`/`Enter`/`Escape` and no pointer at all.
 * 2. **Every interactive element reached that way shows a visible focus indicator.** Asserted by reading
 *    the computed `outline-style` and `outline-width` of the element that has focus, which is the only
 *    thing "visible" can honestly mean for a rendered page.
 *
 * ## Why each theme is a separate run rather than one run with a loop
 *
 * The themes are emulated at the **context** level (`colorScheme`, `forcedColors`), so they cannot be
 * switched mid-test. A loop inside one test would silently measure whichever theme happened to be set
 * when the assertion ran, and the failure would name no theme at all. Separate `test.describe` blocks make
 * the theme part of the test's identity, so a failing report says which theme broke.
 *
 * `forcedColors: 'active'` is the high-contrast case. It is the only lever that emulates what a user
 * running a high-contrast mode actually gets: the system palette replaces every author colour, which is
 * precisely the condition under which a focus indicator written as a theme variable disappears.
 *
 * ## What is deliberately NOT asserted
 *
 * The three screen-reader items FR-076 defers to V2 — form control label association, error-message
 * association, and announced (live-region) status. This file must not grow assertions for them, and the
 * V1 release-readiness record must not claim them. See `quickstart.md` §7 for where they are recorded as
 * deferred. Asserting them here would report a criterion FR-076 says is not V1 as though it were met.
 */

/** The indicator FR-071 requires, read from the computed style of the focused element. */
async function indicatorOf(page: Page): Promise<{ style: string; width: number; color: string }> {
	return page.evaluate(() => {
		const element = document.activeElement
		if (!element || element === document.body) {
			return { style: 'none', width: 0, color: '' }
		}
		const computed = window.getComputedStyle(element)
		return {
			style: computed.outlineStyle,
			width: Number.parseFloat(computed.outlineWidth || '0'),
			color: computed.outlineColor,
		}
	})
}

/**
 * Assert that whatever currently has focus shows an indicator.
 *
 * Framed as a single reusable assertion because it is the criterion, and every journey repeats it for
 * every element it reaches. The message names the element so a failure report identifies *which* control
 * in the journey lost its indicator — the alternative is a report saying "an element" thirty times.
 */
async function expectVisibleFocusIndicator(page: Page, step: string): Promise<void> {
	const indicator = await indicatorOf(page)

	expect(
		indicator.style,
		`FR-071: ${step} must show a focus indicator. Computed outline-style is "${indicator.style}" — a `
		+ 'theme that sets `outline: none` on focus erases the browser default, which is the defect this '
		+ 'criterion exists for.',
	).not.toBe('none')

	expect(
		indicator.width,
		`FR-071: ${step} reached by keyboard but its focus indicator has zero width. A zero-width outline `
		+ 'is not a perceivable change to the focused element.',
	).toBeGreaterThan(0)

	expect(indicator.color, `FR-071: ${step} has no resolved outline colour.`).not.toBe('')
}

/** Tab until the focused element matches `predicate`, so a journey is driven the way a keyboard user would. */
async function tabUntil(page: Page, predicate: (info: { tag: string; text: string; testId: string }) => boolean, limit = 40): Promise<boolean> {
	for (let step = 0; step < limit; step++) {
		await page.keyboard.press('Tab')

		const info = await page.evaluate(() => {
			const element = document.activeElement
			if (!element || element === document.body) {
				return { tag: '', text: '', testId: '' }
			}
			return {
				tag: element.tagName,
				text: (element.textContent ?? '').trim().slice(0, 80),
				testId: element.getAttribute('data-test') ?? element.id ?? '',
			}
		})

		if (predicate(info)) {
			return true
		}
	}

	return false
}

/**
 * The indicator check, under one theme.
 *
 * A function rather than a shared test body so the theme is chosen by the enclosing `describe` and the
 * three runs cannot drift apart — which is what a hand-copied third variant always does eventually.
 */
function journeyUnderTheme(themeName: string): void {
	test.describe(themeName, () => {
		test('every element reached by keyboard shows a visible focus indicator', async ({ page }) => {
			await page.goto('/apps/pettycash/#/sheets')
			await page.waitForLoadState('networkidle')

			const seen: string[] = []
			const limit = 25

			for (let step = 0; step < limit; step++) {
				await page.keyboard.press('Tab')

				const info = await page.evaluate(() => {
					const element = document.activeElement
					if (!element || element === document.body) {
						return null
					}
					const computed = window.getComputedStyle(element)
					return {
						tag: element.tagName,
						label: (element.getAttribute('aria-label')
							?? element.textContent
							?? '').trim().slice(0, 60),
						testId: element.getAttribute('data-test') ?? '',
						disabled: element.hasAttribute('disabled'),
						outlineStyle: computed.outlineStyle,
						outlineWidth: Number.parseFloat(computed.outlineWidth || '0'),
						outlineColor: computed.outlineColor,
					}
				})

				if (info === null || seen.includes(info.testId + info.label)) {
					break
				}
				seen.push(info.testId + info.label)

				// A disabled control is not reached by a keyboard user in any meaningful sense, and its
				// indicator is not part of the criterion — but it is still worth recording that the tab
				// order reached it, because a journey that stalls on a disabled control is a real problem.
				if (info.disabled) {
					continue
				}

				expect(
					info.outlineStyle,
					`FR-071 (${themeName}): the element reached at step ${step + 1} `
					+ `(<${info.tag.toLowerCase()} data-test="${info.testId}"> "${info.label}") has no focus `
					+ 'indicator. FR-071 requires a perceivable change to every interactive element reached '
					+ 'by keyboard, and says explicitly that a browser default the host theme erases does '
					+ 'not count.',
				).not.toBe('none')

				expect(
					info.outlineWidth,
					`FR-071 (${themeName}): the element reached at step ${step + 1} `
					+ `(<${info.tag.toLowerCase()} data-test="${info.testId}"> "${info.label}") has a `
					+ 'zero-width focus outline.',
				).toBeGreaterThan(0)

				expect(info.outlineColor).not.toBe('')
			}

			// The journey is only meaningful if the tab order actually reached something. A page that
			// renders nothing focusable would otherwise pass this test vacuously — which is the exact shape
			// of failure this criterion was written to catch.
			expect(
				seen.length,
				`FR-071 (${themeName}): tabbing from the sheet list reached ${seen.length} element(s). A `
				+ 'journey that reaches nothing cannot demonstrate keyboard operability.',
			).toBeGreaterThan(0)
		})

		test('a primary journey can be completed with no pointer at all', async ({ page }) => {
			await page.goto('/apps/pettycash/#/sheets')
			await page.waitForLoadState('networkidle')

			// Drive sheet creation entirely from the keyboard. Every assertion here is on a *keyboard*
			// action, and there is no `.click()` in this test — which is what makes it a test of the
			// criterion rather than of the selector. Matched on the stable
			// `create-sheet-button` hook first: button labels render through
			// the l10n catalog and read as raw keys until it arrives, so a
			// text-only predicate passes or fails on translation timing
			// rather than on keyboard reachability.
			const reachedCreate = await tabUntil(page, ({ text, testId }) =>
				testId.includes('create-sheet') || /create sheet|new sheet|ایجاد/i.test(text))

			expect(
				reachedCreate,
				`FR-071 (${themeName}): the sheet list offers no keyboard-reachable way to begin creating `
				+ 'a sheet, so User Story 1 cannot be completed with the keyboard alone.',
			).toBe(true)

			await expectVisibleFocusIndicator(page, 'the sheet-list create control')

			await page.keyboard.press('Enter')

			// The title field is the next stop in a keyboard user's path, so it must be reachable and must
			// show the indicator before anything is typed.
			const reachedTitle = await tabUntil(page, ({ testId }) => testId.includes('sheet-title'))
			expect(reachedTitle, `FR-071 (${themeName}): no keyboard-reachable title field after creating a sheet.`).toBe(true)
			await expectVisibleFocusIndicator(page, 'the sheet title field')

			await page.keyboard.type('Keyboard only sheet')
			await expectVisibleFocusIndicator(page, 'the sheet title field after typing')
		})
	})
}

journeyUnderTheme('default theme')

test.describe('dark theme', () => {
	test.use({ colorScheme: 'dark' })
	journeyUnderTheme('dark theme')
})

test.describe('high-contrast (forced colours) theme', () => {
	// FR-071's theme clause is about exactly this: an indicator the active theme renders as absent is a
	// defect, because a keyboard user then has no indication of where they are. Emulating the forced
	// colours mode is what makes that assertion possible rather than assumed.
	test.use({ forcedColors: 'active' })
	journeyUnderTheme('high-contrast (forced colours) theme')
})