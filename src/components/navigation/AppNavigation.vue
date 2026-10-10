<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import { useAuthStore } from '@/stores/auth'
import { visibleEntries, type NavigationSession } from '@/types/navigation'

/**
 * T003 (specs/004-in-app-navigation): the single role-aware in-app navigation.
 *
 * Plain `<nav><ul><RouterLink>` on purpose: no `@nextcloud/vue` seam to drift
 * across (see `NextcloudVueContract.spec.ts`), natively keyboard-focusable,
 * and RTL-safe. Session is read from `useAuthStore` — the same
 * `GET /api/v1/me` context the server authorizes against — so entries and
 * enforcement can never disagree about who the caller is. Visibility here is
 * disclosure only; the OCS controllers remain the authorization boundary.
 *
 * This component is also the session's single load point: `loadSession()` had
 * no production caller (defined in `src/stores/auth.ts`, invoked nowhere), so
 * `auth.roles` was permanently empty and every role-gated surface fell back to
 * its unknown-session default. Mounted once per app lifetime inside
 * `AppRoot.vue`, this issues exactly one load; the rejection is absorbed here
 * because the store already records it in `error` and a mount-time load has
 * no caller to react (the `SheetListView` mount idiom).
 */
const auth = useAuthStore()

onMounted(() => {
	auth.loadSession().catch(() => undefined)
})

const session = computed<NavigationSession>(() => ({
	roles: auth.roles,
	canAdminister: auth.capabilities.canAdminister === true,
	isAuthenticated: auth.isAuthenticated,
	sessionFailed: auth.error !== null && !auth.isAuthenticated,
}))

const entries = computed(() => visibleEntries(session.value))
</script>

<template>
	<nav
		v-if="entries.length > 0"
		class="pettycash-nav"
		:aria-label="t('pettycash', 'navigation.title')"
		data-test="app-navigation"
	>
		<ul class="pettycash-nav__list">
			<li v-for="entry in entries" :key="entry.name" class="pettycash-nav__item">
				<RouterLink :to="{ name: entry.name }" :data-test="entry.testId" class="pettycash-nav__link">
					{{ t('pettycash', entry.labelKey) }}
				</RouterLink>
			</li>
		</ul>
	</nav>
</template>

<style scoped>
.pettycash-nav__list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	list-style: none;
	margin: 0 0 1rem;
	padding: 0;
}

.pettycash-nav__link {
	font-size: var(--font-size-floor);
}

/* The active destination is named, not only coloured: `aria-current` is what
 * a screen reader announces, and colour alone is not a state. */
.pettycash-nav__link.router-link-exact-active {
	font-weight: 600;
}
</style>
