<template>
	<div class="revision-info" data-testid="revision-info">
		<h3 class="revision-info__title">{{ t('pettycash', 'reviews.revisionInfo') }}</h3>
		<div class="info-grid">
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.revisionNumber') }}</span>
				<span class="info-value">#{{ revision.number }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.expenseDate') }}</span>
				<span class="info-value">{{ formatDate(revision.expenseDate) }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.amount') }}</span>
				<span class="info-value money">{{ formatMoney(revision.amount) }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.description') }}</span>
				<span class="info-value">{{ revision.description || t('pettycash', 'common.notProvided') }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.costCenter') }}</span>
				<span class="info-value">{{ revision.costCenter.name }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.expenseType') }}</span>
				<span class="info-value">{{ revision.expenseType.name }}</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.evidence') }}</span>
				<span class="info-value">
					<NcChip
						:variant="hasEvidence ? 'success' : 'warning'"
						:text="hasEvidence ? t('pettycash', 'common.yes') : t('pettycash', 'common.no')"
					/>
				</span>
			</div>
			<div class="info-item">
				<span class="info-label">{{ t('pettycash', 'reviews.createdAt') }}</span>
				<span class="info-value">
					{{ revision.createdAt ? formatDateTime(revision.createdAt) : t('pettycash', 'common.unknown') }}
				</span>
			</div>
		</div>
	</div>
</template>

<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import NcChip from '@nextcloud/vue/components/NcChip'
import type { Revision } from '@/types/api'
import { formatMoney } from '@/utils/money'
import { formatSheetDate, formatSheetDateTime } from '@/utils/date'

interface Props {
	revision: Revision
	hasEvidence: boolean
}

defineProps<Props>()

/**
 * UI-PUR-001: only the field set is local. The shared helpers own the locale, the calendar and
 * the absence rule, so this component cannot drift from the other surfaces.
 */
const LONG_DATE: Intl.DateTimeFormatOptions = { year: 'numeric', month: 'long', day: 'numeric' }
const SHORT_DATE_TIME: Intl.DateTimeFormatOptions = {
	year: 'numeric',
	month: 'short',
	day: 'numeric',
	hour: '2-digit',
	minute: '2-digit',
}

function formatDate(dateStr: string): string {
	return formatSheetDate(dateStr, { options: LONG_DATE })
}

function formatDateTime(dateStr: string): string {
	return formatSheetDateTime(dateStr, { options: SHORT_DATE_TIME })
}
</script>

<style scoped>
.revision-info__title {
	margin-block-end: 16px;
}

.info-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 16px;
}

.info-item {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.info-label {
	font-size: var(--font-size-emphasis);
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
}

.info-value {
	font-size: var(--font-size-emphasis);
	font-weight: 500;
}

.money {
	font-family: monospace;
}

@media (width <= 390px) {
	.info-grid {
		grid-template-columns: 1fr;
	}
}
</style>
