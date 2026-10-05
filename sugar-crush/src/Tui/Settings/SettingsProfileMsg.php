<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\App\Msg;

/**
 * A settings-profile export or import finished (roadmap N-P5): the file, what
 * was written or read, or why nothing was. Produced by the shell's Cmd — the
 * file I/O never runs inside `update()` — and handed to the open settings view
 * ({@see SettingsEditor::withProfileResult()}).
 */
final readonly class SettingsProfileMsg implements Msg
{
    public const EXPORT = 'export';
    public const IMPORT = 'import';

    /**
     * @param array<string, mixed> $values what was exported, or the profile read
     */
    private function __construct(
        public string $action,
        public string $path,
        public array $values,
        public ?string $error,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function exported(string $path, array $values): self
    {
        return new self(self::EXPORT, $path, $values, null);
    }

    /** @param array<string, mixed> $profile */
    public static function imported(string $path, array $profile): self
    {
        return new self(self::IMPORT, $path, $profile, null);
    }

    public static function failed(string $action, string $path, string $error): self
    {
        return new self($action, $path, [], $error);
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
