<template>
	<div class="completed-sheets-view" :dir="direction" data-testid="completed-sheets-view">
		<CompletedSheetTable />
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import CompletedSheetTable from '@/components/finance/CompletedSheetTable.vue'
</script>

<style scoped>
.completed-sheets-view {
	padding: 16px;
}
</style>
