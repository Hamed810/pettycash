import { test, expect } from '@playwright/test'

/**
 * Bug verification: review-decision-button-visibility
 *
 * Verifies the DecisionConfirmation modal's Reject and Return confirm buttons
 * are visible, readable, and correctly styled across themes and viewports,
 * using the live Nextcloud lab at http://localhost:8081.
 *
 * Authentication: uses the existing `pettycash-e2e` storage state
 * (cost-center manager + senior manager). Finance Manager is NOT available
 * through any authorised account — documented as a blocker in test.md.
 *
 * Sheet lifecycle: a newly submitted sheet appears in the Cost Center queue.
 * For Senior Manager tests, the sheet is first promoted via a Cost Center
 * APPROVE decision so it lands in the Senior queue.
 */

const SCREENSHOT_DIR = 'artifacts/visual-review/review-decision-button-visibility'

interface ButtonEvidence {
	backgroundColor: string
	color: string
	display: string
	visibility: string
	opacity: string
	pointerEvents: string
	disabled: boolean | null
	ariaDisabled: string | null
	pettycashReview: string | null
	colorError: string | null
	colorErrorText: string | null
	contrastRatio: number
}

async function createAndSubmitSheet(page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await page.click('[data-test="sheet-account-select"]')
	await page.getByRole('option').first().click()
	await page.click('[data-test="sheet-create-submit"]')
	await expect(page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first()).toBeVisible()

	await page.locator('[data-test="sheet-list-item"]:visible', { hasText: title }).first().click()
	await page.waitForSelector('[data-test="sheet-detail-view"]')

	await page.click('[data-test="create-transaction-btn"]')
	await page.evaluate(() => {
		const input = document.querySelector<HTMLInputElement>('[data-test="expense-date-input"]')
		if (input) {
			input.value = '2026-10-01'
			input.dispatchEvent(new Event('input', { bubbles: true }))
			input.dispatchEvent(new Event('change', { bubbles: true }))
		}
	})
	await page.fill('[data-test="amount-input"]', '50000')
	await page.fill('[data-test="description-input"]', 'verify expense')
	await page.click('[data-test="cost-center-select"]')
	await page.getByRole('option').first().click()
	await page.click('[data-test="expense-type-select"]')
	await page.getByRole('option').first().click()
	await page.click('[data-test="submit-btn"]')

	await page.click('[data-test="submit-sheet-button"]')
	const closeWorkspace = page.locator('[data-test="close-sheet-workspace"]')
	if (await closeWorkspace.count() > 0) {
		await closeWorkspace.click()
		await expect(page.locator('[data-test="sheet-workspace"]')).toHaveCount(0)
	}
}

async function openDecisionConfirmation(page, stage: string, decision: 'REJECT' | 'RETURN' | 'APPROVE', sheetTitle: string): Promise<void> {
	await page.goto(`/apps/pettycash/#/reviews/${stage}`)
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: sheetTitle })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })

	const firstCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(firstCard).toBeVisible()
	await firstCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()

	const decideSelector = `[data-testid="transaction-card-decide-${decision.toLowerCase()}"]`
	await firstCard.locator(decideSelector).click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
}

async function confirmDecision(page, explanation: string = 'verify confirmation'): Promise<void> {
	await page.locator('[data-testid="decision-note-input"]').fill(explanation)
	const confirmBtn = page.locator('[data-testid="decision-confirm-btn"]')
	await expect(confirmBtn).toBeVisible()
	const isDisabled = await confirmBtn.getAttribute('disabled')
	expect(isDisabled).toBeNull()
	await confirmBtn.click()
	await expect(page.locator('[data-testid="decision-modal"]')).not.toBeVisible({ timeout: 15000 })
}

async function cancelDecision(page): Promise<void> {
	await page.locator('[data-testid="decision-cancel-btn"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).not.toBeVisible({ timeout: 10000 })
}

async function captureButtonEvidence(page): Promise<ButtonEvidence> {
	return await page.evaluate(() => {
		const btn = document.querySelector<HTMLElement>('[data-testid="decision-confirm-btn"]')
		if (!btn) {
			throw new Error('decision-confirm-btn not found')
		}
		const style = window.getComputedStyle(btn)

		const bg = style.backgroundColor
		const fg = style.color

		const pettycashReview = style.getPropertyValue('--pettycash-review')?.trim() || null
		const colorError = style.getPropertyValue('--color-error')?.trim() || null
		const colorErrorText = style.getPropertyValue('--color-error-text')?.trim() || null

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
			pettycashReview,
			colorError,
			colorErrorText,
			contrastRatio: Math.round(contrastRatio * 100) / 100,
		}
	})
}

/**
 * Creates a sheet, submits it, promotes via CC APPROVE to the Senior queue,
 * then opens DecisionConfirmation for the given decision on the Senior queue.
 */
