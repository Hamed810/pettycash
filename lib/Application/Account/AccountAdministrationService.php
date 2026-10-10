<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Domain\Account\PettyCashAccount;
use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Shared\CurrencyCode;
use OCA\PettyCash\Domain\Shared\Enum\Role;
use OCA\PettyCash\Domain\Shared\Uuid;

/**
 * FR-014 / FR-020 default petty-cash account provisioning. T085.
 *
 * ## What this owns, and what it deliberately does not
 *
 * The ledger line for this task reads "idempotent default rial account provisioning, additional currency
 * accounts, activation state, and finance account charging". Three of those four already exist and are
 * **not** reimplemented here, because a second implementation would be a second set of rules rather
 * than a second route to the same one:
 *
 * | T085 phrase | Where it actually lives | Why it is not here |
 * |---|---|---|
 * | additional currency accounts | `AccountService::createFor()` | FR-013's write, already delivered and covered by `AccountWriteControllerTest`. Copying it would give the unique-index conflict two error paths. |
 * | finance account charging | `AccountService::charge()` | FR-015's write, including its `expectedVersion` guard and idempotent replay. `FinanceSettlementService` also consumes movements these produce. |
 * | activation state | `PettyCashAccount::$active`, honoured by `AccountRepository::findActiveBy*` | FR-013 says "manage … accounts for users" and, unlike FR-010/FR-012, does **not** say "activate, and deactivate". `contracts/ocs-api.yaml` declares no account activation endpoint. There is no requirement to build against, so none is invented. |
 *
 * What remains is the one clause no other file claims: FR-020's **provisioning trigger**. A purchaser
 * with zero active accounts gets a default active rial account so that "create a sheet" is possible at
 * all. Before this class, that path did not exist in any layer — `SheetService::createSheet()` resolves
 * an `accountId` the caller must already hold, and the sheet form disables submission when the account
 * list is empty, so a brand-new purchaser had no route to a first sheet.
 *
 * ## Why the currency comes from `CurrencyService` and not from a constant here
 *
 * The code `IRR` and the scale `minor_unit = 0` both belong to configuration (T081 owns that decision
 * and documents it). Importing `CurrencyService::defaultRial()` means a provisioning path cannot invent
 * a scale: an installation whose `IRR` is missing or mis-scaled is refused with a message naming the
 * administrator's fix, instead of provisioning an account whose every future amount is off by a factor
 * of a hundred. A `new Currency('IRR', ...)` constructed here would be exactly that bug with the
 * failure moved further from its cause.
 *
 * ## Why provisioning is not written to the configuration audit stream
 *
 * `ConfigurationAuditService::record()` stamps `actor_role = Administrator`, which is what the four
 * Phase-7 administration services need and would be **false** here: the actor is a purchaser who
 * triggered onboarding by opening a form, and in the zero-account case there may be no human decision
 * behind the write at all. FR-017's own list — "assignments, expense types, currencies, access, and
 * control settings" — describes sensitive administrator changes, and this is not one. The decision is
 * recorded here rather than left implicit so the next reader does not "fix" the missing audit.
 *
 * ## Idempotency, precisely
 *
 * FR-020 asks for idempotent provisioning. The property that matters is that a second call **writes
 * nothing**, because a purchaser who opens the sheet form twice must not accumulate a second default
 * account and, with it, the multi-account ambiguity FR-020 asks the UI to resolve. That falls out of the
 * precondition rather than out of a lookup of "the account I created last time": once the first call has
 * provisioned, the caller *has* an active account, so the precondition is false and the second call
 * provisions nothing. Under genuine concurrency — two requests that both read "zero accounts" — the
 * unique index on (user, currency) is the real guard and the loser re-reads the winner's row instead of
 * reporting a conflict, because provisioning is not a caller-visible write and a 409 here would be a
 * spurious failure of an operation the caller cannot retry meaningfully.
 */
