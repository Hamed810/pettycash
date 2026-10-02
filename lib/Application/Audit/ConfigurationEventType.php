<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Audit;

/**
 * FR-017's configuration audit vocabulary. T086.
 *
 * ## Why this is a second enum and not more cases on `AuditEventType`
 *
 * `AuditEventType`'s own docblock states the rule this file follows: it governs
 * `pettycash_transaction_audit` only, and configuration events "belong to FR-055 and are not listed
 * here; conflating the two streams is what made the previous version of this file misleading in the
 * first place." FR-056 requires the two streams to stay separately identifiable, so one enum covering
 * both would work directly against that.
 *
 * ## Why the vocabulary is closed
 *
 * The eight cases below are the configuration mutations that exist. Each one is a public method on one
 * of the four Phase-7 administration services, and the pairing is asserted per case rather than
 * assumed -- a mutation that stopped being audited, or a new one that started without a case, is a
 * change to this enum and therefore visible in review.
 *
 * ## What is deliberately absent
 *
 * `ACCOUNT_CHARGED` and `RETENTION_POLICY_CHANGED` are configuration events and are **not** here.
 * Their producers are `FinanceSettlementService::charge()` and `RetentionPolicyService`, which name
 * them as literals (`ChargedAccountScope::CHARGE_EVENT_TYPE` and inline), and both are reached through
 * their own paths rather than through this task. Listing them here without moving those producers
 * would create a second source of truth for a value another code reads
 * (`ChargedAccountScope::findEntityIdsByEventType()` derives charged accounts from the literal), so
 * the consolidation belongs with the task that owns those writes.
 *
 * ## Width
 *
 * The column is `string(48)`. The longest case below is 30 characters, and `EnumColumnWidthTest` sizes
 * `event_type` from the enums that exist, so this vocabulary cannot force a migration.
 */
enum ConfigurationEventType: string
{
    /** T081 `CurrencyService::create()`. `before` is empty: there was no prior value. */
    case CurrencyCreated = 'CURRENCY_CREATED';

    /**
     * T081 `CurrencyService::update()`. The reason this is not `CURRENCY_PATCHED`: the service keys the
     * currency by `code` and refuses to rename it, so there is no "patch" to distinguish from "update".
     */
    case CurrencyUpdated = 'CURRENCY_UPDATED';

    /** T082 `CostCenterService::create()`, which also writes the manager and senior-manager assignments. */
    case CostCenterCreated = 'COST_CENTER_CREATED';

    /**
     * T082's reopened scope, `CostCenterService::update()`.
     *
     * Distinct from `CostCenterCreated` because the two record opposite facts about `before`: a
     * creation has none, this one has a prior state that FR-017 requires be recorded as the previous
     * value. Folding them together would make "a cost centre was configured" answer both questions.
     *
     * Its own case rather than a pair of `…_RENAMED` / `…_DEACTIVATED`: FR-010's "create, update,
     * activate, and deactivate" map onto the contract's `POST` plus one `PATCH` whose body carries
     * `active`, so activation is an update and a second vocabulary entry would name a distinction the
     * caller cannot make.
     */
    case CostCenterUpdated = 'COST_CENTER_UPDATED';

    /**
     * T082 `CostCenterService::transferManager()`, which closes the outgoing assignment and opens the
     * incoming one. FR-017 names "assignments" as its own category, so this is audited in its own right
     * rather than folded into the cost-centre event that created the centre.
     */
    case ManagerTransferred = 'COST_CENTER_MANAGER_TRANSFERRED';

    /** T083 `ExpenseTypeService::create()`. */
    case ExpenseTypeCreated = 'EXPENSE_TYPE_CREATED';

    /** T083 `ExpenseTypeService::updateByTitle()`. */
    case ExpenseTypeUpdated = 'EXPENSE_TYPE_UPDATED';

    /**
     * T083 `ExpenseTypeService::deactivate()`.
     *
     * Its own case, not folded into `ExpenseTypeUpdated`, because it is the operation US5/AC2 turns on:
     * deactivation stops a type being offered while historical records keep their original values. An
     * event type that merged the two would leave no way to ask "who retired this type" without also
     * matching every correction to it.
     */
    case ExpenseTypeDeactivated = 'EXPENSE_TYPE_DEACTIVATED';

    /**
     * T084 `FinanceAssignmentService::create()`.
     *
     * One case for an upsert, because the contract's `POST /configuration/finance-assignments` is one
     * operation that adds, corrects and revokes -- there is no `PATCH` for this resource. Whether a
     * given call created or changed the row is read from the snapshots: `before` empty means created,
     * populated means changed. Inventing a `_CREATED`/`_UPDATED` pair here would claim a distinction
     * the caller cannot express.
     */
    case FinanceAssignmentChanged = 'FINANCE_ASSIGNMENT_CHANGED';
}