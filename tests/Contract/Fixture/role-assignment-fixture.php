<?php

declare(strict_types=1);

/**
 * Grants or revokes petty-cash role assignments for a Nextcloud user, for
 * suites that must observe a role-scoped read over HTTP as someone other than
 * the shared credential.
 *
 * ## Why this exists (T314, T092)
 *
 * Report, dashboard and audit scope comes from `pettycash_role_assignments`
 * rows alone — the Nextcloud `admin` group is not consulted there, and no API
 * creates manager or administrator assignments. The shared contract credential
 * must never gain one (a run dying mid-suite would silently change what every
 * other suite's role-scope assertions observe), so assignments are provisioned
 * here for a dedicated probe and revoked afterwards.
 *
 * ## Why the removal is the guaranteed half
 *
 * A leaked assignment is worse than a leaked corrupt record: the probe fixture
 * reuses an existing user by name, so a leftover row would promote every
 * future incarnation of that principal. `grant` therefore repairs first (any
 * existing rows for the same user and role go before the new one is written),
 * and `revoke` verifies rather than assumes — it exits non-zero when anything
 * survived, so a failed removal fails the test that asked for it.
 *
 * Booleans are bound with an explicit `PARAM_BOOL`: an untyped `false` arrives
 * as `''`, which strict MariaDB rejects for the `active` column.
 *
 * Usage (inside the container):
 *   php tests/Contract/Fixture/role-assignment-fixture.php grant <nextcloud-user> <ROLE> [cost-center-public-id]
 *   php tests/Contract/Fixture/role-assignment-fixture.php revoke <nextcloud-user> [<ROLE>]
 *
 * Roles: ADMINISTRATOR, COST_CENTER_MANAGER, SENIOR_MANAGER, FINANCE. Manager
 * roles require the cost center; administrator and finance are unscoped.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This fixture must be run from the command line.\n");
    exit(1);
}

$nextcloudRoot = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
if (!is_file($nextcloudRoot . '/lib/base.php')) {
    fwrite(STDERR, "Cannot locate Nextcloud at {$nextcloudRoot}; set NEXTCLOUD_ROOT.\n");
    exit(1);
}

require_once $nextcloudRoot . '/lib/base.php';

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

const ASSIGNABLE_ROLES = ['ADMINISTRATOR', 'COST_CENTER_MANAGER', 'SENIOR_MANAGER', 'FINANCE'];
const SCOPED_ROLES = ['COST_CENTER_MANAGER', 'SENIOR_MANAGER'];

$action = $argv[1] ?? '';
$userUid = $argv[2] ?? '';
$role = strtoupper($argv[3] ?? '');
$costCenterPublicId = $argv[4] ?? '';

if (!in_array($action, ['grant', 'revoke'], true) || $userUid === '') {
    fwrite(STDERR, "Usage: role-assignment-fixture.php <grant|revoke> <nextcloud-user> [ROLE] [cost-center-public-id]\n");
    exit(1);
}

if (\OCP\Server::get(IUserManager::class)->get($userUid) === null) {
    fwrite(STDERR, "Nextcloud user '{$userUid}' does not exist.\n");
    exit(1);
}

$db = \OCP\Server::get(IDBConnection::class);

/** Remove petty-cash assignments for the user, optionally narrowed to one role. Returns how many went. */
function revokeAssignments(IDBConnection $db, string $userUid, ?string $role): int
{
    $select = $db->getQueryBuilder();
    $select->select('id')
        ->from('pettycash_role_assignments')
        ->where($select->expr()->eq('user_uid', $select->createNamedParameter($userUid)));
    if ($role !== null) {
        $select->andWhere($select->expr()->eq('role', $select->createNamedParameter($role)));
    }
    $ids = array_map('intval', $select->executeQuery()->fetchFirstColumn());

    $removed = 0;
    foreach ($ids as $id) {
        $delete = $db->getQueryBuilder()->delete('pettycash_role_assignments');
        $delete->where($delete->expr()->eq('id', $delete->createNamedParameter($id)))
            ->executeStatement();
        $removed++;
    }

    return $removed;
}

function countAssignments(IDBConnection $db, string $userUid, ?string $role): int
{
    $select = $db->getQueryBuilder();
    $select->select($select->func()->count('*', 'n'))
        ->from('pettycash_role_assignments')
        ->where($select->expr()->eq('user_uid', $select->createNamedParameter($userUid)));
    if ($role !== null) {
        $select->andWhere($select->expr()->eq('role', $select->createNamedParameter($role)));
    }

    return (int) $select->executeQuery()->fetchOne();
}

function randomUuid(): string
{
    $parts = str_split(bin2hex(random_bytes(16)), 4);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', $parts);
}

if ($action === 'grant') {
    if (!in_array($role, ASSIGNABLE_ROLES, true)) {
        fwrite(STDERR, 'Grant needs a role: ' . implode(', ', ASSIGNABLE_ROLES) . ".\n");
        exit(1);
    }

    $costCenterDbId = null;
    if (in_array($role, SCOPED_ROLES, true)) {
        if ($costCenterPublicId === '') {
            fwrite(STDERR, "{$role} is cost-center scoped: pass the cost center public id.\n");
            exit(1);
        }
        $select = $db->getQueryBuilder();
        $row = $select->select('id')
            ->from('pettycash_cost_centers')
            ->where($select->expr()->eq('public_id', $select->createNamedParameter($costCenterPublicId)))
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            fwrite(STDERR, "No cost center carries public id '{$costCenterPublicId}'.\n");
            exit(1);
        }
        $costCenterDbId = (int) $row['id'];
    }

    // Repair first: a previous run killed between grant and revoke leaves a row
    // that would make this grant a duplicate privilege rather than a fresh one.
    $repaired = revokeAssignments($db, $userUid, $role);

    $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $today = (new DateTimeImmutable('today', new DateTimeZone('UTC')))->format('Y-m-d');
    $publicId = randomUuid();

    $insert = $db->getQueryBuilder()->insert('pettycash_role_assignments');
    $insert->setValue('public_id', $insert->createNamedParameter($publicId));
    $insert->setValue('user_uid', $insert->createNamedParameter($userUid));
    $insert->setValue('role', $insert->createNamedParameter($role));
    $insert->setValue('cost_center_id', $insert->createNamedParameter($costCenterDbId));
    $insert->setValue('valid_from', $insert->createNamedParameter($today));
    $insert->setValue('valid_to', $insert->createNamedParameter(null));
    $insert->setValue('active', $insert->createNamedParameter(true, IQueryBuilder::PARAM_BOOL));
    $insert->setValue('created_at', $insert->createNamedParameter($now));
    $insert->setValue('created_by', $insert->createNamedParameter('fixture'));
    $insert->setValue('updated_at', $insert->createNamedParameter($now));
    $insert->setValue('updated_by', $insert->createNamedParameter('fixture'));
    $insert->executeStatement();

    echo json_encode(['assignmentId' => $publicId, 'repaired' => $repaired]) . "\n";
    exit(0);
}

// revoke: remove, then verify nothing survived.
$roleOrNull = ($role !== '' && in_array($role, ASSIGNABLE_ROLES, true)) ? $role : null;
$removed = revokeAssignments($db, $userUid, $roleOrNull);
$remaining = countAssignments($db, $userUid, $roleOrNull);
if ($remaining > 0) {
    echo json_encode(['error' => "revoke removed {$removed} rows but {$remaining} assignments survive for '{$userUid}'"]) . "\n";
    exit(1);
}

echo json_encode(['removed' => $removed]) . "\n";
exit(0);
