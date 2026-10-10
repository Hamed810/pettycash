import { defineComponent, h } from 'vue'
import { createRouter, createWebHashHistory, type Router, type RouteRecordRaw } from 'vue-router'

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
const FinanceReviewView = () => import('../views/reviews/FinanceReviewView.vue')
const AccountView = () => import('../views/accounts/AccountView.vue')
// US5 administration (T088). Two views rather than one: reference data (currencies, cost centers,
// expense types, finance assignments) and funding (open, charge, retire/restore) are different
// journeys with different server surfaces, and one combined form would be a page that does everything
// badly. Reachable by URL; there is no navigation shell in this app to extend.
const ConfigurationView = () => import('../views/admin/ConfigurationView.vue')
const FundingView = () => import('../views/admin/FundingView.vue')
// US6 history (T098). The formal per-transaction audit timeline plus revisions
// and evidence state. Reachable by URL and linked from the transaction detail;
// like the admin views there is no navigation shell to extend.
const AuditView = () => import('../views/audit/AuditView.vue')

/**
 * A route that renders nothing, for a path this app has no view for.
 *
 * Registered deliberately rather than left to fall through: a mistyped hash in the address bar used
 * to produce a blank page with no explanation, which reads as a bug. A named placeholder turns it
 * into a sentence saying the page does not exist.
 */
const NotFoundView = defineComponent({
	name: 'NotFoundView',
	setup() {
		return () =>
			h('div', { dir: 'rtl', 'data-testid': 'route-not-found' }, 'صفحه مورد نظر یافت نشد')
	}
})

/**
 * The single route table.
 *
 * Exported so a test can build a router with the same names and parameters the application uses.
 * A view links by route name — `RouterLink :to="{ name: 'dashboard' }"` and
 * `router.push({ name: `review-queue-${stage}` }` ) — so a test that declared its own route names
 * would resolve nothing and fail inside `vue-router` rather than on the view's own behaviour.
 */
export const routes: RouteRecordRaw[] = [
	{ path: '/', name: 'dashboard', component: DashboardView },
	{ path: '/sheets', name: 'sheets', component: SheetListView },
	{ path: '/sheets/create', name: 'sheet-create', component: SheetFormView },
	{ path: '/sheets/:id', name: 'sheet-detail', component: SheetDetailView },
	{ path: '/sheets/:id/edit', name: 'sheet-edit', component: SheetFormView },
	{ path: '/sheets/:sheetId/transactions', name: 'transactions', component: TransactionListView },
	{ path: '/sheets/:sheetId/transactions/create', name: 'transaction-create', component: TransactionFormView },
	{ path: '/transactions/:id', name: 'transaction-detail', component: TransactionDetailView },
	{ path: '/transactions/:id/edit', name: 'transaction-edit', component: TransactionFormView },
	{ path: '/audit/:transactionId', name: 'transaction-audit', component: AuditView },

	// Review queues
	{ path: '/reviews/cost-center', name: 'review-queue-cost-center', component: CostCenterQueueView },
	{ path: '/reviews/senior', name: 'review-queue-senior', component: SeniorQueueView },
	{ path: '/reviews/finance', name: 'review-queue-finance', component: FinanceQueueView },
	{ path: '/reviews/returns', name: 'review-queue-returns', component: ReturnsQueueView },
	{ path: '/reviews/:stage/:transactionId', name: 'review-detail', component: TransactionReviewDetailView, props: true },

	// Finance review (US3, T065).
	//
	// This path **overlaps** `/reviews/:stage/:transactionId`: both match
	// `/reviews/finance/tx-1`. `vue-router` ranks a static segment above a parameter, so the finance
	// row resolves here and the generic detail view never sees a finance transaction — the ranking is
	// by score, not by declaration order, so moving this line would not change that. It is recorded
	// because the overlap is invisible at the call site, and because a finance row that landed on the
	// generic view would lose exactly the settled-versus-management labelling T065 exists to add.
	{ path: '/reviews/finance/:transactionId', name: 'finance-review', component: FinanceReviewView, props: true },

	// Purchaser balance and ledger (US3, T065).
	{ path: '/accounts', name: 'accounts', component: AccountView },

	// Administration (US5, T088). No navigation shell exists to extend, so these are reached by URL;
	// the server still enforces every role the interface does not show.
	{ path: '/admin/configuration', name: 'admin-configuration', component: ConfigurationView },
	{ path: '/admin/funding', name: 'admin-funding', component: FundingView },

	{ path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundView }
]

export function createAppRouter(): Router {
	return createRouter({ history: createWebHashHistory(), routes })
}

export default createAppRouter()