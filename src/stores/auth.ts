import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { fetchSessionContext } from '@/services/sessionApi'
import type { Role, CapabilitySet, SessionContext } from '@/types/api'

export interface User {
  userId: string
  displayName: string
  roles: Role[]
}

export const useAuthStore = defineStore('auth', () => {
  const user = ref<User | null>(null)
  const loading = ref(false)
  const error = ref<string | null>(null)
  const capabilities = ref<CapabilitySet>({})
  /**
   * FR-004/FR-005: the cost centres the caller's assignments cover.
   *
   * Read from `SessionContext.costCenterCodes`, which the server now sends. It used to be read from
   * `capabilities.costCenterCodes`, which nothing in `lib/` has ever emitted, so it was permanently
   * `[]` — and therefore `hasCostCenterScope()` and both scope branches of `canReview()` were
   * permanently `false` for the two management stages, whatever the caller's real assignments were
   * (T336). **A `?? []` is kept deliberately**: it is the *empty* answer, which is the safe direction,
   * and this is a stored value rather than a per-response read, so it must survive a `setUser()` that
   * carries no scope.
   */
  const managedCostCenterCodes = ref<string[]>([])

  const isAuthenticated = computed(() => user.value !== null)
  const roles = computed(() => user.value?.roles ?? [])
  const costCenterCodes = computed<string[]>(() => managedCostCenterCodes.value)

  const hasRole = computed(() => (role: Role) => roles.value.includes(role))
  const hasAnyRole = computed(() => (rolesList: Role[]) => rolesList.some(r => roles.value.includes(r)))
  const hasCostCenterScope = computed(() => (code: string) => managedCostCenterCodes.value.includes(code))

  /**
   * FR-008's client half: role **and** scope, both from the same session response the server
   * authorizes against.
   *
   * Deliberately conservative about what it claims. An administrator is *not* given blanket review
   * here even though the server lets one see everything: FR-007 says administrator status must never
   * confer decision authority, and a gate that opened for an administrator would contradict that on
   * screen even where the server would then refuse the command. Ownership is not consulted at all —
   * FR-002/FR-008 grant a multi-role user review of their own transaction through role, scope and
   * stage, never through ownership.
   *
   * `CORRECTION` returns `true` for an authenticated caller because the transaction owner is the only
   * party who may correct, and which transactions they own is not knowable from the session; the
   * server enforces it.
   */
  function canReview(stage: string, costCenterCode: string): boolean {
    if (!user.value) return false

    // FR-006: the V1 finance queue is shared by every finance officer and carries no cost-centre scope.
    if (stage === 'FINANCE') {
      return roles.value.includes('FINANCE')
    }

    if (stage === 'CORRECTION') {
      return true
    }

    const requiredRole = stage === 'COST_CENTER' ? 'COST_CENTER_MANAGER' : 'SENIOR_MANAGER'
    if (!roles.value.includes(requiredRole)) return false

    // An empty scope list is "manages nothing", never "manages everything" — the same reason
    // `ReportScope::forActor()` drops null cost-centre ids rather than passing them on.
    return managedCostCenterCodes.value.includes(costCenterCode)
  }

  async function loadSession(): Promise<void> {
    loading.value = true
    error.value = null
    try {
      const context: SessionContext = await fetchSessionContext()
      user.value = {
        userId: context.userId,
        displayName: context.displayName,
        roles: context.roles,
      }
      capabilities.value = context.capabilities
      managedCostCenterCodes.value = context.costCenterCodes ?? []
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to load session context'
      throw e
    } finally {
      loading.value = false
    }
  }

  function setUser(u: User | null) {
    user.value = u
  }

  function setCapabilities(caps: CapabilitySet) {
    capabilities.value = caps
  }

  function logout() {
    user.value = null
    capabilities.value = {}
    // T022: a stale load error must not survive sign-out — otherwise a signed-out
    // caller would inherit the failed-session fallback (dashboard+sheets) that is
    // meant only for a load that actually failed. Signed out means no entries.
    error.value = null
    // The scope is the caller's, so it goes with them: leaving it behind would let the next session
    // on this browser pass a scope gate with somebody else's cost centres.
    managedCostCenterCodes.value = []
  }

  return {
    user,
    capabilities,
    managedCostCenterCodes,
    loading,
    error,
    isAuthenticated,
    roles,
    costCenterCodes,
    hasRole,
    hasAnyRole,
    hasCostCenterScope,
    canReview,
    loadSession,
    setUser,
    setCapabilities,
    logout
  }
})