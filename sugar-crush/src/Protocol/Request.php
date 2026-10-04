<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Protocol;

/**
 * One decoded client message: a request (it has an `id`, and is answered) or
 * a notification (it has none, and never is). The `id` is kept exactly as the
 * client spelled it — string or integer — because a JSON-RPC response must
 * echo it unchanged.
 */
final class Request
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly string|int|null $id,
        public readonly bool $hasId,
        public readonly string $method,
        public readonly array $params,
    ) {
    }

    public function isNotification(): bool
    {
        return !$this->hasId;
    }
}
