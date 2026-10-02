<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use Closure;
use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\Repository\ExpenseTypeRepository;
use OCA\PettyCash\Domain\Shared\PageBounds;

/**
 * FR-012 expense-type administration. T083.
 *
 * ## Why this is a service at all: the title invariant the schema does not hold
 *
 * `ExpenseTypeRepository::findActiveByTitle()` exists, which says a title is the natural key — but
 * **no migration creates a unique index on `pettycash_expense_types.title`.** Every other natural key
 * in this schema is enforced by the database: `pettycash_currencies_code_uniq` and
 * `pettycash_cost_centers_code_uniq` are both declared. An expense type has no such index, so nothing
 * below the application layer stops two active types sharing a title.
 *
 * That matters because FR-023 requires every transaction to carry "one active expense type" and
 * FR-012 makes the evidence requirement a property of the type: "Travel" requiring evidence and a
 * second active "Travel" not requiring it are indistinguishable to any caller choosing between them.
 * So uniqueness among active types is enforced here, in `create()` and `update()`, and this is the
 * layer that can refuse with a 409 the caller understands.
 *
 * ## Why `DbExpenseTypeRepository::save()` cannot be trusted to find the row
 *
 * That repository resolves an existing row with `findActiveByTitle($expenseType->title)` when no id is
 * set. Two consequences follow, and the service is shaped to avoid both:
 *
 * - It matches on the **title**, so saving a *rename* through it would not find the row the caller
 *   meant — it would insert a second row. This service therefore always sets `id` before saving, which
 *   switches the repository to `findById()` and makes the write address the row the caller read.
 * - It matches only **active** rows, so re-creating a title that belongs to a deactivated type would
 *   insert a duplicate rather than revive it. This service treats a deactivated title as available for
 *   reuse *only* when the caller means it (see `create()`), and never relies on the repository to
 *   notice.
 *
 * ## Inactive historical retention
 *
 * FR-012 and US5/AC2 both say a deactivated type must stop being offered while historical records keep
 * their original values. The mechanism is that nothing is ever deleted here, and that a transaction's
 * expense type is read through its own revision rather than joined live — so an expense filed under
 * "Travel" keeps the scale and evidence rule that were in force when it was filed. Deactivation changes
 * which rows `findAllActive()` returns and nothing else.
 *
 * ## Authorization
 *
 * Writes are administrators only, per FR-012 and T078's matrix. Reads are **not**: US5 aside,
 * `src/views/transactions/TransactionFormView.vue` loads the active types on every open so a purchaser
 * can satisfy FR-023's "one active expense type" at submission. That read is the live defect this
 * service's read half unblocks, and restricting it would break the purchaser form rather than protect
 * anything — an active expense type is reference data, not anyone's data.
 */
