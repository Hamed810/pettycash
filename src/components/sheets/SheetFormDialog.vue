<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import { NcDialog } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import SheetFormView from '@/views/sheets/SheetFormView.vue'
import type { SheetDetail } from '@/types/api'
import { direction } from '@/utils/direction'

/**
 * T079/T080: the route-driven host for the shared Create/Edit Sheet form.
 *
 * `/sheets/create` and `/sheets/:id/edit` render the mounted Sheets List with this dialog over it.
 * The dialog adds presentation only — the form, its validation, its save path, and the store mutation
 * all remain the single implementation in `SheetFormView`.
 *
 * The heading is derived from the same route the form uses for its edit/create branch, so the URL and
 * the visible mode cannot disagree.
 *
 * `no-close` is deliberate. `NcDialog`'s own Escape handler and close button cannot be cancelled, so
 * they would dismiss the dialog even when the form holds unsaved values. The form owns both
 * dismissal paths and routes them through the shared unsaved-changes guard; Keep/Stay restores the
 * entered values instead of losing them. Backdrop clicks are inert because `closeOnClickOutside`
 * defaults to false.
 */
defineProps<{
	/** Whether the dialog is mounted. The host route decides this. */
	open: boolean
}>()

const emit = defineEmits<{
	(event: 'created', sheet: SheetDetail): void
}>()

const route = useRoute()
const dialogName = computed(() =>
	route.name === 'sheet-edit'
		? t('pettycash', 'sheets.edit')
		: t('pettycash', 'sheets.create')
)
</script>

<template>
	<NcDialog
		:open="open"
		:name="dialogName"
		:dir="direction"
		size="normal"
		no-close
		data-test="sheet-form-dialog"
	>
		<SheetFormView in-dialog @created="emit('created', $event)" />
	</NcDialog>
</template>
