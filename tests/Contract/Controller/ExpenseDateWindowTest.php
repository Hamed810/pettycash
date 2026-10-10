<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Contract\Controller;

use GuzzleHttp\Client;
use OCA\PettyCash\Domain\Account\AccountMovement;
use OCA\PettyCash\Domain\Shared\Enum\MovementType;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Expense\TransactionRevision;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Tests\Contract\Fixture\ContractFixtures;
use OCA\PettyCash\Tests\TestDoubles\FixedClock;
use PHPUnit\Framework\TestCase;

/**
 * FR-023 (clarified 2026-10-08): the expense date carries **no validity window**.
 *
 * ## The rule
 *
 * Any valid calendar date MUST be accepted, **including a date later than today**, and no lower bound is
 * imposed. The date is a fact the purchaser asserts about when a cost was incurred, not a scheduling
 * promise. A lower bound is also absent: back-dating an already-incurred cost is ordinary, not an abuse.
 *
 * ## Why this needs a test at all, when the code already conforms
 *
 * Nothing in `lib/` compares an expense date to the current date — verified, not assumed. So this suite
 * pins a **stated rule**, and the failure it guards against is additive: someone reasonably concludes that an
 * expense dated next month is a data-entry mistake, adds a validation, and every purchaser recording a
 * forward-dated cost is refused with a message about a rule nobody wrote down. That is precisely the harm
 * the clarification was written to prevent, and only an executable assertion stops it.
 *
 * ## Why the movement assertion matters more than the acceptance assertion
 *
 * Acceptance is the visible half. The consequential half is FR-023's second sentence: **the date does not
 * decide when money moves.** `AccountMovement::createConsumption()` is the proof — its signature has no
 * expense-date parameter at all and stamps `occurredAt` from the clock. So consumption is booked at finance
 * approval (FR-040) and an account is never debited before anyone approved the expense, whatever the date
 * says. A future-dated expense therefore cannot pre-drain a balance.
 */
final class ExpenseDateWindowTest extends TestCase
{
    private Client $client;

    protected function setUp(): void
    {
        $this->client = OcsTestClient::create();
        ContractFixtures::prepare('draft');
    }

    // ===== the domain accepts any valid calendar date =====

    /**
     * A revision dated next month is constructed without complaint.
     *
     * `TransactionRevision::draft()` and `complete()` validate what a revision *is* — a positive number, a
     * non-empty description, an active-state rule — and deliberately say nothing about where the date sits
     * relative to today.
     */
    public function testARevisionAcceptsADateInTheFuture(): void
    {
        $future = (new \DateTimeImmutable('today'))->modify('+1 year');

        $revision = TransactionRevision::draft(
            'rev-future',
            1,
            $future,
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );
        $revision->complete('A forward-dated cost', 'cc-1', 'CC-1', 'Cost Center', 1, 'Travel', 1, false);

        self::assertSame(
            $future->format('Y-m-d'),
            $revision->expenseDate()->format('Y-m-d'),
            'the expense date must be stored exactly as asserted, not clamped to today'
        );
    }

    /**
     * A date far in the past is equally unremarkable.
     *
     * The "no lower bound" half of the rule. Back-dating last week's fuel purchase is the common case, and a
     * lower bound would break ordinary work rather than protect an invariant.
     */
    public function testARevisionAcceptsADateInThePast(): void
    {
        $past = (new \DateTimeImmutable('today'))->modify('-3 years');

        $revision = TransactionRevision::draft(
            'rev-past',
            1,
            $past,
            Money::positive(1000, CurrencyCode::fromString('IRR'))
        );

        self::assertSame($past->format('Y-m-d'), $revision->expenseDate()->format('Y-m-d'));
    }

    // ===== the consequential half: the date never moves money =====

    /**
     * A consumption is dated by the **clock**, not by the expense it settles.
     *
     * Asserted with an injected fixed clock: a test that compared against `new DateTimeImmutable()` would
     * pass whether the code read the clock or the expense date, because both are "about now". Pinning the
     * instant is what makes the assertion say something.
     */
    public function testAConsumptionIsDatedWhenItIsApprovedNotByTheExpenseDate(): void
    {
        $approvedAt = new \DateTimeImmutable('2026-03-04 10:00:00', new \DateTimeZone('UTC'));
        $expenseDate = new \DateTimeImmutable('2027-09-30 00:00:00', new \DateTimeZone('UTC'));

        // The expense date is deliberately a year into the future: if the movement took its date from the
        // transaction, this value would show up in `occurredAt` and fail the assertion below.
        $movement = AccountMovement::createConsumption(
            'mv-1',
            Money::positive(5000, CurrencyCode::fromString('IRR')),
            'Settlement of a forward-dated expense',
            $this->accountWithBalance(100000),
            'rev-1',
            'dec-1',
            'cmd-approve'
        );

        self::assertSame(MovementType::Consumption, $movement->type());
        self::assertNotNull(
            $movement->occurredAt,
            'a consumption must carry the moment it happened — that is when the balance moved'
        );
        self::assertLessThan(
            $expenseDate,
            $movement->occurredAt,
            'the balance is consumed at approval, never on or after the expense date'
        );
        self::assertSame(
            'Settlement of a forward-dated expense',
            $movement->description,
            'the movement describes the settlement, and its own timestamp is the approval'
        );
    }

