<template>
	<span
		class="compact-identifier"
		:class="{ 'compact-identifier--truncated': isTruncated }"
		tabindex="0"
		:title="value"
		:aria-label="value"
		:v-bind="$attrs"
	>
		<bdi dir="ltr" class="compact-identifier__value">{{ displayValue }}</bdi>
	</span>
</template>

<script setup lang="ts">
import { computed } from 'vue'

interface Props {
	/** The full underlying identifier — never mutated, always preserved in `title`/`aria-label`. */
	value: string
	/** Maximum length before truncation applies. Short human-readable values stay intact. */
	maxLength?: number
}

const props = withDefaults(defineProps<Props>(), {
	maxLength: 13,
})

/** Deterministic truncation: `25ccedd9…145b` — first 8, ellipsis, last 4. */
const displayValue = computed(() => {
	const value = props.value
	if (!value || value.length <= props.maxLength) return value
	const head = value.slice(0, 8)
	const tail = value.slice(-4)
	return `${head}…${tail}`
})

const isTruncated = computed(() => {
	const value = props.value
	return Boolean(value) && value.length > props.maxLength
})
</script>

<style scoped>
.compact-identifier {
	display: inline-flex;
	align-items: center;
	font-family: monospace;
	font-size: var(--font-size-floor);
	unicode-bidi: isolate;
	overflow-wrap: anywhere;
	line-height: 1.3;
	color: var(--color-main-text);
	cursor: default;
}

.compact-identifier__value {
	white-space: nowrap;
	overflow-wrap: anywhere;
}

.compact-identifier--truncated .compact-identifier__value {
	overflow: hidden;
	text-overflow: ellipsis;
	max-inline-size: 100%;
}

/* Keyboard focus exposes the full value: on focus the ellipsis yields so the complete identifier
   is readable without relying on hover or a tooltip. */
.compact-identifier:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
	border-radius: 4px;
}

.compact-identifier:focus-visible .compact-identifier__value {
	overflow: visible;
	text-overflow: unset;
	white-space: pre-wrap;
}

@media (width <= 390px) {
	.compact-identifier {
		font-size: var(--font-size-floor);
	}
}

@media (forced-colors: active) {
	.compact-identifier {
		color: CanvasText;
	}

	.compact-identifier:focus-visible {
		outline: 2px solid CanvasText;
		outline-offset: 2px;
	}
}
</style>
