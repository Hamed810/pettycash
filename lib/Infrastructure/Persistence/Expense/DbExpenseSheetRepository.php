<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Expense;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Expense\ExpenseSheet;
use OCA\PettyCash\Domain\Expense\Repository\ExpenseSheetRepository;
use OCA\PettyCash\Domain\Workflow\SheetStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbExpenseSheetRepository implements ExpenseSheetRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function findById(int $id): ?ExpenseSheet
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function findByPublicId(string $publicId): ?ExpenseSheet
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    /** @return list<ExpenseSheet> */
    public function findByOwnerUid(string $ownerUid, ?SheetStatus $status = null, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('owner_uid', $qb->createNamedParameter($ownerUid)));
        if ($status) {
            $qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status->value)));
        }
        $qb->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    /** @return list<ExpenseSheet> */
    public function findByStatus(SheetStatus $status, int $offset = 0, int $limit = 100): array
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_sheets')
            ->where($qb->expr()->eq('status', $qb->createNamedParameter($status->value)))
            ->orderBy('created_at', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);
        $rows = $qb->executeQuery()->fetchAllAssociative();
        return array_map([$this, 'mapRow'], $rows);
    }

    public function save(ExpenseSheet $sheet): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $sheet->dbId ? $this->findById($sheet->dbId) : $this->findByPublicId($sheet->id);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $currentUser = $this->currentUser->uid();

        if ($existing) {
            $qb->update('pettycash_sheets')
                ->set('title', $qb->createNamedParameter($sheet->title ?? ''))
                ->set('owner_uid', $qb->createNamedParameter($sheet->ownerUid))
                ->set('account_id', $qb->createNamedParameter($this->getAccountDbId($sheet->accountId)))
                ->set('currency_id', $qb->createNamedParameter($this->getCurrencyDbId($sheet->currency)))
                ->set('status', $qb->createNamedParameter($sheet->status->value))
                ->set('submitted_total_minor', $sheet->submittedTotalMinor ? $qb->createNamedParameter($sheet->submittedTotalMinor) : $qb->createNamedParameter(null))
                ->set('first_submitted_at', $sheet->firstSubmittedAt ? $qb->createNamedParameter($sheet->firstSubmittedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('management_completed_at', $sheet->managementCompletedAt ? $qb->createNamedParameter($sheet->managementCompletedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('finance_started_at', $sheet->financeStartedAt ? $qb->createNamedParameter($sheet->financeStartedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('completed_at', $sheet->completedAt ? $qb->createNamedParameter($sheet->completedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null))
                ->set('lock_version', $qb->createNamedParameter($existing->lockVersion + 1))
                ->where($qb->expr()->eq('id', $qb->createNamedParameter($existing->dbId)))
                ->andWhere($qb->expr()->eq('lock_version', $qb->createNamedParameter($existing->lockVersion)))
                ->executeStatement();
            $sheet->lockVersion = $existing->lockVersion + 1;
        } else {
            $qb->insert('pettycash_sheets')
                ->values([
                    'public_id' => $qb->createNamedParameter($sheet->id),
                    'title' => $qb->createNamedParameter($sheet->title ?? ''),
                    'owner_uid' => $qb->createNamedParameter($sheet->ownerUid),
                    'account_id' => $qb->createNamedParameter($this->getAccountDbId($sheet->accountId)),
                    'currency_id' => $qb->createNamedParameter($this->getCurrencyDbId($sheet->currency)),
                    'status' => $qb->createNamedParameter($sheet->status->value),
                    'submitted_total_minor' => $sheet->submittedTotalMinor ? $qb->createNamedParameter($sheet->submittedTotalMinor) : $qb->createNamedParameter(null),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                    'first_submitted_at' => $sheet->firstSubmittedAt ? $qb->createNamedParameter($sheet->firstSubmittedAt->format('Y-m-d H:i:s')) : $qb->createNamedParameter(null),
                    'management_completed_at' => $qb->createNamedParameter(null),
                    'finance_started_at' => $qb->createNamedParameter(null),
                    'completed_at' => $qb->createNamedParameter(null),
                    'lock_version' => $qb->createNamedParameter(0),
                ])
                ->executeStatement();
            
            $sheet->dbId = $qb->getLastInsertId();
            $sheet->lockVersion = 0;
        }
    }

    private function getAccountDbId(string $publicId): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('public_id', $qb->createNamedParameter($publicId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    private function getCurrencyDbId(\OCA\PettyCash\Domain\Shared\CurrencyCode $currencyCode): int
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('id')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('code', $qb->createNamedParameter((string) $currencyCode)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? (int) $row['id'] : 0;
    }

    private function mapRow(array $row): ExpenseSheet
    {
        $currencyCode = \OCA\PettyCash\Domain\Shared\CurrencyCode::fromString(
            $this->getCurrencyCode($row['currency_id'])
        );
        $sheet = ExpenseSheet::open(
            $row['public_id'],
            $row['owner_uid'],
            $this->getAccountPublicId($row['account_id']),
            $currencyCode
        );
        $sheet->title = $row['title'] ?? '';
        $sheet->status = SheetStatus::from($row['status']);
        $sheet->submittedTotalMinor = $row['submitted_total_minor'] ? (int) $row['submitted_total_minor'] : null;
        $sheet->firstSubmittedAt = $row['first_submitted_at'] ? new DateTimeImmutable($row['first_submitted_at']) : null;
        $sheet->managementCompletedAt = $row['management_completed_at'] ? new DateTimeImmutable($row['management_completed_at']) : null;
        $sheet->financeStartedAt = $row['finance_started_at'] ? new DateTimeImmutable($row['finance_started_at']) : null;
        $sheet->completedAt = $row['completed_at'] ? new DateTimeImmutable($row['completed_at']) : null;
        $sheet->createdAt = $row['created_at'] ? new DateTimeImmutable($row['created_at']) : null;
        $sheet->lockVersion = (int) ($row['lock_version'] ?? 0);
        $sheet->dbId = (int) $row['id'];
        $sheet->publicId = $row['public_id'];
        return $sheet;
    }

    private function getCurrencyCode(int $currencyId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('code')
            ->from('pettycash_currencies')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($currencyId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $row['code'] : 'IRR';
    }

    private function getAccountPublicId(int $accountId): string
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('public_id')
            ->from('pettycash_accounts')
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $row['public_id'] : '';
    }
}