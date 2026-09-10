<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Registry;

/**
 * Signature describing a tool's expected arguments.
 *
 * Canonical home after E14 (docs/plans/crush_code_hardening_backlog.md): a
 * side declaration of `src/ToolRegistry.php` in the ROOT namespace until the
 * registry pair was moved into `SugarCraft\Crush\Registry` so each class is
 * independently PSR-4 loadable. Nothing was deleted.
 *
 * @readonly
 * @immutable
 */
final class ToolSignature
{
    /**
     * @param list<string>                 $positional  Ordered names of positional args
     * @param array<string, bool>         $named       Map of flag-name => whether-it-takes-a-value
     * @param non-empty-string|null       $description Human-readable one-liner
     */
    public function __construct(
        public readonly array $positional = [],
        public readonly array $named = [],
        public readonly ?string $description = null,
    ) {}
}
