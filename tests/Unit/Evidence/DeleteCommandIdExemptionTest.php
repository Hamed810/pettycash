<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Evidence;

use PHPUnit\Framework\TestCase;

/**
 * T318: `contracts/ocs-api.yaml` must not require `X-PettyCash-Command-ID` on a `DELETE`.
 *
 * ## The defect
 *
 * The maintainer decision on T318 settled the artifact contradiction this repository had been
 * carrying: `contracts/workflow.md` requires the header for *non-repeatable* commands only, while
 * `contracts/ocs-api.yaml` had `$ref`ed `components/parameters/CommandId` — declared
 * `required: true` — onto two `DELETE` operations. A `DELETE` is repeatable, so on `workflow.md`'s
 * wording it is exempt, and the OpenAPI was requiring a header the Command Contract does not.
 *
 * `EvidenceController::destroy()` had already chosen the `workflow.md` answer in the *code* — it
 * required the header, so it chose the `ocs-api.yaml` answer instead — which is the exact silent
 * choice Constitution Governance forbids. This test pins the artifact side of the settlement so the
 * two files cannot drift apart again: a re-added `$ref` fails here rather than being discovered by
 * a client that stops sending the header.
 *
 * ## Why the YAML is read as text rather than parsed
 *
 * The container's PHP has no `yaml` extension (`yaml_parse_file` returns `false` under
 * `scripts/dev/in-container`), and no YAML library is vendored. Parsing it by hand would mean
 * reimplementing an indentation-sensitive format inside a test, where a mistake in the reader would
 * read as agreement with whatever the file happens to say. The text scan below is deliberately
 * narrow — it bounds each operation's block at the next verb key — because the earlier unbounded
 * search that T318 recorded produced two false positives whose `CommandId` belonged to a sibling
 * `POST`/`PATCH` on the same path. An unbounded `strpos` on the file would reintroduce exactly
 * those false positives, and would also flag every future non-repeatable command in the contract.
 *
 * ## What is deliberately still asserted
 *
 * The header stays `required: true` on every non-repeatable operation, and `workflow.md` keeps its
 * non-repeatable rule. Those two are the load-bearing half of the decision: removing the header from
 * the DELETEs is only correct while the rest of the contract still requires it where it must. So this
 * file also proves the exemption is narrow — a blanket removal would satisfy every assertion below
 * and would be a strictly worse contract.
 */
final class DeleteCommandIdExemptionTest extends TestCase
{
    private const TRANSACTION_DELETE = '/transactions/{transactionId}';
    private const EVIDENCE_DELETE = '/evidence/{evidenceId}';

    /**
     * Operations that stay non-repeatable and must keep requiring the header.
     *
     * `POST /transactions/{transactionId}/correction` is included deliberately: it is the operation
     * most likely to be swept along by a fix written for the DELETEs, and it is one of the four
     * FR-042 names.
     */
    private const STILL_REQUIRED = [
        '/transactions/{transactionId}/correction' => 'POST',
        '/transactions/{transactionId}/decisions' => 'POST',
        '/transactions/{transactionId}/resubmit' => 'POST',
        '/sheets/{sheetId}/submit' => 'POST',
        '/sheets/{sheetId}/transactions' => 'POST',
        '/transactions/{transactionId}' => 'PATCH',
    ];

    private function contract(): string
    {
        $path = dirname(__DIR__, 3) . '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml';
        self::assertFileExists($path, "the contract under test was not found at {$path}");

        return (string) file_get_contents($path);
    }

    /**
     * The block of lines belonging to one operation of one path.
     *
     * A path's own keys sit at two spaces and its verbs at four, so the block for `$verb` runs from
     * that verb line to the next line indented at four spaces or fewer — which is the next verb on
     * the same path, or the next path. Without that bound a missing `$verb` would match the whole
     * remainder of the file and report every `CommandId` in it.
     *
     * @return list<string>
     */
    private function operation(string $path, string $verb): array
    {
        $lines = explode("\n", $this->contract());

        $pathLine = '  ' . $path . ':';
        $start = null;
        foreach ($lines as $index => $line) {
            if (rtrim($line) === $pathLine) {
                $start = $index + 1;
                break;
            }
        }
        self::assertNotNull($start, "no path '{$path}' was found in contracts/ocs-api.yaml");

            // The contract spells verbs in lower case (`post:`, `patch:`, `delete:`), while the
            // callers here name them the way HTTP does. Normalised here rather than at each call
            // site: a case mismatch does not fail, it silently scans past the operation into the
            // next path and reports "no such verb" for an operation that exists.
            $verbLine = '    ' . strtolower($verb) . ':';
        $from = null;
        for ($index = $start; $index < count($lines); $index++) {
            if (rtrim($lines[$index]) === $verbLine) {
                $from = $index + 1;
                break;
            }
            // The next path begins before this verb was found, so this path has no such operation.
            if (preg_match('/^ {0,2}\S/', $lines[$index]) === 1) {
                return [];
            }
        }
        self::assertNotNull($from, "path '{$path}' declares no '{$verb}' operation");

        $block = [];
        for ($index = $from; $index < count($lines); $index++) {
            if (preg_match('/^ {0,4}\S/', $lines[$index]) === 1) {
                break;
            }
            $block[] = $lines[$index];
        }

        return $block;
    }