async function seniorDecisionFlow(page, title: string, decision: 'REJECT' | 'RETURN'): Promise<void> {
	await createAndSubmitSheet(page, title)

	// Promote via Cost Center APPROVE so the sheet reaches the Senior queue.
	await page.goto('/apps/pettycash/#/reviews/cost-center')
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: title })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })
	const ccCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(ccCard).toBeVisible()
	await ccCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
	await ccCard.locator('[data-testid="transaction-card-decide-approve"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
	await confirmDecision(page, 'CC approve to promote to senior queue')

	// Now on the Senior queue — open DecisionConfirmation for the target decision.
	await page.goto('/apps/pettycash/#/reviews/senior')
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: title })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })
	const seniorCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(seniorCard).toBeVisible()
	await seniorCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
	const decideSelector = `[data-testid="transaction-card-decide-${decision.toLowerCase()}"]`
	await seniorCard.locator(decideSelector).click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
}

async function financeDecisionFlow(page, title: string, decision: 'REJECT' | 'RETURN'): Promise<void> {
	await createAndSubmitSheet(page, title)

	// Promote via Cost Center APPROVE.
	await page.goto('/apps/pettycash/#/reviews/cost-center')
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: title })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })
	const ccCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(ccCard).toBeVisible()
	await ccCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
	await ccCard.locator('[data-testid="transaction-card-decide-approve"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
	await confirmDecision(page, 'CC approve to promote to senior queue')

	// Promote via Senior APPROVE.
	await page.goto('/apps/pettycash/#/reviews/senior')
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: title })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })
	const seniorCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(seniorCard).toBeVisible()
	await seniorCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
	await seniorCard.locator('[data-testid="transaction-card-decide-approve"]').click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
	await confirmDecision(page, 'Senior approve to promote to finance queue')

	// Now on the Finance queue — open DecisionConfirmation for the target decision.
	await page.goto('/apps/pettycash/#/reviews/finance')
	await page
		.locator('[data-testid="sheet-title"]:visible')
		.filter({ hasText: title })
		.first()
		.click()
	await expect(page.locator('[data-testid="sheet-details-modal"]')).toBeVisible({ timeout: 15000 })
	const financeCard = page.locator('[data-testid^="transaction-review-card-"]').first()
	await expect(financeCard).toBeVisible()
	await financeCard.locator('[data-testid="transaction-card-title"]').click()
	await expect(page.locator('[data-testid="transaction-expanded-detail"]')).toBeVisible()
	const decideSelector = `[data-testid="transaction-card-decide-${decision.toLowerCase()}"]`
	await financeCard.locator(decideSelector).click()
	await expect(page.locator('[data-testid="decision-modal"]')).toBeVisible({ timeout: 10000 })
}

async function runCombination(
	page,
	role: string,
	decision: 'REJECT' | 'RETURN',
	theme: 'light' | 'dark',
	viewport: 'desktop' | 'mobile',
): Promise<ButtonEvidence> {
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

	const title = `Verify ${role} ${decision} ${theme} ${viewport} ${Date.now()}`

	if (role === 'Senior') {
		await seniorDecisionFlow(page, title, decision)
	} else if (role === 'Finance') {
		await financeDecisionFlow(page, title, decision)
	} else {
		await createAndSubmitSheet(page, title)
		const stage = 'cost-center'
		await openDecisionConfirmation(page, stage, decision, title)
	}

	const evidence = await captureButtonEvidence(page)

	const safeName = `${role.replace(/\s+/g, '-')}-${decision}-${theme}-${viewport}`
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

	// Cancel without submitting to avoid mutating data
	await cancelDecision(page)

	return evidence
}

test.describe('DecisionConfirmation visibility verification (CC + Senior roles)', () => {
	test('Cost Center Manager - Reject - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'REJECT', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Return - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'RETURN', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Reject - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'REJECT', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Return - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'RETURN', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Reject - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'REJECT', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Return - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'RETURN', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Reject - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'REJECT', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Return - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'RETURN', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Reject - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'REJECT', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Return - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'RETURN', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Reject - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'REJECT', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Return - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'RETURN', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Reject - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'REJECT', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Cost Center Manager - Return - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Cost Center', 'RETURN', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Reject - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'REJECT', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Senior Manager - Return - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Senior', 'RETURN', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'REJECT', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Light - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'RETURN', 'light', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'REJECT', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Dark - Desktop', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'RETURN', 'dark', 'desktop')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'REJECT', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Light - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'RETURN', 'light', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Reject - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'REJECT', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})

	test('Finance Manager - Return - Dark - 390px mobile', async ({ page }) => {
		const evidence = await runCombination(page, 'Finance', 'RETURN', 'dark', 'mobile')
		test.info().annotations.push({ type: 'evidence', description: JSON.stringify(evidence) })
		expect(evidence.contrastRatio).toBeGreaterThanOrEqual(4.5)
		expect(evidence.backgroundColor).not.toBe('rgba(0, 0, 0, 0)')
		expect(evidence.backgroundColor).not.toBe('')
	})
})
