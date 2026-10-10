<?php

declare(strict_types=1);

namespace OCA\PettyCash\Infrastructure\Nextcloud;

use OCA\PettyCash\Application\Port\RequestRoute;
use OCP\IRequest;

/**
 * `RequestRoute` over Nextcloud's request. FR-087.
 *
 * The only place in this chain that knows `OCP\IRequest` exists, which is the point of the port: the
 * application layer states what a fault must record, and this adapter is the only part that has to know how
 * to ask the platform for it.
 */
final readonly class NextcloudRequestRoute implements RequestRoute
{
    public function __construct(private IRequest $request) {}

    /**
     * Method first, then path.
     *
     * `GET` and `DELETE` on `/transactions/{id}` fail for entirely different reasons, and an operator
     * reading a log line needs to know which was attempted. `getPathInfo()` rather than `getRequestUri()`
     * because the latter includes the query string — which for an export request could carry a title, an
     * account name, or a date range, and a fault record is not the place for caller-supplied values.
     */
    public function describe(): string
    {
        return $this->request->getMethod() . ' ' . $this->request->getPathInfo();
    }
}