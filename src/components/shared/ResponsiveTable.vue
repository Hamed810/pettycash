<script setup lang="ts" generic="T">
import type { VNode } from 'vue'

/**
 * One table, two layouts: a `<table>` on wide viewports and one card per row on narrow ones.
 *
 * ## Why this exists
 *
 * FR-071 requires every listed element to be *"visible without horizontal scroll at 390px viewport"* and
 * to *"adapt to desktop and mobile-sized screens"*. The eight surfaces outside the report table answered
 * this with `overflow-x: auto` on their table container — which produces a horizontal scrollbar rather
 * than satisfying the requirement, and is precisely what `src/styles/app.scss` says must not be done:
 * *"FR-071's 'no horizontal scroll at 390px' is a requirement to *fix* the overflow … not to clip it."*
 *
 * `ReportTable.vue` had the right pattern — a `display: none` table plus a per-row card list under a
 * media query — and the other seven did not. **Rather than copy that pattern a seventh time**, this is the
 * single copy.
 *
 * ## Why cells are a render function and not markup
 *
 * The card list and the table must render **the same content**, or the narrow layout becomes a second,
 * silently divergent presentation of the same figures — which is the defect this component is meant to
 * close, not reproduce. A `cell(row, column)` function is called once per cell per layout, so a figure
 * cannot be formatted two ways.
 *
 * Callers therefore build cells with `h()` rather than template markup. That is the cost of the guarantee.
 *
 * ## Per-field hooks exist on **both** layouts
 *
 * `ReportTable.vue`'s card fields carry no `data-test`, so a card-only defect is unassertable: the
 * `data-testid="report-cell-<column>"` hook lives only on the desktop table that a narrow viewport never
 * renders. Each field here carries a hook derived from the column, so a test that asserts a value on the
 * table asserts the same value on the card.
 *
 * ## Hooks are emitted under **both** `data-test` and `data-testid`
 *
 * Not a stylistic hedge — the repository is genuinely inconsistent, and each spelling is load-bearing for a
 * different suite. `SheetListView` and its 125 tests use `data-test`; the four review queues used
 * `data-testid="queue-row-${item.id}"` and seven assertions across two spec files select on it. Emitting one
 * spelling silently renamed every hook on conversion, which showed up as *zero rows found* rather than as
 * anything pointing at the attribute. Emitting both keeps every existing assertion working as written, which
 * is the same reason this component's `rowTestId` accepts a per-row function rather than a fixed string.
 */
const props = defineProps<{
	/** Column descriptors in display order. `label` is already translated by the caller. */
	columns: ReadonlyArray<{ key: string; label: string }>
	rows: readonly T[]
	/** Renders one cell. Called for both layouts, so the two cannot disagree. */
	cell: (row: T, column: { key: string; label: string }) => VNode | string | number
	/** Stable identity per row, so Vue keys rather than array indices. */
	rowKey: (row: T, index: number) => string
	/** Accessible name for both layouts. */
	caption: string
	loading?: boolean
	testId?: string
	/** Prefix for the per-field hooks, so two tables on one page do not collide. */
	testIdPrefix?: string
	/**
	 * Hook for one row. On the `<tr>` it is this value verbatim; on the card `<li>` it is
	 * `` `${rowTestId}-card` ``.
	 *
	 * Added because converting a view to this component silently removed its row hook: existing assertions
	 * located rows by `[data-test="sheet-row"]`, which was on the `<tr>` this component no longer renders.
	 * Fixing that by editing the assertions would have been the wrong direction -- the hook was how those
	 * tests found a row at all.
	 *
	 * **The two layouts get distinct hooks, not a shared one.** The first version put the same value on
	 * both, which is defensible until a test counts rows: `findAll('[data-test="sheet-row"]')` then returns
	 * two per record -- one table row and one card for the same sheet -- and a row-count assertion fails by
	 * exactly doubling. The card keeps its own `-card` suffix so both layouts stay addressable **and** a
	 * count stays a count.
	 *
	 * **A function is accepted for the same reason**, and it was added because a conversion removed a hook
	 * rather than merely relocating it. The four review queues assert rows with
	 * `[data-testid^="queue-row-"]`, matching a **per-row** id built from the record's own identity; a single
	 * static string cannot express that, so the only two available moves were editing those assertions or
	 * making the hook expressible. Editing them would have discarded the selectors seven tests use to find a
	 * row at all, and a component that cannot be pointed at is a component that gets abandoned.
	 */
	rowTestId?: string | ((row: T, index: number) => string)
	/**
	 * The card `<li>`'s own hook, for callers that need it to differ from the `<tr>`'s.
	 *
	 * Defaults to `` `${rowTestId}-card` ``, which is right for a caller asserting rows with an **exact**
	 * selector — `[data-test="sheet-row"]` does not match `sheet-row-card`. It is **wrong** for a caller
	 * using a **prefix** selector: `[data-testid^="queue-row-"]` matches both the table row and the card for
	 * the same record, so every row count silently doubles. Six queue assertions did exactly that, and the
	 * symptom was "expected 2, received 4" pointing at nothing that had changed.
	 *
	 * So the caller may name the card independently — the review queues pass `queue-card-${id}` — which
	 * keeps both layouts addressable *and* leaves a prefix selector meaning one thing: rows of the table.
	 */
	cardRowTestId?: (row: T, index: number) => string
}>()

