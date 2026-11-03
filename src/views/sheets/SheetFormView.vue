<script setup lang="ts">
import { direction } from '@/utils/direction'
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter, type RouteLocationRaw } from 'vue-router'
import { mdiClose } from '@mdi/js'
import { useSheetStore } from '@/stores/sheets'
import { useAccountStore } from '@/stores/accounts'
import { ApiError, type SheetCreate, type SheetDetail } from '@/types/api'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import { formatNumber } from '@/utils/number'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcIconSvgWrapper from '@nextcloud/vue/components/NcIconSvgWrapper'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import UnsavedChangesDialog from '@/components/shared/UnsavedChangesDialog.vue'
import { useUnsavedChangesGuard } from '@/composables/useUnsavedChangesGuard'
import { t } from '@nextcloud/l10n'

/**
 * T079/T080: the same form implementation is rendered either full-page or inside the shared
 * Create/Edit Sheet dialog. The flag changes presentation and dismissal only; it does not add a
 * second form, validation path, or save path.
 */
const props = withDefaults(defineProps<{ inDialog?: boolean }>(), { inDialog: false })

const emit = defineEmits<{
	(event: 'created', sheet: SheetDetail): void
}>()

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()
const accountStore = useAccountStore()

const isEditing = computed(() => !!route.params.id)
const sheetId = computed(() => route.params.id as string)

/**
 * T080: where a completed edit or cancellation returns to.
 *
 * The workspace's edit control adds `?from=workspace` when it opens this route, so the form knows the
 * sheet it came from is still the right return target. A list-row edit, a direct URL, and a reload
 * without that marker return to the list. Provenance is in the URL deliberately: it survives a
 * refresh, and the URL remains an honest description of where the surface came from.
 */
const returnTarget = computed<RouteLocationRaw>(() => {
	if (isEditing.value && route.query.from === 'workspace') {
		return { name: 'sheet-detail', params: { id: sheetId.value } }
	}
	return { name: 'sheets' }
})

/**
 * T080: edit mode is only rendered from an authoritative, editable detail.
 *
 * The detail read and the account list are asynchronous. Until the matching detail arrives the form
 * must not render with an empty or previous sheet's title, and a non-`OPEN` or unreadable sheet must
 * not expose controls the server would refuse. `editLoadComplete` keeps the first render in a
 * deliberate loading state instead of briefly presenting "not found".
 */
const editLoadComplete = ref(false)
const editableSheet = computed(() =>
	isEditing.value && sheetStore.currentSheet?.id === sheetId.value ? sheetStore.currentSheet : null
)
const sheetLoading = computed(() => isEditing.value && !editLoadComplete.value)
const sheetNotEditable = computed(() =>
	editLoadComplete.value && !!editableSheet.value && editableSheet.value.status !== 'OPEN'
)
const sheetUnreadable = computed(() =>
	editLoadComplete.value && !editableSheet.value
)
const canShowForm = computed(() =>
	!isEditing.value
	// T080: a workspace-originated edit already has this sheet in the store. Without the load
	// completion gate the form would render empty from that copy, accept input, and then overwrite
	// it when the edit's own authoritative detail arrived — a lost keystroke, not a loading state.
	|| (editLoadComplete.value && !!editableSheet.value && editableSheet.value.status === 'OPEN')
)

const form = ref<SheetCreate>({ title: '', accountId: '' })
// The caller's real accounts, loaded from the OCS accounts endpoint. An invented identifier must
// never reach the API: `SheetService::createSheet()` resolves the account by public UUID and
// rejects anything it cannot find, so a placeholder account makes sheet creation fail.
const accounts = computed(() => accountStore.accounts)
const loadingAccounts = computed(() => accountStore.loading)
const accountsError = computed(() => accountStore.error)
const errors = ref<Record<string, string>>({})

