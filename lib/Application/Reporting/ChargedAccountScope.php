<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use OCA\PettyCash\Domain\Account\Repository\AccountRepository;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;

/**
 * FR-016 and FR-055: which accounts a finance officer may see beyond their own.
 *
 * ## Why this exists as its own collaborator
 *
 * FR-055 requires configuration audit to be visible to finance officers "for accounts they charge",
 * and until T294 nothing recorded a charge at all, so there was no such thing as a charged account
 * to scope over. T106 hit the resulting gap and stopped rather than inventing a way to answer it:
 * the one row that *did* hold the answer was `pettycash_command_results.actor_uid`, reachable only
 * one command at a time, and deriving the answer from there would have coupled
 * `IdempotencyService`'s port to a reporting concern.
 *
 * T294 removed that trap. A charge now writes an `ACCOUNT_CHARGED` event to the configuration audit
 * stream naming the account in `entity_id`, which is the record FR-017 asks for anyway — so the
 * derivation reads the audit trail rather than the command log, needs no schema change, and does
 * not touch a port that idempotency depends on. No migration, and so no approval-gated baseline
 * reset.
 *
 * ## Instance-wide, not per-officer
 *
 * Every finance officer shares one queue, so a charge is a fact about the instance rather than about
 * the officer who made it. An officer may therefore read configuration audit for accounts charged
 * *at all*, not only their own charges. This class deliberately has no notion of a "current
 * officer": it answers the instance-wide question, and scoping that answer down to one person is
 * a narrowing this class does not perform.
 *
 * An account that cannot be resolved is dropped rather than reported as a scope member, because
 * `configurationAuditScope()` widens from the ids it is given and a fabricated id would widen the
 * visible set to include an account the caller cannot name.
 */
final readonly class ChargedAccountScope
{
    /** The event type a settlement writes when it debits an account (FR-055). */
    public const CHARGE_EVENT_TYPE = 'ACCOUNT_CHARGED';

    public function __construct(
        private AuditEventRepository $configurationAudit,
        private AccountRepository $accounts
    ) {}

    /**
     * The surrogate keys of every account that has been charged, instance-wide.
     *
     * Returned as dbIds because that is what `ReportQuery::configurationAuditScope()` takes.
     * A caller given an empty list must treat it as an empty scope, never as "all": an officer who
     * has charged nothing sees nothing, which is the safe direction to fail.
     *
     * @return list<int>
     */
    public function chargedAccountDbIds(): array
    {
        $dbIds = [];

        foreach ($this->configurationAudit->findEntityIdsByEventType(self::CHARGE_EVENT_TYPE) as $publicId) {
            $account = $this->accounts->findByPublicId($publicId);
            if ($account !== null && $account->dbId !== null) {
                $dbIds[] = $account->dbId;
            }
        }

        return array_values(array_unique($dbIds));
    }
}
