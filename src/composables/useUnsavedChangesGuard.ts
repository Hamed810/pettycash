import { onBeforeUnmount, onMounted, ref } from 'vue'
import { onBeforeRouteLeave, useRouter, type RouteLocationRaw } from 'vue-router'

interface GuardOptions {
	/** Whether the form currently holds changes the server has not stored. */
	isDirty: () => boolean
	/** Stores the form. Returns `false` when the save was refused (validation, conflict, API). */
	save: () => Promise<boolean>
	/** Where Cancel/Escape leave when the caller did not name a destination. */
	fallback: () => RouteLocationRaw
	/** A second dialog (such as a delete confirmation) that owns Escape while it is open. */
	isBlocked?: () => boolean
}

/**
 * The approved save / discard / stay guard for both editable forms.
 *
 * Covered exits: the Cancel button, Escape, in-app navigation (which also covers the browser's
 * Back button inside a hash-routed SPA), and tab close/reload through `beforeunload`.
 *
 * Nothing is written unless the caller chooses Save. Discard and Stay are deliberately inert with
 * respect to the API.
 */
export function useUnsavedChangesGuard(options: GuardOptions) {
	const router = useRouter()
	const open = ref(false)
	const saving = ref(false)
	const saveFailed = ref(false)
	let pending: RouteLocationRaw | null = null
	// Set while the guard itself is navigating, so the leave hook does not block its own push.
	let bypass = false

	function ask(target?: RouteLocationRaw): void {
		if (!options.isDirty()) {
			bypass = true
			void router.push(target ?? options.fallback()).finally(() => { bypass = false })
			return
		}
		pending = target ?? options.fallback()
		saveFailed.value = false
		open.value = true
	}

	function stay(): void {
		open.value = false
		saveFailed.value = false
		pending = null
	}

	async function saveAndLeave(): Promise<void> {
		saving.value = true
		saveFailed.value = false
		try {
			const saved = await options.save()
			if (!saved) {
				saveFailed.value = true
				return
			}
			const target = pending ?? options.fallback()
			open.value = false
			pending = null
			bypass = true
			await router.push(target)
		} finally {
			saving.value = false
			bypass = false
		}
	}

	async function discard(): Promise<void> {
		const target = pending ?? options.fallback()
		open.value = false
		pending = null
		bypass = true
		try {
			await router.push(target)
		} finally {
			bypass = false
		}
	}

	function beforeUnload(event: BeforeUnloadEvent): void {
		if (!options.isDirty()) return
		// The browser supplies its own wording; returning a value is the deprecated spelling some
		// engines still require to show the prompt at all.
		event.preventDefault()
		event.returnValue = ''
	}

	function onEscape(event: KeyboardEvent): void {
		if (event.key !== 'Escape') return
		if (options.isBlocked?.()) return
		if (open.value) {
			event.preventDefault()
			stay()
			return
		}
		if (!options.isDirty()) return
		event.preventDefault()
		pending = options.fallback()
		open.value = true
	}

	onMounted(() => {
		window.addEventListener('beforeunload', beforeUnload)
		window.addEventListener('keydown', onEscape)
	})
	onBeforeUnmount(() => {
		window.removeEventListener('beforeunload', beforeUnload)
		window.removeEventListener('keydown', onEscape)
	})

	onBeforeRouteLeave((to) => {
		if (bypass || options.isBlocked?.() || !options.isDirty()) return true
		pending = to.fullPath
		saveFailed.value = false
		open.value = true
		// Stay on the form; the guard decides where to go once the caller has answered.
		return false
	})

	return { open, saving, saveFailed, ask, stay, saveAndLeave, discard }
}
