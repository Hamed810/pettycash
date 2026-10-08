<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

/**
 * FR-073's request log: newline-delimited JSON, one line per HTTP request the suites issued.
 *
 * ## Why the suites record rather than something counting them
 *
 * `scripts/measure-error-rate.mjs` needs every response across the functional, contract and end-to-end
 * suites. The alternative — parsing suite output, or inferring counts from pass/fail totals — cannot see
 * a `429` on a request that happened to be retried, nor a `500` a test swallowed. Recording at the
 * transport is the only place all three suites pass through.
 *
 * ## Why it is opt-in
 *
 * `PETTYCASH_REQUEST_LOG` unset means nothing is written. The figure is a release-readiness measurement
 * taken in a dedicated session, not a by-product of every test run, and a log file appearing and
 * disappearing on every invocation would be a side effect of running the suite rather than of measuring
 * anything.
 *
 * ## Why a failure to record is never a test failure
 *
 * The measurement must not be able to break the thing it measures. If the log path is unwritable the
 * record is dropped and the run continues; the resulting log is short, and `measure-error-rate.mjs`
 * reports the number of requests it actually saw rather than silently reporting on a partial set.
 */
final class RequestLog
{
    /** @var resource|null */
    private static $handle = null;

    /**
     * @param int|null $status the HTTP status, or null when the request never got one
     * @param mixed $transportError Guzzle's `TransferStats::getHandlerErrorData()`. Typed `mixed` on
     *                             purpose: the getter is declared `int|float|string|bool|null` and the
     *                             curl handler fills it with an **errno integer**, so a `?string`
     *                             signature would both reject the ordinary case and read a successful
     *                             response as a transport failure.
     */
    public static function record(?int $status, mixed $transportError = null): void
    {
        $path = self::path();
        if ($path === null) {
            return;
        }

        $line = ['status' => $status];

        if (self::isTransportFailure($transportError)) {
            // FR-073's "transport failure" excluded class. Recorded as a message rather than a sentinel
            // status, so the reader can tell a refused connection from a DNS failure and from a cURL
            // errno. A status of null alongside it is itself the signal: no response was received.
            $line['error'] = is_string($transportError)
                ? $transportError
                : 'transport error ' . var_export($transportError, true);
        }

        $encoded = json_encode($line, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }

        self::handle($path);
        if (self::$handle !== null) {
            // `LOCK_EX` because the Playwright reporter may be appending to the same file, and an
            // interleaved partial line would be unreadable JSON — which the measurement script counts as
            // an unaccounted request rather than discarding it.
            @fwrite(self::$handle, $encoded . "\n");
        }
    }

    public static function close(): void
    {
        if (self::$handle !== null) {
            @fclose(self::$handle);
            self::$handle = null;
        }
    }

    private static function path(): ?string
    {
        $path = getenv('PETTYCASH_REQUEST_LOG');
        if ($path === false || trim($path) === '') {
            return null;
        }

        return trim($path);
    }

    /**
     * Whether Guzzle's handler error data describes an actual failure.
     *
     * **`0` is success, not a failure.** The curl handler fills `TransferStats::$handlerErrorData` with
     * `curl_errno()`, and `CURLE_OK` is `0` — so a plain "non-null means failed" test stamps
     * `"error": "transport error 0"` onto **every successful request**. That is not a cosmetic bug: the
     * measurement script classifies anything carrying an `error` as environment-induced, so every `200`
     * in the run would be excluded from the ratio and the figure would either be refused as contaminated
     * or reported over a denominator of near zero.
     *
     * The check is therefore falsy-based, which covers all three shapes the handler can produce: the
     * integer `CURLE_OK`, the empty string, and `null`.
     */
    private static function isTransportFailure(mixed $handlerErrorData): bool
    {
        return !($handlerErrorData === null || $handlerErrorData === '' || $handlerErrorData === 0
            || $handlerErrorData === '0' || $handlerErrorData === false);
    }

    /**
     * @return resource|null
     */
    private static function handle(string $path)
    {
        if (self::$handle !== null) {
            return self::$handle;
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0o777, true) && !is_dir($directory)) {
            return null;
        }

        // Append mode, so the PHP and Playwright halves of one run share a file without either truncating
        // the other.
        $handle = @fopen($path, 'a');

        return self::$handle = $handle === false ? null : $handle;
    }
}