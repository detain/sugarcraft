<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\DirectoryPicker;

/**
 * What a key in the `/new` folder picker ({@see DirectoryPicker}) asks its
 * host ({@see \SugarCraft\Crush\Chat}) to do: close it, start a new session
 * in this process (the chosen directory IS the current project root), or
 * restart sugar-crush in another directory (confirmed in the picker first).
 */
final class DirectoryPickerAction
{
    public const CANCEL = 'cancel';

    public const HERE = 'here';

    public const RELAUNCH = 'relaunch';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $path,
    ) {
    }

    public static function cancel(): self
    {
        return new self(self::CANCEL, null);
    }

    public static function here(string $path): self
    {
        return new self(self::HERE, $path);
    }

    public static function relaunch(string $path): self
    {
        return new self(self::RELAUNCH, $path);
    }
}
