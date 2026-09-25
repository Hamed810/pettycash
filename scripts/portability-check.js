#!/usr/bin/env node
/**
 * Static database-portability check (FR-077).
 *
 * Enforces the construct catalogue in `specs/001-pettycash-v1-core/research.md`
 * Decision 16. It exists because `lib/` currently satisfies the portable-API rule
 * by construction but nothing prevents a regression.
 *
 * Declared V1 backends: `mysql` (MySQL/MariaDB), `pgsql` (PostgreSQL), `sqlite`
 * (SQLite). Oracle is out of V1 scope, so Oracle-only limits are deliberately NOT
 * enforced here - see the deferred Oracle/Enterprise constraint in `plan.md`.
 *
 * Exits non-zero on the first category that fails, printing every violation found.
 */

import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { fileURLToPath } from 'node:url'

const ROOT = fileURLToPath(new URL('..', import.meta.url))
const REQUIRED_DATABASES = ['mysql', 'pgsql', 'sqlite']

/** PostgreSQL NAMEDATALEN is 64 bytes, so 63 characters is the binding limit. */
const MAX_IDENTIFIER = 63
/** Nextcloud prepends its own table prefix (3 chars for the default `oc_`). */
const PREFIX_ALLOWANCE = 3

const violations = []

function report(file, rule, message) {
	violations.push({ file: relative(ROOT, join(ROOT, file)), rule, message })
}

function walk(dir, out = []) {
	let entries
	try {
		entries = readdirSync(dir)
	} catch {
		return out
	}
	for (const entry of entries) {
		const full = join(dir, entry)
		if (statSync(full).isDirectory()) walk(full, out)
		else if (full.endsWith('.php')) out.push(full)
	}
	return out
}

function rel(abs) {
	return relative(ROOT, abs)
}

const php = [...walk(join(ROOT, 'lib')), ...walk(join(ROOT, 'tests'))]

/**
 * 1. The supported backend set must be declared explicitly in info.xml.
 *    FR-077: the declared set must not be left implied.
 */
function checkDeclaredBackends() {
	const infoPath = join(ROOT, 'appinfo', 'info.xml')
	const raw = readFileSync(infoPath, 'utf8').replace(/<!--[\s\S]*?-->/g, '')
	const declared = [...raw.matchAll(/<database>([^<]+)<\/database>/g)].map((m) => m[1].trim())

	for (const required of REQUIRED_DATABASES) {
		if (!declared.includes(required)) {
			report('appinfo/info.xml', 'declared-backends',
				`V1 backend "${required}" is not declared in <dependencies>`)
		}
	}
	// The info.xsd only permits sqlite|mysql|pgsql, so oci is not declarable at all.
	if (declared.includes('oci')) {
		report('appinfo/info.xml', 'declared-backends',
			'"oci" is outside V1 scope and must not be declared')
	}
	if (new Set(declared).size !== declared.length) {
		report('appinfo/info.xml', 'declared-backends', 'duplicate <database> entries')
	}

	// <dependencies> is an xs:sequence: <database> must precede <nextcloud>.
	const inner = raw.match(/<dependencies>([\s\S]*?)<\/dependencies>/)?.[1] ?? ''
	const order = ['php', 'database', 'command', 'lib', 'owncloud', 'nextcloud', 'architecture', 'backend']
	const children = [...inner.matchAll(/<(\w+)[\s>]/g)].map((m) => m[1])
	let last = -1
	for (const child of children) {
		const idx = order.indexOf(child)
		if (idx < 0) {
			report('appinfo/info.xml', 'declared-backends', `unknown <dependencies> child <${child}>`)
			continue
		}
		if (idx < last) {
			report('appinfo/info.xml', 'declared-backends',
				`<${child}> is out of schema order; <database> must precede <nextcloud>`)
		}
		last = idx
	}
	if (!children.includes('nextcloud')) {
		report('appinfo/info.xml', 'declared-backends', '<nextcloud> is required inside <dependencies>')
	}
}

/**
 * 2. Application code must reach the database only through the Nextcloud
 *    abstractions - no Doctrine internals, no unwrapping the connection.
 */
