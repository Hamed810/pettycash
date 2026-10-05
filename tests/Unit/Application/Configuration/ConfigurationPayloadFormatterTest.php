<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Application\Configuration;

use OCA\PettyCash\Application\Configuration\ConfigurationPayloadFormatter;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use PHPUnit\Framework\TestCase;

/**
 * T087 — `ConfigurationPayloadFormatter` against `contracts/ocs-api.yaml`.
 *
 * ## Why the shape is checked here and not over HTTP
 *
 * The eleven `configuration#*` endpoints are administrator-only for their writes, and this repository
 * has no administrator credential for the HTTP suite. So the *response shape* cannot be asserted over
 * HTTP without committing a secret, and asserting it only by hand is how the contract and the emitters
 * drifted in the first place — `SheetController` returned `SheetSummary` from all four of its
 * single-sheet responses because nothing compared them to `SheetDetail`.
 *
 * These cases therefore state each schema's key set literally, taken from the contract, and compare it
 * to what the formatter emits. **The declared sets are copied by hand and are themselves the assertion:**
 * if the contract changes, these fail until someone re-reads it, which is the point. T337 asks for a
 * generated conformance check across every response schema; this is the same idea for the eleven
 * endpoints T087 owns, done by hand because the generator is not built.
 */
final class ConfigurationPayloadFormatterTest extends TestCase
{
    private ConfigurationPayloadFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ConfigurationPayloadFormatter();
    }

    // --- currencies ---

    public function testACarriesExactlyCurrencyWritePlusVersion(): void
    {
        $currency = Currency::create('IRR', 'Iranian Rial', 0);
        $currency->lockVersion = 3;

        self::assertSame(
            // CurrencyWrite: code, name, minorUnit, active. Currency adds version.
            ['code', 'name', 'minorUnit', 'active', 'version'],
            array_keys($this->formatter->currency($currency))
        );
        self::assertSame(3, $this->formatter->currency($currency)['version']);
    }

    public function testCurrencyPageIsPageMetaPlusItems(): void
    {
        $page = $this->formatter->currencyPage([
            'items' => [Currency::create('IRR', 'Iranian Rial', 0)],
            'page' => 2,
            'pageSize' => 10,
            'total' => 11,
        ]);

        // PageMeta requires page, pageSize, total; CurrencyPage adds items.
        self::assertSame(['items', 'page', 'pageSize', 'total'], array_keys($page));
        self::assertSame(2, $page['page']);
        self::assertSame(11, $page['total']);
    }

    // --- cost centres ---

    public function testACostCentreCarriesEveryCostCenterWriteFieldPlusIdAndVersion(): void
    {
        $costCenter = CostCenter::create(
            'ENG',
            'Engineering',
            'a description',
            new \DateTimeImmutable('2026-01-01'),
            new \DateTimeImmutable('2026-12-31')
        );
        $costCenter->publicId = 'cc-public-uuid';
        $costCenter->lockVersion = 2;

        $payload = $this->formatter->costCenter($costCenter, [
            Role::CostCenterManager->value => [$this->assignment('employee1', Role::CostCenterManager)],
            Role::SeniorManager->value => [$this->assignment('employee2', Role::SeniorManager)],
        ]);

        // CostCenterWrite requires code, name, validFrom, active, managerUserId, seniorManagerUserId,
        // and carries description, validTo, changeReason. CostCenter adds id and version.
        self::assertSame(
            [
                'id',
                'code',
                'name',
                'description',
                'validFrom',
                'validTo',
                'active',
                'managerUserId',
                'seniorManagerUserId',
                'changeReason',
                'version',
            ],
            array_keys($payload)
        );
        self::assertSame('cc-public-uuid', $payload['id'], 'the id is the public uuid, not the surrogate key');
        self::assertSame('employee1', $payload['managerUserId']);
        self::assertSame('employee2', $payload['seniorManagerUserId']);
    }

    public function testDatesAreCalendarDaysRatherThanTimestamps(): void
    {
        $payload = $this->formatter->costCenter(
            CostCenter::create(
                'ENG',
                'Engineering',
                null,
                new \DateTimeImmutable('2026-01-01T13:45:00+00:00'),
                null
            )
        );

        // `format: date` is Y-m-d. `DateTimeInterface::ATOM` here would emit a full RFC 3339 timestamp,
        // which a `format: date` consumer would reject -- and a validity boundary is a calendar day in
        // every artifact that mentions one.
        self::assertSame('2026-01-01', $payload['validFrom']);
        self::assertNull($payload['validTo']);
    }

    public function testAnUnresolvableManagerIsNullRatherThanABlankUserId(): void
    {
        $payload = $this->formatter->costCenter(
            CostCenter::create('ENG', 'Engineering', null, new \DateTimeImmutable('2026-01-01'), null),
            []
        );

        // `null` says "no holder could be read", which is a data problem worth surfacing. `''` reads as
        // a real user id to whatever consumes this, and `''` is what a caller cannot act on either.
        self::assertNull($payload['managerUserId']);
        self::assertNull($payload['seniorManagerUserId']);
    }

    public function testCostCenterPageResolvesManagersPerRow(): void
    {
        $first = CostCenter::create('AAA', 'First', null, new \DateTimeImmutable('2026-01-01'), null);
        $second = CostCenter::create('BBB', 'Second', null, new \DateTimeImmutable('2026-01-01'), null);

        $page = $this->formatter->costCenterPage(
            ['items' => [$first, $second], 'page' => 1, 'pageSize' => 25, 'total' => 2],
            // Bound to `$this` rather than static: the fixture helper is an instance method, and a
            // `static fn` calling `self::` is exactly the kind of thing that only fails when the
            // closure is finally invoked.
            fn (CostCenter $costCenter): array => [
                Role::CostCenterManager->value => [$costCenter->code() === 'AAA'
                    ? $this->assignment('holder-of-aaa', Role::CostCenterManager)
                    : $this->assignment('holder-of-bbb', Role::CostCenterManager)],
            ]
        );

        // The per-row resolver is the point: answering with one centre's holders for the whole page
        // would report the wrong manager for every other row while looking completely correct.
        self::assertSame('holder-of-aaa', $page['items'][0]['managerUserId']);
        self::assertSame('holder-of-bbb', $page['items'][1]['managerUserId']);
        self::assertNull($page['items'][0]['seniorManagerUserId']);
    }

    public function testACreatedCostCentreUsesTheTwoAssignmentsItReturned(): void
    {
        $payload = $this->formatter->costCenterFromCreate([
            'costCenter' => CostCenter::create('ENG', 'Engineering', null, new \DateTimeImmutable('2026-01-01'), null),
            'manager' => $this->assignment('employee1', Role::CostCenterManager),
            'seniorManager' => $this->assignment('employee2', Role::SeniorManager),
        ]);

        self::assertSame('employee1', $payload['managerUserId']);
        self::assertSame('employee2', $payload['seniorManagerUserId']);
    }

    // --- expense types ---

    public function testAnExpenseTypeCarriesEveryExpenseTypeWriteFieldPlusIdAndVersion(): void
    {
        $type = ExpenseType::create('Travel', 'Trips', true);
        $type->publicId = 'et-public-uuid';
        $type->lockVersion = 1;

        $payload = $this->formatter->expenseType($type);

        // ExpenseTypeWrite: title, evidenceRequired, active required; description, changeReason optional.
        self::assertSame(
            ['id', 'title', 'description', 'evidenceRequired', 'active', 'changeReason', 'version'],
            array_keys($payload)
        );
        self::assertTrue($payload['evidenceRequired'], 'FR-023 turns this flag into a submission precondition');
        self::assertSame('et-public-uuid', $payload['id']);
    }

    public function testEvidenceRequiredFalseIsCarriedRatherThanTreatedAsAbsent(): void
    {
        $payload = $this->formatter->expenseType(ExpenseType::create('Other', null, false));

        // An `array_key_exists`-style check would pass here; the assertion is on the *value*, because a
        // projection that dropped the key when it was false would make the form demand evidence the
        // configuration explicitly does not require.
        self::assertArrayHasKey('evidenceRequired', $payload);
        self::assertFalse($payload['evidenceRequired']);
    }

    // --- finance assignments ---

    public function testARoleAssignmentCarriesEveryFinanceAssignmentWriteFieldPlusIdAndRole(): void
    {
        $payload = $this->formatter->roleAssignment(
            $this->assignment('employee1', Role::Finance, new \DateTimeImmutable('2025-08-27'))
        );

        // FinanceAssignmentWrite: userId, validFrom, active, changeReason required; validTo optional.
        // RoleAssignment adds id and role.
        self::assertSame(
            ['id', 'role', 'userId', 'validFrom', 'validTo', 'active', 'changeReason'],
            array_keys($payload)
        );
        self::assertSame('FINANCE', $payload['role'], 'the contract declares role as an enum of FINANCE only');
    }

    public function testTheFinanceRosterIsABareArrayRatherThanAPageEnvelope(): void
    {
        $payload = $this->formatter->roleAssignments([$this->assignment('employee1', Role::Finance)]);

        // `GET /configuration/finance-assignments` declares `type: array, items: RoleAssignment`. It is
        // the only one of the four listings that is not paged, and wrapping it in PageMeta would be a
        // second disagreement with the same file.
        self::assertIsList($payload);
        self::assertCount(1, $payload);
        self::assertArrayNotHasKey('items', $payload[0]);
        self::assertArrayNotHasKey('total', $payload);
    }

    /**
     * `changeReason` is emitted as `null` on every read projection, and that is a recorded contract
     * disagreement rather than an oversight.
     *
     * `RoleAssignment` is `allOf[FinanceAssignmentWrite, ...]` and `FinanceAssignmentWrite` lists
     * `changeReason` as **required**, but the domain aggregate has no such column — `data-model.md`
     * defines `RoleAssignment` without one, because the explanation belongs to the configuration audit
     * FR-017 actually asks for.
     *
     * The key is emitted rather than omitted so the schema's `required` is satisfied; the value is
     * `null`, which contradicts the schema's `type: string`. Adding the column or amending the
     * contract are both amendments T087 does not own, so the disagreement is asserted here rather than
     * worked around silently: if someone later adds a real `changeReason` to the aggregate, this test
     * is the thing that tells them the contract and the domain now agree and the `null` can go.
     */
    public function testChangeReasonIsPresentAndNullOnEveryReadProjection(): void
    {
        $costCenter = $this->formatter->costCenter(
            CostCenter::create('ENG', 'Engineering', null, new \DateTimeImmutable('2026-01-01'), null)
        );
        $expenseType = $this->formatter->expenseType(ExpenseType::create('Travel', null, false));
        $assignment = $this->formatter->roleAssignment($this->assignment('employee1', Role::Finance));

        foreach (['costCenter' => $costCenter, 'expenseType' => $expenseType, 'roleAssignment' => $assignment] as $name => $payload) {
            self::assertArrayHasKey('changeReason', $payload, "$name must carry the key");
            self::assertNull($payload['changeReason'], "$name has no stored changeReason to report");
        }
    }

    private function assignment(
        string $userUid,
        Role $role,
        ?\DateTimeImmutable $validFrom = null
    ): RoleAssignment {
        // `RoleAssignment::create()` refuses a cost-centre id for a non-manager role and refuses its
        // absence for a manager role, so the fixture has to honour that invariant rather than pass a
        // value that happens to render the same way.
        $assignment = RoleAssignment::create(
            $userUid,
            $role,
            $role === Role::Finance ? null : 1,
            $validFrom ?? new \DateTimeImmutable('2026-01-01'),
            null
        );
        $assignment->publicId = 'assignment-public-uuid';

        return $assignment;
    }
}