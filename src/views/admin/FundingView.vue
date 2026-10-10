<script setup lang="ts">
/**
 * T088: the funding surface — open an account, fund it, retire or restore it.
 *
 * Three operations on one view because they are one journey: an administrator opens an account for
 * a user, funds it, and later retires or restores it, and each step hands the next its identifiers.
 * Creating shows the new account (id and version); funding and deactivation act on the account id
 * shown, or on one pasted from the accounts report — there is no endpoint listing another user's
 * accounts, so cross-user funding addresses an id from the creation response or the report, never
 * from a lookup this view cannot perform.
 *
 * All writes go through `useAccountStore`, which reloads afterwards: a stale refusal leaves the
 * displayed rows at the versions the server actually holds.
 */
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcNoteCard from '@nextcloud/vue/components/NcNoteCard'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcDateTimePickerNative from '@nextcloud/vue/components/NcDateTimePickerNative'
import { t } from '@nextcloud/l10n'
import { useAccountStore } from '@/stores/accounts'

const accountStore = useAccountStore()

onMounted(async () => {
	await accountStore.loadAccounts().catch(() => undefined)
})

const openForm = ref({ userId: '', currency: '' })
const createdAccountId = ref<string | null>(null)

async function openAccount() {
	const account = await accountStore.openAccount({ userId: openForm.value.userId, currency: openForm.value.currency })
	createdAccountId.value = account.id
	fundForm.value.accountId = account.id
}

const fundForm = ref({ accountId: '', amountMinor: 0, description: '', occurredAt: '' })

/**
 * The picker speaks `Date`, the contract speaks ISO strings — converted at the boundary in both
 * directions, following `TransactionFormView`'s `expenseDateModel`. A string bound straight to the
 * picker is a type error, and a `Date` sent to the server is a shape the contract does not declare.
 */
const occurredAtModel = computed<Date | null>({
	get: () => (fundForm.value.occurredAt ? new Date(fundForm.value.occurredAt) : null),
	set: (value: Date | null) => {
		fundForm.value.occurredAt = value ? value.toISOString() : ''
	},
})

/**
 * The version the charge must carry, read from the loaded list rather than remembered: funding is
 * the write two officers make at the same moment, and a charge applied to a moved balance is money
 * that vanishes from the ledger. Unknown id, unknown version — the button stays off rather than
 * sending a guess the server must refuse.
 */
const fundVersion = computed(() => accountStore.accountById(fundForm.value.accountId)?.version)
const canFund = computed(() => fundVersion.value !== undefined && fundForm.value.amountMinor > 0)

async function fundAccount() {
	if (fundVersion.value === undefined) return
	await accountStore.fundAccount(fundForm.value.accountId, {
		amountMinor: fundForm.value.amountMinor,
		description: fundForm.value.description,
		occurredAt: fundForm.value.occurredAt,
		expectedVersion: fundVersion.value,
	})
}

const activeForm = ref({ accountId: '', explanation: '' })

/** The account's current state, when the id names a loaded row — otherwise the control stays neutral. */
const activeState = computed(() => accountStore.accountById(activeForm.value.accountId)?.active ?? null)
const activeVersion = computed(() => accountStore.accountById(activeForm.value.accountId)?.version)

async function flipActive() {
	if (activeState.value === null || activeVersion.value === undefined) return
	await accountStore.setAccountActive(
		activeForm.value.accountId,
		{ active: !activeState.value, explanation: activeForm.value.explanation || null },
		activeVersion.value,
	)
}
</script>

<template>
	<div class="funding-view" dir="rtl" data-test="funding-view">
		<nav :aria-label="t('pettycash', 'navigation.title')" data-test="funding-breadcrumb">
			<RouterLink :to="{ name: 'dashboard' }" data-test="funding-back-dashboard">{{ t('pettycash', 'navigation.dashboard') }}</RouterLink>
		</nav>
		<h2 data-test="funding-heading">{{ t('pettycash', 'administration.funding') }}</h2>

		<div v-if="accountStore.error" class="alert alert--error" role="alert" data-test="funding-error">
			<span>{{ accountStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="accountStore.resetError">×</button>
		</div>

		<!-- Open -->
		<NcNoteCard :heading="t('pettycash', 'administration.openAccount')" data-test="funding-open">
			<form data-test="open-account-form" @submit.prevent="openAccount">
				<NcTextField v-model="openForm.userId" :label="t('pettycash', 'administration.user')" data-test="open-account-user-input" />
				<NcTextField v-model="openForm.currency" :label="t('pettycash', 'administration.currency')" data-test="open-account-currency-input" />
				<NcButton variant="primary" type="submit" data-test="open-account-save">
					{{ t('pettycash', 'common.create') }}
				</NcButton>
			</form>
			<p v-if="createdAccountId" data-test="open-account-result">{{ createdAccountId }}</p>
		</NcNoteCard>

		<!-- Fund -->
		<NcNoteCard :heading="t('pettycash', 'administration.fundAccount')" data-test="funding-charge">
			<form data-test="charge-form" @submit.prevent="fundAccount">
				<NcTextField v-model="fundForm.accountId" :label="t('pettycash', 'administration.account')" data-test="charge-account-input" />
				<NcTextField v-model.number="fundForm.amountMinor" type="number" step="1" :label="t('pettycash', 'transactions.amount')" data-test="charge-amount-input" />
				<NcTextField v-model="fundForm.description" :label="t('pettycash', 'administration.description')" data-test="charge-description-input" />
				<NcDateTimePickerNative v-model="occurredAtModel" :label="t('pettycash', 'administration.occurredAt')" data-test="charge-date-input" />
				<NcButton variant="primary" type="submit" :disabled="!canFund" data-test="charge-save">
					{{ t('pettycash', 'administration.fund') }}
				</NcButton>
			</form>
		</NcNoteCard>

		<!-- Retire / restore -->
		<NcNoteCard :heading="t('pettycash', 'administration.accountState')" data-test="funding-active">
			<form data-test="active-form" @submit.prevent="flipActive">
				<NcTextField v-model="activeForm.accountId" :label="t('pettycash', 'administration.account')" data-test="active-account-input" />
				<NcTextField v-model="activeForm.explanation" :label="t('pettycash', 'administration.changeReason')" data-test="active-reason-input" />
				<NcButton variant="primary" type="submit" :disabled="activeState === null" data-test="active-save">
					{{ activeState === false ? t('pettycash', 'administration.reactivate') : t('pettycash', 'administration.deactivate') }}
				</NcButton>
			</form>
		</NcNoteCard>
	</div>
</template>

<style scoped>
.funding-view {
	display: flex;
	flex-direction: column;
	gap: 16px;
}
</style>
