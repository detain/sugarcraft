<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

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

/**
 * Canonical pipeline: .tape file → .gif file.
 *
 * Wires together Lexer → Parser → Compiler → Player → Terminal →
 * Renderer → FrameStream → FrameDedup → Rasterizer → GifEncoder.
 *
 * Per-frame hold durations (milliseconds) are tracked from FrameDedup output
 * and passed to the encoder for VFR timing — so a `Sleep 2s` in the tape
 * produces an actual 2-second pause in the GIF instead of repeating
 * identical frames.
 *
 * Designed to be reused across many tape renders (batch mode): the
 * stateless components (Lexer/Parser/Compiler/Renderer) are created once
 * and the rasterizer/encoder are reused so glyph caches inside the
 * rasterizer survive across frames within a tape (Glyphs is still
 * rebuilt per-tape since cell dimensions can change between tapes).
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
     * @param array{
     *   fps?: float,
     *   theme?: string,
     *   fontSize?: int,
     *   fontFamily?: string,
     *   backend?: 'gd'|'imagick',
     *   encoder?: 'ffmpeg'|'php',
     *   strict?: bool,
     * } $options
     */
    public function render(string $tapePath, ?string $outputPath = null, array $options = []): void
    {
        $fps = (float) ($options['fps'] ?? 30.0);
        $fontSize = (int) ($options['fontSize'] ?? 14);
        $fontFamily = $options['fontFamily'] ?? 'JetBrainsMono';
        $cliTheme = $options['theme'] ?? null;

        $source = @file_get_contents($tapePath);
        if ($source === false) {
            throw new \RuntimeException("Cannot read tape file: {$tapePath}");
        }

        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $cassette = $this->compiler->compile($ast, $tapePath);

        $themeName = $cassette->header->theme ?? $cliTheme ?? 'TokyoNight';
        $theme = $this->resolveTheme($themeName);
        $rasterizer = $this->themedRasterizerWithFonts($theme, $fontFamily);

        $terminal = Terminal::new($cassette->header->cols, $cassette->header->rows, $theme);
        $player = new Player($cassette);

        $frameStream = $this->renderer->render($player, $terminal, $fps);

        $cellW = max(1, (int) floor($fontSize * 0.6));
        $cellH = max(1, $fontSize * 2);

        $tempDir = $this->createTempDir();
        $pngPaths = [];
        $frameHoldsMs = [];

        try {
            foreach ($this->buildFramesWithHolds($frameStream, 1.0 / $fps) as $index => $frameInfo) {
                $renderCursor = $frameStream->captureCursor;
                $image = $rasterizer->rasterize($frameInfo['snapshot'], $cellW, $cellH, null, $renderCursor);

                $framePath = $tempDir . '/frame_' . sprintf('%05d', $index) . '.png';
                try {
                    $written = $image instanceof \Imagick
                        ? $image->writeImage($framePath)
                        : imagepng($image, $framePath);
                    if ($written === false) {
                        throw new \RuntimeException("Failed to write PNG frame: {$framePath}");
                    }
                } finally {
                    if ($image instanceof \Imagick) {
                        $image->clear();
                    } else {
                        imagedestroy($image);
                    }
                }

                $pngPaths[] = $framePath;
                $frameHoldsMs[] = (int) round($frameInfo['hold'] * 1000);

                // Handle screenshot capture
                if (($frameInfo['screenshotPath'] ?? false) !== false) {
                    $screenshotPath = $frameInfo['screenshotPath'];
                    $screenshotImage = $rasterizer->rasterize($frameInfo['snapshot'], $cellW, $cellH, null, $renderCursor);
                    try {
                        $written = $screenshotImage instanceof \Imagick
                            ? $screenshotImage->writeImage($screenshotPath)
                            : imagepng($screenshotImage, $screenshotPath);
                        if ($written === false) {
                            throw new \RuntimeException("Failed to write screenshot: {$screenshotPath}");
                        }
                    } finally {
                        if ($screenshotImage instanceof \Imagick) {
                            $screenshotImage->clear();
                        } else {
                            imagedestroy($screenshotImage);
                        }
                    }
                }
            }

            if ($pngPaths === []) {
                throw new \RuntimeException("Tape produced no frames: {$tapePath}");
            }

            $output = $outputPath ?? (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');
            $this->encoder->encode($pngPaths, $output, (int) round($fps), $frameHoldsMs);
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    /**
     * @return \Generator<int, array{snapshot:Snapshot, hold:float}>
     */
    private function buildFramesWithHolds(FrameStream $frameStream, float $frameInterval): \Generator
    {
        $prevTime = 0.0;
        $dedupIterator = FrameDedup::dedup($frameStream);

        $emittedIndex = 0;
        $lastSnapshot = null;
        foreach ($dedupIterator as $snapshot) {
            $lastSnapshot = $snapshot;
            $frameTime = $snapshot->time;
            $hold = $emittedIndex === 0
                ? $frameInterval
                : max($frameInterval, $frameTime - $prevTime);

            $prevTime = $frameTime;

            // Capture any pending screenshot BEFORE clearing it — this
            // associates the screenshot with exactly the frame that was
            // current when the Snapshot event fired.
            $screenshotPath = $frameStream->pendingScreenshotPath;
            if ($screenshotPath !== null) {
                $frameStream->pendingScreenshotPath = null;
            }

            yield $emittedIndex => [
                'snapshot' => $snapshot,
                'hold' => $hold,
                'screenshotPath' => $screenshotPath,
            ];
            $emittedIndex++;
        }

        // If a Snapshot event was processed after the last yielded frame
        // (e.g., because subsequent frames were deduped away), capture it
        // using the last yielded snapshot.
        if ($frameStream->pendingScreenshotPath !== null && $lastSnapshot !== null) {
            yield $emittedIndex => [
                'snapshot' => $lastSnapshot,
                'hold' => 0.0,
                'screenshotPath' => $frameStream->pendingScreenshotPath,
            ];
            $frameStream->pendingScreenshotPath = null;
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

    /**
     * Create a themed rasterizer, applying both font and theme settings.
     * The chained withFont()->withTheme() approach forces a cache rebuild
     * for the new font, which is correct behavior.
     */
    private function themedRasterizerWithFonts(Theme $theme, string $fontFamily): Rasterizer
    {
        if ($this->rasterizer instanceof GdRasterizer) {
            return $this->rasterizer->withFont($fontFamily)->withTheme($theme);
        }
        if ($this->rasterizer instanceof ImagickRasterizer) {
            return $this->rasterizer->withFont($fontFamily)->withTheme($theme);
        }
        return $this->rasterizer;
    }

    private function createTempDir(): string
    {
        $base = sys_get_temp_dir() . '/candy-vcr-t2g-' . getmypid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($base, 0700, true) && !is_dir($base)) {
            throw new \RuntimeException("Failed to create temp dir: {$base}");
        }
        return $base;
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
     *   fontFamily?: string,
     *   backend?: 'gd'|'imagick',
     *   encoder?: 'ffmpeg'|'php',
     * } $options
     */
    public static function create(array $options = []): self
    {
        $backend = $options['backend'] ?? 'gd';
        $encoderType = $options['encoder'] ?? 'ffmpeg';
        $fontSize = (int) ($options['fontSize'] ?? 14);
        $fontFamily = $options['fontFamily'] ?? 'JetBrainsMono';

        $encoder = match ($encoderType) {
            'php' => new PhpGifEncoder(),
            default => new FfmpegGifEncoder(),
        };

        $rasterizer = match ($backend) {
            'imagick' => new ImagickRasterizer($fontSize, $fontFamily),
            default => new GdRasterizer($fontSize, $fontFamily),
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
