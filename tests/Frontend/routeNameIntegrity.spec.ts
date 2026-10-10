import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'
import { routes } from '@/router'

/**
 * Every route name the application navigates by must exist in the application's route table.
 *
 * ## Why this file exists
 *
 * `TransactionDetailView.vue` pushed `{ name: 'transaction-correction-create' }`, a route that no
 * record in `src/router/index.ts` declares. `vue-router` throws `MATCHER_NOT_FOUND`
 * **synchronously** from `router.push` — not a rejected promise — so the click handler aborted,
 * nothing caught it, and the purchaser's only correction affordance did nothing at all. US4/AC2 had
 * no entry point (T359).
 *
 * Every layer stayed green, and the reason generalises beyond this one defect:
 *
 * - Five assertions in `PurchaserFlow.spec.ts` checked the button **exists**. None pressed it. A
 *   `router.push` that throws produces no DOM change and no rejection, so an existence assertion
 *   sees a perfectly healthy page.
 * - `PurchaserFlow.spec.ts`, `NextcloudVueContract.spec.ts` and `ReportingExport.spec.ts` each
 *   carried their **own copy** of the route table. A copied table makes every name resolvable
 *   whether or not the application declares it, so the missing name could not have been caught even
 *   by a test that pushed it.
 *
 * Deriving those test routers from `@/router` fixed the copy, and this file covers what that cannot:
 * **a view with no component test at all.** No mount means no failure, so a bad name in an
 * untested view is still invisible to every other check in this suite.
 *
 * ## Why it is a text scan and not a mount
 *
 * Mounting every view to see where its links resolve is not possible here: the suite's own tests
 * show the views need a session, an axios stub and, for some, real `@nextcloud/vue` behaviour. A
 * scan over the source is the check that scales to the views nobody mounts. It is a structural fact
 * about text, so it has the limitation T326's route check documents — a name assembled at runtime
 * rather than written literally would not be seen — and that limitation is stated here rather than
 * left for the next reader to discover.
 */

const SRC = join(process.cwd(), 'src')

function sourceFiles(dir: string): string[] {
	const out: string[] = []
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) {
			out.push(...sourceFiles(full))
		} else if (/\.(vue|ts)$/.test(entry)) {
			out.push(full)
		}
	}
	return out
}

const declaredNames = new Set(
	routes.map((r) => String(r.name)).filter((n): n is string => n !== 'undefined')
)

/**
 * A `name` written literally in a `router.push(...)` or a `:to="{ name: ... }"`.
 *
 * Two shapes, because the codebase writes both:
 *   `router.push({ name: 'transaction-edit', params: { … } })`
 *   `<RouterLink :to="{ name: 'dashboard' }">`
 *
 * Template literals are deliberately not matched — `` { name: `review-queue-${stage}` } `` is how
 * the review queues are addressed, and its value is not knowable without rendering. That is the
 * stated limitation, not an oversight.
 */
function literalNamesIn(source: string): string[] {
	const found: string[] = []
	const patterns = [
		/\bname:\s*'([a-z0-9-]+)'/g,
		/\bname:\s*"([a-z0-9-]+)"/g,
	]
	for (const pattern of patterns) {
		for (const match of source.matchAll(pattern)) {
			if (match[1]) found.push(match[1])
		}
	}
	return found
}

