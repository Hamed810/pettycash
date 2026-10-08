<?php

declare(strict_types=1);

namespace OCA\PettyCash\Application\Port;

interface AuditWriter
{
    /**
     * Append an event to the transaction audit stream.
     *
     * @param array<string,mixed> $event
     */
    public function append(array $event): void;

    /**
     * Append an event to the configuration audit stream (FR-055).
     *
     * Configuration events identify their subject by entity_type/entity_id, never by
     * sheet/transaction, so they cannot be appended through the transaction stream.
     *
     * @param array<string,mixed> $event
     */
    public function appendConfiguration(array $event): void;

    /**
     * Append a configuration event on behalf of a viewer who is **not** the signed-in user.
     *
     * Exists for FR-031's public evidence link consumption, which is unauthenticated by design and
     * records its viewer as `anonymous`. `appendConfiguration()` cannot serve it: the writer resolves the
     * actor from the session, and `NextcloudCurrentUser::uid()` throws for an unauthenticated request.
     *
     * It is a separate port method rather than an `actor_uid` key on the payload because every other
     * caller already passes `actor_uid` and the writer deliberately ignores it. Making the override a key
     * would silently let any producer attribute an event to somebody other than the session, which is the
     * one property an audit trail must not have.
     *
     * @param string $viewerUid never empty; `anonymous` for an unauthenticated request
     * @param array<string,mixed> $event
     */
    public function appendConfigurationAsViewer(string $viewerUid, array $event): void;
}
