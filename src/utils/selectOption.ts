/**
 * Reads a `<NcSelect>` option's value out of the object `v-model` receives.
 *
 * ## Why this exists
 *
 * `NcSelect` forwards `v-model` straight to `vue-select`, whose `reduce` prop defaults to
 * `option => option`. So binding an object-shaped `:options` list — the `{ label, value }` shape the
 * whole app uses, because an account's label is its balance and not its UUID — puts the **entire
 * option object** into whatever the model is bound to. The declared type of `modelValue` is
 * `string | number`, so nothing in the template, the store or the type checker objects; the mismatch
 * only exists at runtime.
 *
 * Measured, not inferred: selecting an account in the sheet form sent
 *
 * ```json
 * {"title":"…","accountId":{"label":"IRR ۱٬۰۰۰٬۰۰۰٬۰۰۰","value":"e2e00000-…"}}
 * ```
 *
 * to `POST /sheets`, and `SheetController::create(?string $title, ?string $accountId)` raised
 * `TypeError: Argument #2 ($accountId) must be of type ?string, array given`. The AppFramework turns
 * that `TypeError` into a **400 with an empty body**, which is not an OCS envelope, so the API client
 * classified it as `NETWORK_FAILURE` and the purchaser saw a generic network error for what was a
 * client-side serialisation defect. Unit tests did not catch it either: they assign the model value
 * directly and never perform a selection.
 *
 * ## Why a `reduce` and not a read at each call site
 *
 * Reading `option.value` at seven call sites is seven chances to forget one, and each omission is a
 * 400 with an empty body that reads as a network fault. `:reduce` is the library's own supported
 * mechanism and it is bidirectional: `vue-select`'s `findOptionFromReducedValue()` matches the stored
 * primitive back to its option, so the selection still renders its label.
 *
 * `:get-option-key` is passed for the same reason. Its default derives an option's identity from
 * `option.id` or, failing that, from `JSON.stringify` of the whole option — and a *label* carrying a
 * live account balance changes whenever a charge is settled, so a balance-labelled option would lose
 * its identity and its selection the moment the balance moved.
 */
export function selectOptionValue(option: unknown): string {
	if (typeof option === 'string') return option
	if (typeof option !== 'object' || option === null) return ''
	const value = (option as { value?: unknown }).value
	return typeof value === 'string' ? value : ''
}

/** Identity of a `<NcSelect>` option, independent of its label. See {@link selectOptionValue}. */
export function selectOptionKey(option: unknown): string {
	return selectOptionValue(option)
}
