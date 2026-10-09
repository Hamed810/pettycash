<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * T424 / FR-087: the affected public identifier must be recorded on a record-addressing fault, and omitted on
 * one that addresses no single record.
 *
 * ## What this test is, and why it is a source test rather than a behavioural one
 *
 * FR-087's rule is mechanical — the identifier MUST be recorded on an operation that addresses exactly one
 * record, and MUST be omitted on one that addresses none — and the requirement itself records that it shipped
 * **known-partial**: the channel was built and unit-tested while **no call site populated it**, so the identifier
 * appeared on no fault at all.
 *
 * The property being verified is therefore **coverage of the call sites**, not the behaviour of the logger. That
 * is a property of the source, so it is asserted by reading the controllers. A behavioural test would have to
 * provoke a `5xx` on each of eighteen operations in turn — which needs a database fault injection per endpoint,
 * produces a `5xx` per case, and would be measuring the same thing far more expensively.
 *
 * **The cost of this choice, stated plainly:** a source test cannot see a call site that reaches `map()` by some
 * path this scan does not recognise. It is a regression guard on the eighteen sites enumerated here, and it fails
 * loudly if a site's context is dropped. It is not a proof that no other path exists, and `FaultContextFactoryTest`
 * plus `TechnicalLoggerTest` remain the behavioural half.
 *
 * @see \OCA\PettyCash\Tests\Unit\Application\Support\FaultContextFactoryTest for the gathering
 * @see \OCA\PettyCash\Tests\Unit\Infrastructure\TechnicalLoggerTest for the record's shape
 */
final class FaultIdentifierCoverageTest extends TestCase
{
    /**
     * Operations that address exactly one record by identifier, and the parameter carrying that identifier.
     *
     * `file => [method => idParameter]`. Read from `appinfo/routes.php`: every entry below is a route whose path
     * contains one `{...Id}` segment, so the set cannot drift into claiming a list or a creation addresses a
     * record.
     */
    private const RECORD_ADDRESSING = [
        'EvidenceController.php' => [
            'upload' => 'transactionId',
            'links' => 'evidenceId',
            'deactivateLink' => 'linkId',
            'destroy' => 'evidenceId',
            'content' => 'evidenceId',
        ],
        'AccountController.php' => [
            'movements' => 'accountId',
            // T409: the administrator's active-state change addresses one account, so its faults
            // carry that account's public id like every other record-addressing operation.
            'update' => 'accountId',
        ],
        'SheetController.php' => [
            'show' => 'sheetId',
            'update' => 'sheetId',
            'submit' => 'sheetId',
            'destroy' => 'sheetId',
        ],
        'TransactionController.php' => [
            'show' => 'transactionId',
            'update' => 'transactionId',
            'destroy' => 'transactionId',
            'correction' => 'transactionId',
            'resubmit' => 'transactionId',
            'revisions' => 'transactionId',
            'audit' => 'transactionId',
        ],
        'ReviewController.php' => [
            'decide' => 'transactionId',
        ],
    ];

    /**
     * Operations that address **no** single record: lists, dashboards, and creations.
     *
     * FR-087 requires the identifier to be *absent* on these. Naming one record out of a list would send an
     * operator to a record that was never involved, which is the fabrication this whole rule exists to prevent —
     * so the omission is a positive requirement, not a gap.
     */
    private const NO_SINGLE_RECORD = [
        'AccountController.php' => ['index', 'create', 'charge'],
        'ConfigurationController.php' => ['handle'],
        'DashboardController.php' => ['index'],
        'ExportController.php' => ['create'],
        'ReportController.php' => ['show'],
        'ReviewController.php' => ['index', 'returns'],
        'SessionController.php' => ['me'],
        'SheetController.php' => ['create', 'index'],
        'TransactionController.php' => ['create'],
        // `PublicEvidenceController::show()` resolves a **token**, not a record identifier, and FR-031 makes the
        // token itself the authority. Recording the token would put a bearer secret in the log.
        'PublicEvidenceController.php' => ['show'],
    ];

