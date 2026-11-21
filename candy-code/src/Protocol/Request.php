<?php

declare(strict_types=1);

namespace SugarCraft\Code\Protocol;

use SugarCraft\McpClient\Protocol\Request as LibRequest;

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

    /**
     * The client message the library codec decoded, as this copy's type. The fields are the
     * same; the wire ids and params pass through untouched.
     */
    public static function fromLibrary(LibRequest $request): self
    {
        return new self($request->id, $request->hasId, $request->method, $request->params);
    }
}
