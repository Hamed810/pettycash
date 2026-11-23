// Management Review - Vue Component Tests (RED: behavior not implemented)

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import CostCenterQueueView from '@/views/reviews/CostCenterQueueView.vue'
import SeniorQueueView from '@/views/reviews/SeniorQueueView.vue'
import FinanceQueueView from '@/views/reviews/FinanceQueueView.vue'
import TransactionReviewDetailView from '@/views/reviews/TransactionReviewDetailView.vue'
import DecisionForm from '@/components/review/DecisionForm.vue'
import PriorDecisionHistory from '@/components/review/PriorDecisionHistory.vue'
import { ALLOWED_ACTION_KEYS } from '@/types/api'
import { useReviewsStore } from '@/stores/reviews'
import { expectCardAgreesWithTable } from '../../Support/cardAgreement'
import { useAuthStore } from '@/stores/auth'
import { useConfigurationStore } from '@/stores/configuration'
import { formatSheetDate } from '@/utils/date'
import { routes } from '@/router'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

// Every view mounted here calls a router composable in `setup()` — `useRouter()` in all five queue
// and detail views — and renders `<RouterLink>` to `{ name: 'dashboard' }`, and the detail view
// reads `route.params.stage` and `route.params.transactionId`. Mounting them without a router made
// every test in this file die inside `vue-router` with
// `TypeError: Cannot read properties of undefined (reading 'resolve')` before a single assertion
// ran, so the file measured nothing about US2. The routes come from the application's own table
// rather than a copy, so a name the views link to cannot go missing here without failing loudly.
// The transport is stubbed at the axios boundary rather than per service, so a store that calls a
// function this file did not think to stub cannot reach the network and leave the test hanging on a
// real request. `vi.mock('@/services/reviewsApi', …)` was tried first and did not work: the store
// calls `reviewsApi.fetchQueue` on a mount-time load, and a partial stub left the real function in
// place, which then issued a live request.
const ocsRequest = vi.hoisted(() => vi.fn())

/**
 * UI-PUR-001: the queue views now derive their `dir` from the running language instead of pinning
 * `dir="rtl"`, so this file must declare the language it renders in. `importOriginal` keeps the real
 * `t`/`n` (this file asserts catalogue keys, not translations) and pins only the two language
 * readers, which is exactly the environment the "Persian RTL" assertions describe.
 */
vi.mock('@nextcloud/l10n', async (importOriginal) => {
	const actual = await importOriginal<typeof import('@nextcloud/l10n')>()
	return { ...actual, getLanguage: () => 'fa', isRTL: () => true }
})

vi.mock('@nextcloud/axios', () => ({
  default: {
    request: (...args: unknown[]) => ocsRequest(...args),
    isAxiosError: (error: unknown) => Boolean((error as { isAxiosError?: boolean } | null)?.isAxiosError)
  }
}))

vi.mock('@nextcloud/router', () => ({
  generateOcsUrl: (path: string) => `/ocs/v2.php${path}`,
  imagePath: (path: string) => `/apps/pettycash/${path}`,
  appName: 'pettycash',
  appVersion: '1.0.0'
}))

