<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import {
	mdiArrowULeftTop,
	mdiChartBar,
	mdiClipboardCheckOutline,
	mdiCogOutline,
	mdiDotsHorizontal,
	mdiFileDocumentOutline,
	mdiViewDashboardOutline,
	mdiWalletOutline,
	mdiCashPlus,
} from '@mdi/js'
import { t } from '@nextcloud/l10n'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
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

/**
 * The approved narrow shell is one row of four tabs. A session can legitimately carry more
 * destinations than that -- a purchaser who also reviews renders three queues, returns, accounts
 * and reports on top of dashboard and sheets -- so the narrow row keeps the first three and folds
 * the rest into a native disclosure. This is the responsive overflow mechanism for the refinement:
 * a horizontal scroller is ruled out by 004/FR-007, and the wide sidebar keeps every entry visible
 * because the fold applies only while the bottom bar is in use.
 */
const NARROW_VIEWPORT = '(max-width: 900px)'
const NARROW_SLOT_COUNT = 4
const narrowViewport = ref(false)
const moreDetails = ref<HTMLDetailsElement | null>(null)
let narrowQuery: MediaQueryList | null = null

function onNarrowChange(event: MediaQueryListEvent) {
	narrowViewport.value = event.matches
}

onMounted(() => {
	auth.loadSession().catch(() => undefined)
	if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return
	narrowQuery = window.matchMedia(NARROW_VIEWPORT)
	narrowViewport.value = narrowQuery.matches
	narrowQuery.addEventListener?.('change', onNarrowChange)
})

onBeforeUnmount(() => {
	narrowQuery?.removeEventListener?.('change', onNarrowChange)
})

const session = computed<NavigationSession>(() => ({
	roles: auth.roles,
	canAdminister: auth.capabilities.canAdminister === true,
	isAuthenticated: auth.isAuthenticated,
	sessionFailed: auth.error !== null && !auth.isAuthenticated,
}))

const entries = computed(() => visibleEntries(session.value))

const showOverflow = computed(() => narrowViewport.value && entries.value.length > NARROW_SLOT_COUNT)
const barEntries = computed(() => (showOverflow.value ? entries.value.slice(0, NARROW_SLOT_COUNT - 1) : entries.value))
const overflowEntries = computed(() => (showOverflow.value ? entries.value.slice(NARROW_SLOT_COUNT - 1) : []))

function closeOverflow() {
	if (moreDetails.value) moreDetails.value.open = false
}

/**
 * The approved shell gives every destination a familiar icon. The mapping is presentational only:
 * visibility still comes from `visibleEntries()` and authorization still belongs to the server.
 */
const ENTRY_ICONS: Record<string, string> = {
	dashboard: mdiViewDashboardOutline,
	sheets: mdiFileDocumentOutline,
	'review-queue-cost-center': mdiClipboardCheckOutline,
	'review-queue-senior': mdiClipboardCheckOutline,
	'review-queue-finance': mdiClipboardCheckOutline,
	'review-queue-returns': mdiArrowULeftTop,
	accounts: mdiWalletOutline,
	reports: mdiChartBar,
	'admin-configuration': mdiCogOutline,
	'admin-funding': mdiCashPlus,
}

function iconFor(name: string): string {
	return ENTRY_ICONS[name] ?? mdiClipboardCheckOutline
}
</script>

<template>
	<nav
		v-if="entries.length > 0"
		class="pettycash-nav"
		:aria-label="t('pettycash', 'navigation.title')"
		data-test="app-navigation"
	>
		<ul class="pettycash-nav__list">
			<li v-for="entry in barEntries" :key="entry.name" class="pettycash-nav__item">
				<RouterLink :to="{ name: entry.name }" :data-test="entry.testId" class="pettycash-nav__link">
					<NcIconSvgWrapper
						:path="iconFor(entry.name)"
						:size="20"
						class="pettycash-nav__icon"
						aria-hidden="true"
					/>
					<span>{{ t('pettycash', entry.labelKey) }}</span>
				</RouterLink>
			</li>
			<li v-if="showOverflow" class="pettycash-nav__item pettycash-nav__item--more">
				<details ref="moreDetails" class="pettycash-nav__more" @keydown.esc="closeOverflow">
					<summary class="pettycash-nav__link pettycash-nav__more-summary" data-test="nav-more">
						<NcIconSvgWrapper
							:path="mdiDotsHorizontal"
							:size="20"
							class="pettycash-nav__icon"
							aria-hidden="true"
						/>
						<span>{{ t('pettycash', 'navigation.more') }}</span>
					</summary>
					<ul class="pettycash-nav__overflow">
						<li v-for="entry in overflowEntries" :key="entry.name">
							<RouterLink
								:to="{ name: entry.name }"
								:data-test="entry.testId"
								class="pettycash-nav__link"
								@click="closeOverflow"
							>
								<NcIconSvgWrapper
									:path="iconFor(entry.name)"
									:size="20"
									class="pettycash-nav__icon"
									aria-hidden="true"
								/>
								<span>{{ t('pettycash', entry.labelKey) }}</span>
							</RouterLink>
						</li>
					</ul>
				</details>
			</li>
		</ul>
	</nav>
