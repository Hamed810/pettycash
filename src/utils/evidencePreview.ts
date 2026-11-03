/**
 * The server's closed inline-safe media-type set, mirrored for the client (005 FR-005-042 / C-16).
 *
 * The authority is `lib/Infrastructure/Http/EvidenceContentPolicy.php`: it serves exactly these
 * types inline and everything else as `application/octet-stream` with an attachment disposition.
 * The list is deliberately not a prefix rule. `image/*` would admit `image/svg+xml`, which is an
 * executable document in this origin, and `text/*` would admit `text/html`.
 */
export const INLINE_SAFE_MEDIA_TYPES = [
	'application/pdf',
	'image/png',
	'image/jpeg',
	'image/gif',
	'image/webp',
	'image/bmp',
	'text/plain',
] as const

/** Parameters are dropped and case folded, because `text/plain; charset=utf-8` is still plain text. */
export function normalizedMediaType(mediaType: string | null | undefined): string {
	return (mediaType ?? '').split(';')[0]?.trim().toLowerCase() ?? ''
}

export function isInlineSafeMediaType(mediaType: string | null | undefined): boolean {
	return (INLINE_SAFE_MEDIA_TYPES as readonly string[]).includes(normalizedMediaType(mediaType))
}
