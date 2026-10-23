<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

/**
 * The `--cursor-mode` vocabulary shared by the interactive text commands
 * (`input`, `write`). gum accepts exactly blink | static | hidden; the
 * first two leave the terminal's own cursor settings in place (sugar-core
 * shows the cursor for text entry), while `hidden` asks the Program to
 * hide it via {@see \SugarCraft\Core\ProgramOptions::$hideCursor}.
 */
final class CursorMode
{
    /** @var list<string> */
    public const KNOWN = ['blink', 'static', 'hidden'];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN, true);
    }

    public static function hidesCursor(string $mode): bool
    {
        return $mode === 'hidden';
    }
}
