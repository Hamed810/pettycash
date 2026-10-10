import { test, expect, type Browser, type Page } from '@playwright/test'
import { execFileSync } from 'node:child_process'
import { randomBytes } from 'node:crypto'
import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { recordResponses } from './record-responses'

/**
 * US6 end-to-end: history, evidence deactivation, authorization, and public links (T099).
 *
 * ## Multi-identity by design
 *
 * Every journey here needs more than one principal — a reviewer for a correction,
 * a stranger for an authorization refusal, a disabled actor, an administrator
 * for link administration — and the global session is a single purchaser. So
 * each test mints disposable probe users (`probe-user-fixture.php create`),
 * provisions them, signs them in through the real login form, and purges them
 * in a `finally`, exactly so a crashed run cannot leave a promoted or disabled
 * account behind for the next suite. Nothing here touches `pettycash-e2e` or
 * `employee1`, whose roles and balances other suites depend on.
 *
 * Shelling out from a browser suite is deliberate: user provisioning, role
 * grants and disable/enable are container operations with dedicated scripts
 * (`scripts/dev/in-container`, `scripts/dev/occ`), and reimplementing them
 * through the UI would test Nextcloud's admin screens rather than this app.
 * What the UI must do — review, correct, deactivate, refuse, link — stays in
 * the browser; only principal management leaves it.
 *
 * Selector notes: purchaser surfaces use `data-test`, review surfaces use
 * `data-testid`, and both are addressed literally. `NcSelect` options are the
 * library's own DOM, so options are picked by accessible name, never by
 * `data-test`.
 */

// NOTE: there is no usable shared E2E cost center or expense type under those
// names — `seed-e2e-fixtures.php` was never run in this lab, and its fixed ids
// would clobber the shared purchaser anyway. The globally seeded
// 'Contract Fixture Expense Type' is reused; tests needing review scope create
// a dedicated cost center (unique code per run) and deactivate it afterwards,
// so no suite ever meets another suite's configuration in a dropdown.
const E2E_EXPENSE_TYPE = 'Contract Fixture Expense Type'

function sh(script: string, args: string[]): string {
	return execFileSync(script, args, { cwd: process.cwd(), encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] })
}

function stamp(label: string): void {
	// eslint-disable-next-line no-console
	console.log(`[T099:${Date.now()}] ${label}`)
}

function createProbe(tag: string, admin = false): { uid: string; password: string } {
	const uid = `ae-${tag}-${randomBytes(4).toString('hex')}`
	const password = randomBytes(16).toString('hex')
	const args = ['php', 'tests/Contract/Fixture/probe-user-fixture.php', 'create', uid, password]
	if (admin) args.push('admin')
	sh('scripts/dev/in-container', args)
	// Suppress the firstrunwizard modal for the probe: it sits above the app
	// and intercepts every pointer event, so a fresh account's first click
	// retries until the test times out. The value mirrors what the wizard's
	// own disable endpoint writes (current major.minor.patch); read it from
	// `occ status` rather than hardcoding it so a server upgrade cannot
	// silently re-enable the modal. Per-user, so no other account is touched.
	stamp(`occ-status-start ${uid}`)
	const versionstring = execFileSync('scripts/dev/occ', ['status', '--output=json'], {
		cwd: process.cwd(),
		encoding: 'utf8'
	}).trim()
	stamp(`occ-status-done ${uid}`)
	const version = (JSON.parse(versionstring) as { versionstring?: string }).versionstring ?? '34.0.2'
	stamp(`occ-setting-start ${uid}`)
	sh('scripts/dev/occ', ['user:setting', uid, 'firstrunwizard', 'show', version])
	stamp(`occ-setting-done ${uid}`)
	return { uid, password }
}

function purgeProbe(uid: string): void {
	sh('scripts/dev/in-container', ['php', 'tests/Contract/Fixture/probe-user-fixture.php', 'purge', uid])
}

