<script setup lang="ts">
import { computed } from 'vue'
import { t } from '@nextcloud/l10n'
import type { Money } from '@/types/api'
import { formatMoney } from '@/utils/money'

const props = withDefaults(
	defineProps<{
		balance: Money
		minorUnit: number
	}>(),
	{}
)

/**
 * Owed-aware balance display (T032).
 *
 * Renders the authoritative `balance.amountMinor` unchanged — never clamped, never sign-flipped,
 * never client-derived. When negative, the magnitude (a Money copy with absolute minor units)
 * is shown beside the approved English owed label; Persian localization is deferred to T036.
 */
const owed = computed(() => props.balance.amountMinor < 0)
const owedText = computed(() => owed.value ? t('pettycash', 'financeBalance.owedLabel') : '')
const magnitude = computed(() =>
	owed.value ? { ...props.balance, amountMinor: Math.abs(props.balance.amountMinor) } : props.balance
)
</script>

<template>
	<span
		:data-testid="owed ? 'balance-owed' : 'balance'"
		:class="{ 'balance--owed': owed }"
		:aria-label="owed ? t('pettycash', 'financeBalance.owedLabel') : formatMoney(balance)"
	>
		<template v-if="owed">
			{{ formatMoney(magnitude) }} {{ owedText }}
		</template>
		<template v-else>
			{{ formatMoney(magnitude) }}
		</template>
	</span>
</template>

<style scoped>
.balance--owed {
	color: var(--color-error-text);
}
</style>
