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
            $proc = @proc_open(
                ['taskkill', '/F', '/T', '/PID', (string) $pid],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
            );
            if (!is_resource($proc)) {
                return;
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($proc);
        };
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
