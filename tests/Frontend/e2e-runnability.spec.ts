import { existsSync, readdirSync, readFileSync } from 'node:fs'
import { homedir } from 'node:os'
import { createRequire } from 'node:module'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

/**
 * T405: "the E2E suite cannot run here" must be a **reported** fact, never a silent one.
 *
 * ## Why this file exists
 *
 * `npm run check` deliberately omits `test:e2e`. That is right — an E2E run needs a live Nextcloud and
 * three browser engines, neither of which belongs in a per-commit gate — but it has a consequence
 * `playwright.config.ts`'s own `globalSetup` comment already records: "all 28 tests failed on a missing
 * credential while reading like a broken product — and `npm run check` does not run `test:e2e`, so
 * nothing noticed."
 *
 * The same silence covers a **missing browser**. On 2026-10-08 no Playwright browser was installed, so
 * no project could run, and no gate said so. Every browser-dependent criterion in the ledger — T053,
 * T054, T076, T114, T116, T122, T123, and the dark/high-contrast half of T400's keyboard floor — was
 * unverifiable for a reason indistinguishable from "not finished yet".
 *
 * ## Why this asserts instead of only reporting
 *
 * T405 asked for "a check that fails when no browser is available". Taken literally that produces a
 * **permanently red** `npm run check` on any host that cannot install one, and `RouteResolutionTest`'s
 * docblock states the consequence better than I can: "A permanently red test is not a guard, though —
 * it trains everyone to ignore it, and the day it is skipped for being noisy is the day it stops
 * protecting anything."
 *
 * So this distinguishes two states that look identical and must not be treated alike:
 *
 * - **Blocked** — the browser CDN cannot be reached from this host, so installation is impossible.
 *   Asserted as *skipped with the reason recorded*, never as a pass.
 * - **Missing** — the CDN is reachable and no browser is installed. That is a real failure, because
 *   someone could have run one command, and it fails here.
 *
 * A host with browsers installed passes both branches without either probe mattering.
 *
 * ## What changed on 2026-10-09, and why the distinction above grew a third case
 *
 * **The geo-block is gone.** `cdn.playwright.dev` now answers `307` → `storage.googleapis.com` → `200`
 * from this host, where on 2026-10-08 every mirror returned `403 AccessDenied`. `npx playwright install
 * chromium firefox` therefore succeeds, and this host now has Chromium 143 and Firefox 144.
 *
 * **That exposed a bug in this guard, which made it permanently red.** `installedEngines()` collected
 * `readdirSync()` entries verbatim — `chromium-1200`, `firefox-1497` — and `unrunnableProjects()` then
 * asked `engines.has('chromium')`. That is never true on any host, installed or not, so the guard
 * reported every downloaded engine as missing forever. The failure mode is exactly the one this file's
 * own docblock names as worse than having no guard: "a guard that looked in the wrong place would be
 * green on a host with browsers and red on a host without." This was masked while nothing was
 * installed, because the CDN probe took the *skip* branch first. The engine name is now derived the
 * way Playwright builds the directory — `browserDirectoryPrefix.replace(/-/g, "_") + "-" + revision`
 * (`registry/index.js:510`) — and read back by stripping the revision, so `chromium-1200` is `chromium`
 * and `chromium_headless_shell-1200` is `chromium-headless-shell`, a *different* engine that must not
 * satisfy a `chromium` project.
 *
 * **The system Edge is a third state, and it is not a CDN question at all.** `edge-desktop` sets
 * `channel: 'msedge'`, which `registry/index.js:648` resolves to the hardcoded absolute path
 * `/opt/microsoft/msedge/msedge`. Playwright cannot download that on Linux: `reinstall_msedge_stable_linux.sh`
 * adds a Microsoft apt repository and runs `apt-get install`, which needs root. So the CDN probe — the
 * thing that decides *blocked* vs *missing* for every other project — says **nothing** about whether
 * `edge-desktop` can run. Asking it anyway would be a category error, and the answer it gives
 * ("reachable, so install it") names a remedy that cannot work without root.
 *
 * It is therefore reported separately, with the remedy that is actually true, and asserted against
 * Playwright's own registry so this file cannot drift from the path it claims to check.
 *
 * **That reporting branch is not hypothetical — it is what this host hit, from the opposite direction.**
 * The first pass here concluded `edge-desktop` was missing because `npx playwright install msedge` exited
 * with *"sudo: a terminal is required to read the password"*, and an engine that was in fact installed and
 * runnable was nearly written off as needing a privilege the work never required. The error is worth
 * stating because the command is not a check: Playwright's Linux channel installer **removes and
 * re-installs** the package, so it demands root whether or not the browser is already present. Absence has
 * to be established by looking at the path, which is what {@link systemEdgePresent} does.
 */
