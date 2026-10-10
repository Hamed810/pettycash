<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import DashboardCard from '@/components/dashboard/DashboardCard.vue'
import { useDashboardStore } from '@/stores/dashboard'
import { useAuthStore } from '@/stores/auth'
import { visibleEntries } from '@/types/navigation'

const dashboard = useDashboardStore()
const auth = useAuthStore()

/**
 * T013/T016 (specs/004-in-app-navigation): the dashboard cards are metrics,
 * not destinations (`DashboardCard.vue` stays presentational), so the entry
 * points live here as explicit links reusing the same `visibleEntries` table
 * as the global navigation — one role table, two surfaces, no drift.
 */
const quickLinks = computed(() =>
	visibleEntries({
		roles: auth.roles,
		canAdminister: auth.capabilities.canAdminister === true,
		isAuthenticated: auth.isAuthenticated,
		sessionFailed: auth.error !== null && !auth.isAuthenticated,
	}).filter((entry) => entry.name !== 'dashboard')
)

/**
 * The store records the failure in `error` and the state below renders it with a manual retry, so a
 * rejected read is caught here rather than escaping the mount as an unhandled rejection.
 */
function onLoad(): void {
	dashboard.loadDashboard().catch(() => undefined)
}

onMounted(onLoad)
</script>

<template>
	<section class="dashboard" dir="rtl" aria-labelledby="dashboard-title" data-testid="dashboard-view">
		<h2 id="dashboard-title" class="dashboard__title">
			{{ t('pettycash', 'dashboard.title') }}
		</h2>

		<div v-if="dashboard.loading" class="dashboard__state" data-testid="dashboard-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div
			v-else-if="dashboard.error"
			class="dashboard__state dashboard__state--error"
			role="alert"
			data-testid="dashboard-error"
		>
			<span>{{ dashboard.error }}</span>
			<NcButton
				variant="tertiary"
				data-testid="dashboard-retry"
				@click="onLoad"
			>
				{{ t('pettycash', 'common.retry') }}
			</NcButton>
		</div>

		<div v-else-if="dashboard.isEmpty" class="dashboard__state" data-testid="dashboard-empty">
			{{ t('pettycash', 'dashboard.noRoles') }}
		</div>

		<ul v-else class="dashboard__cards" :aria-label="t('pettycash', 'dashboard.cards')">
			<li v-for="card in dashboard.cards" :key="card.role" class="dashboard__card-item">
				<DashboardCard
					:role="card.role"
					:metrics="card.metrics"
					:notes="card.notes"
				/>
			</li>
		</ul>

		<nav
			v-if="quickLinks.length > 0"
			class="dashboard__quicklinks"
			:aria-label="t('pettycash', 'navigation.title')"
			data-testid="dashboard-quicklinks"
		>
			<ul class="dashboard__quicklinks-list">
				<li v-for="entry in quickLinks" :key="entry.name" class="dashboard__quicklinks-item">
					<RouterLink :to="{ name: entry.name }" :data-testid="`dashboard-link-${entry.name}`">
						{{ t('pettycash', entry.labelKey) }}
					</RouterLink>
				</li>
			</ul>
		</nav>
	</section>
</template>

<style scoped>
.dashboard {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	min-inline-size: 0;
}

.dashboard__title {
	margin: 0;
	font-size: var(--font-size-title);
}

/*
 * FR-071: the cards reflow onto one column at a 390px viewport, so the listed elements stay visible
 * without horizontal scroll. The columns are `auto-fit` rather than a fixed count, so a wide screen
 * fills the row and a narrow one never needs one.
 */
.dashboard__cards {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
	gap: 1rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.dashboard__card-item {
	min-inline-size: 0;
}

/*
 * T013/T016: entry points beside the metric cards. Same wrapping flex idiom
 * as the pager rows elsewhere — reflows at 390px instead of scrolling (FR-071).
 */
.dashboard__quicklinks-list {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem 0.75rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.dashboard__quicklinks {
	font-size: var(--font-size-floor);
}

.dashboard__state {
	padding: 1.5rem;
	text-align: center;
	font-size: var(--font-size-floor);
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
}

.dashboard__state--error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

@media (width <= 390px) {
	.dashboard__cards {
		grid-template-columns: 1fr;
	}
}
</style>
