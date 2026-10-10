<script setup lang="ts">
import { ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'

/**
 * A transaction's immutable revision history (T098, FR-048, FR-050).
 *
 * Presentational: the caller supplies revisions in stored order and this
 * component only selects which one is shown. Prior revisions stay viewable —
 * nothing here edits, and nothing here hides a superseded revision.
 */
export interface RevisionOption {
	id: string
	number: number
	expenseDate?: string
}

const props = defineProps<{
	revisions: RevisionOption[]
}>()

const selectedId = ref(props.revisions[0]?.id ?? '')

watch(
	() => props.revisions,
	(next) => {
		if (!next.some((revision) => revision.id === selectedId.value)) {
			selectedId.value = next[0]?.id ?? ''
		}
	}
)

function select(id: string) {
	selectedId.value = id
}
</script>

<template>
	<div class="revision-history" dir="rtl" data-test="revision-history">
		<div v-if="revisions.length > 0" class="revision-tabs" data-test="revision-tabs" role="tablist">
			<button
				v-for="revision in revisions"
				:key="revision.id"
				type="button"
				role="tab"
				:aria-selected="revision.id === selectedId"
				:class="['revision-tab', { active: revision.id === selectedId }]"
				data-test="revision-tab"
				@click="select(revision.id)"
			>
				{{ t('pettycash', 'transactions.revisionNumber', { number: revision.number }) }}
			</button>
		</div>
		<p v-else class="revision-history__empty" data-test="revision-empty">
			{{ t('pettycash', 'transactions.noRevisions') }}
		</p>
	</div>
</template>

<style scoped>
.revision-history {
	font-size: var(--font-size-floor);
}

.revision-tabs {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.revision-tab[aria-selected="true"] {
	font-weight: bold;
}

.revision-history__empty {
	margin: 0;
}
</style>