const BROWSERS_PATH = process.env.PLAYWRIGHT_BROWSERS_PATH ?? join(homedir(), '.cache', 'ms-playwright')

/**
 * The path `playwright-core` resolves the `msedge` channel to on Linux.
 *
 * `registry/index.js:648` — `_createChromiumChannel("msedge", { "linux": "/opt/microsoft/msedge/msedge" })`.
 * There is no environment variable and no `PATH` fallback for a channel, so this is the only place a
 * system Edge can live as far as Playwright is concerned. The last test asserts this string still
 * matches the installed Playwright, because a guard that checks the wrong path reports a gap that does
 * not exist and hides a real one.
 */
const MSEGE_CHANNEL_PATH = '/opt/microsoft/msedge/msedge'

const require_ = createRequire(import.meta.url)
const PLAYWRIGHT_ROOT = dirname(require_.resolve('playwright-core/package.json'))
const PLAYWRIGHT_REGISTRY = join(PLAYWRIGHT_ROOT, 'lib', 'server', 'registry', 'index.js')
const PLAYWRIGHT_BROWSERS_JSON = join(PLAYWRIGHT_ROOT, 'browsers.json')

const PLAYWRIGHT_CONFIG = join(dirname(fileURLToPath(import.meta.url)), '..', '..', 'playwright.config.ts')

/**
 * The projects, split by **how** a missing browser would be closed.
 *
 * `engine` projects are satisfied by `npx playwright install <engine>` — a CDN download, which is what
 * {@link browserCdnBlocked} probes. `channel` projects are satisfied only by a **system package**, and
 * conflating the two would make the CDN probe answer a question it was never asked.
 */
const PROJECTS: ReadonlyArray<{ project: string; engine?: string; channel?: string }> = [
	{ project: 'chromium-desktop', engine: 'chromium' },
	{ project: 'chromium-mobile', engine: 'chromium' },
	{ project: 'firefox-desktop', engine: 'firefox' },
	{ project: 'edge-desktop', channel: 'msedge' },
]

/**
 * The engine a browser directory belongs to, or `undefined` if the entry is not one.
 *
 * Playwright names a browser directory `<browser>-<revision>` with dashes in the browser name replaced
 * by underscores (`registry/index.js:510`), so the revision is what has to be stripped to recover the
 * engine. The inverse mapping is why `chromium_headless_shell-1200` must not satisfy a `chromium`
 * project: it is `chromium-headless-shell`, a separate download that cannot launch a headed run.
 *
 * A pure function so the mapping is asserted directly rather than only through whatever this host
 * happens to have installed — the regression this replaces was invisible on a host with nothing
 * installed, which is exactly when it must still have been correct.
 */
function engineOf(entry: string): string | undefined {
	// `[1]` is defined whenever the regex matches — the group is not optional — but `noUncheckedIndexedAccess`
	// cannot see that, so the capture is unpacked explicitly rather than indexed.
	const [, engine] = /^([a-z0-9_]+)-\d+$/.exec(entry) ?? []
	return engine?.replace(/_/g, '-')
}

/**
 * The engine names actually present in {@link BROWSERS_PATH}.
 *
 * `INSTALLATION_COMPLETE` is required as well as the directory, because Playwright writes that marker
 * *after* extracting — an interrupted `install` leaves a populated directory that cannot launch, and
 * counting it would trade this guard's only failure mode for a silent one.
 */
