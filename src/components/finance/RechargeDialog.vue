<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { NcButton, NcDialog } from '@nextcloud/vue'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'
import BalanceAmount from './BalanceAmount.vue'
import { useFinanceStore } from '@/stores/finance'
import type { HolderAccountSummary } from '@/types/api'
import type { MoneyInputError } from '@/utils/moneyInput'

const props = defineProps<{
	open: boolean
	account: HolderAccountSummary
	holderName: string | null
}>()

const emit = defineEmits<{
	(e: 'update:open', value: boolean): void
	(e: 'success'): void
}>()

const store = useFinanceStore()

const amountInput = ref<HTMLElement | null>(null)
const opener = ref<HTMLElement | null>(null)

/** Open the flow for the selected account: draft from the account payload, focus the amount input. */
watch(
	() => props.open,
	async (isOpen) => {
		if (isOpen) {
			opener.value = document.activeElement as HTMLElement | null
			store.startRecharge(props.account, props.holderName)
			await nextTick()
			amountInput.value?.focus()
		}
	}
)

function onAmountInput(event: Event): void {
	store.setRechargeAmount((event.target as HTMLInputElement).value)
}

function close(): void {
	emit('update:open', false)
	store.cancelRecharge()
	opener.value?.focus()
}

function enterReview(): void {
	store.confirmRecharge()
}

async function confirm(): Promise<void> {
	await store.submitRecharge()
	if (store.rechargeStatus === 'success') {
		emit('success')
	}
}

async function retry(): Promise<void> {
	await store.retryRecharge()
}

function startOver(): void {
	store.newIntendedRecharge()
}

const validationMessages: Record<MoneyInputError, string> = {
	empty: t('pettycash', 'financeRecharge.error.empty'),
	malformed: t('pettycash', 'financeRecharge.error.malformed'),
	negative: t('pettycash', 'financeRecharge.error.negative'),
	non_positive: t('pettycash', 'financeRecharge.error.non_positive'),
	excess_precision: t('pettycash', 'financeRecharge.error.excess_precision'),
	too_large: t('pettycash', 'financeRecharge.error.too_large'),
	unsupported_scale: t('pettycash', 'financeRecharge.error.too_large')
}

const validationMessage = computed(() => {
	const reason = store.rechargeValidationReason
	return reason ? validationMessages[reason] : ''
})

const draftAmount = computed(() => {
	if (store.rechargeAmountMinor === null || !store.rechargeCurrency) return ''
	return formatMoney({
		amountMinor: store.rechargeAmountMinor,
		currency: store.rechargeCurrency,
		minorUnit: store.rechargeMinorUnit
	})
})

const holderLabel = computed(() => props.holderName ?? props.account.userId)
</script>

