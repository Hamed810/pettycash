<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, onMounted, nextTick, ref } from 'vue'
import { useRoute } from 'vue-router'
import { t } from '@nextcloud/l10n'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import { useAuditStore } from '@/stores/audit'
import { useAuthStore } from '@/stores/auth'
import AuditTimeline from '@/components/audit/AuditTimeline.vue'
import RevisionHistory from '@/components/audit/RevisionHistory.vue'
import EvidenceList from '@/components/evidence/EvidenceList.vue'
import EvidencePreview from '@/components/evidence/EvidencePreview.vue'
import EvidenceLinkManagement from '@/components/evidence/EvidenceLinkManagement.vue'
import { getTransaction } from '@/services/transactionsApi'
import { isLegacyDraftEvent } from '@/composables/useSheetHistory'
import type { Evidence, TransactionDetail } from '@/types/api'

/**
 * One transaction's formal history (T098, US6/AC1).
 *
 * The audit timeline is the surface FR-051 defines per transaction; revisions
 * show the immutable history FR-048 protects; the evidence list shows active
 * state and deactivation history (FR-058/FR-059). Link administration is
 * administrator-only (FR-031) and hidden otherwise. Visibility itself is
 * enforced server-side (FR-055) — an out-of-scope visit arrives here as the
 * store's error, never as another transaction's history.
 */
const route = useRoute()
const auditStore = useAuditStore()
const authStore = useAuthStore()

const transactionId = computed(() => route.params.transactionId as string)
const evidence = ref<Evidence[]>([])
const evidenceError = ref<string | null>(null)
const detail = ref<TransactionDetail | null>(null)
const selectedEvidence = ref<Evidence | null>(null)
const previewOpen = computed(() => selectedEvidence.value !== null)
let previewOpener: HTMLElement | null = null

const isAdministrator = computed(() => authStore.hasRole('ADMINISTRATOR'))
const isDraft = computed(() => detail.value?.status === 'DRAFT')

/**
 * T069. The audit page is an official-history surface, so it applies the same read-side boundary as
 * the transaction surface: a never-submitted draft has no official events or revisions, and legacy
 * draft-stage working events are filtered from a submitted transaction's timeline.
 *
 * Nothing is deleted. `auditStore` keeps whatever the server returned; this is a projection.
 */
const officialEvents = computed(() => {
	if (isDraft.value) return []
	return auditStore.items.filter((event) => !isLegacyDraftEvent(event))
})

const workingCorrection = computed(() => {
	const active = detail.value?.activeRevision
	if (detail.value?.status !== 'AWAITING_PURCHASER_CORRECTION' || !active || auditStore.revisions.length < 2) return null
	return auditStore.revisions.find((revision) => revision.id === active.id) ?? null
})

const officialRevisions = computed(() => {
	if (isDraft.value) return []
	if (workingCorrection.value) {
		return auditStore.revisions.filter((revision) => revision.id !== workingCorrection.value?.id)
	}
	return auditStore.revisions
})

function openPreview(item: Evidence) {
	previewOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null
	selectedEvidence.value = item
}

function closePreview() {
	selectedEvidence.value = null
	void nextTick(() => {
		if (previewOpener?.isConnected) previewOpener.focus()
		previewOpener = null
	})
}

onMounted(async () => {
	// Roles are loaded here rather than inherited from the navigation shell:
	// link administration is gated on them, and a view that only renders its
	// gate correctly when another component happened to mount first is
	// ordering-dependent by construction. Rejected loads are absorbed — the
	// store records them — so an unreachable session reads as unknown, never
	// as an administrator.
	authStore.loadSession().catch(() => undefined)
	await Promise.allSettled([
		auditStore.loadAudit(transactionId.value),
		auditStore.loadRevisions(transactionId.value),
		loadDetail()
	])
})

async function loadDetail() {
	try {
		const loaded = await getTransaction(transactionId.value)
		detail.value = loaded
		evidence.value = loaded.evidence ?? []
	} catch (e: unknown) {
		const err = e as Error
		evidenceError.value = err.message
	}
}
</script>

<template>
	<div class="audit-view" :dir="direction" data-test="audit-view">
		<NcNoteCard v-if="auditStore.error" type="error" data-test="audit-load-error">
			{{ auditStore.error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="evidenceError" type="error" data-test="evidence-load-error">
			{{ evidenceError }}
		</NcNoteCard>

		<section :aria-label="t('pettycash', 'audit.timeline')">
			<h2>{{ t('pettycash', 'audit.timeline') }}</h2>
			<div v-if="isDraft" class="audit-view__working-draft" data-test="audit-working-draft">
				<strong>{{ t('pettycash', 'transactions.workingDraft') }}</strong>
				<p>{{ t('pettycash', 'transactions.workingDraftHint') }}</p>
			</div>
			<AuditTimeline v-else :events="officialEvents" />
		</section>

		<section :aria-label="t('pettycash', 'transactions.revisionHistory')">
			<h2>{{ t('pettycash', 'transactions.revisionHistory') }}</h2>
			<div v-if="workingCorrection" class="audit-view__working-correction" data-test="audit-working-correction">
				<strong>{{ t('pettycash', 'transactions.workingCorrection') }} — {{ t('pettycash', 'transactions.revision') }} {{ workingCorrection.number }}</strong>
				<p>{{ t('pettycash', 'transactions.workingCorrectionHint') }}</p>
			</div>
			<RevisionHistory :revisions="officialRevisions" />
		</section>

		<section :aria-label="t('pettycash', 'evidence.list')">
			<h2>{{ t('pettycash', 'evidence.list') }}</h2>
			<EvidenceList :evidence="evidence" :is-draft="false" @preview="openPreview" />
			<div v-for="item in evidence" :key="item.id" class="audit-view__item">
				<EvidenceLinkManagement :evidence-id="item.id" :is-administrator="isAdministrator" />
			</div>
		</section>

		<!-- T068: one preview layer for the list, not one per row. -->
		<EvidencePreview
			:evidence="selectedEvidence"
			:open="previewOpen"
			@close="closePreview" />
	</div>
</template>

<style scoped>
.audit-view {
	font-size: var(--font-size-floor);
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.audit-view h2 {
	font-size: var(--font-size-emphasis);
	margin: 0 0 8px;
}

.audit-view__item {
	margin-top: 8px;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.audit-view__working-draft,
.audit-view__working-correction {
	padding: 8px;
	border-radius: var(--border-radius);
	background-color: var(--color-background-hover);
}

.audit-view__working-draft p,
.audit-view__working-correction p {
	margin: 4px 0 0;
	color: var(--color-text-maxcontrast);
}
</style>
