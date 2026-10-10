import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { TransactionDetail, DecisionWrite, DecisionResult } from '@/types/api'
import * as reviewsApi from '@/services/reviewsApi'
import type { TransactionPage } from '@/services/reviewsApi'

export const useReviewsStore = defineStore('reviews', () => {
  const costCenterQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const seniorQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const financeQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const returnsQueue = ref<TransactionPage>({ items: [], page: 1, pageSize: 25, total: 0 })
  const selectedTransaction = ref<TransactionDetail | null>(null)
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

  async function recordDecision(transactionId: string, payload: DecisionWrite): Promise<DecisionResult> {
    loading.value = true
    error.value = null
    try {
      const result = await reviewsApi.recordDecision(transactionId, payload)
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
    loading,
    error,
    hasCostCenterQueue,
    hasSeniorQueue,
    hasFinanceQueue,
    hasReturnsQueue,
    fetchCostCenterQueue,
    fetchSeniorQueue,
    fetchFinanceQueue,
    fetchReturnsQueue,
    fetchTransactionForReview,
    recordDecision,
    clearSelectedTransaction,
    clearError
  }
})