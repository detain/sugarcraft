<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Emitted once at startup carrying the originating process
 * environment. Useful for SSH-aware programs (`SSH_TTY` /
 * `SSH_CONNECTION`), locale handling (`LANG` / `LC_ALL`), and any
 * model that previously poked `getenv()` at startup.
 */
final class EnvMsg implements Msg
{
    /** @param array<string,string> $vars */
    public function __construct(
        public readonly array $vars,
    ) {
    }

    /** Convenience accessor with optional default. */
    public function get(string $key, ?string $default = null): ?string
    {
        return $this->vars[$key] ?? $default;
    }
}
