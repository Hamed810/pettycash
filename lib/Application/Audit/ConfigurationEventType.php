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
 * ## The eight administration cases, and the two account cases
 *
 * The first eight cases below are the configuration mutations that exist as a public method on one
 * of the four Phase-7 administration services, and the pairing is asserted per case rather than
 * assumed -- a mutation that stopped being audited, or a new one that started without a case, is a
 * change to this enum and therefore visible in review.
 *
 * `AccountFunded` and `AccountCharged` are the two account cases, and they are here now rather than
 * as raw strings on their producers. `AccountCharged` was deliberately absent while its only producer
 * named it as the literal `ChargedAccountScope::CHARGE_EVENT_TYPE`, because a value that a second
 * class *derives scope from* cannot have two definitions; the note deferring that consolidation to
 * "the task that owns those writes" has now been paid by T369, which owns the funding write and so
 * owns both. Moving them in is what lets `ChargedAccountScope` read the enum instead of a literal,
 * so the vocabulary and the derivation can no longer drift apart silently.
 *
 * They are two cases and not one because FR-055 distinguishes them in its own text: an officer's
 * charged accounts are the subjects of their "account funding **and** balance-charging" events, and
 * "an event type outside funding and charging -- a rename, an activation, a deactivation -- MUST NOT
 * count toward the set." One case for both would erase exactly the distinction that sentence is
 * drawn to make: you could no longer ask who put money into an account, as against who consumed from
 * it. FR-015's charge and FR-043's settlement are different business facts and are recorded as
 * different facts.
 *
 * ## What is still deliberately absent
 *
 * `RETENTION_POLICY_CHANGED` is a configuration event and is **not** here. Its producer
 * (`RetentionPolicyService`) names it as an inline literal, no other code reads it, and it is not one
 * of the events FR-055 scopes by account subject -- so there is no derivation to keep in step with the
 * enum, and moving it would be tidiness rather than correctness. It is the same situation
 * `AccountCharged` was in before T369, and the difference that made that one worth fixing is exactly
 * the thing that does not apply here.
 *
 * ## Width
 *
 * The column is `string(48)`. The longest case below is 30 characters, and `EnumColumnWidthTest` sizes
 * `event_type` from the enums that exist, so this vocabulary cannot force a migration. The two account
 * cases are 14 characters each, well inside the longest.
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

    /**
     * T369 `AccountService::charge()` — FR-015's funding of a petty-cash account.
     *
     * Its own case, not a reuse of `AccountCharged`, because FR-055 scopes configuration-audit
     * visibility by "account funding **and** balance-charging" events and requires that anything
     * outside that pair not count. Folding the two together would satisfy the sentence while losing
     * the distinction it is drawn to preserve: FR-015 puts money into an account, FR-043's
     * settlement takes it out, and an auditor asking which happened gets one answer instead of two.
     *
     * This is also the event that makes FR-055's clarification true. It reads, in its own words, that
     * "relying on the transaction-audit decision alone would leave this scope empty for every officer
     * who had actually charged an account" — T294 answered that for the settlement side only, so an
     * officer who had funded an account and not yet settled against it saw nothing.
     */
    case AccountFunded = 'ACCOUNT_FUNDED';

    /**
     * T294 `FinanceSettlementService` — an approved settlement consuming an account's balance.
     *
     * The other half of FR-055's pair. Read together with {@see self::AccountFunded} by
     * `ChargedAccountScope::chargedAccountDbIds()`, which is what turns the pair into the set of
     * accounts a finance officer may read configuration audit for.
     */
    case AccountCharged = 'ACCOUNT_CHARGED';

    /**
     * FR-031 (clarified 2026-10-07): a public evidence link was consumed.
     *
     * It belongs in the **configuration** stream, not the transaction one, for the reason FR-031
     * already gives: a public link is administered configuration, and the access is recorded per
     * FR-031 "in configuration audit". Putting it in the transaction stream would make an anonymous
     * visit appear as a business action on a transaction by a caller who holds no role on it — which is
     * exactly the kind of record FR-056 requires the two streams to keep separable.
     *
     * It is **not** `AccountCharged`, `AccountFunded`, or anything else in FR-055's charged-account
     * pair. `ChargedAccountScope` reads that pair to derive which accounts a finance officer may read,
     * and FR-055 says "an event type outside funding and charging MUST NOT count toward the set". A
     * distinct case makes that automatic rather than a rule someone has to remember: a public link
     * access is not a charge, and adding it here cannot widen anyone's configuration-audit scope.
     *
     * Width: 23 characters, inside the `string(48)` column this enum's docblock documents.
     */
    case EvidenceLinkAccessed = 'EVIDENCE_LINK_ACCESSED';
}