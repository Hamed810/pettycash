<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Configuration;

use InvalidArgumentException;
use OCA\PettyCash\Application\Audit\ConfigurationAuditService;
use OCA\PettyCash\Application\Audit\ConfigurationEventType;
use OCA\PettyCash\Application\Port\AdministratorCheck;
use OCA\PettyCash\Domain\Configuration\Currency;
use OCA\PettyCash\Domain\Configuration\Repository\CurrencyRepository;
use OCA\PettyCash\Domain\Shared\PageBounds;

/**
 * FR-013 / FR-012 currency administration. T081.
 *
 * ## What "immutable historical references" means here
 *
 * `contracts/ocs-api.yaml` identifies a currency by its `code` alone — `CurrencyId` is
 * `{ minLength: 3, maxLength: 3 }`, matching `CurrencyWrite.code`'s `^[A-Z]{3}$`. The code is
 * therefore the currency's identity, not an editable attribute, and no endpoint can express
 * "rename this currency". That is the whole mechanism behind FR-012's requirement that historical
 * records retain their original values: an expense whose currency was `IRR` resolves `IRR` forever,
 * because the row keyed `IRR` still exists and its `minor_unit` is what that expense was scaled by.
 *
 * What *is* mutable is `name`, `minorUnit` and `active`. Retirement is `active = false` and nothing
 * else — there is no delete anywhere in this service or its contract, because deleting the row a
 * settled expense points at would break exactly the historical reference the requirement protects.
 * A deactivated currency stays readable through `findByCode` (so history still resolves it) and
 * drops out of `findAllActive` (so no new record can select it).
 *
 * ## The rial default
 *
 * FR-014, FR-020 and T085 all need to know which currency is "the rial", because a purchaser with
 * zero active accounts is provisioned a default account *in rial*. That identification is a property
 * of configuration, not a constant scattered through provisioning code, so it lives here: the code is
 * `IRR` and its scale must be `minor_unit = 0`, since rial has no minor unit. `defaultRial()` is the
 * one lookup T085 needs, and it fails loudly rather than returning null so a provisioning path
 * cannot silently provision in the wrong scale.
 *
 * ## Authorization
 *
 * FR-010/FR-012/FR-013 put currency management with administrators, and T078's authorization matrix
 * is "administrators manage configuration". Reads are restricted the same way: the contract describes
 * this endpoint as "currencies visible to the current **administrative** context", and unlike cost
 * centres and expense types no purchaser-facing view calls it. Nothing here is reference data a
 * transaction form needs — `AccountService` resolves a currency by code directly — so there is no
 * reason to widen it.
 */