describe('route name integrity', () => {
	it('declares a non-empty route table', () => {
		// The guard against the check passing vacuously: if this read empty, every comparison below
		// would be "everything is unknown" and satisfied. This is the third such self-check in this
		// repository, after `LayerBoundaryTest` and `RouteDispatchCompletenessTest`'s own.
		expect(declaredNames.size).toBeGreaterThan(0)
		expect(declaredNames.has('dashboard')).toBe(true)
		expect(declaredNames.has('transaction-edit')).toBe(true)
	})

	it('never names a route the table does not declare', () => {
		const unknown: string[] = []

		for (const file of sourceFiles(SRC)) {
			const source = readFileSync(file, 'utf8')
			for (const name of literalNamesIn(source)) {
				if (!declaredNames.has(name)) {
					unknown.push(`${name} (${file.replace(process.cwd() + '/', '')})`)
				}
			}
		}

		expect(
			unknown,
			'Every route name the application navigates by must exist in src/router/index.ts. '
				+ 'vue-router throws MATCHER_NOT_FOUND synchronously from router.push for an unknown '
				+ 'name, so the control silently does nothing rather than failing visibly — which is '
				+ 'how the purchaser correction button shipped broken (T359).\n'
				+ `Unknown: ${unknown.join(', ') || 'none'}`
		).toEqual([])
	})

	/**
	 * No OCS URL may be reached by a browser navigation.
	 *
	 * ## Why this exists
	 *
	 * `EvidenceCard.vue` built its evidence URL with `generateOcsUrl(...)` and then reached it by
	 * `window.open()` / `window.location.href`. **A browser navigation cannot set a request header**,
	 * and `GET /evidence/{id}/content` requires `OCS-APIRequest: true` — `EvidenceController::content()`
	 * carries `#[NoAdminRequired]` and no `#[NoCSRFRequired]`, so `SecurityMiddleware` answered
	 * `412 CSRF check failed` for **every** user at **every** review stage. FR-060's preview was
	 * unavailable precisely where US2/AC1 and US3/AC1 ask for it (T361).
	 *
	 * The purchaser's `EvidenceList.vue` never had this defect, because it fetches the bytes and hands
	 * the browser a `Blob` URL — which is why one half of the application worked and the other did not.
	 *
	 * This is the third correspondence with no symptom of its own: contract path ↔ route (T326),
	 * client payload key ↔ server payload key (T327/T329), and now **client URL ↔ how the browser
	 * issues it**. The first two are checks over text; this one is also a check over text, because the
	 * alternative is discovering it by clicking, which is exactly what no test did.
	 *
	 * **The rule is about where the URL comes from, not about the sink itself — and the first draft of
	 * this check got that wrong.** It flagged *every* navigation sink, which failed on T361's own fix:
	 * `preview()` legitimately calls `window.open()` with a **`blob:`** URL, because opening the
	 * fetched evidence in a tab is exactly how FR-060's preview works once the bytes are in hand. A
	 * blanket ban would have forbidden the correct behaviour.
	 *
	 * So the invariant is the one that actually distinguishes them: **`generateOcsUrl` exists to hand
	 * a URL to `fetch`/axios, and using it in a navigation sink is always wrong.** The second clause
	 * catches the case where the OCS path is written literally rather than through the helper.
	 *
	 * **Stated limitation:** this matches navigation *sinks*, not every way to issue a request. A
	 * component that built an OCS URL and used it as an `<a href>` or an `<img src>` would not be
	 * caught — the inline `<img>` in T361's defect was one of those, and it is now removed for that
	 * reason rather than because this check would have caught it. The limitation is why the sink list
	 * is asserted non-empty below rather than trusted.
	 */
	it('never reaches an OCS URL by navigation', () => {
		const sinks = [
			/window\.open\(/g,
			/window\.location\.href\s*=/g,
			/window\.location\.replace\(/g,
			/(?<![.\w])location\.assign\(/g,
			/(?<![.\w])location\.replace\(/g,
		]

		const offenders: string[] = []
		let sinkCount = 0

		for (const file of sourceFiles(SRC)) {
			const source = readFileSync(file, 'utf8')
			const rel = file.replace(process.cwd() + '/', '')

			// Does this file obtain an OCS URL at all? Only then is a navigation sink suspect.
			const usesOcsUrl =
				/^import\s*\{[^}]*generateOcsUrl[^}]*\}\s*from\s*'@nextcloud\/router'/m.test(source)

			for (const sink of sinks) {
				for (const match of source.matchAll(sink)) {
					sinkCount++
					const line = source.slice(0, match.index).split('\n').length
					// Commented-out or docblock prose is not a call site.
					const lineText = source.split('\n')[line - 1] ?? ''
					const isProse = lineText.trimStart().startsWith('*') || lineText.trimStart().startsWith('//')
					if (isProse) continue

					const after = source.slice(match.index, match.index + 200)
					const navigatesToOcs =
						usesOcsUrl || /generateOcsUrl|\/ocs\//.test(after)

					if (navigatesToOcs) {
						offenders.push(`${rel}:${line}`)
					}
				}
			}
		}

		// The scan's own self-check: with zero sinks matched, the loop above proved nothing.
		expect(sinkCount, 'the navigation-sink scan found at least one sink to reason about').toBeGreaterThan(0)

		expect(
			offenders,
			'An OCS route must be reached by a request that can carry `OCS-APIRequest: true`, not by a '
				+ 'browser navigation — a navigation cannot set a request header, and every OCS endpoint '
				+ 'in this app is behind the CSRF check, so navigating to one answers 412. Fetch the bytes '
				+ 'and hand the browser a `blob:` URL instead (T361). Navigating to a `blob:` URL is fine '
				+ 'and is how preview works.\n'
				+ `OCS URL reached by navigation: ${offenders.join('; ') || 'none'}`
		).toEqual([])
	})

	it('finds names in the source it claims to scan', () => {
		// The self-check for the scan. A regex that stopped matching — because a view was refactored
		// to a different call shape — would make the test above pass with nothing compared, which is
		// indistinguishable from a clean result.
		const sample = readFileSync(
			join(SRC, 'views/transactions/TransactionDetailView.vue'),
			'utf8'
		)
		expect(literalNamesIn(sample)).toContain('transaction-edit')
	})
})

/**
 * A component's tests certify the component **only if some view renders it**.
 *
 * ## Why this file exists
 *
 * `src/components/evidence/EvidenceList.vue` was the working evidence list: download wired to
 * `getEvidenceContent`, removal wired to `deleteEvidence`, with a confirm, a guard and an error region.
 * `TransactionDetailView.vue` had its **own inline copy** of the same list whose download and remove
 * handlers were `// TODO: Implement …`. `grep -rn "from '@/components/evidence" src/` returned only
 * `EvidenceUploader` — the list component was imported by **no view at all**.
 *
 * So the tested surface and the shipped surface were two different files: `PurchaserFlow.spec.ts`
 * mounted the unused component and asserted `download-evidence-btn` / `delete-evidence-btn` against
 * it, while every purchaser saw the view's inert inline buttons carrying `data-test="download-evidence"`
 * — a selector **no test referenced**. Sixteen assertions were green and the feature did not work
 * (T360).
 *
 * Nothing in the suite could see this. Every component test mounts its subject directly, so a
 * component that is never rendered is perfectly testable and perfectly invisible.
 */
describe('component reachability', () => {
	const COMPONENTS = join(process.cwd(), 'src/components')
	const VIEWS = join(process.cwd(), 'src/views')

	function vueFiles(dir: string): string[] {
		const out: string[] = []
		for (const entry of readdirSync(dir)) {
			const full = join(dir, entry)
			if (statSync(full).isDirectory()) {
				out.push(...vueFiles(full))
			} else if (entry.endsWith('.vue')) {
				out.push(full)
			}
		}
		return out
	}

	const componentFiles = vueFiles(COMPONENTS)
	const viewSources = vueFiles(VIEWS).map((f) => readFileSync(f, 'utf8'))

	it('finds components and views to compare', () => {
		// The vacuity guard again: with no components or no views discovered, the walk below would
		// start from nothing and report nothing.
		expect(componentFiles.length).toBeGreaterThan(0)
		expect(viewSources.length).toBeGreaterThan(0)
	})

	/**
	 * Components reachable from a view, following component → component edges transitively.
	 *
	 * **Transitive because that is what "rendered" means.** A first version of this check compared
	 * components against views only, and its first run reported `review/EvidenceCard.vue` as an
	 * orphan — which `src/components/review/EvidenceList.vue:15,26` plainly imports. A card rendered by
	 * a list that a view renders is rendered; stopping at one hop would have produced a false positive
	 * and, worse, an incentive to inline a component rather than compose it.
	 */
	it('has every component rendered by some view', () => {
		const viewFiles = vueFiles(VIEWS)
		const sources = new Map<string, string>()
		for (const file of [...viewFiles, ...componentFiles]) {
			sources.set(file, readFileSync(file, 'utf8'))
		}

		/**
		 * The `src/components/**` files a file imports.
		 *
		 * Both spellings the codebase uses: the `@/` alias
		 * (`@/components/evidence/EvidenceList.vue`) and a relative one (`./EvidenceCard.vue`,
		 * `../components/review/EvidenceList.vue`). The relative form is resolved against the importing
		 * file's own directory and then tested for containment, so a same-named file outside
		 * `src/components` cannot be mistaken for a component edge.
		 */
		const componentEdges = (file: string): string[] => {
			const source = sources.get(file) ?? ''
			const dir = file.replace(/\/[^/]+$/, '')
			const edges = new Set<string>()

			const patterns = [
				/@\/components\/([\w./-]+?\.vue)/g,
				/\.\.?\/([\w./-]+?\.vue)/g,
			]
			for (const pattern of patterns) {
				for (const match of source.matchAll(pattern)) {
					const spec = match[1]
					if (!spec) continue
					const absolute = pattern.source.startsWith('@')
						? join(process.cwd(), 'src/components', spec)
						: join(dir, spec)
					const normalised = absolute.replace(/\/\.\//g, '/').replace(/\/[^/]+\/\.\.\//g, '/')
					if (normalised.startsWith(COMPONENTS + '/')) edges.add(normalised)
				}
			}
			return [...edges]
		}

		// Breadth-first from every view. A component is reachable when some view reaches it, directly
		// or through other components.
		const reachable = new Set<string>()
		const queue = [...viewFiles]
		while (queue.length > 0) {
			const file = queue.shift() as string
			for (const edge of componentEdges(file)) {
				if (reachable.has(edge)) continue
				reachable.add(edge)
				queue.push(edge)
			}
		}

		const orphans = componentFiles.filter((f) => !reachable.has(f)).map((f) => f.replace(COMPONENTS + '/', ''))

		expect(
			orphans,
			'Every component under src/components must be reachable from some view, directly or through '
				+ 'another component. A component that only tests import is a component whose tests '
				+ 'certify a surface no user reaches — which is how a working evidence list shipped beside '
				+ 'an inert copy of itself, with the tests green against the one nobody rendered (T360).\n'
				+ `Unreachable from every view: ${orphans.join(', ') || 'none'}`
		).toEqual([])
	})
})
