// Finance Review - Vue Component Tests (US3)
//
// These tests are US3 behaviour and live here rather than in `ManagementReview.spec.ts`.
// `T287` moved the finance-queue block out of that file: the shared finance queue is a US3
// requirement (T062/T063 deliver the query and the endpoint, T058 owns this suite, T065/T193 own
// the views), and an assertion about US3 behaviour sitting in a US2 file is counted as a US2
// failure for as long as US3 work is in progress. It was the last red assertion in the US2 file.

import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount, flushPromises, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia, type Pinia } from 'pinia'
import { createRouter, createWebHashHistory, type Router } from 'vue-router'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import FinanceQueueView from '@/views/reviews/FinanceQueueView.vue'
import { expectCardAgreesWithTable } from '../../Support/cardAgreement'
import FinanceReviewView from '@/views/reviews/FinanceReviewView.vue'
import AccountView from '@/views/accounts/AccountView.vue'
import SettlementLabel from '@/components/review/SettlementLabel.vue'
import { routes } from '@/router'

// The same harness `ManagementReview.spec.ts` uses, for the same reasons: the view calls
// `useRouter()` in `setup()` and renders a `RouterLink` to `{ name: 'dashboard' }`, so without a
// router every test here would die inside `vue-router` on `undefined.resolve` before an assertion
// ran. The route table is the application's own, so a name a view links to cannot go missing here
// without failing loudly. The transport is stubbed at the axios boundary rather than per service,
// so a store method this file did not think to stub cannot reach the network and hang the run.
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

/** An OCS envelope carrying any value, i.e. the `ocs.data` shape every endpoint answers with. */
function ocsEnvelope(data: unknown) {
  return { status: 200, data: { ocs: { meta: { status: 'ok', statuscode: 200 }, data } } }
}

