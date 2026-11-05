import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { TransactionDetail, DecisionWrite, DecisionResult, CommandOptions, ManagerSheetReview, ManagerTransactionReadState } from '@/types/api'
import { ApiError } from '@/types/api'
import * as reviewsApi from '@/services/reviewsApi'
import type { TransactionPage } from '@/services/reviewsApi'

export const useReviewsStore = defineStore('reviews', () => {
  const costCenterQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const seniorQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const financeQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const returnsQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const selectedTransaction = ref<TransactionDetail | null>(null)
  /**
   * Manager-scoped sheet reads, keyed by public sheet id.
   *
   * Kept separately from the queue page because it is a different contract with a different
   * authorization boundary: the queue is a proven-complete pending set, while this map carries the
   * real sheet header and this manager's own awaiting/decided/returned states. A failed read leaves
   * the entry absent; the queue then shows its honest fallback rather than inventing fields.
   */
  const managerSheetReviews = ref<Record<string, ManagerSheetReview>>({})
  /**
   * T026: guarded per-transaction detail reads, keyed by public transaction id.
   *
   * The expanded quick-review panel needs `allowedActions`, `version` and the evidence set that the
   * sheet read does not carry, so each card's decisions and thumbnails read from here instead of
   * guessing from the summary. Kept beside the sheet reviews for the same reason: a failed read is
   * supporting data for one card, and must not blank the queue or flip its global error state.
   */
  const managerTransactionDetails = ref<Record<string, TransactionDetail>>({})
  /**
   * How the last read of each transaction ended. `forbidden` (the server refused) and `notFound`
   * (no such transaction in scope) stay distinct so the drawer can render a refusal as a refusal,
   * and `unavailable` covers every other failure without inventing which one it was.
   */
  const managerTransactionReads = ref<Record<string, ManagerTransactionReadState>>({})
  const loading = ref(false)
  const error = ref<string | null>(null)

  const hasCostCenterQueue = computed(() => costCenterQueue.value.items.length > 0)
  const hasSeniorQueue = computed(() => seniorQueue.value.items.length > 0)
  const hasFinanceQueue = computed(() => financeQueue.value.items.length > 0)
  const hasReturnsQueue = computed(() => returnsQueue.value.items.length > 0)

  async function fetchCostCenterQueue(params: reviewsApi.QueueParams = { stage: 'cost-center' }) {
    loading.value = true
    error.value = null
    try {
      costCenterQueue.value = await reviewsApi.fetchQueue(params)
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch cost center queue'
      throw e
    } finally {
      loading.value = false
    }
  }

  /**
   * Reads one manager-scoped sheet without touching the queue's global loading/error state.
   *
   * A sheet header is supporting data for the queue, not a page-level load: setting `loading` here
   * would blank the already-rendered queue while the details arrive, and writing `error` would turn
   * one unavailable sheet into a whole-queue failure. Callers render the absent entry as unavailable.
   */
  async function fetchManagerSheetReview(sheetId: string, force = false): Promise<ManagerSheetReview> {
    if (!force && managerSheetReviews.value[sheetId]) {
      return managerSheetReviews.value[sheetId] as ManagerSheetReview
    }

    const review = await reviewsApi.fetchCostCenterSheet(sheetId)
    managerSheetReviews.value = { ...managerSheetReviews.value, [sheetId]: review }
    return review
  }

  function clearManagerSheetReviews() {
    managerSheetReviews.value = {}
  }

  /**
   * T026: one transaction's detail for the expanded quick-review panel.
   *
   * Reads the existing `GET /transactions/{id}` surface — no new route — but through the guarded
   * channel: no global loading/error write (the panel keeps rendering its other cards), a cached
   * answer reused unless `force`, and the outcome classified into the read-state map so refusal,
   * absence and failure stay distinguishable downstream.
   */
  async function fetchManagerTransactionDetail(transactionId: string, force = false): Promise<TransactionDetail | null> {
    if (!force && managerTransactionDetails.value[transactionId]) {
      return managerTransactionDetails.value[transactionId]
    }

    setManagerTransactionReadState(transactionId, 'loading')
    try {
      const detail = await reviewsApi.fetchTransactionForReview(transactionId)
      managerTransactionDetails.value = { ...managerTransactionDetails.value, [transactionId]: detail }
      setManagerTransactionReadState(transactionId, 'loaded')
      return detail
    } catch (e: unknown) {
      setManagerTransactionReadState(transactionId, e instanceof ApiError && e.status === 403 ? 'forbidden' : e instanceof ApiError && e.status === 404 ? 'notFound' : 'unavailable')
      return null
    }
  }

  function setManagerTransactionReadState(transactionId: string, state: ManagerTransactionReadState): void {
    managerTransactionReads.value = { ...managerTransactionReads.value, [transactionId]: state }
  }

  /**
   * Drops one cached detail read.
   *
   * A recorded decision changes the transaction's version, state and decision history, so a detail
   * held from before the decision is stale. The entry is removed rather than patched with the
   * decision result: the result's `TransactionDetail` carries the server-side view of the
   * transaction but not this manager's `managerState`, and merging the two here would be a second,
   * client-side definition of the state the Batch 1 read owns.
   */
  function clearManagerTransactionDetail(transactionId: string): void {
    if (!(transactionId in managerTransactionDetails.value)) return
    const next = { ...managerTransactionDetails.value }
    delete next[transactionId]
    managerTransactionDetails.value = next
  }

  async function fetchSeniorQueue(params: reviewsApi.QueueParams = { stage: 'senior' }) {
    loading.value = true
    error.value = null
    try {
      seniorQueue.value = await reviewsApi.fetchQueue(params)
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch senior queue'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchFinanceQueue(params: reviewsApi.QueueParams = { stage: 'finance' }) {
    loading.value = true
    error.value = null
    try {
      financeQueue.value = await reviewsApi.fetchQueue(params)
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch finance queue'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchReturnsQueue(params: reviewsApi.ReturnsParams = {}) {
    loading.value = true
    error.value = null
    try {
      returnsQueue.value = await reviewsApi.fetchReturnsQueue(params)
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch returns queue'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function fetchTransactionForReview(transactionId: string) {
    loading.value = true
    error.value = null
    try {
      selectedTransaction.value = await reviewsApi.fetchTransactionForReview(transactionId)
      return selectedTransaction.value
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to fetch transaction'
      throw e
    } finally {
      loading.value = false
    }
  }

  async function recordDecision(transactionId: string, payload: DecisionWrite, options: CommandOptions = {}): Promise<DecisionResult> {
    loading.value = true
    error.value = null
    try {
      const result = await reviewsApi.recordDecision(transactionId, payload, options)
      if (selectedTransaction.value && selectedTransaction.value.id === transactionId) {
        selectedTransaction.value = result.transaction
      }
      return result
    } catch (e: unknown) {
      error.value = e instanceof Error ? e.message : 'Failed to record decision'
      throw e
    } finally {
      loading.value = false
    }
  }

  function clearSelectedTransaction() {
    selectedTransaction.value = null
  }

  function clearError() {
    error.value = null
  }

  return {
    costCenterQueue,
    seniorQueue,
    financeQueue,
    returnsQueue,
    selectedTransaction,
    managerSheetReviews,
    managerTransactionDetails,
    managerTransactionReads,
    loading,
    error,
    hasCostCenterQueue,
    hasSeniorQueue,
    hasFinanceQueue,
    hasReturnsQueue,
    fetchCostCenterQueue,
    fetchManagerSheetReview,
    clearManagerSheetReviews,
    fetchManagerTransactionDetail,
    clearManagerTransactionDetail,
    fetchSeniorQueue,
    fetchFinanceQueue,
    fetchReturnsQueue,
    fetchTransactionForReview,
    recordDecision,
    clearSelectedTransaction,
    clearError
  }
})