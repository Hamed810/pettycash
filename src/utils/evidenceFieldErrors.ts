/**
 * The per-transaction evidence field keys the server returns from sheet submission and correction
 * resubmission.
 *
 * FR-091 names the key `transactions.<transactionId>.evidence`, and the same key is used by both
 * commands. Parsing it in one place keeps the two screens from disagreeing about which transaction a
 * refusal belongs to. A generic `evidence` key is deliberately ignored: it is not one of the two
 * contract paths, and treating it as a transaction would invent an id the server never sent.
 */
export interface EvidenceFieldError {
	transactionId: string
	messages: string[]
}

const EVIDENCE_KEY = /^transactions\.([^.]+)\.evidence$/

export function parseEvidenceFieldErrors(
	fieldErrors: Record<string, string[]> | undefined
): EvidenceFieldError[] {
	if (!fieldErrors) return []
	return Object.entries(fieldErrors).flatMap(([field, messages]) => {
		const match = EVIDENCE_KEY.exec(field)
		if (!match?.[1] || messages.length === 0) return []
		return [{ transactionId: match[1], messages }]
	})
}
