<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Commands;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Crush\App\ErrorMsg;
use SugarCraft\Crush\App\StatusMsg;
use SugarCraft\Crush\Lang;

/**
 * The `/editor` command (roadmap 5.14h): compose the next prompt in the user's
 * own editor — `$VISUAL`, else `$EDITOR`, else `vi` (`notepad` on Windows),
 * the precedence git and every other Unix tool that opens an editor uses.
 *
 * HOW IT RUNS. {@see cmd()} writes the starting text (the command's argument,
 * if any) to an owner-only temp file and returns candy-core's {@see Cmd::exec()}
 * for `<editor> <file>`: the Program hands the terminal over — raw mode, the
 * alternate screen and the hidden cursor are all torn down — runs the editor
 * in the foreground with the real TTY, and restores the frame when it exits.
 * The completion hook then reads the file back, deletes it, and answers with
 * ONE {@see PasteMsg} carrying the text, so it lands in the draft box through
 * the box's own paste arm — at the caret, replacing a selection, as one edit —
 * and is NOT sent: the Enter that sends it is still the user's.
 *
 * A failure says so instead of vanishing: an editor that would not start or
 * exited non-zero answers with an {@see ErrorMsg} naming the command and the
 * status, and an empty file with a {@see StatusMsg}; the temp file is removed
 * either way.
 *
 * WHY THE EDITOR IS A SHELL STRING. `$EDITOR` is conventionally a command
 * line, not a path — `code --wait`, `emacsclient -t`, `nvim -u NONE` — and
 * every tool that reads it runs it through `sh -c`. The FILE is the only
 * operand this class adds, and it is passed through `escapeshellarg()`.
 *
 * This is a SugarCraft architecture type, not a port: charmbracelet/crush has
 * no `/editor`; the command is Aider's.
 */
final class EditorCommand
{
    private const TEMP_PREFIX = 'sc_editor_';

    /**
     * @param array<string, string|false> $env `VISUAL`/`EDITOR` as read; injectable for tests
     */
    private function __construct(
        private readonly array $env,
        private readonly bool $windows,
        private readonly string $tempDir,
    ) {
    }

    public static function new(): self
    {
        return new self(
            ['VISUAL' => getenv('VISUAL'), 'EDITOR' => getenv('EDITOR')],
            \PHP_OS_FAMILY === 'Windows',
            sys_get_temp_dir(),
        );
    }

    /**
     * A copy reading $env instead of the process environment, and writing its
     * temp file under $tempDir.
     *
     * @param array<string, string|false> $env
     */
    public function withEnvironment(array $env, ?string $tempDir = null): self
    {
        return new self($env, $this->windows, $tempDir ?? $this->tempDir);
    }

    /** The editor command line: `$VISUAL`, else `$EDITOR`, else the platform default. */
    public function editor(): string
    {
        foreach (['VISUAL', 'EDITOR'] as $name) {
            $value = $this->env[$name] ?? false;
            if (\is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $this->windows ? 'notepad' : 'vi';
    }

    /**
     * The Cmd that opens the editor on $initial and feeds the result back, or
     * an {@see ErrorMsg} Cmd when the temp file cannot be prepared.
     */
    public function cmd(string $initial = ''): \Closure
    {
        $path = $this->prepareFile($initial);
        if ($path === null) {
            return Cmd::send(new ErrorMsg(Lang::t('cmd.editor.no-temp-file', ['dir' => $this->tempDir])));
        }

        $editor = $this->editor();

        return Cmd::exec(
            $editor . ' ' . escapeshellarg($path),
            false,
            static fn (int $exit, string $out, string $err, ?\Throwable $error): Msg => self::collect($path, $editor, $exit, $error),
        );
    }

    /**
     * What the editor left behind, as the Msg the draft box takes. Public so
     * the completion can be exercised without a terminal to hand over.
     */
    public static function collect(string $path, string $editor, int $exit, ?\Throwable $error): Msg
    {
        $text = is_file($path) ? file_get_contents($path) : false;
        @unlink($path);

        if ($error !== null) {
            return new ErrorMsg(Lang::t('cmd.editor.not-started', ['editor' => $editor, 'error' => $error->getMessage()]));
        }
        if ($exit !== 0) {
            return new ErrorMsg(Lang::t('cmd.editor.failed', ['editor' => $editor, 'status' => $exit]));
        }

        // An editor terminates the last line with a newline the user did not
        // mean as part of the prompt; inner newlines are kept as typed.
        $text = $text === false ? '' : rtrim($text, "\r\n");
        if (trim($text) === '') {
            return new StatusMsg(Lang::t('cmd.editor.empty'));
        }

        return new PasteMsg($text);
    }

    /**
     * An owner-only `sc_editor_*.md` file holding $initial, or null. The `.md`
     * suffix is for the editor's syntax highlighting; `tempnam()` creates the
     * name exclusively at 0600 and the rename keeps that inode.
     */
    private function prepareFile(string $initial): ?string
    {
        // tempnam() silently falls back to the system temp directory when the
        // one it is given does not exist; checked first so the file lands
        // where this object says it does.
        if (!is_dir($this->tempDir)) {
            return null;
        }

        $base = @tempnam($this->tempDir, self::TEMP_PREFIX);
        // Compared resolved, because a temp dir reached through a symlink
        // (macOS's /var -> /private/var) is still the one asked for.
        if ($base === false || realpath(\dirname($base)) !== realpath($this->tempDir)) {
            if (\is_string($base)) {
                @unlink($base);
            }

            return null;
        }

        $path = $base . '.md';
        if (!@rename($base, $path)) {
            @unlink($base);

            return null;
        }

        if (@file_put_contents($path, $initial) === false) {
            @unlink($path);

            return null;
        }

        return $path;
    }
}
