<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

/**
 * A refusal a {@see HostServices} call rejects with: the wire's refusal, in-process.
 *
 * The wire answers a refused request with a JSON-RPC error whose `data.kind` names the
 * refusal. An in-process host rejects with the same kind and data, so a bundle reads a
 * refusal the same way on both doors. The host's own error type implements this interface;
 * the plugin libraries never name the host's class.
 */
interface RefusedCall
{
    /** The refusal's kind, the wire's `data.kind` (for example `session_not_found`). */
    public function refusalKind(): string;

    /** @return array<string, mixed> the refusal's data object, as the wire carries it */
    public function refusalData(): array;
}
