<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application;

/**
 * A command that was well formed and cannot be applied because the resource moved.
 *
 * Distinct from a validation failure, which is the caller's input being wrong, and from a refusal,
 * which is the caller not being allowed. This is the third answer: the request was correct, the
 * thing it addresses exists, and doing it now would be wrong — another officer changed the balance,
 * or the pair the caller asked for already exists.
 *
 * It exists as a marker rather than as another `catch` arm because `OcsErrorMapper` is where the
 * exception-to-status mapping already lives, and because PHP's exception hierarchy makes the obvious
 * approach dangerous: `InvalidArgumentException` extends `LogicException`, so a controller that adds
 * one broad `catch (\Exception)` to handle an unrelated case will silently swallow every validation
 * failure and answer 403 or 422 where the contract requires a 409. T063 shipped exactly that bug, and
 * the fix was to reorder a catch chain that no reader would have suspected.
 *
 * The code is the exception's own, so `ACCOUNT_ALREADY_EXISTS` and `ACCOUNT_VERSION_CONFLICT` stay
 * distinguishable to a client without the mapper growing a table of exception classes it should not
 * know about.
 */
interface Conflict
{
    /**
     * The contract's error code, stable enough for a client to branch on.
     */
    public function errorCode(): string;
}
