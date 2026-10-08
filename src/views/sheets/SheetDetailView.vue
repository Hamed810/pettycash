<script setup lang="ts">
import { onMounted, computed, h, watch, type VNode } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useSheetStore } from '@/stores/sheets'
import type { SheetStatus, TransactionSummary } from '@/types/api'
import { NcBreadcrumbs, NcNoteCard, NcButton, NcChip, NcIconSvgWrapper } from '@nextcloud/vue'
import { mdiPencil, mdiPlus, mdiSend, mdiEye, mdiLock } from '@mdi/js'
import { t } from '@nextcloud/l10n'
import { formatMoney } from '@/utils/money'
import ResponsiveTable from '@/components/shared/ResponsiveTable.vue'
import { formatSheetDate as formatDate, formatSheetDateRange as formatDateRange } from '@/utils/date'

const route = useRoute()
const router = useRouter()
const sheetStore = useSheetStore()

const sheetId = computed(() => route.params.id as string)

const statusLabels: Record<SheetStatus, string> = {
	OPEN: 'pettycash.status.open',
	AWAITING_COST_CENTER_REVIEW: 'pettycash.status.awaitingCostCenter',
	AWAITING_SENIOR_REVIEW: 'pettycash.status.awaitingSenior',
	AWAITING_FINANCE_REVIEW: 'pettycash.status.awaitingFinance',
	COMPLETED: 'pettycash.status.completed',
	COMPLETED_WITH_REJECTIONS: 'pettycash.status.completedWithRejections'
}

const isSubmitted = computed(() => sheetStore.currentSheet?.status !== 'OPEN')

/**
 * The sheet's transactions, from the payload the contract defines for exactly this.
 *
 * `contracts/ocs-api.yaml:1125` makes `transactions` a member of `SheetDetail`, and `canSubmit`
 * below is derived from it, so this array is the single source for both the table and the Submit
 * button. Reading it as `?? []` keeps a sheet that has not loaded yet rendering as an empty table
 * rather than throwing.
 */
const sheetTransactions = computed(() => sheetStore.currentSheet?.transactions ?? [])

function goToEdit() { router.push({ name: 'sheet-edit', params: { id: sheetId.value } }) }
function goToCreateTransaction() { router.push({ name: 'transaction-create', params: { sheetId: sheetId.value } }) }
function goToTransaction(transactionId: string) { router.push({ name: 'transaction-detail', params: { id: transactionId } }) }

async function handleSubmit() {
	if (!sheetStore.currentSheet) return
	// The submission response is itself a `SheetDetail`, so it carries the routed transactions and a
	// fresh `allowedActions`; there is no second request to make and no separate list to reload.
	await sheetStore.submitCurrentSheet(sheetId.value, sheetStore.currentSheet.version)
}

/**
 * Loads the sheet, which carries its transactions with it.
 *
 * `contracts/ocs-api.yaml` defines `SheetDetail` as `SheetSummary` plus `transactions` and
 * `allowedActions`, and this view previously issued a second request for the same rows against
 * `GET /sheets/{sheetId}/transactions` — a path no route serves, so it answered `405` with an empty
 * body and the table below rendered no rows even after a transaction had been saved. One request now
 * fills both.
 *
 * The store records a failure in its own `error` ref, which this view renders in its alert. These
 * loads are fired from `onMounted` and from a `watch`, neither of which has a caller to react to a
 * rejection, so it is absorbed here rather than surfacing as an unhandled promise rejection in the
 * user's console.
 */
function loadSheet(id: string) {
	sheetStore.loadSheet(id).catch(() => undefined)
}

onMounted(() => { loadSheet(sheetId.value) })

watch(sheetId, (newId) => { loadSheet(newId) })

const transactionHeaders = [
	{ key: 'expenseDate', label: 'pettycash.transactions.expenseDate', sortable: true },
	{ key: 'amount', label: 'pettycash.transactions.amount', sortable: true },
	{ key: 'costCenter', label: 'pettycash.transactions.costCenter', sortable: true },
	{ key: 'expenseType', label: 'pettycash.transactions.expenseType', sortable: true },
	{ key: 'stage', label: 'pettycash.transactions.stage', sortable: true },
	{ key: 'status', label: 'pettycash.transactions.status', sortable: true },
	{ key: 'evidenceCount', label: 'pettycash.transactions.evidenceCount', sortable: false },
	{ key: 'actions', label: 'pettycash.transactions.actions', sortable: false }
]

