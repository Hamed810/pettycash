import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { fetchDashboard } from '@/services/dashboardApi'
import { useAuthStore } from '@/stores/auth'
import {
	DASHBOARD_ROLES,
	DASHBOARD_ROLE_FOR_SESSION_ROLE,
	type DashboardCard,
	type DashboardRole,
	type RoleScopedDashboard,
} from '@/types/dashboard'
import { dashboardMetrics, dashboardNotes, isRecord } from '@/utils/dashboard'

/**
 * FR-061: the role-aware dashboard, and FR-075: a failed read is reported and retried only when the
 * user asks for it.
 *
 * The store holds the projection and derives the cards from it. It holds no rule about what a number
 * means — the projection decides that, and the client neither rescales an amount nor invents a
 * counter.
 */
export const useDashboardStore = defineStore('dashboard', () => {
	const auth = useAuthStore()

	const buckets = ref<RoleScopedDashboard>({})
	const loading = ref(false)
	const loaded = ref(false)
	const error = ref<string | null>(null)

	/** The session roles mapped onto the buckets they are reported in. */
	const sessionCards = computed<DashboardRole[]>(() => {
		const mapped = new Set(auth.roles.map((role) => DASHBOARD_ROLE_FOR_SESSION_ROLE[role]))
		return DASHBOARD_ROLES.filter((role) => mapped.has(role))
	})

	/**
	 * The cards the user is shown, in the contract's bucket order.
	 *
	 * A bucket the projection sent for a role the session does not hold is left out. That is defence
	 * in depth, not enforcement: `plan.md` fixes authorization as server-authoritative, and a
	 * projection that answers out of scope is a server defect this filter cannot repair — it only
	 * keeps such an answer off the screen. The filter is skipped entirely while the session is still
	 * unknown, because refusing to render anything until `/me` has answered would leave a directly
	 * opened dashboard blank for no reason.
	 */
	const cards = computed<DashboardCard[]>(() => {
		const allowed = new Set(sessionCards.value)
		return DASHBOARD_ROLES.filter((role) => {
			if (!isRecord(buckets.value[role])) return false
			return allowed.size === 0 || allowed.has(role)
		}).map((role) => ({
			role,
			metrics: dashboardMetrics(buckets.value[role]),
			notes: dashboardNotes(buckets.value[role])
		}))
	})

	/**
	 * A card whose bucket the projection sent but which carries no figure renders as "nothing to do".
	 *
	 * Figures only. The envelope every bucket arrives in — `userId`, the read-window bound, the
	 * truncation flag — is stated on the card as a note, so counting it as content would make this
	 * true for every bucket the server ever sends and put the empty state out of reach.
	 */
	const hasMetrics = computed(() => cards.value.some((card) => card.metrics.length > 0))

	const isEmpty = computed(() => !loading.value && error.value === null && cards.value.length === 0)

	function clearResult(): void {
		buckets.value = {}
		loaded.value = false
	}

	/**
	 * Loads the projection. A failure clears the previous cards so a stale count is never presented as
	 * the current one, and nothing is re-issued here: the retry belongs to the user (FR-075), and
	 * there is no mutation on this screen to retry automatically in the first place.
	 */
	async function loadDashboard(): Promise<void> {
		loading.value = true
		error.value = null
		try {
			const projection = await fetchDashboard()
			buckets.value = isRecord(projection) ? (projection as RoleScopedDashboard) : {}
			loaded.value = true
		} catch (e: unknown) {
			error.value = e instanceof Error ? e.message : 'pettycash.error.network'
			clearResult()
			throw e
		} finally {
			loading.value = false
		}
	}

	function clearError(): void {
		error.value = null
	}

	return {
		buckets,
		loading,
		loaded,
		error,
		cards,
		hasMetrics,
		isEmpty,
		sessionCards,
		loadDashboard,
		clearError,
	}
})
