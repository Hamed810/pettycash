<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use DateTimeInterface;
use OCA\PettyCash\Domain\Configuration\CostCenter;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\ExpenseType;
use OCA\PettyCash\Domain\Configuration\RoleAssignment;
use OCA\PettyCash\Domain\Shared\Enum\Role;

/**
 * The `contracts/ocs-api.yaml` response shapes for the eleven `configuration#*` endpoints. T087.
 *
 * ## Why a formatter and not inline arrays in the controller
 *
 * `SheetPayloadFormatter` and `TransactionPayloadFormatter` exist for the same reason, and the reason
 * is already recorded against `SheetController`: a private copy of a contract mapping inside a
 * controller is a second place for the contract to drift, and it had already drifted — it omitted the
 * required `totals` and emitted an `accountId` the contract does not declare. Four of these eleven
 * endpoints are list endpoints and three return the same aggregate their list returns, so the shape is
 * needed in at least seven places; inlining it would mean seven copies.
 *
 * ## `managerUserId` / `seniorManagerUserId` are resolved per cost centre
 *
 * `CostCenterWrite` makes both fields required, and `CostCenter` is `allOf` that write plus `{id,
 * version}` — so a cost centre the contract describes has no representation without its current
 * holders. The aggregate does not carry them: they live in `pettycash_role_assignments`, which is what
 * lets FR-011 change a holder over time without touching the centre. They are therefore resolved
 * through `CostCenterService::currentManagersOn()` and passed in, rather than invented on the
 * aggregate.
 *
 * The cost is one extra read per row. That is accepted deliberately: a cost centre is configuration,
 * not transaction data, so the set is bounded by how many centres an organisation defines (tens, not
 * thousands), and the alternative — dropping the two required fields to save a read — would make every
 * administration screen disagree with the contract. **If that changes, the fix is a batched
 * `findActiveByRoleAndCostCenter` over the page's ids, not a cached aggregate copy.**
 *
 * ## The date format
 *
 * `validFrom` and `validTo` are `format: date`, so `Y-m-d`. `DateTimeInterface::ATOM` is deliberately
 * *not* used here: it would emit a full RFC 3339 timestamp, which a `format: date` consumer would
 * either reject or silently mis-parse, and a validity *boundary* is a calendar day in every artifact
 * that mentions it.
 *
 * ## `changeReason` on a read projection
 *
 * `RoleAssignment` is `allOf[FinanceAssignmentWrite, {required: [id, role]}]`, and
 * `FinanceAssignmentWrite` lists `changeReason` as **required**. The domain aggregate has no such
 * column — `data-model.md` defines `RoleAssignment` with `user_id`, `role`, `cost_center_id`,
 * `valid_from`, `valid_to` and `active`, and no explanation field — because a change reason is an
 * input to the write and the record of it belongs to the configuration audit, which is what FR-017
 * actually asks for.
 *
 * So the key is emitted as `null`: present, which satisfies the schema's `required`, and honest, where
 * inventing a stored string would be worse than both. The contract's type for it (`string`,
 * non-nullable) is a real disagreement and is recorded rather than worked around silently — see the
 * T087 entry in `tasks.md`. Fixing it properly means either adding the column or amending the
 * contract, and both are amendments this task does not own.
 */
final class ConfigurationPayloadFormatter
{
    /**
     * `CurrencyWrite` plus `version`. The code is the currency's identity (T081), so it is emitted
     * verbatim and there is no path here that renames one.
     *
     * @return array<string, mixed>
     */
    public function currency(Currency $currency): array
    {
        return [
            'code' => $currency->code(),
            'name' => $currency->name,
            'minorUnit' => $currency->minorUnit,
            'active' => $currency->active,
            'version' => $currency->lockVersion,
        ];
    }

