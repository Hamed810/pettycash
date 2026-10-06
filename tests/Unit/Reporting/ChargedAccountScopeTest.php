<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Unit\Reporting;

use OCA\PettyCash\Application\Reporting\ChargedAccountScope;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Uuid;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use PHPUnit\Framework\TestCase;

/**
 * T299: "which accounts have been charged" had no answer anywhere in the schema or the ports.
 *
 * The contract test proves this works end to end against the real database, but it can only do so
 * by relying on charge rows a settlement left behind, which makes it order- and residue-dependent.
 * These tests cover the derivation itself deterministically, including the cases the happy path
 * hides: an unresolvable account, and a scope that must not widen when it is empty.
 */
final class ChargedAccountScopeTest extends TestCase
{
    private InMemoryAuditEventRepository $audit;
    private InMemoryAccountRepository $accounts;
    private ChargedAccountScope $scope;

    protected function setUp(): void
    {
        $this->audit = new InMemoryAuditEventRepository();
        $this->accounts = new InMemoryAccountRepository();
        $this->scope = new ChargedAccountScope($this->audit, $this->accounts);
    }

    public function testItReturnsTheAccountsThatHaveBeenCharged(): void
    {
        $account = $this->account('acc-1', 11);
        $this->charge('acc-1');

        self::assertSame([11], $this->scope->chargedAccountDbIds());
        self::assertSame($account->id, 'acc-1');
    }

    /**
     * FR-016: every finance officer shares one queue, so a charge is an instance-wide fact. The
     * scope must therefore include an account charged by a *different* officer, or an officer
     * cannot work a queue that contains someone else's charge.
     */
    public function testAChargeByAnotherOfficerStillCounts(): void
    {
        $this->account('acc-2', 12);
        $this->charge('acc-2', 'someone-else');

        self::assertSame([12], $this->scope->chargedAccountDbIds());
    }

    public function testRepeatedChargesOfOneAccountYieldItOnce(): void
    {
        $this->account('acc-1', 11);
        $this->charge('acc-1');
        $this->charge('acc-1');
        $this->charge('acc-1');

        self::assertSame([11], $this->scope->chargedAccountDbIds());
    }

    /**
     * A charge naming an account that no longer resolves is a real audit row, but it cannot become
     * a scope member: the report has no owner, currency or balance to show for it. Returning the id
     * anyway would widen `configurationAuditScope()`'s visible set to an account nobody can name.
     */
    public function testAChargeNamingAnUnresolvableAccountIsDropped(): void
    {
        $this->account('acc-1', 11);
        $this->charge('acc-missing');
        $this->charge('acc-1');

        self::assertSame(
            [11],
            $this->scope->chargedAccountDbIds(),
            'an unresolvable account is dropped, and does not take the resolvable one with it'
        );
    }

    /**
     * An officer who has charged nothing sees nothing.
     *
     * `ReportQuery::configurationAuditScope()` widens from the ids it is handed, so an empty result
     * must mean "empty scope" and never "everything". This is the direction that has to fail safe.
     */
    public function testAnUnchargedInstanceYieldsAnEmptyScopeNotEverything(): void
    {
        self::assertSame([], $this->scope->chargedAccountDbIds());
    }

    /**
     * FR-055: an event type **outside** funding and charging must not count.
     *
     * **This assertion was inverted by T369, and the inversion is the record.** It previously read
     * "only `ACCOUNT_CHARGED` denotes a charge", with `ACCOUNT_FUNDED` as its counter-example and the
     * funding account *excluded* from the expected scope. That was a faithful encoding of the
     * one-event-type implementation, but not of FR-055, whose text scopes visibility by "account
     * funding **and** balance-charging" events and requires that anything outside that pair not
     * count "because touching an account is not charging it."
     *
     * So the counter-example is now the thing the requirement actually names — a rename and a
     * deactivation — and the funding account moves into the expected scope. The test kept its purpose
     * (an event outside the pair must not widen scope) and the pair's other half is asserted
     * separately below, so a failure says which side moved.
     */
    public function testChargesOfOtherEventTypesAreNotCounted(): void
    {
        $this->account('acc-1', 11);
        $this->account('acc-2', 12);
        $this->account('acc-3', 13);
        $this->charge('acc-1', 'officer-1', 'CURRENCY_UPDATED');
        $this->charge('acc-2', 'officer-1', 'ACCOUNT_DEACTIVATED');
        $this->charge('acc-3', 'officer-1', 'ACCOUNT_CHARGED');

        self::assertSame(
            [13],
            $this->scope->chargedAccountDbIds(),
            'only funding and charging count; a rename or a deactivation is touching an account, not charging it'
        );
    }

