import { test, expect } from '@playwright/test'

/**
 * US8 browser matrix (T122): desktop and mobile-sized viewports, dark and
 * high-contrast themes, keyboard navigation with visible focus.
 *
 * The projects live in playwright.config.ts (chromium-desktop,
 * firefox-desktop, edge-desktop, chromium-mobile); this spec runs under every
 * one of them, so a failure report names the browser. What remains here after
 * the FR-073 amendment is the keyboard and visible-focus half plus the 390px
 * no-horizontal-scroll floor and the 12pt type floor as the browser paints
 * them — the error-rate figure belongs to quickstart §8.2, not to this file.
 *
 * Error-rate note (FR-073 as amended 2026-10-07): deliberately provoked
 * 4xx/409/422 and environment-induced responses are excluded from any rate;
 * this spec provokes none and asserts none.
 */

test.describe('browser matrix', () => {
	test('the sheet list shows no horizontal scroll at a 390px viewport', async ({ page }) => {
		await page.setViewportSize({ width: 390, height: 844 })
		await page.goto('/apps/pettycash/#/sheets')
		await page.waitForLoadState('networkidle')
		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
			bodyScrollWidth: document.body.scrollWidth,
		}))
		expect(overflow.scrollWidth, 'no horizontal scroll at 390px').toBeLessThanOrEqual(overflow.clientWidth + 1)
		expect(overflow.bodyScrollWidth, 'body must not overflow at 390px').toBeLessThanOrEqual(overflow.clientWidth + 1)
	})

	test('user-facing text meets the 12pt (16px) floor as painted', async ({ page }) => {
		await page.goto('/apps/pettycash/#/sheets')
		await page.waitForLoadState('networkidle')
		// App-controlled text only. `@nextcloud/vue`-emitted internals
		// (NcButton 15px, NcSelect 15px, NcTextArea label 13px, vue-select
		// 13.33px) render below the floor and no app declaration controls
		// them; T120 measured and recorded that exception rather than
		// overriding library internals, so this sweep skips those subtrees
		// instead of re-litigating it here.
		const under = await page.evaluate(() => {
			const SKIP = '.button-vue, [class*="vs__"], [class*="nc-"], .ncSelect, button'
			const els = Array.from(document.querySelectorAll('.pettycash-shell p, .pettycash-shell td, .pettycash-shell th, .pettycash-shell h1, .pettycash-shell h2, .pettycash-shell li, .pettycash-shell div'))
			const bad: string[] = []
			for (const el of els.slice(0, 300)) {
				if ((el as HTMLElement).closest(SKIP)) continue
				// Leaf-ish text only: a container inherits its size from the
				// children this sweep already checks one by one.
				if (Array.from(el.children).some((c) => (c.textContent ?? '').trim() !== '')) continue
				const text = (el.textContent ?? '').trim()
				if (text === '') continue
				const size = Number.parseFloat(window.getComputedStyle(el).fontSize || '0')
				if (size > 0 && size < 16) {
					bad.push(`${el.tagName} ${text.slice(0, 40)}: ${size}px`)
				}
			}
			return bad.slice(0, 10)
		})
		expect(under, `elements below the 16px floor: ${under.join(' | ')}`).toEqual([])
	})

	test('keyboard tab reaches a control with a visible focus indicator', async ({ page }) => {
		await page.goto('/apps/pettycash/#/sheets')
		await page.waitForLoadState('networkidle')
		for (let i = 0; i < 25; i++) {
			await page.keyboard.press('Tab')
			const info = await page.evaluate(() => {
				const el = document.activeElement
				if (!el || el === document.body) return null
				const computed = window.getComputedStyle(el)
				return { style: computed.outlineStyle, width: Number.parseFloat(computed.outlineWidth || '0') }
			})
			if (info === null) continue
			expect(info.style, 'focused element must show a focus indicator').not.toBe('none')
			expect(info.width, 'focus indicator must have non-zero width').toBeGreaterThan(0)
			return
		}
	})

	test.describe('dark theme', () => {
		test.use({ colorScheme: 'dark' })
		test('the sheet list shows no horizontal scroll at 390px', async ({ page }) => {
			await page.setViewportSize({ width: 390, height: 844 })
			await page.goto('/apps/pettycash/#/sheets')
			await page.waitForLoadState('networkidle')
			const overflow = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}))
			expect(overflow.scrollWidth, 'no horizontal scroll at 390px (dark)').toBeLessThanOrEqual(overflow.clientWidth + 1)
		})
	})

	test.describe('high-contrast (forced colours) theme', () => {
		test.use({ forcedColors: 'active' })
		test('a keyboard-reached control keeps a visible focus indicator', async ({ page }) => {
			await page.goto('/apps/pettycash/#/sheets')
			await page.waitForLoadState('networkidle')
			for (let i = 0; i < 25; i++) {
				await page.keyboard.press('Tab')
				const info = await page.evaluate(() => {
					const el = document.activeElement
					if (!el || el === document.body) return null
					const computed = window.getComputedStyle(el)
					return { style: computed.outlineStyle, width: Number.parseFloat(computed.outlineWidth || '0') }
				})
				if (info === null) continue
				expect(info.style, 'forced-colours: focused element must keep its indicator').not.toBe('none')
				expect(info.width).toBeGreaterThan(0)
				return
			}
		})
	})
})
