<?php

declare(strict_types=1);

namespace OCA\PettyCash\Controller;

use OCA\PettyCash\Infrastructure\Http\CommandId;
use OCA\PettyCash\Application\Account\AccountAlreadyExists;
use OCA\PettyCash\Application\Account\AccountService;
use OCA\PettyCash\Application\Account\UnknownAccount;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Port\CurrentUser;
use OCA\PettyCash\Infrastructure\Http\OcsErrorMapper;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;

class AccountController extends OCSController
{
    public function __construct(
        \OCP\IRequest $request,
        private AccountService $accountService,
        private CurrentUser $currentUser,
        private OcsErrorMapper $ocsErrorMapper
    ) {
        parent::__construct('pettycash', $request);
    }

    /**
     * The authenticated caller's own petty-cash accounts.
     *
     * A read-only projection of the caller's own records, so no identifier is accepted from the
     * request: there is no way to ask for another user's account. A `userId` naming somebody else
     * is refused rather than ignored, so the endpoint cannot be used to probe for other users. An
     * empty page is returned as success, because having no account is a normal state the purchaser
     * UI has to render.
     */
    #[NoAdminRequired]
    public function index(?string $userId = null, int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            if ($userId !== null && trim($userId) !== '' && $userId !== $uid) {
                return $this->ocsErrorMapper->forbidden('Accounts may only be listed for the authenticated user');
            }

            return new DataResponse($this->accountService->listForUser($uid, $page, $pageSize));
        } catch (\InvalidArgumentException $e) {
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $this->request->getHeader('X-PettyCash-Command-ID'),
                ['page' => [$e->getMessage()]]
            );
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map($e, $this->request->getHeader('X-PettyCash-Command-ID'));
        }
    }

    /**
     * Open a petty-cash account.
     *
     * `userId` is the one identifier in this controller taken from a request rather than from the
     * trusted session, so the service refuses a `userId` that is neither the caller nor an
     * administrator (FR-013, FR-014). The answer is 201 with the contract's `Account` when created,
     * 200 with the same shape when an administrator re-opened a retired pair (re-opening is not a
     * second account); a pair that already has an active account in that currency is a 409, and an unsupported or inactive
     * currency a 422 — a refusal that must not be a bare 500, because the caller's next action
     * differs in each case.
     */
    #[NoAdminRequired]
    public function create(
        string $userId,
        string $currency,
        ?string $commandId = null
    ): DataResponse {
        $commandId ??= $this->request->getHeader('X-PettyCash-Command-ID');

        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $result = $this->accountService->createFor($uid, $userId, $currency);

            // 201 when created, 200 when an administrator re-opened a retired
            // pair: re-opening is not a second account, and answering 201 for
            // it would claim something came into existence that did not.
            return new DataResponse(
                $result['account'],
                $result['created'] ? Http::STATUS_CREATED : Http::STATUS_OK
            );
        } catch (\InvalidArgumentException $e) {
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $commandId,
                ['currency' => [$e->getMessage()]]
            );
        } catch (AccountAlreadyExists $e) {
            // A `Conflict`, so the mapper would answer this correctly through the `\Throwable` arm
            // below. It keeps an arm only so the *message* survives: `map()` is right to replace an
            // internal message with `pettycash.error.unexpected`, but a conflict here is a caller-
            // correctable fact, not a fault, and the sentence is the whole answer.
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

    /**
     * FR-015: fund an account, answering with the charge movement and the account it left behind.
     *
     * `expectedVersion` is required and is not optional-with-a-default. Funding is the one write two
     * finance officers make at the same moment, and a charge applied to a balance that has since
     * moved is a lost update: money that is in the ledger and not in the account. A missing version
     * is a 400 rather than a silent "whatever it is now".
     */
    #[NoAdminRequired]
    public function charge(
        string $accountId,
        int $amountMinor,
        string $description,
        string $occurredAt,
        int $expectedVersion,
        ?string $commandId = null
    ): DataResponse {
        $commandId ??= $this->request->getHeader('X-PettyCash-Command-ID');

        if ($commandId === null || trim($commandId) === '') {
            return $this->ocsErrorMapper->badRequest(
                'Required fields are missing',
                null,
                ['commandId' => ['commandId is required']]
            );
        }

        // T415: present but unstoreable. `pettycash_command_results.command_id` is
        // `string(36)` and the contract declares `format: uuid`, so an over-long
        // value reaches MySQL and answers 500. A malformed request is not a server
        // fault. **400, matching this method's own absent case** rather than 422:
        // both are the same class of fault, a request the caller must change.
        try {
            $commandId = CommandId::fromString((string) $commandId)->__toString();
        } catch (\InvalidArgumentException $malformed) {
            return $this->ocsErrorMapper->badRequest(
                'Malformed command ID',
                null,
                ['commandId' => [$malformed->getMessage()]]
            );
        }

        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            // The request's own shape is validated here, where the field names are still known, and
            // the service keeps its own checks as a defence. `DateTimeImmutable` throws a plain
            // `\Exception`, which no domain type distinguishes, so an unparseable date is turned into
            // a field error here rather than being allowed to reach the generic mapping.
            try {
                $moment = new \DateTimeImmutable($occurredAt);
            } catch (\Exception $e) {
                return $this->ocsErrorMapper->validation(
                    'occurredAt is not a valid date-time',
                    $commandId,
                    ['occurredAt' => ['occurredAt must be an ISO 8601 date-time']]
                );
            }

            $fieldErrors = [];
            if ($amountMinor < 1) {
                $fieldErrors['amountMinor'] = ['amountMinor must be at least 1'];
            }
            if (trim($description) === '') {
                $fieldErrors['description'] = ['description is required'];
            } elseif (strlen($description) > 1000) {
                $fieldErrors['description'] = ['description must be at most 1000 characters'];
            }
            if ($fieldErrors !== []) {
                return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
            }

            return new DataResponse(
                $this->accountService->charge(
                    $uid,
                    $accountId,
                    $amountMinor,
                    $description,
                    $moment,
                    $expectedVersion,
                    $commandId
                ),
                Http::STATUS_CREATED
            );
        } catch (UnknownAccount $e) {
            return $this->ocsErrorMapper->notFound($e->getMessage(), $commandId);
        } catch (\InvalidArgumentException $e) {
            // Checked before `\LogicException`, which is its parent: an invalid field is a 422 and a
            // refused actor is a 403, and the reverse of this order silently turns every validation
            // failure into a refusal.
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $commandId,
                ['amountMinor' => [$e->getMessage()]]
            );
        } catch (UnreadableRecordData $e) {
            // A ledger row that cannot be mapped (e.g. a consumption whose
            // source revision no longer exists) is a data fault, not an
            // authorisation refusal: without this arm the `LogicException`
            // arm below would answer it 403 forbidden (T029's defect class).
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $accountId]);
        } catch (\LogicException $e) {
            return $this->ocsErrorMapper->forbidden($e->getMessage(), $commandId);
        } catch (\Throwable $e) {
            // A `Conflict` — a stale version, a pair that already exists — reaches the mapper, which
            // answers 409 with the code the exception names. It used to need an arm of its own here,
            // which is how this method came to depend on the order of four catch clauses.
            return $this->ocsErrorMapper->map($e, $commandId);
        }
    }

    /**
     * FR-013: deactivate or reactivate a petty-cash account.
     *
     * Administrator-only: the service refuses anyone else, because a purchaser retiring their own
     * account would strand themselves with no sheet-creation path except through the administrator
     * they bypassed. The control is **absent** for a submitted sheet's account only in the sense that
     * no such rule exists — deactivation is about spendability, not about history, and past movements
     * stay readable either way.
     *
     * `active` and `expectedVersion` are required body fields, so a missing one is a 422 naming the
     * field — the same split T415 records for the neighbouring operations (a missing required *header*
     * would be a 400, but this operation takes none). An account already in the requested state
     * succeeds with its current shape and is not an error: the version was still checked, so a stale
     * caller is told to reload rather than handed a quiet success.
     */
    #[NoAdminRequired]
    public function update(
        string $accountId,
        ?bool $active = null,
        ?int $expectedVersion = null,
        ?string $explanation = null,
        ?string $commandId = null
    ): DataResponse {
        $commandId ??= $this->request->getHeader('X-PettyCash-Command-ID');

        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            $fieldErrors = [];
            if ($active === null) {
                $fieldErrors['active'] = ['active is required'];
            }
            if ($expectedVersion === null) {
                $fieldErrors['expectedVersion'] = ['expectedVersion is required'];
            }
            if ($fieldErrors !== []) {
                return $this->ocsErrorMapper->validation('Required fields are missing', $commandId, $fieldErrors);
            }

            return new DataResponse(
                $this->accountService->setActive($uid, $accountId, $active, $expectedVersion, $explanation)
            );
        } catch (UnknownAccount $e) {
            return $this->ocsErrorMapper->notFound($e->getMessage(), $commandId);
        } catch (\InvalidArgumentException $e) {
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $commandId,
                ['active' => [$e->getMessage()]]
            );
        } catch (\LogicException $e) {
            return $this->ocsErrorMapper->forbidden($e->getMessage(), $commandId);
        } catch (\Throwable $e) {
            // A stale version reaches the mapper, which answers 409 with the code the exception names —
            // the same arm `charge()` relies on, so no hand-rolled conflict branch is added here.
            return $this->ocsErrorMapper->map($e, $commandId, ['publicId' => $accountId]);
        }
    }

    /**
     * One account's ledger, as a `MovementPage`.
     *
     * The account id comes from the path, so it is the one identifier in this controller that is not
     * taken from the trusted session, and it is the reason ownership is checked in the service: a
     * ledger states what a named person was given and what they spent, so "not yours" and "does not
     * exist" are answered identically rather than distinguishable.
     *
     * An account with no movements is a success with an empty page. An account that has never been
     * used is a normal state, and reporting it as missing would be a false alarm about data the
     * application simply does not have yet.
     */
    #[NoAdminRequired]
    public function movements(string $accountId, int $page = 1, int $pageSize = 25): DataResponse
    {
        try {
            $uid = $this->currentUser->uid();
            if ($uid === '') {
                return $this->ocsErrorMapper->forbidden('No authenticated user in session');
            }

            return new DataResponse($this->accountService->listMovements($uid, $accountId, $page, $pageSize));
        } catch (\InvalidArgumentException $e) {
            return $this->ocsErrorMapper->validation(
                $e->getMessage(),
                $this->request->getHeader('X-PettyCash-Command-ID'),
                ['page' => [$e->getMessage()]]
            );
        } catch (UnreadableRecordData $e) {
            // Same data-fault guard as above: a ledger that cannot be mapped
            // must not read as a missing account.
            return $this->ocsErrorMapper->map(
                $e,
                $this->request->getHeader('X-PettyCash-Command-ID'),
                ['publicId' => $accountId]
            );
        } catch (\LogicException $e) {
            return $this->ocsErrorMapper->notFound(
                $e->getMessage(),
                $this->request->getHeader('X-PettyCash-Command-ID')
            );
        } catch (\Throwable $e) {
            return $this->ocsErrorMapper->map(
                $e,
                $this->request->getHeader('X-PettyCash-Command-ID'),
                ['publicId' => $accountId]
            );
        }
    }
}
