<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Persistence\Command;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Application\Command\CommandResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

final class DbCommandResultRepository implements CommandResultRepository
{
    public function __construct(
        private readonly IDBConnection $connection,
        private readonly UserDirectory $userDirectory,
        private readonly CurrentUser $currentUser
    ) {}

    public function find(string $commandId): ?CommandResult
    {
        $qb = $this->connection->getQueryBuilder();
        $qb->select('*')
            ->from('pettycash_command_results')
            ->where($qb->expr()->eq('command_id', $qb->createNamedParameter($commandId)))
            ->setMaxResults(1);
        $row = $qb->executeQuery()->fetchAssociative();
        return $row ? $this->mapRow($row) : null;
    }

    public function save(CommandResult $result): void
    {
        $qb = $this->connection->getQueryBuilder();
        $existing = $this->find($result->commandId);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        
        if ($existing) {
            // Command results are immutable - should not be updated
            return;
        } else {
            $qb->insert('pettycash_command_results')
                ->values([
                    'command_id' => $qb->createNamedParameter($result->commandId),
                    'payload_hash' => $qb->createNamedParameter($result->payloadHash),
                    'response_json' => $qb->createNamedParameter(json_encode($result->response)),
                    'result_json' => $qb->createNamedParameter(json_encode($result->response)),
                    'actor_uid' => $qb->createNamedParameter($result->actorUid),
                    'operation' => $qb->createNamedParameter($result->operation),
                    'target_public_id' => $qb->createNamedParameter($result->targetPublicId),
                    'result_status' => $qb->createNamedParameter($result->resultStatus),
                    'created_at' => $qb->createNamedParameter($now, IQueryBuilder::PARAM_DATETIME_IMMUTABLE),
                ])
                ->executeStatement();
        }
    }

    private function mapRow(array $row): CommandResult
    {
        // T251: prefer `result_json`, falling back to the legacy `response_json`
        // column so pre-migration rows replay their stored response verbatim.
        $responseJson = $row['result_json'] ?? $row['response_json'] ?? null;
        return new CommandResult(
            $row['command_id'],
            $row['payload_hash'],
            $responseJson !== null ? (json_decode($responseJson, true) ?? []) : [],
            $row['actor_uid'] ?? null,
            $row['operation'] ?? null,
            $row['target_public_id'] ?? null,
            isset($row['result_status']) ? (int) $row['result_status'] : null,
        );
    }
}