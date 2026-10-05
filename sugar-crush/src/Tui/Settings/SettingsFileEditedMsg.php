<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Crush\App\Msg;

/**
 * The editor the settings view opened a settings file in has exited (roadmap
 * N-P5, "open the file in `$EDITOR`"): which file, what it held before, and
 * whether the editor ran. The shell compares the file with {@see $before}, re-reads
 * the layers and applies what changed, the way a save from the view does.
 */
final readonly class SettingsFileEditedMsg implements Msg
{
    /**
     * @param array<string, mixed> $before the file's object when the editor opened it
     */
    public function __construct(
        public string $path,
        public array $before,
        public string $editor,
        public ?string $error = null,
    ) {
    }

    public function ok(): bool
    {
        return $this->error === null;
    }
}
