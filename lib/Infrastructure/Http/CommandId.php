<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Http;

/**
 * FR-042 / T415: the shape of the caller-supplied `X-PettyCash-Command-ID`.
 *
 * ## Why this exists
 *
 * `contracts/ocs-api.yaml:1088` declares `commandId: { type: string, format: uuid, nullable: true }`, and
 * `pettycash_command_results.command_id` is `string(36)`. Nothing checked the value against either, so a
 * caller sending `"t407-" . <uuid>` — 41 characters — reached the storage layer and was refused by
 * **MySQL**:
 *
 * ```
 * SQLSTATE[22001] String data, right truncated: 1406 Data too long for column 'command_id' at row 1
 * ```
 *
 * `OcsErrorMapper`'s catch-all arm mapped that driver error to **500 `UNEXPECTED_FAILURE`** with
 * `pettycash.error.unexpected`. So a **malformed request** was answered as a **server fault** — the one
 * outcome a caller cannot act on, and the one status that tells an operator to go looking for a bug that
 * is in their client.
 *
 * ## Why a dedicated class rather than a check in each controller
 *
 * Measured, not assumed: **6 controllers and 11 sites** read the header, and they do not even agree on
 * the absent case. `AccountController`, `ReviewController` and `TransactionController` answer `400`;
 * `EvidenceController::upload()` answers `422`; `ExportController` and `SheetController` accumulate the
 * field error into a shared `$fieldErrors` array. A rule enforced in eleven places is a rule that will be
 * enforced in ten of them, so the shape lives here and the call sites only ask the question.
 *
 * **The absent case is deliberately not this class's job.** Each controller already answers it, with a
 * field error keyed to `commandId`, in the shape its own envelope test expects — and that shape is
 * inconsistently `400` and `422` today, which is a separate question about the absent case rather than
 * about this one. Narrowing this class to the **present-but-malformed** value keeps it from becoming the
 * place where that inconsistency gets silently resolved one call site at a time.
 */
final readonly class CommandId
{
    /**
     * The contract's declared shape, as a length bound.
     *
     * `string(36)` is the column, and a v4 UUID is exactly 36 characters, so the two agree. The check is a
     * length bound rather than a full pattern match because the **column** is what rejects the value: a
     * non-UUID string of 36 characters fits and will be stored, and refusing it here would be a
     * stricter rule than the contract's own storage can enforce. Refusing only what cannot be stored keeps
     * the two honest with each other.
     *
     * The full v4 pattern lives in {@see \OCA\PettyCash\Domain\Shared\Uuid::fromString()}, and is not
     * duplicated here — a second definition of "what a UUID is" is how the two drift.
     */
    /**
     * The column's width, exposed so a caller that *truncates* rather than refuses stays in step with
     * one that rejects. `ConfigurationController` uses it for exactly that, and a second hardcoded `36`
     * there would be a second place to forget when the column changes.
     */
    public const MAX_LENGTH = 36;

    private function __construct(private string $value) {}

    /**
     * Wrap a command id that has already been established to be present.
     *
     * @throws \InvalidArgumentException when the value cannot be stored, naming the bound
     */
    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if (strlen($trimmed) > self::MAX_LENGTH) {
            // The bound and the real column are both named, because the actionable half of this message
            // is "here is the maximum", not "your input was wrong".
            throw new \InvalidArgumentException(sprintf(
                'commandId must be at most %d characters to fit pettycash_command_results.command_id; got %d',
                self::MAX_LENGTH,
                strlen($trimmed)
            ));
        }

        return new self($trimmed);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}