const submitButtonText = computed(() => {
	if (isEditing.value) return t('pettycash', 'sheets.saveChanges')
	return props.inDialog ? t('pettycash', 'sheets.create') : t('pettycash', 'common.create')
})

// The field is named `sheets.name` in both modal modes and on the edit form. The page-level
// `sheets.title` key is the list heading ("Sheets"), which would label the field with the page name
// rather than its content; T080 also corrects that inherited edit-form label.
const titleFieldLabel = computed(() =>
	props.inDialog || isEditing.value
		? t('pettycash', 'sheets.name')
		: t('pettycash', 'sheets.title')
)

const hasMultipleAccounts = computed(() => accounts.value.length > 1)
const hasAccounts = computed(() => accounts.value.length > 0)

/**
 * The account the sheet was stored against, read from `SheetDetail` rather than from list order.
 *
 * T440/FR-019/FR-021: the account is immutable after creation. `accounts[0]` is never the answer on
 * an edit — the detail payload's `accountId` is the only authoritative identity, and a stored account
 * that has since been deactivated is absent from the active-account list while still being the
 * account this sheet belongs to.
 */
const storedAccountId = computed(() => sheetStore.currentSheet?.accountId ?? '')
const storedCurrency = computed(() => sheetStore.currentSheet?.currency ?? '')
const storedAccount = computed(() => accounts.value.find((a) => a.id === storedAccountId.value))
const storedAccountInactive = computed(() =>
	isEditing.value
	&& accountStore.loaded
	&& !loadingAccounts.value
	&& !accountsError.value
	&& storedAccountId.value !== ''
	&& !storedAccount.value
)
// T080: the account field shows the persisted identity — never a substituted account and never the
// list's first row — while the balance is presented separately. An inactive account has no current
// balance in the active list, so only its identity and the explanation below are shown.
const storedAccountBalance = computed(() =>
	storedAccount.value ? formatBalance(storedAccount.value) : ''
)

// FR-021: every transaction inherits the sheet currency, and the sheet currency is the selected
// account's currency. A single account fixes the currency; several accounts mean the choice is
// the purchaser's, so the sheet carries no currency of its own until one is selected.
const currency = computed(() => {
	if (isEditing.value) return storedCurrency.value
	if (accounts.value.length === 1) return accounts.value[0]?.balance.currency ?? ''
	const selected = accounts.value.find((a) => a.id === form.value.accountId)
	return selected?.balance.currency ?? ''
})

const accountOptions = computed(() =>
	accounts.value.map((a) => ({
		label: formatBalance(a),
		value: a.id
	}))
)

/** The caller's selected account, so its balance can be shown next to the form. */
const selectedAccount = computed(() => accounts.value.find((a) => a.id === form.value.accountId))

const availableCurrencyOptions = computed(() =>
	accountStore.availableCurrencies.map((code) => ({ label: code, value: code }))
)

/**
 * Formats a balance at its own currency's configured scale. Dividing by a fixed 100 is wrong for
 * rial, which has no minor unit, and for any currency the instance configures differently.
 */
function formatBalance(account: { balance: { amountMinor: number; currency: string; minorUnit: number } }): string {
	const factor = 10 ** account.balance.minorUnit
	return `${account.balance.currency} ${formatNumber(account.balance.amountMinor / factor)}`
}

/**
 * Whether the caller must choose an account before a sheet can be created.
 *
 * FR-020 resolves the account for them when they have none to choose from: a zero-account purchaser
 * gets the default rial account provisioned on the creation attempt. Requiring a selection in that state
 * made FR-020 unreachable from the UI — the form refused to submit, so the request that would have
 * provisioned never left the browser. Exactly one account is chosen automatically and several must be
 * chosen by hand; only **zero** is the server's to decide.
 *
 * `accountsLoaded` is part of the condition deliberately. Before the list arrives the caller has *no
 * account they can see*, and treating that as "provision one" would enable submit on a form whose
 * account list is still loading — a request built before we know whether a choice is required.
 *
 * A failed load leaves `loadingAccounts` false with `accounts` empty, which would therefore read as
 * "none to choose from" and offer to provision. That is the right answer for a genuine zero, and the
 * wrong one for an outage, so `accountsError` is shown as its own state above; provisioning a rial account
 * because a network call failed is not something a retry would forgive.
 */
