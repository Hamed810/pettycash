<?php

declare(strict_types=1);

/**
 * Creates or removes a Nextcloud user holding **no** petty-cash account, and prints the credential.
 *
 * ## Why this exists (T390)
 *
 * FR-020's observable outcome — *"a purchaser with no accounts creates a first sheet, and `GET /accounts`
 * afterwards reports exactly one active `IRR` account"* — needs a caller who is **in the precondition**,
 * that is, holding zero accounts. T358 delivered the trigger and T388 proved the outcome against real
 * persistence, but nothing has observed it over HTTP, because the live OCS suite authenticates with a
 * single credential that holds accounts.
 *
 * ## Why a user has to be created rather than reused
 *
 * Measured on this instance before writing this file, rather than assumed:
 *
 * - five principals already hold **zero** petty-cash accounts — `pts-f004-e2e`, `pts-employee`,
 *   `pts-f004-visual`, `pts-f004-no-profile` and `hamed2` — so the precondition is already satisfied for
 *   all of them;
 * - **none of them can authenticate.** Each returns **401** from `/ocs/…/api/v1/me` with the contract
 *   password, while `employee1` returns **200** with it. Their credentials are not ours to use or reset.
 *
 * So the precondition is reachable but not *usable*, and the remaining way in is a principal we own. This
 * instance already carries five probe accounts from earlier E2E work, so a sixth named for this purpose is
 * consistent with what is there rather than a new kind of thing on it.
 *
 * ## Why this is safe to do repeatedly
 *
 * `create` is idempotent: an existing user of this name is **reused and re-passworded** rather than
 * duplicated, so a run that crashed before its `delete` leaves nothing to clean up by hand. `delete`
 * removes the principal and every petty-cash row it owns, which is why the contract test calls it in a
 * `finally` rather than leaving the user in place.
 *
 * ## What it is allowed to touch
 *
 * **One Nextcloud user account, named by its caller, and nothing else.** It writes no `config.php`, no
 * system value, no group membership and no other user's data. That is the same boundary
 * `user-timezone-fixture.php` states for itself.
 *
 * ## Usage (from the app root, inside the container)
 *
 *   php tests/Contract/Fixture/probe-user-fixture.php create <uid> <password>
 *   php tests/Contract/Fixture/probe-user-fixture.php delete <uid>   # product-faithful; rows survive
 *   php tests/Contract/Fixture/probe-user-fixture.php purge  <uid>    # test hygiene; rows removed
 *
 * The password is **not** defaulted. A fixture that generated one would print a credential that never
 * matched `.env.testing`, and the failure would be a 401 in an HTTP test — a long way from the cause.
 *
 * It must, however, satisfy Nextcloud's own `password_policy` app: at least 10 characters and not among
 * the million most common. A short literal like `abc123` is refused by `createUser()`, and the refusal
 * used to arrive as silence — see the exception handler below.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This fixture must be run from the command line.\n");
    exit(1);
}

/**
 * Turn any escaped failure into one reportable line, and never let the caller see silence.
 *
 * ## Why this exists
 *
 * Nextcloud's `password_policy` app rejects a weak password by throwing `HintException` from inside
 * `createUser()`. Uncaught, that is a fatal — and a fatal raised here reaches the caller as **no output
 * at all** through `shell_exec()` and through this repository's `scripts/dev/in-container` wrapper, whose
 * output this exact failure defeated for a long stretch of debugging.
 *
 * Silence is the worst shape a fixture failure can take, because every caller reads it as an empty
 * result rather than as an error. `create` prints a JSON manifest on success, so an empty string is
 * unambiguous *if* the caller checks — and both callers do — but the message that would have said
 * "password too short" was being thrown away.
 *
 * So: catch `Throwable`, print the exception class and message as the failure report, and exit non-zero.
 * The cause then sits in the same output the caller already reads.
 *
 * **Installed after `lib/base.php`, not before.** A first draft registered it first and it had no effect:
 * Nextcloud installs its own exception handler during bootstrap and *replaces* whatever was there. The
 * symptom was identical to having no handler at all, which is worth naming because a handler that
 * silently does not apply is indistinguishable from a handler that is not needed.
 */

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';

