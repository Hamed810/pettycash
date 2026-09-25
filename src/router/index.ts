import { createRouter, createWebHashHistory } from 'vue-router'

const DashboardView = () => import('../views/DashboardView.vue')
const SheetListView = () => import('../views/sheets/SheetListView.vue')
const SheetDetailView = () => import('../views/sheets/SheetDetailView.vue')
const SheetFormView = () => import('../views/sheets/SheetFormView.vue')
const TransactionListView = () => import('../views/transactions/TransactionListView.vue')
const TransactionFormView = () => import('../views/transactions/TransactionFormView.vue')
const TransactionDetailView = () => import('../views/transactions/TransactionDetailView.vue')
const CostCenterQueueView = () => import('../views/reviews/CostCenterQueueView.vue')
const SeniorQueueView = () => import('../views/reviews/SeniorQueueView.vue')
const FinanceQueueView = () => import('../views/reviews/FinanceQueueView.vue')
const TransactionReviewDetailView = () => import('../views/reviews/TransactionReviewDetailView.vue')
const ReturnsQueueView = () => import('../views/reviews/ReturnsQueueView.vue')

export default createRouter({
	history: createWebHashHistory(),
	routes: [
		{ path: '/', name: 'dashboard', component: DashboardView },
		{ path: '/sheets', name: 'sheets', component: SheetListView },
		{ path: '/sheets/create', name: 'sheet-create', component: SheetFormView },
		{ path: '/sheets/:id', name: 'sheet-detail', component: SheetDetailView },
		{ path: '/sheets/:id/edit', name: 'sheet-edit', component: SheetFormView },
		{ path: '/sheets/:sheetId/transactions', name: 'transactions', component: TransactionListView },
		{ path: '/sheets/:sheetId/transactions/create', name: 'transaction-create', component: TransactionFormView },
		{ path: '/transactions/:id', name: 'transaction-detail', component: TransactionDetailView },
		{ path: '/transactions/:id/edit', name: 'transaction-edit', component: TransactionFormView },

		// Review queues
		{ path: '/reviews/cost-center', name: 'review-queue-cost-center', component: CostCenterQueueView },
		{ path: '/reviews/senior', name: 'review-queue-senior', component: SeniorQueueView },
		{ path: '/reviews/finance', name: 'review-queue-finance', component: FinanceQueueView },
		{ path: '/reviews/returns', name: 'review-queue-returns', component: ReturnsQueueView },
		{ path: '/reviews/:stage/:transactionId', name: 'review-detail', component: TransactionReviewDetailView, props: true },
	]
})