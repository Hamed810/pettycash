<?php

declare(strict_types=1);

namespace OCA\PettyCash\Tests\Integration\Account;

use OCA\PettyCash\Application\Account\AccountPayloadFormatter;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Audit\AuditWriterService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountMovementRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAccountRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryAuditEventRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCommandResultRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrentUser;
use OCA\PettyCash\Tests\TestDoubles\InMemoryCurrencyRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryRoleAssignmentRepository;
use OCA\PettyCash\Tests\TestDoubles\InMemoryUserDirectory;
use PHPUnit\Framework\TestCase;

/**
 * FR-017 (clarified 2026-10-08): `AccountService::createFor()` records the account it opens, and the
 * recorded role says **which** of its two origins did it.
 *
 * ## Why this file exists separately from the provisioning test
 *
 * `AccountCreationAuditTest` covers `AccountAdministrationService::ensureDefaultRialAccount()`. The
 * property that matters *here* is the **role**, and only `AccountService` can see it: `createFor()` has
 * two origins — an administrator opening an account for another user (FR-013) and the account holder
 * opening one for themselves — and the actor gate demands the administrator role only when
 * `$userUid !== $actorUid`. Splitting the assertions by the property each service can observe is why
 * neither file needs the other to be complete.
 *
 * The self-service origin is worth naming: it is a consequence of that gate rather than a separately
 * stated requirement, and it is the origin that records a `PURCHASER` on a **created** account. Without
 * this file it would be indistinguishable from FR-020 provisioning in the audit trail.
 *
 * ## Why the assertions go through the real `AuditWriterService`
 *
 * `InMemoryAuditWriter` records the array it was *handed*; this wires the production writer over
 * in-memory repositories, so the actor, the role, the subject and the JSON snapshots all come from
 * `toEvent()`'s own mapping. A test asserting the payload would pass even if the writer dropped a field
 * the stored row depends on — the same gap that let account creation go unaudited at all.
 *
 * `AccountFundingAuditTest` documents that argument for the funding event; it applies unchanged here.
 * In-memory repositories, so no configuration-audit row reaches the lab database and no cleanup is owed.
 */
final class AccountOpenAuditTest extends TestCase
{
    private InMemoryAccountRepository $accounts;

    private InMemoryCurrencyRepository $currencies;

    private InMemoryRoleAssignmentRepository $roleAssignments;

    private InMemoryAuditEventRepository $configurationAudit;

    private const HOLDER = 'the-account-holder';
    private const ADMINISTRATOR = 'the-administrator';
    private const CURRENCY = 'OPN';

    protected function setUp(): void
    {
        $this->accounts = new InMemoryAccountRepository();
        $this->currencies = new InMemoryCurrencyRepository();
        $this->roleAssignments = new InMemoryRoleAssignmentRepository();
        $this->configurationAudit = new InMemoryAuditEventRepository();

        $this->currencies->save(Currency::create(self::CURRENCY, 'Account Open Test Currency', 2));
    }

    /**
     * @param list<string> $administratorRoles uids to grant the administrator role
     */
    private function serviceFor(string $actorUid, array $administratorRoles = []): AccountService
    {
        foreach ($administratorRoles as $uid) {
            $this->grantRole($uid, Role::Administrator);
        }

        return new AccountService(
            $this->accounts,
            new InMemoryAccountMovementRepository(),
            $this->currencies,
            $this->roleAssignments,
            new IdempotencyService(new InMemoryCommandResultRepository()),
            new InMemoryCurrentUser($actorUid),
            new AccountPayloadFormatter(),
            new AuditWriterService(
                // FR-056: a configuration event goes to the configuration stream and nowhere else, so
                // the transaction stream is a separate double here as it is in the funding test.
                new InMemoryAuditEventRepository(),
                new InMemoryCurrentUser($actorUid),
                new InMemoryUserDirectory(),
                $this->configurationAudit
            )
        );
    }

    /**
     * The stored rows, not the payloads handed to the writer — so actor, role and subject come from
     * `toEvent()`'s mapping.
     *
     * @return list<\OCA\PettyCash\Domain\Audit\AuditEvent>
     */
    private function creationEvents(): array
    {
        return array_values(array_filter(
            $this->configurationAudit->getEvents(),
            static fn ($e): bool => $e->eventType === ConfigurationEventType::AccountCreated->value
        ));
    }

    /**
     * @return array<string,mixed>
     */
    private function afterOf(\OCA\PettyCash\Domain\Audit\AuditEvent $event): array
    {
        /** @var array<string,mixed> $decoded */
        $decoded = json_decode($event->afterJson, true);

        return $decoded;
    }

    private function grantRole(string $userUid, Role $role): void
    {
        $this->roleAssignments->save(
            RoleAssignment::create(
                $userUid,
                $role,
                null,
                new \DateTimeImmutable('2020-01-01T00:00:00+00:00'),
                null
            )
        );
    }

    // ===== the two origins =========================================================

