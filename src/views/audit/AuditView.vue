<script setup lang="ts">
import { computed, onMounted } from 'vue'
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
import { ref } from 'vue'
import type { Evidence } from '@/types/api'

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

const isAdministrator = computed(() => authStore.hasRole('ADMINISTRATOR'))

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
		loadEvidence()
	])
})

async function loadEvidence() {
	try {
		const detail = await getTransaction(transactionId.value)
		evidence.value = detail.evidence ?? []
	} catch (e: unknown) {
		const err = e as Error
		evidenceError.value = err.message
	}
}
</script>

<template>
	<div class="audit-view" dir="rtl" data-test="audit-view">
		<NcNoteCard v-if="auditStore.error" type="error" data-test="audit-load-error">
			{{ auditStore.error }}
		</NcNoteCard>
		<NcNoteCard v-else-if="evidenceError" type="error" data-test="evidence-load-error">
			{{ evidenceError }}
		</NcNoteCard>

		<section :aria-label="t('pettycash', 'audit.timeline')">
			<h2>{{ t('pettycash', 'audit.timeline') }}</h2>
			<AuditTimeline :events="auditStore.items" />
		</section>

		<section :aria-label="t('pettycash', 'transactions.revisionHistory')">
			<h2>{{ t('pettycash', 'transactions.revisionHistory') }}</h2>
			<RevisionHistory :revisions="auditStore.revisions" />
		</section>

		<section :aria-label="t('pettycash', 'evidence.list')">
			<h2>{{ t('pettycash', 'evidence.list') }}</h2>
			<EvidenceList :evidence="evidence" :is-draft="false" />
			<div v-for="item in evidence" :key="item.id" class="audit-view__item">
				<EvidencePreview :evidence="item" />
				<EvidenceLinkManagement :evidence-id="item.id" :is-administrator="isAdministrator" />
			</div>
		</section>
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
</style>
