<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Support;

use SugarCraft\Crush\Attachments\FileMentions;

/**
 * Read an image off the host clipboard into a file (audit 15b-15, Ctrl+V).
 *
 * A terminal cannot paste an image: bracketed paste carries text, so the
 * pixels a screenshot tool put on the clipboard never reach the app as a
 * keystroke. The platform clipboard tool can hand them over - the same
 * family {@see SystemClipboard} writes through, read the other way:
 * `pngpaste` on macOS, `wl-paste` on Wayland, `xclip` on X11. What comes back
 * is saved under {@see directory()} and attached as an ordinary `@` mention,
 * so a pasted image and a mentioned one are the same thing from there on.
 *
 * ONE TOOL, FIRST IMAGE, BEST EFFORT, BOUNDED - SystemClipboard's rules. The
 * whole spawn is held to {@see TIMEOUT_SECONDS} with a TERM→KILL ladder so a
 * wedged X forward cannot freeze the TUI, and nothing here throws: no tool, a
 * failed exit or bytes that are not a PNG/JPEG/GIF/WebP all answer null, and
 * the caller says "no image on the clipboard".
 *
 * STDOUT GOES STRAIGHT TO THE DESTINATION FILE, NOT A PIPE. A pipe would need
 * draining against the deadline while the tool writes a multi-megabyte image,
 * and `xclip`/`wl-paste` may leave a helper holding a write end; a file
 * descriptor needs neither. stdin and stderr are /dev/null.
 */
final class ClipboardImage
{
    /** Wall-clock budget for one read, spawn to reap. */
    public const TIMEOUT_SECONDS = 2.0;

    private const TERMINATE_GRACE_SECONDS = 0.25;

    private const KILL_GRACE_SECONDS = 0.25;

    private const EXIT_POLL_MICROSECONDS = 5_000;

    /**
     * Test seam: replaces the spawn. Receives the argv and the destination
     * path, writes whatever it likes there, returns whether the tool
     * succeeded. Null runs the real tool.
     *
     * @var (\Closure(list<string>, string): bool)|null
     */
    private static ?\Closure $runner = null;

    /**
     * Test seam: replaces candidate DISCOVERY, as
     * {@see SystemClipboard::useCandidatesForTesting()} does - a headless host
     * legitimately finds no tool at all.
     *
     * @var list<list<string>>|null
     */
    private static ?array $candidatesOverride = null;

    /** Test seam: where pasted images are written. Null is the real default. */
    private static ?string $directoryOverride = null;

    /**
     * Save the clipboard's image to a fresh file and return its path, or
     * null when no tool produced an image.
     */
    public static function save(): ?string
    {
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return null;
        }

        foreach (self::candidates() as $argv) {
            $path = $directory . '/paste-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.png';
            // pngpaste takes the destination as its argument; the others
            // write the image to stdout, which run() points at $path.
            $argv = array_map(static fn (string $arg): string => $arg === '{dest}' ? $path : $arg, $argv);

            if (self::run($argv, $path)) {
                $head = (string) @file_get_contents($path, false, null, 0, 16);
                $mime = FileMentions::sniffImage($head);
                if ($mime !== null) {
                    return self::withExtension($path, $mime);
                }
            }

            @unlink($path);
        }

        return null;
    }

    /**
     * The argv list to try, in order. Each binary has been located on PATH, so
     * a spawn never targets a missing program.
     *
     * @return list<list<string>>
     */
    public static function candidates(): array
    {
        if (self::$candidatesOverride !== null) {
            return self::$candidatesOverride;
        }

        $found = [];
        $add = static function (string $binary, string ...$args) use (&$found): void {
            $path = ProcessContainment::locateOnPath($binary);
            if ($path !== '') {
                $found[] = [$path, ...$args];
            }
        };

        if (PHP_OS_FAMILY === 'Darwin') {
            $add('pngpaste', '{dest}');
        }
        if ((string) getenv('WAYLAND_DISPLAY') !== '') {
            $add('wl-paste', '--no-newline', '--type', 'image/png');
        }
        if ((string) getenv('DISPLAY') !== '') {
            $add('xclip', '-selection', 'clipboard', '-target', 'image/png', '-out');
        }

        return $found;
    }

    /**
     * Where pasted images land: a private per-user directory under the system
     * temp dir. The image's bytes are snapshotted into the message when the
     * prompt is sent ({@see \SugarCraft\Crush\Attachment}), so the file only
     * has to live until then.
     */
    public static function directory(): string
    {
        if (self::$directoryOverride !== null) {
            return self::$directoryOverride;
        }

        $user = function_exists('posix_getuid') ? (string) posix_getuid() : (string) getmypid();

        return rtrim(sys_get_temp_dir(), '/') . '/sugarcrush-pastes-' . $user;
    }

    /**
     * @param (\Closure(list<string>, string): bool)|null $runner
     */
    public static function useRunnerForTesting(?\Closure $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * @param list<list<string>>|null $candidates
     */
    public static function useCandidatesForTesting(?array $candidates): void
    {
        self::$candidatesOverride = $candidates;
    }

    public static function useDirectoryForTesting(?string $directory): void
    {
        self::$directoryOverride = $directory;
    }

    /** Rename a saved paste so its extension says what the bytes are. */
    private static function withExtension(string $path, string $mime): string
    {
        $extension = match ($mime) {
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => 'png',
        };
        if ($extension === 'png') {
            return $path;
        }

        $renamed = substr($path, 0, -4) . '.' . $extension;

        return @rename($path, $renamed) ? $renamed : $path;
    }

    /**
     * @param list<string> $argv
     */
    private static function run(array $argv, string $destination): bool
    {
        if (self::$runner !== null) {
            return (self::$runner)($argv, $destination);
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $destination, 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $process = @proc_open($argv, $descriptors, $pipes, null, ProcessContainment::env());
        if (!\is_resource($process)) {
            return false;
        }

        $exitCode = self::waitForExit($process, microtime(true) + self::TIMEOUT_SECONDS);
        if ($exitCode === null) {
            ProcessReaper::escalate(
                static function (int $signal) use ($process): void {
                    ProcessContainment::terminate($process, $signal);
                },
                static fn (): bool => (proc_get_status($process)['running'] ?? false) !== true,
                self::TERMINATE_GRACE_SECONDS,
                self::KILL_GRACE_SECONDS,
            );
        }

        proc_close($process);

        return $exitCode === 0;
    }

    /**
     * The child's exit code once it is gone, or null if $deadline passed first.
     *
     * @param resource $process
     */
    private static function waitForExit($process, float $deadline): ?int
    {
        while (true) {
            $status = proc_get_status($process);
            if (($status['running'] ?? false) !== true) {
                return (int) ($status['exitcode'] ?? -1);
            }

            if (microtime(true) >= $deadline) {
                return null;
            }

            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }
}
