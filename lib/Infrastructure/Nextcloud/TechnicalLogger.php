<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The one place that decides what a technical fault records.
 *
 * FR-087 (clarified 2026-10-08) requires five details on every `5xx`: the actor, the route, the
 * `X-PettyCash-Command-ID` where supplied, the affected public identifier where known, and the exception
 * class. Before it, this class recorded two of them — `commandId` and the throwable — and nothing owned the
 * other three, so an implementation satisfied every requirement while logging nothing an operator could act
 * on. That matters beyond tidiness: FR-073 requires the `5xx` rate to be *measured* at release readiness, and
 * a figure nobody can diagnose is not a readiness signal.
 *
 * ## Why `actor` and `route` are supplied by the caller rather than read here
 *
 * Both are properties of the **request**, and `TechnicalLogger` has no request: it is constructed once and
 * injected wherever a fault needs recording, including `ReportController`, which logs outside any OCS
 * response. Guessing the actor from a global would be a guess. `OcsErrorMapper` — which *does* hold the
 * request and the current user — gathers them and passes them in, so every one of the 35 `->map()` call
 * sites is enriched by one change rather than by 35.
 *
 * ## Why `publicId` is an input rather than something extracted
 *
 * FR-087 says "where one is known", and that qualifier is load-bearing. On most `5xx` paths the throwable
 * does not carry a public identifier and the mapper does not know which record was addressed — so there is
 * honestly nothing to record, and a placeholder or a UUID scraped out of an exception message would be a
 * fabrication. Callers that *do* know pass `['publicId' => …]`; `OcsErrorMapper::map()` forwards a context
 * array so that is possible without changing this class's contract. The key is omitted rather than set to
 * `null` so a reader can tell "not known" from "known to be empty".
 */
final readonly class TechnicalLogger
{
    public function __construct(private LoggerInterface $logger) {}

    /**
     * Record an unexpected fault.
     *
     * @param array<string,mixed> $context extra details the caller knows and this layer cannot infer.
     *                                      Recognised keys: `actor`, `route`, `publicId`. Unknown keys are
     *                                      passed through, so a caller may add a domain detail without
     *                                      this class having to know about it.
     */
    public function unexpected(Throwable $error, ?string $commandId = null, array $context = []): void
    {
        $this->logger->error('PettyCash operation failed', [
            'app' => 'pettycash',
            'commandId' => $commandId,
            // The throwable is logged as the throwable, so PSR-3 renders its class, message, and file/line.
            // The class is what tells an operator whether this is a database, filesystem, or programming
            // fault, and FR-087 names it explicitly — so it is also stated as its own key rather than left
            // for the reader to find inside a rendered trace.
            'exceptionClass' => $error::class,
            'exception' => $error,
        ] + self::withoutEmpty($context));
    }

    /**
     * Record something an operator needs to know that is not an exception.
     *
     * Added because `ReportController::accountsByDbIds()` called `$this->logger->log(...)` on a class
     * that only had `unexpected()`. PHP resolves that at call time, not compile time, so the file
     * parsed, the type checker flagged it, and the endpoint answered 500 with a
     * `Call to undefined method` **only when the branch was reached** -- a charged account that no
     * longer resolves. That is the worst shape a latent defect can have: a code path exercised by
     * nothing, failing on exactly the data fault an operator would most want logged.
     *
     * @param array<string, mixed> $context
     */
    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, ['app' => 'pettycash'] + $context);
    }

    /**
     * Drop `null` values so an unknown detail is absent rather than present-and-empty.
     *
     * `['actor' => null]` reads as "the actor was nobody", which is a different and wrong claim from "the
     * actor was not known here" — the distinction FR-087's "where known" depends on.
     *
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private static function withoutEmpty(array $context): array
    {
        return array_filter($context, static fn (mixed $value): bool => $value !== null);
    }
}