<script setup lang="ts">
import { computed, onMounted } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import ExportControls from '@/components/reports/ExportControls.vue'
import ReportFilterBar from '@/components/reports/ReportFilterBar.vue'
import ReportTable from '@/components/reports/ReportTable.vue'
import { useReportsStore, type DownloadableExport } from '@/stores/reports'
import type { ExportFormat, ReportFilters, ReportType } from '@/types/reporting'

const reports = useReportsStore()

const subjectLabel = (reportType: ReportType): string => t('pettycash', `reports.type.${reportType}`)

const pageInfo = computed(() =>
	t('pettycash', 'reports.pagination', {
		page: String(reports.page),
		totalPages: String(reports.totalPages),
		total: String(reports.total),
	})
)

/** Hands the streamed artifact to the browser under the name the store derived for it. */
function download(payload: DownloadableExport): void {
	const url = URL.createObjectURL(payload.blob)
	const anchor = document.createElement('a')
	anchor.href = url
	anchor.download = payload.filename
	document.body.appendChild(anchor)
	anchor.click()
	document.body.removeChild(anchor)
	URL.revokeObjectURL(url)
}

async function onExport(format: ExportFormat): Promise<void> {
	try {
		download(await reports.exportReport(format))
	} catch {
		// Recorded by the store and rendered by ExportControls, which offers the manual retry.
	}
}

/**
 * Every store call reports its own failure through `error`/`exportError`, which the states below
 * render with a manual retry. These handlers keep a rejected read from also escaping as an
 * unhandled promise rejection.
 */
function onLoad(): void {
	reports.loadReport().catch(() => undefined)
}

function onSelectSubject(subject: ReportType): void {
	reports.selectReportType(subject).catch(() => undefined)
}

function onApplyFilters(filters: ReportFilters): void {
	reports.applyFilters(filters).catch(() => undefined)
}

function onResetFilters(): void {
	reports.resetFilters().catch(() => undefined)
}

function onGoToPage(next: number): void {
	reports.goToPage(next).catch(() => undefined)
}

onMounted(onLoad)
</script>

<template>
	<section class="report-view" dir="rtl" aria-labelledby="report-view-title" data-testid="report-view">
		<header class="report-view__header">
			<h2 id="report-view-title" class="report-view__title">
				{{ t('pettycash', 'reports.title') }}
			</h2>
		</header>

		<ul class="report-view__subjects" :aria-label="t('pettycash', 'reports.subjects')">
			<li v-for="subject in reports.reportTypes" :key="subject">
				<NcButton
					variant="tertiary"
					:disabled="reports.loading"
					:aria-pressed="reports.reportType === subject"
					:data-report-type="subject"
					:data-testid="`report-subject-${subject}`"
					@click="onSelectSubject(subject)"
				>
					{{ subjectLabel(subject) }}
				</NcButton>
			</li>
		</ul>

		<ReportFilterBar
			:filters="reports.filters"
			:report-type="reports.reportType"
			:disabled="reports.loading"
			@apply="onApplyFilters"
			@reset="onResetFilters"
		/>

		<ExportControls
			:available-formats="reports.availableFormats"
			:exporting="reports.exporting"
			:error="reports.exportError"
			:field-errors="reports.exportFieldErrors"
			@export="onExport"
			@dismiss="reports.clearExportError()"
		/>

		<div v-if="reports.loading" class="report-view__state" data-testid="report-view-loading">
			{{ t('pettycash', 'common.loading') }}
		</div>

		<div v-else-if="reports.error" class="report-view__state report-view__state--error" role="alert" data-testid="report-view-error">
			<span>{{ reports.error }}</span>
			<NcButton
				variant="tertiary"
				data-testid="report-view-retry"
				@click="onLoad"
			>
				{{ t('pettycash', 'common.retry') }}
			</NcButton>
		</div>

		<div v-else-if="reports.isEmpty" class="report-view__state" data-testid="report-view-empty">
			{{ t('pettycash', 'reports.empty') }}
		</div>

		<ReportTable
			v-else
			:columns="reports.columns"
			:rows="reports.rows"
			:summary="reports.summary"
			:loading="reports.loading"
		/>

		<nav
			v-if="reports.hasRows"
			class="report-view__pagination"
			:aria-label="t('pettycash', 'reports.pagination.label')"
			data-testid="report-pagination"
		>
			<NcButton
				variant="tertiary"
				:disabled="!reports.canGoPrevious || reports.loading"
				data-testid="report-previous"
				@click="onGoToPage(reports.page - 1)"
			>
				{{ t('pettycash', 'common.previous') }}
			</NcButton>
			<span data-testid="report-page-info">{{ pageInfo }}</span>
			<NcButton
				variant="tertiary"
				:disabled="!reports.canGoNext || reports.loading"
				data-testid="report-next"
				@click="onGoToPage(reports.page + 1)"
			>
				{{ t('pettycash', 'common.next') }}
			</NcButton>
		</nav>
	</section>
</template>

<style scoped>
.report-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
	min-width: 0;
}

.report-view__title {
	margin: 0;
}

.report-view__subjects {
	display: flex;
	flex-wrap: wrap;
	gap: 0.25rem;
	list-style: none;
	margin: 0;
	padding: 0;
}

.report-view__state {
	padding: 1.5rem;
	text-align: center;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
}

.report-view__state--error {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}

.report-view__pagination {
	display: flex;
	align-items: center;
	gap: 0.75rem;
	flex-wrap: wrap;
}
</style>