const accountRequired = computed(() => !isEditing.value && !loadingAccounts.value && accounts.value.length > 0)

function validateForm() {
	errors.value = {}
	if (!form.value.title.trim()) errors.value.title = t('pettycash', 'validation.required')
	if (accountRequired.value && !form.value.accountId) {
		errors.value.accountId = t('pettycash', 'validation.required')
	}
	return Object.keys(errors.value).length === 0
}

/**
 * The same rules as `validateForm`, evaluated for the submit button's disabled state.
 *
 * A submit button that is enabled only to refuse the click hides the reason; disabling it up front
 * states the form is not yet submittable. Both derive from one set of rules so they cannot disagree.
 */
const validationErrors = computed(() => {
	const found: Record<string, string> = {}
	if (!form.value.title.trim()) found.title = t('pettycash', 'validation.required')
	if (accountRequired.value && !form.value.accountId) {
		found.accountId = t('pettycash', 'validation.required')
	}
	return found
})

const isSubmittable = computed(() => Object.keys(validationErrors.value).length === 0)

/* ------------------------------------------------------------------ *
 * UI-PUR-012: unsaved-change guard and 409 refetch-without-merge
 * ------------------------------------------------------------------ */

const baseline = ref('')
const conflictMessage = ref('')

function snapshotBaseline(): void { baseline.value = JSON.stringify(form.value) }
const isDirty = computed(() => JSON.stringify(form.value) !== baseline.value)

async function reloadAfterConflict(): Promise<void> {
	if (!isEditing.value) return
	try {
		await sheetStore.loadSheet(sheetId.value)
		if (sheetStore.currentSheet) {
			form.value.title = sheetStore.currentSheet.title
			snapshotBaseline()
		}
	} catch {
		// The store records the reload failure; the conflict message still explains what happened.
	}
}

/**
 * Stores the sheet. Returns `false` when it was refused, so the guard stays on the form.
 * Navigation is the caller's decision, not part of saving.
 */
async function persist(): Promise<boolean> {
	sheetStore.clearError()
	conflictMessage.value = ''
	try {
		let created: SheetDetail | null = null
		if (isEditing.value) {
			const version = sheetStore.currentSheet?.version
			if (version === undefined) {
				await reloadAfterConflict()
				conflictMessage.value = t('pettycash', 'guard.conflict')
				return false
			}
			await sheetStore.updateExistingSheet(sheetId.value, { title: form.value.title, expectedVersion: version })
		} else {
			created = await sheetStore.createNewSheet(buildCreateRequest())
		}
		// Record the stored values before anyone is told the sheet exists. The host navigates in
		// response to `created`, and the shared guard would otherwise treat that navigation as
		// leaving a still-dirty form and open a second, contradictory decision.
		snapshotBaseline()
		if (created && props.inDialog) emit('created', created)
		return true
	} catch (error: unknown) {
		if (error instanceof ApiError && error.status === 409) {
			await reloadAfterConflict()
			conflictMessage.value = t('pettycash', 'guard.conflict')
		}
		return false
	}
}

async function saveForGuard(): Promise<boolean> {
	if (!validateForm()) return false
	return persist()
}

const {
	open: guardOpen,
	saving: guardSaving,
	saveFailed: guardSaveFailed,
	ask: askLeave,
	stay: stayOnForm,
	saveAndLeave,
	discard: discardChanges,
} = useUnsavedChangesGuard({
	isDirty: () => isDirty.value,
	save: saveForGuard,
	// T080: a workspace-originated edit returns to that workspace; every other dismissal returns to
	// the list. The same target is used by Cancel, Discard, Escape, and the guard's Save action.
	fallback: () => returnTarget.value,
})

