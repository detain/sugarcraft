<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

/**
 * The declaration every built-in tool class carries, read by {@see ToolCatalog}.
 *
 * - `name` is the runtime (wire) name the model, `allowedTools` /
 *   `disabledTools` and `permissionRules` use. It is declared here, not only in
 *   `Tool::name()`, so the permission gate can classify a call without building
 *   the tool; `ToolCatalogTest` pins the two spellings together.
 * - `permission` is the tool's {@see ToolPermissionClass}.
 * - `position` is the tool's place in the wire order. The model has learned
 *   the existing order, so a new tool takes the next free number rather than
 *   an earlier one.
 * - `gloss` is the parenthesised aside the README tool roster prints after
 *   the name, empty for none.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class BuiltInTool
{
    public function __construct(
        public string $name,
        public ToolPermissionClass $permission,
        public int $position,
        public string $gloss = '',
    ) {
    }
}
