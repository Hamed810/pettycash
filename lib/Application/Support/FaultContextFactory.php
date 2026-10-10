<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Support;

use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\RequestRoute;

/**
 * Decides what a technical fault records. FR-087 (clarified 2026-10-08).
 *
 * ## Why this class exists at all
 *
 * FR-087 requires five details on every `5xx`: actor, route, `X-PettyCash-Command-ID` where supplied, the
 * affected public identifier where known, and the exception class. Before it, `TechnicalLogger` recorded
 * two of them and nothing owned the other three — so an implementation satisfied every requirement while
 * logging nothing an operator could act on, and FR-073's `5xx` figure could be reported as met on numbers
 * nobody could diagnose.
 *
 * The gathering was first written inside `OcsErrorMapper`, which cannot be tested at all: `tests/bootstrap.php`
 * does not bootstrap Nextcloud, so **no** `OCP\*` class is loadable in a PHPUnit run and the mapper's
 * constructor types cannot even be resolved. Moving the decision here — a class with no framework
 * dependency — is what turns FR-087 from an assertion-free sentence into one with executable tests.
 *
 * This is the same pattern `RequestTimeZone` and `UserDirectory` already follow for data that has to come
 * out of the platform: the *policy* is application code, the *lookup* is an adapter.
 *
 * ## Why actor and route are gathered here and the public id is not
 *
 * Both of the first two are knowable on every fault path: the mapper holds the request context and the
 * current user. The public identifier is **not** — on most paths neither the mapper nor the throwable knows
 * which record was addressed, which is exactly why FR-087 says "where one is known". A placeholder, or a
 * UUID scraped out of an exception message, would be a fabrication that sends an operator to a record that
 * was never involved. So `build()` merges whatever the caller supplies and does not invent a third value.
 */
final readonly class FaultContextFactory
{
    public function __construct(
        private CurrentUser $currentUser,
        private RequestRoute $requestRoute,
    ) {}

    /**
     * @param array<string,mixed> $extra caller-supplied detail, e.g. `['publicId' => …]`
     * @return array<string,mixed> keys for details that are actually known
     */
    public function build(array $extra = []): array
    {
        // Unknown details are **omitted**, not returned as null. FR-087's qualifier is "where known", and
        // `['actor' => null]` in a log line reads as the opposite claim — "the actor was nobody" — which
        // is different from "the actor was not known here" and is the false claim that would send an
        // operator looking for a missing user.
        //
        // `TechnicalLogger` filters nulls again on its own side. That is deliberate duplication rather
        // than redundancy: it filters the `extra` array this method forwards, which a caller may have
        // populated with an explicit null, and it is the layer that owns the final shape of a log record.
        return array_filter(
            [
                'actor' => $this->actor(),
                'route' => $this->route(),
            ] + $extra,
            static fn (mixed $value): bool => $value !== null,
        );
    }

    /**
     * The acting user, or `null` when there is none.
     *
     * `null` rather than `''`: a CLI or background fault has no actor, and an empty string would read as a
     * claim that the actor was a user whose name is blank. `TechnicalLogger` then drops the key entirely,
     * so a log says nothing rather than saying something false.
     */
    private function actor(): ?string
    {
        try {
            $uid = $this->currentUser->uid();
        } catch (\Throwable) {
            // The current user is not available on every execution path, and this runs while a fault is
            // already being recorded. Swallowing is correct here: an exception raised while building the
            // diagnostic record would replace a diagnosable 500 with a second, less diagnosable one.
            return null;
        }

        return $uid !== '' ? $uid : null;
    }

    /**
     * The method and path, or `null` when no request is in scope.
     *
     * Same reasoning as `actor()`: a background fault genuinely has no route, and the key should be absent
     * rather than present-and-blank.
     */
    private function route(): ?string
    {
        try {
            $route = $this->requestRoute->describe();
        } catch (\Throwable) {
            return null;
        }

        return $route !== '' ? $route : null;
    }
}