const rootEl = ref<HTMLElement | null>(null)
const titleInput = ref<InstanceType<typeof NcTextField> | null>(null)
const guardStayButton = ref<InstanceType<typeof NcButton> | null>(null)
const guardOpenerTestId = ref<string | null>(null)

/**
 * T079: Cancel, Escape, and the dialog's close affordance all call this one method.
 *
 * The shared guard already owns the decision — pristine navigates back to the list, dirty opens the
 * inline decision — so the modal does not reimplement "is it safe to leave?".
 */
function requestClose(): void {
	askLeave()
}

/** The dialog's initial focus target, once its content exists. */
function focusTitle(): void {
	const root = titleInput.value?.$el as HTMLElement | undefined
	if (!root) return
	const input = root.matches('input') ? root : root.querySelector<HTMLInputElement>('input')
	input?.focus()
}

function focusControl(testId: string | null): void {
	if (!testId || !rootEl.value) {
		focusTitle()
		return
	}
	const root = rootEl.value.querySelector<HTMLElement>(`[data-test="${testId}"]`)
	const control = root?.matches('input, button, select, textarea')
		? root
		: root?.querySelector<HTMLElement>('input, button, select, textarea')
	if (control) {
		control.focus()
		return
	}
	focusTitle()
}

/**
 * T080: an edit renders a loading state first, so the name field does not exist when the dialog
 * mounts. Focus follows the form once the authoritative detail has produced it.
 */
watch(canShowForm, async (visible) => {
	if (!props.inDialog || !visible) return
	await nextTick()
	focusTitle()
})

/**
 * The inline guard replaces the form's interactive area but not the dialog. `NcDialog` is configured
 * with `no-close`, because its own Escape/close path cannot be cancelled; this listener therefore
 * handles only the pristine Escape case. A dirty Escape is already handled by the shared guard, and
 * an Escape while the guard is open is handled by the guard's Stay action.
 */
function onDialogEscape(event: KeyboardEvent): void {
	if (!props.inDialog || event.key !== 'Escape') return
	if (guardOpen.value || isDirty.value) return
	event.preventDefault()
	requestClose()
}

watch(guardOpen, async (open) => {
	if (open) {
		const active = document.activeElement as HTMLElement | null
		guardOpenerTestId.value = active?.closest('[data-test]')?.getAttribute('data-test') ?? null
		await nextTick()
		guardStayButton.value?.$el?.focus()
		return
	}
	await nextTick()
	const opener = guardOpenerTestId.value
	guardOpenerTestId.value = null
	focusControl(opener)
})

async function loadAccounts() {
	await accountStore.loadAccounts()
	if (!isEditing.value && accounts.value.length > 0 && !form.value.accountId) {
		form.value.accountId = accounts.value[0]?.id ?? ''
	}
}

async function loadSheetForEdit() {
	if (!isEditing.value) return
	await sheetStore.loadSheet(sheetId.value)
	if (sheetStore.currentSheet?.id === sheetId.value) {
		form.value.title = sheetStore.currentSheet.title
	}
	await loadAccounts()
}

/**
 * The create request, with `accountId` **omitted** rather than sent empty when none is chosen.
 *
 * FR-020's zero-account case is expressed by the field's absence, and `SheetService::createSheet()`
 * treats a blank string exactly as it treats an absent field — so an empty string would also work. It is
 * omitted anyway because `SheetCreate.required` now names only `title`, and `accountId` is a `uuid`:
 * sending `""` is a present-but-invalid value that a client validating against the schema would reject
 * before the request was ever sent, which would reinstate the very refusal this change removes.
 */
function buildCreateRequest(): SheetCreate {
	const request: SheetCreate = { title: form.value.title }
	if (form.value.accountId) request.accountId = form.value.accountId

	return request
}