    /**
     * `CurrencyPage`: `PageMeta` plus `items`.
     *
     * @param array{items: list<Currency>, page: int, pageSize: int, total: int} $page
     * @return array<string, mixed>
     */
    public function currencyPage(array $page): array
    {
        return [
            'items' => array_map([$this, 'currency'], $page['items']),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * `CostCenter`: `CostCenterWrite` plus `{id, version}`.
     *
     * `changeReason` is `null` for the same reason as on a role assignment: it is an input to the
     * write, and the explanation that was given is in the configuration audit.
     *
     * @param array<string, list<RoleAssignment>>|null $managers keyed by `Role::value`, as
     *        `CostCenterService::currentManagersOn()` returns them
     * @return array<string, mixed>
     */
    public function costCenter(CostCenter $costCenter, ?array $managers = null): array
    {
        return [
            'id' => $costCenter->publicId,
            'code' => $costCenter->code(),
            'name' => $costCenter->name,
            'description' => $costCenter->description,
            'validFrom' => $this->date($costCenter->validFrom),
            'validTo' => $costCenter->validTo === null ? null : $this->date($costCenter->validTo),
            'active' => $costCenter->active,
            // Absent rather than an empty string: a centre with no readable holder is a data problem,
            // and a blank user id would read as a real one to whatever consumes this.
            'managerUserId' => $this->firstHolder($managers, Role::CostCenterManager),
            'seniorManagerUserId' => $this->firstHolder($managers, Role::SeniorManager),
            'changeReason' => null,
            'version' => $costCenter->lockVersion,
        ];
    }

    /**
     * `CostCenterPage`.
     *
     * `$managersFor` is called once per row rather than being pre-resolved, so a caller cannot
     * accidentally answer with one centre's holders for every centre on the page.
     *
     * @param array{items: list<CostCenter>, page: int, pageSize: int, total: int} $page
     * @param (callable(CostCenter): array<string, list<RoleAssignment>>) $managersFor
     * @return array<string, mixed>
     */
    public function costCenterPage(array $page, callable $managersFor): array
    {
        $items = [];
        foreach ($page['items'] as $costCenter) {
            $items[] = $this->costCenter($costCenter, $managersFor($costCenter));
        }

        return [
            'items' => $items,
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * The `CostCenter` a `create()` produced, using the two assignments it returned.
     *
     * @param array{costCenter: CostCenter, manager: RoleAssignment, seniorManager: RoleAssignment} $created
     * @return array<string, mixed>
     */
    public function costCenterFromCreate(array $created): array
    {
        return $this->costCenter(
            $created['costCenter'],
            [
                Role::CostCenterManager->value => [$created['manager']],
                Role::SeniorManager->value => [$created['seniorManager']],
            ]
        );
    }

    /**
     * `ExpenseType`: `ExpenseTypeWrite` plus `{id, version}`.
     *
     * `evidenceRequired` is carried because it decides whether FR-023's evidence rule applies to a
     * transaction; a form that had to look it up separately could demand evidence the configuration
     * does not require, or omit evidence it does.
     *
     * @return array<string, mixed>
     */
    public function expenseType(ExpenseType $expenseType): array
    {
        return [
            'id' => $expenseType->publicId,
            'title' => $expenseType->title,
            'description' => $expenseType->description,
            'evidenceRequired' => $expenseType->evidenceRequired,
            'active' => $expenseType->active,
            'changeReason' => null,
            'version' => $expenseType->lockVersion,
        ];
    }

    /**
     * `ExpenseTypePage`.
     *
     * @param array{items: list<ExpenseType>, page: int, pageSize: int, total: int} $page
     * @return array<string, mixed>
     */
    public function expenseTypePage(array $page): array
    {
        return [
            'items' => array_map([$this, 'expenseType'], $page['items']),
            'page' => $page['page'],
            'pageSize' => $page['pageSize'],
            'total' => $page['total'],
        ];
    }

    /**
     * `RoleAssignment`: `FinanceAssignmentWrite` plus `{id, role}`.
     *
     * @return array<string, mixed>
     */
    public function roleAssignment(RoleAssignment $assignment): array
    {
        return [
            'id' => $assignment->publicId,
            'role' => $assignment->role->value,
            'userId' => $assignment->userUid,
            'validFrom' => $this->date($assignment->validFrom),
            'validTo' => $assignment->validTo === null ? null : $this->date($assignment->validTo),
            'active' => $assignment->active,
            'changeReason' => null,
        ];
    }

    /**
     * The bare array `GET /configuration/finance-assignments` returns.
     *
     * Deliberately **not** a paged envelope: the contract's response is `type: array, items:
     * RoleAssignment`, and wrapping it in `PageMeta` would be a second disagreement with the same
     * file this projection exists to satisfy.
     *
     * @param list<RoleAssignment> $assignments
     * @return list<array<string, mixed>>
     */
    public function roleAssignments(array $assignments): array
    {
        return array_map([$this, 'roleAssignment'], $assignments);
    }

    /**
     * The current holder of a role, or null when there is none readable.
     *
     * FR-011 requires exactly one current holder per role, so a list of zero is a state the domain
     * should not produce and a list of many is a state it should not produce either. Neither is
     * papered over here: the first entry is reported because that is the one FR-011 routes to, and the
     * count is not silently collapsed so a caller reading a one-row list cannot tell a healthy centre
     * from a duplicated one.
     *
     * @param array<string, list<RoleAssignment>>|null $managers
     */
    private function firstHolder(?array $managers, Role $role): ?string
    {
        $holders = $managers[$role->value] ?? [];

        return $holders === [] ? null : $holders[0]->userUid;
    }

    private function date(DateTimeInterface $moment): string
    {
        return $moment->format('Y-m-d');
    }
}