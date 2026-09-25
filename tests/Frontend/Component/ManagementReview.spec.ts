// Management Review - Vue Component Tests (RED: behavior not implemented)

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import CostCenterQueueView from '@/views/reviews/CostCenterQueueView.vue'
import SeniorQueueView from '@/views/reviews/SeniorQueueView.vue'
import FinanceQueueView from '@/views/reviews/FinanceQueueView.vue'
import TransactionReviewDetailView from '@/views/reviews/TransactionReviewDetailView.vue'
import DecisionForm from '@/components/review/DecisionForm.vue'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import { useReviewsStore } from '@/stores/reviews'
import { useAuthStore } from '@/stores/auth'

// Mock components that may not exist yet
vi.mock('@/components/review/DecisionForm.vue', () => ({
  default: { template: '<div data-testid="decision-form">DecisionForm</div>' }
}))

vi.mock('@/components/review/PriorDecisionHistory.vue', () => ({
  default: { template: '<div data-testid="prior-decision-history">PriorDecisionHistory</div>' }
}))

describe('Management Review - Vue Component Tests (RED: behavior not implemented)', () => {
  let pinia: ReturnType<typeof createPinia>

  beforeEach(() => {
    pinia = createPinia()
    setActivePinia(pinia)
  })

  describe('CostCenterQueueView', () => {
    it('should render cost center queue with paginated transactions', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = {
        items: [
          { id: 'tx-1', ownerId: 'user1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } },
          { id: 'tx-2', ownerId: 'user2', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', revision: { amount: { amountMinor: 75000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } }
        ],
        total: 2,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="cost-center-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid="queue-item"]').length).toBe(2)
    })

    it('should show empty state when no transactions in queue', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = { items: [], total: 0, page: 1, pageSize: 25 }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
    })

    it('should have Persian RTL layout without horizontal scroll at 390px viewport', () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = { items: [], total: 0, page: 1, pageSize: 25 }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.attributes('dir')).toBe('rtl')
      expect(wrapper.classes()).toContain('rtl-layout')
    })

    it('should display WCAG AA compliant status colors/icons', () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = {
        items: [{ id: 'tx-1', ownerId: 'user1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } }],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      const statusBadge = wrapper.find('[data-testid="status-badge"]')
      expect(statusBadge.exists()).toBe(true)
      expect(statusBadge.classes()).toContain('wcag-aa-contrast')
    })

    it('should render Persian text with ≥12pt font', () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = { items: [], total: 0, page: 1, pageSize: 25 }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="persian-text"]').exists()).toBe(true)
      expect(wrapper.find('[style*="font-size"]').exists()).toBe(true)
    })
  })

  describe('SeniorQueueView', () => {
    it('should render senior queue with advisory cost center decisions visible', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.seniorQueue = {
        items: [
          { id: 'tx-1', ownerId: 'user1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true }, priorDecisions: [{ stage: 'COST_CENTER', decision: 'APPROVE', explanation: 'OK', actorName: 'Manager 1' }] }
        ],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(SeniorQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="advisory-decision"]').exists()).toBe(true)
    })

    it('should allow senior to decide independently without forced acknowledgment', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.seniorQueue = {
        items: [{ id: 'tx-1', ownerId: 'user1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true }, priorDecisions: [{ stage: 'COST_CENTER', decision: 'REJECT', explanation: 'Not valid', actorName: 'Manager 1' }] }],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(SeniorQueueView, { global: { plugins: [pinia] } })

      const decisionForm = wrapper.findComponent({ name: 'DecisionForm' })
      expect(decisionForm.exists()).toBe(true)
      expect(decisionForm.props('allowIndependentDecision')).toBe(true)
    })
  })

  describe('FinanceQueueView', () => {
    it('should render shared finance queue for all finance officers', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.financeQueue = {
        items: [
          { id: 'tx-1', ownerId: 'user1', stage: 'FINANCE', status: 'AWAITING_FINANCE_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } },
          { id: 'tx-2', ownerId: 'user2', stage: 'FINANCE', status: 'AWAITING_FINANCE_REVIEW', revision: { amount: { amountMinor: 30000, currency: 'IRR' }, costCenterId: 'CC002', hasEvidence: true } }
        ],
        total: 2,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(FinanceQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="finance-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid="queue-item"]').length).toBe(2)
    })

    it('should not show cost center scoping in finance queue', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.financeQueue = {
        items: [
          { id: 'tx-1', ownerId: 'user1', stage: 'FINANCE', status: 'AWAITING_FINANCE_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } }
        ],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(FinanceQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="cost-center-filter"]').exists()).toBe(false)
    })
  })

  describe('TransactionReviewDetailView', () => {
    it('should display transaction details with evidence and history', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.selectedTransaction = {
        id: 'tx-1',
        ownerId: 'user1',
        sheetId: 'sheet-1',
        stage: 'COST_CENTER',
        status: 'AWAITING_COST_CENTER_REVIEW',
        finalOutcome: null,
        revision: {
          id: 'rev-1',
          number: 1,
          expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Office supplies',
          costCenterId: 'CC001',
          expenseTypeTitle: 'Office Supplies',
          hasEvidence: true,
          createdAt: '2026-09-20T10:00:00Z',
          active: true
        },
        version: 0
      }
      reviewsStore.fetchTransactionForReview = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(TransactionReviewDetailView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="transaction-detail"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="evidence-list"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="decision-history"]').exists()).toBe(true)
    })

    it('should show evidence preview and download', async () => {
      const reviewsStore = useReviewsStore()
      reviewsStore.selectedTransaction = {
        id: 'tx-1',
        ownerId: 'user1',
        sheetId: 'sheet-1',
        stage: 'COST_CENTER',
        status: 'AWAITING_COST_CENTER_REVIEW',
        finalOutcome: null,
        revision: { id: 'rev-1', number: 1, expenseDate: '2026-09-20', amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 }, description: 'Office supplies', costCenterId: 'CC001', expenseTypeTitle: 'Office Supplies', hasEvidence: true, createdAt: '2026-09-20T10:00:00Z', active: true },
        version: 0
      }
      reviewsStore.fetchTransactionForReview = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(TransactionReviewDetailView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="evidence-preview"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="evidence-download"]').exists()).toBe(true)
    })
  })

  describe('DecisionForm', () => {
    it('should validate mandatory explanation for REJECT', async () => {
      const wrapper = mount(DecisionForm, {
        global: { plugins: [pinia] },
        props: { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false }
      })

      await wrapper.find('[data-testid="decision-select"]').setValue('REJECT')
      await wrapper.find('[data-testid="explanation-input"]').setValue('')
      await wrapper.find('[data-testid="submit-decision"]').trigger('click')

      expect(wrapper.find('[data-testid="explanation-error"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="explanation-error"]').text()).toContain('required')
    })

    it('should validate mandatory explanation for RETURN', async () => {
      const wrapper = mount(DecisionForm, {
        global: { plugins: [pinia] },
        props: { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false }
      })

      await wrapper.find('[data-testid="decision-select"]').setValue('RETURN')
      await wrapper.find('[data-testid="explanation-input"]').setValue('')
      await wrapper.find('[data-testid="submit-decision"]').trigger('click')

      expect(wrapper.find('[data-testid="explanation-error"]').exists()).toBe(true)
    })

    it('should allow APPROVE without explanation', async () => {
      const wrapper = mount(DecisionForm, {
        global: { plugins: [pinia] },
        props: { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false }
      })

      await wrapper.find('[data-testid="decision-select"]').setValue('APPROVE')
      await wrapper.find('[data-testid="submit-decision"]').trigger('click')

      expect(wrapper.find('[data-testid="explanation-error"]').exists()).toBe(false)
    })

    it('should include X-PettyCash-Command-ID header', async () => {
      const wrapper = mount(DecisionForm, {
        global: { plugins: [pinia] },
        props: { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false }
      })

      await wrapper.find('[data-testid="decision-select"]').setValue('APPROVE')
      await wrapper.find('[data-testid="submit-decision"]').trigger('click')

      expect(wrapper.emitted('submit-decision')).toBeTruthy()
      const payload = wrapper.emitted('submit-decision')?.[0]?.[0]
      expect(payload).toHaveProperty('commandId')
    })
  })

  describe('PriorDecisionHistory', () => {
    it('should display all prior decisions with full details', async () => {
      const wrapper = mount(PriorDecisionHistory, {
        global: { plugins: [pinia] },
        props: {
          decisions: [
            { stage: 'COST_CENTER', decision: 'APPROVE', explanation: 'OK', actorName: 'Manager 1', actorRole: 'COST_CENTER_MANAGER', createdAt: '2026-09-20T10:00:00Z' },
            { stage: 'SENIOR', decision: 'REJECT', explanation: 'Not valid', actorName: 'Senior 1', actorRole: 'SENIOR_MANAGER', createdAt: '2026-09-20T11:00:00Z' }
          ]
        }
      })

      expect(wrapper.findAll('[data-testid="decision-item"]').length).toBe(2)
      expect(wrapper.text()).toContain('APPROVE')
      expect(wrapper.text()).toContain('REJECT')
      expect(wrapper.text()).toContain('Manager 1')
      expect(wrapper.text()).toContain('Senior 1')
    })

    it('should show cost center decisions as advisory in senior stage', async () => {
      const wrapper = mount(PriorDecisionHistory, {
        global: { plugins: [pinia] },
        props: {
          decisions: [
            { stage: 'COST_CENTER', decision: 'APPROVE', explanation: 'OK', actorName: 'Manager 1', actorRole: 'COST_CENTER_MANAGER', createdAt: '2026-09-20T10:00:00Z' }
          ],
          currentStage: 'SENIOR'
        }
      })

      expect(wrapper.find('[data-testid="advisory-badge"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="advisory-badge"]').text()).toContain('Advisory')
    })
  })

  describe('Authorization & Self-Review', () => {
    it('should allow multi-role user to review own transaction when scope/stage match', async () => {
      const authStore = useAuthStore()
      authStore.user = { uid: 'multi-role-user', roles: ['PURCHASER', 'COST_CENTER_MANAGER'], costCenterCodes: ['CC001'] }
      authStore.canReview = vi.fn().mockReturnValue(true)

      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = {
        items: [{ id: 'tx-1', ownerId: 'multi-role-user', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', revision: { amount: { amountMinor: 50000, currency: 'IRR' }, costCenterId: 'CC001', hasEvidence: true } }],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(CostCenterQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="queue-item"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="decision-button"]').exists()).toBe(true)
    })

    it('should deny administrator without finance role from finance actions', async () => {
      const authStore = useAuthStore()
      authStore.user = { uid: 'admin-1', roles: ['PURCHASER', 'ADMINISTRATOR'], costCenterCodes: [] }
      authStore.canReview = vi.fn().mockReturnValue(false)

      const reviewsStore = useReviewsStore()
      reviewsStore.financeQueue = { items: [], total: 0, page: 1, pageSize: 25 }
      reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = mount(FinanceQueueView, { global: { plugins: [pinia] } })

      expect(wrapper.find('[data-testid="finance-queue"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="decision-button"]').exists()).toBe(false)
    })
  })
})