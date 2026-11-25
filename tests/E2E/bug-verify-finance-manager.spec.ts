import { test, expect } from '@playwright/test'

/**
 * Bug verification: review-decision-button-visibility — Finance Manager cases
 * Uses seeded finance-eligible transactions instead of creating new sheets,
 * avoiding the first-run wizard blocker on sheet creation.
 */

const SCREENSHOT_DIR = 'artifacts/visual-review/review-decision-button-visibility'

async function dismissAndClick(page, selector: string): Promise<void> {
	await page.evaluate((sel) => {
		document.querySelectorAll('#firstrunwizard').forEach((el) => el.remove())
		document.querySelectorAll('.modal-mask--opaque').forEach((el) => el.remove())
		const app = document.querySelector('#app')
		if (app) {
			app.style.removeProperty('pointer-events')
			app.style.removeProperty('opacity')
		}
		const el = document.querySelector<HTMLElement>(sel)
		if (!el) {
			throw new Error(`element not found: ${sel}`)
		}
		const rect = el.getBoundingClientRect()
		const event = new MouseEvent('click', {
			bubbles: true,
			cancelable: true,
			composed: true,
			view: window,
			clientX: rect.left + rect.width / 2,
			clientY: rect.top + rect.height / 2,
		})
		el.dispatchEvent(event)
	}, selector)
}

async function captureButtonEvidence(page): Promise<{ backgroundColor: string; color: string; contrastRatio: number; [key: string]: any }> {
	return await page.evaluate(() => {
		const btn = document.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')
		if (!btn) {
			throw new Error('decision-confirm-btn not found')
		}
		const style = window.getComputedStyle(btn)
		const bg = style.backgroundColor
		const fg = style.color

		const parseRgb = (cssColor: string): { r: number; g: number; b: number } | null => {
			const m = cssColor.match(/rgba?\((\d+)\s*,\s*(\d+)\s*,\s*(\d+)/)
			if (!m) return null
			return { r: +m[1], g: +m[2], b: +m[3] }
		}
		const linearize = (c: number): number => {
			const v = c / 255
			return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4)
		}
		const luminance = (rgb: { r: number; g: number; b: number }): number =>
			0.2126 * linearize(rgb.r) + 0.7152 * linearize(rgb.g) + 0.0722 * linearize(rgb.b)
		const contrast = (a: { r: number; g: number; b: number }, b: { r: number; g: number; b: number }): number => {
			const la = luminance(a)
			const lb = luminance(b)
			const lighter = Math.max(la, lb)
			const darker = Math.min(la, lb)
			return (lighter + 0.05) / (darker + 0.05)
		}

		const fgRgb = parseRgb(fg)
		const bgRgb = parseRgb(bg)
		let contrastRatio = 0
		if (fgRgb && bgRgb) contrastRatio = contrast(fgRgb, bgRgb)

		return {
			backgroundColor: bg,
			color: fg,
			display: style.display,
			visibility: style.visibility,
			opacity: style.opacity,
			pointerEvents: style.pointerEvents,
			disabled: btn.hasAttribute('disabled'),
			ariaDisabled: btn.getAttribute('aria-disabled') ?? null,
			pettycashReview: style.getPropertyValue('--pettycash-review')?.trim() || null,
			colorError: style.getPropertyValue('--color-error')?.trim() || null,
			colorErrorText: style.getPropertyValue('--color-error-text')?.trim() || null,
			contrastRatio: Math.round(contrastRatio * 100) / 100,
		}
	})
}

async function runFinanceCombination(
	page,
	decision: 'REJECT' | 'RETURN',
	theme: 'light' | 'dark',
	viewport: 'desktop' | 'mobile',
): Promise<any> {
	if (viewport === 'desktop') {
		await page.setViewportSize({ width: 1280, height: 720 })
	} else {
		await page.setViewportSize({ width: 390, height: 844 })
	}

	if (theme === 'dark') {
		await page.evaluate(() => {
			document.documentElement.setAttribute('data-theme-dark', 'true')
		})
	} else {
		await page.evaluate(() => {
			document.documentElement.removeAttribute('data-theme-dark')
		})
	}

	// Navigate to finance queue. The first-run wizard may appear; dismiss it via DOM removal.
	await page.goto('/apps/pettycash/#/reviews/finance')
	await page.waitForTimeout(1000)

	// Remove wizard and click the first sheet title
	await page.evaluate(() => {
		document.querySelectorAll('#firstrunwizard').forEach((el) => el.remove())
		document.querySelectorAll('.modal-mask--opaque').forEach((el) => el.remove())
		const app = document.querySelector('#app')
		if (app) {
			app.style.removeProperty('pointer-events')
			app.style.removeProperty('opacity')
		}
		const titles = Array.from(document.querySelectorAll<HTMLElement>('[data-testid="sheet-title"]')).filter(
		(el) => el.offsetParent !== null || el.style.display !== 'none'
	)
		if (titles.length === 0) {
			throw new Error('no sheet titles visible in finance queue')
		}
		const target = titles[0]
		const rect = target.getBoundingClientRect()
		const event = new MouseEvent('click', {
			bubbles: true, cancelable: true, composed: true, view: window,
			clientX: rect.left + rect.width / 2, clientY: rect.top + rect.height / 2,
		})
		target.dispatchEvent(event)
	})

	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })

	const card = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(card).toBeVisible()
	await card.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()

	const decideSelector = `[data-testid="transaction-card-decide-${decision.toLowerCase()}"]`
	await card.locator(decideSelector).click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })

	const evidence = await captureButtonEvidence(page)

	const safeName = `Finance-${decision}-${theme}-${viewport}`
	await page.screenshot({
		path: `${SCREENSHOT_DIR}/${safeName}.png`,
		fullPage: true,
	})

	// Enter explanation and verify button remains enabled
	await page.locator('[data-testid="decision-note-input"]').fill(`Verify ${decision} explanation`)
	const confirmBtn = page.locator('[data-testid="decision-confirm-btn"]')
	await expect(confirmBtn).toBeVisible()
	const isDisabled = await confirmBtn.getAttribute('disabled')
	expect(isDisabled).toBeNull()

	// Cancel without submitting
	await page.locator('[data-testid="decision-cancel-btn"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).not.toBeVisible({ timeout: 10000 })

	return evidence
}

test.describe('Finance Manager visibility verification (seeded fixtures)', () => {
	test('Finance Manager - Reject - Light - Desktop', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'REJECT', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Light - Desktop', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'RETURN', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Dark - Desktop', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'REJECT', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Dark - Desktop', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'RETURN', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Light - 390px mobile', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'REJECT', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Light - 390px mobile', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'RETURN', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'REJECT', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runFinanceCombination(page, 'RETURN', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})
})
