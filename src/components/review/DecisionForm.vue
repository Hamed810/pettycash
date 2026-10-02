<template>
	<div class="decision-form" data-testid="decision-form">
		<h3 class="decision-form__title">{{ t('pettycash', 'reviews.recordDecision') }}</h3>

		<form class="decision-form__body" novalidate @submit.prevent="submitDecision">
			<NcSelect
				v-model="selectedDecision"
				:options="decisionItems"
				:reduce="selectOptionValue"
				:get-option-key="selectOptionKey"
				:input-label="t('pettycash', 'reviews.decision')"
				:required="true"
				:error="!!decisionError"
				:helper-text="decisionError"
				data-testid="decision-select"
				class="decision-form__field" />

			<!--
				`error` + `helper-text`, not `error-message`.

				`@nextcloud/vue` 9.x declares `error` (boolean) and `helperText` (string); it has no
				`errorMessage` prop. Passing `error-message` therefore fell through as a plain attribute
				on the component's root and the message was never rendered at all — so FR-033's
				acceptance scenario ("the decision is rejected until an explanation is given") left the
				purchaser's manager looking at a submit button that had silently become disabled, with
				nothing saying why.

				No `aria-describedby` is passed: the control appends the passed value to the id of the
				helper-text element it renders, so naming an id of our own would leave a reference
				pointing at nothing. The library already associates the message with the control.
			-->
			<NcTextArea
				v-model="explanation"
				:label="t('pettycash', 'reviews.explanation')"
				:required="requiresExplanation"
				:maxlength="500"
				:rows="3"
				:error="!!explanationError"
				:helper-text="explanationError"
				data-testid="explanation-input"
				class="decision-form__field" />

			<div class="decision-form__actions">
				<NcButton
					variant="tertiary"
					type="button"
					data-testid="cancel-btn"
					@click="emit('cancel')">
					{{ t('pettycash', 'common.cancel') }}
				</NcButton>
				<NcButton
					:disabled="!selectedDecision || !!decisionError || !!explanationError || submitting"
					type="submit"
					data-testid="submit-decision">
					<NcLoadingIcon v-if="submitting" :size="20" />
					{{ submitting ? t('pettycash', 'common.submitting') : t('pettycash', 'common.submit') }}
				</NcButton>
			</div>
		</form>
	</div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@nextcloud/l10n'
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextArea from '@nextcloud/vue/components/NcTextArea'
import { useReviewsStore } from '@/stores/reviews'
import { selectOptionKey, selectOptionValue } from '@/utils/selectOption'

type Decision = 'APPROVE' | 'REJECT' | 'RETURN'

interface Props {
	stage: string
	transactionId: string
	allowedActions: Record<string, boolean>
	allowIndependentDecision?: boolean
	expectedVersion?: number
}

const props = defineProps<Props>()
const emit = defineEmits<{
	(e: 'submit-decision', payload: { decision: string; explanation: string; commandId: string }): void
	(e: 'cancel'): void
}>()

const reviewsStore = useReviewsStore()

const selectedDecision = ref<Decision | ''>('')
const explanation = ref('')
const submitting = ref(false)

const decisionItems = computed(() => ([
	{ label: t('pettycash', 'reviews.decision.approve'), value: 'APPROVE' as Decision },
	{ label: t('pettycash', 'reviews.decision.reject'), value: 'REJECT' as Decision },
	{ label: t('pettycash', 'reviews.decision.return'), value: 'RETURN' as Decision },
].filter(item => props.allowedActions?.[item.value.toLowerCase()] !== false)))

const requiresExplanation = computed(() => selectedDecision.value === 'REJECT' || selectedDecision.value === 'RETURN')

const decisionError = computed(() => {
	if (selectedDecision.value === '') {
		return t('pettycash', 'validation.required')
	}
	return ''
})

const explanationError = computed(() => {
	if (requiresExplanation.value && explanation.value.trim() === '') {
		return t('pettycash', 'reviews.explanationRequired')
	}
	return ''
})

watch(selectedDecision, () => {
	explanation.value = ''
})

async function submitDecision() {
	if (!selectedDecision.value || decisionError.value || explanationError.value || submitting.value) {
		return
	}

	submitting.value = true
	try {
		const commandId = crypto.randomUUID()
		await reviewsStore.recordDecision(props.transactionId, {
			decision: selectedDecision.value,
			explanation: explanation.value.trim(),
			expectedVersion: props.expectedVersion ?? 0,
		})
		emit('submit-decision', {
			decision: selectedDecision.value,
			explanation: explanation.value.trim(),
			commandId,
		})
	} catch (error) {
		console.error('Failed to record decision:', error)
	} finally {
		submitting.value = false
	}
}
</script>

<style scoped>
.decision-form__title {
	margin-block-end: 16px;
}

.decision-form__body {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.decision-form__field {
	width: 100%;
}

.decision-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

@media (width <= 390px) {
	.decision-form__actions {
		flex-direction: column-reverse;
	}

	.decision-form__actions :deep(.button-vue) {
		width: 100%;
	}
}
</style>
