import type { Role } from './api'

/**
 * The five role buckets `contracts/ocs-api.yaml` declares on the `Dashboard` schema.
 *
 * The order is the order the schema lists them in, so a card list reads the same way the contract
 * does instead of in whatever order a payload happened to serialize its keys.
 */
export type DashboardRole = 'purchaser' | 'costCenter' | 'senior' | 'finance' | 'admin'

export const DASHBOARD_ROLES: readonly DashboardRole[] = ['purchaser', 'costCenter', 'senior', 'finance', 'admin']

/**
 * Which bucket each session role's work is reported in.
 *
 * FR-061 requires the dashboard to be role-aware, and the `SessionContext.roles` enum and the
 * `Dashboard` bucket names are the two sides of that: a user holds session roles, the projection
 * answers in buckets. The mapping is stated once here rather than being inferred from the string
 * similarity of the two enums, which would map `COST_CENTER_MANAGER` to a bucket that does not
 * exist and silently drop a manager's queue from their dashboard.
 */
export const DASHBOARD_ROLE_FOR_SESSION_ROLE: Record<Role, DashboardRole> = {
	PURCHASER: 'purchaser',
	COST_CENTER_MANAGER: 'costCenter',
	SENIOR_MANAGER: 'senior',
	FINANCE: 'finance',
	ADMINISTRATOR: 'admin',
}

/**
 * One role's section of the projection.
 *
 * The contract types each bucket as an open object (`additionalProperties: true`) and names no
 * properties inside it, while FR-061 enumerates the content: open sheets, returned transactions,
 * review queues, decision counts, finance work, balances, movements, and administrative summaries.
 * A fixed client-side shape would therefore be a guess at T106's output that could contradict it, so
 * the bucket is read as the open object the contract declares and every key it carries is rendered.
 */
export type DashboardBucket = Record<string, unknown>

/** The projection `GET /dashboard` returns: a bucket per role, absent when the role has no work. */
export type RoleScopedDashboard = Partial<Record<DashboardRole, DashboardBucket>>

/**
 * How one bucket value is presented.
 *
 * - `count` — a number, formatted with the Persian locale's digit grouping.
 * - `money` — a `Money`, formatted at its own currency's minor unit (never rescaled here).
 * - `boolean` — rendered through the catalog as yes/no, never as a bare `true`/`false`.
 * - `text` — a string, or a value the projection did not supply, shown as an explicit dash.
 * - `raw` — a structured value (an object or an array) that has no card presentation of its own. It
 *   is shown rather than dropped: FR-061's content has to be visible, and a value silently discarded
 *   because the client had no renderer for it is the failure mode this kind exists to prevent.
 */
export type DashboardMetricKind = 'count' | 'money' | 'boolean' | 'text' | 'raw'

/** One rendered entry of a role card: the server's own key plus the presentation chosen for it. */
export interface DashboardMetric {
	key: string
	kind: DashboardMetricKind
	text: string
	/** Set only for `kind: 'boolean'`; the component resolves it through the catalog. */
	boolean?: boolean
}

/** One role's card: the bucket's role and the metrics derived from its contents. */
export interface DashboardCard {
	role: DashboardRole
	metrics: DashboardMetric[]
}
