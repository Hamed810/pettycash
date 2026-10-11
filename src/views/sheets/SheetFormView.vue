<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import { useAccountStore } from '@/stores/accounts'
import type { SheetCreate } from '@/types/api'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import { t } from '@nextcloud/l10n'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()
const accountStore = useAccountStore()

const isEditing = computed(() => !!route.params.id)
const sheetId = computed(() => route.params.id as string)

const form = ref<SheetCreate>({ title: '', accountId: '' })
// The caller's real accounts, loaded from the OCS accounts endpoint. An invented identifier must
// never reach the API: `SheetService::createSheet()` resolves the account by public UUID and
// rejects anything it cannot find, so a placeholder account makes sheet creation fail.
const accounts = computed(() => accountStore.accounts)
const loadingAccounts = computed(() => accountStore.loading)
const accountsError = computed(() => accountStore.error)
const errors = ref<Record<string, string>>({})

const submitButtonText = computed(() => isEditing.value ? t('pettycash', 'common.update') : t('pettycash', 'common.create'))

const hasMultipleAccounts = computed(() => accounts.value.length > 1)
const hasAccounts = computed(() => accounts.value.length > 0)
// FR-021: every transaction inherits the sheet currency, and the sheet currency is the selected
// account's currency. A single account fixes the currency; several accounts mean the choice is
// the purchaser's, so the sheet carries no currency of its own until one is selected.
const currency = computed(() => {
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
	return `${account.balance.currency} ${(account.balance.amountMinor / factor).toLocaleString('fa-IR')}`
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
const accountRequired = computed(() => !loadingAccounts.value && accounts.value.length > 0)

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

async function loadAccounts() {
	await accountStore.loadAccounts()
	if (accounts.value.length > 0 && !form.value.accountId) {
		form.value.accountId = accounts.value[0]?.id ?? ''
	}
}

async function loadSheetForEdit() {
	if (!isEditing.value) return
	await sheetStore.loadSheet(sheetId.value)
	if (sheetStore.currentSheet) {
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
	sheetStore.clearError()
	try {
		if (isEditing.value) {
			await sheetStore.updateExistingSheet(sheetId.value, form.value, sheetStore.currentSheet!.version)
		} else {
			await sheetStore.createNewSheet(buildCreateRequest())
		}
		router.push({ name: 'sheets' })
	} catch {
		// Error handled in store
	}
}

onMounted(() => {
	// The stores record a failure in their own `error` ref, which this view renders in its alert.
	// A mount-time load has no caller to react to the rejection, so it is absorbed here rather than
	// surfacing as an unhandled promise rejection in the user's console.
	if (isEditing.value) loadSheetForEdit().catch(() => undefined)
	else loadAccounts().catch(() => undefined)
})
</script>

<template>
	<div class="sheet-form-view" dir="rtl" data-test="sheet-form-view">
		<div class="mb-4">
		</div>

		<NcNoteCard :heading="isEditing ? t('pettycash', 'sheets.edit') : t('pettycash', 'sheets.create')">
			<form @submit.prevent="handleSubmit" data-test="sheet-form">
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
							v-model="form.title"
							:label="t('pettycash', 'sheets.title')"
							:error="!!errors.title"
							:helper-text="errors.title || ''"
							:aria-label="t('pettycash', 'sheets.title')"
							required
							data-test="sheet-title-input"
						/>
					</div>
					<div class="form-col">
						<NcSelect
							v-model="form.accountId"
							:options="accountOptions"
							:reduce="selectOptionValue"
							:get-option-key="selectOptionKey"
							:label="t('pettycash', 'sheets.account')"
							:input-label="t('pettycash', 'sheets.account')"
							:aria-label-combobox="t('pettycash', 'sheets.account')"
							:error="!!errors.accountId"
							:helper-text="errors.accountId || ''"
							:disabled="loadingAccounts || !hasAccounts"
							required
							data-test="sheet-account-select"
						/>
						<!-- Having no petty-cash account is a normal state, so it is stated plainly
						     rather than left as an empty, unexplained dropdown. -->
						<p v-if="!loadingAccounts && !accountsError && !hasAccounts" class="empty-hint" data-test="no-accounts-hint">
							{{ t('pettycash', 'sheets.noAccounts') }}
						</p>
						<p v-else-if="accountsError" class="error-hint" data-test="accounts-error">
							{{ accountsError }}
						</p>
						<!-- FR-027: the balance is shown where the account is chosen, so the purchaser
						     sees it before committing. It is informational only — FR-045 forbids it
						     from blocking submission. -->
						<p v-if="selectedAccount" class="account-balance" data-test="account-balance">
							{{ formatBalance(selectedAccount) }}
						</p>
					</div>
				</div>

				<div class="form-row" v-if="hasMultipleAccounts">
					<div class="form-col">
						<NcSelect
							v-model="currency"
							:name="currency"
							:options="availableCurrencyOptions"
							:label="t('pettycash', 'sheets.currency')"
							:input-label="t('pettycash', 'sheets.currency')"
							:aria-label-combobox="t('pettycash', 'sheets.currency')"
							disabled
							data-test="currency-select"
						/>
					</div>
				</div>

				<div class="form-row" v-else>
					<div class="form-col">
						<NcTextField
							v-model="currency"
							:name="currency"
							:label="t('pettycash', 'sheets.currency')"
							:aria-label="t('pettycash', 'sheets.currency')"
							readonly
							data-test="currency-display"
						/>
					</div>
				</div>

				<div class="form-actions">
					<NcButton variant="secondary" @click="router.push({ name: 'sheets' })">
						{{ t('pettycash', 'common.cancel') }}
					</NcButton>
					<NcButton
						variant="primary"
						:disabled="sheetStore.loading || !isSubmittable"
						:loading="sheetStore.loading"
						type="submit"
						data-test="sheet-create-submit"
					>
						{{ submitButtonText }}
					</NcButton>
				</div>
			</form>
		</NcNoteCard>

		<div v-if="sheetStore.error" class="alert alert--error" role="alert">
			<span>{{ sheetStore.error }}</span>
			<button type="button" @click="sheetStore.clearError" :aria-label="t('pettycash', 'common.dismiss')">
				×
			</button>
		</div>
	</div>
</template>

<style scoped>
.sheet-form-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
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

.form-col {
	display: flex;
	flex-direction: column;
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
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
	border-radius: 4px;
}

.alert--error {
	background-color: var(--nc-color-error-container, #fce4ec);
	color: var(--nc-color-on-error-container, #c62828);
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