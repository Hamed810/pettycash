<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Evidence;

use DateTimeImmutable;
use DateTimeZone;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Command\CommandResultRepository;
use OCA\PettyCash\Application\Command\IdempotencyService;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\AuditWriter;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Domain\Evidence\EvidenceLink;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceLinkRepository;
use OCA\PettyCash\Domain\Evidence\Repository\EvidenceRepository;
use OCA\PettyCash\Domain\Shared\Clock;
use OCA\PettyCash\Domain\Shared\Uuid;
use RuntimeException;

/**
 * FR-031: creating and revoking a public evidence link — the **producer** half of the public-link
 * feature, and the half that had no implementation at all.
 *
 * ## Why this exists separately from consumption
 *
 * `PublicEvidenceLinkService` (T399) can open a link. Nothing could create one: `appinfo/routes.php`
 * declared `POST /evidence/{evidenceId}/links` and `POST /evidence-links/{linkId}/deactivate`, the
 * contract declared both, `RouteResolutionTest` named both as unresolved under T096, and no code
 * anywhere constructed an {@see EvidenceLink} or called `linkRepository->save()`. A consumer with no
 * producer is a feature that cannot be used, and it is worse than an absent one because the route exists
 * and answers `500` rather than `404`.
 *
 * ## The one real tension in this task, and how it is resolved
 *
 * FR-084 says every mutating operation must be replay-safe, so a client retry or double-click does not
 * duplicate the effect. FR-031 says the token is disclosed **once, at creation**. Those pull against
 * each other, because the obvious implementation — returning the token from the operation and letting
 * `IdempotencyService` store the response — **persists the token in `command_results`**, which is
 * exactly the disclosure FR-031 forbids, in a table nothing purges.
 *
 * So the stored result carries the link and **not** the token, and a replay answers with the same link
 * and `url: null`. That is not a workaround invented here: the contract already provides for it —
 * `EvidenceLink.url` is documented "Returned only at creation" and is nullable, which is precisely the
 * shape a replay needs. A retried creation therefore returns the link that already exists and does not
 * mint a second one, and it does not re-disclose the token.
 *
 * ## Who may create a link
 *
 * Administrators (FR-010/FR-012/FR-013 place evidence administration with administrators), but the
 * 2026-10-07 clarification adds the rule that shapes this class: **an administrator's own view right is
 * not required to create a link**, and a link on evidence the administrator cannot themselves view is
 * permitted **and audited as such**. So there is deliberately no `EvidenceVisibility::mayRead()` call
 * here. A check would make the capability useless for exactly the case the clarification names — an
 * administrator acting on evidence they are entitled to administer but not to open — and the audit
 * entry is what makes that use reconstructable rather than silent.
 *
 * ## TTL bounds
 *
 * FR-031: configurable, default 30 days, **maximum 1 year**. A longer request is **refused**, not
 * clamped: silently shortening a link an administrator asked to be longer would make the stored TTL
 * disagree with the one they were given, and an administrator who asked for a year and got 30 days has
 * no way to notice.
 */
final class EvidenceLinkService
{
    /** FR-031's default TTL when the request names none. */
    public const DEFAULT_TTL_DAYS = 30;

    /** FR-031's ceiling. A longer request is refused, never truncated. */
    public const MAX_TTL_DAYS = 365;

    /** 32 random bytes, hex-encoded. Inside the contract's 32..256 token bounds. */
    private const TOKEN_BYTES = 32;

    public function __construct(
        private readonly EvidenceLinkRepository $linkRepository,
        private readonly EvidenceRepository $evidenceRepository,
        private readonly AdministratorCheck $administrators,
        private readonly AuditWriter $auditWriter,
        private readonly IdempotencyService $idempotency,
        private readonly CommandResultRepository $commandResults,
        private readonly CurrentUser $currentUser,
        private readonly Clock $clock,
    ) {}