</template>

<style scoped>
.pettycash-nav__list {
	display: flex;
	flex-direction: column;
	gap: 0.2rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.pettycash-nav__link {
	display: flex;
	align-items: center;
	gap: 0.7rem;
	padding: 0.62rem 0.75rem;
	border-radius: var(--border-radius-large);
	color: var(--color-main-text);
	font-size: var(--font-size-floor);
	text-decoration: none;
}

.pettycash-nav__link:hover {
	background-color: var(--color-background-hover);
}

.pettycash-nav__icon {
	flex: none;
	color: var(--color-text-maxcontrast);
}

/* The active destination is named, not only coloured: `aria-current` is what
 * a screen reader announces, and colour alone is not a state. */
.pettycash-nav__link.router-link-exact-active {
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
	font-weight: 600;
}

.pettycash-nav__link.router-link-exact-active .pettycash-nav__icon {
	color: var(--color-primary-element);
}

@media (width <= 900px) {
	.pettycash-nav {
		position: fixed;
		inset-block-end: 0;
		inset-inline: 0;
		z-index: 100;
		padding: 0.35rem 0.5rem calc(0.35rem + env(safe-area-inset-bottom));
		border-block-start: 1px solid var(--color-border);
		background-color: var(--color-main-background);
	}

	/*
	 * One row, as the approved mobile shell draws it: four equal slots across the width, so the
	 * visible destinations sit exactly where the reference's four tabs sit. A session with more
	 * than four destinations keeps three in the row and folds the rest into the More disclosure
	 * below -- no horizontal scrolling anywhere (004/FR-007).
	 */
	.pettycash-nav__list {
		flex-flow: row nowrap;
		gap: 0;
	}

	.pettycash-nav__item {
		flex: 0 0 25%;
		min-inline-size: 0;
	}

	.pettycash-nav__link {
		flex-direction: column;
		justify-content: center;
		gap: 0.15rem;
		min-block-size: 3.2rem;
		padding: 0.3rem 0.15rem;
		line-height: 1.15;
		text-align: center;
		white-space: normal;
	}

	.pettycash-nav__link span {
		font-size: var(--font-size-floor);
		overflow-wrap: anywhere;
	}

	.pettycash-nav__item--more {
		position: relative;
	}

	.pettycash-nav__more-summary {
		list-style: none;
		cursor: pointer;
	}

	.pettycash-nav__more-summary::-webkit-details-marker {
		display: none;
	}

	.pettycash-nav__more[open] > .pettycash-nav__more-summary {
		background-color: var(--color-background-hover);
	}

	/* The disclosure opens above the bar; anchoring its inline end to the last slot keeps it on
	   screen in both writing directions without any viewport-specific measurement. */
	.pettycash-nav__overflow {
		position: absolute;
		z-index: 1;
		inset-block-end: calc(100% + 0.35rem);
		inset-inline-end: 0;
		display: flex;
		flex-direction: column;
		gap: 0.1rem;
		min-inline-size: 12rem;
		max-block-size: 60vh;
		overflow-y: auto;
		margin: 0;
		padding: 0.35rem;
		list-style: none;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
		background-color: var(--color-main-background);
		box-shadow: 0 6px 18px var(--color-box-shadow);
	}

	.pettycash-nav__overflow .pettycash-nav__link {
		flex-direction: row;
		justify-content: flex-start;
		gap: 0.7rem;
		min-block-size: 0;
		padding: 0.55rem 0.6rem;
		text-align: start;
	}
}
</style>
