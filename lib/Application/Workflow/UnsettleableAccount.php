<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Workflow;

use OCA\PettyCash\Application\Refusal;
use RuntimeException;

/**
 * FR-040 (clarified 2026-10-07): a final finance approval that cannot be settled, because no
 * **active** petty-cash account in the sheet's currency exists for the purchaser.
 *
 * ## Why this is a refusal and not a fault
 *
 * This path is reachable, not hypothetical. `AccountAdministrationService::deactivateAccountsOfDeletedUser`
 * deactivates a user's accounts, and FR-055 confirms accounts can be deactivated at any time — so a
 * sheet created while an account was active can reach final finance approval with that account gone.
 *
 * It used to throw `LogicException`, which `OcsErrorMapper` deliberately does not arm, so the answer
 * was `500 UNEXPECTED_FAILURE` with the message withheld. Finance was told the server had a fault for
 * a request that was entirely correct, and the real cause — a configuration change — was in neither
 * the response nor, without an operator reading the technical log, anywhere they could act on.
 *
 * ## Why it must not provision an account
 *
 * FR-020's idempotent auto-provisioning is scoped to **sheet creation** and is deliberately not
 * extended here. Provisioning at settlement would mean a finance officer's click silently creates a
 * funding object: approved expense moves against a balance nobody funded and nobody approved. FR-042
 * and Principle IV exist to prevent exactly that class of silent financial effect. The remedy is an
 * administrator's deliberate act — restore the account, or charge the intended one — which FR-015 and
 * FR-017 already cover with their own audit.
 *
 * ## Why the message names the account
 *
 * An approved-but-unsettleable expense is an acceptable, visible intermediate state. An expense booked
 * against a conjured-up balance is not. But a refusal that does not say *which* account is missing, or
 * *what* to do about it, leaves finance holding a transaction they can never settle with no way
 * forward — which is a refusal by status code and a dead end in practice. So the message is written for
 * the administrator who has to act, and it names the purchaser, the currency, and the action.
 */
final class UnsettleableAccount extends RuntimeException implements Refusal
{
    public function __construct(
        private readonly string $ownerUid,
        private readonly \OCA\PettyCash\Domain\Shared\CurrencyCode $currencyCode,
    ) {
        parent::__construct(sprintf(
            'This approval cannot be settled: the purchaser (%s) has no active petty-cash account in %s. '
            . 'An administrator must restore or activate that account, or charge a different active '
            . 'account, and then the approval can be retried. PettyCash does not create an account '
            . 'during settlement, because approving an expense must not fund the balance it is charged to.',
            $ownerUid,
            (string) $currencyCode,
        ));
    }

    public function errorCode(): string
    {
        return 'ACCOUNT_NOT_SETTLEABLE';
    }
}