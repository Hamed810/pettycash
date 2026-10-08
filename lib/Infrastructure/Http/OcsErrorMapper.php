<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

use InvalidArgumentException;
use OCA\PettyCash\Application\Command\CommandConflict;
use OCA\PettyCash\Application\Conflict;
use OCA\PettyCash\Application\DataIntegrity\UnreadableRecordData;
use OCA\PettyCash\Application\Refusal;
use OCP\AppFramework\Http;
use OCA\PettyCash\Infrastructure\Nextcloud\TechnicalLogger;
use OCP\AppFramework\Http\DataResponse;
use Throwable;

/**
 * Centralised OCS error mapping.
 *
 * Every controller failure path funnels through this class so that the response
 * body always matches the `Error` schema in `contracts/ocs-api.yaml`
 * (`code`, `message`, optional `fieldErrors`, optional `commandId`) and so that
 * `ocs.meta.statuscode` matches the HTTP status. The payload is nested under an
 * `error` key, which is the shape the typed frontend client reads.
 */
final class OcsErrorMapper
{
    /**
     * The caller-facing text for an unreadable stored record, in one place so it cannot drift.
     *
     * `DataIntegrityReportTest` pins two phrases in it — that this is "a data fault" and that it is
     * "not a permission or filter problem" — because those are the two causes a caller would otherwise
     * go chasing. T029 was raised when a persistence fault was answered `403 Forbidden`, which sent an
     * entire investigation after permissions that were never the problem; a 422 whose text did not
     * name the fault would repeat that confusion under a friendlier status.
     *
     * **Deliberately names neither the endpoint nor the record.** FR-083 asks for "the same status
     * and the same class of explanation, so that a client writes one handler rather than one per
     * endpoint", which means the string cannot vary by endpoint — and the caller's own request is what
     * tells it which endpoint it called. The exception's own message names the surrogate ids of the
     * rows at fault, which is what an operator needs and what a caller must not be given; that goes to
     * {@see TechnicalLogger::unexpected()} instead.
     */
    private const DATA_FAULT_MESSAGE = 'This record set cannot be produced because one or more stored '
        . 'records are incomplete and cannot be read (a record references data that no longer exists, '
        . 'such as a currency). This is a data fault, not a permission or filter problem, so neither '
        . 'retrying nor narrowing the request will change the answer. An administrator can identify the '
        . 'affected records from the application log.';

    public function __construct(
        private readonly TechnicalLogger $logger
    ) {}

