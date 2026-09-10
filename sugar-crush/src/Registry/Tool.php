<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Registry;

use SugarCraft\Crush\ToolResult;

/**
 * A registered tool/command with metadata and an execute handler.
 *
 * Canonical home after E14 (docs/plans/crush_code_hardening_backlog.md): this
 * class used to be a side declaration of `src/ToolRegistry.php` in the ROOT
 * namespace, one `use` away from colliding with the `SugarCraft\Crush\Tools\Tool`
 * interface every live tool implements. Moving it into a distinct namespace
 * makes that collision unconstructible; nothing was deleted. The result type
 * stays the root-namespace pair (`SugarCraft\Crush\ToolResult`) the registry
 * has always spoken — see `ToolRegistry::execute()`.
 *
 * @readonly
 * @immutable
 */
final class Tool
{
    /**
     * @param non-empty-string                          $name       Unique lowercase identifier
     * @param ToolSignature                             $signature  Arg signature
     * @param callable(array<string, mixed>): ToolResult $execute   Handler receiving named args, returning ToolResult
     */
    public function __construct(
        public readonly string $name,
        public readonly ToolSignature $signature,
        #[\SensitiveParameter]
        private readonly mixed $execute,
    ) {}

    /**
     * Invoke the tool with the given arguments.
     *
     * @param array<string, mixed> $args
     */
    public function execute(array $args): ToolResult
    {
        return ($this->execute)($args);
    }
}
