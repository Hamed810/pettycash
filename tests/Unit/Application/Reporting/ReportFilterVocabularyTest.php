<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Reporting;

use OCA\PettyCash\Application\Reporting\ReportFilterFactory;
use PHPUnit\Framework\TestCase;

/**
 * T374: `contracts/ocs-api.yaml` declares the filter vocabulary the server enforces.
 *
 * ## Why a contract-to-code comparison and not just a contract assertion
 *
 * T124's gate already answers "does `src/generated/api.ts` match the contract?" — and it answers it well,
 * failing on a byte difference. It cannot answer the question this file asks, because the question is not
 * about drift between two artifacts. It is: **is the contract right?**
 *
 * Reverting `additionalProperties: false` to `true`, or dropping a property, is a perfectly valid
 * amendment as far as T124 is concerned: the types regenerate, the gate goes green, and the contract is
 * once more a document that permits a filter the server refuses with a 422 nothing declares. Nothing in the
 * frontend build fails, because the defect is the *absence* of a constraint.
 *
 * So the constraint is asserted here against `ReportFilterFactory::ACCEPTED_FILTERS` — the server's own
 * list. The contract and the code are two statements of one rule, and this is the assertion that they are
 * the same statement.
 *
 * ## Parsed as text, deliberately
 *
 * There is no YAML parser available to a unit test here, and the existing contract-reading tests
 * (`PageBoundsTest`, `RouteDispatchCompletenessTest`) read the document as text with indentation-scoped
 * patterns. This follows that precedent rather than adding a dependency to assert a list of names.
 *
 * The extraction is anchored on **exact indentation**: schema properties sit at 8 spaces, the schema's own
 * keys (`type:`, `description:`, `additionalProperties:`) at 6, and anything nested inside a property
 * deeper still. A property line is therefore matched by eight spaces followed immediately by a word
 * character — a ten-space line cannot match it, because its ninth character is a space. That is what keeps
 * `items: { type: string }` inside a property from being counted as a filter named `items`.
 */
final class ReportFilterVocabularyTest extends TestCase
{
    private const CONTRACT = '/specs/001-pettycash-v1-core/contracts/ocs-api.yaml';

    /**
     * The contract declares exactly the filters the server accepts — no more, no fewer.
     *
     * Checked in **both** directions, because each failure is a different defect. A name in the contract the
     * server does not accept is a client sending a guaranteed 422. A name the server accepts but the
     * contract omits is a filter a generated client cannot discover. Asserting set equality is the only
     * form that catches the second, and it is the one a client actually suffers from.
     */
    public function testTheContractDeclaresExactlyTheAcceptedFilterVocabulary(): void
    {
        $declared = $this->contractFilterNames();
        $accepted = ReportFilterFactory::ACCEPTED_FILTERS;

        sort($declared);
        $sorted = $accepted;
        sort($sorted);

        $this->assertSame(
            $sorted,
            $declared,
            "contracts/ocs-api.yaml's ReportFilters must declare exactly the server's accepted vocabulary.\n"
            . 'In the contract but not accepted by the server (a client would send a guaranteed 422): '
            . implode(', ', array_diff($declared, $accepted)) . "\n"
            . 'Accepted by the server but absent from the contract (a filter no client can discover): '
            . implode(', ', array_diff($accepted, $declared))
        );
    }

    /**
     * The vocabulary is **closed**, so an unknown filter is a declared refusal rather than a silent drop.
     *
     * This is the line T374 exists for. With `additionalProperties: true` the document permits any key, so a
     * client generated from it can send anything and receive a 422 the contract never described — and, worse,
     * a client that assumed unrecognised keys were ignored would be *right* about the status and wrong about
     * the answer, because the server returns the unfiltered report.
     */
    public function testTheFilterVocabularyIsClosed(): void
    {
        $this->assertStringContainsString(
            'additionalProperties: false',
            $this->reportFiltersBlock(),
            'ReportFilters must set `additionalProperties: false`; with it true (or absent) the contract '
            . 'permits filters the server refuses with a 422, and a client cannot tell a refusal from an '
            . 'ignored filter'
        );
    }

    /**
     * `ExportRequest` **references** the closed vocabulary instead of restating it.
     *
     * The defect this replaces was an inline `type: object` / `additionalProperties: true` that declared
     * only `period`. A referenced schema is also what stops the export and report endpoints drifting into
     * declaring different vocabularies: the export reads the same JSON body the report reads from a query
     * parameter, and it is one rule rather than a per-endpoint copy.
     */
    public function testTheExportRequestReferencesTheSharedVocabulary(): void
    {
        $contract = $this->contract();

        $this->assertMatchesRegularExpression(
            "/filters:\n(?:\s*#[^\n]*\n)*\s*\\\$ref: '#\/components\/schemas\/ReportFilters'/",
            $contract,
            "ExportRequest.filters must \$ref the shared ReportFilters schema rather than declaring its own "
            . 'object — an inline declaration is a second vocabulary waiting to disagree'
        );
    }

    /**
     * Both endpoints declare the refusal, so a client reading the document can learn that unknown filters
     * are refused rather than ignored.
     *
     * `GET /reports` had **no** `422` declared at all, and `POST /exports` pointed at the generic
     * `ValidationError`. A generic "validation failed" does not tell a caller that an unknown filter is
     * refused *rather than dropped*, which is the specific assumption that turns into a silently unfiltered
     * report.
     */
    public function testBothFilterReadingEndpointsDeclareTheFilterRefusal(): void
    {
        $this->assertSame(
            2,
            substr_count($this->contract(), "\$ref: '#/components/responses/FilterRejected'"),
            'GET /reports and POST /exports must both declare the filter refusal response'
        );
    }

    // ===== helpers =====

    private function contract(): string
    {
        $path = dirname(__DIR__, 4) . self::CONTRACT;
        $contract = is_file($path) ? (string) file_get_contents($path) : '';

        $this->assertNotSame('', $contract, 'contracts/ocs-api.yaml must be readable for this to mean anything');

        return $contract;
    }

    /**
     * The `ReportFilters` schema block, from its key to the next schema at the same indentation.
     */
    private function reportFiltersBlock(): string
    {
        $contract = $this->contract();

        $this->assertSame(
            1,
            preg_match('/^ {4}ReportFilters:\n(?:(?!^ {4}\S).*\n)*/m', $contract, $matches),
            'contracts/ocs-api.yaml must declare a ReportFilters schema'
        );

        return $matches[0];
    }

    /**
     * The property names under `ReportFilters.properties`, as the contract states them.
     *
     * @return list<string>
     */
    private function contractFilterNames(): array
    {
        $block = $this->reportFiltersBlock();

        $this->assertSame(
            1,
            preg_match('/^ {6}properties:\n((?:(?!^ {6}\S).*\n)*)/m', $block, $matches),
            'ReportFilters must declare a `properties` block'
        );

        preg_match_all('/^ {8}([A-Za-z][A-Za-z0-9]*):/m', $matches[1], $found);

        $names = array_values(array_unique($found[1]));
        $this->assertNotEmpty($names, 'ReportFilters.properties must name at least one filter');

        return $names;
    }
}
