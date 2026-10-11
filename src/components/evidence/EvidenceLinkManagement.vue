<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { createPublicLink, deactivatePublicLink } from '@/services/evidenceApi'

/**
 * Administrator link administration for one evidence item (T098, FR-031).
 *
 * Link creation and revocation are administrator-only server-side; the
 * interface additionally hides the whole control from anyone else, so a
 * purchaser is never offered a button that always fails (FR-009: hiding alone
 * is not enforcement, and enforcement alone is not the interface — both hold).
 * The token URL is disclosed once, at creation: after that only revocation is
 * offered, because re-showing it would make the database-backed secrecy
 * meaningless.
 */
const props = defineProps<{
	evidenceId: string
	isAdministrator: boolean
}>()

const expiresAt = ref('')
const linkUrl = ref<string | null>(null)
const linkId = ref<string | null>(null)
const inactive = ref(false)
const busy = ref(false)
const error = ref<string | null>(null)

async function create() {
	if (busy.value) return
	busy.value = true
	error.value = null
	try {
		const link = await createPublicLink(props.evidenceId, expiresAt.value === '' ? undefined : expiresAt.value)
		linkId.value = link.id
		linkUrl.value = link.url
		inactive.value = false
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	} finally {
		busy.value = false
	}
}

async function deactivate() {
	if (busy.value || linkId.value === null) return
	busy.value = true
	error.value = null
	try {
		await deactivatePublicLink(linkId.value)
		inactive.value = true
	} catch (e: unknown) {
		const err = e as Error
		error.value = err.message
	} finally {
		busy.value = false
	}
}
</script>

<template>
	<div v-if="isAdministrator" class="link-management" dir="rtl" data-test="link-management">
		<NcTextField
			:model-value="expiresAt"
			:label="t('pettycash', 'evidence.linkExpiry')"
			data-test="link-expiry-input"
			@update:model-value="expiresAt = String($event ?? '')"
		/>
		<NcButton
			variant="secondary"
			:disabled="busy"
			data-test="create-link-btn"
			@click="create"
		>
			{{ t('pettycash', 'evidence.createLink') }}
		</NcButton>
		<p v-if="linkUrl" class="link-management__url" data-test="public-link-url">{{ linkUrl }}</p>
		<NcButton
			v-if="linkId && !inactive"
			variant="error"
			:disabled="busy"
			data-test="deactivate-link-btn"
			@click="deactivate"
		>
			{{ t('pettycash', 'evidence.deactivateLink') }}
		</NcButton>
		<p v-if="inactive" class="link-management__inactive" data-test="link-inactive">
			{{ t('pettycash', 'evidence.linkDeactivated') }}
		</p>
		<p v-if="error" class="link-management__error" role="alert" data-test="link-error">{{ error }}</p>
	</div>
</template>

<style scoped>
.link-management {
	font-size: var(--font-size-floor);
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.link-management__url {
	overflow-wrap: anywhere;
	margin: 0;
}

.link-management__inactive,
.link-management__error {
	margin: 0;
}
</style>
