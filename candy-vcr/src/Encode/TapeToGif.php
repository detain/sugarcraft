<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Encode;

use SugarCraft\Vcr\Format\AtomicFileWriter;
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
use SugarCraft\Vterm\Snapshot;
use SugarCraft\Vterm\Terminal;
use SugarCraft\Vterm\Theme;

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
     * Notices booked while writing (finding 009's extension swap). Kept
     * separate from the compile warnings so the reset cadence is honest:
     * compile() clears the compiler's list, render() clears this one.
     *
     * @var list<string>
     */
    private array $renderWarnings = [];

    /**
     * The single fps precedence ladder (finding 008): explicit caller/CLI
     * value → tape `Set Framerate` → the 30.0 house default. Public so the
     * door is testable without rendering pixels, and any second render path
     * that appears later can reuse the ladder instead of re-forking it.
     */
    public static function resolveFps(?float $cliFps, ?float $tapeFramerate): float
    {
        if ($cliFps !== null) {
            return $cliFps;
        }
        if ($tapeFramerate !== null && is_finite($tapeFramerate) && $tapeFramerate > 0.0) {
            return $tapeFramerate;
        }

        return 30.0;
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
     *   shell?: string|null,
     *   exec?: bool,
     *   maxFrames?: int,
     *   maxRuntimeSeconds?: float,
     *   onProgress?: callable(int, int, int): void,
     * } $options
     *
     * `maxFrames` / `maxRuntimeSeconds` override the frame-stream safety
     * ceilings (defaults: 100 000 frames / 24 h of virtual tape). A corrupted
     * timestamp breaches them with a named RuntimeException instead of
     * rendering until an external timeout kills the process.
     *
     * The `shell` / `exec` options opt the render into exec mode (real PTY +
     * captured program output). Precedence: an explicit `shell` (CLI `--shell`)
     * wins, else the tape's own `Set Shell` directive, else — when `exec` is set —
     * a default shell (`$SHELL` or `/bin/sh`). When none of these is present the
     * render uses the byte-identical echo path, unchanged.
     *
     * @return string The resolved output path the GIF was written to. When
     *   $outputPath is null the tape's own confined `Output <path>` directive
     *   is honored, falling back to the tape name with a `.gif` extension.
     */
    public function render(string $tapePath, ?string $outputPath = null, array $options = []): string
    {
        // Render-time notices (the .gif swap from finding 009) are per-run;
        // the pipeline instance is reused across batch renders.
        $this->renderWarnings = [];
        $cliTheme = $options['theme'] ?? null;

        // Size law (finding 032): refuse by name BEFORE slurping — a stat is
        // free, a multi-gigabyte slurp is the harm. The post-read strlen is
        // the belt for stream-shaped paths filesize cannot measure.
        $size = @filesize($tapePath);
        if ($size !== false && $size > Lexer::MAX_TAPE_BYTES) {
            throw new \RuntimeException(
                'tape file ' . $tapePath . ' exceeds the ' . Lexer::MAX_TAPE_BYTES . '-byte tape cap',
            );
        }
        $source = @file_get_contents($tapePath);
        if ($source === false) {
            throw new \RuntimeException("Cannot read tape file: {$tapePath}");
        }
        if (strlen($source) > Lexer::MAX_TAPE_BYTES) {
            throw new \RuntimeException(
                'tape file ' . $tapePath . ' exceeds the ' . Lexer::MAX_TAPE_BYTES . '-byte tape cap',
            );
        }

        $tokens = $this->lexer->tokenize($source);
        $ast = $this->parser->parse($tokens);
        $strict = (bool) ($options['strict'] ?? false);
        $cassette = $this->compiler->compile($ast, $tapePath, $strict);

        // fps precedence (finding 008): an explicit --fps always wins; next
        // the tape's `Set Framerate`; the 30.0 seat is the last word. The
        // compile has run, so the compiler's framerate knob is live.
        $optionsFps = $options['fps'] ?? null;
        $fps = self::resolveFps(
            is_numeric($optionsFps) ? (float) $optionsFps : null,
            $this->compiler->framerate(),
        );

        // Prefer font settings from the cassette header (Set FontSize/FontFamily
        // directives in the tape) over CLI defaults. The default matches the
        // Compiler's DEFAULT_FONT_SIZE (VHS 22px) so the cell metrics used to size
        // this canvas agree with the ones used to derive the grid — otherwise
        // cols * cellW would not reconstitute the requested Set Width pixels.
        $fontSize = $cassette->header->fontSize
            ?? (int) ($options['fontSize'] ?? Compiler::DEFAULT_FONT_SIZE);
        $fontFamily = $cassette->header->fontFamily ?? $options['fontFamily'] ?? 'JetBrainsMono';

        $themeName = $cassette->header->theme ?? $cliTheme ?? 'TokyoNight';
        $theme = $this->resolveTheme($themeName, $strict);
        // The resolved fontSize must reach the rasterizer too: the cell grid
        // below is derived from it, and glyphs drawn at the rasterizer's own
        // default would float in cells sized for a different point size.
        $rasterizer = $this->themedRasterizerWithFonts($theme, $fontFamily, $fontSize);

        $terminal = Terminal::new($cassette->header->cols, $cassette->header->rows, $theme);
        $player = new Player($cassette);

        // Resolve the shell for exec mode. Explicit CLI --shell wins, else the
        // tape's own `Set Shell`, else a default shell when --exec was passed.
        // Null ⇒ the byte-identical echo path (the goldens' path), unchanged.
        $shell = $this->resolveShell($options);

        $frameStream = $this->renderer->render(
            $player,
            $terminal,
            $fps,
            $shell,
            ($options['maxFrames'] ?? null) !== null ? (int) $options['maxFrames'] : null,
            ($options['maxRuntimeSeconds'] ?? null) !== null ? (float) $options['maxRuntimeSeconds'] : null,
        );

        // Same cell metrics the Compiler used to derive cols/rows, so the canvas
        // (cols * cellW) x (rows * cellH) lands within one cell of the tape's
        // requested Set Width x Set Height pixel image.
        [$cellW, $cellH] = Compiler::cellMetrics($fontSize);

        $tempDir = $this->createTempDir();
        $pngPaths = [];
        $frameHoldsMs = [];

        // Compute the output path early so screenshots can be confined to its directory.
        // Precedence: explicit caller-supplied path > the tape's confined `Output <path>`
        // directive > the tape name with a `.gif` extension.
        $output = $outputPath
            ?? $this->compiler->outputPath()
            ?? (preg_replace('/\.tape$/', '.gif', $tapePath) ?: $tapePath . '.gif');

        // Finding 009: the only artifact candy-vcr emits is GIF bytes, so a
        // mislabeled suffix used to make the file lie about itself (demo.mp4
        // carrying a GIF header). The write door now rewrites any non-.gif
        // output to .gif and books the swap as a named warning — neither a
        // pretend mp4/webm encode nor a silently mislabeled file. Covers all
        // three output sources (CLI -o, the tape's Output directive, the
        // fallback) at the single seam they converge on.
        if (strcasecmp(pathinfo($output, PATHINFO_EXTENSION), 'gif') !== 0) {
            $outDir = pathinfo($output, PATHINFO_DIRNAME);
            $outName = pathinfo($output, PATHINFO_FILENAME);
            // pathinfo() reads a dotfile as extension-only ('.hidden' has an
            // empty filename); an empty stem means appending, not replacing.
            $stem = $outName === '' ? basename($output) : $outName;
            $swapped = ($outDir === '' ? '' : $outDir . DIRECTORY_SEPARATOR) . $stem . '.gif';
            $this->renderWarnings[] = "Output path '{$output}' is not a .gif file — candy-vcr emits GIF bytes only; writing '{$swapped}' instead";
            $output = $swapped;
        }
        $outputDir = dirname($output);

        // `Hide`/`Show` events flip the frame stream's cursor-capture flag as
        // the generator advances, and `Snapshot` events park a screenshot path
        // the same way: both must be sampled the instant the dedup iterator
        // pulls a snapshot — the stream is suspended right after producing it,
        // which is exactly the state that frame was captured in. (Hoisting the
        // read above the loop froze every frame at the pre-iteration value and
        // made the directives inert; reading it at yield time now that the
        // builder holds one frame of lookahead would attach the NEXT frame's
        // state.) The builder stamps both onto each frame array for this loop.

        // Long renders used to be silent: the first thing a caller heard was
        // 'GIF written', minutes later. The frame stream is lazy, so the total
        // is not knowable in advance and progress is a count, not a percentage.
        $onProgress = $options['onProgress'] ?? null;

        try {
            foreach ($this->buildFramesWithHolds($frameStream, 1.0 / $fps, $cassette->endTime) as $index => $frameInfo) {
                $renderCursor = $frameInfo['renderCursor'];
                if ($onProgress !== null && $index % 100 === 99) {
                    /** @var callable(int, int, int): void $onProgress */
                    $onProgress($index + 1, $cellW, $cellH);
                }
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
                // GIF Graphic Control delay is 16-bit centiseconds: a hold
                // beyond 655.35s saturates, and a non-finite hold (a corrupted
                // tape clock reaching the encoder) must die named, not silently
                // cast into a bogus delay.
                $holdSeconds = $frameInfo['hold'];
                if (!is_finite($holdSeconds)) {
                    throw new \RuntimeException("Non-finite frame hold duration at frame {$index} of {$tapePath}");
                }
                $frameHoldsMs[] = (int) round(min(655.35, max(0.0, $holdSeconds)) * 1000);

                // Handle screenshot capture — confined to output directory for safety
                if (($frameInfo['screenshotPath'] ?? null) !== null) {
                    $confinedPath = $this->confineScreenshotPath($frameInfo['screenshotPath'], $outputDir);
                    $screenshotImage = $rasterizer->rasterize($frameInfo['snapshot'], $cellW, $cellH, null, $renderCursor);
                    try {
                        $this->writeImageAtomically($screenshotImage, $confinedPath);
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

            $this->encodeAtomically($pngPaths, $output, $fps, $frameHoldsMs);
        } finally {
            $this->cleanupDir($tempDir);
        }

        return $output;
    }

    /**
     * Non-fatal compiler diagnostics from the most recent {@see render()} —
     * currently unresolvable / out-of-base `Source` includes that were skipped
     * under non-strict mode. The CLI surfaces these to stderr so a dropped
     * include is never silent. Empty after a clean render or a strict run
     * (strict mode throws on the first such problem instead).
     *
     * @return list<string>
     */
    public function warnings(): array
    {
        return array_merge($this->compiler->warnings(), $this->renderWarnings);
    }

    /**
     * Encode the GIF to a unique temp file in the destination directory, then
     * atomically rename it into place.
     *
     * The write is guarded here — at the single seam every output source funnels
     * through ($output is already resolved from the explicit caller path, the
     * confined `Output` directive, or the `<tape>.gif` fallback) — so the guard
     * covers ALL THREE sources and BOTH encoders (which each write straight to
     * the path they are handed and would otherwise follow a symlink there).
     *
     * `rename()` replaces the directory ENTRY at $output rather than writing
     * *through* it, so a pre-existing symlink or hard link at $output is replaced
     * by the freshly written regular file instead of being followed/truncated.
     * That structurally closes CWE-59 (symlink-follow) for the default
     * `<tape>.gif` path, the confine→write TOCTOU race (the check-and-place is
     * the atomic rename, not a compile-time is_link() far ahead of the write),
     * and hard-link truncation of a shared inode — all at once, without relying
     * on a fopen O_NOFOLLOW that PHP does not expose.
     *
     * @param list<non-empty-string> $pngPaths
     * @param list<int>              $frameHoldsMs
     */
    private function encodeAtomically(array $pngPaths, string $output, float $fps, array $frameHoldsMs): void
    {
        // Reserve the temp IN THE SAME DIRECTORY as $output (one filesystem,
        // atomic rename) with a forced `.gif` suffix: the ffmpeg encoder
        // infers its muxer from the extension even when the final output
        // path has none. The exclusive-create `x` door order and the
        // entry-replacing rename semantics live in AtomicFileWriter — the
        // same primitive every cassette writer gained in VCR-S19.
        $tmp = AtomicFileWriter::reserve($output, 'GIF', 'gif');

        try {
            // The encoder contract returns bool. Ignoring it meant a real
            // false (encoder killed mid-mux, out of memory) fell through to
            // the rename and the caller heard "GIF written" about a 0-byte
            // file. Fail loud BEFORE the rename: the catch below removes the
            // reserved temp, so nothing lies about existing. The bool is only
            // trustworthy now because both encoders re-stat their output
            // instead of reading the 0-byte size the fopen('x') reservation
            // left in PHP's stat cache — that cure rides with this guard in
            // PhpGifEncoder::encode() / FfmpegGifEncoder::encode().
            if ($this->encoder->encode($pngPaths, $tmp, (int) round($fps), $frameHoldsMs) === false) {
                throw new \RuntimeException("GIF encoder failed to produce: {$output}");
            }

            AtomicFileWriter::commit($tmp, $output, 'rendered GIF');
        } catch (\Throwable $e) {
            // Keep: failure-path tmp discard must not mask $e (and the
            // exclusive-create reservation means a vanished tmp is benign).
            AtomicFileWriter::discard($tmp);
            throw $e;
        }
    }

    /**
     * Write a rendered image into place atomically, for outputs whose path was
     * supplied by data rather than chosen by us.
     *
     * The bytes land in an exclusively reserved temp file (`x` =
     * O_CREAT|O_EXCL, so a planted name is never followed) in the DESTINATION
     * directory, then `rename()` replaces the directory ENTRY at $target.
     * Writing straight to $target instead would follow a symlink planted there
     * (CWE-59) and truncate a hard link to another inode — the same failure
     * class {@see encodeAtomically()} closes for the GIF, which is why the
     * screenshot branch must not bypass it. The temp name keeps the target's
     * extension so an ext-imagick backend still infers the same format.
     */
    private function writeImageAtomically(\GdImage|\Imagick $image, string $target): void
    {
        // The temp keeps the target's extension so an ext-imagick backend
        // still infers the same format; reserve/commit/discard semantics
        // (x door order, entry-replacing rename) are the shared primitive.
        $tmp = AtomicFileWriter::reserve($target, 'image');

        try {
            $written = $image instanceof \Imagick
                ? $image->writeImage($tmp)
                : imagepng($image, $tmp);
            if ($written === false) {
                throw new \RuntimeException("Failed to write screenshot: {$target}");
            }
            AtomicFileWriter::commit($tmp, $target, 'screenshot');
        } catch (\Throwable $e) {
            AtomicFileWriter::discard($tmp);
            throw $e;
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

        // Relative path: join under outputDir. realpath BOTH sides before the
        // prefix compare — $outputDir may itself be relative (`-o rel.gif`
        // makes dirname() hand us '.'), and an absolute realpath never starts
        // with './', so the old single-sided check rejected a perfectly
        // confined screenshot whenever the output path was relative. When
        // outputDir does not exist realpath() is false; keeping the raw value
        // as the floor is safe because the join under it then fails the
        // $targetDirReal === false arm below.
        $target = $outputDir . DIRECTORY_SEPARATOR . $rawPath;
        $targetDir = dirname($target);
        $targetDirReal = realpath($targetDir);
        $outputDirReal = realpath($outputDir) ?: $outputDir;
        if ($targetDirReal === false || !str_starts_with($targetDirReal . DIRECTORY_SEPARATOR, $outputDirReal . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException("Screenshot path escapes output directory: {$rawPath}");
        }

        return $target;
    }

    /**
     * Turn the deduped snapshot stream into frames carrying a hold duration.
     *
     * The builder holds ONE frame of lookahead: a pulled frame's true hold is
     * only known when the NEXT distinct frame appears (the gap between their
     * timestamps), and the final frame's hold extends to the tape's end time —
     * without that, a trailing `Sleep` had nothing left to hold (finding 003).
     * The cursor-capture flag and pending screenshot are sampled at PULL time
     * (the stream sits exactly at that frame) and travel inside the frame
     * array, immune to the one-frame deferral.
     *
     * @return \Generator<int, array{snapshot: Snapshot, hold: float, screenshotPath: string|null, renderCursor: bool}>
     */
    private function buildFramesWithHolds(FrameStream $frameStream, float $frameInterval, ?float $endTime = null): \Generator
    {
        $dedupIterator = FrameDedup::dedup($frameStream);

        $emittedIndex = 0;
        /** @var array{snapshot: Snapshot, screenshotPath: string|null, renderCursor: bool}|null $pending */
        $pending = null;
        $pendingTime = 0.0;

        foreach ($dedupIterator as $snapshot) {
            // Capture any pending screenshot BEFORE clearing it — this
            // associates the screenshot with exactly the frame that was
            // current when the Snapshot event fired.
            $screenshotPath = $frameStream->pendingScreenshotPath;
            if ($screenshotPath !== null) {
                $frameStream->pendingScreenshotPath = null;
            }

            $pulled = [
                'snapshot' => $snapshot,
                'screenshotPath' => $screenshotPath,
                'renderCursor' => $frameStream->captureCursor,
            ];

            if ($pending !== null) {
                yield $emittedIndex++ => $pending + [
                    'hold' => max($frameInterval, $snapshot->time - $pendingTime),
                ];
            }

            $pending = $pulled;
            $pendingTime = $snapshot->time;
        }

        if ($pending !== null) {
            // The last held frame lives until the tape's true end — that IS
            // the visible pause a trailing Sleep buys (previously every tape
            // collapsed to a uniform 1/fps hold).
            $hold = $endTime !== null && $endTime > $pendingTime
                ? max($frameInterval, $endTime - $pendingTime)
                : $frameInterval;
            yield $emittedIndex++ => $pending + ['hold' => $hold];
        }

        // If a Snapshot event was processed after the last yielded frame
        // (e.g., because subsequent frames were deduped away), capture it
        // using the last yielded snapshot.
        if ($frameStream->pendingScreenshotPath !== null && $pending !== null) {
            yield $emittedIndex => [
                'snapshot' => $pending['snapshot'],
                'hold' => 0.0,
                'screenshotPath' => $frameStream->pendingScreenshotPath,
                'renderCursor' => $pending['renderCursor'],
            ];
            $frameStream->pendingScreenshotPath = null;
        }
    }

    /**
     * Resolve the effective shell for exec mode from the render options and the
     * compiled tape. Returns null to keep the byte-identical echo path.
     *
     * Precedence:
     *   1. `$options['shell']` — an explicit CLI `--shell=<sh>` (operator override).
     *   2. The tape's own `Set Shell "<sh>"` directive ({@see Compiler::shell()}).
     *   3. A default shell (`$SHELL` or `/bin/sh`) when `$options['exec']` is set
     *      but neither of the above supplied one.
     *
     * @param array<string, mixed> $options
     */
    private function resolveShell(array $options): ?string
    {
        $cliShell = $options['shell'] ?? null;
        if (is_string($cliShell) && trim($cliShell) !== '') {
            return trim($cliShell);
        }

        $tapeShell = $this->compiler->shell();
        if ($tapeShell !== null && trim($tapeShell) !== '') {
            return trim($tapeShell);
        }

        if (($options['exec'] ?? false) === true) {
            return $this->defaultShell();
        }

        return null;
    }

    /**
     * The default shell used by `--exec` when no explicit shell was given:
     * honour `$SHELL` when it points at an executable, else `/bin/sh`.
     */
    private function defaultShell(): string
    {
        $shell = (string) (getenv('SHELL') ?: '');
        if ($shell !== '' && is_executable($shell)) {
            return $shell;
        }
        return '/bin/sh';
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
    private function themedRasterizerWithFonts(Theme $theme, string $fontFamily, int $fontSize): Rasterizer
    {
        if ($this->rasterizer instanceof GdRasterizer) {
            return $this->rasterizer->withFont($fontFamily, $fontSize)->withTheme($theme);
        }
        if ($this->rasterizer instanceof ImagickRasterizer) {
            return $this->rasterizer->withFont($fontFamily, $fontSize)->withTheme($theme);
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
        // Keep: glob()->unlink() TOCTOU races the encoder child's own
        // teardown; a file already gone is the success case, and rmdir only
        // fails when a straggler lingers — neither is actionable.
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
    /**
     * Fail loud at the backend-resolution choke when the PHP extension a
     * rasterizer backend needs is not loaded. Without this door a gd-less
     * install reaches `new GdRasterizer(...)` and dies later inside the
     * first imagecreate() with a plain "Call to undefined function" fatal
     * that names neither the extension nor the remedy; the imagick leg
     * would fatal as a class-not-found on {@see ImagickRasterizer}'s
     * ext-imagick types. Unknown backends fall through to the gd leg, so
     * they are probed as gd (matching the match() arm they will take).
     *
     * @param callable(string):bool|null $extensionLoaded seam for tests to
     *                                                     simulate a
     *                                                     backend-less
     *                                                     install
     *
     * @throws \RuntimeException when the backend's extension is unavailable
     */
    public static function assertBackendAvailable(string $backend, ?callable $extensionLoaded = null): void
    {
        $extensionLoaded ??= static fn (string $name): bool => extension_loaded($name);
        $extension = $backend === 'imagick' ? 'imagick' : 'gd';
        $alternative = $extension === 'gd' ? 'imagick' : 'gd';

        if ($extensionLoaded($extension)) {
            return;
        }

        throw new \RuntimeException(
            "the '{$backend}' rasterizer backend requires the {$extension} PHP extension, "
            . "which is not loaded. Install/enable ext-{$extension}, or pick --backend={$alternative}.",
        );
    }

    public static function create(array $options = []): self
    {
        $backend = $options['backend'] ?? 'gd';
        $encoderType = $options['encoder'] ?? 'ffmpeg';
        $fontSize = (int) ($options['fontSize'] ?? 14);
        $fontFamily = $options['fontFamily'] ?? 'JetBrainsMono';

        self::assertBackendAvailable($backend);

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
