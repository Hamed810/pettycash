<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure\Http;

use OCA\PettyCash\Infrastructure\Http\EvidenceContentPolicy;
use PHPUnit\Framework\TestCase;

/**
 * T275 / FR-060: which stored media types may be rendered in the Nextcloud origin.
 *
 * ## Why this is a unit test and not only a contract test
 *
 * FR-060's rule is about two response headers, and the pair that matters can only be observed together:
 * a type is either served with its own media type **and** `inline`, or as `application/octet-stream`
 * **and** `attachment`. Asserting one without the other accepts the failure that actually occurs --
 * a correct type under the wrong disposition, which is what makes a browser render something it should
 * not. `EvidenceLifecycleControllerTest` and `PurchaserFlowControllerTest` drive the endpoint over HTTP
 * and can see both headers, but they see them one media type at a time and each request is one more
 * against the shared instance.
 *
 * So the rule is a named object and the table is pinned here, exhaustively, with no HTTP at all. The
 * contract suite then proves the controller *uses* the policy; it does not have to re-derive it.
 *
 * ## The case that matters most is the one no browser shows you
 *
 * `EvidenceController::upload()` stores `$file['mediaType']` verbatim, because FR-030 defers
 * classification and redaction to V2. The stored type is therefore attacker-supplied by construction,
 * and an uploader who declares `text/html` must not get it executed in a reviewer's session. That is
 * asserted directly, and it is the assertion that would fail if the controller went back to trusting
 * the caller.
 */
