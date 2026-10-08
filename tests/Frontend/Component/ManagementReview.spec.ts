// Management Review - Vue Component Tests (RED: behavior not implemented)

import { describe, it, expect, vi, beforeEach } from 'vitest'
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

/** A well-formed but empty OCS envelope, so a mount-time load resolves instead of rejecting. */
function emptyOcsResponse(config: { url?: string }) {
  const url = config?.url ?? ''
  const data = /\/revisions(\?|$)/.test(url) ? [] : { items: [], page: 1, pageSize: 25, total: 0 }
  return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
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
        finalOutcome: null, evidenceCount: 1, version: 0,
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

    async function mountQueue(items: unknown[], overrides: Record<string, unknown> = {}) {
      const reviewsStore = useReviewsStore()
      reviewsStore.costCenterQueue = { items, total: items.length, page: 1, pageSize: 25, ...overrides } as never
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)
      return mountView(CostCenterQueueView, '/reviews/cost-center')
    }

    it('should render cost center queue with paginated transactions', async () => {
      // The original asked for `[data-testid="queue-item"]`, which matches nothing: the view emits
      // `queue-row-{id}` per row, which is the design that lets a row be told apart from its
      // neighbours. It also claimed "paginated" while seeding a single page of two items against a
      // page size of 25, so the pagination control it went on to imply was never rendered. Both are
      // fixed here: enough items to force a second page, and the control actually asserted.
      const items = Array.from({ length: 25 }, (_, index) => queueItem(`tx-${index + 1}`))
      const wrapper = await mountQueue(items, { total: 30, page: 1, pageSize: 25 })

      expect(wrapper.find('[data-testid="cost-center-queue"]').exists()).toBe(true)
      // The view renders the page the server sent rather than slicing it again, so the first page is
      // 25 items against a total of 30 — which is two pages. (Seeding 30 into `items` and expecting 25
      // back would be asserting a client-side slice that does not exist and should not.)
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(25)
      const pagination = wrapper.find('[data-testid="queue-pagination"]')
      expect(pagination.exists(), 'a queue spanning two pages must offer pagination').toBe(true)
      expect(pagination.find('[data-testid="page-indicator"]').text()).toContain('1')
    })

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
        queueItem('tx-1'),
        queueItem('tx-2', { sheetId: 'sheet-2' })
      ])

      expectCardAgreesWithTable(wrapper, {
        rows: 2,
        // The hooks the shared cell renderer declares (`src/components/review/queueColumns.ts`), plus
        // this queue's own column hook. A hook absent from either layout is reported by name.
        hooks: [
          'data-testid="amount"',
          'data-testid="transaction-id"',
          'data-testid="sheet-id"',
          'data-testid="queue-stage"',
          'data-testid="view-details-btn"'
        ]
      })
    })

    it('should offer no pagination when the queue fits one page', async () => {
      const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2')])
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(2)
      expect(wrapper.find('[data-testid="queue-pagination"]').exists()).toBe(false)
    })

    it('should show empty state when no transactions in queue', async () => {
      const wrapper = await mountQueue([])
      expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
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
      const wrapper = await mountQueue([queueItem('tx-1'), queueItem('tx-2', { status: 'COST_CENTER_APPROVED' })])

      const rows = wrapper.findAll('[data-testid^="queue-row-"]')
      expect(rows).toHaveLength(2)
      for (const row of rows) {
        // Each row names its own status as text, so the state survives a colour-blind reader, a
        // monochrome print and a high-contrast theme.
        const badge = row.find('[data-testid^="status-badge-"]')
        expect(badge.exists(), 'a status badge must be present').toBe(true)
        expect(badge.text().trim(), 'status must be readable text, not colour alone').not.toBe('')
      }
      // And each row carries the badge derived from *its own* status, rather than all rows sharing one.
      expect(rows[0]?.find('[data-testid="status-badge-awaiting_cost_center_review"]').exists()).toBe(true)
      expect(rows[1]?.find('[data-testid="status-badge-cost_center_approved"]').exists()).toBe(true)
      expect(rows[0]?.find('[data-testid="status-badge-cost_center_approved"]').exists()).toBe(false)
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
  })

  describe('SeniorQueueView', () => {
    async function mountSeniorQueue(items: unknown[]) {
      const reviewsStore = useReviewsStore()
      reviewsStore.seniorQueue = { items, total: items.length, page: 1, pageSize: 25 } as never
      reviewsStore.fetchSeniorQueue = vi.fn().mockResolvedValue(undefined)
      return mountView(SeniorQueueView, '/reviews/senior')
    }

    const COST_CENTER_APPROVAL = {
      id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
      actor: { userId: 'mgr1', displayName: 'Manager 1', role: 'COST_CENTER_MANAGER' },
      decidedAt: '2026-09-20T10:00:00Z'
    }

    it('should render senior queue with advisory cost center decisions visible', async () => {
      const wrapper = await mountSeniorQueue([{
        id: 'tx-1', sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
        finalOutcome: null, evidenceCount: 1, version: 0,
        latestDecision: COST_CENTER_APPROVAL,
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Expense',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Travel' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        }
      }])

      expect(wrapper.find('[data-testid="senior-queue"]').exists()).toBe(true)
      // FR-034: the cost-centre decision is visible to the senior *in full*, not withheld.
      const advisory = wrapper.find('[data-testid="advisory-decision"]')
      expect(advisory.exists()).toBe(true)
      expect(advisory.text().trim()).not.toBe('')
    })

    // FR-034 / FR-035: the senior may decide independently, without being forced to acknowledge or
    // respond to the cost-centre decision.
    //
    // The original mounted `SeniorQueueView` and looked for a `DecisionForm` in it with
    // `allowIndependentDecision: true`. There is no such form in this view — a decision is recorded on
    // the transaction's own page — and `allowIndependentDecision` is a prop no view passes and no code
    // reads. So the assertion could not have held, and the requirement it names is better checked where
    // the decision is actually taken: the senior's own three choices are offered immediately, with no
    // acknowledgment control standing between the senior and them.
    /**
     * T394: the narrow card carries this queue's **own** column too.
     *
     * Worth its own case rather than being folded into the cost-centre one, because the senior queue's
     * distinguishing column (`advisoryDecision`) is rendered by a branch the shared cell renderer does
     * not own -- it is the one column each queue supplies for itself. A queue that adopted the reflow and
     * silently lost that column would still pass every test that only reads the table.
     */
    it('should carry the advisory decision in the narrow card as in the table', async () => {
      const wrapper = await mountSeniorQueue([
        {
          id: 'tx-1', sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
          finalOutcome: null, evidenceCount: 0, version: 0,
          latestDecision: COST_CENTER_APPROVAL,
          activeRevision: {
            id: 'rev-1', number: 1, expenseDate: '2026-09-20',
            amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
            costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
            expenseType: { id: 'et-1', name: 'Travel' },
            createdBy: 'u1', createdAt: '2026-09-20T10:00:00Z', active: true
          }
        }
      ])

      expectCardAgreesWithTable(wrapper, {
        rows: 1,
        hooks: [
          'data-testid="amount"',
          'data-testid="advisory-decision"',
          'data-testid="view-details-btn"'
        ]
      })
    })

    it('should allow senior to decide independently without forced acknowledgment', async () => {
      const wrapper = await mountSeniorQueue([{
        id: 'tx-1', sheetId: 'sheet-1', stage: 'SENIOR', status: 'AWAITING_SENIOR_REVIEW',
        finalOutcome: null, evidenceCount: 1, version: 0,
        latestDecision: COST_CENTER_APPROVAL,
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Expense',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Travel' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        }
      }])

      // The queue offers the senior a way in, and it is not gated on the cost-centre decision.
      expect(wrapper.find('[data-testid="view-details-btn"]').exists()).toBe(true)
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
        finalOutcome: null, evidenceCount: 1, version: 0,
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
        items: [{ id: 'tx-1', sheetId: 'sheet-1', stage: 'COST_CENTER', status: 'AWAITING_COST_CENTER_REVIEW', finalOutcome: null, evidenceCount: 1, version: 0, latestDecision: { id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE', actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' }, decidedAt: '2026-09-20T10:00:00Z' }, activeRevision: { id: 'rev-1', number: 1, expenseDate: '2026-09-20', amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 }, description: 'Expense', costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' }, expenseType: { id: 'et-1', name: 'Travel' }, createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true } }],
        total: 1,
        page: 1,
        pageSize: 25
      }
      reviewsStore.fetchCostCenterQueue = vi.fn().mockResolvedValue(undefined)

      const wrapper = await mountView(CostCenterQueueView, '/reviews/cost-center')

      // FR-002/FR-008 permit a multi-role user to review their own transaction when scope and stage
      // match. The original asserted `[data-testid="queue-item"]`, which matches nothing (the view
      // emits `queue-row-{id}`), and `[data-testid="decision-button"]`, which no component has ever
      // emitted — the row's action is `view-details-btn`, and it leads to the page the decision is
      // taken on.
      //
      // What the *client* owns is narrow and is what is asserted here: the row is rendered and its
      // review action is present and usable. Whether the decision is permitted is the server's call —
      // T045 covers that authorization — so a client-side assertion that the view "allows" the review
      // would be claiming an enforcement the interface does not perform.
      const row = wrapper.find('[data-testid="queue-row-tx-1"]')
      expect(row.exists(), 'the multi-role user sees their own transaction in the queue').toBe(true)
      const action = row.find('[data-testid="view-details-btn"]')
      expect(action.exists(), 'and can open it to decide').toBe(true)
      expect(action.attributes('disabled')).toBeUndefined()
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