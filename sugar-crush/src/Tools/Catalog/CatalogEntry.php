<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

/**
 * One built-in tool as {@see ToolCatalog} found it.
 *
 * `externallyWired` marks a tool the catalog classifies but does not build:
 * `Bootstrap::tools()` appends it after the `allowedTools` / `disabledTools`
 * filter, and only when the launch holds what it needs. `onLaunch` false marks
 * one no launch carries at all — it reaches only the runs that are handed it
 * (the shared-board tools a parallel `Task` batch's members get).
 */
final readonly class CatalogEntry
{
    /**
     * @param class-string<\SugarCraft\Crush\Tools\Tool> $class
     */
    public function __construct(
        public string $class,
        public string $name,
        public ToolPermissionClass $permission,
        public int $position,
        public string $gloss = '',
        public bool $externallyWired = false,
        public bool $onLaunch = true,
    ) {
    }

    /** The class's file name under `src/Tools/BuiltIn/`. */
    public function fileName(): string
    {
        $pos = strrpos($this->class, '\\');

        return ($pos === false ? $this->class : substr($this->class, $pos + 1)) . '.php';
    }
}
