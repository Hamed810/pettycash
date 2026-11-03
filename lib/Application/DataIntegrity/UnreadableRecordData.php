<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\DataIntegrity;

use LogicException;

/**
 * A stored record cannot be read back because the data behind it is incomplete.
 *
 * ## Why this is a type and not a bare LogicException
 *
 * `DbTransactionRevisionRepository` threw a plain `LogicException` when a revision's currency could not
 * be resolved. Every caller therefore had to guess what it meant, and `OcsErrorMapper` had to classify
 * it by exception class alone -- which is how a persistence fault came to be reported to callers as
 * `403 Forbidden` with its internal message attached (T029).
 *
 * A dedicated type lets the caller that *can* do something about it recognise it, and lets the
 * translation live in one place rather than in each controller that happens to be on the path. FR-083
 * requires exactly that: the same fault had two answers depending on the endpoint -- the report
 * endpoints returned 422 because `ReportController` translated it, while sheets and dashboard fell
 * through to the default mapper's 500 -- so `OcsErrorMapper` now classifies this type once, for every
 * caller. It deliberately extends `LogicException` and is checked *before* the `InvalidArgumentException`
 * arm, because it is not the caller's fault and must never be answered as a validation failure.
 *
 * ## Why the discriminator is structural
 *
 * A sheet can be unreadable for two different reasons: its stored account reference is broken, or the
 * currency the sheet itself stores is broken. Both are the same exception type and both are answered
 * `422 DATA_FAULT`, which is right -- a client can do nothing different about either. An operator,
 * however, needs to know which column to inspect, and a later endpoint may need to be more specific
 * about an account fault without reclassifying a currency fault.
 *
 * The distinction is therefore a typed flag set through {@see self::accountReference()}, never a
 * substring of the message. Message text is documentation for a log line: it is rewritten for clarity,
 * localised by nobody in particular, and would make the classification depend on prose instead of on
 * the code that raised it.
 *
 * ## The message is for a log, not for a client
 *
 * It names the surrogate ids, which is exactly what an operator needs and exactly what a caller must
 * not be given. `OcsErrorMapper` answers 422 with its own canonical text and logs this one in full --
 * see its `DATA_FAULT_MESSAGE`. A response built from *this* message is a disclosure, which is the
 * defect T029 was raised for.
 */
final class UnreadableRecordData extends LogicException
{
    /**
     * @param bool $accountReference true when the unreadable reference is the sheet's stored account
     *                               rather than the currency stored on the sheet itself.
     */
    public function __construct(string $message, private readonly bool $accountReference = false)
    {
        parent::__construct($message);
    }

    /**
     * The sheet's stored account cannot be resolved, or disagrees with the sheet's currency.
     *
     * Kept as a named factory rather than a boolean argument at each call site so that the account
     * fault cannot be raised by accident: a new call site that omits it produces the general variant,
     * which is the safe default, and a caller that inspects {@see self::isAccountReference()} gets the
     * answer from the exception type rather than from its message.
     */
    public static function accountReference(string $message): self
    {
        return new self($message, true);
    }

    /**
     * Whether the unreadable reference is the sheet's stored account.
     *
     * A missing sheet currency returns false even when the sheet also carries an account id: the
     * sheet's currency is the authority for its amounts, and the account is only read once that
     * authority resolves. Keeping the two faults separate is what lets an account-specific answer be
     * added without turning a currency fault into one.
     */
    public function isAccountReference(): bool
    {
        return $this->accountReference;
    }
}
