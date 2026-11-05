import { ocs, mutate } from './http'
import type { Page, TransactionSummary, TransactionDetail, DecisionWrite, DecisionResult, CommandOptions, ManagerSheetReview } from '@/types/api'

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