final readonly class AccountAdministrationService
{
    public function __construct(
        private AccountRepository $accounts,
        private CurrencyService $currencies,
        private AuditWriter $auditWriter,
    ) {}

    /**
     * FR-020: provision the default active rial account a purchaser with **zero active accounts** needs.
     *
     * @return PettyCashAccount|null the newly provisioned account, or null when the precondition did
     *                              not hold — that is, when the caller already has at least one active
     *                              account and therefore has nothing to be provisioned
     *
     * @throws InvalidArgumentException when the uid is empty, or the configured rial is missing or
     *                                  carries a scale the rial cannot have
     */
    public function ensureDefaultRialAccount(string $userUid): ?PettyCashAccount
    {
        if (trim($userUid) === '') {
            throw new InvalidArgumentException('userId is required');
        }

        // FR-020's trigger fires on zero **active** accounts — and that is necessary but not sufficient.
        // A deactivated account is not active, so the trigger's precondition is met by an account somebody
        // deliberately retired; provisioning there would let a sheet-creation click silently reverse an
        // administrator's deactivation and replace the account with a new one carrying neither its identity
        // nor its history. The distinction is whether a rial record **exists**, not the active flag alone.
        if ($this->accounts->findActiveByUserUid($userUid) !== []) {
            return null;
        }

        // Resolved before the insert so a misconfigured installation fails with nothing written,
        // rather than provisioning an account and then discovering it has no usable scale.
        $rial = $this->currencies->defaultRial();

        // The carve-out: an inactive rial record blocks provisioning rather than triggering it. The
        // refusal names the account and the remedy, because a purchaser told only "no active account"
        // has neither a way to proceed nor a way to learn that one exists — the same actionable-refusal
        // rule the settlement path follows. Only the holder's **own** accounts are read: another user's
        // inactive rial account is not this purchaser's state and must not block them.
        $inactiveRial = null;
        foreach ($this->accounts->findAllByUserUid($userUid) as $candidate) {
            if (!$candidate->active && $candidate->currencyCode()->__toString() === $rial->code()) {
                $inactiveRial = $candidate;
                break;
            }
        }
        if ($inactiveRial !== null) {
            throw new InvalidArgumentException(sprintf(
                'Account %s (%s) is deactivated; an administrator must reactivate it or open a replacement '
                . 'before a sheet can be created',
                $inactiveRial->id,
                $rial->code()
            ));
        }

        $account = PettyCashAccount::create((string)Uuid::random(), $userUid, $rial);

        try {
            $this->accounts->save($account);
        } catch (\Throwable $e) {
            if (!$this->isDuplicateKey($e)) {
                throw $e;
            }

            // Two requests both read "zero active accounts". The index decided, and the other request's
            // row is the answer for both callers -- see the idempotency note on the class.
            $existing = $this->accounts->findActiveByUserAndCurrency(
                $userUid,
                CurrencyCode::fromString($rial->code())
            );
            if ($existing === []) {
                // The index rejected the insert but the winning row is not readable, which means the
                // conflict was on something other than this (user, currency) pair. Reporting the driver
                // error is honest; inventing an account here would not be.
                throw $e;
            }

            // **No audit row here**, and deliberately: this request created nothing. The concurrent
            // request that won the index writes the row, so exactly one `ACCOUNT_CREATED` exists per
            // account even when two callers raced. Recording it here would claim a creation that did
            // not happen, which is the failure mode FR-017's `before`/`after` pair exists to prevent.
            return $existing[0];
        }

        $this->recordProvisioningAudit($account, $rial->code());

        return $account;
    }

    /**
     * FR-017: record that provisioning created an account.
     *
     * **The actor is the account holder**, which is what distinguishes this row from an
     * administrator's deliberate creation (`AccountService::createFor()`) and what makes it honest about
     * the state it records: the account exists, and **no administrator authorised it**. The same
     * `ACCOUNT_CREATED` event type serves both origins precisely so that one filter over the trail
     * answers "which accounts exist and who authorised each" rather than needing the reader to know
     * two event types.
     *
     * `entity_id` is the account's **public id**, not its surrogate key — `ChargedAccountScope` resolves
     * it through `AccountRepository::findByPublicId()`, so a database id here would silently produce an
     * empty scope rather than an error. `AccountService`'s funding event carries the same caveat.
     *
     * Appended after the save, following the ordering `AccountService::charge()` already uses. That
     * ordering means a failing append leaves a created account unrecorded; the alternative — recording
     * first — would claim an account that a failed insert then erased. This is the lesser fault, and it
     * matches the established pattern rather than introducing a second one.
     */
    private function recordProvisioningAudit(PettyCashAccount $account, string $currencyCode): void
    {
        $this->auditWriter->appendConfiguration([
            'id' => (string)Uuid::random(),
            'entity_type' => 'account',
            'entity_id' => $account->id,
            'event_type' => ConfigurationEventType::AccountCreated->value,
            // FR-017's explanation. Provisioning has no caller-supplied text, and naming the mechanism
            // is more useful to a reader than an empty field or an invented quotation.
            'explanation' => 'Provisioned automatically on first sheet creation attempt (FR-020)',
            'actor_role' => Role::Purchaser->value,
            'before' => [],
            'after' => [
                'currency' => $currencyCode,
                'balance_minor' => 0,
                'active' => true,
                // Self-service is the fact worth recording, and it is not otherwise visible from the
                // row: nothing else distinguishes this account from one an administrator opened.
                'origin' => 'fr020-provisioning',
            ],
        ]);
    }

    /**
     * FR-020's three clauses, as the sheet-creation path reads them.
     *
     * | Caller's active accounts | Answer |
     * |---|---|
     * | none, and no inactive rial record | provision the default rial account and return it |
     * | none, but an inactive rial record exists | **throw** — the trigger must not fire on a deliberately
     * retired account; the sheet-creation attempt is refused naming the account and its remedy |
     * | exactly one | return it — "a purchaser with one active account MUST receive its currency automatically" |
     * | several | null — "a purchaser with multiple active accounts MUST choose", and picking one here would make the server's choice the sheet's currency |
     *
     * @return PettyCashAccount|null the single account the sheet may be created against, or null when
     *                              the caller must choose for themselves
     */
    public function resolveAccountForNewSheet(string $userUid): ?PettyCashAccount
    {
        $active = $this->accounts->findActiveByUserUid($userUid);

        if (count($active) === 1) {
            return $active[0];
        }

        if (count($active) > 1) {
            return null;
        }

        return $this->ensureDefaultRialAccount($userUid);
    }

    /**
     * T391 (ADR-005): close every account a deleted user held. Returns how many accounts **changed**.
     *
     * **Deactivates; never deletes.** FR-054 and Constitution IV forbid removing a deleted user's
     * records, and FR-053/FR-057 forbid removing financial history, so a balance with a movement ledger
     * is not disposable. The balance stays readable and the account stops being spendable, which is the
     * distinction ADR-005 records.
     *
     * **The return value counts transitions, not rows visited.** `deactivateForDeletedOwner()` answers
     * `false` for an account that was already inactive, and those are excluded — so a caller can report
     * "deactivated 2 of 5 held" honestly instead of a number that cannot be reconciled with the
     * database. A retried `UserDeletedEvent` returns 0, which is the correct answer rather than a
     * silent re-write.
     *
     * **Uses `findAllByUserUid()`, not `findActiveByUserUid()`.** The active-only read would skip an
     * account deactivated by an earlier event, which is precisely the account this must not re-touch.
     *
     * @return int the number of accounts transitioned from active to inactive
     */
    public function deactivateAccountsOfDeletedUser(string $userUid): int
    {
        $deactivated = 0;

        foreach ($this->accounts->findAllByUserUid($userUid) as $account) {
            if (!$account->deactivateForDeletedOwner()) {
                continue;
            }

            $this->accounts->save($account);
            $deactivated++;
        }

        return $deactivated;
    }

    /**
     * Whether a driver error is a unique-index violation.
     *
     * Matched on the SQLSTATE rather than the driver's message, which differs between the SAPI and the
     * CLI and between MariaDB versions; 23000 is the integrity class and 1062 the duplicate-key code
     * inside it. The same test `AccountService::createFor()` uses, for the same reason.
     */
    private function isDuplicateKey(\Throwable $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, '23000') || str_contains($message, '1062');
    }
}