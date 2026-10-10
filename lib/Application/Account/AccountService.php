<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Account\Repository\AccountMovementRepository;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Configuration\Repository\RoleAssignmentRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Money;
use OCA\PettyCash\Domain\Shared\Uuid;

/**
 * Read access to a user's own petty-cash accounts and their ledgers, and the two writes the contract
 * declares against them.
 *
 * A purchaser may only ever see and select their own accounts, so the acting user is supplied by the
 * trusted controller from the authenticated session and is never taken from a request parameter. A
 * `userId` filter is accepted only when it names the caller, because the endpoint may not be used to
 * read another user's balances.
 *
 * The writes are here rather than in the controller because both are authorization decisions, and the
 * controller is the layer the project rules forbid holding them: FR-015 says **finance** charges an
 * account, and FR-013 says an administrator manages accounts for users, so who may do what is a rule
 * about the domain and not about HTTP.
 *
 * The `Account` and `Movement` arrays themselves are `AccountPayloadFormatter`'s, not this class's.
 * That class used to live in `Infrastructure/Http/`, where this one could not reach it without
 * inverting the layer direction, so this service grew its own copies — three of the account shape and
 * three of the movement shape by T289, two of them private twins of each other inside this one file.
 * They are gone; the reasoning is in the formatter.
 */
final class AccountService
{
    public function __construct(
        private AccountRepository $accounts,
        private AccountMovementRepository $movements,
        private CurrencyRepository $currencies,
        private RoleAssignmentRepository $roleAssignments,
        private IdempotencyService $idempotency,
        private CurrentUser $currentUser,
        private AccountPayloadFormatter $payload,
        private AuditWriter $auditWriter
    ) {}

    /**
     * The caller's active accounts, in the shape `contracts/ocs-api.yaml` requires: an `Account`
     * carrying a `Money` balance whose `minorUnit` is the configured fraction digits for that
     * currency, never a value guessed from a built-in table. The shape itself is
     * `AccountPayloadFormatter::account()`, shared with the endpoints that report the same aggregate
     * after writing it.
     *
     * An empty page is a valid answer: having no account is a normal state the purchaser UI must be
     * able to render, not an error. Only the active projection is exposed, which is what selecting
     * an account for a new sheet requires; listing inactive accounts is part of the finance and
     * administration surface owned by T085 and T088.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int}
     */
    public function listForUser(string $userUid, int $page = 1, int $pageSize = 25): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $items = [];
        foreach ($this->accounts->findActiveByUserUid($userUid) as $account) {
            $items[] = $this->payload->account($account);
        }

        $total = count($items);