function checkAbstractionBoundaries() {
	const banned = [
		{ rule: 'abstraction', re: /Doctrine\\DBAL(?!.*vendor)/, why: 'Doctrine\\DBAL types bypass OCP\\DB\\Types' },
		{ rule: 'abstraction', re: /->getInner\s*\(/, why: 'getInner() unwraps the portable connection' },
		{ rule: 'abstraction', re: /new\s+\\?OC\\DB\\/, why: 'reaches into Nextcloud private DB internals' },
		{ rule: 'abstraction', re: /->prepare\s*\(\s*['"`]/, why: 'raw SQL passed to prepare()' },
	]

	for (const file of php) {
		const src = readFileSync(file, 'utf8')
		src.split('\n').forEach((line, i) => {
			const trimmed = line.trim()
			if (trimmed.startsWith('*') || trimmed.startsWith('//')) return
			for (const { rule, re, why } of banned) {
				if (re.test(line)) {
					report(rel(file), rule, `line ${i + 1}: ${why} -> ${trimmed.slice(0, 90)}`)
				}
			}
			// Raw SQL literal handed to the low-level execution helpers.
			const rawSql = line.match(/(?:executeQuery|executeStatement|executeUpdate)\s*\(\s*['"`]/)
			if (rawSql) {
				report(rel(file), 'raw-sql',
					`line ${i + 1}: raw SQL literal passed to ${rawSql[1]}() -> ${trimmed.slice(0, 90)}`)
			}
		})
	}
}

/**
 * 3. Two constructs MySQL/MariaDB accept but other backends do not.
 */
function checkVendorExtensions() {
	for (const file of php) {
		const src = readFileSync(file, 'utf8')
		const lines = src.split('\n')

		lines.forEach((line, i) => {
			// delete ... LIMIT
			if (/\bdelete\s*\(/i.test(line) && /limit/i.test(line)) {
				report(rel(file), 'delete-limit',
					`line ${i + 1}: delete relying on LIMIT is MySQL/MariaDB-only -> ${line.trim().slice(0, 90)}`)
			}
		})

		// ONLY_FULL_GROUP_BY: every selected non-aggregate column must be grouped.
		// Parse the select / groupBy / from clauses separately so a selected column
		// can never mask itself by appearing in another clause of the same statement.
		const statements = src.split(';')
		statements.forEach((stmt) => {
			if (!/->groupBy\s*\(/.test(stmt)) return

			const quoted = (clause) => [...(clause ?? '').matchAll(/['"]([\w.]+)['"]/g)].map((m) => m[1])
			const selectClause = stmt.match(/->select\s*\(([^)]*)\)/)?.[1]
			if (selectClause === undefined) return
			if (/\b(count|sum|avg|min|max)\s*\(/i.test(selectClause)) return

			const grouped = quoted(stmt.match(/->groupBy\s*\(([^)]*)\)/)?.[1])
			// Aliases introduced by ->from() are not selected columns.
			const aliases = new Set(quoted(stmt.match(/->from\s*\(([^)]*)\)/)?.[1]).map((c) => c.split('.').pop()))
			const noise = new Set(['*', 'distinct', 'as'])

			for (const col of quoted(selectClause)) {
				const base = col.split('.').pop()
				if (noise.has(base.toLowerCase())) continue
				if (aliases.has(base)) continue
				if (grouped.includes(col) || grouped.includes(base)) continue
				// MySQL/MariaDB detect functional dependency on the primary key, so
				// selecting the `id` PK alongside a grouped column is accepted. Every
				// table in this schema carries an auto-increment `id` primary key
				// (research.md Decision 16), so the name convention is sufficient here.
				if (base.toLowerCase() === 'id') continue
				report(rel(file), 'group-by',
					`column "${col}" is selected but neither aggregated nor in the GROUP BY clause (MySQL/MariaDB ONLY_FULL_GROUP_BY)`)
			}
		})
	}
}

/**
 * 4. Schema identifiers and nullability must stay inside the limits the declared
 *    backends impose. PostgreSQL's 63-character identifier limit is the binding
 *    one; boolean NOT NULL is rejected because the Nextcloud developer manual
 *    documents PHP casting `false` to an empty string, which PostgreSQL refuses.
 */
function checkSchemaLimits() {
	const migrations = walk(join(ROOT, 'lib', 'Migration'))

	for (const file of migrations) {
		const src = readFileSync(file, 'utf8')

		for (const m of src.matchAll(/createTable\('([A-Za-z0-9_]+)'/g)) {
			const name = m[1]
			if (name.length + PREFIX_ALLOWANCE > MAX_IDENTIFIER) {
				report(rel(file), 'schema-identifier',
					`table "${name}" is ${name.length} chars; with the ${PREFIX_ALLOWANCE}-char Nextcloud prefix it exceeds the ${MAX_IDENTIFIER}-character limit (PostgreSQL)`)
			}
		}

		for (const m of src.matchAll(/add(?:Unique)?Index\(\[[^\]]*\],\s*'([A-Za-z0-9_]+)'/g)) {
			if (m[1].length > MAX_IDENTIFIER) {
				report(rel(file), 'schema-identifier',
					`index "${m[1]}" is ${m[1].length} chars, exceeding the ${MAX_IDENTIFIER}-character limit (PostgreSQL)`)
			}
		}

		for (const m of src.matchAll(/addColumn\('([A-Za-z0-9_]+)'/g)) {
			if (m[1].length > MAX_IDENTIFIER) {
				report(rel(file), 'schema-identifier',
					`column "${m[1]}" is ${m[1].length} chars, exceeding the ${MAX_IDENTIFIER}-character limit (PostgreSQL)`)
			}
		}

		// boolean NOT NULL
		for (const m of src.matchAll(/addColumn\('([A-Za-z0-9_]+)'\s*,\s*Types::BOOLEAN\s*,\s*\[([^\]]*)\]/g)) {
			if (/['"]notnull['"]\s*=>\s*true/i.test(m[2])) {
				report(rel(file), 'schema-nullability',
					`boolean column "${m[1]}" is NOT NULL; PHP casts false to an empty string, which PostgreSQL rejects`)
			}
		}
	}
}

checkDeclaredBackends()
checkAbstractionBoundaries()
checkVendorExtensions()
checkSchemaLimits()

if (violations.length === 0) {
	console.log('portability-check: OK — no vendor-specific constructs; declared backends '
		+ REQUIRED_DATABASES.join(', ') + '; schema within declared-backend limits')
	process.exit(0)
}

const byRule = new Map()
for (const v of violations) {
	if (!byRule.has(v.rule)) byRule.set(v.rule, [])
	byRule.get(v.rule).push(v)
}

console.error(`portability-check: FAILED with ${violations.length} violation(s)\n`)
for (const [rule, items] of byRule) {
	console.error(`  [${rule}] ${items.length} violation(s)`)
	for (const v of items) console.error(`    ${v.file}: ${v.message}`)
	console.error('')
}
process.exit(1)
