import { test, expect } from '@playwright/test'

test.describe('Purchaser Capture and Submission', () => {
	test.beforeEach(async ({ page }) => {
		// Navigate to the app
		await page.goto('/apps/pettycash/')
		await page.waitForLoadState('networkidle')
	})

	test('should create an open sheet', async ({ page }) => {
		// Click create sheet button
		await page.click('[data-test="create-sheet-button"]')
		
		// Fill in sheet title
		await page.fill('[data-test="sheet-title-input"]', 'Test Expense Sheet')
		
		// Select account (should auto-select if only one)
		await page.click('[data-test="sheet-account-select"]')
		await page.click('[data-test="account-option"]:first-child')
		
		// Submit create sheet
		await page.click('[data-test="sheet-create-submit"]')
		
		// Verify sheet appears in list
		await expect(page.locator('[data-test="sheet-list-item"]').first()).toContainText('Test Expense Sheet')
		await expect(page.locator('[data-test="sheet-status"]').first()).toContainText('Open')
	})

	test('should add a draft transaction with minimum data', async ({ page }) => {
		// Create a sheet first
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Sheet for Transaction')
		await page.click('[data-test="sheet-create-submit"]')
		
		// Click on the sheet to open detail
		await page.click('[data-test="sheet-list-item"]:first-child')
		
		// Click add transaction
		await page.click('[data-test="add-transaction-button"]')
		
		// Fill minimum required fields: expense date and positive amount
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		
		// Save as draft
		await page.click('[data-test="transaction-save-draft"]')
		
		// Verify transaction appears in sheet
		await expect(page.locator('[data-test="transaction-list-item"]').first()).toContainText('50,000')
		await expect(page.locator('[data-test="transaction-status"]').first()).toContainText('Draft')
	})

	test('should reject incomplete submission', async ({ page }) => {
		// Create a sheet with incomplete transaction
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Incomplete Sheet')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="add-transaction-button"]')
		
		// Fill only date and amount (missing description, cost center, expense type, evidence)
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.click('[data-test="transaction-save-draft"]')
		
		// Try to submit sheet
		await page.click('[data-test="submit-sheet-button"]')
		
		// Verify validation errors
		await expect(page.locator('[data-test="validation-error"]')).toContainText('description')
		await expect(page.locator('[data-test="validation-error"]')).toContainText('cost center')
		await expect(page.locator('[data-test="validation-error"]')).toContainText('expense type')
		await expect(page.locator('[data-test="validation-error"]')).toContainText('evidence')
		
		// Verify sheet is still open
		await expect(page.locator('[data-test="sheet-status"]')).toContainText('Open')
	})

	test('should upload evidence', async ({ page }) => {
		// Create sheet and transaction
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Sheet with Evidence')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="add-transaction-button"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Test expense')
		await page.click('[data-test="cost-center-select"]')
		await page.click('[data-test="cost-center-option"]:first-child')
		await page.click('[data-test="expense-type-select"]')
		await page.click('[data-test="expense-type-option"]:first-child')
		
		// Upload evidence file
		await page.setInputFiles('[data-test="evidence-upload-input"]', {
			name: 'receipt.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('test pdf content')
		})
		
		await page.click('[data-test="transaction-save-draft"]')
		
		// Verify evidence appears
		await expect(page.locator('[data-test="evidence-list-item"]').first()).toContainText('receipt.pdf')
	})

	test('should successfully submit complete sheet', async ({ page }) => {
		// Create sheet and complete transaction
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Complete Sheet')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="add-transaction-button"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Complete expense')
		await page.click('[data-test="cost-center-select"]')
		await page.click('[data-test="cost-center-option"]:first-child')
		await page.click('[data-test="expense-type-select"]')
		await page.click('[data-test="expense-type-option"]:first-child')
		
		// Upload evidence
		await page.setInputFiles('[data-test="evidence-upload-input"]', {
			name: 'receipt.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('test pdf content')
		})
		
		await page.click('[data-test="transaction-save-draft"]')
		
		// Submit sheet
		await page.click('[data-test="submit-sheet-button"]')
		
		// Verify submission success
		await expect(page.locator('[data-test="sheet-status"]')).toContainText('Awaiting Cost Center Review')
		
		// Verify transactions are locked
		await expect(page.locator('[data-test="add-transaction-button"]')).toBeDisabled()
		
		// Verify audit event created
		await page.click('[data-test="audit-tab"]')
		await expect(page.locator('[data-test="audit-event"]').first()).toContainText('SUBMISSION')
	})

	test('should submit without requiring positive account balance', async ({ page }) => {
		// This test verifies that submission works even with zero/negative balance
		// The balance check happens at finance settlement, not submission
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Negative Balance Sheet')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="add-transaction-button"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '1000000') // Large amount
		await page.fill('[data-test="description-input"]', 'Large expense')
		await page.click('[data-test="cost-center-select"]')
		await page.click('[data-test="cost-center-option"]:first-child')
		await page.click('[data-test="expense-type-select"]')
		await page.click('[data-test="expense-type-option"]:first-child')
		
		await page.setInputFiles('[data-test="evidence-upload-input"]', {
			name: 'receipt.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('test pdf content')
		})
		
		await page.click('[data-test="transaction-save-draft"]')
		await page.click('[data-test="submit-sheet-button"]')
		
		// Should succeed even if account balance is insufficient
		await expect(page.locator('[data-test="sheet-status"]')).toContainText('Awaiting Cost Center Review')
	})
})

test.describe('Audit Trail Verification', () => {
	test('should create first formal audit event on submission', async ({ page }) => {
		await page.goto('/apps/pettycash/')
		await page.waitForLoadState('networkidle')
		
		await page.click('[data-test="create-sheet-button"]')
		await page.fill('[data-test="sheet-title-input"]', 'Audit Test Sheet')
		await page.click('[data-test="sheet-create-submit"]')
		
		await page.click('[data-test="sheet-list-item"]:first-child')
		await page.click('[data-test="add-transaction-button"]')
		
		await page.fill('[data-test="expense-date-input"]', '2026-09-20')
		await page.fill('[data-test="amount-input"]', '50000')
		await page.fill('[data-test="description-input"]', 'Audit test')
		await page.click('[data-test="cost-center-select"]')
		await page.click('[data-test="cost-center-option"]:first-child')
		await page.click('[data-test="expense-type-select"]')
		await page.click('[data-test="expense-type-option"]:first-child')
		
		await page.setInputFiles('[data-test="evidence-upload-input"]', {
			name: 'receipt.pdf',
			mimeType: 'application/pdf',
			buffer: Buffer.from('test pdf content')
		})
		
		await page.click('[data-test="transaction-save-draft"]')
		await page.click('[data-test="submit-sheet-button"]')
		
		// Check audit trail
		await page.click('[data-test="audit-tab"]')
		
		// Verify SUBMISSION event exists
		const submissionEvent = page.locator('[data-test="audit-event"]').filter({ hasText: 'SUBMISSION' })
		await expect(submissionEvent).toBeVisible()
		
		// Verify event contains actor, stage, timestamp
		await expect(submissionEvent).toContainText('CostCenter')
		await expect(submissionEvent).toContainText('test-user')
	})
})