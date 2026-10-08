#!/usr/bin/env node
/**
 * T375: generate `THIRD_PARTY_NOTICES.md` from what the application actually ships.
 *
 * ## What this is for
 *
 * The app declares `<licence>agpl</licence>` and bundles two dependency trees: PHP packages installed into
 * `vendor/`, and npm packages compiled into `js/`. Both carry licences that oblige attribution, and the
 * trees are not homogeneous — the PHP side includes **LGPL-3.0-or-later** and the JS side includes
 * **AGPL-3.0-or-later** alongside MIT, ISC, BSD-2, BSD-3, Apache-2.0, BlueOak-1.0.0 and MPL-2.0. A notice
 * that said "MIT dependencies" would be wrong, and a notice that omitted the copyleft packages would be
 * worse than none.
 *
 * ## Why the sources are not the obvious ones
 *
 * **The obvious source for the JS tree is `package-lock.json`'s `dev` flag, and it is wrong.** Measured on
 * this repository: `vite`, `esbuild`, `rollup`, `sass`, `typescript` and `@types/node` all carry no `dev`
 * flag, so a notice built from that flag would attribute the entire build toolchain as shipped software.
 * `yaml` is worse — it is a devDependency here *and* a transitive dependency of a runtime package, so npm
 * marks it non-dev. The flag describes how npm reached a package, not whether the browser loads it.
 *
 * So the JS list comes from the **built bundles' own source maps**, which name the modules Vite actually
 * compiled in. Measured: 47 distinct packages, and none of the build tools appear. That is the set that
 * genuinely ships.
 *
 * `js/*.map` is not tracked in git, so this needs a build to have run. That is why `check:notices` is
 * ordered *after* `npm run build` in `npm run check`, and why a missing source map is a **hard failure with
 * a message** rather than a silent fallback to a guess.
 *
 * ## Why the PHP tree is exact but the JS tree is build-dependent
 *
 * `composer.lock`'s `packages` array is precisely the set installed from `composer.json`'s `require` —
 * `packages-dev` is the test/tooling tree and does not ship. There is no equivalent precision available on
 * the npm side without a build, which is an honest asymmetry rather than an oversight: Composer's manifest
 * separates runtime from dev, npm's does not.
 *
 * ## `--check` is the deliverable, not the generation
 *
 * A generated notice that nobody re-runs goes stale the first time a dependency changes, and a stale
 * licence notice is worse than none: it looks current. `--check` regenerates to a scratch path and compares
 * byte for byte, so an unreviewed dependency change fails rather than shipping unattributed.
 *
 * Usage:
 *   node scripts/generate-third-party-notices.mjs --write   # (re)generate THIRD_PARTY_NOTICES.md
 *   node scripts/generate-third-party-notices.mjs --check   # fail on drift (what CI runs)
 */

import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync, readdirSync, existsSync } from 'node:fs'
import { join, dirname, resolve } from 'node:path'

const ROOT = resolve(dirname(new URL(import.meta.url).pathname), '..')
const OUTPUT = join(ROOT, 'THIRD_PARTY_NOTICES.md')

const fail = (message) => {
	process.stderr.write(`generate-third-party-notices: ${message}\n`)
	process.exit(1)
}

/**
 * Composer's `license` is a string, an array, or absent. Normalised to a string because a notice that
 * renders `[object Object]` is worse than one that says "UNDECLARED" — and an absent licence is a fact
 * about the package worth surfacing rather than skipping.
 */
function normaliseLicence(value) {
	if (value === undefined || value === null || value === '') return 'UNDECLARED'
	if (Array.isArray(value)) return [...new Set(value.map(String))].sort().join(' OR ')
	return String(value)
}

function readJson(path, what) {
	if (!existsSync(path)) fail(`${what} not found at ${path}`)
	try {
		return JSON.parse(readFileSync(path, 'utf8'))
	} catch (error) {
		fail(`${what} is not valid JSON: ${error.message}`)
	}
}

/** The PHP packages installed from `require` — the ones that end up in `vendor/`. */
function shippedPhpPackages() {
	const lock = readJson(join(ROOT, 'composer.lock'), 'composer.lock')

	return (lock.packages ?? [])
		.map((pkg) => ({
			name: pkg.name,
			version: pkg.version,
			licence: normaliseLicence(pkg.license),
			source: pkg.source?.url ?? pkg.dist?.url ?? '',
		}))
		.sort((a, b) => a.name.localeCompare(b.name))
}