function ocsEnvelope(data: unknown) {
  return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

/** A well-formed but empty OCS envelope, so a mount-time load resolves instead of rejecting. */
function emptyOcsResponse(config: { url?: string }) {
  const url = config?.url ?? ''
  const sheetMatch = url.match(/\/queues\/cost-center\/sheets\/([^/?]+)/)
  if (sheetMatch) {
    const sheetId = decodeURIComponent(sheetMatch[1] ?? '')
    return ocsEnvelope({
      sheet: {
        id: sheetId,
        title: '',
        ownerId: '',
        ownerDisplayName: '',
        currency: 'IRR',
        status: 'COST_CENTER',
        submittedTotalMinor: null,
        submittedTotal: null,
        submittedAt: null,
        version: 1
      },
      progress: {
        awaitingManager: 0,
        decidedByManager: 0,
        returnedForCorrection: 0,
        total: 0,
        managerComplete: true
      },
      transactions: []
    })
  }
  const data = /\/revisions(\?|$)/.test(url) ? [] : { items: [], page: 1, pageSize: 25, total: 0 }
  return ocsEnvelope(data)
}

/** A manager-scoped sheet read with real title/purchaser/submitted values and the seeded rows. */
function managerSheetReview(sheetId: string, transactions: unknown[], overrides: Record<string, unknown> = {}) {
  return {
    sheet: {
      id: sheetId,
      title: `Sheet ${sheetId}`,
      ownerId: 'user1',
      ownerDisplayName: 'User One',
      currency: 'IRR',
      status: 'COST_CENTER',
      submittedTotalMinor: 50000 * transactions.length,
      submittedTotal: { amountMinor: 50000 * transactions.length, currency: 'IRR', minorUnit: 0 },
      submittedAt: '2026-09-20T10:00:00Z',
      version: 1
    },
    progress: {
      awaitingManager: transactions.length,
      decidedByManager: 0,
      returnedForCorrection: 0,
      total: transactions.length,
      managerComplete: false
    },
    transactions: transactions.map((transaction) => {
      const row = transaction as Record<string, unknown>
      return {
        ...row,
        managerState: row.managerState ?? 'AWAITING_MANAGER_DECISION',
        managerDecision: row.managerDecision ?? null
      }
    }),
    ...overrides
  }
}

const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/

/** The browser shape on a plain-HTTP non-loopback origin: `randomUUID` is absent. */
function stubCryptoWithoutRandomUUID(): void {
  const original = globalThis.crypto
  vi.stubGlobal('crypto', {
    getRandomValues: original.getRandomValues.bind(original)
  })
}

function createTestRouter(): Router {
  // The real route table is reused for its names and parameters, but its components are replaced
  // with inert placeholders: the view under test is mounted directly, so resolving the route would
  // only re-import every real view through a dynamic import and add ~30s to the file.
  return createRouter({
    history: createWebHashHistory(),
    routes: routes.map(({ path, name, props }) => ({
      path,
      name,
      props,
      component: { template: '<div />' }
    }))
  })
}

describe('Management Review - Vue Component Tests (RED: behavior not implemented)', () => {
  let pinia: Pinia
  let router: Router

  beforeEach(() => {
    pinia = createPinia()
    router = createTestRouter()
    setActivePinia(pinia)
    ocsRequest.mockReset()
    ocsRequest.mockImplementation((config: { url?: string }) => Promise.resolve(emptyOcsResponse(config)))
  })

  /** Mounts with the plugins the view legitimately requires, and awaits its mount-time loads. */
  async function mountView(component: unknown, path = '/', props?: Record<string, unknown>): Promise<VueWrapper> {
    // A navigation is always performed: `router.isReady()` resolves after the first one, so asking
    // for readiness on a router that was never navigated to leaves it pending for ~5s per test.
    await router.push(path)
    await router.isReady()
    const wrapper = mount(component as never, {
      props: props as never,
      global: { plugins: [pinia, router] }
    })
    await flushPromises()
    return wrapper
  }

  describe('CostCenterQueueView', () => {
    /** One queue row, with only the fields the view actually reads varied. */
    function queueItem(id: string, overrides: Record<string, unknown> = {}) {
      return {
        id, sheetId: 'sheet-1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW',
        finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0,
        latestDecision: {
          id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
          actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' },
          decidedAt: '2026-09-20T10:00:00Z'
        },
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Expense',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Travel' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        },
        ...overrides
      }
    }

    async function mountQueue(
      items: unknown[],
      overrides: Record<string, unknown> = {},
      detailMode: 'ok' | 'fail' = 'ok',
      detailOverride?: (sheetId: string, sheetItems: unknown[]) => Record<string, unknown>
    ) {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = { items, total: items.length, page: 1, pageSize: 25, ...overrides } as never
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)
      ocsRequest.mockImplementation((config: { url?: string }) => {
        const url = config?.url ?? ''
        const match = url.match(/\/queues\/cost-center\/sheets\/([^/?]+)/)
        if (!match) return Promise.resolve(emptyOcsResponse(config))
        if (detailMode === 'fail') return Promise.reject(new Error('sheet details unavailable'))
        const sheetId = decodeURIComponent(match[1] ?? '')
        const sheetItems = items.filter((item) => (item as { sheetId?: string }).sheetId === sheetId)
        const detail = managerSheetReview(sheetId, sheetItems)
        return Promise.resolve(ocsEnvelope(detailOverride ? { ...detail, ...detailOverride(sheetId, sheetItems) } : detail))
      })
      return mountView(CostCenterQueueView, '/reviews/cost-center')
    }

    /**
     * The sheet-first queue keeps the transaction table as an explicit fallback
     * (`FR-006-017`). The show-transactions button was removed per FU-10.A, so tests exercise
     * the sheet view directly.
     */

    /**
     * T394: the narrow-viewport card carries the same figures as the table.
     *
     * FR-071 requires every listed element to be visible **without horizontal scroll** at 390px, which
     * this table used to answer with `overflow-x: auto`. `ResponsiveTable` now renders one card per row
     * below 640px, so a figure that is missing or differs there is one a reader cannot see at all -- and
     * **every other assertion in this suite reads the table**, which is the layout jsdom renders by
     * default. That is exactly the state the eight surfaces drifted in before T389.
     */
    it('should carry the same figures in the narrow card as in the table', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1', { sheetId: 'sheet-a' }),
        queueItem('tx-2', { sheetId: 'sheet-b' })
      ])

      expectCardAgreesWithTable(wrapper, {
        rows: 2,
        hooks: [
          'data-testid="sheet-amount"',
          'data-testid="sheet-reference"',
          'data-testid="sheet-purchaser"',
          'data-testid="sheet-transaction-count"',
          'data-testid="sheet-status-badge"',
          'data-testid="sheet-submitted"'
        ],
        cardRowTestId: 'sheet-card-row-'
      })
    })

    it('should offer no pagination when the queue fits one page', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1', { sheetId: 'sheet-a' }),
        queueItem('tx-2', { sheetId: 'sheet-b' })
      ])
      expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
      expect(wrapper.find('[data-testid="queue-pagination"]').exists()).toBe(false)
    })

    it('should show empty state when no transactions in queue', async () => {
      const wrapper = await mountQueue([])
      expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(0)
    })

    it('should present one sheet row per sheet from a complete pending set', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1'),
        queueItem('tx-2'),
        queueItem('tx-3', { sheetId: 'sheet-2' })
      ], { total: 3, page: 1, pageSize: 500 })

      const rows = wrapper.findAll('[data-testid^="sheet-row-"]')
      expect(rows).toHaveLength(2)
      // `ManagementReview.spec.ts` pins the Persian locale, so counts render with Persian digits.
      expect(wrapper.find('[data-testid="stat-sheets"]').text()).toContain('۲')
      expect(wrapper.find('[data-testid="stat-transactions"]').text()).toContain('۳')

      const first = wrapper.find('[data-testid="sheet-row-sheet-1"]')
      expect(first.exists()).toBe(true)
      expect(first.find('[data-testid="sheet-transaction-count"]').text()).toContain('۲')
      expect(first.find('[data-testid="sheet-title"]').text()).toContain('Sheet sheet-1')
      expect(first.find('[data-testid="sheet-purchaser-name"]').text()).toContain('User One')
      expect(first.find('[data-testid="sheet-submitted"]').text()).toContain(formatSheetDate('2026-09-20T10:00:00Z'))
      expect(first.find('[data-testid="sheet-status"]').text()).toContain('reviews.sheetQueue.awaiting')
      expect(first.find('[data-testid="status-badge-cost_center"]').exists()).toBe(true)
      expect(first.find('[data-testid="sheet-latest-activity"]').exists()).toBe(false)
      // UXC-V2-FU-05: long references render via CompactIdentifier, never a bare dominant UUID.
      expect(first.find('[data-testid="sheet-reference"]').classes()).toContain('compact-identifier')
      // UXC-V2-FU-06: no large permanent instructional panel and no subtitle above the table.
      expect(wrapper.find('[data-testid="responsibility-banner"]').exists()).toBe(false)
      expect(wrapper.find('.queue-header__subtitle').exists()).toBe(false)
      // The Phase 1 queue must not claim sheet-wide completion or Senior eligibility.
      expect(first.text()).not.toContain('Eligible for Senior Review')
      expect(wrapper.findAll('[data-testid^="queue-row-"]'), 'the flat table is not the primary surface').toHaveLength(0)
    })

    it('renders one sheet icon per row, aligned with title and reference', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1', { sheetId: 'sheet-a' }),
        queueItem('tx-2', { sheetId: 'sheet-b' })
      ])
      const rows = wrapper.findAll('[data-testid^="sheet-row-"]')
      expect(rows).toHaveLength(2)
      for (const row of rows) {
        // One icon per row
        const icons = row.findAll('.sheet-row__icon')
        expect(icons).toHaveLength(1)
        // Icon is a sibling of the text block, not nested inside it
        const identity = row.find('.sheet-row__identity')
        expect(identity.exists()).toBe(true)
        expect(identity.find('.sheet-row__icon').exists()).toBe(true)
        expect(identity.find('.sheet-row__text').exists()).toBe(true)
        // CompactIdentifier remains present
        expect(row.find('[data-testid="sheet-reference"]').exists()).toBe(true)
        expect(row.find('[data-testid="sheet-reference"]').classes()).toContain('compact-identifier')
        // Title button is present
        expect(row.find('[data-testid="sheet-title"]').exists()).toBe(true)
      }
      // No duplicate icons within table rows (each row has exactly one, verified above)
      const tableIcons = wrapper.findAll('[data-testid^="sheet-row-"] .sheet-row__icon')
      expect(tableIcons).toHaveLength(2)
    })

    /** Seeds the guarded per-transaction detail cache so cards and the drawer render decisions. */
    function seedTransactionDetails(
      entries: Array<{ id: string; decisions?: unknown[] }>
    ) {
      const reviewsStore = useReviewsStore()
      const details: Record<string, unknown> = {}
      const reads: Record<string, string> = {}
      for (const entry of entries) {
        const base = queueItem(entry.id)
        details[entry.id] = {
          ...base,
          allowedActions: { canApprove: true, canReject: true, canReturn: true },
          decisions: entry.decisions ?? [],
          evidence: [{
            id: `ev-${entry.id}`, transactionId: entry.id, revisionId: `rev-${entry.id}`,
            name: `receipt-${entry.id}.png`, mediaType: 'image/png', sizeBytes: 100,
            active: true, uploadedBy: 'user1', uploadedAt: '2026-09-20T10:00:00Z'
          }]
        }
        reads[entry.id] = 'loaded'
      }
      reviewsStore.managerTransactionDetails = details as never
      reviewsStore.managerTransactionReads = reads as never
    }

    it('should count manager progress and show decided transactions in the shared sheet modal', async () => {
      const approvedDecision = {
        id: 'd-approved',
        stage: 'COST_CENTER',
        decision: 'APPROVE',
        explanation: 'Looks right',
        actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
        decidedAt: '2026-09-21T10:00:00Z'
      }
      const returnedDecision = {
        id: 'd-returned',
        stage: 'COST_CENTER',
        decision: 'RETURN',
        explanation: 'Receipt is missing',
        actor: { userId: 'manager1', displayName: 'Manager One', role: 'COST_CENTER_MANAGER' },
        decidedAt: '2026-09-22T10:00:00Z'
      }

      const wrapper = await mountQueue(
        [queueItem('tx-1'), queueItem('tx-2')],
        { total: 2, page: 1, pageSize: 500 },
        'ok',
        () => ({
          progress: {
            awaitingManager: 0,
            decidedByManager: 2,
            returnedForCorrection: 1,
            total: 2,
            managerComplete: true
          },
          transactions: [
            { ...queueItem('tx-1'), managerState: 'DECIDED_BY_MANAGER', managerDecision: approvedDecision },
            { ...queueItem('tx-2'), managerState: 'RETURNED_FOR_CORRECTION', managerDecision: returnedDecision }
          ]
        })
      )
      seedTransactionDetails([
        { id: 'tx-1', decisions: [approvedDecision] },
        { id: 'tx-2', decisions: [returnedDecision] }
      ])

      // "In progress" means this manager already decided something in the sheet. It must not be
      // presented as sheet-wide completion or Senior eligibility.
      expect(wrapper.find('[data-testid="sheet-status"]').text()).toContain('reviews.seniorSheet.reviewCompleted')
      const completedTab = wrapper.find('[data-testid="sheet-queue-tab-completed"]')
      expect(completedTab.exists()).toBe(true)
      await completedTab.trigger('click')
      expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)

      await wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').trigger('click')
      await flushPromises()
      // The shared Sheet Details Modal replaces the inline expansion.
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()
      // The queue stays mounted behind the modal.
      expect(wrapper.find('[data-test="sheet-table"]').exists()).toBe(true)

      // The progress aggregate (with its returned count) renders above the tabs.
      const progress = document.body.querySelector('[data-testid="cc-review-progress"]')
      expect(progress?.textContent).toContain('reviews.quickReview.progress')
      expect(progress?.textContent).toContain('reviews.quickReview.returnedCount')

      const cards = document.body.querySelectorAll('[data-testid^="transaction-review-card-"]')
      expect(cards).toHaveLength(2)

      // The decided card shows its Cost Center outcome as a stage indicator and keeps no decide bar.
      const approvedCard = document.body.querySelector('[data-testid="sheet-transaction-tx-1"]')!
      expect(approvedCard.querySelector('[data-testid="transaction-card-decision-costCenter"]')?.textContent).toContain('reviews.outcome.approved')
      expect(approvedCard.querySelector('[data-testid="transaction-card-decide-approve"]')).toBeNull()

      const returnedCard = document.body.querySelector('[data-testid="sheet-transaction-tx-2"]')!
      expect(returnedCard.querySelector('[data-testid="transaction-card-decision-costCenter"]')?.textContent).toContain('reviews.quickReview.card.returned')

      // The decision explanations live in the expanded transaction's Cost Center previous-decisions
      // group (UXC-V2-09: the drawer path is retired in favour of the inline expansion).
      await (approvedCard.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
      await flushPromises()
      expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
      const ccGroup = document.body.querySelector('[data-testid="inline-decisions-cost-center"]')
      expect(ccGroup?.textContent).toContain('Looks right')
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()

      // One Escape collapses only the expanded card; the sheet modal stays open beneath it.
      document.body.querySelector('[data-testid="transaction-expanded-detail"]')?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
      )
      await flushPromises()
      expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()

      // The returned transaction's explanation is equally authoritative.
      await (returnedCard.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
      await flushPromises()
      expect(document.body.querySelector('[data-testid="inline-decisions-cost-center"]')?.textContent).toContain('Receipt is missing')

      // Close the sheet so this test's teleported overlay cannot leak into later tests' queries
      // (the shared concept testids would otherwise answer another surface's selectors).
      await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
      await flushPromises()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
    })

    it('should expose only the supported summary stats and queue tabs', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')], { total: 1, page: 1, pageSize: 500 })

      expect(wrapper.findAll('[data-testid^="stat-"]')).toHaveLength(2)
      expect(wrapper.find('[data-testid="stat-sheets"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="stat-transactions"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="stat-reviewed"]').exists()).toBe(false)

      expect(wrapper.find('[data-testid="sheet-queue-tab-all"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="sheet-queue-tab-pending"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="sheet-queue-tab-completed"]').exists()).toBe(true)
      // The reviewed-history surface is not implemented and is no longer advertised as disabled.
      expect(wrapper.find('[data-testid="tab-reviewed"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="reviewed-unavailable"]').exists()).toBe(false)
      expect(wrapper.text()).not.toContain('reviews.sheetQueue.stats.reviewedUnavailable')
      expect(wrapper.text()).not.toContain('reviews.sheetQueue.stats.reviewedReason')
    })

    it('should keep decisions transaction-level inside the shared sheet modal', async () => {
      const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
      // The drawer renders from the guarded detail read; seeding keeps the mock's page envelope
      // (which is not a TransactionDetail) out of the cache for a URL the mock cannot answer.
      seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
      const row = wrapper.find('[data-testid="sheet-row-sheet-1"]')
      await row.find('[data-testid="sheet-title"]').trigger('click')
      await flushPromises()

      const modal = document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')
      expect(modal).not.toBeNull()

      // The transaction title toggles the inline expansion — same Sheet review context,
      // no route navigation, no second full-height overlay (UXC-V2-09).
      await (document.body.querySelector('[data-testid="transaction-card-title"]') as HTMLElement).click()
      await flushPromises()
      expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).not.toBeNull()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()

      // Each card carries the transaction-level decide bar (the decide buttons open the shared
      // single-transaction confirmation).
      expect(document.body.querySelector('[data-testid="transaction-card-decide-approve"]')).not.toBeNull()
      expect(document.body.querySelector('[data-testid="transaction-card-decide-reject"]')).not.toBeNull()
      expect(document.body.querySelector('[data-testid="transaction-card-decide-return"]')).not.toBeNull()

      // There is no sheet-level decision unit in the DOM: the decision gate stays
      // transaction-by-transaction, so nothing may decide for the sheet as a whole.
      const labels = Array.from(modal!.querySelectorAll('button')).map((button) => button.textContent?.trim() ?? '')
      expect(labels).not.toContain('reviews.quickReview.approveSheet')
      expect(labels).not.toContain('reviews.quickReview.rejectSheet')
      expect(document.body.querySelector('[data-testid="sheet-decision-bar"]')).toBeNull()

      // Close the sheet so this test's teleported overlay cannot leak into later tests' queries.
      await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
      await flushPromises()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
    })

    it('should restore sheet-row focus through the full modal lifecycle', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')], { total: 1, page: 1, pageSize: 500 })
      // The row-focus restoration reaches the row through `document.querySelector`, so this test's
      // view must be in the document (the suite's default mount is detached).
      // The row-focus restoration reaches the row through `document.querySelector`, so this test's
      // view must be in the document (the suite's default mount is detached).
      document.body.appendChild(wrapper.element as never)
      await flushPromises()
      const rowSummary = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]')
      await rowSummary.trigger('click')
      await flushPromises()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).not.toBeNull()

      // One Escape closes the top layer only, and closing the modal restores the originating row.
      document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')?.dispatchEvent(
        new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
      )
      await flushPromises()
      expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-title"]').exists()).toBe(true)

      // The modal is closed by the Escape above; detach the appended view so its queue markup
      // cannot leak into later tests' document queries.
      wrapper.element.remove()
    })

    it('should refuse to group a partial pending set and offer the transaction list', async () => {
      const items = Array.from({ length: 25 }, (_, index) => queueItem(`tx-${index + 1}`))
      const wrapper = await mountQueue(items, { total: 30, page: 1, pageSize: 25 })

      expect(wrapper.find('[data-testid="grouping-unavailable"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="stat-sheets"]').exists()).toBe(false)
      expect(wrapper.find('[data-test="sheet-table"]').exists()).toBe(false)

      await wrapper.find('[data-testid="open-transactions-btn"]').trigger('click')
      await flushPromises()
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(25)
    })

    it('should filter the complete sheet set locally', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1'),
        queueItem('tx-2', { sheetId: 'sheet-2' })
      ], { total: 2, page: 1, pageSize: 500 })

      await wrapper.find('[data-testid="sheet-search"]').setValue('sheet-2')
      const rows = wrapper.findAll('[data-testid^="sheet-row-"]')
      expect(rows).toHaveLength(1)
      expect(rows[0]?.attributes('data-testid')).toBe('sheet-row-sheet-2')
    })

    // FR-072 / FR-071's "no horizontal scroll at 390px viewport".
    //
    // The original asserted the root carried a class named `rtl-layout`, which no requirement names
    // and no stylesheet defines — a class name is not a layout. It also asserted nothing at all about
    // horizontal scroll. jsdom has no layout engine, so an overflow cannot be measured here; what is
    // checkable is the direction the view declares and whether the declarations that make the
    // viewport achievable are present, which is the substitute T283 adopted when it removed an
    // equivalent `responsive-container` assertion.
    it('should have Persian RTL layout without horizontal scroll at 390px viewport', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')])
      expect(wrapper.attributes('dir')).toBe('rtl')

      const source = readFileSync(join(process.cwd(), 'src/views/reviews/CostCenterQueueView.vue'), 'utf8')

      // The 390px viewport has to fall inside a declared mobile breakpoint. Both spellings count —
      // the legacy `max-width: 600px` and the range syntax `width <= 390px` this view actually uses —
      // and a `max-width: 600px` rule already covers 390px, so the requirement is that the *largest*
      // breakpoint reaches the viewport, not that some breakpoint sits exactly at it.
      const breakpoints = [
        ...[...source.matchAll(/max-width:\s*(\d+)px/g)].map((m) => Number(m[1])),
        ...[...source.matchAll(/width\s*<=\s*(\d+)px/g)].map((m) => Number(m[1]))
      ]
      expect(breakpoints.length, 'a mobile breakpoint must be declared').toBeGreaterThan(0)
      expect(Math.max(...breakpoints)).toBeGreaterThanOrEqual(390)

      // A `min-width` above the viewport is the usual way a table forces a horizontal scrollbar.
      const offenders = [...source.matchAll(/min-width:\s*(\d+)px|min-inline-size:\s*(\d+)px/g)]
        .map((m) => Number(m[1] ?? m[2]))
        .filter((px) => px > 390)
      expect(offenders, 'no declaration may force the viewport wider than 390px').toEqual([])
    })

    // WCAG 1.4.1: state must not be conveyed by colour alone.
    //
    // The original asserted a `wcag-aa-contrast` class that nothing ever set — the same meaningless
    // proxy T283 removed from `PurchaserFlow.spec.ts`. A contrast ratio cannot be computed in jsdom
    // (it needs font metrics); what *is* checkable is that the state survives without colour, which is
    // the half of the requirement that actually excludes colour-only signalling.
    it('should display WCAG AA compliant status colors/icons', async () => {
      const wrapper = await mountQueue([
        queueItem('tx-1', { sheetId: 'sheet-a' }),
        queueItem('tx-2', { sheetId: 'sheet-b', status: 'COST_CENTER_APPROVED' })
      ])

      const rows = wrapper.findAll('[data-testid^="sheet-row-"]')
      expect(rows).toHaveLength(2)
      for (const row of rows) {
        const badge = row.find('[data-testid="sheet-status-badge"]')
        expect(badge.exists(), 'a status badge must be present').toBe(true)
        expect(badge.text().trim(), 'status must be readable text, not colour alone').not.toBe('')
      }
      expect(rows[0]?.find('[data-testid="sheet-status-badge"]').exists()).toBe(true)
      expect(rows[1]?.find('[data-testid="sheet-status-badge"]').exists()).toBe(true)
    })

    // FR-071: ≥12pt, which is 16px. The original asserted a `persian-text` hook that no component
    // emits and an inline `style="font-size: …"` attribute the app never uses — all typography is
    // declared in style blocks, and T120 moved the whole app onto a four-step token scale. The
    // checkable claim is the same one the app-wide gate enforces, asserted for this component: none
    // of its own declarations falls below the floor.
    it('should render Persian text with ≥12pt font', async () => {
      await mountQueue([queueItem('tx-1')])

      const source = readFileSync(join(process.cwd(), 'src/views/reviews/CostCenterQueueView.vue'), 'utf8')
      const below = [...source.matchAll(/font-size:\s*([\d.]+)(px|rem)\s*;/g)]
        .map((m) => ({ value: m[1], unit: m[2] }))
        .filter(({ value, unit }) => Number(value) * (unit === 'rem' ? 16 : 1) < 16)
      expect(below, 'no declaration in this view may fall below the 12pt floor').toEqual([])
    })

    describe('inline transaction expansion (UXC-V2-09)', () => {
    async function openSheetModal(wrapper: VueWrapper, sheetId = 'sheet-1'): Promise<void> {
        await wrapper.find(`[data-testid="sheet-row-${sheetId}"] [data-testid="sheet-title"]`).trigger('click')
        await flushPromises()
    }

      async function openExpansion(id: string): Promise<void> {
        await (document.body.querySelector(
          `[data-testid="transaction-review-card-${id}"] [data-testid="transaction-card-title"]`
        ) as HTMLElement).click()
        await flushPromises()
      }

      it('expands the selected transaction in place with its own detail — never another transaction\'s', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        await openExpansion('tx-2')
        const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
        expect(region).not.toBeNull()
        // The inline detail shows complementary fields (sheet, purchaser, submitted, evidence);
        // transaction-scoped metadata stays on the card above (UXC-V2-FU-02).
        expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
        expect(region?.querySelector('[data-testid="inline-attachment-ev-tx-2"]')).not.toBeNull()
        // The originating card's toggle carries the disclosure state.
        const card = document.body.querySelector('[data-testid="transaction-review-card-tx-2"]')
        expect(card?.querySelector('[data-testid="transaction-card-title"]')?.getAttribute('aria-expanded')).toBe('true')
        expect(card?.querySelector('[data-testid="transaction-card-title"]')?.getAttribute('aria-controls'))
          .toBe('transaction-expanded-tx-2')
        // No route change: the sheet modal is still the only frame.
        expect(document.body.querySelectorAll('[data-testid="sheet-details-modal-dialog"]')).toHaveLength(1)
        // The neighbouring transaction stays mounted and collapsed beside the expansion.
        const neighbor = document.body.querySelector('[data-testid="transaction-review-card-tx-1"]')
        expect(neighbor).not.toBeNull()
        expect(neighbor?.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
        expect(neighbor?.querySelector('[data-testid="transaction-card-title"]')?.getAttribute('aria-expanded')).toBe('false')
      
        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })

      it('opens exactly one expansion at a time — opening B collapses A', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        await openExpansion('tx-1')
        await openExpansion('tx-2')
        const regions = document.body.querySelectorAll('[data-testid="transaction-expanded-detail"]')
        expect(regions).toHaveLength(1)
        expect(regions[0]?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
        expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-expanded-detail"]')).toBeNull()
      
        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })

      it('keeps evidence bound to the selected transaction: the preview opens at that document and returns to the same expansion', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        await openExpansion('tx-2')
        // Precondition: the expansion is open and shows tx-2 before the preview cycle.
        const before = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
        expect(before?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()

        // A thumbnail on tx-2 opens the preview bound to tx-2's evidence set.
        await (document.body.querySelector('[data-testid="transaction-review-card-tx-2"] [data-testid="transaction-card-thumb-ev-tx-2"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).not.toBeNull()
        expect(document.body.querySelector('[data-test="evidence-preview-name"]')?.textContent).toContain('receipt-tx-2.png')

        // Closing the preview returns to the same expanded transaction context.
        document.body.querySelector('[data-test="evidence-preview"]')?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
        )
        await flushPromises()
        expect(document.body.querySelector('[data-test="evidence-preview-layer"]')).toBeNull()
        const region = document.body.querySelector('[data-testid="transaction-expanded-detail"]')
        expect(region?.querySelector('[data-testid="inline-field-sheet"]')).not.toBeNull()
      
        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })

      it('routes the expanded decide row through the same single confirmation as the collapsed bar', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        await openExpansion('tx-1')
        await (document.body.querySelector('[data-testid="inline-decide-approve"]') as HTMLElement).click()
        await flushPromises()
        // The confirmation opens over the still-expanded transaction — one overlay, one decision.
        expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()

        // Completing the decision posts exactly one decision for the EXPANDED transaction and
        // collapses the expansion deterministically (the drawer closed on decision too).
        await (document.body.querySelector('[data-testid="decision-confirm-btn"]') as HTMLElement).click()
        await flushPromises()
        const decisionCalls = ocsRequest.mock.calls.filter((call: unknown[]) => {
          const config = call[0] as { url?: string; method?: string }
          return /\/transactions\/tx-1\/decisions$/.test(config?.url ?? '') && config?.method === 'POST'
        })
        expect(decisionCalls).toHaveLength(1)
        expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()
        expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
      
        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })

      it('collapses the expansion when the modal closes and never retains it into the next sheet', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        await openExpansion('tx-1')
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()

        // Reopening the same sheet starts collapsed — no stale expansion survives the close/reopen.
        await openSheetModal(wrapper)
        await flushPromises()
        expect(document.body.querySelector('[data-testid="transaction-expanded-detail"]')).toBeNull()
        expect(document.body.querySelector('[data-testid="transaction-review-card-tx-1"] [data-testid="transaction-card-title"]')?.getAttribute('aria-expanded')).toBe('false')

        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })

      it('keeps the legacy drawer unreachable through normal Cost Center interaction', async () => {
        seedTransactionDetails([{ id: 'tx-1' }, { id: 'tx-2' }])
        const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')], { total: 2, page: 1, pageSize: 500 })
        await openSheetModal(wrapper)
        await flushPromises()

        // Every user-reachable entry into detail — title toggle, thumbnail, decide, Escape —
        // produces the expansion or the shared overlays, never the drawer element.
        await openExpansion('tx-1')
        await (document.body.querySelector('[data-testid="transaction-card-thumb-ev-tx-2"]') as HTMLElement).click()
        await flushPromises()
        document.body.querySelector('[data-test="evidence-preview"]')?.dispatchEvent(
          new KeyboardEvent('keydown', { key: 'Escape', bubbles: true })
        )
        await flushPromises()
        await (document.body.querySelector('[data-testid="transaction-card-decide-approve"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="decision-modal"]')).not.toBeNull()

        // Cancel the confirmation, collapse the expansion, and close the sheet so this test's
        // markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="decision-cancel-btn"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="decision-modal"]')).toBeNull()

        // Close the sheet so this test's markup cannot leak into later tests' queries.
        await (document.body.querySelector('[data-testid="sheet-details-modal-close"]') as HTMLElement).click()
        await flushPromises()
        expect(document.body.querySelector('[data-testid="sheet-details-modal-dialog"]')).toBeNull()
      })
    })

    /** FU-11.B: Cost Center multi-cost-center filtering. */
    describe('Cost Center cost-center filter', () => {
      function mountWithCenters(
        items: unknown[],
        centerCodes: string[],
        centers: { id: string; code: string; name: string }[]
      ) {
        const reviewsStore = useReviewsStore()
        reviewsStore.costCenterQueue = { items, total: items.length, page: 1, pageSize: 25 } as never
        reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)
        const authStore = useAuthStore()
        authStore.managedCostCenterCodes = centerCodes
        const configStore = useConfigurationStore()
        configStore.costCenters = centers as never
        ocsRequest.mockImplementation((config: { url?: string }) => {
          const url = config?.url ?? ''
          const match = url.match(/\/queues\/cost-center\/sheets\/([^/?]+)/)
          if (!match) return Promise.resolve(emptyOcsResponse(config))
          const sheetId = decodeURIComponent(match[1] ?? '')
          const sheetItems = items.filter((item) => (item as { sheetId?: string }).sheetId === sheetId)
          const detail = managerSheetReview(sheetId, sheetItems)
          return Promise.resolve(ocsEnvelope(detail))
        })
        return mountView(CostCenterQueueView, '/reviews/cost-center')
      }

      function ccItem(id: string, overrides: Record<string, unknown> = {}) {
        return {
          id, sheetId: 'sheet-1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW',
          finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0,
          latestDecision: {
            id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
            actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' },
            decidedAt: '2026-09-20T10:00:00Z'
          },
          activeRevision: {
            id: 'rev-1', number: 1, expenseDate: '2026-09-20',
            amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
            description: 'Expense',
            costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
            expenseType: { id: 'et-1', name: 'Travel' },
            createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
          },
          ...overrides
        }
      }

      it('renders filter chips for assigned cost centers only', async () => {
        const wrapper = await mountWithCenters(
          [ccItem('tx-1')],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
            { id: 'c3', code: 'CC003', name: 'Cost Center 3' },
          ]
        )
        // Only assigned centers appear as chips
        expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="center-filter-chip-CC002"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="center-filter-chip-CC003"]').exists()).toBe(false)
      })

      it('renders the All cost centers chip', async () => {
        const wrapper = await mountWithCenters(
          [ccItem('tx-1')],
          ['CC001'],
          [{ id: 'c1', code: 'CC001', name: 'Cost Center 1' }]
        )
        // All cost centers chip is present
        expect(wrapper.find('[data-testid="center-filter-chip-all"]').exists()).toBe(true)
        // All cost centers is active by default when no specific center is selected
        const allChip = wrapper.find('[data-testid="center-filter-chip-all"]')
        expect(allChip.classes()).toContain('center-filter__chip--active')
        expect(allChip.attributes('aria-pressed')).toBe('true')
      })

      it('activates a specific center chip and deactivates All cost centers', async () => {
        const wrapper = await mountWithCenters(
          [ccItem('tx-1')],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
          ]
        )
        // All cost centers active by default
        expect(wrapper.find('[data-testid="center-filter-chip-all"]').classes()).toContain('center-filter__chip--active')
        // Select CC001
        await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
        await flushPromises()
        // CC001 chip is active
        expect(wrapper.find('[data-testid="center-filter-chip-CC001"]').classes()).toContain('center-filter__chip--active')
        // All cost centers is no longer active
        expect(wrapper.find('[data-testid="center-filter-chip-all"]').classes()).not.toContain('center-filter__chip--active')
      })

      it('restores unrestricted results when All cost centers is reselected', async () => {
        const wrapper = await mountWithCenters(
          [
            ccItem('tx-1', {
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
              },
            }),
            ccItem('tx-2', {
              sheetId: 'sheet-2',
              activeRevision: {
                costCenter: { id: 'cc-2', code: 'CC002', name: 'Cost Center 2' },
              },
            }),
          ],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
          ]
        )
        // Both sheets visible when All selected (default)
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
        // Select CC001 → only sheet-1 remains
        await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
        // Reselect All cost centers → both sheets back
        await wrapper.find('[data-testid="center-filter-chip-all"]').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
      })

      it('shows all sheets when no center is selected', async () => {
        const wrapper = await mountWithCenters(
          [ccItem('tx-1')],
          ['CC001'],
          [{ id: 'c1', code: 'CC001', name: 'Cost Center 1' }]
        )
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
      })

      it('filters sheets to the selected cost center', async () => {
        const wrapper = await mountWithCenters(
          [
            ccItem('tx-1', {
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
              },
            }),
            ccItem('tx-2', {
              sheetId: 'sheet-2',
              activeRevision: {
                costCenter: { id: 'cc-2', code: 'CC002', name: 'Cost Center 2' },
              },
            }),
          ],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
          ]
        )
        // Both sheets visible when "All" selected
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)

        // Select CC001 → only sheet-1 remains
        await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
        await flushPromises()
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
        expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
      })

      it('scopes transaction count to the selected cost center', async () => {
        const wrapper = await mountWithCenters(
          [
            ccItem('tx-1', {
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
              },
            }),
            ccItem('tx-2', {
              sheetId: 'sheet-2',
              activeRevision: {
                costCenter: { id: 'cc-2', code: 'CC002', name: 'Cost Center 2' },
              },
            }),
          ],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
          ]
        )
        await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
        await flushPromises()
        // sheet-1 has one CC001 transaction → count = 1 (Persian digit in fa locale)
        expect(wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-transaction-count"]').text()).toContain('۱')
      })

      it('scopes amount total to the selected cost center', async () => {
        const wrapper = await mountWithCenters(
          [
            ccItem('tx-1', {
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
                amount: { amountMinor: 300000, currency: 'IRR', minorUnit: 0 },
              },
            }),
            ccItem('tx-2', {
              sheetId: 'sheet-2',
              activeRevision: {
                costCenter: { id: 'cc-2', code: 'CC002', name: 'Cost Center 2' },
                amount: { amountMinor: 200000, currency: 'IRR', minorUnit: 0 },
              },
            }),
          ],
          ['CC001', 'CC002'],
          [
            { id: 'c1', code: 'CC001', name: 'Cost Center 1' },
            { id: 'c2', code: 'CC002', name: 'Cost Center 2' },
          ]
        )
        await wrapper.find('[data-testid="center-filter-chip-CC001"]').trigger('click')
        await flushPromises()
        // sheet-1 shows only CC001 amount: 300,000 IRR (Persian digits in fa locale)
        const amountCell = wrapper.find('[data-testid="sheet-row-sheet-1"] [data-testid="sheet-amount"]')
        expect(amountCell.text()).toContain('۳۰۰٬۰۰۰')
        expect(amountCell.text()).not.toContain('۵۰۰٬۰۰۰')
      })

      it('shows each sheet only once when filtered', async () => {
        const wrapper = await mountWithCenters(
          [
            ccItem('tx-1', {
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
              },
            }),
            ccItem('tx-2', {
              sheetId: 'sheet-2',
              activeRevision: {
                costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center 1' },
              },
            }),
          ],
          ['CC001'],
          [{ id: 'c1', code: 'CC001', name: 'Cost Center 1' }]
        )
        expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(2)
        expect(wrapper.find('[data-testid="sheet-row-sheet-1"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="sheet-row-sheet-2"]').exists()).toBe(true)
      })
    })

  describe('Cost Center cross-role tab semantics — Approvals is sheet-level (UXC-7-01/02/03)', () => {
    beforeEach(() => {
      // Teleported modals are attached to document.body; a modal a prior test left behind would
      // answer this describe's document-level queries instead of this test's own.
      document.body.innerHTML = ''
    })

    afterEach(() => {
      document.body.innerHTML = ''
    })

    const managerDecision = (id: string, decision: 'APPROVE' | 'REJECT' | 'RETURN') => ({
      id,
      stage: 'COST_CENTER',
      decision,
      actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' },
      decidedAt: '2026-09-20T10:00:00Z'
    })

    async function openSheetModal(wrapper: VueWrapper, sheetId = 'sheet-1'): Promise<void> {
      await wrapper.find(`[data-testid="sheet-row-${sheetId}"] [data-testid="sheet-title"]`).trigger('click')
      await flushPromises()
    }

    async function openTab(key: 'transactions' | 'approvals' | 'overview' | 'history'): Promise<void> {
      await document.body.querySelector<HTMLElement>(`[data-testid="sheet-details-modal-tab-${key}"]`)!.click()
      await flushPromises()
    }

    it('exposes the same four canonical tabs as Senior and Finance (UXC-7-03)', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')])
      await openSheetModal(wrapper)
      for (const key of ['transactions', 'approvals', 'overview', 'history'] as const) {
        expect(document.body.querySelector(`[data-testid="sheet-details-modal-tab-${key}"]`)).not.toBeNull()
      }
    })

    /**
     * UXC-7F-05: the Approvals tab consumes the manager read's server-authoritative whole-sheet
     * `stageApprovedTotals`. This seeds it into the mocked per-sheet read's sheet header; passing
     * `null` leaves the payload without the field (the old/malformed-payload case).
     */
    async function mountQueueWithSheetTotals(
      items: unknown[],
      totals: Record<string, unknown> | null
    ): Promise<VueWrapper> {
      return mountQueue(items, {}, 'ok', (sheetId, sheetItems) => {
        const sheet = managerSheetReview(sheetId, sheetItems).sheet as Record<string, unknown>
        return { sheet: totals === null ? sheet : { ...sheet, stageApprovedTotals: totals } }
      })
    }

    function stageTotals(overrides: Record<string, unknown> = {}): Record<string, unknown> {
      return {
        costCenterApproved: { amountMinor: 150000, currency: 'IRR', minorUnit: 0 },
        seniorApproved: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 },
        financeApproved: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
        ...overrides,
      }
    }

    /**
     * This file renders the FA locale, so `formatMoney` emits Persian digits and the Persian
     * thousands separator. Amount assertions normalize to ASCII digits first — the value itself,
     * not its script, is what proves the server's figure was rendered.
     */
    function asciiDigits(text: string | null | undefined): string {
      return (text ?? '')
        .replace(/[۰-۹]/g, (digit) => String('۰۱۲۳۴۵۶۷۸۹'.indexOf(digit)))
        .replace(/٬/g, ',')
    }

    it('renders the server whole-sheet stageApprovedTotals for all three stages (UXC-7F-05)', async () => {
      const wrapper = await mountQueueWithSheetTotals(
        [
          // The visible scoped set proves only tx-1's IRR 50,000 as CC-approved; the server's
          // aggregate (150,000) also counts out-of-scope work this surface must not recompute.
          queueItem('tx-1', { managerState: 'DECIDED_BY_MANAGER', managerDecision: managerDecision('d-1', 'APPROVE') }),
          queueItem('tx-2'),
        ],
        stageTotals()
      )
      await openSheetModal(wrapper)
      await openTab('approvals')

      const panel = document.body.querySelector('[data-testid="sheet-details-modal-panel-approvals"]')
      expect(panel).not.toBeNull()
      expect(panel!.querySelector('[data-testid="sheet-approvals-summary"]')).not.toBeNull()
      // Canonical stage order, shared with Senior and Finance (cross-role requirement 15).
      const stageRows = [...panel!.querySelectorAll<HTMLElement>('[data-testid^="sheet-approvals-stage-"]')]
        .map((el) => el.getAttribute('data-testid'))
        .filter((id): id is string => id !== null && /^(sheet-approvals-stage-(costCenter|senior|finance))$/.test(id))
      expect(stageRows).toEqual([
        'sheet-approvals-stage-costCenter',
        'sheet-approvals-stage-senior',
        'sheet-approvals-stage-finance',
      ])
      // Each stage renders exactly one single-currency Money: the server's whole-sheet figure,
      // which no client-side sum over the manager-scoped transaction set can reproduce.
      const stageTotal = (key: string) => panel!.querySelector(`[data-testid="sheet-approvals-stage-${key}-total"]`)
      const expected: Array<[string, string]> = [
        ['costCenter', '150,000'],
        ['senior', '100,000'],
        ['finance', '50,000'],
      ]
      for (const [key, amount] of expected) {
        expect(stageTotal(key)).not.toBeNull()
        expect(stageTotal(key)!.textContent).toContain('IRR')
        expect(asciiDigits(stageTotal(key)!.textContent)).toContain(amount)
        expect(panel!.querySelector(`[data-testid="sheet-approvals-stage-${key}-unavailable"]`)).toBeNull()
      }
      // Out-of-scope transactions remain absent: the tab names no transaction, and no
      // per-transaction decision history or actor/comment data enters it (cross-role 17/18).
      expect(panel!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()
      expect(panel!.querySelector('[data-testid^="sheet-transaction-"]')).toBeNull()
      expect(panel!.querySelector('[data-testid^="inline-decisions-"]')).toBeNull()
      expect(panel!.textContent).not.toContain('User 1')
    })

    it('renders every stage unavailable when the manager read answers without stageApprovedTotals (UXC-7F-05)', async () => {
      // The read itself succeeded, but the payload carries no aggregate (old/malformed server
      // response). The pre-7F fallback would have derived the CC stage from the scoped set — that
      // partial sum must never be presented as a whole-sheet total, so every stage stays honestly
      // unavailable.
      const wrapper = await mountQueueWithSheetTotals(
        [queueItem('tx-1', { managerState: 'DECIDED_BY_MANAGER', managerDecision: managerDecision('d-1', 'APPROVE') })],
        null
      )
      await openSheetModal(wrapper)
      await openTab('approvals')

      for (const stage of ['costCenter', 'senior', 'finance'] as const) {
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
      }
    })

    it('renders zero server totals as known zeros, distinct from unavailable and from "none yet" (UXC-7F-05)', async () => {
      const wrapper = await mountQueueWithSheetTotals(
        [queueItem('tx-1')],
        stageTotals({
          costCenterApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
          seniorApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
          financeApproved: { amountMinor: 0, currency: 'IRR', minorUnit: 0 },
        })
      )
      await openSheetModal(wrapper)
      await openTab('approvals')

      for (const stage of ['costCenter', 'senior', 'finance'] as const) {
        const total = document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)
        expect(total, `a zero ${stage} total is a real answer`).not.toBeNull()
        expect(total!.textContent).toContain('IRR')
        expect(asciiDigits(total!.textContent)).toContain('0')
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).toBeNull()
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-none"]`)).toBeNull()
      }
    })

    it('does not re-derive stage totals from per-transaction detail reads (UXC-7F-05)', async () => {
      // Both scoped transactions carry Senior approvals proven by detail reads — under the pre-7F
      // derivation this produced a Senior stage total. The Approvals aggregate now comes only
      // from the manager read's whole-sheet field; with that field absent, every stage stays
      // honestly unavailable even though the detail reads answered.
      const wrapper = await mountQueueWithSheetTotals(
        [
          queueItem('tx-1', { managerState: 'DECIDED_BY_MANAGER', managerDecision: managerDecision('d-1', 'APPROVE') }),
          queueItem('tx-2', { managerState: 'DECIDED_BY_MANAGER', managerDecision: managerDecision('d-2', 'APPROVE') }),
        ],
        null
      )
      seedTransactionDetails([
        { id: 'tx-1', decisions: [{ ...managerDecision('d-sen-1', 'APPROVE'), stage: 'SENIOR' }] },
        { id: 'tx-2', decisions: [{ ...managerDecision('d-sen-2', 'APPROVE'), stage: 'SENIOR' }] }
      ])
      await openSheetModal(wrapper)
      await openTab('approvals')

      for (const stage of ['costCenter', 'senior', 'finance'] as const) {
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
      }
    })

    it('renders every stage honestly unavailable when neither the manager read nor queue rows prove the set', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')], {}, 'fail')
      await openSheetModal(wrapper)
      await openTab('approvals')

      for (const stage of ['costCenter', 'senior', 'finance'] as const) {
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-unavailable"]`)).not.toBeNull()
        expect(document.body.querySelector(`[data-testid="sheet-approvals-stage-${stage}-total"]`)).toBeNull()
      }
    })

    it('Overview stays sheet-level and History stays an honest sheet-level empty state', async () => {
      const wrapper = await mountQueue([queueItem('tx-1')])
      await openSheetModal(wrapper)

      await openTab('overview')
      const overview = document.body.querySelector('[data-testid="cc-tab-overview"]')
      expect(overview).not.toBeNull()
      expect(overview!.textContent).toContain('Sheet sheet-1')
      expect(overview!.textContent).toContain('User One')
      expect(overview!.querySelector('[data-testid^="transaction-review-card-"]')).toBeNull()

      await openTab('history')
      expect(document.body.querySelector('[data-testid="cc-tab-history-empty"]')).not.toBeNull()
      expect(document.body.querySelector('[data-testid="cc-tab-history-empty"]')!.textContent)
        .toContain('reviews.sheetHistory.unavailable')
      expect(document.body.querySelector('[data-testid="cc-tab-history-empty"]')!.textContent)
        .not.toContain('reviews.noTransactionsInQueue')
    })
  })
  })

  describe('SeniorQueueView', () => {
    async function mountSeniorQueue(items: unknown[]) {
      const reviewsStore = useReviewsStore()
      reviewsStore.seniorQueue = { items, total: items.length, page: 1, pageSize: 100 } as never
      reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
      // The per-sheet read supplies the acting senior's transactions when a test opens the modal.
      // Tests that do not stub it render the modal's honest unavailable state (FR-007-014).
      ocsRequest.mockImplementation((config: { url?: string }) =>
        /\/queues\/senior\/sheets\//.test(config?.url ?? '')
          ? Promise.reject(new Error('senior sheet read unavailable'))
          : Promise.resolve(emptyOcsResponse(config))
      )
      return mountView(SeniorQueueView, '/reviews/senior')
    }

    afterEach(() => {
      // The sheet modal is a teleported overlay; clear the body so it cannot leak into the next test.
      document.body.innerHTML = ''
    })

    const COST_CENTER_APPROVAL = {
      id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
      actor: { userId: 'mgr1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
      decidedAt: '2026-09-20T10:00:00Z'
    }

    /** One `GET /queues/senior/sheets` row (UX-007-07), including completed sheets. */
    function seniorSheetRow(id: string, overrides: Record<string, unknown> = {}) {
      return {
        id, title: `Sheet ${id}`, ownerId: 'user1', ownerDisplayName: 'User One',
        currency: 'IRR', submittedTotalMinor: 50000,
        submittedTotal: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
        submittedAt: '2026-09-20T10:00:00Z', version: 1,
        allTransactionsCostCenterDecided: true, reviewStatus: 'PENDING_REVIEW',
        latestActivityAt: '2026-09-20T10:00:00Z',
        progress: { awaitingSenior: 2, decidedBySenior: 0, total: 2 },
        ...overrides
      }
    }

    /** One transaction row as the per-sheet Senior read returns it. */
    function seniorTransaction(overrides: Record<string, unknown> = {}) {
      return {
        id: 'tx-1', sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
        finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0,
        latestDecision: COST_CENTER_APPROVAL,
        seniorState: 'AWAITING_SENIOR_DECISION', seniorDecision: null,
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Expense',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Travel' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        },
        ...overrides
      }
    }

    /** Serves the per-sheet read for `sheet-1` with the given transaction set. */
    function mockSeniorSheetRead(transactions: unknown[]) {
      ocsRequest.mockImplementation((config: { url?: string }) => {
        const url = config?.url ?? ''
        if (url.includes('/queues/senior/sheets/sheet-1')) {
          return Promise.resolve(ocsEnvelope({
            sheet: seniorSheetRow('sheet-1'),
            progress: { awaitingSenior: transactions.length, decidedBySenior: 0, total: transactions.length },
            transactions
          }))
        }
        return Promise.resolve(emptyOcsResponse(config))
      })
    }

    it('should render senior queue grouped into one sheet row with advisory cost center decisions visible in the sheet modal', async () => {
      const wrapper = await mountSeniorQueue([seniorSheetRow('sheet-1')])

      expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
      // FR-007-001: one sheet row per sheet — no flat transaction rows on the queue surface.
      expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
      expect(wrapper.find('[data-testid="sheet-pending-count"]').text().trim()).not.toBe('')

      // Opening the sheet shows the modal over the still-mounted queue (FR-007-002), and the Cost
      // Center decision is visible to the senior *in full* at transaction level only (FR-034 /
      // FR-007-004) — never rolled up into a sheet verdict. The list row carries no transactions,
      // so the modal reads them from the per-sheet endpoint.
      mockSeniorSheetRead([seniorTransaction(), seniorTransaction({ id: 'tx-2' })])
      await wrapper.find('[data-testid="sheet-title"]').trigger('click')
      await flushPromises()
      const dialog = document.body.querySelector('[data-testid="sheet-details-modal"]')
      expect(dialog).not.toBeNull()
      // UXC-8-03: the Cost Center decision renders in the shared card's stage indicator row — one
      // Cost Center indicator per transaction, never a sheet verdict.
      const ccIndicators = dialog?.querySelectorAll('[data-testid="transaction-card-decision-costCenter"]')
      expect(ccIndicators?.length).toBe(2)
      expect(ccIndicators?.[0]?.textContent ?? '').toContain('reviews.outcome.approved')
    })

    // FR-034 / FR-035: the senior may decide independently, without being forced to acknowledge or
    // respond to the cost-centre decision.
    //
    // This block's former flat-table/narrow-card assertion (`expectCardAgreesWithTable` over the
    // `advisoryDecision` column) described a responsive transaction table the sheet-first queue no
    // longer renders (FR-007-001). It is updated in place to the sheet presentation's own invariants:
    // one row per server sheet-list row, the pending badge (never "Review Completed" unless the
    // server supplies it), and identity from the list row itself (UX-007-07).
    it('keeps the queue sheet-first: one server sheet row with its list-row identity and pending badge', async () => {
      const wrapper = await mountSeniorQueue([
        seniorSheetRow('sheet-1', { title: 'Travel sheet' })
      ])

      expect(wrapper.findAll('[data-testid^="sheet-row-"]')).toHaveLength(1)
      expect(wrapper.find('[data-testid="sheet-status-badge"]').exists()).toBe(true)

      // Identity comes from the list row itself, so the modal can render it even when the
      // per-sheet transaction read is unavailable.
      await wrapper.find('[data-testid="sheet-title"]').trigger('click')
      await flushPromises()
      const dialog = document.body.querySelector('[data-testid="sheet-details-modal"]')
      expect(dialog?.querySelector('[data-testid="sheet-details-modal-title"]')?.textContent)
        .toBe('Travel sheet')
    })

    it('should allow senior to decide independently without forced acknowledgment', async () => {
      const wrapper = await mountSeniorQueue([seniorSheetRow('sheet-1')])

      // The queue offers the senior a way in — the sheet opens over the mounted queue — and it is
      // not gated on the cost-centre decision.
      expect(wrapper.find('[data-testid="sheet-title"]').exists()).toBe(true)
      // Nothing in the view asks the senior to acknowledge anything.
      expect(wrapper.find('[data-testid="advisory-acknowledge"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="acknowledge-advisory"]').exists()).toBe(false)
      // And the whole rendered view carries no acknowledgment affordance by any other name either.
      expect(wrapper.text().toLowerCase()).not.toContain('acknowledge')

      // The senior's own three choices are available on the decision form itself, on the page the
      // queue's action leads to. The transaction has to be in the store, or the detail view has
      // nothing to render and dereferences an absent revision.
      const reviewsStore = useReviewsStore()
      reviewsStore.selectedTransaction = {
        id: 'tx-1', sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
        finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0,
        latestDecision: COST_CENTER_APPROVAL,
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Expense',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Travel' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        },
        allowedActions: { canApprove: true, canReject: true, canReturn: true },
        decisions: [COST_CENTER_APPROVAL],
        evidence: []
      } as never
      reviewsStore.fetchTransactionForReview = vi.fn().mockResolvedValue(undefined)

      const detail = await mountView(TransactionReviewDetailView, '/reviews/senior/tx-1')
      const decisionForm = detail.findComponent(DecisionForm)
      expect(decisionForm.exists(), 'the senior reaches a decision form').toBe(true)
      const actions = decisionForm.props('allowedActions') as Record<string, boolean>
      expect(actions?.canApprove).toBe(true)
      expect(actions?.canReject).toBe(true)
      expect(actions?.canReturn).toBe(true)
    })
  })

  // The `FinanceQueueView` block that used to live here moved to
  // `tests/Frontend/Component/FinanceReview.spec.ts`. The shared finance queue is US3 (T062/T063
  // deliver it, T058 owns its Vue tests, T065/T193 own the view), and an assertion about US3
  // behaviour in a US2 file counts against US2 for as long as US3 is in progress. Its one red
  // assertion was also asserting a `queue-item` test id that no component has ever emitted.

  describe('TransactionReviewDetailView', () => {
    /**
     * A transaction under review, with the evidence a cost-centre manager is being asked to approve.
     */
    function reviewedTransaction(overrides: Record<string, unknown> = {}) {
      return {
        id: 'tx-1',
        sheetId: 'sheet-1',
        stage: 'COST_CENTER',
        status: 'AWAITING_COST_CENTER_REVIEW',
        finalOutcome: null,
        evidenceRequired: false,
        evidenceCount: 1,
        version: 0,
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Office supplies',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Office Supplies' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        },
        latestDecision: {
          id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
          actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' },
          decidedAt: '2026-09-20T10:00:00Z'
        },
        allowedActions: { canApprove: true, canReject: true, canReturn: true },
        decisions: [],
        evidence: [{
          id: 'ev-1', transactionId: 'tx-1', revisionId: 'rev-1', name: 'receipt.pdf',
          mediaType: 'application/pdf', sizeBytes: 1024, active: true, uploadedBy: 'user1',
          uploadedAt: '2026-09-20T10:00:00Z'
        }],
        ...overrides
      }
    }

    async function mountReview(transaction: Record<string, unknown>, stage = 'cost-center') {
      const reviewsStore = useReviewsStore()
      reviewsStore.selectedTransaction = transaction as never
      reviewsStore.fetchTransactionForReview = vi.fn().mockResolvedValue(undefined)
      return mountView(TransactionReviewDetailView, `/reviews/${stage}/tx-1`)
    }

    it('links back to the dashboard and the owning queue (FR-004)', async () => {
      const wrapper = await mountReview(reviewedTransaction(), 'senior')
      const breadcrumbs = wrapper.findAll('.breadcrumbs a')

      expect(breadcrumbs.map((link) => link.attributes('href'))).toEqual([
        '#/',
        '#/reviews/senior'
      ])
    })

    it('should display transaction details with evidence and history', async () => {
      // With a prior decision in hand, so "and history" is a claim about content rather than about
      // a container being present. The original asserted three hooks existed, one of which
      // (`decision-history`) no component has ever emitted — the history is `prior-decision-history`
      // with a `decision-history-list` inside it.
      const wrapper = await mountReview(reviewedTransaction({
        decisions: [{
          id: 'd-0', stage: 'COST_CENTER', decision: 'REJECT', explanation: 'Needs a clearer receipt',
          actor: { userId: 'user0', displayName: 'Earlier Manager', role: 'COST_CENTER_MANAGER' },
          decidedAt: '2026-09-19T10:00:00Z'
        }]
      }))

      expect(wrapper.find('[data-testid="transaction-detail"]').exists()).toBe(true)

      // The evidence the decision is about, by name — not merely that a list exists.
      const evidence = wrapper.find('[data-testid="evidence-list"]')
      expect(evidence.exists()).toBe(true)
      expect(evidence.text()).toContain('receipt.pdf')

      // And the prior decision, with the explanation that travels with it.
      const history = wrapper.find('[data-testid="prior-decision-history"]')
      expect(history.exists()).toBe(true)
      expect(history.find('[data-testid="decision-item-d-0"]').text()).toContain('Needs a clearer receipt')
      expect(history.find('[data-testid="decision-item-d-0"]').text()).toContain('Earlier Manager')
    })

    it('should mark the cost-centre decision advisory on the senior detail reached by route (T053)', async () => {
      // FR-034. The router delivers the stage lowercase (`/reviews/senior/tx-1`),
      // while decisions carry uppercase stages — the badge must survive that
      // boundary. Mounting with an uppercase prop would assert nothing about it.
      const wrapper = await mountReview(reviewedTransaction({
        decisions: [{
          id: 'd-0', stage: 'COST_CENTER', decision: 'APPROVE', explanation: 'Within budget',
          actor: { userId: 'user0', displayName: 'Earlier Manager', role: 'COST_CENTER_MANAGER' },
          decidedAt: '2026-09-19T10:00:00Z'
        }]
      }), 'senior')

      expect(wrapper.find('[data-testid="advisory-badge"]').exists()).toBe(true)
    })

    it('should show evidence preview and download', async () => {
      // FR-060. The inline `evidence-preview` is an `<img>`, so it is image-only by construction and
      // the original assertion — that it exists for a PDF — could never hold. The affordance that
      // applies to every active evidence type is the preview *button*, and that is what is asserted
      // here, together with download. An image case is asserted separately below so the inline
      // preview is not left unchecked.
      const wrapper = await mountReview(reviewedTransaction())

      expect(wrapper.find('[data-testid="evidence-preview-btn"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="evidence-download-btn"]').exists()).toBe(true)
      // Both carry an accessible name rather than being icon-only controls.
      for (const id of ['evidence-preview-btn', 'evidence-download-btn']) {
        expect(wrapper.find(`[data-testid="${id}"]`).text().trim(), `${id} must be named`).not.toBe('')
      }
    })

    /**
     * T361. **This test changed shape, and the reason is the fix rather than a weakened assertion.**
     *
     * The preview used to be `<img :src="generateOcsUrl(…)">`, so the element existed synchronously
     * and this assertion passed. That request carried no `OCS-APIRequest` header and was answered
     * **`412 CSRF check failed` for every user** — the element was present, the image was never
     * displayed, and nothing said so. The card now fetches the bytes and binds an object URL, so the
     * `<img>` appears **once the bytes arrive**. Asserting its presence without awaiting the fetch
     * would be asserting the pre-fix behaviour.
     *
     * The requirement is still asserted, and additionally: the request must carry the header (the
     * whole defect), and both intermediate states must be visible rather than blank.
     */
    it('should fetch image evidence with the OCS header and render it inline', async () => {
      const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        blob: () => Promise.resolve(new Blob(['png-bytes'], { type: 'image/png' }))
      })
      vi.stubGlobal('fetch', fetchMock)
      const createObjectURL = vi.fn(() => 'blob:preview')
      vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL: vi.fn() })

      try {
        const image = await mountReview(reviewedTransaction({
          evidence: [{
            id: 'ev-img', transactionId: 'tx-1', revisionId: 'rev-1', name: 'receipt.png',
            mediaType: 'image/png', sizeBytes: 2048, active: true, uploadedBy: 'user1',
            uploadedAt: '2026-09-20T10:00:00Z'
          }]
        }))
        await flushPromises()

        // **The header is the defect.** A navigation cannot set it, which is why the request was a
        // navigation in the first place.
        expect(fetchMock).toHaveBeenCalled()
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
        expect(String(url)).toContain('/evidence/ev-img/content')
        expect((init.headers as Record<string, string>)['OCS-APIRequest']).toBe('true')

        const preview = image.find('[data-testid="evidence-preview"]')
        expect(preview.exists(), 'image evidence previews inline once the bytes arrive').toBe(true)
        expect(preview.attributes('src')).toBe('blob:preview')
        // And the alternative carries a real `alt`, so it is not announced as an unlabelled image.
        expect(preview.attributes('alt')).toBe('receipt.png')
      } finally {
        vi.unstubAllGlobals()
      }
    })

    it('should say so when an inline preview cannot be loaded', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, blob: () => Promise.resolve(new Blob()) }))
      vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(), revokeObjectURL: vi.fn() })

      try {
        const wrapper = await mountReview(reviewedTransaction({
          evidence: [{
            id: 'ev-bad', transactionId: 'tx-1', revisionId: 'rev-1', name: 'broken.png',
            mediaType: 'image/png', sizeBytes: 2048, active: true, uploadedBy: 'user1',
            uploadedAt: '2026-09-20T10:00:00Z'
          }]
        }))
        await flushPromises()

        // A failed `<img src>` renders an empty box, which reads as "this receipt is blank" rather
        // than "we could not load it". FR-060 makes preview something a reviewer relies on to approve.
        expect(wrapper.find('[data-testid="evidence-error"]').exists()).toBe(true)
        expect(wrapper.find('[data-testid="evidence-error"]').attributes('role')).toBe('alert')
        expect(wrapper.find('[data-testid="evidence-preview"]').exists()).toBe(false)
      } finally {
        vi.unstubAllGlobals()
      }
    })

    it('should issue a header-carrying request when the download button is pressed', async () => {
      const fetchMock = vi.fn().mockResolvedValue({
        ok: true,
        blob: () => Promise.resolve(new Blob(['pdf-bytes'], { type: 'application/pdf' }))
      })
      vi.stubGlobal('fetch', fetchMock)
      vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(() => 'blob:download'), revokeObjectURL: vi.fn() })

      try {
        const wrapper = await mountReview(reviewedTransaction())
        await flushPromises()
        fetchMock.mockClear()

        await wrapper.find('[data-testid="evidence-download-btn"]').trigger('click')
        await flushPromises()

        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit]
        expect(String(url)).toContain('/content')
        // `attachment` is what makes it a save rather than an inline render, and it is a **query
        // parameter** on the same endpoint — the disposition moved off the URL and into the request,
        // so losing it would silently turn every download into a preview.
        expect(String(url)).toContain('disposition=attachment')
        expect((init.headers as Record<string, string>)['OCS-APIRequest']).toBe('true')
      } finally {
        vi.unstubAllGlobals()
      }
    })

    it('should mark deactivated evidence without offering a preview', async () => {
      vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true, blob: () => Promise.resolve(new Blob()) }))
      vi.stubGlobal('URL', { ...URL, createObjectURL: vi.fn(), revokeObjectURL: vi.fn() })

      try {
        // FR-060 asks for the active state to be visible, and an inactive item is history rather than
        // something to open — so it is marked and loses the preview affordance but keeps its download.
        const deactivated = await mountReview(reviewedTransaction({
          evidence: [{
            id: 'ev-off', transactionId: 'tx-1', revisionId: 'rev-1', name: 'withdrawn.pdf',
            mediaType: 'application/pdf', sizeBytes: 1024, active: false, uploadedBy: 'user1',
            uploadedAt: '2026-09-20T10:00:00Z', deactivatedAt: '2026-09-21T10:00:00Z'
          }]
        }))
        await flushPromises()

        expect(deactivated.find('[data-testid="deactivated-badge"]').exists()).toBe(true)
        expect(deactivated.find('[data-testid="evidence-preview-btn"]').exists()).toBe(false)
        // Still listed: FR-059 makes deactivation a mark, not a deletion.
        expect(deactivated.find('[data-testid="evidence-list"]').text()).toContain('withdrawn.pdf')
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })

  describe('DecisionForm', () => {
    /**
     * Sets the decision through the component's `update:modelValue`.
     *
     * `NcSelect` is deep-imported here, so the real one renders: a `v-select` whose root is a `div`
     * and whose dropdown opens on real focus and click-outside, neither of which happens under
     * jsdom. `setValue()` on the root therefore throws `cannot be called on DIV`, and the test that
     * did so never chose a decision at all — which is why the explanation error it went on to assert
     * was absent. Emitting the event a selection emits reaches the same state the control would.
     */
    async function chooseDecision(wrapper: VueWrapper, decision: string) {
      const select = wrapper.findComponent('[data-testid="decision-select"]')
      await (select as VueWrapper).vm.$emit('update:modelValue', decision)
      await wrapper.vm.$nextTick()
    }

    /**
     * Submits the form the way a user does, and waits for the round trip.
     *
     * The submit control is `type="submit"` inside a `<form @submit.prevent>`, so `trigger('click')`
     * on it raises a click and nothing else — jsdom does not submit a form from a synthetic click on
     * its submit button, which is why the original assertions saw no event at all. And
     * `submitDecision()` awaits the store before emitting, so the emit is not observable until the
     * promise chain has drained.
     */
    async function submitForm(wrapper: VueWrapper) {
      await wrapper.find('form').trigger('submit')
      await flushPromises()
    }

    /**
     * The text the explanation control announces, read through its own `aria-describedby`.
     *
     * The requirement is not that a message exists somewhere in the document but that the control
     * points at it, so the ids the control names are what get read back.
     */
    function announcedExplanationError(wrapper: VueWrapper): string {
      const control = wrapper.find('[data-testid="explanation-input"]')
      const ids = (control.attributes('aria-describedby') ?? '').split(/\s+/).filter(Boolean)
      return ids.map((id) => wrapper.find(`#${id}`).text()).join(' ').trim()
    }

    /**
     * The options the decision control is actually offering, read from the component rather than
     * from the DOM.
     *
     * `NcSelect` is deep-imported, so the real control renders and its dropdown opens only on a real
     * focus, which jsdom does not produce — the option list exists as a prop and nowhere in the
     * markup. `findComponent` is typed as `WrapperLike` because the selector is a string, so the cast
     * is what gives back `props`.
     */
    function decisionOptions(wrapper: VueWrapper): Array<{ value: string }> {
      const select = wrapper.findComponent('[data-testid="decision-select"]') as VueWrapper
      const vm = select.vm as unknown as { options: Array<{ value: string }> }

      return vm.options
    }

    /**
     * FR-039: a finance officer on a `SENIOR_REJECTED` transaction may reject or return, and must not
     * be offered Approve — which `FinanceDecisionPolicy` refuses and the command path answers 409 on.
     *
     * **The fixture is server-shaped, and that is the whole point of the test.** `allowedActions` used
     * to be built by hand in every spec in this repository, and the filter looked it up as
     * `item.value.toLowerCase()` — `approve`, `reject`, `return` — while
     * `TransactionPayloadFormatter::allowedActions()` emits `canApprove`, `canReject`, `canReturn`.
     * Every hand-written fixture therefore agreed with the broken client, and the option list could
     * not shrink. The values below are the six keys that method emits, always, for every transaction.
     */
    it('should offer only the decisions the server granted for a senior-rejected transaction', async () => {
      const wrapper = await mountView(DecisionForm, '/', {
        stage: 'FINANCE',
        transactionId: 'tx-1',
        allowIndependentDecision: false,
        // What the server emits for a finance officer on `SENIOR_REJECTED`: `canFinanceApprove()`
        // is false there, while reject and return are granted.
        allowedActions: {
          canApprove: false,
          canReject: true,
          canReturn: true,
          canEdit: false,
          canDelete: false,
          canCorrect: false
        }
      })

      const values = decisionOptions(wrapper).map((option) => option.value)

      expect(values).toEqual(['REJECT', 'RETURN'])
      expect(values).not.toContain('APPROVE')
    })

    it('should offer every decision the server granted for a finance-review transaction', async () => {
      const wrapper = await mountView(DecisionForm, '/', {
        stage: 'FINANCE',
        transactionId: 'tx-1',
        allowIndependentDecision: false,
        allowedActions: {
          canApprove: true,
          canReject: true,
          canReturn: true,
          canEdit: false,
          canDelete: false,
          canCorrect: false
        }
      })

      // The counterpart to the case above: a filter that removed everything would satisfy that one
      // too, so both directions are asserted.
      expect(decisionOptions(wrapper).map((option) => option.value)).toEqual(['APPROVE', 'REJECT', 'RETURN'])
    })

    it('should offer nothing when the server granted no decision', async () => {
      const wrapper = await mountView(DecisionForm, '/', {
        stage: 'FINANCE',
        transactionId: 'tx-1',
        allowIndependentDecision: false,
        allowedActions: {
          canApprove: false,
          canReject: false,
          canReturn: false,
          canEdit: false,
          canDelete: false,
          canCorrect: false
        }
      })

      expect(decisionOptions(wrapper)).toEqual([])
      // And the form cannot be submitted into a decision the server would refuse.
      await submitForm(wrapper)
      expect(wrapper.emitted('submit-decision')).toBeFalsy()
    })

    /**
     * The client's key set is asserted against the **producer**, not against a copy of it, so a
     * rename in `TransactionPayloadFormatter::allowedActions()` fails here instead of silently
     * restoring the "no option is ever removed" behaviour. This file already reads source text for
     * the route table, so it is the same technique applied to the capability names.
     */
    it('should look up exactly the capability names the payload formatter emits', () => {
      const formatter = readFileSync(
        join(process.cwd(), 'lib/Infrastructure/Http/TransactionPayloadFormatter.php'),
        'utf-8'
      )
      const emitted = [...formatter.matchAll(/'(can[A-Z]\w+)'\s*=>/g)].map((match) => match[1])

      expect(emitted.length).toBeGreaterThan(0)
      expect(new Set(emitted)).toEqual(new Set(ALLOWED_ACTION_KEYS))
      expect(emitted).toContain('canApprove')
      expect(emitted).toContain('canReject')
      expect(emitted).toContain('canReturn')
    })

    // FR-033 / FR-035: rejection and return MUST require an explanation.
    it('should validate mandatory explanation for REJECT', async () => {
      const wrapper = await mountView(DecisionForm, '/', { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false, allowedActions: { canApprove: true, canReject: true, canReturn: true } })

      await chooseDecision(wrapper, 'REJECT')
      await wrapper.find('[data-testid="explanation-input"]').setValue('')
      await submitForm(wrapper)

      // The control is marked invalid and announces the reason, so a submit button that has become
      // disabled is not the only signal the manager gets.
      expect(wrapper.find('[data-testid="explanation-input"]').classes().join(' ')).toContain('error')
      expect(announcedExplanationError(wrapper)).toContain('reviews.explanationRequired')
      // And the decision was not emitted.
      expect(wrapper.emitted('submit-decision')).toBeFalsy()
    })

    it('should validate mandatory explanation for RETURN', async () => {
      const wrapper = await mountView(DecisionForm, '/', { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false, allowedActions: { canApprove: true, canReject: true, canReturn: true } })

      await chooseDecision(wrapper, 'RETURN')
      await wrapper.find('[data-testid="explanation-input"]').setValue('')
      await submitForm(wrapper)

      expect(wrapper.find('[data-testid="explanation-input"]').classes().join(' ')).toContain('error')
      expect(announcedExplanationError(wrapper)).toContain('reviews.explanationRequired')
      expect(wrapper.emitted('submit-decision')).toBeFalsy()
    })

    // The requirement is only that reject and return demand one, so approving without an
    // explanation must stay possible.
    it('should allow APPROVE without explanation', async () => {
      const wrapper = await mountView(DecisionForm, '/', { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false, allowedActions: { canApprove: true, canReject: true, canReturn: true } })

      await chooseDecision(wrapper, 'APPROVE')

      expect(wrapper.find('[data-testid="explanation-input"]').classes().join(' ')).not.toContain('error')
      expect(announcedExplanationError(wrapper)).toBe('')
      // The submit button is enabled, and the decision goes through.
      expect(wrapper.find('[data-testid="submit-decision"]').attributes('disabled')).toBeUndefined()
      await submitForm(wrapper)
      expect(wrapper.emitted('submit-decision')).toBeTruthy()
    })

    /**
     * Every decision carries a command identity, so a replayed request is recognised rather than
     * recorded twice.
     *
     * The original assertion could not have found it here even had the behaviour been missing: the
     * form is a presentational component that delegates to `reviewsStore.recordDecision` →
     * `reviewsApi.recordDecision` → `mutate()`, and it is `mutate()` at `src/services/http.ts:67`
     * that puts `X-PettyCash-Command-ID` on the wire. The identity the component *does* own is the
     * one it generates and hands over, so that is what is asserted.
     */
    it('should include X-PettyCash-Command-ID header', async () => {
      const wrapper = await mountView(DecisionForm, '/', { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false, allowedActions: { canApprove: true, canReject: true, canReturn: true } })

      await chooseDecision(wrapper, 'APPROVE')
      await submitForm(wrapper)

      expect(wrapper.emitted('submit-decision')).toBeTruthy()
      const payload = wrapper.emitted('submit-decision')?.[0]?.[0] as { commandId?: string } | undefined
      expect(payload).toHaveProperty('commandId')
      // A command identity has to be a real one, not an empty string that would satisfy `toHaveProperty`.
      expect(payload?.commandId).toMatch(/^[0-9a-f-]{36}$/)
    })

    it('records a decision when crypto.randomUUID is unavailable', async () => {
      stubCryptoWithoutRandomUUID()
      try {
        const wrapper = await mountView(DecisionForm, '/', { stage: 'COST_CENTER', transactionId: 'tx-1', allowIndependentDecision: false, allowedActions: { canApprove: true, canReject: true, canReturn: true } })

        await chooseDecision(wrapper, 'APPROVE')
        await submitForm(wrapper)

        expect(wrapper.emitted('submit-decision')).toBeTruthy()
        const payload = wrapper.emitted('submit-decision')?.[0]?.[0] as { commandId?: string } | undefined
        expect(payload?.commandId).toMatch(UUID_V4)
        // The identity the component emits is the one that travels on the wire, not a second one
        // generated inside `mutate()`.
        expect(ocsRequest).toHaveBeenCalledWith(
          expect.objectContaining({
            headers: expect.objectContaining({ 'X-PettyCash-Command-ID': payload?.commandId })
          })
        )
      } finally {
        vi.unstubAllGlobals()
      }
    })
  })

  describe('PriorDecisionHistory', () => {
    const COST_CENTER_DECISION = {
      id: 'd-cost_center-approve', stage: 'COST_CENTER', decision: 'APPROVE', explanation: 'OK',
      actor: { userId: 'user-1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
      decidedAt: '2026-09-20T10:00:00Z'
    }
    const SENIOR_DECISION = {
      id: 'd-senior-reject', stage: 'SENIOR', decision: 'REJECT', explanation: 'Not valid',
      actor: { userId: 'user-1', displayName: 'Senior 1', role: 'SENIOR_MANAGER' },
      decidedAt: '2026-09-20T11:00:00Z'
    }

    it('should display all prior decisions with full details', async () => {
      const wrapper = await mountView(PriorDecisionHistory, '/', {
        decisions: [COST_CENTER_DECISION, SENIOR_DECISION]
      })

      // Each item carries its own id, which is what lets a later decision be told from an earlier
      // one. The original asked for `[data-testid="decision-item"]`, which matches nothing, because
      // the component emits `decision-item-{id}`.
      const items = wrapper.findAll('[data-testid^="decision-item-"]')
      expect(items).toHaveLength(2)
      expect(wrapper.find('[data-testid="decision-item-d-cost_center-approve"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="decision-item-d-senior-reject"]').exists()).toBe(true)

      // "Full details" is the requirement (FR-034: visible with full details), so each decision has
      // to carry its stage, its outcome, who decided it and in what role, its explanation and when.
      for (const decision of [COST_CENTER_DECISION, SENIOR_DECISION]) {
        const item = wrapper.find(`[data-testid="decision-item-${decision.id}"]`)
        const text = item.text()
        expect(text, `${decision.id} must show its stage`).toContain(`reviews.stage.${decision.stage.toLowerCase()}`)
        expect(text, `${decision.id} must show its outcome`).toContain(`reviews.decision.${decision.decision.toLowerCase()}`)
        expect(text, `${decision.id} must show who decided`).toContain(decision.actor.displayName)
        expect(text, `${decision.id} must show the role`).toContain(`roles.${decision.actor.role.toLowerCase()}`)
        expect(text, `${decision.id} must show its explanation`).toContain(decision.explanation)
      }
    })

    it('should state plainly when there are no prior decisions', async () => {
      const wrapper = await mountView(PriorDecisionHistory, '/', { decisions: [] })
      expect(wrapper.findAll('[data-testid^="decision-item-"]')).toHaveLength(0)
      // The component states it through `NcEmptyContent`'s description rather than through a hook of
      // its own, so the empty state is asserted by the text it puts in front of the reader.
      expect(wrapper.text()).toContain('reviews.noPriorDecisions')
    })

    // FR-034: a cost-centre decision is *advisory* in the senior stage — visible in full, but not
    // determining the outcome, and the senior manager is not forced to respond to it.
    it('should show cost center decisions as advisory in senior stage', async () => {
      const wrapper = await mountView(PriorDecisionHistory, '/', {
        decisions: [COST_CENTER_DECISION],
        currentStage: 'SENIOR'
      })

      const badge = wrapper.find('[data-testid="advisory-badge"]')
      expect(badge.exists()).toBe(true)
      // The label is the translated one. The original asserted the English word "Advisory", which no
      // amount of correct component code could satisfy: the l10n mock returns the message key, as it
      // must for an assertion to mean the same thing in any locale.
      expect(badge.text()).toContain('reviews.advisory')
      // Advisory means advisory: the detail is still shown in full, not withheld behind a summary.
      expect(wrapper.find('[data-testid="decision-item-d-cost_center-approve"]').text()).toContain('OK')
    })

    // The rule is conditional, and only asserting the positive case would let a component that
    // badges every decision as advisory pass.
    it('should not mark a cost center decision advisory in its own stage', async () => {
      const wrapper = await mountView(PriorDecisionHistory, '/', {
        decisions: [COST_CENTER_DECISION],
        currentStage: 'COST_CENTER'
      })
      expect(wrapper.find('[data-testid="advisory-badge"]').exists()).toBe(false)
      // The decision itself is still shown.
      expect(wrapper.find('[data-testid="decision-item-d-cost_center-approve"]').exists()).toBe(true)
    })

    // FR-036: the senior decision is the final management decision, not advice to anyone — so at the
    // senior stage exactly the cost-centre decision is badged and the senior's own is not.
    //
    // The stage is `SENIOR` rather than a later one on purpose: `isAdvisory` is
    // `stage === COST_CENTER && currentStage === SENIOR`, and that is the stage FR-034 describes —
    // the senior manager being shown the cost-centre advice. Asserting the badge at a later stage
    // would be asserting a rule the requirement does not state.
    it('should mark only the cost center decision advisory at the senior stage', async () => {
      const wrapper = await mountView(PriorDecisionHistory, '/', {
        decisions: [COST_CENTER_DECISION, SENIOR_DECISION],
        currentStage: 'SENIOR'
      })
      const badges = wrapper.findAll('[data-testid="advisory-badge"]')
      expect(badges).toHaveLength(1)
      expect(badges[0]?.element.closest('[data-testid^="decision-item-"]')?.getAttribute('data-testid'))
        .toBe('decision-item-d-cost_center-approve')
    })
  })

  describe('Authorization & Self-Review', () => {
    it('should allow multi-role user to review own transaction when scope/stage match', async () => {
      const authStore = useAuthStore()
      authStore.user = { userId: 'multi-role-user', displayName: 'multi-role-user', roles: ['PURCHASER', 'COST_CENTER_MANAGER'] }
      authStore.canReview = vi.fn().mockReturnValue(true)

      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = {
        items: [{ id: 'tx-1', sheetId: 'sheet-1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', finalOutcome: null, evidenceRequired: false, evidenceCount: 1, version: 0, latestDecision: { id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE', actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' }, decidedAt: '2026-09-20T10:00:00Z' }, activeRevision: { id: 'rev-1', number: 1, expenseDate: '2026-09-20', amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 }, description: 'Expense', costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' }, expenseType: { id: 'et-1', name: 'Travel' }, createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true } }],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = await mountView(CostCenterQueueView, '/reviews/cost-center')

      // The sheet-first queue is the default; the transaction-level row this test
      // describes lives in the preserved transaction fallback. The show-transactions button was
      // removed per FU-10.A, so this test exercises the sheet view instead. The transaction-level
      // surface is preserved in code but no longer reachable through the queue header.

      // FR-002/FR-008 permit a multi-role user to review their own transaction when scope and stage
      // match. The original asserted `[data-testid="queue-item"]`, which matches nothing (the view
      // emits `sheet-row-{id}` in the sheet view), and `[data-testid="decision-button"]`, which no component has ever
      // emitted — the row's action is `sheet-title`, and it leads to the page the decision is
      // taken on.
      //
      // What the *client* owns is narrow and is what is asserted here: the row is rendered and its
      // review action is present and usable. Whether the decision is permitted is the server's call —
      // T045 covers that authorization — so a client-side assertion that the view "allows" the review
      // would be claiming an enforcement the interface does not perform.
      const row = wrapper.find('[data-testid="sheet-row-sheet-1"]')
      expect(row.exists(), 'the multi-role user sees their own sheet in the queue').toBe(true)
      const action = row.find('[data-testid="sheet-title"]')
      expect(action.exists(), 'and can open it to decide').toBe(true)
      // The action is named, so it is not announced as a bare button.
      expect(action.text().trim()).not.toBe('')
    })

    it('should deny administrator without finance role from finance actions', async () => {
      const authStore = useAuthStore()
      authStore.user = { userId: 'admin-1', displayName: 'admin-1', roles: ['PURCHASER', 'ADMINISTRATOR'] }
      authStore.canReview = vi.fn().mockReturnValue(false)

      const reviewsStore = useReviewsStore()
      reviewsStore.financeQueue = { items: [], total: 0, page: 1, pageSize: 25 }
      reviewsStore.fetchFinanceQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = await mountView(FinanceQueueView, '/reviews/finance')

      expect(wrapper.find('[data-testid="finance-queue"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="decision-button"]').exists()).toBe(false)
    })
  })

  /**
   * T336: the scope gates, tested against a **server-shaped** session response.
   *
   * **Why this block exists, given the two cases above.** Those two *replace*
   * `authStore.canReview` with a stub, so whatever the real implementation answered was never
   * consulted. That is how `canReview` stayed permanently `false` for both management stages: it read
   * `capabilities.costCenterCodes`, which `SessionController` never emitted, so the list was always
   * `[]` and every scope comparison failed — with a green suite, because the tests that would have
   * noticed had stubbed the function out.
   */
  describe('auth store scope gates (FR-004, FR-005)', () => {
    /** A session response in the shape `GET /me` answers with, scope included. */
    function sessionResponse(overrides: { roles?: string[]; codes?: string[] } = {}) {
      return {
        status: 200,
        data: {
          ocs: {
            meta: { status: 'ok', statuscode: 200 },
            data: {
              userId: 'manager-1',
              displayName: 'Manager One',
              roles: overrides.roles ?? ['PURCHASER', 'COST_CENTER_MANAGER'],
              capabilities: {
                canPurchase: true,
                canReviewCostCenter: true,
                canReviewSenior: false,
                canDecideFinance: false,
                canAdminister: false
              },
              costCenterCodes: overrides.codes ?? ['CC-100']
            }
          }
        }
      }
    }

    async function loadSessionWith(response: unknown): Promise<void> {
      ocsRequest.mockImplementation((config: { url?: string }) =>
        Promise.resolve((config?.url ?? '').includes('/me') ? response : emptyOcsResponse(config))
      )
      await useAuthStore().loadSession()
    }

    it('reads the managed cost centres out of the session response', async () => {
      await loadSessionWith(sessionResponse({ codes: ['CC-100', 'CC-200'] }))

      const authStore = useAuthStore()
      expect(authStore.managedCostCenterCodes).toEqual(['CC-100', 'CC-200'])
      expect(authStore.hasCostCenterScope('CC-100')).toBe(true)
      expect(authStore.hasCostCenterScope('CC-999')).toBe(false)
    })

    it('opens the cost-centre gate for an assigned manager and shuts it for an unassigned one', async () => {
      await loadSessionWith(sessionResponse({ codes: ['CC-100'] }))
      const authStore = useAuthStore()

      expect(authStore.canReview('COST_CENTER', 'CC-100'), 'the centre the caller is assigned').toBe(true)
      expect(authStore.canReview('COST_CENTER', 'CC-200'), 'a centre they hold no assignment for').toBe(false)
    })

    it('opens the senior gate only for a senior manager within scope', async () => {
      await loadSessionWith(sessionResponse({ roles: ['PURCHASER', 'SENIOR_MANAGER'], codes: ['CC-100'] }))
      const authStore = useAuthStore()

      expect(authStore.canReview('SENIOR', 'CC-100')).toBe(true)
      expect(authStore.canReview('SENIOR', 'CC-200')).toBe(false)
      // The same caller holds no cost-centre-manager role, so that stage stays shut.
      expect(authStore.canReview('COST_CENTER', 'CC-100')).toBe(false)
    })

    /**
     * The dangerous direction. A manager whose scope list is empty manages **nothing** — reading an
     * empty scope as "no restriction" would open the gate for every cost centre in the installation,
     * which is the same reason `ReportScope::forActor()` drops null cost-centre ids rather than
     * passing them on to every query.
     */
    it('treats an empty scope as no scope rather than as no restriction', async () => {
      await loadSessionWith(sessionResponse({ codes: [] }))
      const authStore = useAuthStore()

      expect(authStore.canReview('COST_CENTER', 'CC-100')).toBe(false)
      expect(authStore.canReview('COST_CENTER', '')).toBe(false)
    })

    /**
     * FR-006: every finance officer shares one V1 queue, so the finance stage is a role check with no
     * scope branch — and an administrator must not pass it, because FR-007 forbids administrator
     * status from conferring decision authority.
     */
    it('checks the finance stage by role alone and never by scope', async () => {
      await loadSessionWith(sessionResponse({ roles: ['PURCHASER', 'FINANCE'], codes: [] }))
      expect(useAuthStore().canReview('FINANCE', 'CC-100')).toBe(true)

      await loadSessionWith(sessionResponse({ roles: ['PURCHASER', 'ADMINISTRATOR'], codes: [] }))
      expect(useAuthStore().canReview('FINANCE', 'CC-100')).toBe(false)
    })

    it('refuses every stage to an unauthenticated caller', () => {
      const authStore = useAuthStore()
      authStore.setUser(null)
      authStore.managedCostCenterCodes = ['CC-100']

      expect(authStore.canReview('COST_CENTER', 'CC-100')).toBe(false)
      expect(authStore.canReview('FINANCE', 'CC-100')).toBe(false)
    })

    /**
     * The scope belongs to the session, so it goes with it. Leaving it behind would let the next
     * session on this browser pass a scope gate with the previous caller's cost centres.
     */
    it('forgets the scope on logout', async () => {
      await loadSessionWith(sessionResponse({ codes: ['CC-100'] }))
      const authStore = useAuthStore()
      expect(authStore.canReview('COST_CENTER', 'CC-100')).toBe(true)

      authStore.logout()

      expect(authStore.managedCostCenterCodes).toEqual([])
      expect(authStore.canReview('COST_CENTER', 'CC-100')).toBe(false)
    })
  })
})