    /** @param list<string> $block */
    private function referencesCommandId(array $block): bool
    {
        foreach ($block as $line) {
            if (str_contains($line, 'parameters/CommandId')) {
                return true;
            }
        }

        return false;
    }

    public function testTheTransactionDeleteDoesNotRequireACommandIdHeader(): void
    {
        $block = $this->operation(self::TRANSACTION_DELETE, 'delete');

        self::assertNotEmpty($block, 'the DELETE /transactions/{transactionId} operation must exist');
        self::assertFalse(
            $this->referencesCommandId($block),
            'contracts/workflow.md requires X-PettyCash-Command-ID only for non-repeatable commands, and a '
            . 'DELETE is repeatable. Re-adding the $ref here would reinstate the contradiction T318 settles, '
            . 'and a conforming client that stopped sending the header would be refused'
        );
    }

    public function testTheEvidenceDeleteDoesNotRequireACommandIdHeader(): void
    {
        $block = $this->operation(self::EVIDENCE_DELETE, 'delete');

        self::assertNotEmpty($block, 'the DELETE /evidence/{evidenceId} operation must exist');
        self::assertFalse(
            $this->referencesCommandId($block),
            'the same contradiction applies to this DELETE: workflow.md exempts repeatable commands and '
            . 'the evidence delete is one, so the OpenAPI must not demand a header for it'
        );
    }

    /**
     * The path parameters must survive the edit.
     *
     * The `$ref` being removed is the header's, on the same `parameters` list as the path parameter.
     * An edit that took the whole list with it would produce a contract where the delete cannot
     * identify its target, and every assertion above would still pass because none of them looks at
     * the surviving parameter.
     */
    public function testBothDeletesStillIdentifyTheirTargetByPathParameter(): void
    {
        self::assertStringContainsString(
            'parameters/TransactionId',
            implode("\n", $this->operation(self::TRANSACTION_DELETE, 'delete')),
            'the transaction delete must still take its {transactionId} path parameter'
        );
        self::assertStringContainsString(
            'parameters/EvidenceId',
            implode("\n", $this->operation(self::EVIDENCE_DELETE, 'delete')),
            'the evidence delete must still take its {evidenceId} path parameter'
        );
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('stillRequiredOperations')]
    public function testNonRepeatableCommandsStillRequireACommandIdHeader(string $path, string $verb): void
    {
        self::assertTrue(
            $this->referencesCommandId($this->operation($path, $verb)),
            "{$verb} {$path} is a non-repeatable command and must keep requiring X-PettyCash-Command-ID. "
            . 'The T318 decision exempts two DELETE operations; it does not weaken the rule for the rest of '
            . 'the contract, and workflow.md is unchanged'
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function stillRequiredOperations(): iterable
    {
        foreach (self::STILL_REQUIRED as $path => $verb) {
            yield "{$verb} {$path}" => [$path, $verb];
        }
    }

    /**
     * The CommandId parameter itself keeps `required: true`.
     *
     * Its only definition is shared by every non-repeatable operation, so weakening it would make
     * every one of them optional in a single line without touching a single path block.
     */
    public function testTheCommandIdParameterDefinitionIsStillRequired(): void
    {
        self::assertMatchesRegularExpression(
            '/^ {4}CommandId:\n(?: {6}.*\n)*? {6}required: true$/m',
            $this->contract(),
            'components/parameters/CommandId must remain required: true for the operations that still use it'
        );
    }

    /**
     * `workflow.md` is the artifact the decision resolved in favour of, so its rule must survive
     * verbatim. This is a text pin rather than a prose check because the rule is a single sentence
     * that a future edit could soften into ambiguity ("may require", "where practical") without any
     * test failing.
     */
    public function testTheNonRepeatableCommandRuleIsUnchangedInTheWorkflowContract(): void
    {
        $path = dirname(__DIR__, 3) . '/specs/001-pettycash-v1-core/contracts/workflow.md';
        self::assertFileExists($path, "the workflow contract was not found at {$path}");

        $contents = (string) file_get_contents($path);

        // The clause is wrapped across a line break in the source, so the prose is compared with
        // whitespace collapsed. A literal multi-word match against wrapped Markdown would fail on
        // rewrap alone, which would make this test a tripwire for the formatter rather than for the
        // rule -- and a test that cries wolf on rewrap is a test that gets deleted.
        $flow = trim((string) preg_replace('/\s+/', ' ', $contents));

        self::assertStringContainsString(
            'Non-repeatable commands require `X-PettyCash-Command-ID`',
            $flow,
            'contracts/workflow.md keeps requiring the header for non-repeatable commands. That rule is what '
            . 'exempts DELETE, so weakening it would silently un-exempt every delete in the contract'
        );
        self::assertStringContainsString(
            'Replaying the same command with the same canonical payload returns the first result',
            $flow,
            'the replay guarantee for non-repeatable commands is unchanged'
        );
    }
}