    /**
     * FR-015's funding half of the pair, asserted on its own so the two halves are separately
     * falsifiable.
     *
     * This is the case T369 exists for. An officer who had funded an account and not yet settled
     * anything against it produced **no** event of either kind, so the account was missing from their
     * configuration-audit visibility and from the `accounts` report — which is what FR-055's own
     * clarification says must not happen.
     */
    public function testAFundingChargeCountsWithoutAnySettlement(): void
    {
        $this->account('acc-1', 11);
        $this->charge('acc-1', 'officer-1', 'ACCOUNT_FUNDED');

        self::assertSame(
            [11],
            $this->scope->chargedAccountDbIds(),
            'FR-055 scopes by account funding as well as by balance-charging'
        );
    }

    /**
     * Both halves of the pair on one account.
     *
     * The pair is read by two separate queries and unioned, so an account that appeared twice — or
     * one half of the pair that got dropped — is the failure mode of a change like this one.
     */
    public function testFundingAndChargingOneAccountYieldItOnce(): void
    {
        $this->account('acc-1', 11);
        $this->charge('acc-1', 'officer-1', 'ACCOUNT_FUNDED');
        $this->charge('acc-1', 'officer-1', 'ACCOUNT_CHARGED');
        $this->charge('acc-1', 'officer-1', 'ACCOUNT_FUNDED');

        self::assertSame(
            [11],
            $this->scope->chargedAccountDbIds(),
            'the pair is a set of event types, and an account appears in it once'
        );
    }

    public function testAnEntityIdlessEventIsNotCounted(): void
    {
        $this->audit->save($this->event(null, 'officer-1'));

        self::assertSame(
            [],
            $this->scope->chargedAccountDbIds(),
            'an event with no subject cannot name an account'
        );
    }

    private function charge(string $accountPublicId, string $actorUid = 'officer-1', string $eventType = 'ACCOUNT_CHARGED'): void
    {
        $this->audit->save($this->event($accountPublicId, $actorUid, $eventType));
    }

    private function event(?string $entityId, string $actorUid, string $eventType = 'ACCOUNT_CHARGED'): \OCA\PettyCash\Domain\Audit\AuditEvent
    {
        return new \OCA\PettyCash\Domain\Audit\AuditEvent(
            (string) Uuid::random(),
            null,
            null,
            null,
            '',
            null,
            'account',
            $entityId,
            $eventType,
            $actorUid,
            'Officer',
            'FINANCE',
            null,
            '{}',
            '{}',
            (string) Uuid::random(),
            new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        );
    }

    private function account(string $publicId, int $dbId): PettyCashAccount
    {
        $currency = Currency::create('TST', 'Charged Scope Test Currency', 2);
        $account = new PettyCashAccount(
            $publicId,
            'employee1',
            CurrencyCode::fromString('TST'),
            $currency,
            0,
            true,
            0,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'employee1',
            null,
            null,
            $dbId
        );
        $this->accounts->save($account);

        $stored = $this->accounts->findById($dbId);
        self::assertNotNull($stored, 'the fixture account must be resolvable by its surrogate key');
        self::assertNotNull(
            $this->accounts->findByPublicId($publicId),
            'the derivation resolves accounts by public id, so this fixture must be findable that way'
        );

        return $stored;
    }
}
