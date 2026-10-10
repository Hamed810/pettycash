<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use OCA\PettyCash\Application\Audit\ConfigurationEventType;
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
 * ## Two event types, because FR-055 names two kinds of thing
 *
 * FR-055 says an officer's charged accounts are the subjects of their own "account funding **and**
 * balance-charging" configuration audit events, and that an event type outside that pair "MUST NOT
 * count toward the set, because touching an account is not charging it". So the pair is a set of two
 * event types and this method reads both.
 *
 * Until T369 it read one. `AccountService::charge()` wrote no configuration event at all, so an
 * officer who had **funded** an account and had not yet settled anything against it had no event of
 * either kind naming that account — and therefore no configuration-audit visibility, and no place in
 * the `accounts` report. The requirement's own clarification had anticipated exactly this: "relying
 * on the transaction-audit decision alone would leave this scope empty for every officer who had
 * actually charged an account." T294 closed the settlement half of that sentence; this closes the
 * funding half.
 *
 * The set is read from `ConfigurationEventType` rather than from a literal here, because that enum is
 * the single definition of the configuration vocabulary and a second copy of a value that scope is
 * derived from is how the two drift apart unnoticed. That consolidation was deferred by the enum's
 * own docblock to "the task that owns those writes"; T369 owns the funding write, so this is that
 * task.
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
    /**
     * The configuration events that put an account in a finance officer's scope: FR-015's funding and
     * FR-043's settlement consumption.
     *
     * A `const` list rather than a second literal, so the vocabulary lives in
     * {@see ConfigurationEventType} and this class cannot come to name a third thing as a charge
     * without the enum changing too. Order is irrelevant — the result is a set.
     *
     * @var list<string>
     */
    private const CHARGE_EVENT_TYPES = [
        ConfigurationEventType::AccountFunded->value,
        ConfigurationEventType::AccountCharged->value,
    ];

    public function __construct(
        private AuditEventRepository $configurationAudit,
        private AccountRepository $accounts
    ) {}

    /**
     * The surrogate keys of every account that has been funded or charged, instance-wide.
     *
     * Returned as dbIds because that is what `ReportQuery::configurationAuditScope()` takes.
     * A caller given an empty list must treat it as an empty scope, never as "all": an officer who
     * has charged nothing sees nothing, which is the safe direction to fail — and FR-055 requires
     * that empty case to stay a *true* zero rather than becoming a widened scope.
     *
     * @return list<int>
     */
    public function chargedAccountDbIds(): array
    {
        $dbIds = [];

        foreach (self::CHARGE_EVENT_TYPES as $eventType) {
            foreach ($this->configurationAudit->findEntityIdsByEventType($eventType) as $publicId) {
                $account = $this->accounts->findByPublicId($publicId);
                if ($account !== null && $account->dbId !== null) {
                    $dbIds[] = $account->dbId;
                }
            }
        }

        return array_values(array_unique($dbIds));
    }
}
