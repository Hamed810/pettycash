<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Finance;

use InvalidArgumentException;
use OCA\PettyCash\Application\Port\UserDirectory;

/**
 * Feature 008 — the holder directory read (T006/T007).
 *
 * The petty-cash holder population is **exactly** the users who own at least one petty-cash account:
 * the grouped `pettycash_accounts.user_uid` set. This service reads that population and never
 * enumerates or searches the Nextcloud user directory. A user with no account can never enter the
 * population, and display-name resolution can neither add nor remove a holder.
 *
 * ## No-search pagination
 *
 * One grouped, server-side paged read plus an authoritative distinct-holder count. Display names are
 * resolved only for the holders on the returned page.
 *
 * ## Search (T007)
 *
 * A search term is matched case-insensitively as a substring against the holder uid **or** its
 * resolved display name. The candidate set is always the complete account-owning holder population,
 * resolved first and filtered before any pagination, so a match on a later page is found and an
 * account-less user can never appear. This is the approved V1 O(H) approach: H is the number of
 * account-owning holders, and display names are resolved only for those uids with a per-operation
 * cache.
 *
 * ## No persisted status
 *
 * `status` is derived per holder — `ACTIVE` (all accounts active), `INACTIVE` (none active), or
 * `MIXED` (otherwise) — and is never persisted.
 */
final class HolderDirectoryService
{
    public const STATUS_ACTIVE = 'ACTIVE';
    public const STATUS_INACTIVE = 'INACTIVE';
    public const STATUS_MIXED = 'MIXED';

    public const MAX_SEARCH_LENGTH = 64;

    public function __construct(
        private readonly FinanceRead $query,
        private readonly UserDirectory $userDirectory
    ) {}

    /**
     * A page of holders, optionally filtered by a display-name/uid search term.
     *
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, total: int}
     */
    public function page(?string $search, int $page = 1, int $pageSize = 25): array
    {
        if ($page < 1) {
            throw new InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('pageSize must be between 1 and 100');
        }

        $term = $this->normalizeSearch($search);

        // No search term: bounded server-side pagination with an authoritative distinct-holder count.
        if ($term === '') {
            $count = $this->query->holderCount();
            $pageRows = $this->query->holderPage(($page - 1) * $pageSize, $pageSize);
            $cache = [];
            $items = array_map(
                function (array $row) use (&$cache): array {
                    return $this->toHolder($row, $cache);
                },
                $pageRows
            );

            return ['items' => $items, 'page' => $page, 'pageSize' => $pageSize, 'total' => $count];
        }

        // Search term: match against the complete account-owning holder population, then paginate,
        // so the search is never page-local and never touches the unrestricted directory.
        $fullPopulation = $this->query->allHolders();
        $cache = [];
        $matched = [];
        foreach ($fullPopulation as $row) {
            $holder = $this->toHolder($row, $cache);
            if ($this->matches($holder, $term)) {
                $matched[] = $holder;
            }
        }

        $total = count($matched);

        return [
            'items' => array_slice($matched, ($page - 1) * $pageSize, $pageSize),
            'page' => $page,
            'pageSize' => $pageSize,
            'total' => $total,
        ];
    }

    /**
     * Trim and length-check a search term. Empty/whitespace input means "no filter".
     */
    private function normalizeSearch(?string $search): string
    {
        $trimmed = trim((string) $search);
        if (mb_strlen($trimmed) > self::MAX_SEARCH_LENGTH) {
            throw new InvalidArgumentException('search must be at most ' . self::MAX_SEARCH_LENGTH . ' characters');
        }

        return $trimmed;
    }

    /**
     * Build a holder summary from a grouped population row, resolving the display name only for this
     * account-owning uid. `$cache` is the per-operation display-name cache so a uid (which appears
     * once per grouped row) is never resolved twice during one request.
     *
     * @param array{userId: string, accountCount: int, activeAccountCount: int} $row
     * @param array<string, string> $cache
     * @return array{userId: string, displayName: string, accountCount: int, activeAccountCount: int, status: string}
     */
    private function toHolder(array $row, array &$cache): array
    {
        $userId = $row['userId'];
        $accountCount = $row['accountCount'];
        $activeAccountCount = $row['activeAccountCount'];

        return [
            'userId' => $userId,
            'displayName' => $this->displayName($userId, $cache),
            'accountCount' => $accountCount,
            'activeAccountCount' => $activeAccountCount,
            'status' => $this->deriveStatus($activeAccountCount, $accountCount),
        ];
    }

    /**
     * The display name for an account-owning uid, with a per-operation cache and a uid fallback.
     *
     * `UserDirectory::displayName()` already falls back to the uid when the platform has no usable
     * name, so this never changes the holder candidate population by adding or removing a row.
     *
     * @param array<string, string> $cache
     */
    private function displayName(string $userId, array &$cache): string
    {
        if (!isset($cache[$userId])) {
            $name = $this->userDirectory->displayName($userId);
            $cache[$userId] = $name !== '' ? $name : $userId;
        }

        return $cache[$userId];
    }

    /**
     * Derived holder status: all accounts active, none active, or a mixture.
     */
    private function deriveStatus(int $activeAccountCount, int $accountCount): string
    {
        if ($activeAccountCount <= 0) {
            return self::STATUS_INACTIVE;
        }
        if ($activeAccountCount >= $accountCount) {
            return self::STATUS_ACTIVE;
        }

        return self::STATUS_MIXED;
    }

    /**
     * Case-insensitive substring match against the holder uid or its resolved display name.
     *
     * @param array{userId: string, displayName: string} $holder
     */
    private function matches(array $holder, string $term): bool
    {
        $needle = mb_strtolower($term);
        if (mb_strpos(mb_strtolower($holder['userId']), $needle) !== false) {
            return true;
        }

        return mb_strpos(mb_strtolower($holder['displayName']), $needle) !== false;
    }
}