    public function testAnAdministratorOpeningAnAccountForSomeoneElseRecordsTheAdministrator(): void
    {
        $service = $this->serviceFor(self::ADMINISTRATOR, [self::ADMINISTRATOR]);

        $service->createFor(self::ADMINISTRATOR, self::HOLDER, self::CURRENCY);

        $events = $this->creationEvents();

        self::assertCount(1, $events, 'FR-017: an account came into existence, so it must be recorded');
        self::assertSame(Role::Administrator->value, $events[0]->actorRole);
        self::assertSame(self::ADMINISTRATOR, $events[0]->actorUid);
        self::assertSame('account', $events[0]->entityType);
        self::assertSame('administrator', $this->afterOf($events[0])['origin'] ?? null);
    }

    public function testTheHolderOpeningAnAccountForThemselvesRecordsThemAsPurchaser(): void
    {
        // No administrator role is granted at all, so the call can only succeed through the
        // self-service branch — which is why the distinction is observable rather than incidental.
        $service = $this->serviceFor(self::HOLDER);

        $service->createFor(self::HOLDER, self::HOLDER, self::CURRENCY);

        $events = $this->creationEvents();

        self::assertCount(1, $events);
        self::assertSame(Role::Purchaser->value, $events[0]->actorRole);
        self::assertSame(self::HOLDER, $events[0]->actorUid);
        self::assertSame('self-service', $this->afterOf($events[0])['origin'] ?? null);
    }

    public function testBothOriginsUseTheOneSharedEventType(): void
    {
        // The clarification's central claim: **one** event type for every origin. Two types would
        // require a reader to know that `createFor()` has a self-service branch to find them all, so
        // this asserts the rows are findable by event type alone after two different origins.
        $this->serviceFor(self::ADMINISTRATOR, [self::ADMINISTRATOR])
            ->createFor(self::ADMINISTRATOR, 'user-a', self::CURRENCY);
        $this->serviceFor('user-b')->createFor('user-b', 'user-b', self::CURRENCY);

        $events = $this->creationEvents();

        self::assertCount(2, $events, 'one shared event type covers both origins');
        self::assertSame(
            [Role::Administrator->value, Role::Purchaser->value],
            array_map(static fn ($e): string => $e->actorRole, $events),
            'the role, not the event type, is what distinguishes the two origins'
        );
    }

    // ===== the properties that keep it honest =====================================

    public function testTheRowNamesTheAccountItOpenedByItsPublicId(): void
    {
        $service = $this->serviceFor(self::HOLDER);
        $payload = $service->createFor(self::HOLDER, self::HOLDER, self::CURRENCY);

        $events = $this->creationEvents();

        // `ChargedAccountScope` resolves `entity_id` through `findByPublicId()`, so a surrogate key
        // would silently produce an empty scope rather than an error — which is why this asserts
        // against the returned id rather than merely asserting the key is present.
        self::assertSame($payload['id'], $events[0]->entityId);
    }

    public function testARefusedOpenRecordsNothing(): void
    {
        $service = $this->serviceFor(self::HOLDER);
        $service->createFor(self::HOLDER, self::HOLDER, self::CURRENCY);
        $this->configurationAudit->clear();

        // Second open of the same (user, currency) pair: refused as a conflict.
        try {
            $service->createFor(self::HOLDER, self::HOLDER, self::CURRENCY);
            self::fail('a second open of the same account and currency must be refused');
        } catch (\Throwable $refused) {
            // The refusal is the point; nothing below depends on its type.
        }

        self::assertSame(
            [],
            $this->creationEvents(),
            'FR-017: a refused open created nothing, so it records nothing. Recording a refused attempt '
            . 'would claim a creation that never happened.'
        );
    }

    public function testAnUnauthorisedOpenForAnotherUserIsRefusedAndRecordsNothing(): void
    {
        // No administrator role, and the holder asks for somebody else's account.
        $service = $this->serviceFor(self::HOLDER);

        $this->expectException(\LogicException::class);

        try {
            $service->createFor(self::HOLDER, 'someone-else', self::CURRENCY);
        } finally {
            self::assertSame(
                [],
                $this->creationEvents(),
                'FR-003/FR-013: the actor gate runs before any write, so no audit row can precede it.'
            );
        }
    }

    public function testTheEventTypeFitsTheColumnAndIsDistinctFromItsSiblings(): void
    {
        // The column is `string(48)`; an overflowing enum value fails at insert time in production
        // rather than in a unit test, which is why the width is pinned here.
        self::assertLessThanOrEqual(48, strlen(ConfigurationEventType::AccountCreated->value));

        // Distinctness matters: were the created event to reuse a funding value, `ChargedAccountScope`
        // would start matching it and a finance officer would gain visibility of an uncharged account.
        self::assertNotSame(
            ConfigurationEventType::AccountFunded->value,
            ConfigurationEventType::AccountCreated->value
        );
        self::assertNotSame(
            ConfigurationEventType::AccountCharged->value,
            ConfigurationEventType::AccountCreated->value
        );
    }
}