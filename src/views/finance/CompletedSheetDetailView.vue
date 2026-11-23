<template>
	<div class="completed-sheet-detail-view" :dir="direction" data-testid="completed-sheet-detail-view">
		<CompletedSheetDetail :sheet-id="route.params.sheetId as string" />
	</div>
</template>

<script setup lang="ts">
import { direction } from '@/utils/direction'
import { useRoute } from 'vue-router'
import CompletedSheetDetail from '@/components/finance/CompletedSheetDetail.vue'

const route = useRoute()
</script>

<style scoped>
.completed-sheet-detail-view {
	padding: 16px;
}
</style>