/**
 * The row hook for one row, or `undefined` when the caller supplied none.
 *
 * Resolved in **one** place so the `<tr>` and the card `<li>` cannot derive different names for the same
 * record — which would make a prefix selector such as `[data-testid^="queue-row-"]` match twice per row and
 * double every row count.
 */
function rowOf(index: number): T {
	// `props.rows[index]` is typed `T | undefined` because `noUncheckedIndexedAccess` is on. It is
	// defined for every index the template iterates, so the assertion states what the caller's `v-for`
	// guarantees rather than widening the public signature to admit a hole.
	return props.rows[index] as T
}

function rowHook(index: number): string | undefined {
	const supplied = props.rowTestId

	if (supplied === undefined) {
		return undefined
	}

	return typeof supplied === 'function' ? supplied(rowOf(index), index) : supplied
}

function cardRowHook(index: number): string | undefined {
	if (props.cardRowTestId) {
		return props.cardRowTestId(rowOf(index), index)
	}

	const row = rowHook(index)

	return row === undefined ? undefined : `${row}-card`
}
</script>

<template>
	<div class="responsive-table" dir="rtl" :data-test="testId">
		<div class="responsive-table__desktop">
			<table :aria-busy="loading || undefined" :aria-label="caption">
				<caption class="responsive-table__caption">{{ caption }}</caption>
				<thead>
					<tr>
						<th v-for="column in columns" :key="column.key" scope="col">
							{{ column.label }}
						</th>
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="(row, index) in rows"
						:key="rowKey(row, index)"
						:data-test="rowHook(index)"
						:data-testid="rowHook(index)"
					>
						<td
							v-for="column in columns"
							:key="column.key"
							:data-test="testIdPrefix ? `${testIdPrefix}-cell-${column.key}` : undefined"
							:data-testid="testIdPrefix ? `${testIdPrefix}-cell-${column.key}` : undefined"
						>
							<component :is="cell(row, column)" />
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<!--
			FR-071: at a narrow viewport the same rows are rendered one card each. Not a second data set --
			`rows` and `cell` are the same, which is the whole point of this component.
		-->
		<ul class="responsive-table__cards" :aria-label="caption">
			<li
				v-for="(row, index) in rows"
				:key="rowKey(row, index)"
				class="responsive-table__card"
				:data-test="cardRowHook(index)"
				:data-testid="cardRowHook(index)"
			>
				<span
					v-for="column in columns"
					:key="column.key"
					class="responsive-table__field"
					:data-test="testIdPrefix ? `${testIdPrefix}-card-field-${column.key}` : undefined"
					:data-testid="testIdPrefix ? `${testIdPrefix}-card-field-${column.key}` : undefined"
				>
					<span class="responsive-table__label">{{ column.label }}</span>
					<span class="responsive-table__value">
						<component :is="cell(row, column)" />
					</span>
				</span>
			</li>
		</ul>
	</div>
</template>

<style scoped>
/*
 * The desktop table is the default and the cards are the narrow-viewport answer, which mirrors
 * `ReportTable.vue`'s established behaviour rather than inventing a second breakpoint.
 *
 * The template uses `<component :is>` rather than interpolating the `cell` result as text, because a
 * render function returns a VNode and cannot be stringified.
 */
.responsive-table {
	display: flex;
	flex-direction: column;
	width: 100%;
}

.responsive-table__caption {
	/* Visually the caption is redundant with the surrounding card's heading, but a table without one is
	   announced without a name by a screen reader. */
	text-align: start;
	padding: 0;
}

.responsive-table__cards {
	display: none;
	list-style: none;
	margin: 0;
	padding: 0;
	gap: 0.75rem;
	flex-direction: column;
}

@media (width <= 640px) {
	.responsive-table__desktop {
		display: none;
	}

	.responsive-table__cards {
		display: flex;
	}
}

.responsive-table__card {
	display: flex;
	flex-direction: column;
	gap: 0.25rem;
	padding: 0.75rem;
	border: 1px solid var(--nc-color-border, #dbdbdb);
	border-radius: var(--border-radius-large, 8px);

	/* `anywhere` so a long identifier wraps rather than pushing the card past the viewport — the card
	   layout is only a fix for narrow screens if nothing inside it can force a scrollbar. */
	overflow-wrap: anywhere;
}

.responsive-table__field {
	display: flex;
	flex-wrap: wrap;
	align-items: baseline;
	gap: 0.25rem;
}

.responsive-table__label {
	font-size: var(--font-size-floor);
	color: var(--nc-color-text-maxcontrast, #6b6b6b);
}
</style>