    /**
     * @param string $expiresAtIso an explicit expiry, or null for FR-031's default TTL
     * @return CreatedEvidenceLink $token is null on a replay — see the class docblock
     */
    public function create(string $evidencePublicId, ?string $expiresAtIso, ?string $commandId): CreatedEvidenceLink
    {
        $actorUid = $this->currentUser->uid();

        if (!$this->administrators->isAdministrator($actorUid)) {
            throw EvidenceLinkRefusal::becauseCreationIsAdministratorsOnly();
        }

        $evidence = $this->evidenceRepository->findByPublicId($evidencePublicId);
        if ($evidence === null) {
            throw EvidenceLinkRefusal::becauseTheEvidenceDoesNotExist();
        }

        // FR-058 deactivates rather than deletes after submission, so a withdrawn receipt is an
        // ordinary state. A link to one could never be opened, and creating it would leave an
        // administrator holding a capability that always refuses.
        if (!$evidence->active) {
            throw EvidenceLinkRefusal::becauseTheEvidenceIsDeactivated($evidencePublicId);
        }

        $expiresAt = $this->resolveExpiry($expiresAtIso);

        // Read **before** the operation so a replay can be told apart from a first attempt. The stored
        // command result holds the link and deliberately not the token, so "did this command already
        // run" cannot be answered by inspecting the result.
        $isReplay = $commandId !== null && $this->commandResults->find($commandId) !== null;

        $token = self::generateToken();

        $result = $this->idempotency->execute(
            $commandId ?? ('link-create-' . Uuid::random()->__toString()),
            $actorUid,
            'evidence_link_create',
            $evidencePublicId,
            [
                'operation' => 'evidence_link_create',
                'evidence_id' => $evidencePublicId,
                // **The caller's `expiresAt` verbatim, not the resolved expiry.**
                //
                // This is T414's fix, and it is the second of the two branches FR-042's clarification
                // allows: a value the request **derives** rather than receives either has to be
                // deterministic across attempts, or must not participate in the content comparison at all.
                //
                // Hashing the *resolved* expiry was the original mistake, and an intermediate fix was
                // wrong in an instructive way: truncating `now + 30 days` to whole seconds still yields
                // a different value whenever the retry crosses a second boundary. It narrowed the window
                // without closing it — a retry 1.5 seconds later produced `…T00:00:02` where the first
                // produced `…T00:00:00`, so `CommandConflict` remained, correctly, because the content
                // still differed. Only truncating to a bucket large enough to swallow any plausible gap
                // would work, and that is a guess about retry timing rather than a rule.
                //
                // Hashing the caller's own input is that rule. It is what the caller actually asked for:
                // two attempts that both omit `expiresAt` asked for the same thing, and must be recognised
                // as the same command however long apart they arrive. The resolved expiry is a property of
                // the **first** attempt and is already recorded on the link itself, so nothing is lost —
                // and the replay branch still returns that stored link rather than re-deriving anything.
                //
                // Normalised for the empty string, because `''`, `null` and `'  '` all mean "use the
                // default" and a caller sending two of them is retrying, not changing its mind.
                'expires_at' => ($expiresAtIso === null || trim($expiresAtIso) === '')
                    ? null
                    : trim($expiresAtIso),
            ],
            function () use ($actorUid, $evidence, $expiresAt, $token, $commandId): array {
                $link = new EvidenceLink(
                    Uuid::random()->__toString(),
                    (int) $evidence->dbId,
                    // The single definition of the digest. Re-deriving `hash('sha256', $token)` here
                    // would produce links that are created successfully and can never be consumed, and
                    // the refusal for that is indistinguishable from "this link never existed".
                    PublicEvidenceLinkService::hashToken($token),
                    true,
                    $expiresAt,
                    $actorUid,
                    $this->clock->now(),
                );

                $this->linkRepository->save($link);

                // FR-031: creation is administered configuration, so it is audited in the configuration
                // stream like every other configuration change. **Not** a transaction event: the access
                // event uses the same stream, and FR-056 requires the two to stay separable.
                //
                // Deliberately *not* `AccountFunded`/`AccountCharged`, and therefore not counted by
                // `ChargedAccountScope`: FR-055 says only funding and charging events count toward an
                // officer's charged-account set, and a link is neither.
                $this->auditWriter->appendConfiguration([
                    'id' => Uuid::random()->__toString(),
                    'entity_type' => PublicEvidenceLinkService::SUBJECT,
                    'entity_id' => $link->publicId ?? $link->id,
                    'event_type' => ConfigurationEventType::EvidenceLinkCreated->value,
                    'actor_uid' => $actorUid,
                    'actor_role' => 'ADMINISTRATOR',
                    'explanation' => null,
                    'after' => [
                        'link_id' => $link->id,
                        'evidence_id' => $evidence->id,
                        'expires_at' => $expiresAt?->format(DATE_ATOM),
                        // Recorded because the clarification requires an administrator's use of the
                        // capability to be reconstructable: this is where a link on evidence the
                        // administrator cannot themselves view becomes visible after the fact.
                        'administrator_may_view_evidence' => false,
                    ],
                    'command_id' => $commandId,
                ]);

                // The token is deliberately absent from this array. It is the whole authority on the
                // public path, and `command_results` is not a place it may rest.
                return [
                    'link_id' => $link->id,
                    'evidence_id' => $evidence->id,
                    'expires_at' => $expiresAt?->format(DATE_ATOM),
                    'created_at' => ($link->createdAt ?? $this->clock->now())->format(DATE_ATOM),
                ];
            },
            201
        );

        return new CreatedEvidenceLink(
            [
                'id' => (string) $result['link_id'],
                'evidenceId' => $evidencePublicId,
                'active' => true,
                'expiresAt' => $result['expires_at'],
                'createdAt' => $result['created_at'],
            ],
            // Null on a replay. The contract's `url` is nullable and documented "returned only at
            // creation", which is exactly this shape.
            $isReplay ? null : $token,
        );
    }