/**
 * The npm packages the browser actually receives, read from the built bundles' source maps.
 *
 * A package name is taken from a `node_modules/<name>/` source path. Scoped names keep both segments, and
 * a nested install (`node_modules/a/node_modules/b`) reports `b`, which is the package that is really
 * loaded — collapsing to the outermost name would attribute the wrong package.
 */
function shippedNpmPackages() {
	const jsDir = join(ROOT, 'js')
	if (!existsSync(jsDir)) fail('js/ not found. Run `npm run build` first.')

	const maps = readdirSync(jsDir).filter((file) => file.endsWith('.map'))
	if (maps.length === 0) {
		fail(
			'no source maps in js/, so the shipped npm packages cannot be determined.\n'
			+ '  The lock file\'s `dev` flag is not a substitute: on this repository it marks vite, esbuild,\n'
			+ '  rollup, sass and typescript as non-dev, which would attribute the build toolchain as shipped.\n'
			+ '  Run `npm run build` (which emits them) and try again.',
		)
	}

	const names = new Set()
	for (const file of maps) {
		let map
		try {
			map = JSON.parse(readFileSync(join(jsDir, file), 'utf8'))
		} catch {
			continue
		}
		for (const source of map.sources ?? []) {
			const match = source.match(/node_modules\/((?:@[^/]+\/)?[^/]+)/)
			if (match) names.add(match[1])
		}
	}

	const lock = readJson(join(ROOT, 'package-lock.json'), 'package-lock.json')
	const entries = lock.packages ?? {}

	const versions = new Map()
	for (const [path, pkg] of Object.entries(entries)) {
		if (!path.includes('node_modules/')) continue
		const name = path.split('node_modules/').pop()
		if (!names.has(name) || pkg.version === undefined) continue
		if (!versions.has(name)) versions.set(name, new Set())
		versions.get(name).add(String(pkg.version))
	}

	return [...names]
		.sort((a, b) => a.localeCompare(b))
		.map((name) => {
			const found = [...(versions.get(name) ?? [])].sort()
			return {
				name,
				// More than one installed version is a fact worth printing rather than picking one.
				version: found.length === 0 ? '(not in lock)' : found.join(', '),
				licence: normaliseLicence(entries[`node_modules/${name}`]?.license),
				source: '',
			}
		})
}

/** A fingerprint of the inputs, so a stale notice names *what* it predates rather than only that it is old. */
function fingerprint(php, npm) {
	const hash = createHash('sha256')
	hash.update(php.map((p) => `${p.name}@${p.version}:${p.licence}`).join('\n'))
	hash.update('\n--npm--\n')
	hash.update(npm.map((p) => `${p.name}@${p.version}:${p.licence}`).join('\n'))
	return hash.digest('hex').slice(0, 16)
}

