<?php

declare(strict_types=1);

namespace SugarCraft\Code\Commands;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\PasteMsg;
use SugarCraft\Code\App\ErrorMsg;
use SugarCraft\Code\App\StatusMsg;
use SugarCraft\Code\Lang;

/**
 * The `/editor` command (roadmap 5.14h): compose the next prompt in the user's
 * own editor — `$VISUAL`, else `$EDITOR`, else `vi` (`notepad` on Windows),
 * the precedence git and every other Unix tool that opens an editor uses.
 *
 * HOW IT RUNS. {@see cmd()} writes the starting text (the command's argument,
 * if any) to an owner-only temp file and returns sugar-core's {@see Cmd::exec()}
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
     * The Cmd that opens $path in the user's editor IN PLACE (VIS-2b,
     * `/memory open`): `Cmd::exec` and nothing else — the terminal hand-back
     * is exactly what the exec channel performs, and routing the child any
     * other way would run an editor with no terminal. There is deliberately
     * no PasteMsg round-trip here: the file is its own destination, and the
     * memory store re-reads from disk on every call, so the edit lands
     * without this class ever handing the bytes back to the transcript.
     *
     * The optional `$onSaved` closure (C-CTX-6.d) is the journaling hook:
     * it runs exactly once when the editor exits 0 AND the file's hash
     * differs from the one read here, before the terminal is handed over.
     * Without it an edit made in the editor reaches the memory history only
     * when some later `/memory` command sweeps it into an outside-/memory
     * commit; `Chat::memoryOpenArm()` passes one that records the note
     * under its own subject the moment the editor is gone.
     */
    public function openFile(string $path, ?\Closure $onSaved = null): \Closure
    {
        $editor = $this->editor();
        // VIS-2b opens may name a file the editor itself creates, so absence
        // is the ordinary arm-time shape, not an error: false is the sentinel
        // openCollect() compares against. The is_file() door keeps the
        // expected-absent case from tripping a PHP warning under
        // failOnWarning.
        $hashBefore = \is_file($path) ? \sha1_file($path) : false;

        return Cmd::exec(
            $editor . ' ' . escapeshellarg($path),
            false,
            static fn (int $exit, string $out, string $err, ?\Throwable $error): Msg => self::openCollect($path, $editor, $exit, $error, $onSaved, $hashBefore),
        );
    }

    /**
     * The single transcript row an in-place open answers with once the
     * editor is gone: which file was closed, or how it failed. Public for
     * the same reason collect() is — exercisable without a terminal.
     *
     * `$onSaved` and `$hashBefore` arrive as a pair from {@see openFile()}
     * (C-CTX-6.d): the closure runs only on a clean exit and only when the
     * file's hash no longer matches the pre-hand-over one — the user really
     * edited. It may answer a warning line for the transcript (the shape
     * {@see MemoryHistoryCommand::record()} returns); a warning rides under
     * the opened row rather than being swallowed.
     */
    public static function openCollect(
        string $path,
        string $editor,
        int $exit,
        ?\Throwable $error,
        ?\Closure $onSaved = null,
        string|false $hashBefore = false,
    ): Msg {
        if ($error !== null) {
            return new ErrorMsg(Lang::t('cmd.editor.open-not-started', [
                'editor' => $editor,
                'error' => $error->getMessage(),
            ]));
        }

        if ($exit !== 0) {
            return new ErrorMsg(Lang::t('cmd.editor.open-failed', [
                'editor' => $editor,
                'status' => (string) $exit,
            ]));
        }

        $message = Lang::t('cmd.editor.opened', ['path' => $path]);
        // The same absence-tolerant read as openFile()'s: an editor quit
        // before creating the file leaves it false === false, no edit.
        if ($onSaved !== null && (\is_file($path) ? \sha1_file($path) : false) !== $hashBefore) {
            $warning = $onSaved();
            if (\is_string($warning) && trim($warning) !== '') {
                $message .= "\n" . $warning;
            }
        }

        return new StatusMsg($message);
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
