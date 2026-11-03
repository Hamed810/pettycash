import { existsSync, readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * T405: "the E2E suite cannot run here" must be a **reported** fact, never a silent one.
 *
 * `npm run check` deliberately omits `test:e2e`. That is right — an E2E run needs a live Nextcloud
 * and a browser — but it has a consequence `playwright.config.ts`'s own `globalSetup` comment already
 * records: a missing credential or browser is invisible to every per-commit gate. This file is the
 * guard that makes the environment state explicit instead of letting a browser-dependent criterion
 * read as merely "not finished yet".
 *
 * ## What changed with constitution v1.4.0
 *
 * The suite no longer downloads Chromium, Firefox, or Edge. Every Playwright project and standalone
 * E2E runner launches **official Google Chrome** through `channel: 'chrome'`; bundled Chromium,
 * Firefox, WebKit, Edge, and silent fallback are prohibited. Chrome is a **system channel**, not a
 * Playwright download, so the old CDN probe cannot answer whether the suite can run and the old
 * downloaded-engine inventory is meaningless.
 *
 * This guard therefore asks three questions:
 *
 * 1. Is the system Chrome that Playwright resolves present on this host?
 * 2. Does the installed `playwright-core` still resolve `channel: 'chrome'` to the path checked here?
 * 3. Does `playwright.config.ts` declare only the two Chrome projects, with no other engine?
 *
 * A missing Chrome is an **environment blocker**: it is printed with the exact path and skipped, not
 * passed and not converted into a suggestion to install a different browser. The browser-dependent
 * tasks must record that blocker rather than claim browser evidence. A guard that looked in the wrong
 * place would be green on a host with Chrome and red on a host without, which is worse than having no
 * guard; question 2 is what prevents that drift.
 */
const CHROME_CHANNEL_PATH = '/opt/google/chrome/chrome'

const require_ = createRequire(import.meta.url)
const PLAYWRIGHT_ROOT = dirname(require_.resolve('playwright-core/package.json'))
const PLAYWRIGHT_REGISTRY = join(PLAYWRIGHT_ROOT, 'lib', 'server', 'registry', 'index.js')

const PLAYWRIGHT_CONFIG = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'playwright.config.ts')

describe('T405: the E2E suite is runnable on this host', () => {
	it('has official Google Chrome, the only browser the suite may launch', (ctx) => {
		if (existsSync(CHROME_CHANNEL_PATH)) {
			return
		}

		// **Skipped, not passed — and the reason is printed unconditionally.** A silent skip is the
		// failure mode this file exists to end. Chrome is a system package; no unprivileged Playwright
		// command installs it, so failing here would be the permanently red gate this file warns
		// against. The blocker is environment state, and the remedy is an authorized installation —
		// never a substitution with bundled Chromium, Firefox, WebKit, or Edge.
		console.warn(
			`\n[e2e-runnability] BLOCKED — no official Google Chrome at ${CHROME_CHANNEL_PATH}.\n`
			+ 'Constitution v1.4.0 requires every Playwright project and E2E runner to use '
			+ "`channel: 'chrome'`. Bundled Chromium, Firefox, WebKit, and Edge are not substitutes. "
			+ '`npm run test:e2e` cannot produce valid browser evidence until an authorized environment '
			+ 'step installs Google Chrome. Record this blocker; do not report a browser pass.\n',
		)
		ctx.skip()
	})

	it('resolves the Google Chrome channel where playwright-core actually looks for it', () => {
		// The guard must check the path Playwright uses, or it reports a gap that does not exist and
		// hides one that does. `registry/index.js` is the source of truth; reading it here is what makes
		// a Playwright upgrade that moves the path a loud failure instead of a silent one.
		const registry = readFileSync(PLAYWRIGHT_REGISTRY, 'utf8')
		const declared = /_createChromiumChannel\("chrome",\s*\{\s*"linux":\s*"([^"]+)"/.exec(registry)

		expect(declared?.[1]).toBe(CHROME_CHANNEL_PATH)
	})

	it('declares only official Google Chrome projects and no other engine', () => {
		const config = readFileSync(PLAYWRIGHT_CONFIG, 'utf8')
		// Read the project declarations themselves rather than a global `channel:` count: the
		// configuration's explanatory comment also names the required channel.
		const projectLines = config.split('\n').filter((line) => /name:\s*'chrome-/.test(line))

		expect(projectLines.map((line) => /name:\s*'([^']+)'/.exec(line)?.[1]))
			.toEqual(['chrome-desktop', 'chrome-mobile'])
		for (const line of projectLines) {
			expect(line).toContain("browserName: 'chromium'")
			expect(line).toContain("channel: 'chrome'")
		}

		// A real second engine cannot hide behind a matching project name.
		expect(config).not.toMatch(/browserName:\s*'(firefox|webkit)'/)
		expect(config).not.toMatch(/channel:\s*'msedge/)
	})
})
