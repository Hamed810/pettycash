import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type {
	TransactionDetail,
	DecisionWrite,
	DecisionResult,
	CommandOptions,
	ManagerSheetReview,
	ManagerTransactionReadState,
	SeniorManagerSheetReview,
	ExportRequest,
	ExportResult,
} from '@/types/api'
import { ApiError } from '@/types/api'
import * as reviewsApi from '@/services/reviewsApi'
import type { SeniorSheetListPage, TransactionPage } from '@/services/reviewsApi'
import { requestExport } from '@/services/reportsApi'

/**
 * T022: the additive Senior-scoped export resource, forwarded through this one guarded boundary.
 *
 * `ExportRequest.resource` stays the closed vocabulary (`sheet | sheets | transactions | report |
 * audit | evidence`) until the `senior-queue` value is ratified; this module-scoped constant is the
 * only place the cast happens, so the closed type stays intact everywhere else.
 */
const SENIOR_QUEUE_RESOURCE = 'senior-queue' as unknown as ExportRequest['resource']

export const useReviewsStore = defineStore('reviews', () => {
  const costCenterQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  /**
   * The Senior queue's row set (Feature 007, UX-007-07): pages of `GET /queues/senior/sheets`, the
   * sheet-list data source that *retains completed sheets*. Unlike the transaction queues it is not
   * one page but the aggregated result of fetching every page (`fetchAllSeniorSheets`), so a
   * completed sheet on a later server page is never silently truncated.
   */
  const seniorQueue = ref<SeniorSheetListPage>({ items: [], page: 1, pageSize: 25, total: 0 })
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
  /**
   * Senior-scoped sheet reviews (Feature 007), keyed by public sheet id.
   *
   * Held separately from the queue page for the same reason the manager reads are: the queue is the
   * proven-complete pending set, while this map carries the real sheet identity the additive Senior
   * read (`[BD←BE1]`) supplies when it lands. A failed read leaves the entry absent; the modal then
   * renders its unavailable placeholders (FR-007-014) instead of inventing a header.
   */
  const seniorSheetReviews = ref<Record<string, SeniorManagerSheetReview>>({})
  /**
   * How the last Senior sheet read of each sheet ended, reusing the same read-state vocabulary as
   * the per-transaction reads.
   *
   * Unlike the transaction reads, any failure — including a `404` the additive route answers while the
   * capability is absent — is classified `unavailable`, never `notFound`/`forbidden`: the sheet provably
   * exists (it came from the complete queue), so a failed identity read must render unavailable, never
   * a "no such sheet" or a client-derived claim (FR-007-013/014).
   */
  const seniorSheetReadStates = ref<Record<string, ManagerTransactionReadState>>({})
  /**
   * The Senior queue's XLSX export attempt (Feature 007, owner D3/D4; `[BD←BE2]`).
   *
   * `idle` → `exporting` → `done` | `refused`. The refusal carries the server's reason naming the
   * missing Senior-scoped capability (FR-007-044); a refused/never-attempted export is never presented
   * as a measured artifact, and no owner-scoped fallback is used.
   */
  type SeniorExportStatus = 'idle' | 'exporting' | 'done' | 'refused'
  const seniorExport = ref<{ status: SeniorExportStatus; reason: string | null }>({ status: 'idle', reason: null })
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

  /**
   * Feature 007: one Senior sheet's review alongside the queue, without touching the queue's global
   * loading/error state.
   *
   * Reuses the guarded-channel pattern for the additive Senior read (`[BD←BE1]`): no global
   * loading/error write, a cached answer reused unless `force`, and the outcome classified into the
   * read-state map so callers render unavailable identity fields when the backend read is absent
   * (FR-007-014) instead of blanking the already-rendered queue.
   */
  async function fetchSeniorSheetReview(sheetId: string, force = false): Promise<SeniorManagerSheetReview | null> {
    if (!force && seniorSheetReviews.value[sheetId]) {
      return seniorSheetReviews.value[sheetId] as SeniorManagerSheetReview
    }

    setSeniorSheetReadState(sheetId, 'loading')
    try {
      const review = await reviewsApi.fetchSeniorSheet(sheetId)
      seniorSheetReviews.value = { ...seniorSheetReviews.value, [sheetId]: review }
      setSeniorSheetReadState(sheetId, 'loaded')
      return review
    } catch (e: unknown) {
      // Any failure renders unavailable — see the read-state comment above.
      setSeniorSheetReadState(sheetId, 'unavailable')
      if (e instanceof ApiError && (e.status === 403 || e.status === 404)) return null
      return null
    }
  }

  function setSeniorSheetReadState(sheetId: string, state: ManagerTransactionReadState): void {
    seniorSheetReadStates.value = { ...seniorSheetReadStates.value, [sheetId]: state }
  }

  function clearSeniorSheetReviews(): void {
    seniorSheetReviews.value = {}
    seniorSheetReadStates.value = {}
  }

  function beginSeniorExport(): void {
    seniorExport.value = { status: 'exporting', reason: null }
  }

  function settleSeniorExport(status: 'done' | 'refused', reason: string | null = null): void {
    seniorExport.value = { status, reason }
  }

  function resetSeniorExport(): void {
    seniorExport.value = { status: 'idle', reason: null }
  }

  /**
   * T022 — request this sheet's filtered + sorted selection as one XLSX export.
   *
   * The selection travels as the exact ordered transaction ids the user sees (`ExportRequest.ids`).
   * Until the Senior-scoped row source exists the server refuses, and that refusal (naming the
   * missing capability) is what the caller renders — never an empty artifact presented as measured,
   * never an owner-scoped fallback (FR-007-044). `[BD←BE2]` reconnects the real artifact; on success
   * the server's `ExportResult` is returned so the caller can present exactly those bytes.
   */
  async function exportSeniorSelection(ids: string[]): Promise<ExportResult | null> {
    beginSeniorExport()
    try {
      const result = await requestExport({
        resource: SENIOR_QUEUE_RESOURCE,
        fileFormat: 'xlsx',
        ids,
      })
      settleSeniorExport('done')
      return result
    } catch (e: unknown) {
      const reason = e instanceof ApiError
        ? e.body.message
        : (e instanceof Error ? e.message : 'pettycash.error.export')
      settleSeniorExport('refused', reason)
      return null
    }
  }

  /**
   * Feature 007, UX-007-07: the Senior queue reads the sheet-list endpoint, not the old
   * transaction-derived `/queues/senior`.
   *
   * `fetchAllSeniorSheets` follows the server's `total` across pages, so a completed sheet on a
   * later page is never silently truncated. The returned page carries the server's own `total`,
   * which the view's completeness guard checks before drawing any row.
   */
  async function fetchSeniorQueue(pageSize: number = reviewsApi.SENIOR_SHEET_PAGE_SIZE) {
    loading.value = true
    error.value = null
    try {
      seniorQueue.value = await reviewsApi.fetchAllSeniorSheets(pageSize)
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
    seniorSheetReviews,
    seniorSheetReadStates,
    seniorExport,
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
    fetchSeniorSheetReview,
    setSeniorSheetReadState,
    clearSeniorSheetReviews,
    beginSeniorExport,
    settleSeniorExport,
    resetSeniorExport,
    exportSeniorSelection,
    fetchSeniorQueue,
    fetchFinanceQueue,
    fetchReturnsQueue,
    fetchTransactionForReview,
    recordDecision,
    clearSelectedTransaction,
    clearError
  }
})