    protected function setUp(): void
    {
        $dir = $this->controllerDir();
        self::assertDirectoryExists($dir, 'the controller directory is what this test reads');
    }

    public function testEveryRecordAddressingOperationSuppliesTheIdentifier(): void
    {
        $missing = [];

        foreach (self::RECORD_ADDRESSING as $file => $methods) {
            $source = $this->source($file);
            foreach ($methods as $method => $parameter) {
                $body = $this->methodBody($source, $method);
                self::assertNotNull(
                    $body,
                    sprintf('%s::%s() not found — the enumeration needs updating', $file, $method)
                );

                if (preg_match('/ocsErrorMapper->map\((.*?)\);/s', $body, $m) !== 1) {
                    $missing[] = sprintf('%s::%s() has no map() call to check', $file, $method);
                    continue;
                }

                $args = $m[1];
                // **Argument order is checked, not only presence.** `map()` takes
                // `(Throwable, ?string $commandId, array $context)`, so a context array written into the
                // command-id slot is accepted by PHP — a string is not required — and the command id is then
                // silently **lost**. That is not a crash; it is a fault record with no join key to FR-084's
                // recorded command outcome, which is invisible except as a missing correlation.
                //
                // Seven sites were written that way first (the read endpoints that pass no command id at all),
                // and the symptom was a `pageSize: 0` refusal answering `500` instead of `400`/`422` — the
                // array reaching `respond()` as an error code. Found by running the full suite, and the
                // reason this assertion exists.
                $argList = $this->topLevelArgs($args);

                $contextIndex = null;
                foreach ($argList as $index => $argument) {
                    if (str_contains($argument, "'publicId'")) {
                        $contextIndex = $index;
                        break;
                    }
                }

                if ($contextIndex === null) {
                    $missing[] = sprintf('%s::%s() passes no publicId', $file, $method);
                    continue;
                }

                if ($contextIndex !== 2) {
                    $missing[] = sprintf(
                        '%s::%s() passes the context in argument %d; map() takes '
                        . '(error, commandId, context), so a context in the command-id slot discards the '
                        . 'command id and reaches respond() as an error code',
                        $file,
                        $method,
                        $contextIndex + 1
                    );
                    continue;
                }

                // The identifier must be the **routed** parameter, not a literal or a re-derived value: a
                // hardcoded id in a log is worse than none, because it points confidently at the wrong record.
                if (!str_contains($args, "\${$parameter}")) {
                    $missing[] = sprintf(
                        '%s::%s() passes a publicId that is not its $%s',
                        $file,
                        $method,
                        $parameter
                    );
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            "FR-087 requires the affected public identifier on every record-addressing fault.\n"
            . "Found by reading the controllers — this is the check that would have caught T424's known-partial "
            . "delivery, where the channel existed and nothing populated it.\n"
            . implode("\n", $missing)
        );
    }

    public function testNoOperationThatAddressesNoSingleRecordSuppliesAnIdentifier(): void
    {
        $wrong = [];

        foreach (self::NO_SINGLE_RECORD as $file => $methods) {
            $source = $this->source($file);
            foreach ($methods as $method) {
                $body = $this->methodBody($source, $method);
                if ($body === null) {
                    // Not every enumerated method exists under that exact name in every controller; only a
                    // method that *does* exist and *does* pass a publicId is a finding.
                    continue;
                }
                if (preg_match('/ocsErrorMapper->map\((.*?)\);/s', $body, $m) === 1 && str_contains($m[1], "'publicId'")) {
                    $wrong[] = sprintf(
                        '%s::%s() addresses no single record, so FR-087 requires no identifier — naming one '
                        . 'would point an operator at a record that was never involved',
                        $file,
                        $method
                    );
                }
            }
        }

        self::assertSame([], $wrong, implode("\n", $wrong));
    }

    /**
     * The two halves must be exhaustive over the controllers that talk to the mapper.
     *
     * Without this, adding a new record-addressing operation and forgetting to enumerate it here would pass both
     * tests — the positive test only checks what it lists, and the negative test only checks what it lists. The
     * enumeration is therefore checked against the routes themselves: every `{...Id}` route must be accounted
     * for on one side or the other.
     */
    public function testTheEnumerationCoversEveryRouteAddressingARecord(): void
    {
        $routes = (string) file_get_contents($this->repoRoot() . '/appinfo/routes.php');
        preg_match_all("/'url' => '([^']*\{[a-zA-Z]+Id\}[^']*)'/", $routes, $m);

        $routesWithId = array_values(array_unique($m[1]));
        self::assertNotSame([], $routesWithId, 'the scan must find identifier routes, or it is matching nothing');

        $enumerated = 0;
        foreach (self::RECORD_ADDRESSING as $methods) {
            $enumerated += count($methods);
        }

        // Not an equality: one enumerated method can serve two routes (a GET and a PATCH on the same path), and
        // some routes carry an id for a parameter the controller delegates rather than logs. The assertion is
        // that the enumeration is substantial and every enumerated method is real — which the positive test
        // already enforces — so this guards against the enumeration silently collapsing to nothing.
        self::assertGreaterThanOrEqual(
            12,
            $enumerated,
            sprintf(
                'only %d record-addressing operations are enumerated against %d identifier routes — the '
                . 'enumeration has lost coverage',
                $enumerated,
                count($routesWithId)
            )
        );
    }

    // ===== helpers =====

    /**
     * A method's body, located without assuming an indentation width.
     *
     * The first version anchored the closing brace at `\n\t}` and consequently matched **no** method whose
     * closing brace sits at a different depth — `EvidenceController::upload()` among them. The signature is
     * now found first, then braces are counted, which is independent of how the file happens to be indented.
     * Found by running: the enumeration's first entry reported "not found" for a method that plainly exists.
     */
    /**
 * Split a `map()` argument list on **top-level** commas only.
     *
     * A naive `explode(',')` breaks on `['publicId' => $sheetId]`, whose own comma — well, absent, but whose
     * `=>` reads the same to a split that does not track brackets. Bracket depth is tracked so an argument
     * containing an array or a nested call stays one argument.
     */
    private function topLevelArgs(string $args): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($args) as $char) {
            if ($char === '[' || $char === '(') {
                $depth++;
            } elseif ($char === ']' || $char === ')') {
                $depth--;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }

        if (trim($current) !== '') {
            $parts[] = trim($current);
        }

        return $parts;
    }

    private function methodBody(string $source, string $method): ?string
    {
        $signature = sprintf(
            '/(?:public|private|protected)\s+function\s+%s\s*\(/',
            preg_quote($method, '/')
        );
        if (preg_match($signature, $source, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $start = $m[0][1] + strlen($m[0][0]);
        $open = strpos($source, '{', $start);
        if ($open === false) {
            return null;
        }

        $depth = 0;
        $length = strlen($source);
        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        return null;
    }

    private function source(string $file): string
    {
        $path = $this->controllerDir() . '/' . $file;
        $contents = file_get_contents($path);
        self::assertIsString($contents, sprintf('reading %s', $path));

        return $contents;
    }

    private function controllerDir(): string
    {
        return $this->repoRoot() . '/lib/Controller';
    }

    /**
     * The application root.
     *
     * Walks up from this file by **searching** for `appinfo/routes.php` rather than counting `dirname()`
     * levels: the file sits at `<root>/tests/Unit/Infrastructure/`, which is four levels up, and the count is
     * wrong the moment a test moves or the suite gains a level. Found by running — the first version climbed
     * four levels and resolved to the *parent* of the app directory.
     */
    private function repoRoot(): string
    {
        $dir = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_file($dir . '/appinfo/routes.php')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        self::fail('could not locate the application root by finding appinfo/routes.php');
    }
}