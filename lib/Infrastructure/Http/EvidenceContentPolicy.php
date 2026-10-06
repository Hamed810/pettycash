<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

/**
 * FR-060: how stored evidence bytes are served, as a function of their stored media type.
 *
 * ## The rule this exists to enforce
 *
 * FR-060 draws one line and puts the reason in the requirement itself: a response whose stored media
 * type is **inline-safe** -- images, PDF and plain text -- is sent with that stored media type and an
 * `inline` disposition, "so it renders". **Any other media type** is sent as `application/octet-stream`
 * with an `attachment` disposition, "so it is offered as a download and is never rendered in the
 * Nextcloud origin, because an attacker-supplied type served inline would execute as script in the
 * viewer's session."
 *
 * That last clause is the whole reason this is a policy object rather than a header line. The stored
 * media type is **whatever the uploading client declared**: `EvidenceController::upload()` keeps
 * `$file['mediaType']` verbatim because FR-030 defers classification and redaction to V2. So the type
 * is attacker-supplied by construction, and serving it back verbatim with a caller-chosen `inline`
 * disposition is a stored-XSS vector in the Nextcloud origin -- not a hypothetical one, and not one the
 * uploader's own good intentions constrain.
 *
 * ## Why the caller cannot choose their way out of it
 *
 * FR-060 also says "A caller MUST be able to request either disposition explicitly", and that is
 * honoured -- for an inline-safe type. `?disposition=attachment` on a PDF is a download; `?disposition=
 * inline` on a PDF is a preview. What the caller may **not** do is have a non-inline-safe type rendered
 * in the origin, because FR-060's disposition freedom exists so a user can *choose* preview versus
 * download, not so a client can opt into executing an uploaded `.html` inside someone's session.
 *
 * So the request is honoured on the disposition and ignored on the content type. A non-inline-safe
 * type is forced to `application/octet-stream` **and** to `attachment`, and the forced value is what the
 * response carries -- not the value that was asked for.
 *
 * ## Why the list is short and does not include SVG
 *
 * FR-060 names three families -- images, PDF, plain text -- and T275 owns naming the concrete list. The
 * list below is exactly those three, and the omission that matters is **`image/svg+xml`**. SVG is an
 * image, it is the image format people most expect receipts and diagrams to be in, and it can carry
 * `<script>`. Including it would satisfy a literal reading of "images" while reintroducing precisely
 * the harm the requirement was written to prevent. Anything not named here falls to the safe side, which
 * is the direction that has to fail: an unrecognised type is offered as a download.
 *
 * `text/plain` is included because browsers render it as text and execute nothing in it. It is *not*
 * accompanied by `X-Content-Type-Options: nosniff` here, because FR-060 does not ask for it and adding
 * headers is FR-030's deferred territory; the type is fixed and inline, so a browser is not being asked
 * to guess.
 *
 * ## Media type parameters are stripped for matching only
 *
 * A stored `text/plain; charset=utf-8` is still plain text, so the match ignores parameters. For an
 * inline-safe type the **stored string is returned verbatim**, parameters included, because it is the
 * value the uploader declared and the one the browser needs in order to read the bytes correctly.
 */
final class EvidenceContentPolicy
{
    /**
     * The types FR-060 renders in place.
     *
     * `image/svg+xml` is deliberately absent -- see the class docblock. `application/octet-stream` is
     * the fallback rather than a member: a file that declares it is already being served as a download.
     *
     * @var list<string>
     */
    public const INLINE_SAFE_MEDIA_TYPES = [
        'application/pdf',
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
        'image/bmp',
        'text/plain',
    ];

    /** What a non-inline-safe type is served as, per FR-060. */
    public const FALLBACK_MEDIA_TYPE = 'application/octet-stream';

    /**
     * The `Content-Type` and `Content-Disposition` this evidence is served with.
     *
     * @param string $storedMediaType the type recorded at upload, verbatim
     * @param string|null $requestedDisposition `inline`, `attachment`, or null for the `inline` default
     * @return array{contentType: string, disposition: string}
     */
    public function resolve(string $storedMediaType, ?string $requestedDisposition = null): array
    {
        // An empty or absent type is the same case as an unrecognised one: the upload path already
        // substitutes the fallback for an empty declaration, and either way there is no known-safe type
        // to render, so it is offered as a download.
        if (!$this->isInlineSafe($storedMediaType)) {
            return [
                'contentType' => self::FALLBACK_MEDIA_TYPE,
                'disposition' => 'attachment',
            ];
        }

        // FR-060 lets the caller pick, and `inline` is the documented default. An unrecognised value
        // falls to `inline` rather than to `attachment`: the value can only come from a client that
        // sent something the contract's `enum` forbids, and for an inline-safe type both dispositions
        // are safe, so there is nothing to protect by guessing defensively here.
        return [
            'contentType' => $storedMediaType,
            'disposition' => $requestedDisposition === 'attachment' ? 'attachment' : 'inline',
        ];
    }

    /**
     * Whether this stored media type may be rendered in the Nextcloud origin.
     *
     * Comparison is on the type/subtype with any parameters dropped, case-insensitively, because
     * `Text/Plain; charset=UTF-8` and `text/plain` are the same type and a browser treats them so.
     */
    public function isInlineSafe(string $storedMediaType): bool
    {
        $type = strtolower(trim(explode(';', $storedMediaType, 2)[0]));

        return in_array($type, self::INLINE_SAFE_MEDIA_TYPES, true);
    }
}