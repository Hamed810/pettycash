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

    /**
     * FR-031: an administrator created a public evidence link.
     *
     * A distinct case from {@see self::EvidenceLinkAccessed} because the two answer opposite
     * questions and happen at opposite ends of the link's life — "who opened it" versus "who handed it
     * out". Merging them would make the creation event answer a question about access and the access
     * event answer one about issuance, and neither could be asked cleanly.
     *
     * **`ChargedAccountScope` reads only `AccountFunded` and `AccountCharged`,** so neither this nor
     * {@see self::EvidenceLinkDeactivated} can widen an officer's configuration-audit scope. That is
     * structural rather than incidental: FR-055 says only funding and charging events count toward the
     * charged-account set, and adding a case to this enum cannot change what that reader matches.
     *
     * Width: 24 characters, inside the `string(48)` column.
     */
    case EvidenceLinkCreated = 'EVIDENCE_LINK_CREATED';

    /**
     * FR-031: a public evidence link was revoked.
     *
     * Revocation is one of the **two controls FR-031 leaves** once the token is the whole authority —
     * TTL being the other — so it is a named control with its own record rather than a mutation of the
     * creation event. `before`/`after` carry `active`, so an audit reader can tell a revocation from a
     * re-issued link.
     *
     * Width: 27 characters, inside the `string(48)` column.
     */
    case EvidenceLinkDeactivated = 'EVIDENCE_LINK_DEACTIVATED';

    /**
     * FR-017: a petty-cash account came into existence.
     *
     * An account is the object that confers spending authority, and this is the **one configurable
     * resource whose arrival used to leave no trace** — currency, cost centre and expense type each
     * recorded their own creation, and an account's *funding* and *charging* were already recorded, so
     * the omission was an inconsistency rather than a policy. FR-055 did not name accounts, which is
     * why the question was open rather than settled; clarified 2026-10-08.
     *
     * **One event type for every origin, not one per origin.** Three paths create an account:
     * `AccountService::createFor()` as an administrator (FR-013), `createFor()` as the account holder
     * opening one for themselves, and `AccountAdministrationService::ensureDefaultRialAccount()` as
     * FR-020's self-service provisioning. They are the same kind of fact, so two types would fragment
     * the stream an administrator reads to answer "who created this, and was that authorised".
     *
     * **The recorded actor is what distinguishes them.** For a provisioned account the actor is the
     * **account holder**, so the row shows both that the account exists and that **no administrator
     * authorised it** — the most useful thing it can say.
     *
     * **The row is a record, not an authorisation.** Provisioning stays self-service and a purchaser
     * needs no approval or role; this event MUST NOT be presented or exported as evidence of
     * administrator consent. Mirrors the 2026-10-07 evidence-link clarification.
     *
     * **`ChargedAccountScope` still reads only {@see self::AccountFunded} and
     * {@see self::AccountCharged}**, so adding this case cannot widen a finance officer's
     * configuration-audit scope — the same structural argument that applies to the evidence-link
     * cases. A freshly provisioned, unfunded account therefore stays outside that scope, which is
     * correct: FR-055 admits funding and charging events only.
     *
     * Width: 14 characters, inside the `string(48)` column.
     */
    case AccountCreated = 'ACCOUNT_CREATED';

    /**
     * FR-017: an administrator deactivated a petty-cash account. FR-013 as decided.
     *
     * A distinct case from `AccountCreated` because the two answer opposite questions about the same
     * object — one says spending authority was conferred, the other says it was withdrawn — and a single
     * `ACCOUNT_UPDATED` would force every reader to open the before/after pair to tell which happened.
     *
     * **`ChargedAccountScope` reads only `AccountFunded` and `AccountCharged`**, so adding these cases
     * cannot widen a finance officer's configuration-audit scope — the same structural argument the
     * `AccountCreated` case records. A deactivated account therefore stays visible exactly where it was
     * visible before, which is correct: FR-055 admits funding and charging events only.
     *
     * Width: 19 characters, inside the `string(48)` column.
     */
    case AccountDeactivated = 'ACCOUNT_DEACTIVATED';

    /**
     * FR-017: an administrator reactivated a petty-cash account.
     *
     * Its own case rather than sharing `AccountDeactivated` for the same reason that case is separate
     * from `AccountCreated`: the direction is the fact, and a reader filtering for "which accounts were
     * retired" must not have to exclude the ones that were later restored.
     *
     * Width: 19 characters, inside the `string(48)` column.
     */
    case AccountReactivated = 'ACCOUNT_REACTIVATED';
}