function installedEngines(): Set<string> {
	if (!existsSync(BROWSERS_PATH)) {
		return new Set()
	}

	const engines = new Set<string>()
	for (const entry of readdirSync(BROWSERS_PATH)) {
		const engine = engineOf(entry)
		if (engine === undefined || !existsSync(join(BROWSERS_PATH, entry, 'INSTALLATION_COMPLETE'))) {
			continue
		}
		engines.add(engine)
	}

	return engines
}

/** The downloaded-engine projects with no browser for their engine. */
function unrunnableDownloadedProjects(): string[] {
	const engines = installedEngines()

	return PROJECTS.filter(({ engine }) => engine !== undefined && !engines.has(engine))
		.map(({ project, engine }) => `${project} (no ${engine} build in ${BROWSERS_PATH})`)
}

function systemEdgePresent(): boolean {
	return existsSync(MSEGE_CHANNEL_PATH)
}

/**
 * The download URL for the Chromium build this Playwright version actually wants.
 *
 * Read from `browsers.json` rather than hardcoded, because a hardcoded revision goes `404` on the next
 * Playwright upgrade — and a `404` is not a `403`, so a stale URL would report a blocked CDN as
 * reachable and turn this guard permanently red for the opposite reason.
 */
function chromiumDownloadUrl(): string {
	const manifest = JSON.parse(readFileSync(PLAYWRIGHT_BROWSERS_JSON, 'utf8')) as {
		browsers: Array<{ name: string; revision: string }>
	}
	const chromium = manifest.browsers.find((browser) => browser.name === 'chromium')
	if (chromium === undefined) {
		throw new Error(`No "chromium" entry in ${PLAYWRIGHT_BROWSERS_JSON}.`)
	}

	return `https://cdn.playwright.dev/dbazure/download/playwright/builds/chromium/${chromium.revision}/chromium-linux.zip`
}

/**
 * Whether the browser CDN can serve an install from this host.
 *
 * One HEAD request against the path `npx playwright install` actually uses. The CDN answers `403` for a
 * geo-blocked region and `200` when it will serve, which is exactly the distinction this needs.
 */
async function browserCdnBlocked(): Promise<boolean> {
	try {
		const response = await fetch(chromiumDownloadUrl(), { method: 'HEAD' })
		return response.status !== 200
	} catch {
		// Unreachable at all is also "cannot install here", for whatever reason.
		return true
	}
}

