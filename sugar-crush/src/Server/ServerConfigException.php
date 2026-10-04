<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Server;

/**
 * A `sugarcrush serve` setting that cannot be honoured: a port that is not a
 * port, an origin that is not an origin, a non-loopback bind without
 * `--allow-remote`. The message is the user-facing sentence; the CLI reports it
 * at exit 2 because nothing was attempted and a retry cannot help.
 */
final class ServerConfigException extends \InvalidArgumentException
{
}
