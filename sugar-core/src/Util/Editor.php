<?php

declare(strict_types=1);

namespace SugarCraft\Core\Util;

use SugarCraft\Core\Lang;
use SugarCraft\Pty\Posix\PosixProcess;

/**
 * Spawn the user's `$EDITOR` against a seeded temp file and return the
 * edited contents. Mirrors charmbracelet/x/editor.Cmd.
 *
 * Discovery chain: explicit override → `$VISUAL` → `$EDITOR` → platform
 * defaults (`vi` then `nano` on POSIX, `notepad` on Windows). Splits the
 * env-var value on whitespace so `EDITOR='vim -p'` keeps the `-p` flag.
 *
 * STDOUT/STDERR are inherited — the editor takes over the controlling
 * terminal for its lifetime. STDIN is the RUNNER's business (sugar-core-055):
 * the default runner spawns through sugar-pty's PosixProcess, whose contract
 * binds fd 0 to `/dev/null` so a supervised child can never steal the
 * controlling shell's stdin. Interactive editors are unaffected — vim, nano
 * and friends reopen `/dev/tty` for the UI — but an editor driven by
 * stdin-fed scripts (`gawk -f`, here-doc pipelines into the `$EDITOR`) sees
 * immediate EOF through this arm. A caller that truly owns the terminal may
 * inject a stdin-inheriting runner via {@see setRunner()}.
 * Windows posture: the `notepad` default arm is behind sugar-core-049 and
 * untested on Linux CI hosts — the POSIX discovery chain is the proven path.
 * Callers that own the altscreen (e.g. sugar-core's `Program`) must suspend
 * the renderer around this call; that integration is the responsibility of
 * `Cmd::exec()` / `ExecRequest`, not this helper.
 *
 * Throws `\RuntimeException` on every error path: editor not found,
 * non-zero exit (e.g. vim `:cq`), unreadable temp file. Tests inject a
 * fake runner via {@see setRunner()} to avoid spawning real editors.
 */
final class Editor
{
    /** @var \Closure(list<string>): int|null */
    private static ?\Closure $runner = null;

    /**
     * Spawn the editor on a temp file seeded with `$seed` and return
     * the file contents after the editor exits successfully.
     *
     * `$extension` controls the temp-file suffix so syntax-aware
     * editors pick the right ftplugin (`.md` for markdown, `.json`
     * for JSON, etc). Pass `''` to skip the suffix entirely. A suffix
     * is a LABEL, never a path: after the optional leading dot it must
     * match `^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$` — slashes, `..` and other
     * traversal shapes are refused with InvalidArgumentException
     * (sugar-core-050: the pre-validation arm renamed the seeded temp file
     * outside the temp directory).
     *
     * `$editor` overrides the discovery chain — useful for unit tests
     * and for callers that need a fixed editor regardless of env.
     */
    public static function edit(
        string $seed = '',
        string $extension = '.txt',
        ?string $editor = null,
    ): string {
        $argv = self::discover($editor);
        $tmp  = self::makeTempFile($extension);

        try {
            if (@file_put_contents($tmp, $seed) === false) {
                throw new \RuntimeException("Could not seed editor temp file: {$tmp}");
            }

            $runner = self::$runner ?? self::defaultRunner();
            $exit   = $runner([...$argv, $tmp]);
            if ($exit !== 0) {
                throw new \RuntimeException("Editor exited with status {$exit}");
            }

            $content = @file_get_contents($tmp);
            if ($content === false) {
                throw new \RuntimeException("Could not read editor output file: {$tmp}");
            }
            return $content;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Resolve the editor argv prefix that {@see edit()} would spawn.
     * Exposed for tests that want to assert discovery without running
     * a process; production callers should use {@see edit()}.
     *
     * @return list<string>
     */
    public static function command(?string $editor = null): array
    {
        return self::discover($editor);
    }

    /**
     * Inject a custom runner. The runner receives the full argv
     * (editor binary + extra flags + temp-file path) and returns the
     * child's exit status. Pass `null` to restore the default
     * `proc_open`-backed runner. Returns the previously installed
     * runner so tests can restore it.
     *
     * @param \Closure(list<string>): int|null $runner
     * @return \Closure(list<string>): int|null
     */
    public static function setRunner(?\Closure $runner): ?\Closure
    {
        $prev = self::$runner;
        self::$runner = $runner;
        return $prev;
    }

    /** @return list<string> */
    private static function discover(?string $override): array
    {
        $candidates = $override !== null
            ? [$override]
            : [
                self::envOrNull('VISUAL'),
                self::envOrNull('EDITOR'),
                DIRECTORY_SEPARATOR === '\\' ? 'notepad' : 'vi',
                DIRECTORY_SEPARATOR === '\\' ? null : 'nano',
            ];

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === '') {
                continue;
            }
            $argv = self::splitArgv($candidate);
            if ($argv === []) {
                continue;
            }
            $bin = self::which($argv[0]);
            if ($bin !== null) {
                $argv[0] = $bin;
                return $argv;
            }
        }

        throw new \RuntimeException(
            'No usable editor found ($VISUAL/$EDITOR/'
            . (DIRECTORY_SEPARATOR === '\\' ? 'notepad' : 'vi/nano')
            . ').',
        );
    }

    private static function envOrNull(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return null;
        }
        return $value;
    }

    /** @return list<string> */
    private static function splitArgv(string $cmd): array
    {
        $parts = preg_split('/\s+/', trim($cmd));
        if ($parts === false) {
            return [$cmd];
        }
        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }

    private static function which(string $cmd): ?string
    {
        if ($cmd === '') {
            return null;
        }

        $hasSep = str_contains($cmd, DIRECTORY_SEPARATOR)
            || (DIRECTORY_SEPARATOR === '\\' && str_contains($cmd, '/'));
        if ($hasSep) {
            return is_file($cmd) && is_executable($cmd) ? $cmd : null;
        }

        $shell = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($cmd) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($cmd) . ' 2>/dev/null';
        $out = @shell_exec($shell);
        if (!is_string($out)) {
            return null;
        }
        $first = strtok(trim($out), "\r\n");
        // strtok returns false when string is empty, otherwise a non-empty token
        return $first !== false ? $first : null;
    }

    private static function makeTempFile(string $extension): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sc-editor-');
        if ($base === false) {
            throw new \RuntimeException('Could not create temp file for editor.');
        }

        $ext = ltrim($extension, '.');
        if ($ext === '') {
            return $base;
        }

        // sugar-core-050: the suffix used to ride straight into
        // rename($base, $base . '.' . $ext) — a `/`-bearing or `..`-bearing
        // "extension" walked the seeded temp file OUT of sys_get_temp_dir().
        // A suffix is a label, never a path: shape-validate, refuse by name.
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/', $ext) !== 1) {
            @unlink($base);
            throw new \InvalidArgumentException(Lang::t('editor.extension_invalid', [
                'extension' => $extension,
            ]));
        }

        $renamed = $base . '.' . $ext;
        if (!@rename($base, $renamed)) {
            @unlink($base);
            throw new \RuntimeException("Could not rename temp file to {$renamed}.");
        }
        return $renamed;
    }

    /** @return \Closure(list<string>): int */
    private static function defaultRunner(): \Closure
    {
        return static function (array $argv): int {
            $proc = PosixProcess::spawn($argv);
            return $proc->wait();
        };
    }
}
