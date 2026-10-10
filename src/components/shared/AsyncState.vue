<script setup lang="ts">
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import { t } from '@nextcloud/l10n'
import type { AsyncStatus } from '@/composables/useAsyncAction'

/**
 * T121: the normalized loading/error/empty surface.
 *
 * One component instead of twenty hand-rolled state blocks: loading shows a
 * spinner, error shows the message with `role="alert"` plus an explicit retry
 * button (wired by the view to `useAsyncAction().retry()` — nothing here retries
 * on its own), empty shows the empty message, and otherwise the content slot
 * renders. Slots let a view override any state without forking the shape.
 */
withDefaults(
	defineProps<{
		status: AsyncStatus
		error?: string | null
		empty?: boolean
		emptyMessage?: string
		retryTestId?: string
	}>(),
	{
		error: null,
		empty: false,
		emptyMessage: '',
		retryTestId: 'async-retry',
	}
)

defineEmits<{
	retry: []
}>()
</script>

<template>
	<div v-if="status === 'loading'" class="async-state" data-testid="async-loading">
		<NcLoadingIcon :size="44" />
		<p>{{ t('pettycash', 'common.loading') }}</p>
		<slot name="loading" />
	</div>

	<div
		v-else-if="status === 'error'"
		class="async-state async-state--error"
		role="alert"
		data-testid="async-error"
	>
		<p>{{ error ?? t('pettycash', 'common.error') }}</p>
		<NcButton variant="tertiary" :data-testid="retryTestId" @click="$emit('retry')">
			{{ t('pettycash', 'common.retry') }}
		</NcButton>
		<slot name="error" />
	</div>

	<div v-else-if="empty" class="async-state" data-testid="async-empty">
		<p>{{ emptyMessage || t('pettycash', 'common.empty') }}</p>
		<slot name="empty" />
	</div>

	<slot v-else />
</template>

<style scoped>
.async-state {
	display: flex;
	flex-direction: column;
	align-items: center;
	gap: 0.5rem;
	padding: 1.5rem;
	text-align: center;
	font-size: var(--font-size-floor);
}

.async-state--error {
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
}
</style>
