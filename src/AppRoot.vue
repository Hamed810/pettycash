<script setup lang="ts">
import { nextTick, useTemplateRef, watch } from 'vue'
import { RouterView, useRoute } from 'vue-router'
import AppNavigation from '@/components/navigation/AppNavigation.vue'

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
	<main class="pettycash-shell" aria-labelledby="pettycash-heading">
		<h1 id="pettycash-heading" ref="heading" tabindex="-1" class="pettycash-shell__heading">
			Petty Cash
		</h1>
		<AppNavigation />
		<RouterView />
	</main>
</template>

<style scoped>
.pettycash-shell__heading:focus:not(:focus-visible) {
	outline: none;
}
</style>
