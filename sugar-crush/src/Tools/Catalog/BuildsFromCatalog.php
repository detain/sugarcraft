<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

use SugarCraft\Crush\Tools\Tool;

/**
 * A built-in tool {@see ToolCatalog::build()} can construct on its own.
 *
 * The factory takes the launch's shared {@see ToolBuildContext} and picks the
 * arguments it needs, so adding a tool means adding its file, not editing
 * `Bootstrap::unfilteredTools()`. Returning null keeps the tool off this
 * launch (a dependency the context does not carry).
 */
interface BuildsFromCatalog
{
    public static function fromCatalog(ToolBuildContext $context): ?Tool;
}