/**
 * T389: the transaction table's columns, as `ResponsiveTable` takes them.
 *
 * **Derived from {@link transactionHeaders} rather than restated.** The header list already names every
 * column; a second literal would be two statements of one rule, and the failure mode is a column that
 * appears in the table but not in the card — a narrow layout quietly missing a field, which is the defect
 * the reflow exists to close. `sortable` is dropped because `ResponsiveTable` does not sort: it is metadata
 * about an interactive header, and this view's headers are not interactive.
 */
const transactionColumns = computed(() =>
	transactionHeaders.map((header) => ({
		key: header.key,
		label: t('pettycash', header.label)
	}))
)

/** Stable per-row identity, so Vue keys rather than array indices. */
function transactionRowKey(item: TransactionSummary, index: number): string {
	return item.id || `sheet-transaction-${index}`
}

/**
 * The card's own row hook, deliberately off the `sheet-transaction-row` prefix.
 *
 * A prefix selector such as `[data-test^="sheet-transaction-row"]` would otherwise match the card for the
 * same record and double every row count. The exact selector the existing suites use is unaffected either
 * way; this only keeps a prefix meaningful for whoever writes the next assertion.
 */
function sheetTransactionCardId(item: TransactionSummary): string {
	return `sheet-transaction-card-${item.id}`
}

/**
 * One transaction cell, rendered identically by the table and by the narrow-viewport card.
 *
 * A render function rather than markup because FR-071 requires the same figures at 390px as on a desktop,
 * and duplicated markup is how the narrow layout becomes a second, divergent presentation of the same money
 * and dates.
 *
 * The `default` branch renders the column's key rather than nothing, so a column added to
 * {@link transactionHeaders} without a case here is visible in both layouts and assertable instead of
 * silently blank.
 */
function transactionCell(item: TransactionSummary, column: { key: string }): VNode {
	switch (column.key) {
	case 'expenseDate':
		// T393: through `@/utils/date`, so an absent date is a dash rather than 1 January 1970.
		return h('span', { 'data-test': 'transaction-expense-date' }, formatDate(item.activeRevision.expenseDate))
	case 'amount':
		return h('span', { 'data-test': 'transaction-amount' }, formatMoney(item.activeRevision.amount))
	case 'costCenter':
		return h('span', null, item.activeRevision.costCenter.name)
	case 'expenseType':
		return h('span', null, item.activeRevision.expenseType.name)
	case 'stage':
		return h(NcChip, null, () => t('pettycash', 'stage.' + item.stage.toLowerCase()))
	case 'status':
		// The hook is `data-test`, matching the table this replaces, so every existing assertion that
		// found the status badge keeps finding it.
		return h(
			NcChip,
			{ 'data-test': 'transaction-status-badge' },
			() => t('pettycash', 'status.' + item.status.toLowerCase().replace(/_/g, '-'))
		)
	case 'evidenceCount':
		return h('span', { 'data-test': 'evidence-count' }, item.evidenceCount)
	case 'actions':
		return h(
			NcButton,
			{ variant: 'tertiary', 'data-test': 'view-transaction', onClick: () => goToTransaction(item.id) },
			() => h(NcIconSvgWrapper, { path: mdiEye, size: 16 })
		)
	default:
		return h('span', { 'data-test': `transaction-unrendered-${column.key}` }, column.key)
	}
}
</script>