async function loginAs(browser: Browser, uid: string, password: string): Promise<{ page: Page; close: () => Promise<void> }> {
	// Blank storage state, stated rather than assumed: a plain newContext()
	// inherits the global purchaser session, which redirects /login to the
	// dashboard and fails on the login fields while reading like a product
	// fault (measured). The override keeps every probe session anonymous.
	stamp(`newContext-start ${uid}`)
	const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
	stamp(`newContext-done ${uid}`)
	const page = await context.newPage()
	recordResponses(page)
	await page.goto('/login', { waitUntil: 'networkidle' })
	// Wait for the Vue login app to render its fields rather than racing it:
	// without this a slow first paint fails on `fill` with no indication why.
	await page.waitForSelector('input[name="user"]', { timeout: 20000 })
	await page.fill('input[name="user"]', uid)
	await page.fill('input[name="password"]', password)
	await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])

	// Fresh accounts are greeted by the firstrunwizard modal, which sits above
	// the app and intercepts every pointer event — a click on any app control
	// then retries until the test times out while reading like a product fault
	// (measured: the create-sheet click retried for the whole test budget with
	// `#firstrunwizard` named as the interceptor in the trace log). Dismiss it
	// once per session if it is showing; a missing wizard is the normal case
	// for returning users, not an error.
	await dismissFirstRunWizard(page)
	return { page, close: () => context.close() }
}

/**
 * Dismiss Nextcloud's firstrunwizard if it is covering the app.
 *
 * Called after navigations to app pages rather than only at login: the modal
 * appears on first app render, which can be after the login landing page has
 * already settled. Dismissal persists per user, so exactly one call per
 * session does anything and the rest are no-ops.
 */
async function dismissFirstRunWizard(page: Page): Promise<void> {
	// Matched by accessible name rather than by attribute: whether the X is an
	// `aria-label` or text content is the apps' markup detail, and either reads
	// as "Close" to the user doing the dismissing.
	const wizardClose = page.locator('#firstrunwizard').getByRole('button', { name: 'Close' })
	if ((await wizardClose.count()) > 0) {
		await wizardClose.first().click()
		await page.locator('#firstrunwizard').waitFor({ state: 'detached', timeout: 15000 })
	}
}

/** In-page OCS call as the signed-in user: same session, same headers as the app itself. */
async function ocs<T>(page: Page, method: string, path: string, body?: unknown): Promise<{ status: number; data: T }> {
	return page.evaluate(
		async ({ method, path, body }) => {
			const response = await fetch(`/ocs/v2.php/apps/pettycash/api/v1${path}`, {
				method,
				headers: {
					'OCS-APIRequest': 'true',
					'Accept': 'application/json',
					'Content-Type': 'application/json',
					'X-PettyCash-Command-ID': crypto.randomUUID()
				},
				body: body === undefined ? undefined : JSON.stringify(body)
			})
			return { status: response.status, data: (await response.json()).ocs.data as T }
		},
		{ method, path, body }
	)
}

function loadEnvFile(): void {
	// Same shape as global-setup.ts: Playwright workers inherit none of the
	// wrapper environment, so the admin credential is read from the same
	// gitignored file rather than a second copy of the secret.
	try {
		const path = resolve(process.cwd(), '.env.testing')
		if (!existsSync(path)) return
		for (const line of readFileSync(path, 'utf8').split('\n')) {
			const trimmed = line.trim()
			if (trimmed === '' || trimmed.startsWith('#')) continue
			const separator = trimmed.indexOf('=')
			if (separator === -1) continue
			const key = trimmed.slice(0, separator).trim()
			if (key !== '' && process.env[key] === undefined) {
				process.env[key] = trimmed.slice(separator + 1).trim()
			}
		}
	} catch {
		// Absent file: adminCreds() below fails loudly instead.
	}
}

