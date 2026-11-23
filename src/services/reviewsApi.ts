import { ocs, mutate } from './http'
import type {
	Page,
	TransactionSummary,
	TransactionDetail,
	DecisionWrite,
	DecisionResult,
	CommandOptions,
	ManagerSheetReview,
	SeniorManagerSheetReview,
	SeniorSheetListRow,
} from '@/types/api'

export interface QueueParams {
  stage: 'cost-center' | 'senior' | 'finance'
  page?: number
  pageSize?: number
}

export interface ReturnsParams {
  page?: number
  pageSize?: number
}

export type TransactionPage = Page<TransactionSummary>

export async function fetchQueue(params: QueueParams): Promise<TransactionPage> {
  return ocs<TransactionPage>(`/queues/${params.stage}`, {
    params: {
      page: params.page ?? 1,
      pageSize: params.pageSize ?? 25
    }
  })
}

export interface SeniorSheetsParams {
  page?: number
  pageSize?: number
}

export type SeniorSheetListPage = Page<SeniorSheetListRow>

/**
 * The largest page the contract allows (`PageSize` maximum 100). The all-pages loop uses it so the
 * number of round trips stays bounded while still covering every sheet in the senior's scope.
 */
export const SENIOR_SHEET_PAGE_SIZE = 100

/**
 * One page of `GET /queues/senior/sheets` — the senior-scoped Cost Sheet list (UX-007-07).
 *
 * This is the additive, transaction-derived sheet list that *retains completed sheets*, replacing
 * the old transaction-based `/queues/senior` as the Senior queue's data source. It is paginated
 * like the transaction queues (`items`/`total`/`page`/`pageSize`); `total` counts the sheets in
 * scope, not the page.
 */
export async function fetchSeniorSheets(params: SeniorSheetsParams = {}): Promise<SeniorSheetListPage> {
  return ocs<SeniorSheetListPage>('/queues/senior/sheets', {
    params: {
      page: params.page ?? 1,
      pageSize: params.pageSize ?? 25
    }
  })
}

/**
 * Bounded cap on the all-pages loop: at `pageSize = 100` this covers 10 000 sheets, far beyond any
 * real senior scope. The accumulated rows are still returned so the completeness guard can render
 * its honest unavailable state instead of the loop running forever against a misbehaving server.
 */
const MAX_SENIOR_SHEET_PAGES = 100

/**
 * Fetch every page of the senior sheet list so completed sheets on later pages are never silently
 * truncated (UX-007-07; the backend orders deterministically so pages are disjoint). The loop stops
 * once the accumulated rows cover the server's `total`, or when the server returns a short page;
 * the returned page always carries the server's own `total` so the completeness guard can tell an
 * honest "we got everything" from a truncated fetch.
 */
export async function fetchAllSeniorSheets(pageSize = SENIOR_SHEET_PAGE_SIZE): Promise<SeniorSheetListPage> {
  const items: SeniorSheetListRow[] = []
  let page = 1
  let total = 0
  for (;;) {
    const res = await fetchSeniorSheets({ page, pageSize })
    items.push(...res.items)
    total = res.total
    if (items.length >= total) break
    if (res.items.length < pageSize) break
    if (page >= MAX_SENIOR_SHEET_PAGES) break
    page += 1
  }
  return { items, page: 1, pageSize, total }
}

/**
 * The largest single page the Finance queue requests before it stops claiming completeness.
 *
 * `GET /queues/finance` returns a paginated flat transaction collection. A Finance manager may be
 * reachable through more than one assignment, so the same transaction can appear across pages;
 * de-duplication happens client-side after the complete set is proven (see `useFinanceQueue.ts`).
 */
export const FINANCE_SHEET_PAGE_SIZE = 100

/** Bounded cap on the all-pages loop: at `pageSize = 100` this covers 10 000 transactions. */
const MAX_FINANCE_SHEET_PAGES = 100

/**
 * Fetch every page of `GET /queues/finance` so no finance-eligible transaction is silently
 * truncated on a later page. The loop stops once the accumulated rows cover the server's `total`,
 * or when the server returns a short page, or when the page cap is hit; the returned page always
 * carries the server's own `total` so the completeness guard can tell an honest "we got
 * everything" from a truncated fetch.
 */
export async function fetchAllFinanceQueues(pageSize = FINANCE_SHEET_PAGE_SIZE): Promise<TransactionPage> {
  const items: TransactionSummary[] = []
  let page = 1
  let total = 0
  for (;;) {
    const res = await fetchQueue({ stage: 'finance', page, pageSize })
    items.push(...res.items)
    total = res.total
    if (items.length >= total) break
    if (res.items.length < pageSize) break
    if (page >= MAX_FINANCE_SHEET_PAGES) break
    page += 1
  }
  return { items, page: 1, pageSize, total }
}

/**
 * One sheet as the acting Cost Center manager may see it (Batch 1, B001–B004).
 *
 * This is the manager-scoped read, not the owner-only `GET /sheets/{sheetId}`. It supplies the
 * genuine title, purchaser display name, first-submission date and this manager's own transaction
 * states/progress. The id is a public uuid; encoding it keeps a malformed value from changing the
 * path shape before the server's scope check can answer one 404 for unknown and out-of-scope sheets.
 */
export async function fetchCostCenterSheet(sheetId: string): Promise<ManagerSheetReview> {
  return ocs<ManagerSheetReview>(`/queues/cost-center/sheets/${encodeURIComponent(sheetId)}`)
}

/**
 * One sheet as the acting Senior manager may see it (Feature 007, additive, `[BD←BE1]`).
 *
 * Mirrors `fetchCostCenterSheet` for the Senior stage. The endpoint is additive and backend-required:
 * until it lands, the route answers a `404`, which the reviews store classifies as an *unavailable*
 * senior sheet read — the modal then renders unavailable identity fields (FR-007-014) rather than a
 * fabricated sheet, and never a "Waiting for Cost Center Review" state. The id is a public uuid;
 * encoding it keeps a malformed value from changing the path shape before the server's scope check
 * can answer one `404` for unknown and out-of-scope sheets (FR-007-056).
 */
export async function fetchSeniorSheet(sheetId: string): Promise<SeniorManagerSheetReview> {
  return ocs<SeniorManagerSheetReview>(`/queues/senior/sheets/${encodeURIComponent(sheetId)}`)
}

export async function fetchReturnsQueue(params: ReturnsParams = {}): Promise<TransactionPage> {
  return ocs<TransactionPage>('/returns', {
    params: {
      page: params.page ?? 1,
      pageSize: params.pageSize ?? 25
    }
  })
}

export async function fetchTransactionForReview(transactionId: string): Promise<TransactionDetail> {
  return ocs<TransactionDetail>(`/transactions/${transactionId}`)
}

export async function recordDecision(
  transactionId: string,
  payload: DecisionWrite,
  options: CommandOptions = {}
): Promise<DecisionResult> {
  return mutate<DecisionResult>(
    `/transactions/${transactionId}/decisions`,
    'POST',
    payload,
    options.commandId
  )
}