final class EvidenceContentPolicyTest extends TestCase
{
    private EvidenceContentPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new EvidenceContentPolicy();
    }

    /**
     * Every type FR-060 renders in place, with its own type and `inline` by default.
     *
     * Data-provider driven rather than a loop over the constant, so that **adding** a type to the
     * constant without deciding its disposition fails here: the provider and the constant are
     * compared in `testTheInlineSafeListIsExactlyWhatTheProviderCovers`.
     *
     * @return list<array{string}>
     */
    public static function inlineSafeProvider(): array
    {
        return array_map(static fn (string $t): array => [$t], EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES);
    }

    /** @dataProvider inlineSafeProvider */
    public function testAnInlineSafeTypeIsServedWithItsOwnTypeAndRendersInline(string $mediaType): void
    {
        self::assertSame(
            ['contentType' => $mediaType, 'disposition' => 'inline'],
            $this->policy->resolve($mediaType),
            'FR-060 sends an inline-safe type with its stored media type and an inline disposition, '
            . 'so that it renders in place'
        );
    }

    /**
     * The download half of the same rule, which is FR-060's "a caller MUST be able to request either
     * disposition explicitly".
     *
     * Asserted per inline-safe type because the disposition freedom is the requirement's positive
     * half; without it the only way to satisfy the negative half would be to serve everything as a
     * download, which would make FR-060's preview journey unimplementable — the exact state
     * `contracts/ocs-api.yaml` was in before this task.
     *
     * @dataProvider inlineSafeProvider
     */
    public function testAnInlineSafeTypeCanStillBeRequestedAsADownload(string $mediaType): void
    {
        self::assertSame(
            ['contentType' => $mediaType, 'disposition' => 'attachment'],
            $this->policy->resolve($mediaType, 'attachment'),
            'FR-060 lets the caller choose the disposition for a type that is safe to render'
        );
    }

    // ===== THE CASE THAT IS A SECURITY PROPERTY =====

    /**
     * An attacker-declared type is never rendered, whatever the caller asks for.
     *
     * The three types here are the ones that turn "served inline" into "script executed in the
     * viewer's session": `text/html` is the direct one, and `image/svg+xml` and `application/xhtml+xml`
     * are the same capability wearing an image's clothes. FR-060 names the harm in its own words -- "an
     * attacker-supplied type served inline would execute as script in the viewer's session" -- and this
     * is that sentence as an assertion.
     *
     * The `inline` request is the important half: `?disposition=inline` is exactly what a naive client
     * sends, and it is exactly the request that must be overridden.
     */
    public function testAnAttackerSuppliedScriptableTypeIsNeverServedInline(): void
    {
        foreach (['text/html', 'image/svg+xml', 'application/xhtml+xml'] as $hostile) {
            self::assertSame(
                [
                    'contentType' => EvidenceContentPolicy::FALLBACK_MEDIA_TYPE,
                    'disposition' => 'attachment',
                ],
                $this->policy->resolve($hostile, 'inline'),
                "{$hostile} must be forced to octet-stream and attachment even when the caller asks "
                . 'for inline; the stored media type is whatever the uploader declared'
            );
        }
    }

    /**
     * SVG is the omission that a literal reading of "images" gets wrong.
     *
     * It is pinned on its own because it is the type most likely to be added later by someone reasoning
     * "SVG is an image, FR-060 says images render". It is an image, and it can carry `<script>`.
     */
    public function testSvgIsNotInlineSafeEvenThoughItIsAnImage(): void
    {
        self::assertFalse(
            $this->policy->isInlineSafe('image/svg+xml'),
            'SVG can carry script, so including it would reintroduce the harm FR-060 exists to prevent'
        );
        self::assertFalse($this->policy->isInlineSafe('image/svg+xml; charset=utf-8'));
    }

    // ===== THE FAIL-SAFE DIRECTION =====

    /**
     * Anything not on the list is offered as a download.
     *
     * A deny-by-default list is only worth having if the default is the safe side, so the unrecognised
     * cases are asserted rather than assumed: an unknown vendor type, a type with no slash, an empty
     * string, and the fallback naming itself.
     */
    public function testAnythingUnrecognisedIsOfferedAsADownload(): void
    {
        foreach (['application/vnd.acme.thing', 'application/zip', 'not-a-media-type', ''] as $unknown) {
            self::assertSame(
                [
                    'contentType' => EvidenceContentPolicy::FALLBACK_MEDIA_TYPE,
                    'disposition' => 'attachment',
                ],
                $this->policy->resolve($unknown),
                "an unrecognised type ({$unknown}) must fall to the safe side rather than be rendered"
            );
        }
    }

    /**
     * Parameters are ignored for matching and kept for the response.
     *
     * `text/plain; charset=utf-8` is still plain text, so it renders; and the browser needs the
     * charset to read the bytes, so the stored string is returned rather than a normalised `text/plain`.
     */
    public function testParametersAreStrippedForMatchingAndPreservedInTheResponse(): void
    {
        $stored = 'text/plain; charset=utf-8';

        self::assertTrue($this->policy->isInlineSafe($stored), 'a parameter does not change what the type is');
        self::assertSame(
            ['contentType' => $stored, 'disposition' => 'inline'],
            $this->policy->resolve($stored),
            'and the stored value is returned verbatim, because that is what tells the browser how to '
            . 'read the bytes'
        );
    }

    /**
     * Case is not a security boundary, so it is not treated as one.
     */
    public function testTheMatchIsCaseInsensitive(): void
    {
        self::assertTrue($this->policy->isInlineSafe('TEXT/PLAIN'));
        self::assertTrue($this->policy->isInlineSafe('Application/PDF'));
    }

    /**
     * A disposition outside the contract's `enum` falls to `inline` for an inline-safe type.
     *
     * Recorded because it is a choice rather than an oversight. `disposition` is declared
     * `enum: [inline, attachment]` in `contracts/ocs-api.yaml`, so an unrecognised value can only
     * arrive from a client that ignored the contract. For an inline-safe type both dispositions are
     * safe, so there is nothing to protect by defaulting defensively to `attachment` -- and a
     * non-inline-safe type is forced to `attachment` regardless, which is where the protection lives.
     */
    public function testAnUnrecognisedDispositionFallsToInlineForAnInlineSafeType(): void
    {
        self::assertSame(
            ['contentType' => 'application/pdf', 'disposition' => 'inline'],
            $this->policy->resolve('application/pdf', 'something-else')
        );
    }

    /**
     * The list and the provider cannot drift apart.
     *
     * Without this, adding a type to `INLINE_SAFE_MEDIA_TYPES` would be a one-line change that
     * silently renders it, with no test failing. That is the failure mode this whole file exists to
     * prevent, applied to itself.
     */
    public function testTheInlineSafeListIsExactlyWhatTheProviderCovers(): void
    {
        self::assertSame(
            EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES,
            array_map(static fn (array $row): string => $row[0], self::inlineSafeProvider()),
            'the provider and the constant must describe the same list'
        );

        self::assertContains(
            'application/pdf',
            EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES,
            'FR-060 names PDF explicitly'
        );
        self::assertContains(
            'text/plain',
            EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES,
            'FR-060 names plain text explicitly'
        );
        self::assertNotEmpty(
            array_filter(
                EvidenceContentPolicy::INLINE_SAFE_MEDIA_TYPES,
                static fn (string $t): bool => str_starts_with($t, 'image/')
            ),
            'FR-060 names images explicitly'
        );
    }
}