<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

use OCA\PettyCash\Domain\Shared\Enum\AuditEventType;
use OCA\PettyCash\Domain\Workflow\Decision;

/**
 * Maps a workflow {@see Decision} to the audit event type that records it.
 *
 * ## Why this is a shared mapper and not a `match` in each service
 *
 * Both the cost-center manager path (`ManagementReviewService`) and the finance path
 * (`FinanceSettlementService`) write a `DECISION` event, and they previously shared only the *string*
 * `"DECISION"` -- nothing tied the two together, which is how they came to disagree with the enum and
 * with FR-051 independently. Routing both through one function means the two decision paths cannot
 * drift apart, and the mapping is stated once.
 *
 * It lives in the Application layer rather than on the enum itself because `Decision` is a
 * workflow concept and `AuditEventType` is shared vocabulary; making the shared enum depend on a
 * specific workflow type would invert that. The vocabulary stays in the domain, the choice of how to
 * *record* a decision is orchestration.
 *
 * FR-051 names approvals, rejections and returns as three separate records, so they are three event
 * types. Before this, all three wrote one `DECISION` event with the outcome only inside the `after`
 * snapshot -- and that snapshot was being discarded, so the trail recorded the fact of a decision and
 * nothing about it. The split makes the outcome answerable from the event stream itself.
 */
final class DecisionEventType
{
    public static function for(Decision $decision): AuditEventType
    {
        return match ($decision) {
            Decision::Approve => AuditEventType::Approved,
            Decision::Reject => AuditEventType::Rejected,
            Decision::Return => AuditEventType::Returned,
        };
    }
}
