<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Expense;

use OCA\PettyCash\Domain\Workflow\SheetStatus;

/**
 * Which actions the caller may take on a sheet, per `contracts/workflow.md`.
 *
 * ## Why this is domain policy and not formatter code
 *
 * The rules below are not presentation. Each one restates a guard that a command path already
 * enforces, and the whole point of the capability set is that **a hidden action and a refused
 * action cannot disagree** — the same principle `FinanceDecisionPolicy` exists for on the finance
 * side. Deriving them inside `SheetPayloadFormatter` put a business invariant in the infrastructure
 * layer, where the only honest way to test it was through real HTTP against a seeded database.
 *
 * It is here, in the domain layer, because that is where the invariants belong (Constitution IV) and
 * because it makes the whole matrix — including the denial half — assertable without a session. The
 * formatter composes the payload and asks this class what it may put in it.
 *
 * ## Why the actor's uid is a parameter rather than read from a session
 *
 * `GET /sheets/{sheetId}` is owner-scoped: `SheetService::getSheet()` answers `404` for anyone who is
 * not the owner, so a capability set is only ever emitted to the owner in practice. Passing the uid
 * anyway keeps the ownership rule visible at the call site rather than implied by a caller three
 * frames away, and it makes the denial half testable at all — the OCS contract suite has exactly one
 * set of credentials and cannot authenticate as a second user.
 *
 * ## The rules, and the guards they mirror
 *
 * | Capability           | Rule                                   | Enforced by                      |
 * |----------------------|----------------------------------------|-----------------------------------|
 * | `canUpdate`          | owner, and the sheet is still open     | `SheetService::updateSheet()`     |
 * | `canAddTransaction`  | owner, and the sheet is still open     | `TransactionService::createTransaction()` |
 * | `canSubmit`          | owner, open, and at least one transaction | `SubmitSheet::execute()`, FR-024 |
 *
 * `SheetStatus::Open` is the whole of "still open". The enum models the sheet's current stage rather
 * than a separate submitted flag — there is no `SUBMITTED` case, and `data-model.md` is deliberate
 * about that — so a sheet under management review is `COST_CENTER`, `SENIOR`, `FINANCE` or
 * `CORRECTION`, and every one of them denies all three. That is FR-026: after submission a purchaser
 * must not add transactions or edit the sheet.
 *
 * **If one of those guards changes, this class must change with it.** That is the coupling this
 * class exists to make visible; `tests/Unit/Domain/Expense/SheetCapabilitiesTest.php` is where it is
 * pinned.
 */
final class SheetCapabilities
{
    /**
     * The caller's capability set for one sheet.
     *
     * Every key is always present. A capability the caller may not exercise is `false`, never absent:
     * `CapabilitySet` is `additionalProperties: {type: boolean}` in the contract, and a missing key
     * arrives in the client as `undefined` — which passes every `!== false` filter the frontend
     * writes. That is not hypothetical; it is exactly why `DecisionForm`'s option filter was inert
     * until its lookup was corrected.
     *
     * @return array{canUpdate: bool, canAddTransaction: bool, canSubmit: bool}
     */
    public static function derive(ExpenseSheet $sheet, string $actorUid, int $transactionCount): array
    {
        $isOwner = $sheet->ownerUid === $actorUid;
        $isOpen = $sheet->status === SheetStatus::Open;

        return [
            'canUpdate' => $isOwner && $isOpen,
            'canAddTransaction' => $isOwner && $isOpen,
            // FR-024: a sheet must contain at least one transaction before submission. Derived from
            // the count the payload already carries, so the button and the table cannot disagree
            // about whether there is anything to submit.
            'canSubmit' => $isOwner && $isOpen && $transactionCount > 0,
        ];
    }
}