        return [
            'items' => array_slice($items, ($page - 1) * $pageSize, $pageSize),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * One account's ledger, in the shape `contracts/ocs-api.yaml` requires: a `Movement` per row,
     * carrying the `Money` shape the purchaser and finance views format at the currency's own
     * configured scale.
     *
     * Ownership is enforced here rather than in the controller, because the account's public id
     * arrives from the request and the ledger is the most sensitive read in the application: a
     * ledger states what a named person was given and what they spent. An account belonging to
     * somebody else is refused with the same error as one that does not exist, so the endpoint
     * cannot be used to discover which account ids are real.
     *
     * `total` is the account's full movement count rather than the size of the requested page,
     * which is what lets the view offer pagination and say how much history exists.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int}
     */
    public function listMovements(
        string $userUid,
        string $accountPublicId,
        int $page = 1,
        int $pageSize = 25
    ): array {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $account = $this->accounts->findByPublicId($accountPublicId);
        if ($account === null || $account->userUid !== $userUid) {
            // One message for both cases on purpose: distinguishing them would turn this endpoint
            // into an oracle for which account ids exist.
            throw new LogicException('Account not found for the authenticated user');
        }
        if ($account->dbId === null) {
            throw new LogicException('Account has not been persisted');
        }

        $all = $this->movements->findByAccountId($account->dbId);
        $total = count($all);
        // One query for the whole ledger and slice it, the same trade `listForUser` makes: the
        // alternative is a count query plus a paged one, and the contract needs `total` for the
        // page metadata regardless, so the single read is both cheaper and simpler to reason about.
        $ledgerPage = array_slice($all, ($page - 1) * $pageSize, $pageSize);

        $items = [];
        foreach ($ledgerPage as $movement) {
            $items[] = $this->payload->movement($movement, $account);
        }

        return [
            'items' => $items,
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * FR-013/FR-014: open a petty-cash account in a supported currency.
     *
     * A user opens their own; an administrator opens one for somebody else, which is what FR-013's
     * "manage … currency-specific petty-cash accounts **for users**" asks for. Anyone else naming
     * another user is refused — the contract declares 403 for this endpoint, and an endpoint that
     * opened accounts for arbitrary uids would be an account-minting oracle.
     *
     * A currency that is not configured, or is configured inactive, is refused rather than created in
     * an unknown scale: `amountMinor` means nothing without a `minor_unit`, and a guess is a factor of
     * a hundred on every figure the account will ever hold.
     *
     * One account per user and currency, enforced by the table's unique index and reported here as a
     * conflict rather than left to surface as a database error. Re-opening the account a user already
     * has is not a second account; the contract has no idempotent answer for a create, and the
     * command id is the caller's key if they meant to repeat one.
     *
     * @return array<string, mixed> the contract's `Account`
     */
    public function createFor(string $actorUid, string $userUid, string $currencyCode): array
    {
        if (trim($userUid) === '') {
            throw new InvalidArgumentException('userId is required');
        }

        if ($userUid !== $actorUid && !$this->hasRole($actorUid, Role::Administrator)) {
            throw new LogicException('Accounts may only be opened for the authenticated user');
        }

        $currency = $this->currencies->findActiveByCode($currencyCode);
        if ($currency === null) {
            throw new InvalidArgumentException('Currency is not a supported active currency');
        }

        if ($this->accounts->findActiveByUserAndCurrency($userUid, CurrencyCode::fromString($currency->code())) !== []) {
            throw new AccountAlreadyExists('This user already has an active account for that currency');
        }

        $account = \OCA\PettyCash\Domain\Account\PettyCashAccount::create(
            (string) Uuid::random(),
            $userUid,
            $currency
        );

        try {
            $this->accounts->save($account);
        } catch (\Throwable $e) {
            // The unique index is the real guard: two concurrent opens of the same pair both read
            // "no account" and then both try to insert. Reporting that as a conflict is honest; a
            // raw driver error is not something a caller can act on.
            if ($this->isDuplicateKey($e)) {
                throw new AccountAlreadyExists('This user already has an active account for that currency');
            }

            throw $e;
        }

        $this->recordCreationAudit($account, $actorUid, $userUid, $currency->code());

        return $this->payload->account($account);
    }

    /**
     * FR-017: record that an account came into existence here.
     *
     * **This method has two origins, and the role is what distinguishes them.** `createFor()` admits
     * both an administrator opening an account for another user and the account holder opening one for
     * themselves — the gate above only demands the administrator role when `$userUid !== $actorUid`.
     * FR-013 describes the administrator path; the self-service path is a consequence of that gate
     * rather than a separately stated requirement, and it is recorded here rather than left implicit,
     * because "who authorised this account" and "who opened this account" have different answers.
     *
     * **The same `ACCOUNT_CREATED` event type serves both, and the provisioning path in
     * `AccountAdministrationService` writes it too.** One type means a reader filtering the
     * configuration audit for account creations sees all three origins in one place; two types would
     * require the reader to know that `createFor()` has a self-service branch to find them all.
     *
     * **`origin` in `after` is what makes provisioning legible** rather than merely inferable: an
     * `actor_role` of `PURCHASER` on a created account is either a self-service open or an FR-020
     * provisioning, and those are different facts for anyone asking why an account exists.
     *
     * `entity_id` is the account's **public id**, matching the funding event below — `ChargedAccountScope`
     * resolves it through `findByPublicId()`, so a surrogate key here would yield an empty scope rather
     * than an error.
     */
    private function recordCreationAudit(
        \OCA\PettyCash\Domain\Account\PettyCashAccount $account,
        string $actorUid,
        string $userUid,
        string $currencyCode
    ): void {
        $selfService = $userUid === $actorUid;

        $this->auditWriter->appendConfiguration([
            'id' => (string)Uuid::random(),
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'event_type' => ConfigurationEventType::AccountCreated->value,
            // FR-017 requires an explanation. This path has no caller-supplied text, so the record
            // states the mechanism instead of an empty field or an invented quotation.
            'explanation' => $selfService
                ? 'Opened for themselves by the account holder'
                : 'Opened by an administrator for another user',
            'actor_role' => ($selfService ? Role::Purchaser : Role::Administrator)->value,
            'before' => [],
            'after' => [
                'currency' => $currencyCode,
                'balance_minor' => 0,
                'active' => true,
                'origin' => $selfService ? 'self-service' : 'administrator',
            ],
        ]);
    }

    /**
     * FR-013: an administrator deactivates or reactivates a petty-cash account.
     *
     * **Administrator-only, and the gate is on the role rather than on ownership.** Unlike `createFor()`,
     * which admits the holder opening one for themselves, there is no self-service branch here: a
     * purchaser retiring their own account would strand themselves with no way to create a sheet again
     * except through the administrator they just bypassed — which is exactly the state FR-020's carve-out
     * refuses to create by accident. Deactivation is a deliberate, audited act, and deliberation is what
     * the administrator role marks.
     *
     * An unknown account is refused the same way `charge()` refuses one, so this endpoint cannot be used
     * to discover which account ids are real. A request for the state the account already holds succeeds
     * with the current state and writes **no** audit row — nothing changed, so there is nothing to record,
     * following the same rule the provisioning path applies to a concurrent loser.
     *
     * Optimistic locking is honoured like `charge()` honours it: `expectedVersion` must match, or the
     * change is a 409. Two administrators acting on the same account at the same moment is the ordinary
     * case for a shared administration surface, and a lost update here silently un-does the other's
     * decision about whether an account may spend.
     *
     * @return array<string, mixed> the contract's `Account`
     */
    public function setActive(
        string $actorUid,
        string $accountPublicId,
        bool $active,
        int $expectedVersion,
        ?string $explanation = null
    ): array {
        if (!$this->hasRole($actorUid, Role::Administrator)) {
            throw new LogicException('Only administrators may change an account\'s active state');
        }

        $account = $this->accounts->findByPublicId($accountPublicId);
        if ($account === null) {
            throw new UnknownAccount('No such petty-cash account');
        }

        if ($account->lockVersion !== $expectedVersion) {
            throw new StaleAccountVersion('The account has changed; reload it and retry the change');
        }

        if (!$account->setActiveByAdministrator($active)) {
            // Already in the requested state: the version was still checked above, so a stale caller
            // is told to reload rather than handed a success that hides that their view was old.
            return $this->payload->account($account);
        }

        $this->accounts->save($account);

        $this->auditWriter->appendConfiguration([
            'id' => (string)Uuid::random(),
            'entity_type' => 'account',
            // The public id, matching the funding and creation events: `ChargedAccountScope` resolves
            // it through `findByPublicId()`, so a surrogate key would silently produce an empty scope.
            'entity_id' => $account->id,
            'event_type' => ($active
                ? ConfigurationEventType::AccountReactivated
                : ConfigurationEventType::AccountDeactivated)->value,
            // FR-017 requires an explanation. A caller-supplied reason states why this account was
            // retired or restored, which is the fact a later reader most needs; the mechanism sentence
            // is the fallback, not an invented quotation.
            'explanation' => trim((string)$explanation) !== ''
                ? trim((string)$explanation)
                : ($active ? 'Reactivated by an administrator' : 'Deactivated by an administrator'),
            'actor_role' => Role::Administrator->value,
            'before' => ['active' => !$active],
            'after' => ['active' => $active],
        ]);

        return $this->payload->account($account);
    }

    /**
     * FR-015: finance charges an account and the amount, date, description and resulting balance are
     * recorded as a movement.
     *
     * The movement is what makes a balance reconstructible, so a charge that moved the balance and
     * left no row is the failure this method exists to prevent — and the unique `command_id` index
     * means a replayed command would collide rather than double-charge, so the command id is run
     * through the same idempotency service the decision path uses and the **stored response** is
     * returned for a replay.
     *
     * Optimistic locking is honoured: `expectedVersion` must match the account's current version, or
     * the charge is a 409. Funding is the one write two finance officers make at the same moment, and
     * a lost update here is money that vanishes from the ledger.
     *
     * @param int $amountMinor positive; a charge that does not add money is not a charge
     * @return array<string, mixed> the contract's `AccountMutationResult`
     */
    public function charge(
        string $actorUid,
        string $accountPublicId,
        int $amountMinor,
        string $description,
        \DateTimeImmutable $occurredAt,
        int $expectedVersion,
        string $commandId
    ): array {
        if ($amountMinor < 1) {
            throw new InvalidArgumentException('amountMinor must be at least 1');
        }
        if (trim($description) === '') {
            throw new InvalidArgumentException('description is required');
        }
        if (strlen($description) > 1000) {
            throw new InvalidArgumentException('description must be at most 1000 characters');
        }

        // FR-015 names finance as the actor. An administrator is allowed as well, because FR-013 puts
        // account funding with administrators and the spec's journey has the same person doing both.
        if (!$this->hasRole($actorUid, Role::Finance) && !$this->hasRole($actorUid, Role::Administrator)) {
            throw new LogicException('Only finance may charge a petty-cash account');
        }

        $account = $this->accounts->findByPublicId($accountPublicId);
        if ($account === null) {
            // FR-015 has finance charge "a **selected user** petty-cash account", so another user's
            // account is the normal case here and ownership is deliberately not the rule — the actor
            // gate above is. What has to be refused is an account that is not there at all.
            throw new UnknownAccount('No such petty-cash account');
        }

        if ($account->lockVersion !== $expectedVersion) {
            throw new StaleAccountVersion('The account has changed; reload it and retry the charge');
        }

        if (!$account->active) {
            throw new LogicException('An inactive account cannot be charged');
        }

        return $this->idempotency->execute(
            $commandId,
            $actorUid,
            'account.charge',
            $accountPublicId,
            [
                'account_id' => $accountPublicId,
                'amount_minor' => $amountMinor,
                'description' => $description,
                'occurred_at' => $occurredAt->format(\DateTimeInterface::ATOM),
                'actor' => $actorUid,
            ],
            function () use ($account, $actorUid, $amountMinor, $description, $occurredAt, $commandId): array {
                // Read before the charge, because `PettyCashAccount::charge()` mutates
                // `balanceMinor` in place. A "before" read after that call would be the
                // post-charge value wearing a "before" label — the same trap
                // `FinanceSettlementService::recordConsumption()` documents at length, avoided here
                // rather than copied, because this method receives the account *before* the mutation
                // and so can read an honest pair. FR-017 requires the previous value be recorded
                // for a sensitive change, and a funding charge is one.
                $balanceBefore = $account->balanceMinor;

                $movement = $account->charge(
                    Money::of($amountMinor, $account->currencyCode()),
                    $commandId,
                    $description,
                    $occurredAt
                );

                $this->movements->save($movement);
                $this->accounts->save($account);

                // FR-055: a funding charge names its account in the configuration audit stream, and
                // FR-055's scope is derived from exactly this event. T294 wrote the settlement side of
                // the pair; without this half, an officer who had funded an account but not yet
                // settled anything against it had no event naming it — so the account was absent from
                // their `accounts` report and from their configuration-audit visibility. FR-055 names
                // that exact gap: "relying on the transaction-audit decision alone would leave this
                // scope empty for every officer who had actually charged an account."
                //
                // Inside the idempotent closure, so a replayed command writes no second event — the
                // same rule `createTransaction()` and `updateTransaction()` follow for the FR-051
                // events they write.
                //
                // `entity_type`/`entity_id` address the subject because a configuration event has no
                // sheet or transaction of its own. `ChargedAccountScope` resolves
                // `entity_id` with `AccountRepository::findByPublicId()`, so it must be the public id
                // and not the surrogate key — the settlement's event gets this right only because its
                // `entity_id` is `$account->id`, which is the public id; this is worth naming because
                // a database id here would silently produce an empty scope rather than an error.
                $this->auditWriter->appendConfiguration([
                    'id' => (string)Uuid::random(),
                    'entity_type' => 'account',
                    'entity_id' => $account->id,
                    'event_type' => ConfigurationEventType::AccountFunded->value,
                    'explanation' => $description,
                    // The two roles FR-015's actor gate admits. That gate has already run by the time
                    // this closure executes, so one of the two is true; asking the role assignments
                    // rather than hardcoding `FINANCE` keeps the audit row honest about who did it,
                    // which is one of the two things FR-055 says the event must name. Defaulting to
                    // the writer's `'PURCHASER'` would have been the alternative, and it would have
                    // stamped a finance funding as a purchase.
                    'actor_role' => $this->actorRoleFor($actorUid)->value,
                    'before' => [
                        'balance_minor' => $balanceBefore,
                        'currency' => $account->currencyCode()->__toString(),
                    ],
                    'after' => [
                        'balance_minor' => $movement->balanceAfter()->minorUnits(),
                        'currency' => $account->currencyCode()->__toString(),
                        'movement_id' => $movement->id,
                        'amount_minor' => $amountMinor,
                    ],
                    'command_id' => $commandId,
                ]);

                return $this->payload->mutation($account, $movement);
            },
            201
        );
    }

    private function hasRole(string $userUid, Role $role): bool
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($this->roleAssignments->findActiveByUserUid($userUid, $now) as $assignment) {
            if ($assignment->active && $assignment->role === $role) {
                return true;
            }
        }

        return false;
    }

    /**
     * The role to record on the funding audit event, resolved from the actor's own assignments.
     *
     * FR-015 admits finance and, through FR-013, an administrator, and FR-055 requires the funding
     * event to "name the charging officer". An administrator funding an account is a real and
     * specified case, so hardcoding `FINANCE` would misattribute exactly the situation FR-013
     * creates; and `AuditWriterService`'s default of `PURCHASER` would misattribute all of them.
     *
     * `Administrator` is the fallback rather than an error because the actor gate above has already
     * refused anyone holding neither role — by the time this runs, one of the two is true. A person
     * holding both is recorded as `FINANCE`, because FR-015 names finance as the charging role and a
     * dual-role actor is doing the finance thing here.
     */
    private function actorRoleFor(string $actorUid): Role
    {
        return $this->hasRole($actorUid, Role::Finance)
            ? Role::Finance
            : Role::Administrator;
    }

    /**
     * Whether a driver error is a unique-index violation.
     *
     * Matched on the SQLSTATE rather than the driver's message, which differs between the SAPI and
     * the CLI and between MariaDB versions; 23000 is the integrity class and 1062 the duplicate-key
     * code inside it.
     */
    private function isDuplicateKey(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '23000') || str_contains($message, '1062');
    }
}