final readonly class CurrencyService
{
    /** FR-014/FR-020: the currency a default petty-cash account is provisioned in. */
    public const DEFAULT_RIAL_CODE = 'IRR';

    /** Rial has no minor unit; a configured `IRR` with another scale is a misconfiguration, not a rial. */
    public const DEFAULT_RIAL_MINOR_UNIT = 0;

    public function __construct(
        private CurrencyRepository $currencies,
        private AdministratorCheck $administrators,
        private ConfigurationAuditService $configurationAudit,
    ) {}

    /**
     * The active currencies, paged.
     *
     * @return array{items: list<Currency>, page: int, pageSize: int, total: int}
     */
    public function listForCaller(string $actorUid, ?int $page = null, ?int $pageSize = null): array
    {
        $this->requireAdministrator($actorUid);

        $bounds = new PageBounds($page, $pageSize);
        $active = $this->currencies->findAllActive();
        $total = count($active);

        return [
            'items' => array_slice($active, $bounds->offset, $bounds->pageSize),
            ...$bounds->pageMeta($total),
        ];
    }

    /**
     * Register a currency.
     *
     * The duplicate check is here rather than left to `pettycash_currencies_code_uniq` so the caller
     * gets the contract's 409 with a sentence it can act on, instead of a driver exception mapped to a
     * 500. The unique index still exists and is still the last line of defence; this is the layer
     * that speaks.
     */
    public function create(string $actorUid, string $code, string $name, int $minorUnit, bool $active = true): Currency
    {
        $this->requireAdministrator($actorUid);

        $normalised = strtoupper(trim($code));
        if ($this->currencies->findByCode($normalised) !== null) {
            throw ConfigurationConflict::alreadyExists('currency', $normalised);
        }

        // T086 / FR-017: the write and its audit record are one unit. No `changeReason` parameter
        // exists here because `contracts/ocs-api.yaml`'s `CurrencyWrite` has no such field -- inventing
        // one would let a caller supply an explanation the contract never accepted, and FR-017's
        // "explanation" for a currency is then recorded as null.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::CurrencyCreated->value,
            entityType: 'currency',
            // The code, because `pettycash_currencies` has no `public_id` column at all -- see the
            // report. The code is also the identity this service resolves every read on.
            subjectOf: static fn(Currency $c): string => $c->code(),
            afterSnapshot: static fn(Currency $c): array => [
                'code' => $c->code(),
                'name' => $c->name,
                'minorUnit' => $c->minorUnit,
                'active' => $c->active,
            ],
            mutate: function () use ($normalised, $name, $minorUnit, $active): Currency {
                // `Currency::create()` is the authority on the shape of a currency; letting it raise keeps
                // one definition of "valid" rather than a service copy that can drift from the domain's.
                $currency = Currency::create($normalised, trim($name), $minorUnit);
                $currency->active = $active;
                $this->currencies->save($currency);

                return $currency;
            },
        );
    }

    /**
     * Update name, scale and active state.
     *
     * `expectedVersion` is required and is not defaulted, for the same reason `AccountController::charge()`
     * requires one: administration is a rare concurrent write, but two administrators editing the same
     * currency is exactly the case where last-write-wins silently discards one of them. The version is
     * checked against the value read in this call, so a mismatch is a 409 the caller recovers from by
     * reloading — never an update applied to a row that has moved on.
     *
     * The code is absent from the signature on purpose: it is the identity (see the class docblock), so
     * there is no version of "rename a currency" to accidentally support.
     */
    public function update(
        string $actorUid,
        string $code,
        string $name,
        int $minorUnit,
        bool $active,
        int $expectedVersion
    ): Currency {
        $this->requireAdministrator($actorUid);

        $existing = $this->currencies->findByCode($code);
        if ($existing === null) {
            throw new InvalidArgumentException(sprintf('No currency with code %s exists', $code));
        }
        if ($existing->lockVersion !== $expectedVersion) {
            throw ConfigurationConflict::staleVersion(
                'currency',
                $code,
                $expectedVersion,
                $existing->lockVersion
            );
        }

        // T086 / FR-017. `beforeSnapshot` closes over `$existing`, which was read *above* and not
        // re-read inside the transaction: capturing it after the write would make the "previous value"
        // the post-state wearing a "before" label.
        return $this->configurationAudit->record(
            eventType: ConfigurationEventType::CurrencyUpdated->value,
            entityType: 'currency',
            subjectOf: static fn(Currency $c): string => $c->code(),
            beforeSnapshot: static fn(): array => [
                'code' => $existing->code(),
                'name' => $existing->name,
                'minorUnit' => $existing->minorUnit,
                'active' => $existing->active,
            ],
            afterSnapshot: static fn(Currency $c): array => [
                'code' => $c->code(),
                'name' => $c->name,
                'minorUnit' => $c->minorUnit,
                'active' => $c->active,
            ],
            mutate: function () use ($code, $existing, $name, $minorUnit, $active): Currency {
                // Re-validate through the domain factory rather than assigning the properties, so an
                // update cannot set a name or scale that `create()` would have refused.
                $updated = Currency::create($code, trim($name), $minorUnit);
                $updated->active = $active;
                $updated->id = $existing->id;
                $updated->lockVersion = $existing->lockVersion;
                $this->currencies->save($updated);

                return $updated;
            },
        );
    }

    /**
     * Whether a currency code identifies the default rial.
     *
     * Scale is not consulted here — this is an identity question, and `defaultRial()` is the one that
     * also asserts the scale. Splitting them keeps a caller's "is this the rial?" answerable without
     * a repository read.
     */
    public function isDefaultRial(string $code): bool
    {
        return strtoupper(trim($code)) === self::DEFAULT_RIAL_CODE;
    }

    /**
     * The configured default rial, for FR-020's idempotent provisioning.
     *
     * Throws rather than returning null: T085 provisions a user's first petty-cash account in this
     * currency, and a provisioning path that quietly continued without one would create an account in
     * an unknown currency. "IRR is not configured" is an administrator's problem and must be reported
     * as one.
     */
    public function defaultRial(): Currency
    {
        $rial = $this->currencies->findByCode(self::DEFAULT_RIAL_CODE);
        if ($rial === null) {
            throw new InvalidArgumentException(
                'The default rial currency is not configured; an administrator must create currency IRR'
            );
        }
        if ($rial->minorUnit !== self::DEFAULT_RIAL_MINOR_UNIT) {
            throw new InvalidArgumentException(
                sprintf(
                    'Currency IRR is configured with minor unit %d; the rial has no minor unit',
                    $rial->minorUnit
                )
            );
        }
        return $rial;
    }

    /**
     * @throws \LogicException when the caller is not an administrator
     */
    private function requireAdministrator(string $actorUid): void
    {
        if ($actorUid === '' || !$this->administrators->isAdministrator($actorUid)) {
            throw new \LogicException('Only an administrator may administer currencies');
        }
    }
}
