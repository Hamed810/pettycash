// Persian catalog completeness (T119): every key the UI can ask for exists.
import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, extname } from 'node:path'
import { DYNAMIC_KEY_FAMILIES, expandFamily } from '@/l10n'

const SRC = join(process.cwd(), 'src')
const FA_JSON = join(process.cwd(), 'l10n/fa.json')
const FA_JS = join(process.cwd(), 'l10n/fa.js')

/** The catalog as the server loads it: `l10n/<lang>.json` for API consumers. */
function loadCatalog(): Record<string, string> {
	const document = JSON.parse(readFileSync(FA_JSON, 'utf8')) as {
		translations?: Record<string, string>
	}
	const catalog = document.translations
	expect(catalog, 'l10n/fa.json must hold the pettycash translations').toBeTruthy()
	if (catalog === undefined) {
		throw new Error('l10n/fa.json must hold the pettycash translations')
	}
	return catalog
}

/** The legacy `.js` registration the app page injects must carry the same catalog. */
function loadJsCatalog(): Record<string, string> {
	const code = readFileSync(FA_JS, 'utf8')
	const registered: Record<string, Record<string, string>> = {}
	const OC = {
		L10N: {
			register(app: string, translations: Record<string, string>, _plural: string) {
				registered[app] = translations
			},
		}
	}
	new Function('OC', code)(OC)
	const catalog = registered['pettycash']
	expect(catalog, 'l10n/fa.js must register the pettycash catalog').toBeTruthy()
	if (catalog === undefined) {
		throw new Error('l10n/fa.js must register the pettycash catalog')
	}
	return catalog
}

function* sourceFiles(dir: string): Generator<string> {
	for (const entry of readdirSync(dir)) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) {
			yield* sourceFiles(full)
		} else if (extname(full) === '.vue' || extname(full) === '.ts') {
			yield full
		}
	}
}

/** Static `t('pettycash', 'literal')` keys used anywhere under src/. */
function staticKeys(): Set<string> {
	const keys = new Set<string>()
	const literal = /t\(\s*['"]pettycash['"]\s*,\s*['"]([^'"]+)['"]/g
	for (const file of sourceFiles(SRC)) {
		for (const line of readFileSync(file, 'utf8').split('\n')) {
			// Doc comments name key patterns; only real calls count.
			if (/^\s*(\/\/|\*)/.test(line)) {
				continue
			}
			literal.lastIndex = 0
			let match: RegExpExecArray | null
			while ((match = literal.exec(line)) !== null) {
				if (match[1] !== undefined) {
					keys.add(match[1])
				}
			}
		}
	}
	return keys
}

describe('Persian catalog completeness', () => {
	it('registers a well-formed pettycash catalog', () => {
		const catalog = loadCatalog()
		const legacy = loadJsCatalog()
		expect(Object.keys(catalog).length).toBeGreaterThan(300)
		for (const [key, value] of Object.entries(catalog)) {
			expect(value.trim().length, `empty translation for ${key}`).toBeGreaterThan(0)
		}
		// Both shipped spellings carry the same catalog: the page injects the
		// `.js` registration while API consumers read the `.json` file.
		expect(legacy, 'fa.js catalog must match fa.json').toEqual(catalog)
	})

	it('covers every static translation key used in source', () => {
		const catalog = loadCatalog()
		const missing = [...staticKeys()].filter((key) => !(key in catalog))
		expect(missing, `missing from l10n/fa.json: ${missing.join(', ')}`).toEqual([])
	})

	it('covers every dynamic key family', () => {
		const catalog = loadCatalog()
		const missing: string[] = []
		for (const prefix of Object.keys(DYNAMIC_KEY_FAMILIES)) {
			for (const key of expandFamily(prefix)) {
				if (!(key in catalog)) {
					missing.push(key)
				}
			}
		}
		expect(missing, `missing from l10n/fa.json: ${missing.join(', ')}`).toEqual([])
	})

	it('contains no doubled-app-prefix keys', () => {
		// The `pettycash.status.*` / `pettycash.transactions.*` era: passing an
		// already-prefixed string as the lookup text can never resolve, so any
		// such key in source is a defect, not a catalog gap.
		const doubled: string[] = []
		for (const file of sourceFiles(SRC)) {
			const source = readFileSync(file, 'utf8')
			const matches = source.match(/['"]pettycash\.(status|transactions|navigation|common)\./g)
			if (matches) {
				doubled.push(`${file}: ${[...new Set(matches)].join(', ')}`)
			}
		}
		expect(doubled, `doubled-prefix keys must not be passed to t(): ${doubled.join(' | ')}`).toEqual([])
	})

	it('has no hardcoded user-facing aria-labels in views', () => {
		const offenders: string[] = []
		for (const file of sourceFiles(join(SRC, 'views'))) {
			const source = readFileSync(file, 'utf8')
			for (const line of source.split('\n')) {
				const match = line.match(/aria-label="([^"{]+)"/)
				if (match && !line.includes('t(')) {
					offenders.push(`${file.split('/').pop()}: ${match[1]}`)
				}
			}
		}
		expect(offenders, `hardcoded aria-labels: ${offenders.join(' | ')}`).toEqual([])
	})
})
