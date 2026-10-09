<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure;

use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `TechnicalLogger` — its own surface, and the FR-087 details T418 added.
 *
 * ## The original defect this file was written for
 *
 * `ReportController::accountsByDbIds()` called `$this->logger->log('warning', ...)` on the branch
 * where a charged account no longer resolves. PHP resolves a method at call time, so the file parsed,
 * the suite stayed green, and the endpoint would have answered 500 with a `Call to undefined method`
 * on exactly the data fault an operator most wants recorded in the log.
 *
 * Nothing else could have caught it: the branch needs a charge naming an account that no longer
 * resolves, which no seeded fixture produces, and `tests/bootstrap.php` does not bootstrap
 * Nextcloud, so a test that reached the controller would have to mount the whole container.
 *
 * So the test is at the level the defect lives in -- the logger's own surface -- and it asserts the
 * two things that make the call safe: the method exists, and what it records is the message and
 * context rather than a reinterpretation of them.
 *
 * ## What T418 added, and where its assertions live
 *
 * FR-087 (clarified 2026-10-08) requires five details on every `5xx`. Before it, this class recorded
 * two: `commandId` and the throwable. The other three — **actor**, **route**, and the **affected public
 * identifier where known** — had no owner, so an implementation satisfied every requirement while
 * logging nothing an operator could act on.
 *
 * These tests pin the logger's half: the `exceptionClass` key, the pass-through of caller context, and
 * the omission of `null`s. **The `actor` and `route` half is asserted in
 * `tests/Contract/Controller/UnexpectedFaultLoggingTest.php`**, because those are gathered by
 * `OcsErrorMapper` — the one layer holding both the request and the current user — and asserting them
 * here would mean constructing the mapper, which this file deliberately does not reach for.
 */
final class TechnicalLoggerTest extends TestCase
{
    public function testLogRecordsTheMessageAtTheGivenLevel(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->log('warning', 'a charged account could not be resolved', [
            'accountDbId' => 7,
        ]);

        $this->assertCount(1, $psr->records);
        $this->assertSame('warning', $psr->records[0]['level']);
        $this->assertSame('a charged account could not be resolved', $psr->records[0]['message']);
    }

    public function testLogKeepsTheCallersContextAndTagsTheApp(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->log('warning', 'a charged account could not be resolved', [
            'accountDbId' => 7,
        ]);

        $context = $psr->records[0]['context'];

        $this->assertSame('pettycash', $context['app'], 'every record must be attributable to the app');
        $this->assertSame(7, $context['accountDbId'], "the caller's context must survive");
    }

    public function testLogDoesNotLetTheAppTagOverwriteTheCallers(): void
    {
        // `['app' => 'pettycash'] + $context` puts the app tag first so a caller cannot silently
        // mislabel a record as coming from another app. Asserted because the other order is the
        // obvious way to write it.
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->log('warning', 'x', ['app' => 'some-other-app']);

        $this->assertSame('pettycash', $psr->records[0]['context']['app']);
    }

    public function testUnexpectedStillRecordsTheThrowable(): void
    {
        $psr = $this->recordingLogger();
        $error = new RuntimeException('boom');
        (new TechnicalLogger($psr))->unexpected($error, 'cmd-1');

        $this->assertSame('error', $psr->records[0]['level']);
        $this->assertSame('cmd-1', $psr->records[0]['context']['commandId']);
        $this->assertSame($error, $psr->records[0]['context']['exception']);
    }

    /**
     * FR-087 names the **exception class** explicitly, so it is stated as its own key.
     *
     * The throwable is already in the context and PSR-3 would render its class inside a trace, but that
     * requires the reader to parse a rendered stack to learn whether this was a database, filesystem, or
     * programming fault — the one thing that decides where an operator looks next.
     */
    public function testUnexpectedNamesTheExceptionClass(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->unexpected(new RuntimeException('boom'), 'cmd-1');

        $this->assertSame(
            RuntimeException::class,
            $psr->records[0]['context']['exceptionClass'],
            'the exception class must be readable without parsing a rendered trace'
        );
    }

    /**
     * FR-087's `actor`, `route` and `publicId` reach the log through the caller's context.
     *
     * Asserted as pass-through rather than as keys the logger produces: the logger has no request and no
     * user, so `OcsErrorMapper` gathers them and this class must not drop them.
     */
    public function testUnexpectedForwardsTheCallersContext(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->unexpected(
            new RuntimeException('boom'),
            'cmd-1',
            ['actor' => 'purchaser-1', 'route' => 'GET /sheets/abc', 'publicId' => 'sheet-abc']
        );

        $context = $psr->records[0]['context'];

        $this->assertSame('purchaser-1', $context['actor']);
        $this->assertSame('GET /sheets/abc', $context['route']);
        $this->assertSame('sheet-abc', $context['publicId']);
        $this->assertSame('pettycash', $context['app']);
    }

    /**
     * An **unknown** detail is omitted, not recorded as empty.
     *
     * `['actor' => null]` reads as a claim — "the actor was nobody" — which is a different and wrong
     * statement from "the actor was not known here". FR-087's "where known" depends on that distinction,
     * so `array_key_exists` is the assertion rather than a null check.
     */
    public function testUnexpectedOmitsAnUnknownDetailRatherThanRecordingItAsEmpty(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->unexpected(
            new RuntimeException('boom'),
            null,
            ['actor' => null, 'publicId' => null]
        );

        $context = $psr->records[0]['context'];

        $this->assertArrayNotHasKey(
            'actor',
            $context,
            'an absent actor must be absent from the record, not present-and-null'
        );
        $this->assertArrayNotHasKey('publicId', $context);
        $this->assertArrayHasKey(
            'exceptionClass',
            $context,
            'the always-known details must survive the filtering'
        );
    }

    /**
     * `log()` — the non-exception path — is unaffected by the filtering, so a caller recording a warning
     * with an explicitly null value still sees it.
     *
     * Without this the `withoutEmpty()` filter could be moved onto `log()` by a later reader and only the
     * exception path would be tested.
     */
    public function testLogIsNotFiltered(): void
    {
        $psr = $this->recordingLogger();
        (new TechnicalLogger($psr))->log('warning', 'x', ['accountDbId' => null]);

        $this->assertArrayHasKey('accountDbId', $psr->records[0]['context']);
    }

    /**
     * @return LoggerInterface&object{records: list<array{level: mixed, message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): object
    {
        return new class implements LoggerInterface {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function emergency(string|\Stringable $message, array $context = []): void
            {
                $this->record('emergency', $message, $context);
            }

            public function alert(string|\Stringable $message, array $context = []): void
            {
                $this->record('alert', $message, $context);
            }

            public function critical(string|\Stringable $message, array $context = []): void
            {
                $this->record('critical', $message, $context);
            }

            public function error(string|\Stringable $message, array $context = []): void
            {
                $this->record('error', $message, $context);
            }

            public function warning(string|\Stringable $message, array $context = []): void
            {
                $this->record('warning', $message, $context);
            }

            public function notice(string|\Stringable $message, array $context = []): void
            {
                $this->record('notice', $message, $context);
            }

            public function info(string|\Stringable $message, array $context = []): void
            {
                $this->record('info', $message, $context);
            }

            public function debug(string|\Stringable $message, array $context = []): void
            {
                $this->record('debug', $message, $context);
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->record($level, $message, $context);
            }

            /** @param array<string, mixed> $context */
            private function record(mixed $level, string|\Stringable $message, array $context): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }
}
