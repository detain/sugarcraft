<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Host;

use SugarCraft\McpClient\Protocol\Refusal;

/**
 * A refusal a {@see HostServices} call rejects with: the wire's refusal, in-process.
 *
 * The wire answers a refused request with a JSON-RPC error whose `data.kind` names the
 * refusal. An in-process host rejects with the same kind and data, so a bundle reads a
 * refusal the same way on both doors. The host's own error type implements this interface;
 * the plugin libraries never name the host's class.
 *
 * It extends the protocol's {@see Refusal} so a bundle that checks the library interface
 * also accepts a refusal raised by the host's copy of the error type.
 */
interface RefusedCall extends Refusal
{
}