function createTestRouter(): Router {
  // The real route table for its names and parameters, its components replaced with inert
  // placeholders: the view under test is mounted directly, so resolving the route would only
  // re-import every real view through a dynamic import and add ~30s to the file.
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

describe('Finance Review - Vue Component Tests (US3)', () => {
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

  describe('FinanceQueueView', () => {
    /**
     * One queue row. The senior decision is carried on every item, because a senior-*rejected*
     * transaction is still in the finance queue (FR-039 forbids approving it, not seeing it), and
     * the stage that matters to finance is the one on `stage`/`status`.
     */
    function financeQueueItem(id: string, overrides: Record<string, unknown> = {}) {
      return {
        id, sheetId: 'sheet-1', stage: 'FINANCE', status: 'AWAITING_FINANCE_REVIEW',
        finalOutcome: null, evidenceCount: 1, version: 0,
        latestDecision: {
          id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
          actor: { userId: 'user1', displayName: 'User 1', role: 'COST_CENTER_MANAGER' },
          decidedAt: '2026-09-20T10:00:00Z'
        },
        activeRevision: {
          id: 'rev-1', number: 1, expenseDate: '2026-09-20',
          amount: { amountMinor: 50000, currency: 'IRR', minorUnit: 0 },
          description: 'Office supplies',
          costCenter: { id: 'cc-1', code: 'CC001', name: 'Cost Center' },
          expenseType: { id: 'et-1', name: 'Office Supplies' },
          createdBy: 'user1', createdAt: '2026-09-20T10:00:00Z', active: true
        },
        ...overrides
      }
    }

    /**
     * Mounts the queue with the transport answering the way the server would, and does **not**
     * seed the store or stub the fetch.
     *
     * The US2 file seeds `reviewsStore.costCenterQueue` and stubs `fetchCostCenterQueue`, which
     * works only because the stub is what stops the mount-time load from overwriting the seed.
     * That arrangement makes the component a passenger on state the test arranged, and it silently
     * breaks if the view ever stops loading on mount. Here the request really is issued and the
     * page really does arrive through `fetchQueue`, so the assertions are about what the component
     * does with a response rather than about what was handed to it.
     */
    async function mountFinanceQueue(items: unknown[], overrides: Record<string, unknown> = {}) {
      const page = { items, total: items.length, page: 1, pageSize: 25, ...overrides }
      ocsRequest.mockImplementation((config: { url?: string }) => {
        const url = config?.url ?? ''
        if (url.includes('/queues/')) {
          return Promise.resolve(ocsEnvelope(page))
        }
        return Promise.resolve(emptyOcsResponse(config))
      })
      return mountView(FinanceQueueView, '/reviews/finance')
    }

    /**
     * T394: the narrow card carries the same figures as the table.
     *
     * FR-071 requires every listed element visible **without horizontal scroll** at 390px, which this
     * table used to answer with `overflow-x: auto`. `ResponsiveTable` renders one card per row below
     * 640px, so a figure missing or differing there is invisible to a reader -- and every other
     * assertion in this file reads the table, the layout jsdom renders by default.
     *
     * Seeded through the real OCS request rather than by writing the store directly, matching this
     * suite's own convention: the assertions are about what the component does with a response, not
     * about state the test handed it.
     */
    it('carries the same figures in the narrow card as in the table', async () => {
      const wrapper = await mountFinanceQueue([
        financeQueueItem('tx-1'),
        financeQueueItem('tx-2')
      ])

      expectCardAgreesWithTable(wrapper, {
        rows: 2,
        // The hooks `src/components/review/queueColumns.ts` declares, plus this queue's own column and
        // the status badge, which is the element a finance officer reads a queue by.
        hooks: [
          'data-testid="amount"',
          'data-testid="transaction-id"',
          'data-testid="senior-decision"',
          'data-testid="view-details-btn"'
        ]
      })
    })

    it('should render shared finance queue for all finance officers', async () => {
      // The original asked for `[data-testid="queue-item"]`, which matches nothing: the view emits
      // `queue-row-{id}` per row, the same convention every other queue view uses, and the one that
      // lets a row be told apart from its neighbours. Asserting the selector the component
      // actually renders is the correction; inventing a `queue-item` attribute to satisfy the
      // assertion would have been the alternative, and would have tested nothing.
      const wrapper = await mountFinanceQueue([financeQueueItem('tx-1'), financeQueueItem('tx-2')])

      expect(wrapper.find('[data-testid="finance-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(2)
    })

    it('should show the senior decision that finance is deciding on top of', async () => {
      // FR-036: the senior decision is the final *management* decision and finance does not change
      // it. Finance still has to see it to act, so the queue carries it — what it must not do is
      // present it as settled. The distinction the finance view has to keep visible is
      // management-decided versus financially settled, and the queue is where an officer first
      // meets the row.
      const wrapper = await mountFinanceQueue([
        financeQueueItem('tx-1', {
          latestDecision: {
            id: 'd-2', stage: 'SENIOR', decision: 'APPROVE',
            actor: { userId: 'senior1', displayName: 'Senior 1', role: 'SENIOR_MANAGER' },
            decidedAt: '2026-09-21T10:00:00Z'
          }
        })
      ])

      const row = wrapper.find('[data-testid="queue-row-tx-1"]')
      expect(row.exists()).toBe(true)
      expect(row.find('[data-testid="senior-decision"]').text()).not.toBe('')
      // Nothing in the row may claim the money has moved while the row is still awaiting finance.
      expect(row.text()).not.toContain('settled')
    })

    it('should not show cost center scoping in finance queue', async () => {
      // The original asserted the absence of `[data-testid="cost-center-filter"]`, which passes
      // against a component that has never had such a control — it would pass just as well if the
      // view rendered nothing at all. FR-034 and T062 make the substantive claim: the finance queue
      // is shared, so finance asks for the whole queue rather than a slice of one cost centre's.
      //
      // That claim is observable in the request, which is what is asserted here. The query
      // parameters are read from `config.params` rather than from the URL because axios keeps them
      // separate and serialises at send time — asserting on `config.url` passes no matter what is
      // asked for, which is exactly the mistake this test is being rewritten to avoid.
      await mountFinanceQueue([financeQueueItem('tx-1')])

      const queueCalls = ocsRequest.mock.calls.filter(([config]) =>
        String(config?.url ?? '').includes('/queues/')
      )
      expect(queueCalls.length, 'the finance queue is fetched on mount').toBeGreaterThan(0)
      for (const [config] of queueCalls) {
        expect(config.url).toContain('/queues/finance')
        expect(Object.keys(config.params ?? {}).sort())
          .toEqual(['page', 'pageSize'])
      }
    })

    it('should show an empty state when finance has nothing awaiting review', async () => {
      const wrapper = await mountFinanceQueue([])
      expect(wrapper.find('[data-testid="empty-queue"]').exists()).toBe(true)
      expect(wrapper.findAll('[data-testid^="queue-row-"]')).toHaveLength(0)
    })

    it('should send a row to the finance review view, not the generic detail view', async () => {
      // Both `/reviews/finance/:id` names match the same path, so the router would resolve either one
      // to a working page and a link pointing at the wrong name fails *silently*: the officer lands on
      // a detail view with no settled-versus-management labelling and no way to tell that is what
      // happened. The route table therefore proves nothing about this — only the name the view pushes
      // does, so that is what is asserted. Reverting the view to `review-detail` fails this test.
      const push = vi.spyOn(router, 'push')
      const wrapper = await mountFinanceQueue([financeQueueItem('tx-1')])

      await wrapper.find('[data-testid="queue-row-tx-1"] [data-testid="view-details-btn"]').trigger('click')

      expect(push).toHaveBeenCalledWith({ name: 'finance-review', params: { transactionId: 'tx-1' } })
      push.mockRestore()
    })
  })

  describe('SettlementLabel', () => {
    /**
     * The label as the finance surface mounts it, for the state under test.
     *
     * Mounted directly rather than through a view, because the claim is about the label's own
     * derivation and a test that went through a view would also be asserting that view's wiring —
     * which is what the `FinanceReviewView` block below does separately.
     */
    function mountLabel(status: string, finalOutcome: string | null) {
      return mount(SettlementLabel, { props: { status, finalOutcome } })
    }

    // FR-036 + FR-040: the senior decision is final as a *management* decision, and only a finance
    // approval moves the money. `contracts/exports.md` states the same rule for reports — "do not
    // describe pre-finance approval as settled" — and a report that gets it wrong is a report that
    // misstates what a company has paid.
    it('should not call a senior approval settled', () => {
      const wrapper = mountLabel('SENIOR_APPROVED', null)
      const label = wrapper.find('[data-testid="settlement-label-management_decided"]')

      expect(label.exists()).toBe(true)
      expect(label.attributes('data-settled')).toBe('false')
      // The wording is what stops the misreading, so it is asserted rather than left to the icon.
      expect(wrapper.find('[data-testid="settlement-label-text"]').text()).not.toBe('')
    })

    it('should not call a senior rejection settled', () => {
      const wrapper = mountLabel('SENIOR_REJECTED', null)
      expect(wrapper.find('[data-testid="settlement-label-management_decided"]').attributes('data-settled'))
        .toBe('false')
    })

    it('should call a finance approval settled', () => {
      const wrapper = mountLabel('COMPLETED_APPROVED', 'APPROVED')
      const label = wrapper.find('[data-testid="settlement-label-settled"]')

      expect(label.exists()).toBe(true)
      expect(label.attributes('data-settled')).toBe('true')
    })

    it('should not call a finance rejection settled', () => {
      // A rejected transaction is finished but nothing was paid, and "rejected" is routinely read as
      // "the expense was disallowed" rather than "no money left the account". The distinction is the
      // same one the settlement label exists for.
      const wrapper = mountLabel('COMPLETED_REJECTED', 'REJECTED')
      expect(wrapper.find('[data-testid="settlement-label-rejected"]').attributes('data-settled'))
        .toBe('false')
    })

    it('should distinguish each state by text, not colour alone', () => {
      // WCAG 1.4.1. A colour-blind reader, a monochrome print and a high-contrast theme all have to
      // be able to tell these apart, and the only thing that survives all three is the wording.
      const states = [
        ['SENIOR_APPROVED', null, 'management_decided'],
        ['SENIOR_REJECTED', null, 'management_decided'],
        ['AWAITING_FINANCE_REVIEW', null, 'awaiting_finance'],
        ['COMPLETED_APPROVED', 'APPROVED', 'settled'],
        ['COMPLETED_REJECTED', 'REJECTED', 'rejected']
      ] as const

      for (const [status, outcome, expected] of states) {
        const wrapper = mountLabel(status, outcome)
        expect(wrapper.find(`[data-testid="settlement-label-${expected}"]`).exists()).toBe(true)
        expect(wrapper.find('[data-testid="settlement-label-text"]').text().trim()).not.toBe('')
      }
    })
  })

  describe('FinanceReviewView', () => {
    /** A transaction under finance review, with the history finance inherits. */
    function reviewedTransaction(overrides: Record<string, unknown> = {}) {
      return {
        id: 'tx-1',
        sheetId: 'sheet-1',
        stage: 'FINANCE',
        status: 'AWAITING_FINANCE_REVIEW',
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
        decisions: [
          {
            id: 'd-1', stage: 'COST_CENTER', decision: 'APPROVE',
            actor: { userId: 'ccm1', displayName: 'CC Manager', role: 'COST_CENTER_MANAGER' },
            decidedAt: '2026-09-20T10:00:00Z'
          },
          {
            id: 'd-2', stage: 'SENIOR', decision: 'APPROVE',
            actor: { userId: 'senior1', displayName: 'Senior 1', role: 'SENIOR_MANAGER' },
            decidedAt: '2026-09-21T10:00:00Z'
          }
        ],
        allowedActions: { canApprove: true, canReject: true, canReturn: true },
        evidence: [{
          id: 'ev-1', transactionId: 'tx-1', revisionId: 'rev-1', name: 'receipt.pdf',
          mediaType: 'application/pdf', sizeBytes: 1024, active: true, uploadedBy: 'user1',
          uploadedAt: '2026-09-20T10:00:00Z'
        }],
        ...overrides
      }
    }

    /** Mounts the finance review page with the transport answering with `transaction`. */
    async function mountReview(transaction: unknown, path = '/reviews/finance/tx-1') {
      ocsRequest.mockImplementation((config: { url?: string }) => {
        if (String(config?.url ?? '').includes('/transactions/')) {
          return Promise.resolve(ocsEnvelope(transaction))
        }
        return Promise.resolve(emptyOcsResponse(config))
      })
      return mountView(FinanceReviewView, path)
    }

    it('should show the management decision finance is deciding on top of', async () => {
      // FR-036: the senior decision is the final management decision, so finance acts on top of a
      // settled position rather than an open question. Both decisions are shown — the cost-centre one
      // is advisory (FR-034) and filtering it out would hide exactly what that requirement keeps
      // visible.
      const wrapper = await mountReview(reviewedTransaction())
      const history = wrapper.find('[data-testid="management-decision"]')

      expect(history.exists()).toBe(true)
      expect(history.text()).toContain('Senior 1')
      expect(history.text()).toContain('CC Manager')
    })

    it('should explain why approve is unavailable on a senior-rejected transaction', async () => {
      // FR-039: finance must not approve a senior-rejected transaction. The capability comes from the
      // server, so the client does not restate the policy — but an officer who finds the button
      // missing is entitled to be told why rather than left to assume the interface is broken.
      const wrapper = await mountReview(reviewedTransaction({
        status: 'SENIOR_REJECTED',
        allowedActions: { canApprove: false, canReject: true, canReturn: true }
      }))

      const reason = wrapper.find('[data-testid="approve-forbidden-reason"]')
      expect(reason.exists(), 'a refused approval must be explained').toBe(true)
      expect(reason.text().trim()).not.toBe('')
    })

    it('should not explain a refusal on a transaction finance may approve', async () => {
      // The other direction: an explanation shown on every row would be noise, and a permanent one
      // would train an officer to stop reading it.
      const wrapper = await mountReview(reviewedTransaction())
      expect(wrapper.find('[data-testid="approve-forbidden-reason"]').exists()).toBe(false)
    })

    it('should show the settled label on the transaction under review', async () => {
      const wrapper = await mountReview(reviewedTransaction({
        status: 'COMPLETED_APPROVED', finalOutcome: 'APPROVED'
      }))
      expect(wrapper.find('[data-testid="settlement-label-settled"]').exists()).toBe(true)
    })

    it('should require a manual retry on a failed load and never retry by itself', async () => {
      // FR-075: a communication failure shows a clear error and waits for a person. The assertion that
      // matters is the *absence* of a second request: a finance decision writes a movement, a balance,
      // a final state and an audit row, so an automatic retry is how a double charge happens.
      ocsRequest.mockImplementation(() => Promise.reject(new Error('network down')))
      const wrapper = await mountView(FinanceReviewView, '/reviews/finance/tx-1')

      const beforeRetry = ocsRequest.mock.calls.length
      // Exactly one, and this is the assertion that carries the requirement. `flushPromises()` drains
      // macrotasks as well as microtasks, so a retry scheduled on a short timer has already fired by
      // the time `mountView` returns and a later "nothing else was requested" check would not see it.
      // Counting here catches a retry whenever it happens; the wait below catches one that would
      // happen later.
      expect(beforeRetry, 'the page is requested exactly once').toBe(1)

      expect(wrapper.find('[data-testid="error"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="error-message"]').text().trim()).not.toBe('')
      expect(wrapper.find('[data-testid="manual-retry-hint"]').exists(),
        'the failure must say a person has to act').toBe(true)

      // Time passing is the automatic retry this requirement forbids; nothing fires on a timer.
      await new Promise((resolve) => setTimeout(resolve, 50))
      expect(ocsRequest.mock.calls.length, 'nothing re-requests on its own').toBe(beforeRetry)
    })

    it('should re-request only when the retry button is used', async () => {
      ocsRequest.mockImplementation(() => Promise.reject(new Error('network down')))
      const wrapper = await mountView(FinanceReviewView, '/reviews/finance/tx-1')
      const beforeRetry = ocsRequest.mock.calls.length

      await wrapper.find('[data-testid="retry-btn"]').trigger('click')
      await flushPromises()

      expect(ocsRequest.mock.calls.length).toBe(beforeRetry + 1)
    })
  })

  describe('AccountView', () => {
    function account(overrides: Record<string, unknown> = {}) {
      return {
        id: 'acc-1', userId: 'user1', active: true, version: 0,
        balance: { amountMinor: 100000000, currency: 'IRR', minorUnit: 0 },
        ...overrides
      }
    }

    function movement(overrides: Record<string, unknown> = {}) {
      return {
        id: 'mov-1', type: 'CHARGE',
        amount: { amountMinor: 100000000, currency: 'IRR', minorUnit: 0 },
        balanceAfter: { amountMinor: 100000000, currency: 'IRR', minorUnit: 0 },
        description: 'Opening petty-cash float',
        sourceRevisionId: null,
        occurredAt: '2026-09-01T09:00:00Z',
        ...overrides
      }
    }

    /** Mounts the account page with the transport answering with `accounts` and `movements`. */
    async function mountAccounts(accounts: unknown[], movements: unknown[]) {
      ocsRequest.mockImplementation((config: { url?: string }) => {
        const url = String(config?.url ?? '')
        if (url.includes('/movements')) {
          return Promise.resolve(ocsEnvelope({ items: movements, page: 1, pageSize: 25, total: movements.length }))
        }
        if (url.includes('/accounts')) {
          return Promise.resolve(ocsEnvelope({ items: accounts, page: 1, pageSize: 100, total: accounts.length }))
        }
        return Promise.resolve(emptyOcsResponse(config))
      })
      return mountView(AccountView, '/accounts')
    }

    it('should show the balance at the currency configured scale', async () => {
      // IRR has no minor unit, so a projection that divided by 100 would print a rial figure a
      // hundred times too small. The store formats from the payload's own `minorUnit`.
      const wrapper = await mountAccounts([account()], [])
      expect(wrapper.find('[data-testid="balance-value"]').text()).toContain('۱۰۰٬۰۰۰٬۰۰۰')
    })

    it('should show a negative balance as a permitted value rather than an error', async () => {
      // FR-027: negative balances MUST be permitted. Reporting one as a failure would contradict a
      // requirement the submission path already obeys, and would tell a purchaser their account is
      // broken when it is not.
      const wrapper = await mountAccounts(
        [account({ balance: { amountMinor: -5000, currency: 'IRR', minorUnit: 0 } })],
        []
      )
      expect(wrapper.find('[data-testid="error"]').exists()).toBe(false)
      expect(wrapper.find('[data-testid="negative-balance-note"]').exists()).toBe(true)
    })

    it('should show both movement types with the direction of the money', async () => {
      // `formatMoney` prints a magnitude, so a charge of 100 and a consumption of 100 are the same
      // string. The sign and the type label are what make the ledger readable at all.
      const wrapper = await mountAccounts([account()], [
        movement(),
        movement({
          id: 'mov-2', type: 'CONSUMPTION',
          amount: { amountMinor: 100000, currency: 'IRR', minorUnit: 0 },
          balanceAfter: { amountMinor: 99900000, currency: 'IRR', minorUnit: 0 },
          description: 'Settlement of a revision',
          sourceRevisionId: 'rev-1'
        })
      ])

      const charge = wrapper.find('[data-testid="movement-mov-1"]')
      const consumption = wrapper.find('[data-testid="movement-mov-2"]')
      expect(charge.find('[data-testid="movement-amount"]').text().trim().startsWith('+')).toBe(true)
      expect(consumption.find('[data-testid="movement-amount"]').text().trim().startsWith('−')).toBe(true)
      expect(consumption.find('[data-testid="movement-source-revision"]').exists(),
        'a consumption names the revision it settled').toBe(true)
      expect(charge.find('[data-testid="movement-source-revision"]').exists(),
        'a charge is justified by no revision').toBe(false)
    })

    it('should show an empty state for an account that has never been used', async () => {
      // FR-020 provisions a default account, so "no movements" is a normal state rather than missing
      // data — the view must not present it as an error.
      const wrapper = await mountAccounts([account()], [])
      expect(wrapper.find('[data-testid="no-movements"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="movements-error"]').exists()).toBe(false)
    })

    it('should show an empty state when the purchaser has no account at all', async () => {
      const wrapper = await mountAccounts([], [])
      expect(wrapper.find('[data-testid="no-accounts"]').exists()).toBe(true)
    })

    it('should require a manual retry when the ledger fails to load', async () => {
      // FR-075, on the ledger rather than the list. A ledger that silently showed the last known page
      // next to a fresh error would read as the current state of the account, which is worse than an
      // error: it is a wrong balance shown confidently.
      ocsRequest.mockImplementation((config: { url?: string }) => {
        const url = String(config?.url ?? '')
        if (url.includes('/movements')) return Promise.reject(new Error('network down'))
        if (url.includes('/accounts')) {
          return Promise.resolve(ocsEnvelope({ items: [account()], page: 1, pageSize: 100, total: 1 }))
        }
        return Promise.resolve(emptyOcsResponse(config))
      })
      const wrapper = await mountView(AccountView, '/accounts')

      expect(wrapper.find('[data-testid="movements-error"]').exists()).toBe(true)
      expect(wrapper.find('[data-testid="movements-manual-retry-hint"]').exists()).toBe(true)
      // The balance is still shown: the list succeeded, and hiding it would misreport a working
      // account as an empty one.
      expect(wrapper.find('[data-testid="balance-value"]').text()).not.toBe('')
      expect(wrapper.find('[data-testid="movement-list"]').exists(),
        'a stale ledger is never shown beside a fresh error').toBe(false)
    })
  })

  // FR-071's three clauses — "all elements visible without horizontal scroll at 390px viewport, WCAG
  // AA contrast, ≥12pt Persian font" — cannot be measured here. jsdom has no layout engine and no font
  // metrics, so an overflow is not observable and a contrast ratio is never computed. The same
  // position T053 was reopened for, and the same substitute T286 adopted for the US2 views: assert
  // the declarations that make those outcomes achievable, and leave the measurement to T116's file
  // and T122/T123's browser and manual matrices.
  describe('FR-071 declarations', () => {
    const VIEWS = [
      'src/components/review/SettlementLabel.vue',
      'src/views/reviews/FinanceReviewView.vue',
      'src/views/accounts/AccountView.vue'
    ]

    const declarations = VIEWS.flatMap((file) => ({
      file,
      source: readFileSync(join(process.cwd(), file), 'utf8')
    }))

    it('should declare a mobile breakpoint that reaches 390px in every view', () => {
      // Both spellings count: the legacy `max-width: 600px` and the range syntax `width <= 390px` the
      // queue views use. A `max-width: 600px` rule already covers 390px, so the requirement is that
      // the *largest* declared breakpoint reaches the viewport, not that one sits exactly at it.
      for (const { file, source } of declarations) {
        const breakpoints = [
          ...[...source.matchAll(/max-width:\s*(\d+)px/g)].map((m) => Number(m[1])),
          ...[...source.matchAll(/width\s*<=\s*(\d+)px/g)].map((m) => Number(m[1]))
        ]
        expect(breakpoints.length, `${file} must declare a mobile breakpoint`).toBeGreaterThan(0)
        expect(Math.max(...breakpoints), `${file}'s breakpoint must reach 390px`)
          .toBeGreaterThanOrEqual(390)
      }
    })

    it('should declare no width that forces the viewport wider than 390px', () => {
      // A `min-width` above the viewport is the usual way a table or a panel forces a horizontal
      // scrollbar, and it is the one that survives a media query: a `@media` rule cannot undo it.
      for (const { file, source } of declarations) {
        const offenders = [...source.matchAll(/min-width:\s*(\d+)px|min-inline-size:\s*(\d+)px/g)]
          .map((m) => Number(m[1] ?? m[2]))
          .filter((px) => px > 390)
        expect(offenders, `${file} must not force the viewport wider than 390px`).toEqual([])
      }
    })

    it('should declare no user-facing text below the 12pt (16px) floor', () => {
      // Read through the app's own tokens, and a token reference counts as compliant rather than as
      // an unknown unit — the same resolution the app-wide `typography.spec.ts` gate performs, so the
      // two cannot disagree about what the scale is.
      const scale = readFileSync(join(process.cwd(), 'src/styles/app.scss'), 'utf8')
      const tokens = new Map<string, number>()
      for (const match of scale.matchAll(/--([\w-]+):\s*([\d.]+)rem/g)) {
        const [, name, value] = match
        if (name !== undefined && value !== undefined) tokens.set(name, Number(value) * 16)
      }
      expect(tokens.size, 'the app must declare a type scale to read through').toBeGreaterThan(0)

      /** Resolves a declared font-size to pixels, or `null` when it is not an absolute length. */
      const toPixels = (value: string): number | null => {
        const token = value.match(/^var\(\s*--([\w-]+)\s*\)$/)
        if (token?.[1] !== undefined) return tokens.get(token[1]) ?? null
        const rem = value.match(/^([\d.]+)rem$/)
        if (rem?.[1] !== undefined) return Number(rem[1]) * 16
        const px = value.match(/^([\d.]+)px$/)
        if (px?.[1] !== undefined) return Number(px[1])
        // Relative or unknown units are reported as violations rather than assumed compliant, which is
        // the safe direction: `typography.spec.ts` reports them the same way.
        return null
      }

      for (const { file, source } of declarations) {
        const below = [...source.matchAll(/font-size:\s*([^;]+);/g)]
          .map((m) => m[1]?.trim() ?? '')
          .map((value) => ({ value, px: toPixels(value) }))
          .filter(({ px }) => px === null || px < 16)
          .map(({ value }) => `${file}: ${value}`)
        expect(below, `${file} declares text below the floor`).toEqual([])
      }
    })
  })
})
