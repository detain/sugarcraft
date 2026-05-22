<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vcr\Raster\Rasterizer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;
use SugarCraft\Vt\Theme;
use SugarCraft\Vt\Themes;

/**
 * Canonical pipeline: .tape file → .gif file.
 *
 * Wires together Lexer → Parser → Compiler → Player → Terminal →
 * Renderer → FrameStream → FrameDedup → Rasterizer → GifEncoder.
 *
 * Per-frame hold durations (seconds) are tracked from FrameDedup output
 * and passed to the encoder for VFR timing.
 */
final class TapeToGif
{
    public function __construct(
        private Lexer $lexer,
        private Parser $parser,
        private Compiler $compiler,
        private Renderer $renderer,
        private Rasterizer $rasterizer,
        private GifEncoder $encoder,
    ) {
    }

    /**
     * Render a .tape file to a .gif file.
     *
     * @param string $tapePath
     * @param string|null $outputPath null = same dir with .gif extension
     * @param array{
     *   fps?: float,
     *   theme?: string,
     *   fontSize?: int,
     *   backend?: 'gd'|'imagick',
     *   encoder?: 'ffmpeg'|'php',
     *   strict?: bool,
     * } $options
     */
    public function render(string $tapePath, ?string $outputPath = null, array $options = []): void
    {
        $fps = $options['fps'] ?? 30.0;
        $fontSize = $options['fontSize'] ?? 14;
        $themeName = $options['theme'] ?? 'TokyoNight';
        $backend = $options['backend'] ?? 'gd';

        $source = @file_get_contents($tapePath);
        if ($source === false) {
            throw new \RuntimeException("Cannot read tape file: {$tapePath}");
        }

        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $cassette = $this->compiler->compile($ast, $tapePath);

        $cols = $cassette->header->cols;
        $rows = $cassette->header->rows;

        $theme = $this->resolveTheme($themeName);

        $terminal = Terminal::new($cols, $rows, $theme);
        $player = new Player($cassette);

        $frameStream = $this->renderer->render($player, $terminal, $fps);

        $framesWithHolds = $this->buildFramesWithHolds($frameStream, 1.0 / $fps);

        $tempDir = sys_get_temp_dir() . '/candy-vcr-t2g-' . getmypid();
        if (!mkdir($tempDir) && !is_dir($tempDir)) {
            throw new \RuntimeException("Failed to create temp dir: {$tempDir}");
        }

        $pngPaths = [];
        $frameHolds = [];

        foreach ($framesWithHolds as ['snapshot' => $snapshot, 'hold' => $hold]) {
            $image = $this->rasterizer->rasterize($snapshot, 8, $fontSize * 2, null);
            \assert($image instanceof \GdImage);

            $framePath = $tempDir . '/frame_' . count($pngPaths) . '.png';
            if (!imagepng($image, $framePath)) {
                throw new \RuntimeException("Failed to write PNG frame: {$framePath}");
            }
            imagedestroy($image);

            $pngPaths[] = $framePath;
            $frameHolds[] = (int) round($hold * 1000);
        }

        $output = $outputPath ?? (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');

        try {
            $this->encoder->encode($pngPaths, $output, (int) $fps, $frameHolds);
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    /**
     * @param \Traversable<int, Snapshot> $stream
     * @return \Generator<int, array{snapshot:Snapshot, hold:float}>
     */
    private function buildFramesWithHolds(\Traversable $stream, float $frameInterval): \Generator
    {
        $prevTime = 0.0;
        $dedupIterator = FrameDedup::dedup($stream);

        foreach ($dedupIterator as $index => $snapshot) {
            $frameTime = $snapshot->time;

            if ($index > 0) {
                $elapsed = $frameTime - $prevTime;
                $hold = $elapsed > 0 ? $elapsed : $frameInterval;
            } else {
                $hold = $frameInterval;
            }

            $prevTime = $frameTime;

            yield $index => [
                'snapshot' => $snapshot,
                'hold' => $hold,
            ];
        }
    }

    private function resolveTheme(string $name): Theme
    {
        return match ($name) {
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
            'Dracula' => Theme::dracula(),
            'SolarizedDark' => Theme::solarizedDark(),
            default => Theme::tokyoNight(),
        };
    }

    private function cleanupDir(string $dir): void
    {
        $files = glob($dir . '/*') ?: [];
        foreach ($files as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    /**
     * Create a TapeToGif with default components.
     *
     * @param array{
     *   fps?: float,
     *   theme?: string,
     *   fontSize?: int,
     *   backend?: 'gd'|'imagick',
     *   encoder?: 'ffmpeg'|'php',
     * } $options
     */
    public static function create(array $options = []): self
    {
        $fps = $options['fps'] ?? 30.0;
        $backend = $options['backend'] ?? 'gd';
        $encoderType = $options['encoder'] ?? 'ffmpeg';

        $encoder = match ($encoderType) {
            'php' => new PhpGifEncoder(),
            default => new FfmpegGifEncoder(),
        };

        $rasterizer = match ($backend) {
            'imagick' => new ImagickRasterizer(),
            default => new GdRasterizer(),
        };

        return new self(
            new Lexer(),
            new Parser(),
            new Compiler(),
            new Renderer(),
            $rasterizer,
            $encoder,
        );
    }
}
