<script setup lang="ts">
import { RouterView } from 'vue-router'
import AppNavigation from '@/components/navigation/AppNavigation.vue'
</script>

<template>
	<main class="pettycash-shell" aria-labelledby="pettycash-heading">
		<h1 id="pettycash-heading">
			Petty Cash
		</h1>
		<AppNavigation />
		<RouterView />
	</main>
</template>