set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, sprintf(
        "probe-user-fixture failed: %s: %s\n",
        $e::class,
        $e->getMessage()
    ));
    exit(1);
});

/** @var \OCP\IUserManager $userManager */
$userManager = \OCP\Server::get(\OCP\IUserManager::class);

$action = $argv[1] ?? '';
$userUid = $argv[2] ?? '';

if ($userUid === '') {
    fwrite(STDERR, "Usage: probe-user-fixture.php create <uid> <password> | delete <uid>\n");
    exit(1);
}

/**
 * Whether the user already holds petty-cash accounts, so `create` can refuse rather than hand a caller a
 * principal that is not in FR-020's precondition.
 *
 * A precondition that is quietly false would make the whole test meaningless **and** green: the first
 * sheet would be created against an existing account, `GET /accounts` would report one account, and every
 * assertion would pass while provisioning was never involved.
 */
$pettyCashAccountCount = static function (string $uid): int {
    /** @var \OCP\IDBConnection $db */
    $db = \OCP\Server::get(\OCP\IDBConnection::class);
    $qb = $db->getQueryBuilder();
    $qb->select($qb->func()->count('id', 'total'))
        ->from('pettycash_accounts')
        ->where($qb->expr()->eq('user_uid', $qb->createNamedParameter($uid)));

    return (int) $qb->executeQuery()->fetchOne();
};

