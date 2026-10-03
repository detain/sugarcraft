<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools\Catalog;

/**
 * How {@see \SugarCraft\Crush\Permissions\PermissionGate} treats a built-in
 * tool when no `permissionRules` entry decides it first.
 *
 * Every class under `src/Tools/BuiltIn/` declares one through
 * {@see BuiltInTool}, so the gate, `ProtectFilesHook` and the generated
 * documentation read one declaration instead of three hand-kept name lists.
 *
 * - `Read`: changes nothing and reaches nothing outside the process. Allowed
 *   unasked in every mode.
 * - `Write`: can change the tree or run what can (`Bash`, `Edit`, `Write`,
 *   `Task`). Asks under `default`, denied under `plan` and `dont-ask`.
 * - `Ask`: neither of the above, but reaches something the gate cannot judge
 *   (an outbound request, a capability probe, a skill body). Asks under
 *   `default`, `accept-edits` and `plan`; denied under `dont-ask`.
 * - `NoAsk`: writes only state the harness owns (the memory directories), so
 *   a prompt would protect nothing. Allowed in every mode. Explicit rules and
 *   hooks still run first.
 */
enum ToolPermissionClass: string
{
    case Read = 'read';
    case Write = 'write';
    case Ask = 'ask';
    case NoAsk = 'no-ask';

    /** The label the generated documentation prints for this class. */
    public function label(): string
    {
        return match ($this) {
            self::Read => 'read-only',
            self::Write => 'write-capable',
            self::Ask => 'ask',
            self::NoAsk => 'no-ask',
        };
    }
}
