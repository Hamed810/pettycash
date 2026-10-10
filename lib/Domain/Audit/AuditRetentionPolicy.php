<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Audit;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * FR-057: a configurable audit retention policy with a hard-coded seven-year minimum floor.
 *
 * The floor is a constant, not a setting. A policy may extend how long history is kept or how it is
 * stored, and it may never shorten the floor, so `of()` refuses a shorter period instead of clamping
 * it — a caller that asked for three years has made a mistake that must be visible, not one that is
 * quietly corrected into something else.
 *
 * What the policy governs is *archival and availability*, never removal. `permitsDeletion()` is
 * therefore not configurable and answers false for every possible policy: Constitution IV makes
 * business audit non-deletable for every role including administrators, and FR-057's "policy may
 * govern archival or availability but MUST NOT rewrite business history" says the same thing from
 * the requirement side. A record that has aged past the window may be *moved*; it is never removed.
 *
 * Pure domain logic with no Nextcloud dependency, so the floor is directly testable.
 */
final readonly class AuditRetentionPolicy
{
    /**
     * FR-057's minimum, in years. Fixed by the requirement and not relaxable by any instance.
     */
    public const MINIMUM_RETENTION_YEARS = 7;

    private function __construct(private int $retentionYears) {}

    /**
     * @throws InvalidArgumentException when the requested period is shorter than the FR-057 floor.
     */
    public static function of(int $retentionYears): self
    {
        if ($retentionYears < self::MINIMUM_RETENTION_YEARS) {
            throw new InvalidArgumentException(sprintf(
                'Audit retention must preserve history for at least %d years; %d requested',
                self::MINIMUM_RETENTION_YEARS,
                $retentionYears
            ));
        }

        return new self($retentionYears);
    }

    /**
     * The V1 default: exactly the floor, used whenever nothing valid has been configured.
     *
     * This is the safe direction. An absent or unreadable setting yields the shortest period the
     * requirement permits, which still keeps every record for the full seven years.
     */
    public static function default(): self
    {
        return new self(self::MINIMUM_RETENTION_YEARS);
    }

    public function retentionYears(): int
    {
        return $this->retentionYears;
    }

    public function honorsMinimum(): bool
    {
        return $this->retentionYears >= self::MINIMUM_RETENTION_YEARS;
    }

    /**
     * The instant from which the record may stop being served from primary storage.
     *
     * Before this instant the record must remain fully available. The row and the bytes both survive
     * past it; only their storage location is a policy question.
     */
    public function availableUntil(DateTimeImmutable $occurredAt): DateTimeImmutable
    {
        return $occurredAt->modify(sprintf('+%d years', $this->retentionYears));
    }

    /**
     * Whether the record has aged past the window and may be moved to cold storage.
     */
    public function isArchivable(DateTimeImmutable $occurredAt, DateTimeImmutable $now): bool
    {
        return $now >= $this->availableUntil($occurredAt);
    }

    /**
     * Whether the record is still inside the window and therefore must not be archived.
     *
     * The inverse of `isArchivable()` and the form the callers actually need, so that a retention
     * check reads as the protection it is rather than as the absence of a prohibition.
     */
    public function isProtected(DateTimeImmutable $occurredAt, DateTimeImmutable $now): bool
    {
        return !$this->isArchivable($occurredAt, $now);
    }

    /**
     * Always false. Present so that the prohibition is stated where the policy is defined instead of
     * being left to each caller's judgement.
     */
    public function permitsDeletion(): bool
    {
        return false;
    }
}
