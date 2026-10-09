<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Application\Configuration\ConfigurationConflict;
use OCA\PettyCash\Application\Configuration\ConfigurationPayloadFormatter;
use OCA\PettyCash\Application\Configuration\CostCenterService;
use OCA\PettyCash\Application\Configuration\CurrencyService;
use OCA\PettyCash\Application\Configuration\ExpenseTypeService;
use OCA\PettyCash\Application\Configuration\FinanceAssignmentService;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Infrastructure\Http\CommandId;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

/**
 * The eleven `configuration#*` endpoints `appinfo/routes.php` already registers. T087.
 *
 * ## Why this controller existed before it did
 *
 * `appinfo/routes.php:44-54` registered all eleven targets on 2026-09-25 while no
 * `ConfigurationController` existed, so every one of them resolved to nothing and answered **HTTP
 * 500**. That is not a cosmetic gap: `src/services/configurationApi.ts` has called
 * `GET /configuration/cost-centers` and `GET /configuration/expense-types` since 2026-09-26 and
 * `src/views/transactions/TransactionFormView.vue:150` loads both when the form opens, so the
 * purchaser could not select a cost centre or an expense type — and FR-023 makes both mandatory at
 * submission. The US1 journey was blocked on an unimplemented class.
 *
 * ## Thin adapter, as `plan.md`'s Controller layer requires
 *
 * Every action does the same four things and nothing else: resolve the authenticated uid, validate the
 * *request's own shape* while the field names are still known, delegate to a Phase-7 administration
 * service, and translate a failure through `OcsErrorMapper`. No business rule lives here. The services
 * keep their own checks as a defence, and the duplication is deliberate — `AccountController::charge()`
 * documents the same arrangement — because a controller that validates only what the service happens to
 * catch is not a boundary at all.
 *
 * ## `#[NoAdminRequired]` on every action, and why that is not a hole
 *
 * Nextcloud 34 requires the attribute explicitly; without it an OCS controller action is
 * administrator-only, and with it the action is open to any authenticated user. Neither state is the
 * authorization decision. FR-010/FR-012/FR-013 put configuration management with administrators, and
 * each service enforces that itself through `AdministratorCheck` — `CurrencyService` refuses a
 * non-administrator on reads as well as writes, deliberately, because the contract describes the
 * listing as "visible to the current **administrative** context". A 403 from the service is the
 * authorization answer; this attribute only says who may reach the endpoint at all.
 *
 * The one action that must **not** be open to everyone is the finance roster, because it enumerates
 * who can settle expenses. It is guarded by the service's own `requireAuthenticated()` and, for the
 * write, `requireAdministrator()` — see `financeAssignmentsIndex()`.
 *
 * ## Field validation here rather than in the service, and the order of the catch clauses
 *
 * `InvalidArgumentException` is caught **before** `LogicException`, which is its parent. Reversing
 * those two arms silently turns every validation failure into a 403 — the failure `AccountController`
 * documents having already had to correct once. `ConfigurationConflict` is a `RuntimeException` and is
 * reached by the final `\Throwable` arm, which answers 409 with the code it names; that is the one
 * path a stale version and a duplicate code both travel, and it is why a conflict needs no arm of its
 * own here.
 *
 * ## Every body field is nullable, and why that is not laxness
 *
 * Nextcloud's `Dispatcher` invokes a controller action with **named arguments** built from the request,
 * so a body field the caller omitted arrives as `null` — and a parameter declared `string $code` throws a
 * `TypeError` on `null` before a single line of this class runs. The measured result of omitting
 * `managerUserId` from `POST /configuration/cost-centers` with a non-nullable signature was
 * **HTTP 500 "Internal Server Error"**, not the 422 the contract declares for a malformed body.
 *
 * So every request-body parameter here is `?type = null` and presence is decided explicitly. This is
 * not a weakening: the required-field check below rejects exactly the same requests, one layer later
 * and with the field named, which is what a caller needs in order to fix the request. A `TypeError`
 * names a PHP type; a `fieldErrors` entry names the field.
 *
 * Path parameters stay non-nullable, because the router has already substituted them by the time the
 * action is called and there is nothing left to validate.
 *
 * ## Dates
 *
 * `CostCenterService` takes `DateTimeImmutable` and `FinanceAssignmentService` takes strings, because
 * the two were written against different contracts. `new \DateTimeImmutable()` throws a plain
 * `\Exception` that no domain type distinguishes, so an unparseable `validFrom` is turned into a field
 * error here rather than being allowed to reach the generic mapping and become a 500 with the message
 * withheld.
 */
