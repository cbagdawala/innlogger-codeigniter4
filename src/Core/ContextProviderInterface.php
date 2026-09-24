<?php

declare(strict_types=1);

namespace InnLogger\CodeIgniter4\Core;

/**
 * Supplies request-scoped fields for each event. Recognised keys:
 * url, http_method, http_status, request_id, user_id, route.
 * Implementations must not throw (the client guards anyway).
 */
interface ContextProviderInterface
{
    /**
     * @return array{url?: string|null, http_method?: string|null, http_status?: int|null, request_id?: string|null, user_id?: int|string|null, route?: string|null}
     */
    public function context(): array;
}
