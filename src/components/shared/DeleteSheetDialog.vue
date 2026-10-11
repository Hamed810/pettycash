<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'

/**
 * FR-086's confirmation step, shared by the sheet list and the sheet detail.
 *
 * ## Why a confirmation is required rather than a courtesy
 *
 * Deleting a draft sheet cascades to its transactions, their revisions, **and the stored evidence files** —
 * and those files cannot be recovered, because a never-submitted sheet has no audit trail to restore from.
 * A single mis-click destroys evidence irreversibly. This is one of the few V1 operations where asking first is
 * the difference between a recoverable mistake and a permanent one, so the dialog states the consequence rather
 * than asking a generic "are you sure?".
 *
 * ## Why the sheet is named in the body
 *
 * A purchaser with three open sheets cannot otherwise tell which one is about to be deleted. FR-086 requires the
 * confirmation to name it, and a generic dialog would leave the caller guessing which sheet they just agreed to
 * destroy.
 *
 * ## Why focus handling is here and not left to the dialog component
 *
 * User Story 1 is a **primary journey**, so SC-009 already requires it to be completable "using the keyboard
 * alone" with a visible focus indicator on every interactive element reached. A dialog a keyboard user cannot
 * enter, operate, or leave fails that criterion even though every control inside it is individually focusable.
 * So on open, focus moves to the dialog's first control; on close, it returns to whatever opened it.
 *
 * `NcDialog` restores focus to the opener on its own, but only when it is the element that opened it — and here
 * the opener differs between the two surfaces (a row action in the list, a toolbar button on the detail). Doing it
 * explicitly means the behaviour does not depend on where the component happens to be mounted.
 *
 * **Refuses to take focus for granted:** the `nextTick` is required because the dialog's content is not in the
 * document until after the open state has propagated, so focusing synchronously targets nothing.
 */
const props = defineProps<{
	sheetId: string
	sheetTitle: string
	open: boolean
	busy?: boolean
}>()

const emit = defineEmits<{
	(event: 'confirm'): void
	(event: 'cancel'): void
	(event: 'update:open', value: boolean): void
}>()

const cancelButton = ref<InstanceType<typeof NcButton> | null>(null)

/** The opener, captured before the dialog takes focus so focus can be given back. */
const opener = ref<HTMLElement | null>(null)

const title = computed(() => props.sheetTitle || props.sheetId)

watch(
	() => props.open,
	async (isOpen) => {
		if (isOpen) {
			opener.value = document.activeElement as HTMLElement | null
			// After the dialog has rendered, so the ref resolves.
			await nextTick()
			cancelButton.value?.$el?.focus()
		}
	}
)

function close(): void {
	emit('update:open', false)
	emit('cancel')
	// Returning focus is what makes the journey completable with the keyboard: without it a keyboard user
	// is left with focus on a removed element and has to rediscover where they were.
	opener.value?.focus()
}

function confirm(): void {
	// Deliberately does not restore focus: the sheet is gone, so the opener no longer exists. The caller
	// navigates, and the list is the landing surface.
	emit('update:open', false)
	emit('confirm')
}
</script>

<template>
	<NcDialog
		:name="`delete-sheet-${sheetId}`"
		size="normal"
		:open="open"
		:can-close="!busy"
		@update:open="(value: boolean) => !value && close()">
		<h2 class="delete-sheet-dialog__heading">
			{{ t('pettycash', 'Delete this sheet?') }}
		</h2>
		<p class="delete-sheet-dialog__body">
			{{
				t(
					'pettycash',
					'“{title}” and everything in it will be permanently deleted, including its draft transactions and their uploaded evidence files. This cannot be undone.'
				, { title })
			}}
		</p>
		<p class="delete-sheet-dialog__body">
			{{ t('pettycash', 'Only a sheet that has not been submitted can be deleted.') }}
		</p>
		<div class="delete-sheet-dialog__actions">
			<NcButton
				ref="cancelButton"
				:disabled="busy"
				data-test="cancel-delete-sheet"
				@click="close">
				{{ t('pettycash', 'common.cancel') }}
			</NcButton>
			<NcButton
				variant="error"
				:disabled="busy"
				data-test="confirm-delete-sheet"
				@click="confirm">
				{{ t('pettycash', 'Delete permanently') }}
			</NcButton>
		</div>
	</NcDialog>
</template>

<style scoped>
.delete-sheet-dialog__heading {
	font-weight: bold;
	margin-block-end: 8px;
}

.delete-sheet-dialog__body {
	margin-block-end: 8px;
}

.delete-sheet-dialog__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-block-start: 16px;
}
</style>