describe('T405: the E2E suite is runnable on this host', () => {
	it('has a downloaded browser for every project the CDN can satisfy', async (ctx) => {
		const unrunnable = unrunnableDownloadedProjects()
		// `.length === 0`, not `=== []`: JavaScript compares arrays by reference, so `unrunnable === []`
		// is always false and `vue-tsc` is right to say so.
		if (unrunnable.length === 0) {
			// The system channel is reported by its own test below rather than folded in here, because
			// the CDN probe below cannot say anything about it.
			return
		}

		const blocked = await browserCdnBlocked()
		const detail = `\`npm run test:e2e\` cannot run these projects:\n  ${unrunnable.join('\n  ')}`

		if (blocked) {
			// **Skipped, not passed — and the reason is printed unconditionally.** A silent skip is the
			// failure mode this file exists to end, so the reason goes to the console rather than relying
			// on the reporter showing skipped-test detail, which varies by verbosity.
			console.warn(
				`\n[e2e-runnability] SKIPPED — ${detail}\n`
				+ 'The Playwright browser CDN did not serve an install from this host, so installation is '
				+ 'impossible here and the browser-dependent criteria are blocked on the environment. The '
				+ 'tasks that own them must record that rather than report them as attempted. On a host that '
				+ 'CAN install, the same condition is a real failure.\n',
			)
			ctx.skip()
		}

		expect(
			unrunnable,
			`${detail}\n\nThe CDN is reachable from this host, so installation is possible and one `
			+ '`npx playwright install` would close this. `npm run check` omits `test:e2e`, which is why '
			+ 'nothing has reported it until now.',
		).toEqual([])
	})

	it('resolves the system Edge channel where playwright-core actually looks for it', (ctx) => {
		// The guard must check the path Playwright uses, or it reports a gap that does not exist and
		// hides one that does. `registry/index.js` is the source of truth; reading it here is what makes
		// a Playwright upgrade that moves the path a loud failure instead of a silent one.
		const registry = readFileSync(PLAYWRIGHT_REGISTRY, 'utf8')
		const declared = /_createChromiumChannel\("msedge",\s*\{\s*"linux":\s*"([^"]+)"/.exec(registry)

		expect(declared?.[1]).toBe(MSEGE_CHANNEL_PATH)

		if (systemEdgePresent()) {
			return
		}

		// Reported, not asserted. `edge-desktop` cannot be closed by `npx playwright install`: Playwright
		// resolves the channel to a fixed absolute path and its Linux installer adds a Microsoft apt
		// repository and runs `apt-get install`, which needs root. Failing here would be the permanently
		// red gate this file's own docblock warns against, on a condition no unprivileged command can fix.
		//
		// **Do not infer absence from `npx playwright install msedge` failing — that command is not a
		// check.** On Linux `reinstall_msedge_stable_linux.sh` removes the package and re-installs it, so
		// it demands root *whether or not Edge is already correctly installed*. During this task that
		// mistake wrote off a working engine as missing; `ls MSEGE_CHANNEL_PATH` is the check, and this
		// branch is where it happens.
		console.warn(
			'\n[e2e-runnability] `edge-desktop` cannot run: no system Edge at '
			+ `${MSEGE_CHANNEL_PATH}.\n`
			+ 'This is NOT a CDN-blocked browser and `npx playwright install msedge` cannot fix it — Playwright '
			+ 'resolves `channel: \'msedge\'` to that fixed path and its Linux installer runs `apt-get`, which '
			+ 'needs root. (Note that `npx playwright install msedge` failing does NOT mean Edge is absent; it '
			+ 'reinstalls unconditionally. Check the path above.) The remedy is:\n'
			+ '    sudo npx playwright install msedge\n'
			+ 'T122\'s three-browser matrix is therefore two-thirds runnable until that is done.\n',
		)
		ctx.skip()
	})

	it('reads the inventory from the path Playwright writes to, and knows which projects need a channel', () => {
		// A guard that looked in the wrong place would be green on a host with browsers and red on a
		// host without, which is worse than having none. The channel assertion is separate because
		// `edge-desktop` is the project no install can satisfy without root.
		const config = readFileSync(PLAYWRIGHT_CONFIG, 'utf8')
		const channels = [...config.matchAll(/channel:\s*'([^']+)'/g)].map((match) => match[1])

		expect(BROWSERS_PATH).toContain('ms-playwright')
		expect(channels).toEqual(['msedge'])
		expect(PROJECTS.map(({ project }) => project).sort()).toEqual([
			'chromium-desktop',
			'chromium-mobile',
			'edge-desktop',
			'firefox-desktop',
		])
	})

	it('derives an engine name from the revision-suffixed directory Playwright creates', () => {
		// The regression that made this guard permanently red: `chromium-1200` was collected verbatim
		// and then compared against `'chromium'`, which is never equal on any host. These are the exact
		// directory names Playwright writes for Playwright 1.57, so this holds on a host with nothing
		// installed — which is the only place this assertion has ever been able to fail.
		expect(engineOf('chromium-1200')).toBe('chromium')
		expect(engineOf('firefox-1497')).toBe('firefox')

		// The headless shell is a **separate engine**, not an alias for chromium: `npx playwright install
		// chromium` fetches both, and treating the shell as chromium would let a headless-shell-only
		// install satisfy a headed project.
		expect(engineOf('chromium_headless_shell-1200')).toBe('chromium-headless-shell')
		expect(engineOf('chromium_headless_shell-1200')).not.toBe('chromium')

		// Not browser directories, and therefore not engines.
		expect(engineOf('.links')).toBeUndefined()
		expect(engineOf('ffmpeg-1011')).toBe('ffmpeg')
		// A directory with no revision suffix is not a browser directory; `isBrowserDirectory` in
		// `registry/index.js` requires the `<name>-<revision>` shape too.
		expect(engineOf('chromium')).toBeUndefined()
	})
})