if ($action === 'create') {
    $password = $argv[3] ?? '';
    if ($password === '') {
        fwrite(STDERR, "create requires a password: probe-user-fixture.php create <uid> <password>\n");
        exit(1);
    }

    $existing = $userManager->get($userUid);

    if ($existing !== null && $pettyCashAccountCount($userUid) > 0) {
        fwrite(STDERR, sprintf(
            "%s already holds %d petty-cash account(s), so it is not in FR-020's precondition. "
            . "Delete it first, or choose another name.\n",
            $userUid,
            $pettyCashAccountCount($userUid)
        ));
        exit(1);
    }

    // Reused and re-passworded rather than duplicated, so a run that died before its `delete` needs no
    // manual repair. `setPassword` rather than `createUser` for the same reason: the account exists, only
    // its credential has to be known by this suite.
    if ($existing === null) {
        $created = $userManager->createUser($userUid, $password);
        if (!$created) {
            fwrite(STDERR, "Nextcloud refused to create {$userUid}.\n");
            exit(1);
        }
        $outcome = 'created';
    } else {
        $userManager->get($userUid)?->setPassword($password);
        $outcome = 'reused';
    }

    $accounts = $pettyCashAccountCount($userUid);
    if ($accounts !== 0) {
        fwrite(STDERR, sprintf(
            "%s holds %d petty-cash account(s) after create; FR-020's precondition is not met.\n",
            $userUid,
            $accounts
        ));
        exit(1);
    }

    echo json_encode([
        'userId' => $userUid,
        'password' => $password,
        'outcome' => $outcome,
        'pettyCashAccounts' => $accounts,
    ], JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

/**
 * Remove every petty-cash row this principal owns, in dependency order.
 *
 * ## Why this is needed at all
 *
 * Deleting a Nextcloud user leaves its petty-cash rows behind. Measured on this instance: after
 * `$user->delete()` the `oc_users` row was gone while `pettycash_accounts` and `pettycash_sheets` each
 * still held a row for the same uid.
 *
 * The cause is structural and was verified rather than guessed: the schema declares **no foreign key
 * constraints** — `SHOW CREATE TABLE` for both tables lists only `PRIMARY KEY`, a unique key on
 * `public_id` and a plain index — and no user-deletion listener exists in `lib/` or `appinfo/`.
 * Nothing was ever going to cascade, because nothing was declared to.
 *
 * ## Why the fixture cleans up rather than leaving it
 *
 * Without this, the *second* run of FR-020's test cannot reach its own precondition: the principal is
 * new but `create` refuses because the previous run's provisioned account is still there under the same
 * uid. That is a self-inflicted ordering trap on a shared instance, and the fix belongs to whoever owns
 * the data-retention question — **not** to a test fixture deciding that deleted users' financial records
 * should vanish. So this deletes only rows owned by the named probe, never a broader sweep.
 *
 * ## The retention question this surfaces, deliberately not answered here
 *
 * Whether orphaned petty-cash rows surviving their owner's deletion is correct is an open product
 * question (financial records may be required to outlive the account; the user's personal data may not).
 * It is recorded in `tasks.md` under T390 rather than decided here, because answering it would change
 * application behaviour on the strength of a test fixture's convenience.
 */
$purgeOwnedRows = static function (string $uid): array {
    /** @var \OCP\IDBConnection $db */
    $db = \OCP\Server::get(\OCP\IDBConnection::class);

    $idsIn = static function (string $table, string $column, string $uid) use ($db): array {
        $qb = $db->getQueryBuilder();
        $qb->select('id')->from($table)
            ->where($qb->expr()->eq($column, $qb->createNamedParameter($uid)));

        return array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    };

    $deleteByIds = static function (string $table, array $ids) use ($db): int {
        if ($ids === []) {
            return 0;
        }

        $qb = $db->getQueryBuilder();
        $qb->delete($table)->where($qb->expr()->in('id', $qb->createNamedParameter($ids, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    };

    $sheetIds = $idsIn('pettycash_sheets', 'owner_uid', $uid);
    $accountIds = $idsIn('pettycash_accounts', 'user_uid', $uid);

    // Children before parents: a sheet's transactions hang off it, and an account's movements do too.
    $transactionIds = [];
    if ($sheetIds !== []) {
        $qb = $db->getQueryBuilder();
        $qb->select('id')->from('pettycash_transactions')
            ->where($qb->expr()->in('sheet_id', $qb->createNamedParameter($sheetIds, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)));
        $transactionIds = array_map('intval', $qb->executeQuery()->fetchFirstColumn());
    }

    $removed = [];
    foreach ([
        'pettycash_transaction_revisions' => $transactionIds,
        'pettycash_workflow_decisions' => $transactionIds,
        'pettycash_evidence' => $transactionIds,
        'pettycash_transactions' => $transactionIds,
        'pettycash_sheets' => $sheetIds,
        'pettycash_account_movements' => $accountIds,
        'pettycash_accounts' => $accountIds,
    ] as $table => $ids) {
        $removed[$table] = $deleteByIds($table, $ids);
    }

    return $removed;
};

/**
 * Report what the deleted user's accounts look like **without** removing anything.
 *
 * T391 / ADR-005: a deleted user's account is deactivated, never deleted. `delete` must therefore be
 * able to show that state, which means it must not also purge it — so the two concerns are two actions,
 * `delete` and `purge`, rather than one action with a flag nobody reads.
 *
 * @return array<string, mixed>
 */
$describeOwnedAccounts = static function (string $uid): array {
    /** @var \OCA\PettyCash\Domain\Account\Repository\AccountRepository $accounts */
    $accounts = \OCP\Server::get(\OCA\PettyCash\Domain\Account\Repository\AccountRepository::class);
    $held = $accounts->findAllByUserUid($uid);

    return [
        // Two counts, because their difference is the whole finding: `held` includes the accounts the
        // deletion deactivated, `active` is what a caller could still spend from.
        'accountsHeld' => count($held),
        'accountsActive' => count($accounts->findActiveByUserUid($uid)),
        'accountIds' => array_map(static fn ($a): string => $a->id, $held),
        'balances' => array_map(static fn ($a): int => $a->balanceMinor, $held),
    ];
};

if ($action === 'delete') {
    // **Product-faithful.** Only `IUser::delete()` runs. The rows stay, deactivated by
    // `DeletedUserAccountListener`, which is exactly what FR-054 requires and what a test asserting
    // FR-054 needs to observe. A `delete` that also purged would make that test impossible to write.

    // **The app is loaded first, and this line is load-bearing.**
    //
    // Nextcloud loads applications lazily, so a bare CLI script that only does `require lib/base.php`
    // never runs `Application::register()` — and therefore never registers `DeletedUserAccountListener`.
    // `IUser::delete()` *does* dispatch `UserDeletedEvent` (verified: a listener added with
    // `IEventDispatcher::addListener()` fires on it), but a listener that was never registered cannot
    // receive it. The first version of this fixture omitted this line and the account came back
    // `active: true` on every run, which read as "the listener does not work" when the listener had
    // simply never been registered.
    //
    // A web request from the Nextcloud administration UI loads the app, so this line reproduces
    // production rather than distorting it.
    \OCP\Server::get(\OCP\App\IAppManager::class)->loadApp('pettycash');

    // **An authenticated session is set, and this line is load-bearing too.**
    //
    // `DbAccountRepository::save()` stamps `updated_by` from `NextcloudCurrentUser::uid()`, which throws
    // "Authenticated user required" with no session. The listener catches that, logs it, and leaves the
    // account active — so the fixture reported `accountsActive: 1` on every run and read as "the listener
    // does not work", when what had actually happened was that the *save* was refused.
    //
    // Setting an administrator also makes the run more faithful rather than less: a Nextcloud user is
    // removed by an administrator from a web request where a session exists.
    $sessionUser = $userManager->get('admin');
    if ($sessionUser !== null) {
        \OCP\Server::get(\OCP\IUserSession::class)->setUser($sessionUser);
    }

    $user = $userManager->get($userUid);

    if ($user === null) {
        // Already gone is the state `delete` is trying to reach, so it is a success rather than an error.
        // A fixture that failed here would turn a double-cleanup into a red suite.
        echo json_encode(
            ['userId' => $userUid, 'outcome' => 'absent'] + $describeOwnedAccounts($userUid),
            JSON_UNESCAPED_SLASHES
        ), "\n";

        exit(0);
    }

    // `IUser::delete()`, not a manager method: `IUserManager` in this Nextcloud version exposes no
    // `deleteUser()`. A first draft called one and the fixture printed nothing at all -- it fataled
    // before reaching its own `echo`, and `shell_exec()` swallows the fatal's stderr, so the caller saw
    // an empty string and a `delete` that had silently left the principal in place.
    $user->delete();
    $remaining = $userManager->get($userUid);

    echo json_encode([
        'userId' => $userUid,
        'outcome' => $remaining === null ? 'deleted' : 'still-present',
    ] + $describeOwnedAccounts($userUid), JSON_UNESCAPED_SLASHES), "\n";

    exit($remaining === null ? 0 : 1);
}

if ($action === 'purge') {
    // **Test hygiene, and deliberately not the product behaviour.** Removes the probe's petty-cash rows
    // so the *next* run of a suite starts from a zero-account precondition.
    //
    // This is not what ADR-005 decides a deleted user's data should do — that is retention, and `delete`
    // above is faithful to it. This exists because T390's provisioning test needs the precondition back,
    // and it is a separate, explicitly named action so the two can never be confused: a suite that calls
    // `delete` and then asserts the rows survived cannot have had them purged underneath it.
    $removed = $purgeOwnedRows($userUid);

    echo json_encode([
        'userId' => $userUid,
        'removedRows' => $removed,
    ], JSON_UNESCAPED_SLASHES), "\n";

    exit(0);
}

fwrite(STDERR, "Unknown action '{$action}'; expected create, delete or purge.\n");
exit(1);