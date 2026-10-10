<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Application\Port\UserDirectory;
use OCA\PettyCash\Domain\Audit\AuditEvent;
use OCA\PettyCash\Domain\Audit\Repository\AuditEventRepository;

final class AuditWriterService implements AuditWriter
{
    public function __construct(
        private readonly AuditEventRepository $auditRepository,
        private readonly CurrentUser $currentUser,
        private readonly UserDirectory $userDirectory,
        private readonly AuditEventRepository $configurationAuditRepository
    ) {}

    /** @param array<string,mixed> $event */
    public function append(array $event): void
    {
        $this->auditRepository->save($this->toEvent($event));
    }

    /**
     * FR-055: configuration changes are audited in their own stream, addressed by
     * entity_type/entity_id. Settlement writes one of these naming the account it charges.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfiguration(array $event): void
    {
        if (($event['entity_type'] ?? '') === '') {
            throw new \InvalidArgumentException(
                'A configuration audit event must name its subject with entity_type.'
            );
        }
        $this->configurationAuditRepository->save($this->toEvent($event));
    }

    /**
     * FR-031 (clarified 2026-10-07): a configuration event whose viewer is **not** the signed-in user.
     *
     * ## Why this method exists rather than an `actor_uid` key on the payload
     *
     * `toEvent()` resolves the actor from the session, and `NextcloudCurrentUser::uid()` **throws** for
     * an unauthenticated request — which is exactly the case this records. There is no signed-in user to
     * read, so the viewer has to be supplied.
     *
     * It is a named method rather than a payload key because every other caller *already* passes
     * `actor_uid` in its payload and `toEvent()` deliberately ignores it. Making the override a key would
     * silently give every producer the ability to attribute an event to somebody other than the session,
     * which is the one property an audit trail must not have. A method that must be called by name keeps
     * that decision visible in review.
     *
     * FR-031 requires the viewer field to be `anonymous` and "never null or absent, because an anonymous
     * visit has no UID and a nullable field would make the audit entry unusable for the access
     * investigation it exists to serve" — so an empty viewer is refused rather than stored.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfigurationAsViewer(string $viewerUid, array $event): void
    {
        if (trim($viewerUid) === '') {
            throw new \InvalidArgumentException(
                'FR-031: an access recorded on behalf of a viewer must name the viewer. Use '
                . "'anonymous' for an unauthenticated request. A null or empty viewer makes the entry "
                . 'indistinguishable from a missing audit record during an access investigation.'
            );
        }
        if (($event['entity_type'] ?? '') === '') {
            throw new \InvalidArgumentException(
                'A configuration audit event must name its subject with entity_type.'
            );
        }

        $this->configurationAuditRepository->save($this->toEvent($event, $viewerUid));
    }

    /**
     * @param array<string,mixed> $event
     * @param string|null $viewerUid the actor to record instead of the session's, or null to read the
     *                              session. Only {@see self::appendConfigurationAsViewer()} passes it.
     */
    private function toEvent(array $event, ?string $viewerUid = null): AuditEvent
    {
        $this->rejectPreEncodedSnapshots($event);

        $actorUid = $viewerUid ?? $this->currentUser->uid();
        $actorName = $viewerUid === null ? $this->userDirectory->displayName($actorUid) : $viewerUid;
        $actorRole = $event['actor_role'] ?? 'PURCHASER';

        return new AuditEvent(
            $event['id'] ?? \OCA\PettyCash\Domain\Shared\Uuid::random()->__toString(),
            $event['sheet_id'] ?? null,
            $event['transaction_id'] ?? null,
            $event['revision_id'] ?? null,
            $event['stage'] ?? 'DRAFT',
            $event['decision_id'] ?? null,
            $event['entity_type'] ?? '',
            $event['entity_id'] ?? null,
            $this->requiredEventType($event),
            $actorUid,
            $actorName,
            $actorRole,
            $event['explanation'] ?? null,
            json_encode($event['before'] ?? []),
            json_encode($event['after'] ?? []),
            $event['command_id'] ?? null,
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }

    /**
     * The event type, and a refusal when it cannot be read.
     *
     * ## Why this refuses rather than defaulting to `UNKNOWN`
     *
     * `toEvent()` used to fall back to `'UNKNOWN'` for a missing `event_type`, which meant a payload
     * spelled any way the writer did not expect was still *stored* -- as an event nothing can query,
     * with `stage` defaulted to `DRAFT` and `entity_type` to `''`. And because `before` and `after`
     * would also be absent, `json_encode([])` stored the literal `"[]"` in both.
     *
     * That is not a cosmetic default. FR-052 requires every event to retain "applicable
     * before-and-after values", and FR-053 makes audit records append-only: a row written with
     * defaults is a row nobody can afterwards repair. `EvidenceService::deactivateEvidence()` builds
     * its event by casting a constructed `AuditEvent` to an array, which yields **camelCase** property
     * names, so not one of the snake_case keys read here is present and every field is silently
     * defaulted. The first evidence deactivation would have written such a row, and the transaction
     * audit table is append-only forever after.
     *
     * Refusing at the boundary is the only moment the mistake can be caught, and it doubles as a guard
     * against the next caller making it -- which is the whole reason to prefer a refusal over a default
     * that at least lets the write succeed.
     *
     * **Only `event_type` is required, and the scope is deliberate.** `appendConfiguration()` already
     * refuses an event with no `entity_type`, because FR-055 addresses configuration events by their
     * subject and one without it is unaddressable. The transaction stream does not need the same rule:
     * an event with a type and no subject is still readable and still queryable, and
     * `AuditWriterSurfaceTest` writes exactly such an event deliberately, to prove the two streams do
     * not cross. Requiring it there would have broken a legitimate event to fix nothing.
     */
    private function requiredEventType(array $event): string
    {
        $value = $event['event_type'] ?? '';
        if (!is_string($value) || trim($value) === '') {
            throw new \InvalidArgumentException(
                'An audit event must carry a non-empty `event_type`. This payload does not, so writing '
                . 'it would store an event nothing can query -- as `UNKNOWN`, with `stage` defaulted to '
                . 'DRAFT and its before/after values as `[]`. Audit records are append-only (FR-053), so '
                . 'such a row is one nobody can repair afterwards. The keys this writer reads are '
                . 'snake_case; a payload produced by casting an AuditEvent to an array is camelCase, and '
                . 'every field of it is then silently defaulted.'
            );
        }

        return $value;
    }

    /**
     * Refuses a payload that pre-encodes its snapshots under `before_json` / `after_json`.
     *
     * ## The second instance of the same defect
     *
     * This writer reads `before` and `after` and does the encoding itself. A caller that sends
     * `before_json` / `after_json` therefore has **no** snapshot key at all, and the `?? []` default
     * stores the literal `"[]"` in both columns -- silently, and with the caller's carefully built
     * payload discarded.
     *
     * Two real callers did exactly this, and they are the two `DECISION` paths: `ManagementReviewService`
     * and `FinanceSettlementService`. So every approval, rejection and return was being written with
     * empty before-and-after values, discarding the decision, the stage, the explanation, the revision
     * number and -- for finance -- the `financial_effect`. FR-052 requires an event to retain the
     * applicable before-and-after values, and FR-053 makes the row unrepairable afterwards.
     *
     * The three other callers (`SubmitSheet`, `ResubmitCorrection`, and the `ACCOUNT_CHARGED`
     * configuration event) already used the correct `before` / `after` keys, so the writer's vocabulary
     * was never the problem -- the vocabulary was simply undocumented, and nothing detected a caller
     * disagreeing with it.
     *
     * ## Why refuse instead of quietly ignoring
     *
     * Ignoring is what turned a one-word spelling mistake into permanent data loss. Refusing makes the
     * call fail loudly at the moment it is written, which is the only point at which it is cheap to fix.
     * The check is deliberately narrow -- only these two keys -- because a blanket "reject any key the
     * writer does not read" rule would also reject `actor_uid`, `actor_name` and `occurred_at`, which
     * callers legitimately pass and which the writer resolves from the session instead.
     */
    private function rejectPreEncodedSnapshots(array $event): void
    {
        $stray = array_values(array_intersect(['before_json', 'after_json'], array_keys($event)));
        if ($stray !== []) {
            throw new \InvalidArgumentException(sprintf(
                'This audit payload carries %s, which this writer does not read. It reads `before` and '
                . '`after` and encodes them itself, so a payload spelled this way is stored with both '
                . 'snapshots as the literal "[]" and the values it carries are discarded permanently '
                . '(audit records are append-only, FR-053). Pass the values as arrays under `before` '
                . 'and `after`.',
                implode('` and `', $stray)
            ));
        }
    }
}