    /**
     * `createConsumption()` takes **no expense date at all** — the strongest form of the same claim.
     *
     * Asserted by reflection rather than by reading the signature, so that adding an optional
     * `?DateTimeImmutable $expenseDate = null` parameter to make the movement "more informative" fails here
     * instead of quietly reintroducing the coupling.
     */
    public function testAConsumptionFactoryAcceptsNoExpenseDateParameter(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(AccountMovement::class, 'createConsumption'))->getParameters()
        );

        self::assertNotContains(
            'expenseDate',
            $parameters,
            'the ledger records when money moved; the expense date belongs to the revision'
        );
        self::assertNotContains('date', $parameters);
    }

    // ===== over HTTP: nothing rejects the forward-dated record =====

    /**
     * A forward-dated transaction saves and the sheet submits with it.
     *
     * The end-to-end half, over the real transport, because "no code rejects it" is only reassuring if the
     * endpoint agrees — and an endpoint could refuse for a reason no domain test would see.
     */
    public function testAForwardDatedTransactionSavesAndItsSheetSubmits(): void
    {
        $futureDate = (new \DateTimeImmutable('today'))->modify('+1 year')->format('Y-m-d');

        $sheetId = ContractFixtures::sheetId();
        $created = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . $sheetId . '/transactions',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                // Only date and amount, which is all this operation accepts for a draft
                // (`TransactionDraftWrite` requires exactly these two) -- found by reading the schema after
                // a first attempt sent the full field set and the create accepted it but left the revision
                // without a cost centre, so the *submit* was refused with "Transaction missing cost center".
                'json' => [
                    'expenseDate' => $futureDate,
                    'amountMinor' => 5000,
                ],
            ]
        );

        self::assertSame(
            201,
            $created->getStatusCode(),
            'FR-023: a date later than today must be accepted, not refused. Answered instead: '
            . (string) $created->getBody()
        );

        $transactionId = json_decode((string) $created->getBody(), true)['ocs']['data']['id'] ?? null;
        self::assertIsString($transactionId);

        // Complete the draft the way FR-023's sibling requirement FR-023/US1/AC6 does: the submission
        // fields arrive on the PATCH, which is also where the date must survive unaltered.
        $completed = $this->client->request(
            'PATCH',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId,
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => [
                    'expenseDate' => $futureDate,
                    'amountMinor' => 5000,
                    'description' => 'A cost dated next year',
                    'costCenterId' => ContractFixtures::costCenterId(),
                    'expenseTypeId' => ContractFixtures::expenseTypeId(),
                    'expectedVersion' => $this->liveTransactionVersion($transactionId),
                ],
            ]
        );
        self::assertSame(
            200,
            $completed->getStatusCode(),
            'a forward-dated draft must complete normally. Answered instead: ' . (string) $completed->getBody()
        );

        // The date must survive the round trip unaltered — not clamped, re-encoded, or defaulted to today.
        $readBack = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId
        );
        self::assertSame(200, $readBack->getStatusCode());
        self::assertStringContainsString(
            $futureDate,
            (string) $readBack->getBody(),
            'the stored expense date must be the one the purchaser entered'
        );

        // And the sheet must still submit: FR-023 adds no new submission blocker.
        $submitted = $this->client->request(
            'POST',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . $sheetId . '/submit',
            [
                'headers' => ['X-PettyCash-Command-ID' => ContractFixtures::commandId()],
                'json' => ['expectedVersion' => $this->liveSheetVersion($sheetId)],
            ]
        );

        self::assertSame(
            200,
            $submitted->getStatusCode(),
            'a forward-dated expense must not block submission. Answered instead: ' . (string) $submitted->getBody()
        );
    }

    private function liveTransactionVersion(string $transactionId): int
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/transactions/' . $transactionId
        );

        return (int) (json_decode((string) $response->getBody(), true)['ocs']['data']['version'] ?? 0);
    }

    private function liveSheetVersion(string $sheetId): int
    {
        $response = $this->client->request(
            'GET',
            '/ocs/v2.php/apps/pettycash/api/v1/sheets/' . $sheetId
        );

        // The sheet payload names the optimistic-lock value `version`, not `lockVersion` -- verified by
        // reading `SheetPayloadFormatter.php:85` after a first attempt sent `0` and was refused with a
        // 409 by this task's own T416 guard. The guard being what caught the mistake is the point: the
        // refusal named the live version and told the caller to reload.
        return (int) (json_decode((string) $response->getBody(), true)['ocs']['data']['version'] ?? 0);
    }

    /**
     * A funded, active rial account.
     *
     * `PettyCashAccount` takes both a `CurrencyCode` and a `Currency` -- the former for arithmetic, the
     * latter for the configured scale -- and `balanceMinor` is a plain integer, not a `Money`. Found by
     * running: the first attempt passed a `Money` as the balance and the constructor rejected it.
     */
    private function accountWithBalance(int $balanceMinor): PettyCashAccount
    {
        return new PettyCashAccount(
            'acc-1',
            'purchaser-1',
            CurrencyCode::fromString('IRR'),
            Currency::create('IRR', 'Iranian Rial', 0),
            $balanceMinor,
            true
        );
    }
}