function adminCreds(): { user: string; password: string } {
	loadEnvFile()
	const user = (process.env.PETTYCASH_E2E_ADMIN_USER ?? '').trim()
	const password = (process.env.PETTYCASH_E2E_ADMIN_PASSWORD ?? '').trim()
	if (user === '' || password === '') {
		throw new Error(
			'PETTYCASH_E2E_ADMIN_USER/PASSWORD are required for the admin-provisioned journeys. '
			+ 'See tests/E2E/global-setup.ts for how to create the principal.'
		)
	}
	return { user, password }
}

async function pickOption(page: Page, name: string | RegExp): Promise<void> {
	const listbox = page.getByRole('listbox').last()
	await expect(listbox, 'the select must open a listbox of options').toBeVisible()
	await listbox.getByRole('option', { name }).first().click()
}

/**
 * Pick an option from a named select, scoped to that select's own listbox.
 *
 * The bare `.last()` listbox disambiguation breaks the moment two selects
 * have rendered dropdown markup: a stale (closed) listbox from an earlier
 * select can sort after the open one, and the option match then searches the
 * wrong list until the test times out. Scoping to the select's subtree makes
 * the lookup structural instead of positional.
 */
async function pickSelectOption(page: Page, selectHook: string, name: string | RegExp): Promise<void> {
	// Both hook flavors: purchaser surfaces use `data-test`, review surfaces use `data-testid`.
	const scope = page.locator(`[data-test="${selectHook}"], [data-testid="${selectHook}"]`)
	await scope.click()
	// vue-select appends its dropdown to the document body, so the listbox
	// cannot be scoped to the select's subtree. Disambiguate by visibility
	// (a closed select's leftover markup is hidden) and recency (the just
	// opened dropdown sorts last) instead of by bare position.
	const listbox = page.getByRole('listbox').filter({ visible: true }).last()
	await expect(listbox, `the ${selectHook} select must open a listbox of options`).toBeVisible()
	if (typeof name === 'string') {
		// Matched on the option's `title`, not its accessible name: the
		// template splits display names into first/last parts, so the
		// accessible name of `AE CC 1791579748447` reads with an inserted
		// break and an exact accessible-name match can never succeed, while
		// `title` carries the whole. The `title` sits on the inner span
		// rather than the `li` itself; the click bubbles to the row handler.
		const safe = name.replace(/"/g, '')
		await listbox.locator(`span[title="${safe}"]`).click()
	} else {
		await listbox.getByRole('option', { name }).first().click()
	}
}

/** Self-service account opening (FR-013/FR-014): anyone may open their own first account. */
async function ensureOwnAccount(page: Page, uid: string, currency = 'IRR'): Promise<void> {
	// Account creation for ANOTHER user needs the petty-cash Administrator
	// assignment, which no test principal holds by design — so each probe opens
	// its own, which the endpoint permits. Asserted loudly: a silent failure
	// here surfaces pages later as an empty account select.
	const created = await ocs(page, 'POST', '/accounts', { userId: uid, currency })
	if (created.status === 201) return
	// Idempotent: a re-run meets the account it opened last time. Anything
	// else (a real refusal) still throws loudly.
	const code = (created.data as { error?: { code?: string } })?.error?.code
		?? (created.data as { code?: string })?.code
		?? ''
	if (created.status === 409 && String(code).includes('ALREADY_EXISTS')) return
	throw new Error(`account creation answered ${created.status}: ${JSON.stringify(created.data).slice(0, 200)}`)
}

async function createSheet(page: Page, title: string): Promise<void> {
	await page.goto('/apps/pettycash/#/sheets')
	await page.waitForLoadState('networkidle')
	await dismissFirstRunWizard(page)
	await page.click('[data-test="create-sheet-button"]')
	await page.fill('[data-test="sheet-title-input"]', title)
	await pickSelectOption(page, 'sheet-account-select', /.+/)
	await page.click('[data-test="sheet-create-submit"]')
	await expect(page.locator('[data-test="sheet-list-item"]', { hasText: title }).first()).toBeVisible()
	await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
	// Wait for the navigation to commit: callers read `page.url()` off this
	// helper, and a click that has only been dispatched leaves them holding
	// the list URL — which made an authorization probe open the wrong page
	// while asserting about the right one.
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
}

/** Dedicated cost center managed (and senior-managed) by the probe, for review-scope journeys. */
async function createCostCenter(adminPage: Page, code: string, name: string, managerUid: string): Promise<string> {
	const created = await ocs(adminPage, 'POST', '/configuration/cost-centers', {
		code, name, validFrom: '2026-01-01', managerUserId: managerUid, seniorManagerUserId: managerUid,
		active: true, changeReason: 'T099 audit-evidence journey'
	})
	if (created.status !== 201) {
		throw new Error(`cost center creation answered ${created.status}: ${JSON.stringify(created.data).slice(0, 200)}`)
	}
	return (created.data as { id?: string }).id ?? ''
}

/** Retire a journey cost center so later dropdowns never meet it. */
async function deactivateCostCenter(adminPage: Page, code: string): Promise<void> {
	const listed = await ocs(adminPage, 'GET', '/configuration/cost-centers?page=1&pageSize=100') as {
		status: number; data: { items?: Array<Record<string, unknown>> }
	}
	const row = (listed.data.items ?? []).find((item) => item['code'] === code)
	if (row === undefined) return
	const id = String(row['id'] ?? '')
	const updated = await ocs(adminPage, 'PATCH', `/configuration/cost-centers/${id}`, {
		costCenterId: id,
		code: row['code'],
		name: row['name'],
		validFrom: row['validFrom'] ?? row['valid_from'] ?? '2026-01-01',
		active: false,
		expectedVersion: row['version'] ?? row['lockVersion'] ?? 0
	})
	if (updated.status !== 200) {
		throw new Error(`cost center deactivation answered ${updated.status}`)
	}
}

/** Fill a complete transaction on the create form (cost center chosen by exact name). */
async function fillTransaction(page: Page, description: string, amount: string, costCenter: string): Promise<void> {
	await page.click('[data-test="create-transaction-btn"]')
	await page.fill('[data-test="expense-date-input"]', '2026-09-20')
	await page.fill('[data-test="amount-input"]', amount)
	await page.fill('[data-test="description-input"]', description)
	await pickSelectOption(page, 'cost-center-select', costCenter)
	await pickSelectOption(page, 'expense-type-select', E2E_EXPENSE_TYPE)
	await page.click('[data-test="transaction-save-draft"]')
}

/** Upload evidence on the transaction detail (the create form has no transaction id yet). */
async function uploadEvidence(page: Page, name: string, mimeType: string): Promise<void> {
	await page.click('[data-test="view-transaction"]')
	await page.click('[data-test="upload-evidence-btn"]')
	await page.setInputFiles('[data-test="evidence-upload-input"]', {
		name,
		mimeType,
		buffer: Buffer.from('audit evidence bytes')
	})
	await page.click('[data-test="upload-btn"]')
	await expect(page.locator('[data-test="evidence-list-item"]').first()).toContainText(name)
}

async function backToSheet(page: Page): Promise<void> {
	await page.goBack()
	await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
}

async function submitSheet(page: Page): Promise<void> {
	await page.click('[data-test="submit-sheet-button"]')
	await expect(page.locator('[data-test="sheet-status"]')).toContainText('pettycash.status.costCenter')
}

test.describe('Audit and evidence journeys', () => {
	// Multi-identity setup is slow: each test mints probe users through the
	// container, signs sessions in through the real login form, and drives
	// full purchaser flows. The default 30s budget expires mid-setup and reads
	// as a product failure, so this file budgets per test instead.
	test.describe.configure({ timeout: 180000 })
	test('immutable history across return and correction', async ({ browser }) => {
		const purchaser = createProbe('hist')
		const ccCode = `AECC${Date.now().toString(36).toUpperCase()}`
		const ccName = `AE CC ${Date.now()}`
		// The cost center is dedicated to this run and managed by the probe
		// itself: shared centers would entangle this suite with the contract
		// fixtures' manager assignments. Created (and later retired) by the
		// persistent admin over the configuration API.
		const admin = await loginAs(browser, adminCreds().user, adminCreds().password)
		try {
			await createCostCenter(admin.page, ccCode, ccName, purchaser.uid)
		} finally {
			await admin.close()
		}
		try {
			const session = await loginAs(browser, purchaser.uid, purchaser.password)
			await ensureOwnAccount(session.page, purchaser.uid)
			const page = session.page
			try {
				const title = `AE History ${Date.now()}`
				await createSheet(page, title)
				await fillTransaction(page, 'Audit history expense', '50000', ccName)
					await uploadEvidence(page, 'receipt.pdf', 'application/pdf')
					await backToSheet(page)
					await submitSheet(page)

					// Self-review is permitted (FR-008): the same principal holds the
					// manager role and scope for this cost center, so it returns its
					// own transaction without a second account.
					await page.goto('/apps/pettycash/#/reviews/cost-center')
					await page.locator('[data-testid^="queue-row-"]', { hasText: ccName }).first().locator('[data-testid="view-details-btn"]').click()
					await pickSelectOption(page, 'decision-select', /return/i)
					await page.fill('[data-testid="explanation-input"]', 'Needs a clearer description')
					await page.click('[data-testid="submit-decision"]')

					// Correct and resubmit from the transaction form.
					await page.goto('/apps/pettycash/#/sheets')
					await page.locator('[data-test="sheet-list-item"]', { hasText: title }).first().click()
					await expect(page.locator('[data-test="sheet-detail-view"]')).toBeVisible()
					await page.click('[data-test="view-transaction"]')
					// A returned transaction is corrected, not edited: the detail
					// offers `create-correction-btn`, and the form resubmits the
					// correction draft (FR-047/FR-048).
					await page.click('[data-test="create-correction-btn"]')
					await page.fill('[data-test="amount-input"]', '55000')
					// Save first (PATCH creates revision 2), then resubmit: the
					// resubmit endpoint restarts review of the current draft, it
					// does not persist form edits itself. Saving lands back on
					// the sheet detail (FR-088), so return through the row.
					await page.click('[data-test="transaction-save-draft"]')
					await page.click('[data-test="view-transaction"]')
					await page.click('[data-test="create-correction-btn"]')
					await page.click('[data-test="resubmit-correction-btn"]')

					// History assertions: two immutable revisions, the RETURN
					// decision with its explanation and actor preserved.
					await page.click('[data-test="view-transaction"]')
					await expect(page.locator('[data-test="revision-tab"]')).toHaveCount(2)
					const decisions = page.locator('[data-test="decision-item"]')
					await expect(decisions.filter({ hasText: 'RETURN' })).toHaveCount(1)
					await expect(decisions.filter({ hasText: 'Needs a clearer description' })).toHaveCount(1)
					await expect(decisions.filter({ hasText: purchaser.uid })).toHaveCount(1)

					// And the formal audit timeline shows the submission.
					await page.click('[data-test="view-audit-btn"]')
					await expect(page.locator('[data-test="audit-event"]', { hasText: 'SUBMITTED' }).first()).toBeVisible()
				} finally {
					await session.close()
				}
		} finally {
			const adminClose = await loginAs(browser, adminCreds().user, adminCreds().password)
			try {
				await deactivateCostCenter(adminClose.page, ccCode)
			} finally {
				await adminClose.close()
			}
			purgeProbe(purchaser.uid)
		}
	})

	test('evidence deactivation keeps history listed', async ({ browser }) => {
		const purchaser = createProbe('deact')
		try {
			const session = await loginAs(browser, purchaser.uid, purchaser.password)
			await ensureOwnAccount(session.page, purchaser.uid)
			const page = session.page
			try {
				const title = `AE Deactivation ${Date.now()}`
				await createSheet(page, title)
				await fillTransaction(page, 'Deactivation expense', '50000', 'Contract Fixture Cost Center')
				await uploadEvidence(page, 'receipt.pdf', 'application/pdf')
				await backToSheet(page)
				await submitSheet(page)

				// No removal control exists post-submission by design (FR-058 is
				// pre-submission only), so deactivation goes through the endpoint
				// the control would call, as this signed-in purchaser.
				await page.click('[data-test="view-transaction"]')
				const evidenceId: string = await page.evaluate(async () => {
					const headers = { 'OCS-APIRequest': 'true', 'Accept': 'application/json' }
					const txId = location.hash.match(/\/transactions\/([^/#?]+)/)?.[1] ?? ''
					const detail = await (await fetch(`/ocs/v2.php/apps/pettycash/api/v1/transactions/${txId}`, { headers })).json()
					const item = detail.ocs.data.evidence[0]
					const deactivated = await fetch(`/ocs/v2.php/apps/pettycash/api/v1/evidence/${item.id}`, {
						method: 'DELETE',
						headers: { ...headers, 'X-PettyCash-Command-ID': crypto.randomUUID() }
					})
					if (!deactivated.ok) throw new Error(`deactivate answered ${deactivated.status}`)
					const meta = await deactivated.json()
					if (meta.ocs.data.active !== false) throw new Error('deactivation did not report active=false')
					return item.id as string
				})
				expect(evidenceId).not.toBe('')

				await page.reload()
				await expect(page.locator('[data-test="evidence-list-item"]', { hasText: 'receipt.pdf' })).toBeVisible()
				await expect(page.locator('[data-test="deactivation-info"]').first()).toBeVisible()
			} finally {
				await session.close()
			}
		} finally {
			purgeProbe(purchaser.uid)
		}
	})

	test('disabled actors keep their historical identity', async ({ browser }) => {
		const purchaser = createProbe('disabled')
		const manager = createProbe('mgr')
		const ccCode = `AEDC${Date.now().toString(36).toUpperCase()}`
		const ccName = `AE Disabled CC ${Date.now()}`
		const admin = await loginAs(browser, adminCreds().user, adminCreds().password)
		try {
			await createCostCenter(admin.page, ccCode, ccName, manager.uid)
		} finally {
			await admin.close()
		}
		try {
			const purchaserSession = await loginAs(browser, purchaser.uid, purchaser.password)
			await ensureOwnAccount(purchaserSession.page, purchaser.uid)
			try {
				const title = `AE Disabled ${Date.now()}`
				await createSheet(purchaserSession.page, title)
				await fillTransaction(purchaserSession.page, 'Disabled actor expense', '50000', ccName)
				await backToSheet(purchaserSession.page)
				await submitSheet(purchaserSession.page)
			} finally {
				await purchaserSession.close()
			}

			// Disable mid-flight: decisions, rows and audit must keep the
			// purchaser's snapshot rather than blanking or dropping it.
			execFileSync('scripts/dev/occ', ['user:disable', purchaser.uid], { cwd: process.cwd(), stdio: 'ignore' })
			try {
				const mgrSession = await loginAs(browser, manager.uid, manager.password)
				try {
					await mgrSession.page.goto('/apps/pettycash/#/reviews/cost-center')
					await mgrSession.page.locator('[data-testid^="queue-row-"]', { hasText: ccName }).first().locator('[data-testid="view-details-btn"]').click()
					await expect(mgrSession.page.locator('[data-testid="transaction-detail"]')).toBeVisible()
					// The review surface renders the disabled purchaser's
					// transaction history rather than blanking it; the
					// actor identity itself is pinned on the audit timeline below.
					await expect(mgrSession.page.locator('[data-testid="revision-info"]')).toBeVisible()
					// The formal audit timeline names the disabled purchaser too.
					const txId = mgrSession.page.url().match(/\/reviews\/[^/]+\/([^/#?]+)/)?.[1] ?? ''
					expect(txId, 'review detail URL must carry the transaction id').not.toBe('')
					await mgrSession.page.goto(`/apps/pettycash/#/audit/${txId}`)
					await expect(
						mgrSession.page.locator('[data-test="audit-event"]', { hasText: purchaser.uid }).first()
					).toBeVisible()
				} finally {
					await mgrSession.close()
				}
			} finally {
				execFileSync('scripts/dev/occ', ['user:enable', purchaser.uid], { cwd: process.cwd(), stdio: 'ignore' })
			}
		} finally {
			const adminClose = await loginAs(browser, adminCreds().user, adminCreds().password)
			try {
				await deactivateCostCenter(adminClose.page, ccCode)
			} finally {
				await adminClose.close()
			}
			purgeProbe(manager.uid)
			purgeProbe(purchaser.uid)
		}
	})

	test('out-of-scope readers see no foreign records', async ({ browser }) => {
		const owner = createProbe('owner')
		const stranger = createProbe('stranger')
		try {
			const ownerSession = await loginAs(browser, owner.uid, owner.password)
			await ensureOwnAccount(ownerSession.page, owner.uid)
			let sheetUrl = ''
			try {
				const title = `AE Private ${Date.now()}`
				await createSheet(ownerSession.page, title)
				sheetUrl = ownerSession.page.url()
			} finally {
				await ownerSession.close()
			}

			const strangerSession = await loginAs(browser, stranger.uid, stranger.password)
			try {
				// A sheet the caller may not see: an error, never the content.
				await strangerSession.page.goto(sheetUrl)
				await expect(strangerSession.page.locator('[data-test="submission-errors"]')).toBeVisible()
				await expect(strangerSession.page.locator('[data-test="sheet-detail-view"]')).not.toContainText(`AE Private`)
			} finally {
				await strangerSession.close()
			}
		} finally {
			purgeProbe(owner.uid)
			purgeProbe(stranger.uid)
		}
	})

	test('public evidence link opens anonymously and dies on revocation', async ({ browser }) => {
		const administrator = createProbe('linkadmin', true)
		try {
			const adminSession = await loginAs(browser, administrator.uid, administrator.password)
			await ensureOwnAccount(adminSession.page, administrator.uid)
			const page = adminSession.page
			try {
				const title = `AE Link ${Date.now()}`
				await createSheet(page, title)
				await fillTransaction(page, 'Link expense', '50000', 'Contract Fixture Cost Center')
				await uploadEvidence(page, 'archive.zip', 'application/zip')
				await backToSheet(page)

				// Link administration lives on the audit view (admin-only controls).
				await page.click('[data-test="view-transaction"]')
				await page.click('[data-test="view-audit-btn"]')
				await page.click('[data-test="create-link-btn"]')
				const linkUrl = await page.locator('[data-test="public-link-url"]').textContent()
				expect(linkUrl, 'the creation must disclose the URL once').toContain('/public/evidence/')

				// Anonymous: a fresh context with no session at all. The blank
				// storage state is load-bearing, not hygiene: a plain context
				// inherits the global purchaser session, and the visit would
				// no longer prove unauthenticated access.
				const anon = await browser.newContext({ storageState: { cookies: [], origins: [] } })
				try {
					const anonPage = await anon.newPage()
					// `goto` rejects with "Download is starting" when the response is an
					// attachment: that rejection IS half the success signal, and the
					// download event is the other half.
					const [download] = await Promise.all([
						anonPage.waitForEvent('download'),
						anonPage.goto(linkUrl as string).catch(() => undefined)
					])
					expect(download.suggestedFilename()).toContain('.zip')

					// Revoke: the same token is refused with no further detail.
					await page.click('[data-test="deactivate-link-btn"]')
					await expect(page.locator('[data-test="link-inactive"]')).toBeVisible()
					const refused = await anonPage.goto(linkUrl as string)
					expect(refused?.status(), 'a revoked link must be refused').toBe(404)
				} finally {
					await anon.close()
				}
			} finally {
				await adminSession.close()
			}
		} finally {
			purgeProbe(administrator.uid)
		}
	})
})
