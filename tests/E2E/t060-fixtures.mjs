/**
 * Shared lab fixtures for the one-shot browser evidence runners (T071).
 *
 * The T060 fixture command is the only supported way to produce the 25/30/35/40 product fixture
 * shapes. It is debug-gated, replaces its own namespace on every run, and touches nothing else.
 * These helpers wrap the documented repository entry points; they do not reach into the container
 * directly and they do not change product behavior.
 */

import { existsSync } from 'node:fs'
import { execFileSync } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const SEED_SCRIPT = fileURLToPath(new URL('../../scripts/dev/seed-test-sheets', import.meta.url))
const OCC = fileURLToPath(new URL('../../scripts/dev/occ', import.meta.url))

function requireScript(path) {
	if (!existsSync(path)) throw new Error(`required lab script is missing: ${path}`)
}

/**
 * Replace the T060 fixture namespace for `owner`.
 *
 * Rerunning is a restore, not an append: the command removes the namespace first. A failure is
 * thrown rather than swallowed — a run against unknown fixture state is not evidence.
 */
export function reseedT060Fixtures(owner, env = process.env) {
	if (env.T071_SKIP_RESEED === '1' || env.T061_SKIP_RESEED === '1') return false
	requireScript(SEED_SCRIPT)
	execFileSync(SEED_SCRIPT, ['--owner', owner], { stdio: 'pipe' })
	return true
}

export function getUserLanguage(user) {
	requireScript(OCC)
	return execFileSync(OCC, ['user:setting', user, 'core', 'lang'], { encoding: 'utf8' }).trim()
}

export function setUserLanguage(user, language) {
	requireScript(OCC)
	execFileSync(OCC, ['user:setting', user, 'core', 'lang', language], { stdio: 'pipe' })
}

export function t060SeedScript() {
	return SEED_SCRIPT
}
