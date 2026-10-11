import type { Role } from './permissions'

/**
 * T001 (specs/004-in-app-navigation): the in-app navigation entry table.
 *
 * A navigation entry names an existing route — never a new one — plus the
 * session predicate that decides whether it is shown. Route names stay literal
 * strings so `tests/Frontend/routeNameIntegrity.spec.ts` keeps proving every
 * navigated name exists in `src/router/index.ts` (001/T359).
 *
 * Visibility is disclosure hygiene only, never authorization: every OCS action
 * behind these routes enforces its own role/scope server-side
 * (`AdministratorCheck`, per-controller scope checks). Hiding the admin
 * entries from non-admins keeps the interface honest; it grants nothing.
 */
export interface NavigationSession {
	roles: Role[]
	canAdminister: boolean
	isAuthenticated: boolean
	/**
	 * T022: the session load failed while the caller is unauthenticated
	 * (`auth.error !== null && !auth.isAuthenticated`). Distinct from merely
	 * signed out (no error): a failed load degrades to dashboard+sheets per
	 * T004 instead of leaving the caller at a dead end, while a signed-out
	 * caller is advertised nothing.
	 */
	sessionFailed: boolean
}

export interface NavigationEntry {
	/** Must equal a `name` in `src/router/index.ts` `routes`. */
	name: string
	/** Resolved via `t('pettycash', labelKey)`; catalogued under `navigation.*`. */
	labelKey: string
	/** Stable `data-test` hook, e.g. `nav-admin-configuration`. */
	testId: string
	visibleFor: (session: NavigationSession) => boolean
}

const authenticated = (session: NavigationSession): boolean => session.isAuthenticated

const hasRole = (session: NavigationSession, role: Role): boolean =>
	session.isAuthenticated && session.roles.includes(role)

const adminOnly = (session: NavigationSession): boolean =>
	session.isAuthenticated && session.canAdminister

/**
 * T022: the degraded fallback. Only dashboard and sheets use it (T004:
 * "dashboard+sheets only") — queues, accounts and administration stay
 * authenticated-only, so a failed load can never surface role-gated or
 * admin entries.
 */
const degradedFallback = (session: NavigationSession): boolean =>
	!session.isAuthenticated && session.sessionFailed

const dashboardOrSheets = (session: NavigationSession): boolean =>
	authenticated(session) || degradedFallback(session)

/**
 * The single source of truth for in-app navigation, in display order.
 *
 * - `dashboard`, `sheets`, `accounts`, `review-queue-returns`: every
 *   authenticated user (FR-018: any caller with app access is a purchaser, and
 *   the returns queue is scoped server-side to the caller's own returns).
 * - The three decision queues: owning role only. Finance deliberately requires
 *   `FINANCE`, never administrator-alone (001/FR-007: administrator status must
 *   never confer decision authority).
 * - `admin-configuration`, `admin-funding`: `canAdminister` only (FR-010/12/13).
 */
export const NAVIGATION_ENTRIES: NavigationEntry[] = [
	{ name: 'dashboard', labelKey: 'navigation.dashboard', testId: 'nav-dashboard', visibleFor: dashboardOrSheets },
	{ name: 'sheets', labelKey: 'navigation.sheets', testId: 'nav-sheets', visibleFor: dashboardOrSheets },
	{ name: 'review-queue-cost-center', labelKey: 'navigation.reviewCostCenter', testId: 'nav-review-cost-center', visibleFor: (s) => hasRole(s, 'COST_CENTER_MANAGER') },
	{ name: 'review-queue-senior', labelKey: 'navigation.reviewSenior', testId: 'nav-review-senior', visibleFor: (s) => hasRole(s, 'SENIOR_MANAGER') },
	{ name: 'review-queue-finance', labelKey: 'navigation.reviewFinance', testId: 'nav-review-finance', visibleFor: (s) => hasRole(s, 'FINANCE') },
	{ name: 'review-queue-returns', labelKey: 'navigation.returns', testId: 'nav-returns', visibleFor: authenticated },
	{ name: 'accounts', labelKey: 'navigation.accounts', testId: 'nav-accounts', visibleFor: authenticated },
	{ name: 'reports', labelKey: 'navigation.reports', testId: 'nav-reports', visibleFor: authenticated },
	{ name: 'admin-configuration', labelKey: 'navigation.administration', testId: 'nav-admin-configuration', visibleFor: adminOnly },
	{ name: 'admin-funding', labelKey: 'navigation.funding', testId: 'nav-admin-funding', visibleFor: adminOnly },
]

export function visibleEntries(session: NavigationSession): NavigationEntry[] {
	return NAVIGATION_ENTRIES.filter((entry) => {
		try {
			return entry.visibleFor(session)
		} catch {
			return false
		}
	})
}
