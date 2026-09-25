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

  const isAuthenticated = computed(() => user.value !== null)
  const roles = computed(() => user.value?.roles ?? [])
  const costCenterCodes = computed<string[]>(() => (capabilities.value.costCenterCodes as string[] | undefined) ?? [])

  const hasRole = computed(() => (role: Role) => roles.value.includes(role))
  const hasAnyRole = computed(() => (rolesList: Role[]) => rolesList.some(r => roles.value.includes(r)))
  const hasCostCenterScope = computed(() => (code: string) => costCenterCodes.value.includes(code))

  function canReview(stage: string, costCenterCode: string): boolean {
    if (!user.value) return false

    // Finance stage: only Finance role (shared queue, no scope check)
    if (stage === 'FINANCE') {
      return roles.value.includes('FINANCE')
    }

    // Correction stage: only the transaction owner (purchaser) can correct
    if (stage === 'CORRECTION') {
      return true // The actual check is done server-side
    }

    // CostCenter and Senior stages: require role + scope
    const requiredRole = stage === 'COST_CENTER' ? 'COST_CENTER_MANAGER' : 'SENIOR_MANAGER'

    // Multi-role self-review: if actor is the owner AND holds the required role + scope + stage permission, allow
    // This is handled server-side, but we can check basic requirements here
    if (roles.value.includes(requiredRole) && costCenterCodes.value.includes(costCenterCode)) {
      return true
    }

    // Normal review: require role + scope (not owner)
    return roles.value.includes(requiredRole) && costCenterCodes.value.includes(costCenterCode)
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
  }

  return {
    user,
    capabilities,
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