    /**
     * Revoke a link.
     *
     * Revocation is one of the **two controls FR-031 leaves** once the token is the whole authority —
     * TTL being the other — so this operation is a named control rather than a convenience.
     *
     * Deactivating an already-deactivated link is **not** an error. The client's intent is satisfied,
     * and a retry after a lost response must not fail; the audit records that it was already inactive
     * so a second attempt is still visible.
     *
     * @return array{link: array<string,mixed>, already_inactive: bool}
     */
    public function deactivate(string $linkPublicId, ?string $commandId): array
    {
        $actorUid = $this->currentUser->uid();

        if (!$this->administrators->isAdministrator($actorUid)) {
            throw EvidenceLinkRefusal::becauseDeactivationIsAdministratorsOnly();
        }

        $link = $this->linkRepository->findByPublicId($linkPublicId);
        if ($link === null) {
            throw EvidenceLinkRefusal::becauseTheLinkDoesNotExist();
        }

        $alreadyInactive = !$link->active;

        $result = $this->idempotency->execute(
            $commandId ?? ('link-deactivate-' . Uuid::random()->__toString()),
            $actorUid,
            'evidence_link_deactivate',
            $linkPublicId,
            [
                'operation' => 'evidence_link_deactivate',
                'link_id' => $linkPublicId,
            ],
            function () use ($link, $linkPublicId, $actorUid, $commandId): array {
                if ($link->active) {
                    $link->deactivate($actorUid);
                    $this->linkRepository->save($link);
                }

                $this->auditWriter->appendConfiguration([
                    'id' => Uuid::random()->__toString(),
                    'entity_type' => PublicEvidenceLinkService::SUBJECT,
                    'entity_id' => $linkPublicId,
                    'event_type' => ConfigurationEventType::EvidenceLinkDeactivated->value,
                    'actor_uid' => $actorUid,
                    'actor_role' => 'ADMINISTRATOR',
                    'explanation' => null,
                    'before' => ['active' => $link->active],
                    'after' => [
                        'link_id' => $linkPublicId,
                        'active' => false,
                        'deactivated_by' => $actorUid,
                    ],
                    'command_id' => $commandId,
                ]);

                return ['link_id' => $linkPublicId, 'active' => false];
            },
            200
        );

        return [
            'link' => [
                'id' => (string) $result['link_id'],
                'active' => false,
                'expiresAt' => $link->expiresAt?->format(DATE_ATOM),
                'createdAt' => ($link->createdAt ?? $this->clock->now())->format(DATE_ATOM),
            ],
            'already_inactive' => $alreadyInactive,
        ];
    }

    /**
     * FR-031's TTL rules: default 30 days, maximum 1 year, refused rather than clamped.
     *
     * @throws EvidenceLinkRefusal when the requested expiry is unparseable, in the past, or beyond the
     *                             ceiling
     */
    private function resolveExpiry(?string $expiresAtIso): ?DateTimeImmutable
    {
        $now = $this->clock->now();

        if ($expiresAtIso === null || trim($expiresAtIso) === '') {
            // **Truncated to whole seconds before the default TTL is added.**
            //
            // This is **defence in depth, not the fix.** `create()` hashes the caller's `expiresAt`
            // verbatim rather than this resolved value, so a retry cannot disagree with the first attempt
            // even when the two land in different seconds -- the defect T414 recorded. Kept anyway because
            // the stored column is `Y-m-d H:i:s`: a sub-second value written here would be truncated by
            // the database and then re-derived differently on read.
            $second = $now->setTime(
                (int) $now->format('H'),
                (int) $now->format('i'),
                (int) $now->format('s')
            );

            return $second->modify('+' . self::DEFAULT_TTL_DAYS . ' days');
        }

        try {
            $requested = new DateTimeImmutable(trim($expiresAtIso), new DateTimeZone('UTC'));
        } catch (\Exception) {
            // **`\Exception`, not `RuntimeException` — and that is the whole point of this catch clause.**
            //
            // PHP 8.3 moved malformed date strings to `DateMalformedStringException`, which extends
            // `DateException extends Exception` and **not** `RuntimeException`. A `catch
            // (RuntimeException)` therefore compiles, passes the happy path, and lets an unparseable
            // `expiresAt` escape as a 500 — the exact failure T398 was raised for on the settlement
            // path, reproduced here on a different one. `\Exception` covers both the pre-8.3 `Exception`
            // and the post-8.3 `DateMalformedStringException`, so the composer floor of 8.2 and the
            // 8.5 runtime both behave.
            throw EvidenceLinkRefusal::becauseTheExpiryIsNotADate('expiresAt');
        }

        if ($requested <= $now) {
            throw EvidenceLinkRefusal::becauseTheExpiryIsInThePast();
        }

        if ($requested > $now->modify('+' . self::MAX_TTL_DAYS . ' days')) {
            throw EvidenceLinkRefusal::becauseTheExpiryExceedsTheCeiling(
                $requested->format(DATE_ATOM),
                $now->modify('+' . self::MAX_TTL_DAYS . ' days')->format(DATE_ATOM),
            );
        }

        return $requested;
    }

    /**
     * A fresh unguessable token.
     *
     * `random_bytes` — the CSPRNG — rather than `uniqid()`, `mt_rand()` or a UUID: this value is the
     * entire authority on the public path, so a predictable generator would make every link guessable
     * no matter how well the storage and audit handle it. Hex encoding keeps it URL-safe with no
     * escaping, and 64 characters sits inside the contract's 32..256 bounds.
     */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }
}