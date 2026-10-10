<script setup lang="ts">
import { t } from '@nextcloud/l10n'
import type { AuditEvent } from '@/types/api'

/**
 * One transaction's formal audit history, oldest first (T098, FR-051, SC-006).
 *
 * Presentational: the store (or view) supplies `events` in server order and
 * this component renders them. An empty list renders the empty state, never an
 * empty table — "no history yet" and "history failed to load" are different
 * claims, and the load failure belongs to the view's alert, not to this list.
 */
defineProps<{
	events: AuditEvent[]
}>()

function actorLabel(event: AuditEvent): string {
	return event.actor.displayName || event.actor.userId
}

function hasDiff(event: AuditEvent): boolean {
	return event.before != null || event.after != null
}
</script>

<template>
	<div class="audit-timeline" dir="rtl" data-test="audit-timeline">
		<ul v-if="events.length > 0" class="audit-timeline__list">
			<li
				v-for="event in events"
				:key="event.id"
				class="audit-timeline__event"
				data-test="audit-event"
			>
				<span class="audit-timeline__type" data-test="audit-event-type">{{ event.eventType }}</span>
				<span class="audit-timeline__stage" data-test="audit-event-stage">{{ event.stage }}</span>
				<span class="audit-timeline__actor" data-test="audit-event-actor">{{ actorLabel(event) }}</span>
				<span class="audit-timeline__role" data-test="audit-event-role">{{ event.actor.role }}</span>
				<p v-if="event.explanation" class="audit-timeline__explanation" data-test="audit-event-explanation">
					{{ event.explanation }}
				</p>
				<div v-if="hasDiff(event)" class="audit-timeline__diff" data-test="audit-diff">
					<pre v-if="event.before != null" class="audit-timeline__before" data-test="audit-before">{{ JSON.stringify(event.before) }}</pre>
					<pre v-if="event.after != null" class="audit-timeline__after" data-test="audit-after">{{ JSON.stringify(event.after) }}</pre>
				</div>
				<time class="audit-timeline__time" data-test="audit-event-time">{{ event.occurredAt }}</time>
			</li>
		</ul>
		<p v-else class="audit-timeline__empty" data-test="audit-empty">
			{{ t('pettycash', 'audit.empty') }}
		</p>
	</div>
</template>

<style scoped>
.audit-timeline {
	font-size: var(--font-size-floor);
}

.audit-timeline__list {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
}

.audit-timeline__event {
	border-inline-start: 2px solid var(--color-border-dark, #aaa);
	padding-inline-start: 8px;
	display: flex;
	flex-wrap: wrap;
	gap: 4px 8px;
}

.audit-timeline__explanation {
	flex-basis: 100%;
	margin: 0;
}

.audit-timeline__diff {
	flex-basis: 100%;
	display: flex;
	gap: 8px;
}

.audit-timeline__diff pre {
	margin: 0;
	font-size: var(--font-size-floor);
}

.audit-timeline__empty {
	margin: 0;
}
</style>