<template>
	<NcDialog
		:name="t('pettycash', 'financeRecharge.title')"
		size="normal"
		:open="open"
		:can-close="store.rechargeStatus !== 'submitting'"
		@update:open="(value: boolean) => !value && close()"
	>
		<!-- Amount entry: validation-gated, no request is sent. -->
		<div v-if="store.rechargeStatus === 'idle'" class="recharge-dialog__step" data-testid="recharge-step-amount">
			<div class="recharge-dialog__context">
				<p><strong>{{ t('pettycash', 'financeRecharge.label.holder') }}:</strong> {{ holderLabel }}</p>
				<p><strong>{{ t('pettycash', 'financeRecharge.label.account') }}:</strong> {{ account.id }}</p>
				<p><strong>{{ t('pettycash', 'financeRecharge.label.currency') }}:</strong> {{ account.currency }}</p>
				<p>
					<strong>{{ t('pettycash', 'financeRecharge.label.balance') }}:</strong>
					<BalanceAmount :balance="store.rechargeBalance!" :minor-unit="account.minorUnit" />
				</p>
			</div>
			<div class="recharge-dialog__field">
				<label :for="'recharge-amount-input'">{{ t('pettycash', 'financeRecharge.label.amount') }}</label>
				<input
					id="recharge-amount-input"
					ref="amountInput"
					data-testid="recharge-amount-input"
					:value="store.rechargeAmountText"
					inputmode="decimal"
					:aria-invalid="store.rechargeValidationReason !== null"
					:aria-errormessage="store.rechargeValidationReason ? 'recharge-amount-error' : undefined"
					@input="onAmountInput"
				>
				<p v-if="store.rechargeValidationReason" id="recharge-amount-error" class="recharge-dialog__error" role="alert" data-testid="recharge-amount-error">
					{{ validationMessage }}
				</p>
			</div>
			<div class="recharge-dialog__actions">
				<NcButton
					data-testid="recharge-button-cancel"
					@click="close"
				>
					{{ t('pettycash', 'common.cancel') }}
				</NcButton>
				<NcButton
					:disabled="!store.canConfirm"
					data-testid="recharge-button-review"
					@click="enterReview"
				>
					{{ t('pettycash', 'financeRecharge.button.review') }}
				</NcButton>
			</div>
		</div>

		<!-- Review: authoritative figures only, no POST. -->
		<div v-else-if="store.rechargeStatus === 'review'" class="recharge-dialog__step" data-testid="recharge-step-review">
			<div class="recharge-dialog__context">
				<p><strong>{{ t('pettycash', 'financeRecharge.label.holder') }}:</strong> {{ holderLabel }}</p>
				<p><strong>{{ t('pettycash', 'financeRecharge.label.account') }}:</strong> {{ account.id }}</p>
				<p><strong>{{ t('pettycash', 'financeRecharge.label.currency') }}:</strong> {{ account.currency }}</p>
				<p><strong>{{ t('pettycash', 'financeRecharge.label.amount') }}:</strong> {{ draftAmount }}</p>
				<p>
					<strong>{{ t('pettycash', 'financeRecharge.label.balance') }}:</strong>
					<BalanceAmount :balance="store.rechargeBalance!" :minor-unit="account.minorUnit" />
				</p>
				<p>
					<strong>{{ t('pettycash', 'financeRecharge.label.description') }}:</strong>
					{{ t('pettycash', 'financeRecharge.label.recharge') }}
				</p>
			</div>
			<div class="recharge-dialog__actions">
				<NcButton
					data-testid="recharge-button-cancel"
					@click="close"
				>
					{{ t('pettycash', 'common.cancel') }}
				</NcButton>
				<NcButton
					data-testid="recharge-button-confirm"
					@click="confirm"
				>
					{{ t('pettycash', 'financeRecharge.button.confirm') }}
				</NcButton>
			</div>
		</div>

		<!-- Submitting: controls disabled, no duplicate submit. -->
		<div v-else-if="store.rechargeStatus === 'submitting'" class="recharge-dialog__step" data-testid="recharge-step-submitting">
			<p class="recharge-dialog__status">{{ t('pettycash', 'financeRecharge.state.submitting') }}</p>
		</div>

		<!-- Success: server-authoritative state only. -->
		<div v-else-if="store.rechargeStatus === 'success'" class="recharge-dialog__step" data-testid="recharge-step-success">
			<p class="recharge-dialog__status recharge-dialog__status--success">{{ t('pettycash', 'financeRecharge.state.success') }}</p>
			<p>
				<strong>{{ t('pettycash', 'financeRecharge.success.balance') }}:</strong>
				<BalanceAmount :balance="store.rechargeBalance!" :minor-unit="account.minorUnit" />
			</p>
			<div class="recharge-dialog__actions">
				<NcButton data-testid="recharge-button-close" @click="close">
					{{ t('pettycash', 'common.close') }}
				</NcButton>
			</div>
		</div>

		<!-- Definitive error: never rendered as success, no balance change shown. -->
		<div v-else-if="store.rechargeStatus === 'error'" class="recharge-dialog__step" data-testid="recharge-step-error">
			<p class="recharge-dialog__status recharge-dialog__status--error" role="status" aria-live="polite" data-testid="recharge-error">
				{{ store.rechargeError }}
			</p>
			<div class="recharge-dialog__actions">
				<NcButton
					data-testid="recharge-button-start-over"
					@click="startOver"
				>
					{{ t('pettycash', 'financeRecharge.button.tryAgain') }}
				</NcButton>
				<NcButton data-testid="recharge-button-close" @click="close">
					{{ t('pettycash', 'common.close') }}
				</NcButton>
			</div>
		</div>

		<!-- Unknown result: safe retry reuses the same command id. -->
		<div v-else-if="store.rechargeStatus === 'unknown'" class="recharge-dialog__step" data-testid="recharge-step-unknown">
			<p class="recharge-dialog__status recharge-dialog__status--unknown" role="status" aria-live="polite" data-testid="recharge-unknown">
				{{ t('pettycash', 'financeRecharge.state.unknown') }}
			</p>
			<p v-if="store.rechargeError" data-testid="recharge-unknown-detail">{{ store.rechargeError }}</p>
			<div class="recharge-dialog__actions">
				<NcButton data-testid="recharge-button-retry" @click="retry">
					{{ t('pettycash', 'financeRecharge.button.retry') }}
				</NcButton>
				<NcButton
					data-testid="recharge-button-close"
					@click="close"
				>
					{{ t('pettycash', 'common.close') }}
				</NcButton>
			</div>
		</div>
	</NcDialog>
</template>

<style scoped>
.recharge-dialog__context {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block-end: 16px;
	font-size: var(--font-size-floor);
}

.recharge-dialog__field {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-block-end: 16px;
	font-size: var(--font-size-floor);
}

.recharge-dialog__field label {
	display: block;
}

.recharge-dialog__actions {
	display: flex;
	gap: 8px;
	justify-content: flex-end;
	margin-block-start: 16px;
}

.recharge-dialog__status {
	margin-block-end: 12px;
	font-size: var(--font-size-floor);
}

.recharge-dialog__status--success {
	color: var(--nc-color-on-success-container, #1b5e20);
}

.recharge-dialog__status--error,
.recharge-dialog__status--unknown {
	color: var(--nc-color-on-error-container, #c62828);
}

.recharge-dialog__error {
	margin: 0;
	color: var(--nc-color-on-error-container, #c62828);
	font-size: var(--font-size-floor);
}
</style>
