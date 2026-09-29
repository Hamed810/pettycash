<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared\Enum;

/**
 * The authoritative vocabulary of the **transaction** audit stream.
 *
 * ## Provenance
 *
 * Every case corresponds to one of the ten transitions FR-051 names, in the order FR-051 lists them:
 * submissions, routing, approvals, rejections, returns, corrections, revision creation, transaction
 * changes, evidence additions, and evidence deactivations. The list was derived from the requirement
 * rather than from the code, because the code and the previous version of this enum had drifted apart
 * and neither could be trusted as the source. See `docs/decisions/` for the ADR.
 *
 * ## Why this enum exists at all
 *
 * It used to exist without being used. The production services wrote raw strings, the five of them
 * being `SUBMISSION`, `DECISION`, `ACCOUNT_CHARGED`, `CORRECTION_RESUBMITTED` and
 * `EVIDENCE_DEACTIVATED` -- of which only the last appeared here at all. `EnumColumnWidthTest` sized
 * the `event_type` column from this enum, so the column was being kept wide for a vocabulary that
 * nothing wrote, while the values actually stored were invisible to every check on this file.
 *
 * The producers now pass these cases, so the type of the column and the values in it cannot disagree
 * again. `AuditEventTypeConformanceTest` fails if a producer reintroduces a raw string.
 *
 * ## What changed from the previous version, and why
 *
 * - `Decided` was **split** into `Approved`, `Rejected` and `Returned`. A single type covering approval
 *   and rejection meant the audit stream could not answer "show me every return" without reading a JSON
 *   snapshot, and FR-051 names approvals, rejections and returns as three separate records. The domain
 *   agrees: `Decision` is exactly `{APPROVE, REJECT, RETURN}`.
 * - `Settled` was **removed**. FR-051 does not name settlement as a transition. The charge itself is
 *   recorded as `ACCOUNT_CHARGED` in the *configuration* stream under FR-055, which is a different
 *   stream with a different vocabulary and is deliberately not modelled here.
 * - `RevisionCreated` and `TransactionChanged` were **added**. FR-051 names both and the previous enum
 *   had no case for either, which is part of why nothing wrote them.
 *
 * ## Scope
 *
 * This enum governs `pettycash_transaction_audit` only. Configuration events such as `ACCOUNT_CHARGED`
 * and `RETENTION_POLICY_CHANGED` belong to FR-055 and are not listed here; conflating the two streams is
 * what made the previous version of this file misleading in the first place.
 */
enum AuditEventType: string
{
    /** FR-051 "submissions". Sheet-level; `transaction_id` is null. FR-032 starts formal history here. */
    case Submitted = 'SUBMITTED';

    /** FR-051 "routing". FR-032: each transaction routed to the manager assigned to its cost center. */
    case Routed = 'ROUTED';

    /** FR-051 "approvals". The `after` snapshot carries the explanation FR-033 requires. */
    case Approved = 'APPROVED';

    /** FR-051 "rejections". Explanation required by FR-033. */
    case Rejected = 'REJECTED';

    /** FR-051 "returns". The request for correction; explanation required by FR-033. */
    case Returned = 'RETURNED';

    /** FR-051 "corrections". The corrected transaction resubmitted into the workflow. */
    case Corrected = 'CORRECTED';

    /** FR-051 "revision creation". A new revision, on first submission or on resubmission. */
    case RevisionCreated = 'REVISION_CREATED';

    /** FR-051 "transaction changes". A field of a transaction was edited. */
    case TransactionChanged = 'TRANSACTION_CHANGED';

    /** FR-051 "evidence additions". */
    case EvidenceAdded = 'EVIDENCE_ADDED';

    /** FR-051 "evidence deactivations". */
    case EvidenceDeactivated = 'EVIDENCE_DEACTIVATED';
}
