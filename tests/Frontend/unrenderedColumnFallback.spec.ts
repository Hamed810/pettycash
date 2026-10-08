import { describe, it, expect } from 'vitest'
import { sharedQueueCell } from '@/components/review/queueColumns'
import type { TransactionSummary } from '@/types/api'

/**
 * T396: the `default:` branch of a keyed cell renderer surfaces the column's key instead of nothing.
 *
 * ## Why this is worth a test
 *
 * Every cell renderer written for `ResponsiveTable` ends with a `default:` that renders the column key --
 * `queue-unrendered-<key>`, `transaction-unrendered-<key>`. That is the right default for a keyed `switch`:
 * a column added to a header list without a matching case produces a **visible, assertable** cell rather
 * than a silently blank one, which is what a reader sees and what a reviewer misses.
 *
 * **A branch with no coverage reads as unreachable**, and the next person tidying the file has no evidence
 * that removing it costs anything. That is the whole risk here.
 *
 * ## Scope, stated rather than implied
 *
 * This covers the **shared** renderer, which is the one exported from a module and therefore reachable
 * without a test seam. The four per-view renderers (`TransactionListView`, `SheetDetailView`,
 * `SheetListView`) live inside `<script setup>` and are not importable, and their column lists are derived
 * from a fixed header constant — so reaching their `default:` would mean either exporting script-setup
 * internals or adding a column-injection prop purely for testability. Neither is a change worth making
 * for this, and recording the limit is better than pretending the branch is covered.
 */
describe('cell renderer fallback for an unrendered column', () => {
	const row = {
		id: 'tx-1',
		sheetId: 'sh-1',
		stage: 'COST_CENTER',
		status: 'DRAFT',
		evidenceCount: 0,
		version: 0,
		activeRevision: {
			expenseDate: '2026-01-15',
			amount: { amountMinor: 1000, currency: 'IRR', minorUnit: 0 },
			costCenter: { id: 'c', name: 'Cost Center' },
			expenseType: { id: 'e', name: 'Travel' }
		}
	} as unknown as TransactionSummary

	const openDetail = () => {}

	it('renders the column key, and a hook naming it, for a column with no case', () => {
		const vnode = sharedQueueCell(row, { key: 'someNewColumn', label: 'Some New Column' }, openDetail)

		expect(vnode).not.toBeNull()
		expect(vnode).not.toBe('')

		const data = (vnode as { props?: Record<string, unknown> }).props ?? {}
		expect(
			data['data-testid'],
			'the fallback must be addressable, or it is visible to a reader but not to a test -- which is '
				+ 'the same unassertable state T395 was written to end'
		).toBe('queue-unrendered-someNewColumn')

		expect(
			(vnode as { children?: unknown }).children,
			'the fallback must render the key, so a column someone forgot to implement is visible in both '
				+ 'layouts rather than a silently blank cell'
		).toBe('someNewColumn')
	})

	it('still renders the known columns, so the fallback does not shadow them', () => {
		for (const key of ['transactionId', 'sheetId', 'costCenter', 'amount', 'status', 'actions']) {
			const vnode = sharedQueueCell(row, { key, label: key }, openDetail)
			const data = (vnode as { props?: Record<string, unknown> }).props ?? {}

			expect(
				data['data-testid'],
				`'${key}' has a case and must not fall through to the fallback`
			).not.toBe(`queue-unrendered-${key}`)
		}
	})
})
