<?php

declare(strict_types=1);

namespace SugarCraft\Git;

/**
 * The OS seam under {@see Process}: every place the subprocess runner differs
 * between POSIX and Windows is answered here, and nowhere else.
 *
 * WHY A SEAM. Three POSIX assumptions were hard-coded in Process: the
 * `/dev/null` stdin spec, `stream_select` over child pipes, and
 * `proc_terminate(15)`. On Windows the null device is `NUL`, `stream_select`
 * cannot poll a `proc_open` pipe (it reports ready then blocks in fread), and
 * there is no SIGTERM — `taskkill /T` is the only tree-aware stop. Because the
 * values are injected, both ladders run in the Linux test suite
 * (`Platform::windows()->withNullDevice('/dev/null')`) and the Windows CI leg
 * (`WINDOWS_LIBS` in scripts/affected-libs.php) verifies the real thing.
 *
 * Immutable: every `with*()` returns a new instance.
 */
final class Platform
{
    /** @var \Closure(int): void */
    private \Closure $killTree;

    /**
     * @param \Closure(int): void|null $killTree Force-stops the child process tree rooted at a pid; default `taskkill /F /T`.
     */
    private function __construct(
        public readonly bool $windows,
        public readonly string $nullDevice,
        ?\Closure $killTree = null,
    ) {
        $this->killTree = $killTree ?? static function (int $pid): void {
            if ($pid <= 0) {
                return;
            }
            // Git-for-Windows bash first: its fork emulation leaves backgrounded
            // children with no living Windows parent, so taskkill's tree walk
            // cannot reach them; the MSYS process-group kill does (it runs the
            // taskkill walk itself first). A pid that is no MSYS process falls
            // through to the plain walk below.
            $bash = self::msysBash();
            if ($bash !== '' && self::runBounded([$bash, '-c', self::MSYS_GROUP_KILL_SCRIPT, 'msys-group-kill', (string) $pid])) {
                return;
            }
            self::runBounded(['taskkill', '/F', '/T', '/PID', (string) $pid]);
        };
    }

    /** Run under Git-for-Windows bash with $1 = the Windows pid; exit 0 = the group was found and killed. */
    private const MSYS_GROUP_KILL_SCRIPT = 'w=$1; for d in /proc/[0-9]*; do '
        . 'read -r x < "$d/winpid" 2>/dev/null || continue; '
        . '[ "$x" = "$w" ] || continue; '
        . 'read -r g < "$d/pgid" 2>/dev/null || exit 1; '
        . 'MSYS_NO_PATHCONV=1 taskkill /T /F /PID "$w" >/dev/null 2>&1; '
        . 'kill -9 -- "-$g" 2>/dev/null; '
        . 'exit 0; done; exit 1';

    /** How long a killer child may run before it is itself stopped. */
    private const KILLER_BOUND_SECONDS = 10.0;

    /**
     * A Git/MSYS bash.exe — never System32's WSL launcher — or '' when the host
     * has none: PATH first, then the stock installer location.
     */
    private static function msysBash(): string
    {
        $found = self::windows()->findExecutable('bash');
        if ($found !== null && !str_contains(strtolower($found), '\\system32\\')) {
            return $found;
        }
        $programFiles = getenv('ProgramFiles');
        if ($programFiles === false || $programFiles === '') {
            return '';
        }
        $candidate = rtrim($programFiles, '\\/') . '\\Git\\bin\\bash.exe';

        return is_file($candidate) ? $candidate : '';
    }

    /**
     * Spawn $argv (a list, no shell), discard its output under a bound with
     * pipes + polling (never a blocking read), and report exit status 0.
     *
     * @param non-empty-list<string> $argv
     */
    private static function runBounded(array $argv): bool
    {
        $proc = @proc_open($argv, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return false;
        }
        fclose($pipes[0]);
        $drained = \SugarCraft\Core\Util\Proc\WindowsPipes::drainUntilExit(
            [1 => $pipes[1], 2 => $pipes[2]],
            \SugarCraft\Core\Util\Proc\WindowsPipes::runningProbe($proc),
            microtime(true) + self::KILLER_BOUND_SECONDS,
            0,
        );
        if ($drained['timedOut']) {
            proc_terminate($proc);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($proc) === 0;
    }

    /** The host platform. */
    public static function new(): self
    {
        return PHP_OS_FAMILY === 'Windows' ? self::windows() : self::posix();
    }

    public static function posix(): self
    {
        return new self(false, '/dev/null');
    }

    public static function windows(): self
    {
        return new self(true, 'NUL');
    }

    public function isWindows(): bool
    {
        return $this->windows;
    }

    /** Path whose read end is an immediate EOF (the child's stdin when no payload is given). */
    public function nullDevice(): string
    {
        return $this->nullDevice;
    }

    public function withNullDevice(string $path): self
    {
        return new self($this->windows, $path, $this->killTree);
    }

    /** @param \Closure(int): void $killTree */
    public function withKillTree(\Closure $killTree): self
    {
        return new self($this->windows, $this->nullDevice, $killTree);
    }

    /**
     * Resolves a program on PATH the way the OS would (PATHEXT suffixes on
     * Windows), or null. Lets the CLI tell "git is not installed" apart from
     * "git is installed but could not be launched".
     */
    public function findExecutable(string $name, ?string $path = null): ?string
    {
        $path ??= (string) getenv($this->windows ? 'Path' : 'PATH');
        if ($path === '' && $this->windows) {
            $path = (string) getenv('PATH');
        }
        $suffixes = $this->windows ? ['.exe', '.cmd', '.bat', '.com'] : [''];
        foreach (explode($this->windows ? ';' : ':', $path) as $dir) {
            if ($dir === '') {
                continue;
            }
            foreach ($suffixes as $suffix) {
                $candidate = rtrim($dir, '/\\') . ($this->windows ? '\\' : '/') . $name . $suffix;
                if (is_file($candidate) && ($this->windows || is_executable($candidate))) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Force-stop the process tree rooted at $pid (Windows ladder only). */
    public function killTree(int $pid): void
    {
        ($this->killTree)($pid);
    }
}
