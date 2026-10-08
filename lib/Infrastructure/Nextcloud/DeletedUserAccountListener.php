<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use OCA\PettyCash\Application\Account\AccountAdministrationService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * T391 / ADR-005: close the petty-cash accounts of a deleted Nextcloud user.
 *
 * @template-implements IEventListener<UserDeletedEvent>
 */
final class DeletedUserAccountListener implements IEventListener
{
    public function __construct(
        private AccountAdministrationService $accountAdministration,
        private LoggerInterface $logger
    ) {}

    /**
     * @param UserDeletedEvent $event
     *
     * ## Why this exists at all
     *
     * `pettycash_accounts.user_uid` is a plain `string(64)` with **no foreign key** to `oc_users`, and
     * `pettycash_sheets.owner_uid` likewise. An FK is deliberately *not* the fix: `roundtrip-runner.php`'s
     * probe owner and `RepositoryRoundTripTest`'s leak post-condition both depend on a uid naming no
     * Nextcloud user being a legal value. So integrity here is enforced at the event boundary, which is
     * also where the decision belongs.
     *
     * ## Why deactivating rather than deleting
     *
     * ADR-005, and Constitution IV behind it: "Historical actor identity and role-at-event MUST survive
     * account disablement or deletion." A balance with a movement ledger is financial history, so the row
     * stays and only `active` changes. Before this listener existed, a deleted user's account stayed
     * **active** with its balance, unreachable through every API yet counted by the administrator
     * dashboard's `totalAccounts`.
     *
     * ## Why the failure is swallowed
     *
     * A listener that throws aborts the surrounding event dispatch, and the thing being dispatched is a
     * **user deletion that has already happened**. Refusing to complete it, or rolling it back, would
     * mean the application decided a Nextcloud administrator could not remove an account — a far worse
     * outcome than a stale-but-inactive row that the next `delete` run closes.
     *
     * So the failure is logged with the uid and the event continues. The account is then deactivated by
     * nobody until the next run, which is the safe direction: an active orphaned account is worse than a
     * deactivated one, and both are recoverable, whereas a blocked deletion is not.
     *
     * Deliberately **not** calling `stopPropagation()`. What happens to a user's remaining data is
     * Nextcloud's decision across all its apps, and this application claiming to veto it would be a
     * far-reaching side effect of one account's deactivation.
     */
    public function handle(Event $event): void
    {
        if (!$event instanceof UserDeletedEvent) {
            return;
        }

        $uid = $event->getUid();

        try {
            $deactivated = $this->accountAdministration->deactivateAccountsOfDeletedUser($uid);
            $this->logger->info(
                'PettyCash closed the accounts of a deleted user',
                ['uid' => $uid, 'accountsDeactivated' => $deactivated]
            );
        } catch (\Throwable $e) {
            // See the docblock: the deletion has already been applied and must not be undone or refused.
            $this->logger->error(
                'PettyCash could not deactivate a deleted user\'s accounts; they remain active until a '
                    . 'later run succeeds',
                ['uid' => $uid, 'exception' => $e]
            );
        }
    }
}