    public function map(Throwable $error, ?string $commandId = null): DataResponse
    {
        [$status, $code] = match (true) {
            $error instanceof CommandConflict => [Http::STATUS_CONFLICT, 'IDEMPOTENCY_CONFLICT'],
            // A conflict the application names itself, so the code comes from the exception rather
            // than from a table here.
            //
            // This arm is what lets a controller catch only `\Throwable` and still answer 409 for a
            // stale version or an existing pair. Without it, every such endpoint had to hand-roll a
            // catch arm — and hand-rolled arms are where T063's bug lived: `InvalidArgumentException`
            // extends `LogicException`, so one broad `catch (\Exception)` added for an unrelated case
            // silently swallowed every validation failure and answered 422 where the contract
            // requires a 409.
            $error instanceof Conflict => [Http::STATUS_CONFLICT, $error->errorCode()],
            // FR-040: a correct request the server is not in a state to serve.
            //
            // Added because the third answer had nowhere to go. It is neither a validation failure
            // (the request is right) nor a conflict (nothing has moved — there is nothing to reload),
            // and it used to be a bare `LogicException`, which this class deliberately does not arm,
            // so a finance approval that could not be settled because the purchaser's account had
            // been deactivated answered `500 UNEXPECTED_FAILURE` with its message withheld. The
            // message withheld part was the worst of it: the sentence naming the missing account is
            // the only actionable thing in the whole response, and it was being suppressed.
            //
            // Placed **before** the `UnreadableRecordData` arm deliberately. That type extends
            // `LogicException` for the same reason this one does — to keep itself out of the
            // `InvalidArgumentException` arm — and the two are disjoint types, so the order is not
            // load-bearing today. It is stated anyway, because the day one of them implements the
            // other's interface the silent arm-shadowing that T063 was raised for becomes possible
            // and nothing would report it.
            $error instanceof Refusal => [Http::STATUS_UNPROCESSABLE_ENTITY, $error->errorCode()],
            // FR-083, and the reason this arm is here rather than in four controllers.
            //
            // A stored record whose reference no longer resolves is a fault in the *data* — not in the
            // request, and not in the caller's authority. It used to be answered two different ways
            // depending on which controller caught it: `ReportController` translated it into a 422 that
            // explained itself, while `SheetController` and `DashboardController` fell through to the
            // default arm below and answered 500 with `pettycash.error.unexpected`. FR-083 was raised
            // for exactly that split and now requires one answer from all of them.
            //
            // **Placed here so that it cannot be forgotten.** A per-controller `catch` arm is a thing
            // the next endpoint can simply not have, and the failure is silent in the worst direction:
            // the endpoint answers 500, the client caches it as "the server is broken", and an operator
            // reads a data-integrity problem as a transient fault. Classifying by exception type makes
            // the rule a property of the type rather than of the endpoint — which is what FR-083's
            // "a client writes one handler rather than one per endpoint" actually requires.
            //
            // It is a `LogicException`, not an `InvalidArgumentException`, so this arm cannot be
            // shadowed by the one below it. That separation is deliberate and must be preserved: one of
            // the two is the caller's fault and this one is not, and a client acting on the wrong one
            // retries forever or gives up on data it could have had.
            $error instanceof UnreadableRecordData => [Http::STATUS_UNPROCESSABLE_ENTITY, 'DATA_FAULT'],
            $error instanceof InvalidArgumentException => [Http::STATUS_UNPROCESSABLE_ENTITY, 'VALIDATION_FAILED'],
            // There is deliberately no `LogicException => 403` arm.
            //
            // It used to be there, and it was wrong twice over. `InvalidArgumentException` extends
            // `LogicException`, so the arm was only ever correct for exceptions that were *not*
            // validation failures -- which in practice meant it caught whatever the persistence layer
            // happened to throw. A data fault then answered 403 FORBIDDEN with its internal message
            // echoed verbatim: `GET /reports/transactions` returned
            // "Cannot resolve the currency of transaction revision 7 from its sheet". A client caches
            // that as a permission refusal and stops retrying; an operator reads it as an authorisation
            // problem that does not exist. It is the same over-broad classification that caused T063's
            // bug, where a broad `catch (\Exception)` swallowed every validation failure and answered
            // 422 where the contract requires 409.
            //
            // Authorisation is not inferred from an exception class. Every controller that means
            // "forbidden" already says so explicitly through `forbidden()`, which carries its own
            // message. Anything unrecognised is a server fault: logged in full, reported as a generic
            // failure, and its message withheld.
            default => [Http::STATUS_INTERNAL_SERVER_ERROR, 'UNEXPECTED_FAILURE'],
        };

        if ($status === Http::STATUS_INTERNAL_SERVER_ERROR) {
            // Never surface an internal message to the caller, but always record it:
            // technical errors must go through standard Nextcloud logging.
            $this->logger->unexpected($error, $commandId);
            $message = 'pettycash.error.unexpected';
        } elseif ($error instanceof UnreadableRecordData) {
            // **Logged *and* withheld** — the combination no other 4xx arm needs. The status is a
            // client-actionable one, so the caller gets a real explanation; but the explanation is the
            // canonical text rather than the exception's own, because that one names the surrogate ids
            // of the rows at fault and disclosing those is the leak T029 was raised for.
            //
            // Without this branch the arm above would fall through to `$error->getMessage()` and publish
            // "Cannot resolve the currency of sheet <uuid>: currency_id 42 matches no row in
            // pettycash_currencies" — which tells a caller precisely which stored reference is broken,
            // i.e. something about rows they may not be able to see. It would also break the status of
            // the answer: a caller reading surrogate keys learns it can probe, and one reading "IRR"
            // learns a wrong unit.
            $this->logger->unexpected($error, $commandId);
            $message = self::DATA_FAULT_MESSAGE;
        } else {
            $message = $error->getMessage();
        }

        return $this->respond($status, $code, $message, $commandId);
    }

    /** @param array<string, list<string>> $fieldErrors */
    public function validation(
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        return $this->respond(
            Http::STATUS_UNPROCESSABLE_ENTITY,
            'VALIDATION_FAILED',
            $message,
            $commandId,
            $fieldErrors,
        );
    }

    public function notFound(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_NOT_FOUND, 'NOT_FOUND', $message, $commandId);
    }

    /**
     * A malformed request rather than an invalid field value.
     *
     * Distinct from `validation()` on purpose: a missing required header means the
     * request never carried the information the endpoint needs, which is a 400, while
     * a present-but-unacceptable value is a 422. Both still answer with the `Error`
     * schema, so the frontend client reads them the same way.
     *
     * @param array<string, list<string>> $fieldErrors
     */
    public function badRequest(
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        return $this->respond(
            Http::STATUS_BAD_REQUEST,
            'BAD_REQUEST',
            $message,
            $commandId,
            $fieldErrors,
        );
    }

    public function forbidden(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_FORBIDDEN, 'FORBIDDEN', $message, $commandId);
    }

    public function conflict(string $message, ?string $commandId = null): DataResponse
    {
        return $this->respond(Http::STATUS_CONFLICT, 'STATE_CONFLICT', $message, $commandId);
    }

    /**
     * Reject a payload that exceeds a documented upload limit, per FR-074.
     *
     * @param array<string, list<string>> $fieldErrors
     */
    public function payloadTooLarge(
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        return $this->respond(
            Http::STATUS_REQUEST_ENTITY_TOO_LARGE,
            'PAYLOAD_TOO_LARGE',
            $message,
            $commandId,
            $fieldErrors,
        );
    }

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    private function respond(
        int $status,
        string $code,
        string $message,
        ?string $commandId = null,
        array $fieldErrors = [],
    ): DataResponse {
        $error = [
            'code' => $code,
            'message' => $message,
        ];
        if ($commandId !== null) {
            $error['commandId'] = $commandId;
        }
        if ($fieldErrors !== []) {
            $error['fieldErrors'] = $fieldErrors;
        }

        return new DataResponse(['error' => $error], $status);
    }
}