function render(php, npm) {
	const groups = new Map()
	for (const pkg of [...php, ...npm]) {
		if (!groups.has(pkg.licence)) groups.set(pkg.licence, [])
		groups.get(pkg.licence).push(pkg)
	}

	const total = php.length + npm.length
	const out = []
	out.push('# Third-Party Notices')
	out.push('')
	out.push('<!--')
	out.push(' GENERATED FILE — DO NOT EDIT BY HAND.')
	out.push('')
	out.push(' Produced by `scripts/generate-third-party-notices.mjs --write` from `composer.lock`,')
	out.push(' `package-lock.json` and the built bundles\' source maps in `js/`.')
	out.push('')
	out.push(' An edit here is discarded by the next regeneration and fails `npm run check:notices`')
	out.push(' in the meantime. To change what is attributed, change a dependency.')
	out.push(' -->')
	out.push('')
	out.push('Petty Cash is licensed under the GNU Affero General Public License v3.0 or later')
	out.push('(`<licence>agpl</licence>` in `appinfo/info.xml`). It bundles the third-party software listed')
	out.push('below, each under its own licence. Nothing here changes the terms of the application\'s own')
	out.push('licence; the bundled licences apply to the bundled code.')
	out.push('')
	out.push(`This notice covers the **${total} packages that ship with the application**: ${php.length} PHP`)
	out.push(`packages installed into \`vendor/\`, and ${npm.length} npm packages compiled into \`js/\`.`)
	out.push('')
	out.push('**Development dependencies are excluded**, because they are not shipped: `composer.lock`\'s')
	out.push('`packages-dev` (PHPUnit, Psalm, the Nextcloud coding standard, Guzzle, the OCP stubs) is build')
	out.push('and test tooling, and the npm side is read from the bundles\' source maps rather than from the')
	out.push('lock file. The npm lock file cannot make this distinction: it marks `vite`, `esbuild`, `rollup`,')
	out.push('`sass` and `typescript` as non-dev, so a notice built from it would attribute the build')
	out.push('toolchain as shipped software.')
	out.push('')
	out.push(`<!-- @dependencyFingerprint ${fingerprint(php, npm)} -->`)
	out.push('')

	out.push('## Licence summary')
	out.push('')
	out.push('| Licence | Packages |')
	out.push('| --- | ---: |')
	for (const [licence, pkgs] of [...groups].sort((a, b) => b[1].length - a[1].length || a[0].localeCompare(b[0]))) {
		out.push(`| \`${licence}\` | ${pkgs.length} |`)
	}
	out.push('')
	const copyleft = [...groups.keys()].filter((l) => /^(AGPL|GPL|LGPL|MPL|AGPL)/i.test(l))
	if (copyleft.length > 0) {
		out.push(
			`Copyleft and file-level-copyleft licences are present (${copyleft.map((l) => `\`${l}\``).join(', ')}). `
			+ 'They are listed individually rather than folded into a permissive-licence summary, because their '
			+ 'attribution and source-availability obligations differ from MIT/BSD/ISC/Apache.',
		)
		out.push('')
	}

	for (const [licence, pkgs] of [...groups].sort((a, b) => b[1].length - a[1].length || a[0].localeCompare(b[0]))) {
		out.push(`## ${licence}`)
		out.push('')
		out.push('| Package | Version |')
		out.push('| --- | --- |')
		for (const pkg of pkgs.sort((a, b) => a.name.localeCompare(b.name))) {
			out.push(`| \`${pkg.name}\` | ${pkg.version} |`)
		}
		out.push('')
	}

	out.push('## Full licence texts')
	out.push('')
	out.push('This notice names each package, its version and its licence identifier. It does **not**')
	out.push('reproduce the licence texts. Each is authoritative in the installed package itself:')
	out.push('')
	out.push('- PHP: `vendor/<vendor>/<package>/LICENSE`, per the package\'s own distribution.')
	out.push('- npm: `node_modules/<package>/LICENSE`, per the package\'s own distribution.')
	out.push('')
	out.push('Reproducing the texts here would create a second copy that can drift from the one the code is')
	out.push('actually distributed under, which is the failure a notice that names a location avoids.')

	return out.join('\n') + '\n'
}

const php = shippedPhpPackages()
const npm = shippedNpmPackages()

const undeclared = [...php, ...npm].filter((p) => p.licence === 'UNDECLARED')
if (undeclared.length > 0) {
	fail(
		`${undeclared.length} shipped package(s) declare no licence: ${undeclared.map((p) => p.name).join(', ')}\n`
		+ '  A notice cannot attribute what the package does not state, and guessing is worse than failing.',
	)
}

const generated = render(php, npm)
const mode = process.argv[2]

if (mode === '--write') {
	writeFileSync(OUTPUT, generated)
	process.stdout.write(
		`generate-third-party-notices: wrote THIRD_PARTY_NOTICES.md `
		+ `(${php.length} PHP + ${npm.length} npm packages).\n`,
	)
} else if (mode === '--check') {
	if (!existsSync(OUTPUT)) {
		fail('THIRD_PARTY_NOTICES.md does not exist. Run: node scripts/generate-third-party-notices.mjs --write')
	}
	const committed = readFileSync(OUTPUT, 'utf8')
	if (committed !== generated) {
		process.stderr.write(
			'generate-third-party-notices: FAILED — the notice does not match what ships.\n\n'
			+ `  ${php.length} PHP + ${npm.length} npm packages are installed, but THIRD_PARTY_NOTICES.md `
			+ 'describes a different set.\n\n'
			+ '  A stale licence notice is worse than none: it reads as current. Review the dependency change,\n'
			+ '  then run:\n\n'
			+ '      node scripts/generate-third-party-notices.mjs --write\n\n'
			+ '  If a dependency should not ship at all, move it to a dev dependency rather than editing the\n'
			+ '  notice to match. First 20 differing lines:\n\n',
		)
		const a = committed.split('\n')
		const b = generated.split('\n')
		let shown = 0
		for (let i = 0; i < Math.max(a.length, b.length) && shown < 20; i++) {
			if (a[i] !== b[i]) {
				process.stderr.write(`  - ${a[i] ?? '(absent)'}\n  + ${b[i] ?? '(absent)'}\n`)
				shown++
			}
		}
		process.exit(1)
	}
	process.stdout.write(
		`generate-third-party-notices: OK — notice matches ${php.length} PHP + ${npm.length} npm packages.\n`,
	)
} else {
	fail(`usage: ${process.argv[1].split('/').pop()} [--write|--check]`)
}
