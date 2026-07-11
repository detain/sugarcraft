<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Renderer;

use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Lang;

/**
 * Chafa graphics renderer — invokes the chafa command-line tool.
 *
 * Chafa is a command-line image-to-terminal converter that supports
 * true-color, transparency, and various output formats.
 */
final class ChafaRenderer implements Renderer
{
    use \SugarCraft\Mosaic\Concerns\RenderValidationTrait;

    /**
     * Memoised result of {@see available()} — null means not yet probed,
     * true/false are cached for the lifetime of the process.
     *
     * This memoisation is intentional: spawning `chafa --version` on every
     * frame of an animation would be catastrophic for performance. The cost
     * is that if chafa is installed after process startup, {@see reset()}
     * must be called to re-probe. For short-lived CLI tools this is fine;
     * for long-running daemons consider calling reset() after package
     * installation if you need to use chafa dynamically.
     */
    private static ?bool $available = null;

    /**
     * Reused per-process scratch file for the chafa input image, plus the
     * content hash of whatever is currently written to it.
     *
     * An animation renders many frames; a fresh tempnam() + full rewrite +
     * unlink per frame is wasteful. Instead we keep ONE temp file for the
     * process and only rewrite it when the image bytes actually change (keyed
     * by hash), so a static image re-rendered on every TUI redraw skips the
     * write entirely. The file is unlinked on shutdown.
     *
     * @var array{path: string, hash: string}|null
     */
    private static ?array $scratch = null;

    /**
     * @param list<string> $options Additional chafa CLI options (e.g. ['--colors=256', '--work=n'])
     * @param string|null  $format  chafa output format: 'sixels', 'iterm', 'kitty', or
     *                              'symbols'. null leaves chafa's own default (symbols)
     *                              — the high-quality character-art mode. Pass 'sixels'
     *                              to drive a fast, full-quality sixel encode in C
     *                              (far faster than the pure-PHP {@see SixelRenderer}).
     */
    public function __construct(
        private readonly array $options = [],
        private readonly ?string $format = null,
    ) {}

    /**
     * Whether the `chafa` binary is on PATH. Probed once per process (the result
     * is memoised) so a per-frame video render does not spawn a probe each frame.
     */
    public static function available(): bool
    {
        if (self::$available !== null) {
            return self::$available;
        }

        $proc = @proc_open(
            ['chafa', '--version'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($proc)) {
            // proc_open() may have created pipes before failing — close them
            // to avoid file descriptor leaks in long-running processes. When
            // the binary is missing entirely $pipes stays null (foreach on
            // null raises a warning, which failOnWarning turns fatal).
            foreach ($pipes ?? [] as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            return self::$available = false;
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }

        return self::$available = (proc_close($proc) === 0);
    }

    public function render(ImageSource $image, int $width, ?int $height = null): string
    {
        $effectiveHeight = $this->prepareRender($image, $width, $height);

        $size = "{$width}x{$effectiveHeight}";
        $cmd = ['chafa', '--size=' . $size];
        if ($this->format !== null) {
            $cmd[] = '--format=' . $this->format;
        }
        $cmd = array_merge($cmd, $this->options);

        $cmd[] = self::scratchFile($image->bytes);

        $descriptorSpec = [1 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptorSpec, $pipes);

        if ($process === false) {
            throw new \RuntimeException(Lang::t('chafa.not_found'));
        }

        if (!is_resource($process)) {
            throw new \RuntimeException(Lang::t('chafa.command_failed', ['error' => 'proc_open returned false']));
        }

        $stdout = stream_get_contents($pipes[1]);
        if ($stdout === false) {
            $stdout = '';
        }

        fclose($pipes[1]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            throw new \RuntimeException(
                Lang::t('chafa.command_failed', ['error' => "exit code $exitCode"])
            );
        }

        return $stdout;
    }

    /**
     * Path to a temp file containing $bytes, reusing a single per-process
     * scratch file and skipping the write when its content is already current
     * (matched by hash). Replaces a fresh tempnam()+write+unlink per frame.
     *
     * @throws \RuntimeException  if the scratch file cannot be created/written
     */
    private static function scratchFile(string $bytes): string
    {
        $hash = hash('xxh3', $bytes);

        $scratch = self::$scratch;
        if ($scratch !== null && is_file($scratch['path'])) {
            if ($scratch['hash'] === $hash) {
                return $scratch['path']; // already current — skip the rewrite
            }
            if (file_put_contents($scratch['path'], $bytes) === false) {
                throw new \RuntimeException(Lang::t('image_source.temp_failed'));
            }
            self::$scratch = ['path' => $scratch['path'], 'hash' => $hash];

            return $scratch['path'];
        }

        $path = tempnam(sys_get_temp_dir(), 'chafa');
        if ($path === false) {
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }
        if (file_put_contents($path, $bytes) === false) {
            @unlink($path);
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }
        self::$scratch = ['path' => $path, 'hash' => $hash];

        // The file outlives each render (that is the point — it is reused), so
        // clean it up when the process ends rather than per-call.
        register_shutdown_function(static function () use ($path): void {
            @unlink($path);
        });

        return $path;
    }

    /**
     * @internal Test seam — the current reused scratch-file path, or null if
     *           none has been created yet.
     */
    public static function currentScratchPath(): ?string
    {
        return self::$scratch['path'] ?? null;
    }

    /**
     * @internal Test seam — drop and unlink the reused scratch file so the
     *           next render() re-creates it. Never needed in production.
     */
    public static function resetScratch(): void
    {
        if (self::$scratch !== null) {
            @unlink(self::$scratch['path']);
            self::$scratch = null;
        }
    }

    public function name(): string
    {
        return 'chafa';
    }

    /**
     * Reset the memoised availability check.
     *
     * For long-running CLI tools that may install chafa after startup,
     * call this to force a fresh probe rather than relying on the
     * per-process memoised result.
     */
    public static function reset(): void
    {
        self::$available = null;
    }

    public function supportsAlpha(): bool
    {
        return true;
    }

    public function isInline(): bool
    {
        return true;
    }

    /**
     * Chafa invokes an external command — no persistent image identity
     * to delete. Returns the empty string.
     */
    public function delete(string $imageId): string
    {
        return '';
    }
}
