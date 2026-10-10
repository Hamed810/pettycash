<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Account;

use RuntimeException;

/**
 * The named account is not there.
 *
 * Distinct from a refusal because the contract's answer differs: 404 for something that does not
 * exist, 403 for something the caller may not touch. `listMovements()` answers both cases identically
 * so its endpoint cannot be used to discover which account ids are real — but a charge is a write
 * with no read to leak, so naming the account as missing tells the caller nothing they could not get
 * by trying.
 */
final class UnknownAccount extends RuntimeException {}
