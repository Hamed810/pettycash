<script setup lang="ts">
import { nextTick, useTemplateRef, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import { mdiWallet } from '@mdi/js'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import AppNavigation from '@/components/navigation/AppNavigation.vue'
import { direction } from '@/utils/direction'

/**
 * T118: route-heading focus.
 *
 * A keyboard (or screen-reader) user who follows a link must land *in* the new
 * view: without this, focus stays on `body` while the content changes elsewhere
 * and the user has to Tab from the top to find where they are. After every
 * navigation the app heading takes programmatic focus.
 *
 * `tabindex="-1"` keeps the heading out of the sequential Tab order — Tab still
 * flows link by link — and the outline is suppressed for exactly this target,
 * and only when the focus did not arrive by keyboard: the ring would otherwise
 * appear after every pointer click too, where it communicates nothing. A
 * keyboard user who activated the navigation *does* see the ring, because
 * script focus after a keyboard interaction matches `:focus-visible`, which
 * this rule does not suppress. Screen readers still announce the focused
 * heading either way.
 */
const route = useRoute()
const heading = useTemplateRef<HTMLElement>('heading')

watch(
	() => route.fullPath,
	async () => {
		await nextTick()
		heading.value?.focus({ preventScroll: true })
	}
)
</script>

<template>
	<main class="pettycash-shell" :dir="direction" aria-labelledby="pettycash-heading">
		<aside class="pettycash-sidebar">
			<div class="pettycash-brand">
				<span class="pettycash-brand__mark" aria-hidden="true">
					<NcIconSvgWrapper :path="mdiWallet" :size="22" />
				</span>
				<h1 id="pettycash-heading" ref="heading" tabindex="-1" class="pettycash-brand__name">
					Petty Cash
				</h1>
			</div>
			<AppNavigation />
		</aside>
		<div class="pettycash-content">
			<RouterView />
		</div>
	</main>
</template>

<style scoped>
.pettycash-brand {
	display: flex;
	align-items: center;
	gap: 0.65rem;
	padding: 0.25rem 0.5rem 1.25rem;
}

.pettycash-brand__mark {
	display: inline-flex;
	align-items: center;
	justify-content: center;
	flex: none;
	inline-size: 2rem;
	block-size: 2rem;
	border-radius: var(--border-radius-small);
	background-color: var(--color-primary-element-light);
	color: var(--color-primary-element);
}

.pettycash-brand__name {
	margin: 0;
	font-size: var(--font-size-section);
	font-weight: 700;
	color: var(--color-main-text);
}

@media (width <= 900px) {
	.pettycash-brand {
		padding-block-end: 0.75rem;
	}
}
</style>
