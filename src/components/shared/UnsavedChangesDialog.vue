<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'

const props = defineProps<{
	open: boolean
	saving?: boolean
	saveFailed?: boolean
}>()

const emit = defineEmits<{
	(event: 'save'): void
	(event: 'discard'): void
	(event: 'stay'): void
}>()

const stayButton = ref<InstanceType<typeof NcButton> | null>(null)
const panel = ref<HTMLElement | null>(null)
let opener: HTMLElement | null = null

function stay(): void {
	emit('stay')
}

watch(() => props.open, async (isOpen) => {
	if (isOpen) {
		opener = document.activeElement as HTMLElement | null
		await nextTick()
		stayButton.value?.$el?.focus()
	} else {
		opener?.focus()
		opener = null
	}
})

const FOCUSABLE = 'button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'

function onKeydown(event: KeyboardEvent): void {
	if (event.key === 'Escape') {
		event.preventDefault()
		stay()
		return
	}
	if (event.key !== 'Tab' || !panel.value) return
	const nodes = Array.from(panel.value.querySelectorAll<HTMLElement>(FOCUSABLE))
	if (nodes.length === 0) return
	const first = nodes[0]
	const last = nodes[nodes.length - 1]
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault()
		last?.focus()
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault()
		first?.focus()
	}
}
</script>

<template>
	<div v-if="open" class="unsaved-guard" data-test="unsaved-changes-dialog">
		<div class="unsaved-guard__scrim" />
		<section
			ref="panel"
			class="unsaved-guard__panel"
			role="dialog"
			aria-modal="true"
			aria-labelledby="unsaved-guard-title"
			data-test="unsaved-changes-panel"
			@keydown="onKeydown">
			<h2 id="unsaved-guard-title" class="unsaved-guard__title">
				{{ t('pettycash', 'guard.title') }}
			</h2>
			<p class="unsaved-guard__message">{{ t('pettycash', 'guard.message') }}</p>
			<p
				v-if="saveFailed"
				class="unsaved-guard__error"
				role="alert"
				data-test="unsaved-save-failed">
				{{ t('pettycash', 'guard.saveFailed') }}
			</p>
			<div class="unsaved-guard__actions">
				<NcButton
					ref="stayButton"
					variant="secondary"
					data-test="unsaved-stay"
					@click="stay">
					{{ t('pettycash', 'guard.stay') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="saving"
					data-test="unsaved-discard"
					@click="emit('discard')">
					{{ t('pettycash', 'guard.discard') }}
				</NcButton>
				<NcButton
					variant="primary"
					:loading="saving"
					:disabled="saving"
					data-test="unsaved-save"
					@click="emit('save')">
					{{ t('pettycash', 'guard.save') }}
				</NcButton>
			</div>
		</section>
	</div>
</template>

<style scoped>
.unsaved-guard {
	position: fixed;
	inset: 0;
	z-index: 10050;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 1rem;
}

.unsaved-guard__scrim {
	position: absolute;
	inset: 0;
	background-color: var(--color-backdrop);
}

.unsaved-guard__panel {
	position: relative;
	inline-size: min(100%, 30rem);
	padding: 1.5rem;
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
	color: var(--color-main-text);
	box-shadow: 0 8px 32px var(--color-box-shadow);
	font-size: var(--font-size-floor);
}

.unsaved-guard__title {
	margin-block-start: 0;
}

.unsaved-guard__error {
	color: var(--color-error);
}

.unsaved-guard__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-block-start: 1.5rem;
}

.unsaved-guard__panel :focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

@media (prefers-reduced-motion: reduce) {
	.unsaved-guard__panel {
		transition: none;
	}
}
</style>
