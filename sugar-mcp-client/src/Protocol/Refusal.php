<?php

declare(strict_types=1);

namespace SugarCraft\McpClient\Protocol;

/**
 * A refused `candy-code.v1` call, read the same way on the wire and in-process.
 *
 * The wire answers a refusal as a JSON-RPC error whose `data.kind` names it, and an
 * in-process host rejects with the same kind and data. A bundle branches on this
 * interface rather than on a concrete error class, so a refusal keeps its kind and its
 * `retryable` hint whichever copy of the error type raised it. Plugin libraries extend
 * this interface (`SugarCraft\Plugins\Host\RefusedCall`) instead of redeclaring it.
 */
interface Refusal
{
    /** The refusal's kind, the wire's `data.kind` (for example `session_not_found`). */
    public function refusalKind(): string;

    /** @return array<string, mixed> the refusal's data object, as the wire carries it */
    public function refusalData(): array;
}
