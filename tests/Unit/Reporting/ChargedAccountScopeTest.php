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

    public function testChargesOfOtherEventTypesAreNotCounted(): void
    {
        $this->account('acc-1', 11);
        $this->account('acc-2', 12);
        $this->charge('acc-1', 'officer-1', 'ACCOUNT_FUNDED');
        $this->charge('acc-2', 'officer-1', 'ACCOUNT_CHARGED');

        self::assertSame(
            [12],
            $this->scope->chargedAccountDbIds(),
            'only ACCOUNT_CHARGED denotes a charge; another event type on the same account is not one'
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