class ConfigurationController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private CurrencyService $currencyService,
        private CostCenterService $costCenterService,
        private ExpenseTypeService $expenseTypeService,
        private FinanceAssignmentService $financeAssignmentService,
        private CurrentUser $currentUser,
        private AdministratorCheck $administrators,
        private OcsErrorMapper $ocsErrorMapper,
        private ConfigurationPayloadFormatter $payload
    ) {
        parent::__construct('pettycash', $request);
    }

    // --- currencies (T081) ---

    #[NoAdminRequired]
    public function currenciesIndex(int $page = 1, int $pageSize = 25): DataResponse
    {
        return $this->handle(function () use ($page, $pageSize): array {
            $uid = $this->requireUid();
            $paging = $this->paging($page, $pageSize);

            return [
                $this->payload->currencyPage($this->currencyService->listForCaller($uid, ...$paging)),
                Http::STATUS_OK,
            ];
        });
    }

    #[NoAdminRequired]
    public function currencyCreate(
        ?string $code = null,
        ?string $name = null,
        ?int $minorUnit = null,
        ?bool $active = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'code' => $this->missing($code),
            'name' => $this->missing($name),
            'active' => $active === null ? 'active is required' : '',
        ]);

        if ($minorUnit === null) {
            $fieldErrors['minorUnit'] = ['minorUnit is required'];
        } elseif ($minorUnit < 0 || $minorUnit > 4) {
            $fieldErrors['minorUnit'] = ['minorUnit must be between 0 and 4'];
        }

        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                $this->payload->currency($this->currencyService->create(
                    $this->requireUid(),
                    $code,
                    $name,
                    $minorUnit,
                    (bool)$active
                )),
                Http::STATUS_CREATED,
            ],
            $commandId,
            ['code' => [$code]]
        );
    }

    #[NoAdminRequired]
    public function currencyUpdate(
        string $currencyId,
        ?string $code = null,
        ?string $name = null,
        ?int $minorUnit = null,
        ?bool $active = null,
        ?int $expectedVersion = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'code' => $this->missing($code),
            'name' => $this->missing($name),
            'active' => $active === null ? 'active is required' : '',
            'expectedVersion' => $expectedVersion === null ? 'expectedVersion is required' : '',
        ]);

        if ($minorUnit < 0 || $minorUnit > 4) {
            $fieldErrors['minorUnit'] = ['minorUnit must be between 0 and 4'];
        }
        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                // `CurrencyId` in the contract is `{minLength: 3, maxLength: 3}` -- the code *is* the
                // identity (T081), so the path parameter is validated against it rather than ignored
                // in favour of the body's copy. When they disagree the path wins, because the path is
                // the resource the caller addressed; writing to the body instead would silently update
                // a different currency than the URL named.
                $this->payload->currency($this->currencyService->update(
                    $this->requireUid(),
                    $currencyId,
                    $name,
                    $minorUnit,
                    (bool)$active,
                    (int)$expectedVersion
                )),
                Http::STATUS_OK,
            ],
            $commandId,
            ['code' => [$code]]
        );
    }

    // --- cost centers (T082) ---

    #[NoAdminRequired]
    public function costCentersIndex(int $page = 1, int $pageSize = 25): DataResponse
    {
        return $this->handle(function () use ($page, $pageSize): array {
            $uid = $this->requireUid();
            $paging = $this->paging($page, $pageSize);
            $centers = $this->costCenterService;

            return [
                $this->payload->costCenterPage(
                    $centers->listValidOnForCaller($uid, null, ...$paging),
                    static fn ($costCenter): array => $centers->currentManagersOn($costCenter)
                ),
                Http::STATUS_OK,
            ];
        });
    }

    #[NoAdminRequired]
    public function costCenterCreate(
        ?string $code = null,
        ?string $name = null,
        ?string $validFrom = null,
        ?string $managerUserId = null,
        ?string $seniorManagerUserId = null,
        ?string $description = null,
        ?string $validTo = null,
        ?bool $active = null,
        ?string $changeReason = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'code' => $this->missing($code),
            'name' => $this->missing($name),
            'validFrom' => $this->missing($validFrom),
            'managerUserId' => $this->missing($managerUserId),
            'seniorManagerUserId' => $this->missing($seniorManagerUserId),
            'active' => $active === null ? 'active is required' : '',
        ]);

        $dates = $this->dates($validFrom, $validTo, $fieldErrors);
        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                $this->payload->costCenterFromCreate($this->costCenterService->create(
                    $this->requireUid(),
                    $code,
                    $name,
                    $description === '' ? null : $description,
                    $dates['validFrom'],
                    $dates['validTo'],
                    $managerUserId,
                    $seniorManagerUserId,
                    (bool)$active,
                    $changeReason
                )),
                Http::STATUS_CREATED,
            ],
            $commandId,
            ['code' => [$code]]
        );
    }

    #[NoAdminRequired]
    public function costCenterUpdate(
        string $costCenterId,
        ?string $code = null,
        ?string $name = null,
        ?string $validFrom = null,
        ?bool $active = null,
        ?int $expectedVersion = null,
        ?string $description = null,
        ?string $validTo = null,
        ?string $managerUserId = null,
        ?string $seniorManagerUserId = null,
        ?string $changeReason = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'code' => $this->missing($code),
            'name' => $this->missing($name),
            'validFrom' => $this->missing($validFrom),
            'active' => $active === null ? 'active is required' : '',
            'expectedVersion' => $expectedVersion === null ? 'expectedVersion is required' : '',
            'costCenterId' => $this->missing($costCenterId),
        ]);

        $dates = $this->dates($validFrom, $validTo, $fieldErrors);
        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(function () use (
            $code,
            $name,
            $dates,
            $active,
            $expectedVersion,
            $description,
            $managerUserId,
            $seniorManagerUserId,
            $changeReason,
            $costCenterId,
            $commandId
        ): array {
            $uid = $this->requireUid();
            $updated = $this->costCenterService->update(
                $uid,
                $costCenterId,
                (int)$expectedVersion,
                $code,
                $name,
                $description === '' ? null : $description,
                $dates['validFrom'],
                $dates['validTo'],
                (bool)$active,
                // `null` here means "leave the current holder alone", which is distinct from the
                // required fields: `CostCenterWrite` makes both manager fields required, so a caller
                // renaming a centre resends the holders it already has, and treating that as a
                // transfer would churn the assignment history FR-011 rests on. See the service.
                $managerUserId === '' ? null : $managerUserId,
                $seniorManagerUserId === '' ? null : $seniorManagerUserId,
                $changeReason
            );

            return [
                $this->payload->costCenter($updated, $this->costCenterService->currentManagersOn($updated)),
                Http::STATUS_OK,
            ];
        }, $commandId, ['code' => [$code]]);
    }

    // --- expense types (T083) ---

    #[NoAdminRequired]
    public function expenseTypesIndex(int $page = 1, int $pageSize = 25): DataResponse
    {
        return $this->handle(function () use ($page, $pageSize): array {
            $uid = $this->requireUid();
            $paging = $this->paging($page, $pageSize);

            return [
                $this->payload->expenseTypePage(
                    $this->expenseTypeService->listActiveForCaller($uid, ...$paging)
                ),
                Http::STATUS_OK,
            ];
        });
    }

    #[NoAdminRequired]
    public function expenseTypeCreate(
        ?string $title = null,
        ?bool $evidenceRequired = null,
        ?bool $active = null,
        ?string $description = null,
        ?string $changeReason = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'title' => $this->missing($title),
            'evidenceRequired' => $evidenceRequired === null ? 'evidenceRequired is required' : '',
            'active' => $active === null ? 'active is required' : '',
        ]);

        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                $this->payload->expenseType($this->expenseTypeService->create(
                    $this->requireUid(),
                    $title,
                    $description === '' ? null : $description,
                    (bool)$evidenceRequired,
                    (bool)$active,
                    $changeReason
                )),
                Http::STATUS_CREATED,
            ],
            $commandId,
            ['title' => [$title]]
        );
    }

    #[NoAdminRequired]
    public function expenseTypeUpdate(
        string $expenseTypeId,
        ?string $title = null,
        ?bool $evidenceRequired = null,
        ?bool $active = null,
        ?int $expectedVersion = null,
        ?string $description = null,
        ?string $changeReason = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'title' => $this->missing($title),
            'evidenceRequired' => $evidenceRequired === null ? 'evidenceRequired is required' : '',
            'active' => $active === null ? 'active is required' : '',
            'expectedVersion' => $expectedVersion === null ? 'expectedVersion is required' : '',
            'expenseTypeId' => $this->missing($expenseTypeId),
        ]);

        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                // The aggregate is keyed by title, and `ExpenseType` carries no id accessor, so the
                // path parameter is validated for presence and the body supplies the natural key. The
                // asymmetry with `costCenterUpdate` (which does resolve a public id) is T083's, not a
                // shortcut: `ExpenseTypeService::updateByTitle()` is the delivered method and T083
                // notes that a rename must not insert a second row. Recorded rather than papered over.
                $this->payload->expenseType($this->expenseTypeService->updateByTitle(
                    $this->requireUid(),
                    $title,
                    $description === '' ? null : $description,
                    (bool)$evidenceRequired,
                    (bool)$active,
                    (int)$expectedVersion,
                    $changeReason
                )),
                Http::STATUS_OK,
            ],
            $commandId,
            ['title' => [$title]]
        );
    }

    // --- finance assignments (T084) ---

    /**
     * The shared-queue finance roster.
     *
     * This is the one listing in the application that names **people who can settle expenses**, so the
     * actor has to be an administrator rather than merely authenticated — `T084`'s service enforces
     * `requireAuthenticated()` only, which is right for its own callers and not wide enough for an
     * enumeration. The check is here rather than added to the service because it is specific to this
     * endpoint: the roster is what a finance officer's own name would appear in, and the value of
     * hiding that is low while the value of the list is not.
     *
     * The response is a bare array, because the contract's response for this path is `type: array`
     * rather than the `PageMeta` envelope the other three listings use.
     */
    #[NoAdminRequired]
    public function financeAssignmentsIndex(): DataResponse
    {
        return $this->handle(function (): array {
            $uid = $this->requireUid();
            if (!$this->administrators->isAdministrator($uid)) {
                // Thrown rather than returned, so `handle()`'s `\LogicException` arm turns it into the
                // 403 the mapper defines. Returning a pre-built DataResponse from inside the delegate
                // would need a sentinel status to mean "already answered", which is the kind of signal
                // that is misread the first time someone adds a status of 0.
                throw new \LogicException('Only an administrator may list finance assignments');
            }

            return [
                $this->payload->roleAssignments($this->financeAssignmentService->listForCaller($uid)),
                Http::STATUS_OK,
            ];
        });
    }

    #[NoAdminRequired]
    public function financeAssignmentCreate(
        ?string $userId = null,
        ?string $validFrom = null,
        ?string $changeReason = null,
        ?string $validTo = null,
        ?bool $active = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId = $this->commandId($commandId);

        $fieldErrors = $this->fieldErrors([
            'userId' => $this->missing($userId),
            'validFrom' => $this->missing($validFrom),
            'changeReason' => $this->missing($changeReason),
            'active' => $active === null ? 'active is required' : '',
        ]);

        // The service parses these itself and names the field, but a `new \DateTimeImmutable()` failure
        // is a plain `\Exception` that would become a 500 with the message withheld. Validated here so
        // a typo in a date is reported as the typo it is.
        $this->dates($validFrom, $validTo, $fieldErrors);

        if ($fieldErrors !== []) {
            return $this->validation('Required fields are missing or invalid', $commandId, $fieldErrors);
        }

        return $this->handle(
            fn (): array => [
                $this->payload->roleAssignment($this->financeAssignmentService->create(
                    $this->requireUid(),
                    $userId,
                    $validFrom,
                    $changeReason,
                    $validTo === '' ? null : $validTo,
                    (bool)$active
                )),
                Http::STATUS_CREATED,
            ],
            $commandId,
            ['userId' => [$userId]]
        );
    }

    // --- shared plumbing ---

    /**
     * Run a delegate and turn any failure into the answer `OcsErrorMapper` defines for it.
     *
     * Eleven actions repeating the same four catch clauses is eleven places for the arm order to be
     * wrong, and the order is load-bearing — see the class docblock. Centralising it is also what lets
     * a later change to the error vocabulary happen in one file.
     *
     * @param callable(): array{0: mixed, 1: int} $delegate
     * @param array<string, list<string>> $validationField the field a validation failure is reported
     *        against when the service raises `InvalidArgumentException` and the controller has not
     *        already decided; the field names are only known at the call site
     */
    private function handle(callable $delegate, ?string $commandId = null, array $validationField = []): DataResponse
    {
        try {
            [$body, $status] = $delegate();

            return new DataResponse($body, $status);
        } catch (\InvalidArgumentException $e) {
            // Checked before `\LogicException`, which is its parent: an invalid field is a 422 and a
            // refused actor is a 403, and reversing the two arms turns every validation failure into a
            // refusal.
            return $this->validation($e->getMessage(), $commandId, $validationField !== [] ? $validationField : ['request' => [$e->getMessage()]]);
        } catch (ConfigurationConflict $e) {
            // Reachable through the mapper as well; kept so the sentence survives. `map()` is right to
            // replace an internal message with `pettycash.error.unexpected`, but a duplicate code or a
            // stale version is a caller-correctable fact and the sentence is the whole answer.
            return new DataResponse(
                ['error' => ['code' => $e->errorCode(), 'message' => $e->getMessage()]],
                Http::STATUS_CONFLICT
            );
        } catch (\LogicException $e) {
            return $this->ocsErrorMapper->forbidden($e->getMessage(), $commandId);
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    private function requireUid(): string
    {
        $uid = $this->currentUser->uid();
        if ($uid === '') {
            // Thrown, not returned: every caller is inside a `try`, and a `LogicException` becomes the
            // 403 the mapper defines rather than a 500.
            throw new \LogicException('No authenticated user in session');
        }

        return $uid;
    }

    private function commandId(?string $commandId): string
    {
        $raw = $commandId ?? (string)$this->request->getHeader('X-PettyCash-Command-ID');

        // T415: bounded so an over-long value cannot reach storage as an oversized key.
        //
        // One place, unlike the other controllers which each guard their own site — this controller
        // already funnels every command id through this helper.
        //
        // **Truncated rather than refused**, and the reason is measured: no configuration write reaches
        // `IdempotencyService` (`grep -rn "IdempotencyService" lib/Application/Configuration/` is empty),
        // so nothing here ever inserts into `pettycash_command_results` and an over-long id cannot become
        // the 500 T415 is about. The value is forwarded into audit rows, where a longer key is merely
        // recorded. Refusing a configuration write over the width of an audit key would trade a
        // non-existent bug for a real one: a caller unable to create a currency.
        if (strlen(trim($raw)) > CommandId::MAX_LENGTH) {
            return substr(trim($raw), 0, CommandId::MAX_LENGTH);
        }

        return $raw;
    }

    /**
     * The page bounds to pass on, with the contract's bounds enforced here.
     *
     * The services take `null` for "unpaged" and their own `PageBounds` clamps the rest, so this only
     * refuses what no bound could answer. A negative page is a 422 rather than a silently clamped one
     * because there is no nearest legal page to fall back to.
     *
     * @return array{0: int|null, 1: int|null}
     */
    private function paging(int $page, int $pageSize): array
    {
        if ($page < 1) {
            throw new \InvalidArgumentException('page must be 1 or greater');
        }
        if ($pageSize < 1 || $pageSize > 100) {
            throw new \InvalidArgumentException('pageSize must be between 1 and 100');
        }

        return [$page, $pageSize];
    }

    /**
     * Turn `field => message-or-empty` into a `fieldErrors` map, dropping the satisfied ones.
     *
     * Booleans and integers are checked with `=== null`, never with a truthiness test, because
     * `expectedVersion: 0` and `active: false` are both meaningful values — `0` is the version every
     * freshly created configuration row has, and `false` is how a resource is deactivated. A truthiness
     * check would report both as "missing" and refuse the exact requests that deactivate something.
     *
     * @param array<string, string> $checks field name => message, empty string when satisfied
     * @param array<string, list<string>> $into
     * @return array<string, list<string>>
     */
    private function fieldErrors(array $checks, array $into = []): array
    {
        foreach ($checks as $field => $message) {
            if ($message !== '') {
                $into[$field] = [$message];
            }
        }

        return $into;
    }

    /**
     * Parse the two dates a cost centre and a finance assignment share.
     *
     * `format: date`, so the calendar day is what matters; a malformed value is reported against the
     * field that carried it rather than becoming an unclassified 500.
     *
     * @param array<string, list<string>> $fieldErrors
     * @return array{validFrom: \DateTimeImmutable, validTo: \DateTimeImmutable|null}|null
     */
    private function dates(?string $validFrom, ?string $validTo, array &$fieldErrors): ?array
    {
        // Null is reported as "required", not as a malformed date. The two are different answers for
        // the caller -- one says "you did not send it", the other "you sent something I cannot read" --
        // and collapsing them would make the caller guess which mistake they made. `parseDate()`
        // therefore takes a nullable and distinguishes an absent value from an unparseable one.
        if ($validFrom === null || trim($validFrom) === '') {
            $fieldErrors['validFrom'] = ['validFrom is required'];

            return null;
        }

        $parsedFrom = $this->parseDate($validFrom);
        if ($parsedFrom === null) {
            $fieldErrors['validFrom'] = ['validFrom must be an ISO 8601 date'];

            return null;
        }

        $parsedTo = null;
        if ($validTo !== null && trim($validTo) !== '') {
            $parsedTo = $this->parseDate($validTo);
            if ($parsedTo === null) {
                $fieldErrors['validTo'] = ['validTo must be an ISO 8601 date'];

                return null;
            }
            if ($parsedTo < $parsedFrom) {
                // Refused here as well as in the service: `RoleAssignment::isValidOn()` is inclusive at
                // both ends, so a `validTo` before `validFrom` is an assignment that is never valid on
                // any date, which is a silent data defect rather than a reported one.
                $fieldErrors['validTo'] = ['validTo must not precede validFrom'];

                return null;
            }
        }

        return ['validFrom' => $parsedFrom, 'validTo' => $parsedTo];
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        // The shape is checked before parsing, not after. `new \DateTimeImmutable()` accepts relative
        // expressions ("now", "+1 day") and a great deal else, so parsing first would accept a validity
        // window a caller expressed in a form no artifact ever described -- and `\DateTimeImmutable` would
        // happily resolve it against the server clock, making a configuration row's effective dates
        // depend on when the request happened to arrive.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) !== 1) {
            return null;
        }

        try {
            return new \DateTimeImmutable(trim($value));
        } catch (\Exception) {
            // Well-shaped and still unparseable: `2026-02-31`, which the regex accepts and the calendar
            // does not have.
            return null;
        }
    }

    /**
     * The "is required" message for a string field, or an empty string when it was supplied.
     *
     * Trimmed before the comparison so a field of `" "` counts as absent. Every string in these eleven
     * payloads is a name, an identifier or an explanation, and none of them is meaningfully blank.
     */
    private function missing(?string $value): string
    {
        return $value === null || trim($value) === '' ? 'this field is required' : '';
    }

    private function validation(string $message, ?string $commandId, array $fieldErrors): DataResponse
    {
        return $this->ocsErrorMapper->validation($message, $commandId === '' ? null : $commandId, $fieldErrors);
    }
}