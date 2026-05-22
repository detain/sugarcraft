<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

use SugarCraft\Vcr\Cassette;
use SugarCraft\Vcr\CassetteHeader;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vcr\Render\FrameStream;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Raster\Rasterizer;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
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
 * Tracks per-frame hold durations (seconds) from FrameDedup output
 * to pass accurate VFR timing to the encoder.
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
        $frameInterval = 1.0 / $fps;

        $framesWithHolds = $this->buildFramesWithHolds($frameStream, $frameInterval);

        $frames = [];
        $frameHolds = [];
        foreach ($framesWithHolds as ['snapshot' => $snapshot, 'hold' => $hold]) {
            $image = $this->rasterizer->rasterize($snapshot, 8, $fontSize * 2, null);
            \assert($image instanceof \GdImage);
            $frames[] = $image;
            $frameHolds[] = $hold;
        }

        $output = $outputPath ?? preg_replace('/\.tape$/', '.gif', $tapePath);
        if ($output === null || $output === '') {
            $output = $tapePath . '.gif';
        }

        $framesIter = new \ArrayIterator($frames);
        $this->encoder->encode($framesIter, $cols, $rows, $frameHolds, $output);

        foreach ($frames as $image) {
            imagedestroy($image);
        }
    }

    /**
     * Walk FrameDedup output and attach per-frame hold durations.
     *
     * FrameStream yields snapshots at 1/fps intervals. FrameDedup collapses
     * identical adjacent snapshots. For each emitted snapshot, the hold is
     * (number of original frames collapsed + 1) * frameInterval.
     *
     * @param \Traversable<int, Snapshot> $stream
     * @return \Generator<int, array{snapshot:Snapshot, hold:float}>
     */
    private function buildFramesWithHolds(\Traversable $stream, float $frameInterval): \Generator
    {
        $prevTime = 0.0;
        $dedupIterator = FrameDedup::dedup($stream);

        $heldFrames = [];

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

    /**
     * Resolve a theme name to a Theme instance.
     */
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
