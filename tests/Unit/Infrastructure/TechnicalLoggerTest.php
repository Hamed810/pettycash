<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure;

use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * `TechnicalLogger::log()` did not exist, and one caller used it anyway.
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
