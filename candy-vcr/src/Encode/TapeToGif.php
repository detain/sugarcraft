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
        $cliTheme = $options['theme'] ?? null;

        // Prefer font settings from the cassette header (Set FontSize/FontFamily
        // directives in the tape) over CLI defaults.
        $fontSize = $cassette->header->fontSize ?? (int) ($options['fontSize'] ?? 14);
        $fontFamily = $cassette->header->fontFamily ?? $options['fontFamily'] ?? 'JetBrainsMono';

        $source = @file_get_contents($tapePath);
        if ($source === false) {
            throw new \RuntimeException("Cannot read tape file: {$tapePath}");
        }

        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $strict = (bool) ($options['strict'] ?? false);
        $cassette = $this->compiler->compile($ast, $tapePath, $strict);

        $themeName = $cassette->header->theme ?? $cliTheme ?? 'TokyoNight';
        $theme = $this->resolveTheme($themeName, $strict);
        $rasterizer = $this->themedRasterizerWithFonts($theme, $fontFamily);

        $terminal = Terminal::new($cassette->header->cols, $cassette->header->rows, $theme);
        $player = new Player($cassette);

        $frameStream = $this->renderer->render($player, $terminal, $fps);

        $cellW = max(1, (int) floor($fontSize * 0.6));
        $cellH = max(1, $fontSize * 2);

        $tempDir = $this->createTempDir();
        $pngPaths = [];
        $frameHoldsMs = [];

        // Compute the output path early so screenshots can be confined to its directory
        $output = $outputPath ?? (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');
        $outputDir = dirname($output);

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

                // Handle screenshot capture — confined to output directory for safety
                if (($frameInfo['screenshotPath'] ?? false) !== false) {
                    $rawScreenshotPath = $frameInfo['screenshotPath'];
                    $confinedPath = $this->confineScreenshotPath($rawScreenshotPath, $outputDir);
                    $screenshotImage = $rasterizer->rasterize($frameInfo['snapshot'], $cellW, $cellH, null, $renderCursor);
                    try {
                        $written = $screenshotImage instanceof \Imagick
                            ? $screenshotImage->writeImage($confinedPath)
                            : imagepng($screenshotImage, $confinedPath);
                        if ($written === false) {
                            throw new \RuntimeException("Failed to write screenshot: {$confinedPath}");
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

            $this->encoder->encode($pngPaths, $output, (int) round($fps), $frameHoldsMs);
        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    /**
     * Confine a screenshot path to the output directory.
     *
     * Allows relative paths (joined under outputDir) and absolute paths that
     * already reside inside outputDir. Rejects path traversal segments and
     * absolute paths that escape the output directory.
     */
    private function confineScreenshotPath(string $rawPath, string $outputDir): string
    {
        // Reject path traversal
        if (str_contains($rawPath, '..') || str_contains($rawPath, '\\')) {
            throw new \RuntimeException("Screenshot path escapes output directory: {$rawPath}");
        }

        // Absolute path: verify its directory lives under outputDir
        if (str_starts_with($rawPath, '/') || (strlen($rawPath) >= 2 && $rawPath[1] === ':')) {
            $rawDir = dirname($rawPath);
            $rawDirReal = realpath($rawDir);
            $outputDirReal = realpath($outputDir);
            if ($rawDirReal === false || $outputDirReal === false) {
                throw new \RuntimeException("Screenshot path escapes output directory: {$rawPath}");
            }
            if (!str_starts_with($rawDirReal . DIRECTORY_SEPARATOR, $outputDirReal . DIRECTORY_SEPARATOR)) {
                throw new \RuntimeException("Screenshot path escapes output directory: {$rawPath}");
            }
            return $rawPath;
        }

        // Relative path: join under outputDir
        $target = $outputDir . DIRECTORY_SEPARATOR . $rawPath;
        $targetDir = dirname($target);
        $targetDirReal = realpath($targetDir);
        if ($targetDirReal === false || !str_starts_with($targetDirReal . DIRECTORY_SEPARATOR, $outputDir . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Screenshot path escapes output directory: {$rawPath}");
        }

        return $target;
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

    private function resolveTheme(string $name, bool $strict = false): Theme
    {
        return match ($name) {
            'TokyoNight' => Theme::tokyoNight(),
            'TokyoNightLight' => Theme::tokyoNightLight(),
            'TokyoNightStorm' => Theme::tokyoNightStorm(),
            'Dracula' => Theme::dracula(),
            'SolarizedDark' => Theme::solarizedDark(),
            default => $this->handleUnknownTheme($name, $strict),
        };
    }

    private function handleUnknownTheme(string $name, bool $strict): Theme
    {
        if ($strict) {
            throw new \InvalidArgumentException(
                "Unknown theme '{$name}' (known: TokyoNight, TokyoNightLight, TokyoNightStorm, Dracula, SolarizedDark)",
            );
        }
        // Non-strict: warn and fall back to TokyoNight
        error_log("candy-vcr: unknown theme '{$name}', falling back to TokyoNight");
        return Theme::tokyoNight();
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