async function handleSubmit() {
	if (!validateForm()) return
	if (!await persist()) return
	// The create dialog closes through its host, which also refetches the authoritative list. An edit
	// returns to the surface it was opened from — the same target the unsaved-change guard uses.
	if (props.inDialog && !isEditing.value) return
	await router.push(returnTarget.value)
}

onMounted(() => {
	if (props.inDialog) {
		window.addEventListener('keydown', onDialogEscape)
		// The dialog's content is not in the document until after this hook, and the field is the
		// required initial focus target (T079 / SC-005-022).
		void nextTick().then(focusTitle)
	}
	// The stores record a failure in their own `error` ref, which this view renders in its alert.
	// A mount-time load has no caller to react to the rejection, so it is absorbed here rather than
	// surfacing as an unhandled promise rejection in the user's console.
	if (isEditing.value) {
		void (async () => {
			try {
				await loadSheetForEdit()
				snapshotBaseline()
			} catch {
				// The store records the failure; the form renders the unreadable state instead of a
				// title it never received.
			} finally {
				editLoadComplete.value = true
			}
		})()
	} else {
		loadAccounts().then(() => { snapshotBaseline() }).catch(() => undefined)
	}
})

onBeforeUnmount(() => {
	window.removeEventListener('keydown', onDialogEscape)
})
</script>

