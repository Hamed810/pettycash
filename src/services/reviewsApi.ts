import { ocs, mutate } from './http'
import type { Page, TransactionSummary, TransactionDetail, DecisionWrite, DecisionResult } from '@/types/api'

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
  payload: DecisionWrite
): Promise<DecisionResult> {
  return mutate<DecisionResult>(
    `/transactions/${transactionId}/decisions`,
    'POST',
    payload
  )
}