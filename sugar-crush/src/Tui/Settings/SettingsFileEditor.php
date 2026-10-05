<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Settings;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Crush\Commands\EditorCommand;
use SugarCraft\Crush\Lang;

/**
 * Opens a settings file in the user's own editor from the settings view
 * (roadmap N-P5; Appendix N §5.4 "open file in $EDITOR") — the editor `/editor`
 * uses ({@see EditorCommand::editor()}: `$VISUAL`, else `$EDITOR`, else the
 * platform default), run through candy-core's {@see Cmd::exec()}, which hands
 * the terminal over and restores the frame when it exits.
 *
 * The file itself is the operand, through `escapeshellarg()` — the editor line
 * is a shell string by convention (`code --wait`), the path is not. The
 * completion answers ONE {@see SettingsFileEditedMsg} carrying what the file
 * held before, so the shell can tell which settings the edit changed.
 */
final class SettingsFileEditor
{
    private function __construct(private readonly EditorCommand $editor)
    {
    }

    public static function new(?EditorCommand $editor = null): self
    {
        return new self($editor ?? EditorCommand::new());
    }

    /** The editor command line this opens files with. */
    public function editor(): string
    {
        return $this->editor->editor();
    }

    /**
     * The Cmd that opens `$path` and reports back.
     *
     * @param array<string, mixed> $before what the file holds now
     */
    public function cmd(string $path, array $before): \Closure
    {
        $editor = $this->editor();

        return Cmd::exec(
            $editor . ' ' . escapeshellarg($path),
            false,
            static fn (int $exit, string $out, string $err, ?\Throwable $error): Msg => self::collect($path, $before, $editor, $exit, $error),
        );
    }

    /**
     * The editor's outcome as the Msg the shell takes. Public so the
     * completion can be exercised without a terminal to hand over.
     *
     * @param array<string, mixed> $before
     */
    public static function collect(string $path, array $before, string $editor, int $exit, ?\Throwable $error): SettingsFileEditedMsg
    {
        return match (true) {
            $error !== null => new SettingsFileEditedMsg($path, $before, $editor, Lang::t('tui.settings.editor.not_started', ['editor' => $editor, 'error' => $error->getMessage()])),
            $exit !== 0 => new SettingsFileEditedMsg($path, $before, $editor, Lang::t('tui.settings.editor.exit_status', ['editor' => $editor, 'status' => $exit])),
            default => new SettingsFileEditedMsg($path, $before, $editor),
        };
    }
}
