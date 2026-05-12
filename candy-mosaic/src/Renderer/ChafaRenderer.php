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
    /**
     * @param list<string> $options  Additional chafa CLI options (e.g. ['--colors=256', '--work=n'])
     */
    public function __construct(
        private readonly array $options = [],
    ) {}

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
        $cmd = array_merge(['chafa', '--size=' . $size], $this->options, []);

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

            stream_set_blocking($pipes[1], false);

            $stdout = '';
            while (!feof($pipes[1])) {
                $chunk = fread($pipes[1], 8192);
                if ($chunk !== false && $chunk !== '') {
                    $stdout .= $chunk;
                }
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
}
