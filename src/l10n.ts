/**
 * T119: localization contract for the Persian V1 catalog.
 *
 * The catalog itself lives in `l10n/fa.js` (`OC.L10N.register`), which the
 * Nextcloud server loads for Persian users — that file is the single source of
 * truth for translations. This module carries no translations; it names the
 * *dynamic* key families whose members cannot be found by scanning for string
 * literals (route params, enum values, server-driven metric keys), so the
 * catalog-completeness gate (`tests/Frontend/l10nCatalog.spec.ts`) can expand
 * them. Static translation literals are collected from source by
 * that gate directly (comments excluded).
 *
 * Canonical API/domain values (route names, enum codes, currency codes, UUIDs)
 * are never translation keys: they pass through `t()` only where a view maps
 * them to a catalogued label first (status maps, role labels, column labels),
 * each with a raw-identifier fallback so an unnamed value renders honestly.
 */
export const FA_LOCALE = 'fa' as const

/** Prefix → allowed suffixes for dynamically built catalog keys. */
export const DYNAMIC_KEY_FAMILIES: Readonly<Record<string, readonly string[]>> = {
	'reviews.': [
		'cost-centerQueue',
		'seniorQueue',
		'financeQueue',
		'returnsQueue',
		'decision.approve',
		'decision.reject',
		'decision.return',
		'outcome.approved',
		'outcome.rejected',
		'stage.draft',
		'stage.cost_center',
		'stage.correction',
		'stage.senior',
		'stage.finance',
		'stage.final',
	],
	'reports.format.': ['csv', 'xlsx', 'pdf', 'zip'],
	'reports.period.': ['month', 'quarter', 'year', 'custom'],
	'reports.type.': ['sheets', 'transactions', 'cost-centers', 'accounts', 'process', 'evidence', 'audit'],
	'roles.': ['purchaser', 'administrator', 'cost_center_manager', 'senior_manager', 'finance'],
	'sheets.status.': ['open', 'cost_center', 'correction', 'senior', 'finance', 'completed', 'completed_with_rejections'],
	'status.': [
		'open', 'costCenter', 'correction', 'senior', 'finance', 'completed',
		'completedWithRejections', 'draft', 'awaitingCostCenter', 'costCenterApproved',
		'costCenterRejected', 'awaitingSenior', 'seniorApproved', 'seniorRejected',
		'awaitingFinance', 'awaitingCorrection', 'completedApproved', 'completedRejected',
	],
	'dashboard.metric.': [
		'openSheets', 'returnedTransactions', 'awaitingReview', 'pendingDecision',
		'financeQueue', 'balances', 'totalAccounts', 'totalSheets', 'totalTransactions',
		'returnedToMe', 'queueItems',
	],
	'dashboard.role.': ['purchaser', 'administrator', 'cost_center_manager', 'senior_manager', 'finance'],
	'navigation.': [
		'dashboard', 'sheets', 'reviewCostCenter', 'reviewSenior', 'reviewFinance',
		'returns', 'accounts', 'reports', 'administration', 'funding', 'title',
	],
}

/** Expand one dynamic family to full keys. */
export function expandFamily(prefix: string): readonly string[] {
	return (DYNAMIC_KEY_FAMILIES[prefix] ?? []).map((suffix) => `${prefix}${suffix}`)
}

function lowerToken(value: string): string {
	return value.toLowerCase().replace(/-/g, '_')
}

/** Canonical key for a workflow stage value in any spelling (`SENIOR`, `senior`, `cost-center`). */
export function stageKey(stage: string): string {
	return `reviews.stage.${lowerToken(stage)}`
}

/** Canonical key for a transaction/sheet status value in any spelling. */
export function statusKey(status: string): string {
	const camel = lowerToken(status).replace(/_([a-z])/g, (_, letter: string) => letter.toUpperCase())
	return `status.${camel}`
}

/** Canonical key for a decision value in any spelling. */
export function decisionKey(decision: string): string {
	return `reviews.decision.${lowerToken(decision)}`
}
