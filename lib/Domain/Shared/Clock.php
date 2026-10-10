<?php

declare(strict_types=1);

namespace OCA\PettyCash\Domain\Shared;

use DateTimeImmutable;

interface Clock { public function now(): DateTimeImmutable; }