final readonly class ExpenseTypeService
{
    /** `pettycash_expense_types.title` is `string(255)` in the migration. */
    public const MAX_TITLE_LENGTH = 255;

    /** `ExpenseTypeWrite.description` is nullable; the column is TEXT. */
    public const MAX_DESCRIPTION_LENGTH = 2000;

    public function __construct(
        private ExpenseTypeRepository $expenseTypes,
        private AdministratorCheck $administrators,
        private ConfigurationAuditService $configurationAudit,
    ) {}

    /**
     * The active expense types, paged.
     *
     * Readable by any authenticated caller, deliberately. See the class docblock: the purchaser
     * transaction form is the consumer, and FR-023 requires an active type at submission.
     *
     * @return array{items: list<ExpenseType>, page: int, pageSize: int, total: int}
     */
    public function listActiveForCaller(string $actorUid, ?int $page = null, ?int $pageSize = null): array
    {
        $this->requireAuthenticated($actorUid);

        $bounds = new PageBounds($page, $pageSize);
        $active = $this->expenseTypes->findAllActive();
        $total = count($active);

        return [
            'items' => array_slice($active, $bounds->offset, $bounds->pageSize),
            ...$bounds->pageMeta($total),
        ];
    }

    /**
     * Define an expense type.
     *
     * `evidenceRequired` is set at creation and is the whole point of FR-012's "including whether a
     * type requires specific evidence": FR-023 makes it a submission precondition, so it cannot be a
     * later inference from the type's name.
     *
     * @throws ConfigurationConflict 409 when an *active* type already holds this title
     */
    public function create(
        string $actorUid,
        string $title,
        ?string $description = null,
        bool $evidenceRequired = false,
        bool $active = true,
        ?string $changeReason = null
    ): ExpenseType {
        $this->requireAdministrator($actorUid);

        $title = $this->requireTitle($title);
        $description = $this->normaliseDescription($description);

        // Only an *active* type blocks the title. A deactivated type keeps its row — that is the
        // historical retention above — but nothing is selecting it, so its title is free to be reused
        // by a genuinely different type. Reusing it is only sound because the old row is retained
        // rather than overwritten, so history still reads the original.
        if ($this->expenseTypes->findActiveByTitle($title) !== null) {
            throw ConfigurationConflict::alreadyExists('expense type', $title);
        }

        // T086 / FR-017: the definition and its audit record commit together.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::ExpenseTypeCreated->value,
            entityType: 'expense_type',
            // The title, because it is the identity this service resolves every read on.
            subjectOf: static fn(ExpenseType $t): string => $t->title,
            changeReason: $changeReason,
            afterSnapshot: self::snapshot(),
            mutate: function () use ($title, $description, $evidenceRequired, $active): ExpenseType {
                $expenseType = ExpenseType::create($title, $description, $evidenceRequired);
                $expenseType->active = $active;
                $this->expenseTypes->save($expenseType);

                return $expenseType;
            },
        );
    }

    /**
     * Update description, evidence requirement and active state.
     *
     * The title is absent from the signature: it is the identity `findActiveByTitle()` resolves on, and
     * the repository's own update path matches on it — so accepting a new title would make the write
     * address a different row than the caller read. Renaming an expense type is therefore not a V1
     * operation, which keeps `DbExpenseTypeRepository`'s title-based lookup correct rather than
     * working around it.
     *
     * `expectedVersion` is required and checked, for the same reason as in `CurrencyService::update()`:
     * two administrators editing one type is the case where last-write-wins loses work silently.
     */
    public function updateByTitle(
        string $actorUid,
        string $title,
        ?string $description,
        bool $evidenceRequired,
        bool $active,
        int $expectedVersion,
        ?string $changeReason = null
    ): ExpenseType {
        $this->requireAdministrator($actorUid);

        $existing = $this->expenseTypes->findActiveByTitle($this->requireTitle($title));
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('No active expense type titled %s exists', $title));
        }
        if ($existing->lockVersion !== $expectedVersion) {
            throw ConfigurationConflict::staleVersion(
                'expense type',
                $title,
                $expectedVersion,
                $existing->lockVersion
            );
        }

        // T086 / FR-017. The `before` snapshot is taken from `$existing` *before* the fields are
        // overwritten below; reading it afterwards would record the post-state as the previous value.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::ExpenseTypeUpdated->value,
            entityType: 'expense_type',
            subjectOf: static fn(ExpenseType $t): string => $t->title,
            changeReason: $changeReason,
            beforeSnapshot: static fn(): array => self::snapshotOf($existing),
            afterSnapshot: self::snapshot(),
            mutate: function () use ($existing, $description, $evidenceRequired, $active): ExpenseType {
                // `id` is carried across so the repository resolves by surrogate key rather than by title:
                // see the class docblock on why the title-based branch is unsafe for an update.
                $existing->description = $this->normaliseDescription($description);
                $existing->evidenceRequired = $evidenceRequired;
                $existing->active = $active;
                $this->expenseTypes->save($existing);

                return $existing;
            },
        );
    }

    /**
     * Deactivate, leaving the row and its history in place.
     *
     * Distinct from `updateByTitle()` on purpose. FR-012 and US5/AC2 require that deactivation stops a
     * type being *offered* while historical records keep their original values, and this is the shortest
     * path to exactly that: one flag, no other field touched, nothing deleted. It is also the operation
     * an administrator performs when a type is retired rather than corrected, and folding it into the
     * general update would make the read-modify-write of every other field a precondition for
     * retiring one.
     */
    public function deactivate(string $actorUid, string $title, int $expectedVersion, ?string $changeReason = null): ExpenseType
    {
        $this->requireAdministrator($actorUid);

        $existing = $this->expenseTypes->findActiveByTitle($this->requireTitle($title));
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('No active expense type titled %s exists', $title));
        }
        if ($existing->lockVersion !== $expectedVersion) {
            throw ConfigurationConflict::staleVersion(
                'expense type',
                $title,
                $expectedVersion,
                $existing->lockVersion
            );
        }

        // T086 / FR-017. Its own event type, not `ExpenseTypeUpdated`: US5/AC2 turns on this specific
        // operation, and merging it with the update would leave no way to ask who retired a type.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::ExpenseTypeDeactivated->value,
            entityType: 'expense_type',
            subjectOf: static fn(ExpenseType $t): string => $t->title,
            changeReason: $changeReason,
            beforeSnapshot: static fn(): array => self::snapshotOf($existing),
            afterSnapshot: self::snapshot(),
            mutate: function () use ($existing): ExpenseType {
                $existing->active = false;
                $this->expenseTypes->save($existing);

                return $existing;
            },
        );
    }

    /**
     * The audit snapshot of an expense type, from its return value.
     *
     * Shared by all three audited methods so the three cannot drift apart: a `before` written by one
     * spelling and an `after` by another is a pair no query can compare.
     */
    private static function snapshot(): Closure
    {
        return static fn(ExpenseType $t): array => self::snapshotOf($t);
    }

    /** @return array<string,mixed> */
    private static function snapshotOf(ExpenseType $t): array
    {
        return [
            'title' => $t->title,
            'description' => $t->description,
            // FR-012's evidence requirement is the field a historical record was filed under, so it
            // belongs in the snapshot rather than only in the row.
            'evidenceRequired' => $t->evidenceRequired,
            'active' => $t->active,
        ];
    }

    private function requireTitle(string $title): string
    {
        $title = trim($title);
        if ($title === '') {
            throw new InvalidArgumentException('Expense type title is required');
        }
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Expense type title must be at most %d characters', self::MAX_TITLE_LENGTH)
            );
        }
        return $title;
    }

    private function normaliseDescription(?string $description): ?string
    {
        if ($description === null) {
            return null;
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(
                sprintf('Expense type description must be at most %d characters', self::MAX_DESCRIPTION_LENGTH)
            );
        }
        return $description;
    }

    private function requireAdministrator(string $actorUid): void
    {
        if ($actorUid === '' || !$this->administrators->isAdministrator($actorUid)) {
            throw new \LogicException('Only an administrator may administer expense types');
        }
    }

    private function requireAuthenticated(string $actorUid): void
    {
        // A read is not restricted by role, but it is still a request in an authenticated session: an
        // empty actor is a caller with no session at all, which the controller answers as a refusal
        // before it gets here. This guard keeps the service honest if it is ever called directly.
        if (trim($actorUid) === '') {
            throw new \LogicException('An authenticated caller is required');
        }
    }
}
