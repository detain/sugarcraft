<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Edit;

/**
 * What one `*** … File:` section of an `ApplyPatch` patch does to its path
 * ({@see PatchParser}).
 */
enum PatchAction: string
{
    case Add = 'add';
    case Update = 'update';
    case Delete = 'delete';
}
