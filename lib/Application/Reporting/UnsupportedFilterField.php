<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Reporting;

use InvalidArgumentException;

/**
 * A report filter naming a field the report's rows do not carry.
 *
 * ## Why this exists
 *
 * `ReportQuery::applyFilter()` used to read fixed row keys (`currencyCode`, `actorUid`,
 * `costCenterDbId`, …) while the report and export producers emitted different ones (`currency`,
 * `ownerUid`). A filter on a mismatched key compared `''` against the requested value, matched nothing,
 * and returned an **empty page with a 200** — so filtering `/reports/sheets` by the currency every
 * sheet actually had answered "you have no sheets" (T307).
 *
 * A filter that cannot be evaluated must be refused, not answered. Silently returning zero rows is the
 * most damaging thing a report endpoint can do, because it is indistinguishable from a true result and
 * the caller has no way to tell that their question was never asked.
 */
final class UnsupportedFilterField extends InvalidArgumentException
{
}