<template>
	<div
		ref="rootEl"
		class="sheet-form-view"
		:class="{ 'sheet-form-view--dialog': inDialog }"
		:dir="direction"
		data-test="sheet-form-view"
	>
		<div v-if="!inDialog" class="mb-4" />

		<component
			:is="inDialog ? 'div' : NcNoteCard"
			v-bind="inDialog
				? {
					class: 'sheet-form-surface',
					'data-test': 'sheet-form-surface',
					inert: guardOpen ? '' : undefined,
				}
				: { heading: isEditing ? t('pettycash', 'sheets.edit') : t('pettycash', 'sheets.create') }"
		>
			<div v-if="inDialog" class="sheet-form-close-row">
				<NcButton
					variant="tertiary"
					:aria-label="t('pettycash', 'common.close')"
					data-test="sheet-form-close"
					@click="requestClose"
				>
					<NcIconSvgWrapper :path="mdiClose" :size="20" />
				</NcButton>
			</div>

			<!--
				T080: the edit form is only rendered from an authoritative, editable detail. A
				non-OPEN sheet, an unreadable sheet, and the in-flight read each get a state of their
				own, so no editable control exists for a sheet the server would refuse.
			-->
			<div v-if="sheetLoading" class="sheet-form-state" data-test="sheet-edit-loading">
				<p class="empty-hint">{{ t('pettycash', 'common.loading') }}</p>
			</div>
			<div v-else-if="sheetNotEditable" class="sheet-form-state" data-test="sheet-edit-unavailable">
				<p class="empty-hint">{{ t('pettycash', 'workspace.submittedHint') }}</p>
			</div>
			<div v-else-if="sheetUnreadable" class="sheet-form-state" data-test="sheet-edit-unreadable">
				<strong>{{ t('pettycash', 'workspace.notFoundTitle') }}</strong>
				<p class="empty-hint">{{ t('pettycash', 'workspace.notFoundHint') }}</p>
			</div>

			<form v-if="canShowForm" data-test="sheet-form" @submit.prevent="handleSubmit">
				<!-- A summary as well as the per-field messages: after a refused submit the purchaser
				     needs to see what is missing without hunting for each red field. -->
				<div
					v-if="Object.keys(errors).length > 0"
					class="alert alert--error validation-summary"
					role="alert"
					data-test="validation-summary"
				>
					<strong data-test="validation-summary-title">{{ t('pettycash', 'validation.summary') }}</strong>
					<ul class="validation-summary-list">
						<li v-for="(message, field) in errors" :key="field" :data-test="`validation-summary-${field}`">
							{{ message }}
						</li>
					</ul>
				</div>

				<div class="form-row">
					<div class="form-col">
						<NcTextField
							ref="titleInput"
							v-model="form.title"
							:label="titleFieldLabel"
							:error="!!errors.title"
							:helper-text="errors.title || ''"
							:aria-label="inDialog ? t('pettycash', 'sheets.name') : t('pettycash', 'sheets.title')"
							required
							data-test="sheet-title-input"
						/>
					</div>
					<div class="form-col">
						<!--
							T440: an edit shows the account the sheet was stored against. It is read-only
							because FR-019/FR-021 make the account immutable after creation, and it comes
							from `SheetDetail.accountId` rather than from the account list's first row.
							An inactive account is absent from the active list but is still displayed by
							its persisted id, with the inactive state stated rather than silently replaced.
						-->
						<template v-if="isEditing">
							<!--
								T080: a read-only display rather than an input. A persisted account id is
								a full UUID; an input clips it at the half-width desktop column, and a
								one-line field cannot wrap. This block wraps the whole identity instead of
								hiding part of it, and the balance is stated separately.
							-->
							<div class="sheet-readonly-field" data-test="sheet-account-display">
								<span class="sheet-readonly-field__label">{{ t('pettycash', 'sheets.account') }}</span>
								<span class="sheet-readonly-field__value" data-test="sheet-account-value">
									{{ storedAccountId || t('pettycash', 'common.notProvided') }}
								</span>
							</div>
							<p v-if="loadingAccounts" class="empty-hint" data-test="accounts-loading">
								{{ t('pettycash', 'common.loading') }}
							</p>
							<p v-else-if="accountsError" class="error-hint" data-test="accounts-error">
								{{ accountsError }}
							</p>
							<p v-else-if="storedAccount" class="account-balance" data-test="sheet-account-balance">
								{{ t('pettycash', 'accounts.balance') }}: {{ storedAccountBalance }}
							</p>
							<NcNoteCard
								v-else-if="storedAccountInactive"
								type="warning"
								class="sheet-account-inactive"
								data-test="sheet-account-inactive"
							>
								{{ t('pettycash', 'sheets.accountInactive') }}
							</NcNoteCard>
						</template>
						<template v-else>
							<!--
								T079: keep the dialog's option list inside the form instead of teleporting it
								to `<body>`. In a right-to-left document the teleported menu inherits that
								direction, and the library's inline `left` is overridden by
								`inset-inline-start: 0` (which maps to `right`), so the list is pushed to
								the viewport edge and is clipped at 390px. Rendering it in place keeps it
								attached to the field and inside the dialog. The full-page edit route keeps
								the library default; T080 owns its own presentation.
							-->
							<NcSelect
								v-if="hasAccounts"
								v-model="form.accountId"
								:options="accountOptions"
								:reduce="selectOptionValue"
								:get-option-key="selectOptionKey"
								label="label"
								:input-label="t('pettycash', 'sheets.account')"
								:error="!!errors.accountId"
								:helper-text="errors.accountId || ''"
								:disabled="loadingAccounts"
								:append-to-body="!inDialog"
								required
								data-test="sheet-account-select"
							/>
							<!-- Having no petty-cash account is a normal state, so it is stated plainly
							     rather than left as an empty, unexplained dropdown. -->
							<p v-if="loadingAccounts" class="empty-hint" data-test="accounts-loading">
								{{ t('pettycash', 'common.loading') }}
							</p>
							<p v-else-if="accountsError" class="error-hint" data-test="accounts-error">
								{{ accountsError }}
							</p>
							<p v-else-if="!hasAccounts" class="empty-hint" data-test="no-accounts-hint">
								{{ t('pettycash', 'sheets.noAccounts') }}
							</p>
							<!-- FR-027: the balance is shown where the account is chosen, so the purchaser
							     sees it before committing. It is informational only — FR-045 forbids it
							     from blocking submission. -->
							<p v-if="selectedAccount" class="account-balance" data-test="account-balance">
								{{ formatBalance(selectedAccount) }}
							</p>
						</template>
					</div>
				</div>

				<!--
					T440: edit mode shows the sheet's own currency, read-only. Create mode keeps the
					existing single/multiple-account presentation below.
				-->
				<div v-if="isEditing" class="form-row">
					<div class="form-col">
						<NcTextField
							:model-value="storedCurrency"
							:name="storedCurrency"
							:label="t('pettycash', 'sheets.currency')"
							:aria-label="t('pettycash', 'sheets.currency')"
							readonly
							data-test="currency-display"
						/>
					</div>
				</div>
				<!-- The zero-account state deliberately omits this row: there is no account from which
				     a currency could be derived, and an empty currency control would be an invented one. -->
				<div v-else-if="hasAccounts" class="form-row">
					<div class="form-col">
						<NcSelect
							v-if="hasMultipleAccounts"
							:model-value="currency"
							:name="currency"
							:options="availableCurrencyOptions"
							:reduce="selectOptionValue"
							:get-option-key="selectOptionKey"
							label="label"
							:input-label="t('pettycash', 'sheets.currency')"
							disabled
							data-test="currency-select"
						/>
						<NcTextField
							v-else
							:model-value="currency"
							:name="currency"
							:label="t('pettycash', 'sheets.currency')"
							:aria-label="t('pettycash', 'sheets.currency')"
							readonly
							data-test="currency-display"
						/>
					</div>
				</div>

				<!--
					T079: while the in-dialog decision is open, its three actions are the only
					choice. Keeping the form's own primary action visible but inert would show two
					primaries for one question.
				-->
				<div v-if="!inDialog || !guardOpen" class="form-actions">
					<NcButton variant="secondary" data-test="sheet-cancel" @click="requestClose">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="sheetStore.mutationLoading || !isSubmittable"
						:loading="sheetStore.mutationLoading"
						type="submit"
						data-test="sheet-create-submit"
					>
						{{ submitButtonText }}
					</NcButton>
				</div>
			</form>

			<div v-if="!canShowForm" class="form-actions">
				<NcButton variant="secondary" data-test="sheet-state-cancel" @click="requestClose">
					{{ t('pettycash', 'common.cancel') }}
				</NcButton>
			</div>
		</component>

		<!-- The state panel above already explains an unreadable edit; one message is enough. -->
		<div v-if="sheetStore.error && (!isEditing || canShowForm)" class="alert alert--error" role="alert">
			<span>{{ sheetStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="sheetStore.clearError">
				×
			</button>
		</div>

		<NcNoteCard v-if="conflictMessage" type="warning" role="alert" data-test="conflict-message">
			{{ conflictMessage }}
		</NcNoteCard>

		<!--
			T079: the guard decision lives inside the same dialog, so there is exactly one
			[role="dialog"]. The panel is a group, not a second dialog; the form is inert while it is
			open, and Stay restores the values and the control that had focus.
		-->
		<div
			v-if="inDialog && guardOpen"
			class="sheet-form-guard"
			role="group"
			aria-labelledby="sheet-form-guard-title"
			data-test="sheet-unsaved-guard"
		>
			<h3 id="sheet-form-guard-title" class="sheet-form-guard__title">
				{{ t('pettycash', 'guard.title') }}
			</h3>
			<p class="sheet-form-guard__message">{{ t('pettycash', 'guard.message') }}</p>
			<p
				v-if="guardSaveFailed"
				class="sheet-form-guard__error"
				role="alert"
				data-test="unsaved-save-failed"
			>
				{{ t('pettycash', 'guard.saveFailed') }}
			</p>
			<div class="sheet-form-guard__actions">
				<NcButton
					ref="guardStayButton"
					variant="secondary"
					data-test="unsaved-stay"
					@click="stayOnForm"
				>
					{{ t('pettycash', 'guard.stay') }}
				</NcButton>
				<NcButton
					variant="error"
					:disabled="guardSaving"
					data-test="unsaved-discard"
					@click="discardChanges"
				>
					{{ t('pettycash', 'guard.discard') }}
				</NcButton>
				<NcButton
					variant="primary"
					:loading="guardSaving"
					:disabled="guardSaving"
					data-test="unsaved-save"
					@click="saveAndLeave"
				>
					{{ t('pettycash', 'guard.save') }}
				</NcButton>
			</div>
		</div>

		<UnsavedChangesDialog
			v-if="!inDialog"
			:open="guardOpen"
			:saving="guardSaving"
			:save-failed="guardSaveFailed"
			@stay="stayOnForm"
			@discard="discardChanges"
			@save="saveAndLeave"
		/>
	</div>
</template>

<style scoped>
.sheet-form-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

/*
 * T079: inside the dialog the form is the dialog's content, not a page. The close affordance is
 * inside the surface so it scrolls with the form and stays inside the focus trap.
 */
.sheet-form-view--dialog {
	gap: 0.75rem;
}

.sheet-form-surface {
	position: relative;
}

.sheet-form-close-row {
	display: flex;
	justify-content: flex-end;
	margin-block-end: 0.25rem;
}

/*
 * The unsaved-change decision is presented in place rather than as a second modal: a dialog over a
 * dialog is two focus traps and two `role="dialog"` elements for one question.
 */
.sheet-form-guard {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
	padding: 1rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.sheet-form-guard__title,
.sheet-form-guard__message {
	margin: 0;
}

.sheet-form-guard__title {
	font-size: var(--font-size-section);
}

.sheet-form-guard__error {
	margin: 0;
	color: var(--color-error);
}

.sheet-form-guard__actions {
	display: flex;
	flex-wrap: wrap;
	justify-content: flex-end;
	gap: 0.5rem;
}

.form-row {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
}

@media (width >= 600px) {
	.form-row {
		grid-template-columns: 1fr 1fr;
	}
}

/* A grid/flex child defaults to min-width:auto, which lets a long persisted account id push the
   whole dialog wider than the viewport instead of wrapping or ellipsizing. */
.form-col {
	display: flex;
	flex-direction: column;
	min-width: 0;
}

/*
 * T080: the account identity wraps in full instead of being clipped by a one-line input. The value is
 * a UUID, so `overflow-wrap: anywhere` is what keeps a 36-character token inside the column at 390px.
 */
.sheet-readonly-field {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.5rem 0.75rem;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	background-color: var(--color-main-background);
}

.sheet-readonly-field__label {
	color: var(--color-text-maxcontrast);
	font-size: var(--font-size-floor);
}

.sheet-readonly-field__value {
	font-size: var(--font-size-floor);
	line-height: 1.4;
	overflow-wrap: anywhere;
	unicode-bidi: plaintext;
}

.sheet-account-inactive {
	margin-block-start: 0.5rem;
}

.sheet-form-state {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 1rem 0;
}

.sheet-form-state p {
	margin: 0;
}

.form-actions {
	display: flex;
	justify-content: flex-end;
	gap: 0.5rem;
	margin-top: 1rem;
}

.validation-summary-list {
	margin: 0;
	padding-inline-start: 1.25rem;
}

.account-balance {
	margin: 0.25rem 0 0;
	font-size: var(--font-size-floor);
	opacity: 0.8;
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
	background-color: var(--color-background-hover);
	color: var(--color-error);
	border-radius: var(--border-radius-small);
}

.alert--error {
	background-color: var(--color-background-hover);
	color: var(--color-error);
}

.alert button {
	background: none;
	border: none;
	font-size: 1.5rem;
	cursor: pointer;
	color: inherit;
	opacity: 0.7;
}

.alert button:hover {
	opacity: 1;
}
</style>