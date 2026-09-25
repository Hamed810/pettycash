<template>
	<div class="evidence-list" data-testid="evidence-list">
		<h3 class="evidence-list__title">
			{{ t('pettycash', 'reviews.evidence') }}
			<span v-if="evidence.length > 0" class="evidence-count">{{ evidence.length }}</span>
		</h3>

		<NcEmptyContent
			v-if="evidence.length === 0"
			:name="t('pettycash', 'reviews.evidence')"
			:description="t('pettycash', 'reviews.noEvidence')"
			data-testid="no-evidence" />

		<div v-else class="evidence-grid" data-testid="evidence-grid">
			<EvidenceCard
				v-for="item in evidence"
				:key="item.id"
				:evidence="item" />
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcEmptyContent from '@nextcloud/vue/components/NcEmptyContent'
import EvidenceCard from './EvidenceCard.vue'

interface Props {
	evidence: Array<{
		id: string
		transactionId: string
		revisionId: string
		name: string
		mediaType: string
		sizeBytes: number
		active: boolean
		uploadedBy: string
		uploadedAt: string
		deactivatedAt?: string
	}>
}

defineProps<Props>()
</script>

<style scoped>
.evidence-list {
	direction: rtl;
}

.evidence-list__title {
	display: flex;
	justify-content: space-between;
	align-items: center;
	gap: 8px;
	margin-block-end: 16px;
}

.evidence-count {
	background: var(--color-primary-element);
	color: var(--color-primary-element-text);
	padding: 2px 8px;
	border-radius: 12px;
	font-size: 12px;
	font-weight: 500;
}

.evidence-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
	gap: 16px;
	min-height: 200px;
}

@media (width <= 390px) {
	.evidence-grid {
		grid-template-columns: 1fr;
		min-height: auto;
	}
}
</style>
