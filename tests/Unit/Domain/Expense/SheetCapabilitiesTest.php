<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Domain\Expense;

use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\SheetCapabilities;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use PHPUnit\Framework\TestCase;

/**
 * T326/T327: the full capability matrix for a sheet, including the denial half.
 *
 * ## Why this is a unit test and not a contract test
 *
 * `GET /sheets/{sheetId}` is owner-scoped — `SheetService::getSheet()` answers `404` for anyone who is
 * not the owner — so a capability set is only ever put on the wire for the owner, and the OCS contract
 * suite has exactly one set of credentials (`PETTYCASH_TEST_USER`) with no way to authenticate as a
 * second user. Every "a caller who may **not** act" case was therefore unreachable from the only
 * suite that talks to the real endpoint. Passing the actor's uid as a parameter is what makes the
 * denial half assertable at all.
 *
 * ## Why the matrix matters more than the payload
 *
 * The capability set exists so that **a hidden action and a refused action cannot disagree**. Each
 * entry restates a guard a command path already enforces — `SheetService::updateSheet()`,
 * `TransactionService::createTransaction()` and `SubmitSheet::execute()`. If a guard changes and this
 * matrix does not, the interface offers a control the server refuses, and the only symptom is a user
 * who is told "not allowed" after filling in a form. That is the failure `FinanceDecisionPolicy` was
 * extracted to prevent on the finance side.
 */
final class SheetCapabilitiesTest extends TestCase
{
    private const OWNER = 'employee1';
    private const STRANGER = 'someone-else';
    private const ACCOUNT = '44444444-4444-4444-8444-444444444444';

    public function testTheOwnerOfAnOpenSheetMayUpdateAndAddTransactions(): void
    {
        $actions = SheetCapabilities::derive($this->sheet(SheetStatus::Open, self::OWNER), self::OWNER, 0);

        $this->assertTrue($actions['canUpdate'], 'the owner of an open sheet may retitle it');
        $this->assertTrue($actions['canAddTransaction'], 'the owner of an open sheet may draft a transaction');
    }

    public function testAnOpenSheetWithATransactionIsSubmittable(): void
    {
        // The case that was impossible in every browser: `canSubmit` was derived from a payload key
        // the server never sent, so `?? 0` made it permanently false and the button was never in the
        // DOM. FR-024 requires at least one transaction, and one is what makes it true.
        $actions = SheetCapabilities::derive($this->sheet(SheetStatus::Open, self::OWNER), self::OWNER, 1);

        $this->assertTrue(
            $actions['canSubmit'],
            'an open sheet holding a transaction must be submittable, or a purchaser can never submit'
        );
    }

    public function testAnEmptyOpenSheetIsNotSubmittable(): void
    {
        $actions = SheetCapabilities::derive($this->sheet(SheetStatus::Open, self::OWNER), self::OWNER, 0);

        $this->assertFalse(
            $actions['canSubmit'],
            'FR-024: a sheet must contain at least one transaction before submission, so the '
            . 'capability must say so rather than offer a control the command path refuses'
        );
    }

    public function testANonOwnerIsOfferedNothing(): void
    {
        $actions = SheetCapabilities::derive($this->sheet(SheetStatus::Open, self::OWNER), self::STRANGER, 3);

        // Every key, compared as a whole rather than one at a time: a capability set that *omits* a
        // key arrives in the client as `undefined`, and `undefined !== false` passes every filter the
        // frontend writes. Asserting only `canUpdate` would accept a set missing the other two.
        $this->assertSame(
            ['canUpdate' => false, 'canAddTransaction' => false, 'canSubmit' => false],
            $actions,
            'a caller who does not own the sheet must be offered no action at all'
        );
    }

    /**
     * FR-026: after submission a purchaser must not add transactions or edit the sheet.
     *
     * `SheetStatus` has no `SUBMITTED` case and that is deliberate — it models the sheet's current
     * stage, so a submitted sheet is one of the four review states. Each is asserted, because "the
     * enum has no Submitted case" is not the same claim as "every review state denies everything",
     * and a future case added to the enum would otherwise be denied by accident rather than by
     * decision.
     */
    public function testASubmittedSheetOffersNothingEvenToItsOwner(): void
    {
        foreach ([
            SheetStatus::CostCenter,
            SheetStatus::Senior,
            SheetStatus::Finance,
            SheetStatus::Correction,
        ] as $stage) {
            $actions = SheetCapabilities::derive($this->sheet($stage, self::OWNER), self::OWNER, 5);

            $this->assertSame(
                ['canUpdate' => false, 'canAddTransaction' => false, 'canSubmit' => false],
                $actions,
                "FR-026 forbids editing, adding and re-submitting a sheet at stage {$stage->value}, "
                . 'so none of the three may be offered'
            );
        }
    }

    public function testACompletedSheetOffersNothing(): void
    {
        foreach ([SheetStatus::Completed, SheetStatus::CompletedWithRejections] as $stage) {
            $actions = SheetCapabilities::derive($this->sheet($stage, self::OWNER), self::OWNER, 5);

            $this->assertFalse($actions['canUpdate'], "a {$stage->value} sheet is locked (FR-044)");
            $this->assertFalse($actions['canAddTransaction'], "a {$stage->value} sheet is locked (FR-044)");
            $this->assertFalse($actions['canSubmit'], "a {$stage->value} sheet is locked (FR-044)");
        }
    }

    public function testTheCapabilitySetIsACompleteBooleanMap(): void
    {
        // `CapabilitySet` is `additionalProperties: {type: boolean}` in the contract, so a non-boolean
        // fails a live response rather than here, where the message can name the key.
        foreach (SheetCapabilities::derive($this->sheet(SheetStatus::Open, self::OWNER), self::OWNER, 1) as $key => $value) {
            $this->assertIsBool($value, "capability {$key} must be a boolean");
        }
    }

    private function sheet(SheetStatus $status, string $ownerUid): ExpenseSheet
    {
        return new ExpenseSheet(
            '11111111-1111-4111-8111-111111111111',
            $ownerUid,
            self::ACCOUNT,
            CurrencyCode::fromString('IRR'),
            $status
        );
    }
}
