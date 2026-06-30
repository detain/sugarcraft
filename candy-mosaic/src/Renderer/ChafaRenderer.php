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
    /** Memoised result of {@see available()} (null = not probed yet). */
    private static ?bool $available = null;

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
            // to avoid file descriptor leaks in long-running processes.
            foreach ($pipes as $pipe) {
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
        if ($width <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_width', ['width' => $width])
            );
        }

        if ($height !== null && $height <= 0) {
            throw new \InvalidArgumentException(
                Lang::t('renderer.invalid_height', ['height' => $height])
            );
        }

        $effectiveHeight = $height ?? (int) round($width / $image->aspectRatio());
        if ($effectiveHeight <= 0) {
            $effectiveHeight = 1;
        }

        $size = "{$width}x{$effectiveHeight}";
        $cmd = ['chafa', '--size=' . $size];
        if ($this->format !== null) {
            $cmd[] = '--format=' . $this->format;
        }
        $cmd = array_merge($cmd, $this->options);

        $tempFile = tempnam(sys_get_temp_dir(), 'chafa');
        if ($tempFile === false) {
            throw new \RuntimeException(Lang::t('image_source.temp_failed'));
        }

        try {
            $written = file_put_contents($tempFile, $image->bytes);
            if ($written === false) {
                throw new \RuntimeException(Lang::t('image_source.temp_failed'));
            }

            $cmd[] = $tempFile;

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
        } finally {
            @unlink($tempFile);
        }
    }

    public function name(): string
    {
        return 'chafa';
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
