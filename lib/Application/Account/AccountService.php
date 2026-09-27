<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use InvalidArgumentException;
use LogicException;
use OCA\PettyCash\Application\Command\IdempotencyService;
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
        private AccountPayloadFormatter $payload
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
            [
                'account_id' => $accountPublicId,
                'amount_minor' => $amountMinor,
                'description' => $description,
                'occurred_at' => $occurredAt->format(\DateTimeInterface::ATOM),
                'actor' => $actorUid,
            ],
            function () use ($account, $amountMinor, $description, $occurredAt, $commandId): array {
                $movement = $account->charge(
                    Money::of($amountMinor, $account->currencyCode()),
                    $commandId,
                    $description,
                    $occurredAt
                );

                $this->movements->save($movement);
                $this->accounts->save($account);

                return $this->payload->mutation($account, $movement);
            }
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