<template>
	<div class="sheet-detail-view" dir="rtl" data-test="sheet-detail-view">
		<div class="mb-4">
			<NcBreadcrumbs
				:items="[
					{ text: t('pettycash', 'sheets.title'), href: '/sheets' },
					{ text: sheetStore.currentSheet?.title ?? '' }
				]"
			/>
		</div>

		<div class="header-row">
			<div>
				<NcChip


					data-test="sheet-status"
				>
					{{ t('pettycash', statusLabels[sheetStore.currentSheet?.status ?? 'OPEN']) }}
				</NcChip>
				<NcChip
					v-if="isSubmitted"
					variant="secondary"


					data-test="read-only-badge"
				>
					<NcIconSvgWrapper :path="mdiLock" :size="16" class="mr-2" />
					{{ t('pettycash', 'common.readOnly') }}
				</NcChip>
			</div>
		</div>

		<NcNoteCard data-test="sheet-header">
			<!--
				The card's heading row lives in the default slot, not in a `#header` slot.

				`@nextcloud/vue` 9.12.0's `NcNoteCard` renders `heading` (a string prop) and `default`;
				**it has no `header` slot**. A `<template #header>` is therefore dropped without a
				warning, and everything inside it disappears from the rendered page while remaining
				present in the source — which is how this card came to have no Edit, no Add transaction
				and no Submit button at all, in the one view a purchaser has to pass through. Anything
				richer than a string therefore has to be a normal child of the card, and the app's
				existing `.card-header-row` gives it the same shape the dropped slot intended.
			-->
			<div class="card-header-row">
				<h2 class="mb-0" data-test="sheet-title">{{ sheetStore.currentSheet?.title }}</h2>
				<div class="d-flex gap-2">
					<NcButton v-if="!isSubmitted" variant="secondary" data-test="edit-sheet-btn" @click="goToEdit">
						<NcIconSvgWrapper :path="mdiPencil" :size="16" class="mr-2" />
						{{ t('pettycash', 'common.edit') }}
					</NcButton>
					<NcButton v-if="!isSubmitted" variant="primary" data-test="create-transaction-btn" @click="goToCreateTransaction">
						<NcIconSvgWrapper :path="mdiPlus" :size="16" class="mr-2" />
						{{ t('pettycash', 'transactions.add') }}
					</NcButton>
					<NcButton v-if="sheetStore.canSubmit" variant="primary" data-test="submit-sheet-button" @click="handleSubmit">
						<NcIconSvgWrapper :path="mdiSend" :size="16" class="mr-2" />
						{{ t('pettycash', 'sheets.submit') }}
					</NcButton>
				</div>
			</div>

			<div class="card-details">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.currency') }}</strong>
					<div data-test="sheet-currency">{{ sheetStore.currentSheet?.currency }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.owner') }}</strong>
					<!--
						`data-testid` because this row had none, and a rendered value with no hook is a
						value nothing can assert: the Owner row rendered blank for every sheet in the
						application while `ownerDisplayName` was a literal `''`, and a shape check passed on
						`''` (T340).
					-->
					<div data-testid="sheet-owner">{{ sheetStore.currentSheet?.ownerDisplayName }}</div>
				</div>
			</div>

			<!--
				FR-063's "key dates", which this view rendered **nowhere** before T384: `createdAt`,
				`submittedAt`, `completedAt` and the `firstExpenseDate`/`lastExpenseDate` range were all
				absent from the whole component, and its only date was a *transaction's*
				`activeRevision.expenseDate` inside the transaction table. T381 delivered the same four for
				the sheet **list**, so this is the second half of a requirement about two surfaces.

				**Inside the existing `sheet-header` card rather than a card of its own.** `.card-details` is
				a grid of one column below 600px and three above, so four more items grow the card vertically
				and reflow on a narrow viewport — FR-071 requires everything visible without horizontal
				scroll at 390px, and a separate card with its own heading would add width for something that
				reads as part of the sheet's identity.

				**One shared renderer for both surfaces.** `formatSheetDate` / `formatSheetDateRange` live in
				`@/utils/date` rather than here, because the list already needed the identical rule and a
				second copy is how a convention drifts — a null date becoming 1970 on one surface and a dash
				on the other is precisely the kind of split this repository keeps finding.
			-->
			<div class="card-details key-dates" data-test="sheet-key-dates">
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.createdAt') }}</strong>
					<div data-test="sheet-created-at">{{ formatDate(sheetStore.currentSheet?.createdAt) }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.submittedAt') }}</strong>
					<div data-test="sheet-submitted-at">{{ formatDate(sheetStore.currentSheet?.submittedAt) }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.completedAt') }}</strong>
					<div data-test="sheet-completed-at">{{ formatDate(sheetStore.currentSheet?.completedAt) }}</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.activeRevision') }}</strong>
					<!--
						FR-063's latest active revision number (T385), the same value the list shows. `0` for a
						sheet holding no transactions is a real answer, so it is printed rather than replaced with
						the absent-marker the dates use.
					-->
					<div data-test="sheet-active-revision">
						{{ sheetStore.currentSheet?.activeRevisionNumber ?? 0 }}
					</div>
				</div>
				<div class="detail-item">
					<strong>{{ t('pettycash', 'sheets.expenseRange') }}</strong>
					<!--
						Read through a `{}` default so the cell renders the absent-marker rather than throwing
						while `currentSheet` is still null on a slow load. Both bounds missing reads as absent,
						which is the same answer an unloaded sheet deserves.
					-->
					<div data-test="sheet-expense-range">{{ formatDateRange(sheetStore.currentSheet ?? {}) }}</div>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard :heading="t('pettycash', 'sheets.totals')" data-test="sheet-totals">
			<div class="totals-grid">
				<div class="total-chip" data-test="current-total">
					<span class="total-label">{{ t('pettycash', 'sheets.currentTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.current) }}</span>
				</div>
				<div class="total-chip total-chip--info" data-test="submitted-total">
					<span class="total-label">{{ t('pettycash', 'sheets.submittedTotal') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.submitted) }}</span>
				</div>
				<!-- FR-062 asks for the stage-approved columns "alongside the latest effective outcome
				     column". The three latest-effective figures are that single column: each is summed by
				     each transaction's latest effective decision, so presenting them as one labelled
				     group is what distinguishes them from the three per-stage approved columns below. -->
				<div class="total-column" data-test="effective-outcome-total">
					<span class="total-column-label">{{ t('pettycash', 'sheets.latestEffectiveOutcome') }}</span>
					<div class="total-chip total-chip--success" data-test="effective-approved-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectiveApproved') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveApproved) }}</span>
					</div>
					<div class="total-chip total-chip--error" data-test="effective-rejected-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectiveRejected') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectiveRejected) }}</span>
					</div>
					<div class="total-chip total-chip--warning" data-test="effective-pending-total">
						<span class="total-label">{{ t('pettycash', 'sheets.effectivePending') }}</span>
						<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.effectivePending) }}</span>
					</div>
				</div>
				<div class="total-chip total-chip--primary" data-test="cost-center-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.costCenterApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.costCenterApproved) }}</span>
				</div>
				<div class="total-chip total-chip--secondary" data-test="senior-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.seniorApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.seniorApproved) }}</span>
				</div>
				<div class="total-chip total-chip--orange" data-test="finance-approved-total">
					<span class="total-label">{{ t('pettycash', 'sheets.financeApproved') }}</span>
					<span class="total-value">{{ formatMoney(sheetStore.currentSheet?.totals.financeApproved) }}</span>
				</div>
			</div>
		</NcNoteCard>

		<NcNoteCard :heading="`${t('pettycash', 'transactions.list')} (${sheetTransactions.length})`" data-test="transaction-list">
			<!--
					FR-071 (T389): this table sat in `overflow-x: auto`, which produces a horizontal scrollbar
					at 390px rather than satisfying the requirement -- exactly what `src/styles/app.scss`
					says must not be done. `ResponsiveTable` renders these same rows as a `<table>` above
					640px and as one card per row below it, from one column list and one cell renderer, so the
					narrow layout cannot become a second presentation of the same figures.
				-->
			<p v-if="sheetStore.loading" class="loading-row" data-test="transaction-loading">
				{{ t('pettycash', 'common.loading') }}
			</p>
			<ResponsiveTable
				v-else
				:columns="transactionColumns"
				:rows="sheetTransactions"
				:cell="transactionCell"
				:row-key="transactionRowKey"
				:caption="t('pettycash', 'sheets.transactions')"
				:loading="sheetStore.loading"
				test-id="transaction-table"
				test-id-prefix="sheet-transaction"
				row-test-id="sheet-transaction-row"
				:card-row-test-id="sheetTransactionCardId"
			/>
		</NcNoteCard>

		<!--
	The sheet's audit card used to live here, and it was removed by T343 rather than bound.

	Its body row was `<tr v-if="false">` — a literal, so the "no events" message could never render
	either — and no audit loader was bound in this view, so it had **no data source at all**. The
	endpoints are per-transaction (`GET /transactions/{id}/audit`, the only audit route in
	`appinfo/routes.php`); there is no sheet-scoped one, so binding this table would have meant either
	N per-transaction requests to assemble a sheet history client-side, or a new endpoint and a contract
	amendment. Both are Phase 50's subject, which owns the audit page's shape and pagination.

	**A header with a permanently empty body is worse than no card**, because it is indistinguishable
	from a sheet with no history: a reviewer looking at an empty table concludes nothing was recorded,
	when in fact nothing was ever fetched. Nothing asserted this card's presence, so no test changed
	with it.

	Phase 50 owns putting one audit surface back, once the shape is settled.
-->

		<!-- A refused submission is reported here. `role="alert"` plus `aria-live` makes it announced
		     rather than only shown, so a purchaser who has scrolled past the totals still learns why
		     nothing happened. -->
		<div
			v-if="sheetStore.error"
			class="alert alert--error submission-errors"
			role="alert"
			aria-live="assertive"
			data-test="submission-errors"
		>
			<span data-test="submission-error-message">{{ sheetStore.error }}</span>
			<button type="button" :aria-label="t('pettycash', 'common.dismiss')" @click="sheetStore.clearError">×</button>
		</div>
	</div>
</template>

<style scoped>
.sheet-detail-view {
	display: flex;
	flex-direction: column;
	gap: 1rem;
}

.header-row {
	display: flex;
	flex-wrap: wrap;
	gap: 0.5rem;
	align-items: center;
}

.card-header-row {
	display: flex;
	justify-content: space-between;
	align-items: center;
	flex-wrap: wrap;
	gap: 0.5rem;
}

.card-details {
	display: grid;
	grid-template-columns: 1fr;
	gap: 1rem;
}

@media (width >= 600px) {
	.card-details {
		grid-template-columns: repeat(3, 1fr);
	}
}

.detail-item {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
}

.total-column {
	display: flex;
	flex-direction: column;
	gap: 0.5rem;
	padding: 0.5rem;
	border: 1px solid var(--nc-color-border, #ddd);
	border-radius: 4px;
}

.total-column-label {
	font-weight: 600;
}

.totals-grid {
	display: grid;
	grid-template-columns: 1fr;
	gap: 0.75rem;
}

@media (width >= 480px) {
	.totals-grid {
		grid-template-columns: repeat(2, 1fr);
	}
}

@media (width >= 768px) {
	.totals-grid {
		grid-template-columns: repeat(4, 1fr);
	}
}

@media (width >= 1024px) {
	.totals-grid {
		grid-template-columns: repeat(8, 1fr);
	}
}

.total-chip {
	display: flex;
	flex-direction: column;
	padding: 0.75rem;
	border-radius: 4px;
	background-color: var(--nc-color-surface-variant, #f5f5f5);
	gap: 0.25rem;
}

	.total-chip--info {
		background-color: var(--nc-color-info-container, #e3f2fd);
	}

	.total-chip--success {
		background-color: var(--nc-color-success-container, #e8f5e9);
	}

	.total-chip--error {
		background-color: var(--nc-color-error-container, #fce4ec);
	}

	.total-chip--warning {
		background-color: var(--nc-color-warning-container, #fff3e0);
	}

	.total-chip--primary {
		background-color: var(--nc-color-primary-container, #e3f2fd);
	}

	.total-chip--secondary {
		background-color: var(--nc-color-secondary-container, #f3e5f5);
	}

	.total-chip--orange {
		background-color: var(--nc-color-orange-container, #ffe0b2);
	}

.total-label {
	font-size: var(--font-size-floor);
	color: var(--nc-color-on-surface-variant, #616161);
}

.total-value {
	font-size: var(--font-size-floor);
	font-weight: 600;
	color: var(--nc-color-on-surface, #212121);
}

.nc-table {
	width: 100%;
	border-collapse: collapse;
	font-size: var(--font-size-floor);
}

.nc-table th,
.nc-table td {
	padding: 0.75rem;
	text-align: start;
	border-bottom: 1px solid var(--nc-color-outline-variant, #e0e0e0);
}

.nc-table th {
	font-weight: 600;
	color: var(--nc-color-on-surface-variant, #616161);
	background-color: var(--nc-color-surface-container-lowest, #fff);
}

.nc-table tbody tr:hover {
	background-color: var(--nc-color-surface-container-low, #fafafa);
}

.loading-row {
	padding: 2rem;
	color: var(--nc-color-on-surface-variant, #616161);
}

.alert {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 0.75rem 1rem;
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

	.mr-2 {
		margin-inline-end: 0.5rem;
	}

	.d-flex {
		display: flex;
	}

	.gap-2 {
		gap: 0.5rem;
	}

	.text-center {
		text-align: center;
	}

	.py-8 {
		padding-top: 2rem; padding-bottom: 2rem;
	}

	.w-100 {
		width: 100%;
	}
</style>