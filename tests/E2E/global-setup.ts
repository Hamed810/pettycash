import { existsSync, readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { chromium, type FullConfig } from '@playwright/test'
import type { BrowserContext } from '@playwright/test'

/**
 * Signs one real user in through Nextcloud's login form and saves the session, so every spec starts
 * from an authenticated browser instead of a 401 page.
 *
 * ## Why this exists at all
 *
 * `playwright.config.ts` used to declare no authentication of any kind -- no `globalSetup`, no
 * `storageState`, no `httpCredentials` -- while `purchaser-submit.spec.ts` navigated straight to
 * `/apps/pettycash/`, which answers **401**. The suite was therefore 28/28 red and had never once run
 * against a signed-in session. Nothing noticed, because `npm run check` does not run `test:e2e`: the
 * quality gate is typecheck, portability, lint, stylelint, unit tests and build.
 *
 * ## Why the credential is a real account password and not the app token
 *
 * The repository already holds a Nextcloud **app token** for `PETTYCASH_TEST_PASSWORD`, and it works:
 * `GET /ocs/v2.php/apps/pettycash/api/v1/me` answers 200 with it. It cannot open the web UI, and that
 * is Nextcloud's design rather than a fault -- an app token authenticates Basic-auth/OCS callers and is
 * never accepted by the login form, which only takes the account password. Measured: the token returns
 * "Wrong login or password" from the form, and `Basic` against `/apps/pettycash/` answers 403 because
 * Nextcloud accepts Basic auth for OCS endpoints and not for app pages.
 *
 * So this logs in the way a person does, by filling the form, and the credentials come from a
 * **dedicated** `pettycash-e2e` account rather than from `employee1`. That isolation is the point: the
 * OCS contract suite authenticates as `employee1` and depends on that account's role assignments and
 * its seeded account balances, so resetting or re-purposing its password to serve the browser suite
 * would put one suite's fixture in another suite's way.
 *
 * ## Why the credentials are read from `.env.testing` and not from the environment
 *
 * A Playwright process is launched by npm, not by `scripts/dev/phpunit`, so it inherits none of the
 * environment that wrapper loads. The same gitignored file is read here instead, so there is one place
 * the local credentials live and no second copy of a secret. A `PETTYCASH_E2E_PASSWORD` that is absent
 * is a loud failure rather than a silent unauthenticated run -- an unauthenticated suite is 28/28 red and
 * reads like a product fault.
 */
const REQUIRED = ['PETTYCASH_E2E_USER', 'PETTYCASH_E2E_PASSWORD'] as const

/** Read `.env.testing` into `process.env` without adding a dependency for six lines of parsing. */
function loadEnvFile(): void {
	const path = resolve(process.cwd(), '.env.testing')
	if (!existsSync(path)) {
		return
	}
	for (const line of readFileSync(path, 'utf8').split('\n')) {
		const trimmed = line.trim()
		if (trimmed === '' || trimmed.startsWith('#')) {
			continue
		}
		const separator = trimmed.indexOf('=')
		if (separator === -1) {
			continue
		}
		const key = trimmed.slice(0, separator).trim()
		// An already-exported value wins, so a CI secret overrides the local file.
		if (key !== '' && process.env[key] === undefined) {
			process.env[key] = trimmed.slice(separator + 1).trim()
		}
	}
}

function credentials(): { user: string; password: string } {
	loadEnvFile()
	const missing = REQUIRED.filter((name) => (process.env[name] ?? '').trim() === '')
	if (missing.length > 0) {
		throw new Error(
			`The Playwright suite needs ${missing.join(' and ')} in .env.testing (or in the environment).\n`
			+ 'Without them this suite runs unauthenticated, which answers 401 and fails every test in a way\n'
			+ 'that reads like a product fault rather than a missing credential. See .env.example for how to\n'
			+ 'create the account:\n'
			+ '  scripts/dev/in-container --env NC_PASS=<password> --env OC_PASS=<password> \\\n'
			+ '    php /var/www/html/occ user:add --password-from-env --display-name "PettyCash E2E" pettycash-e2e',
		)
	}

	return {
		user: (process.env.PETTYCASH_E2E_USER as string).trim(),
		password: (process.env.PETTYCASH_E2E_PASSWORD as string).trim(),
	}
}

const STORAGE_STATE = 'test-results/.auth/e2e-storage-state.json'

async function signIn(context: BrowserContext, baseURL: string, user: string, password: string): Promise<void> {
	const page = await context.newPage()
	try {
		await page.goto(`${baseURL}/login`, { waitUntil: 'networkidle' })

		// Nextcloud 34's login page is a Vue application with no HTML <form>: the visible fields are
		// `input[name=user]` and `input[name=password]`, and submission is a `button[type=submit]`.
		// Selecting by `name` rather than by position or label is what keeps this working across the
		// translations the login page ships in.
		await page.fill('input[name="user"]', user)
		await page.fill('input[name="password"]', password)
		await Promise.all([page.waitForLoadState('networkidle'), page.click('button[type="submit"]')])

		// The failure mode this guards is the expensive one: a wrong password leaves the browser on
		// `/login` with an inline message, and every subsequent test then fails on a 401 that looks
		// like a broken product. Asserting we are actually signed in turns that into one clear failure
		// here.
		if (new URL(page.url()).pathname.startsWith('/login')) {
			const shown = (await page.textContent('body')) ?? ''
			throw new Error(
				`Signing in as '${user}' left the browser on ${page.url()}.\n`
				+ `The page says: ${shown.replace(/\s+/g, ' ').trim().slice(0, 300)}\n`
				+ 'Nextcloud also rate-limits logins per source address: if `scripts/dev/occ '
				+ 'security:bruteforce:attempts <address>` reports a non-zero delay, wait it out before '
				+ 'concluding the password is wrong.',
			)
		}

		// The second half of the check, and the one that matters more. Reaching the dashboard proves a
		// session; it does not prove the app's own page is reachable, which is a separate defect
		// (`PageController` answered 403 then 412 until it was annotated). Loading the app here means a
		// regression in that annotation fails the whole suite with one message naming the cause, instead
		// of 28 timeouts waiting for a button.
		const response = await page.goto(`${baseURL}/apps/pettycash/`, { waitUntil: 'domcontentloaded' })
		const status = response?.status() ?? 0
		if (status !== 200) {
			throw new Error(
				`Signed in as '${user}', but GET /apps/pettycash/ answered ${status}.\n`
				+ 'A signed-in user must be able to load the app page. 403 means `PageController::index()` is\n'
				+ 'missing `#[NoAdminRequired]`; 412 means it is missing `#[NoCSRFRequired]`.',
			)
		}

		// The Nextcloud first-run wizard is suppressed by a **server-side user setting**, not by clicking
		// through it, and the distinction matters.
		//
		// Background, because this cost real time to establish: a user created with `occ user:add` has
		// never seen Nextcloud, so the server overlays the "Welcome to Nextcloud!" wizard
		// (`div#firstrunwizard[role="dialog"]`, carrying `modal-mask--opaque`). It covers the app, and
		// Playwright's `page.click()` then waits for an intercepted element and times out — so all 7
		// tests in `purchaser-submit.spec.ts` failed on `waiting for
		// locator('[data-test="create-sheet-button"]')` while the app underneath had loaded and
		// authenticated correctly. This is **not** a PettyCash defect and must never be reported as one.
		//
		// Two attempts to dismiss it through the UI both failed, and both failures are informative:
		//  - The wizard has no `Close` control. `#firstrunwizard` exposes only Previous / Skip / Next, so
		//    a locator looking for "Close" (as the tour panel's inner button would suggest) matches
		//    nothing. Guessing the control from the accessibility snapshot would have left the suite red
		//    with no explanation; probing the DOM is what settled it.
		//  - `id="firstrunwizard"` is **duplicated** in the page: an empty `data-v-app` placeholder plus
		//    the dialog. A bare `#firstrunwizard` locator is a strict-mode violation, and a
		//    `.catch(() => false)` wrapped around `isVisible()` silently converted that violation into
		//    "not visible" — skipping the dismissal entirely and reporting success. A setup step that
		//    cannot decide whether it ran must fail loudly rather than swallow the reason.
		//  - Even a correct `Skip` click left the dialog on screen, so the UI route is not dependable.
		//
		// The supported mechanism is `WizardController::disable()`, which writes the user config
		// `firstrunwizard` / `show` = the server's `major.minor.patch`. Set it once per test account:
		//
		//   scripts/dev/occ user:setting pettycash-e2e firstrunwizard show 34.0.2
		//
		// That is deterministic, has no DOM dependency, and states the server version it is claiming the
		// user has seen — so the wizard reappears on a real upgrade, which is the behaviour Nextcloud
		// intends. It is environment provisioning rather than test logic, so it is documented here and in
		// `.env.example` instead of being run from `globalSetup`, which cannot reach `occ`.
	} finally {
		await page.close()
	}
}

export default async function globalSetup(config: FullConfig): Promise<void> {
	const baseURL = config.projects[0]?.use?.baseURL ?? 'http://localhost:8080'
	const { user, password } = credentials()

	const browser = await chromium.launch()
	try {
		const context = await browser.newContext()
		await signIn(context, baseURL, user, password)
		await context.storageState({ path: STORAGE_STATE })
		await context.close()
	} finally {
		await browser.close()
	}
}

export { STORAGE_STATE }
