<?php

declare(strict_types=1);

namespace SugarCraft\CandyImage;

use SugarCraft\Animation\Decoder;
use SugarCraft\Animation\Player;
use SugarCraft\Animation\Renderer;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Image\Detect;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Mosaic;
use SugarCraft\Image\Scale;
use SugarCraft\Image\TermDb;
use SugarCraft\Pty\SizeIoctl;

/**
 * Command-line entry point: parse argv, detect the terminal (or honour the
 * explicit --format), decode the image through sugar-image, and write the
 * rendered bytes to stdout.
 *
 * F-18: the chafa-independent viewer surface. This class is COMPOSITION
 * ONLY — detection lives in {@see Detect}, protocol selection in
 * {@see Mosaic}, decode/scale in {@see ImageSource}/{@see Scale}. No render
 * logic may ever land here (façade law, plan_chafa.md §0). The bin is a
 * two-line shim so every branch is testable in-process; the optional $play
 * seam swaps the blocking animation Program for a callback, mirroring
 * candy-gifview's harness shape.
 *
 * Chafa flag-compat vocabulary (survey :870-875) and what this base honors:
 * --format/--size/--scale/--probe-shape/--dump-detect are wired; --polite is
 * an accepted no-op (this viewer always renders inline and synchronously);
 * --symbols and --passthrough=screen refuse with a pointer: the backing
 * features (S6.2 symbol-map engine / S7.1 Screen envelope) HAVE landed in
 * sugar-image, but the app-surface dials are deliberately unwired here
 * (symbols deferred per REACH-A04; the Screen envelope is detection-chosen,
 * never forced); --probe=off has
 * no seam in Detect yet, so the spelling is refused like any unknown flag.
 * --format=chafa is REFUSED by design: F-18's whole point is a viewer that
 * does not shell out to chafa.
 */
final class Cli
{
    public const VERSION = '0.1.0';

    public const EXIT_OK = 0;
    public const EXIT_USAGE = 1;
    public const EXIT_INPUT = 2;

    /** Viewport used when STDOUT is not a tty (piped output, CI, examples). */
    public const FALLBACK_CELLS_W = 60;
    public const FALLBACK_CELLS_H = 24;

    /**
     * --format vocabulary: every {@see Mosaic::fromModeString()} arm on this
     * base EXCEPT 'chafa', which is refused separately with its own message.
     * Text-grid tiers are spelled with chicle's short names as aliases.
     */
    private const FORMATS = [
        'auto', 'sixel', 'kitty', 'iterm2',
        'half', 'halfblock', 'quarter', 'quarterblock',
        'ascii', 'ansi16', 'ansi256', 'truecolor',
    ];

    /** chicle-style --scale spellings mapped onto the Scale enum. */
    private const SCALES = [
        'fit'     => Scale::Fit,
        'shrink'  => Scale::ShrinkToFit,
        'fill'    => Scale::Fill,
        'stretch' => Scale::Stretch,
        'none'    => Scale::None,
        'crop'    => Scale::Crop,
    ];

    private function __construct()
    {
    }

    /**
     * Run the viewer front end.
     *
     * @param list<string> $argv   full argument vector, script name at index 0
     * @param resource     $stdout stream for rendered bytes / info output
     * @param resource     $stderr stream for usage and error output
     * @param ?\Closure(Player): int $play test seam: invoked with the wired
     *        Player instead of launching the blocking Program loop; its int
     *        becomes the exit code.
     */
    public static function main(array $argv, $stdout, $stderr, ?\Closure $play = null): int
    {
        $file = null;
        $format = 'auto';
        $width = null;
        $height = null;
        $scale = null;
        $animate = false;
        $dump = false;
        $passthrough = 'auto';
        $sizeSeen = false;

        foreach (array_slice($argv, 1) as $token) {
            if ($token === '--help' || $token === '-h') {
                \fwrite($stdout, Lang::t('cli.usage') . "\n");

                return self::EXIT_OK;
            }

            if ($token === '--version') {
                \fwrite($stdout, 'candy-image ' . self::VERSION . "\n");

                return self::EXIT_OK;
            }

            if ($token === '--dump-detect') {
                $dump = true;
                continue;
            }

            if ($token === '--animate') {
                $animate = true;
                continue;
            }

            // chafa's --polite selects its async-worker render model; this
            // viewer is inline and synchronous by construction, so the flag
            // is accepted for muscle-memory and changes nothing (documented).
            if ($token === '--polite' || str_starts_with($token, '--polite=')) {
                continue;
            }

            if ($token === '--symbols' || str_starts_with($token, '--symbols=')) {
                \fwrite($stderr, Lang::t('cli.symbols_refused') . "\n");

                return self::EXIT_USAGE;
            }

            if (\str_starts_with($token, '--')) {
                $flag = substr($token, 2);
                $raw = null;
                if (($eq = strpos($flag, '=')) !== false) {
                    $raw = substr($flag, $eq + 1);
                    $flag = substr($flag, 0, $eq);
                }

                if (!in_array($flag, ['format', 'width', 'height', 'size', 'scale', 'passthrough'], true)) {
                    \fwrite($stderr, Lang::t('cli.bad_flag', ['flag' => $flag]) . "\n");

                    return self::EXIT_USAGE;
                }

                if ($raw === null || $raw === '') {
                    \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--' . $flag, 'value' => '']) . "\n");

                    return self::EXIT_USAGE;
                }

                $bad = static function () use ($stderr, $flag, $raw): int {
                    \fwrite($stderr, Lang::t('cli.bad_value', ['flag' => '--' . $flag, 'value' => $raw]) . "\n");

                    return self::EXIT_USAGE;
                };

                switch ($flag) {
                    case 'format':
                        if ($raw === 'chafa') {
                            \fwrite($stderr, Lang::t('cli.chafa_refused') . "\n");

                            return self::EXIT_USAGE;
                        }
                        if (!in_array(strtolower($raw), self::FORMATS, true) || Mosaic::fromModeString($raw) === null) {
                            \fwrite($stderr, Lang::t('cli.bad_format', ['value' => $raw]) . "\n");

                            return self::EXIT_USAGE;
                        }
                        $format = strtolower($raw);
                        break;
                    case 'width':
                        if ($sizeSeen) {
                            return $bad();
                        }
                        $width = self::parseCells($raw);
                        if ($width === null) {
                            return $bad();
                        }
                        break;
                    case 'height':
                        if ($sizeSeen) {
                            return $bad();
                        }
                        $height = self::parseCells($raw);
                        if ($height === null) {
                            return $bad();
                        }
                        break;
                    case 'size':
                        if ($width !== null || $height !== null) {
                            return $bad();
                        }
                        $box = self::parseSize($raw);
                        if ($box === null) {
                            return $bad();
                        }
                        [$width, $height] = $box;
                        $sizeSeen = true;
                        break;
                    case 'scale':
                        $case = self::SCALES[strtolower($raw)] ?? null;
                        if ($case === null) {
                            return $bad();
                        }
                        $scale = $case;
                        break;
                    case 'passthrough':
                        $value = strtolower($raw);
                        if ($value === 'auto' || $value === 'none') {
                            $passthrough = $value;
                            break;
                        }
                        \fwrite($stderr, Lang::t('cli.passthrough_refused', ['value' => $raw]) . "\n");

                        return self::EXIT_USAGE;
                }
                continue;
            }

            if ($file === null) {
                $file = $token;
                continue;
            }

            \fwrite($stderr, Lang::t('cli.too_many', ['arg' => $token]) . "\n");

            return self::EXIT_USAGE;
        }

        Detect::setAutoPassthrough($passthrough === 'auto');

        if ($dump) {
            return self::dumpDetect($stdout);
        }

        if ($file === null) {
            \fwrite($stderr, Lang::t('cli.usage') . "\n");

            return self::EXIT_USAGE;
        }

        [$viewportW, $viewportH] = self::viewportCells();
        $cellsW = $width ?? $viewportW;

        if ($animate) {
            return self::animate($file, $cellsW, $height ?? $viewportH, $stdout, $stderr, $play);
        }

        try {
            $image = ImageSource::fromFile($file);
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            \fwrite($stderr, $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        }

        try {
            $mosaic = Mosaic::fromModeString($format) ?? Mosaic::auto();
            if ($scale !== null) {
                $mosaic = $mosaic->withScale($scale);
            }
            $bytes = $mosaic->render($image, $cellsW, $height);
        } catch (\Throwable $e) {
            \fwrite($stderr, $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        }

        \fwrite($stdout, $bytes);
        if (!str_ends_with($bytes, "\n")) {
            \fwrite($stdout, "\n");
        }

        return self::EXIT_OK;
    }

    /**
     * Print the resolved terminal detection state — the chafa
     * --dump-detect surface that answers "why is my image blank in this
     * terminal" (survey :870-875). Read-only: env table, TermDb match,
     * Detect capability, DA1 reply, auto() selection, palette report.
     *
     * @param resource $stdout
     */
    private static function dumpDetect($stdout): int
    {
        $write = static function (string $line) use ($stdout): void {
            \fwrite($stdout, $line . "\n");
        };

        $write('candy-image ' . self::VERSION . ' — terminal detection dump');
        $write('env: TERM=' . (getenv('TERM') !== false && getenv('TERM') !== '' ? getenv('TERM') : '(unset)')
            . '  COLORTERM=' . (getenv('COLORTERM') !== false && getenv('COLORTERM') !== '' ? getenv('COLORTERM') : '(unset)'));

        $info = TermDb::matchChain(TermDb::snapshotEnv());
        $passthrough = [];
        foreach ($info->passthrough as $protocol => $needed) {
            $passthrough[] = $protocol . ($needed ? '=needs' : '=direct');
        }
        $write('term-db: chain=' . $info->chain
            . '  claims=' . ($info->claims === [] ? '(none)' : implode(',', $info->claims))
            . '  passthrough=' . ($passthrough === [] ? '(none)' : implode(',', $passthrough))
            . '  mux=' . ($info->mux ?? '(none)'));

        $cap = Detect::probe();
        $write('detect: sixel=' . ($cap->sixel ? 'yes' : 'no')
            . '  kitty=' . ($cap->kitty ? 'yes' : 'no')
            . '  iterm2=' . ($cap->iterm2 ? 'yes' : 'no')
            . '  halfblock=' . ($cap->halfblock ? 'yes' : 'no')
            . '  chafa=' . ($cap->chafa ? 'yes' : 'no')
            . '  tmux=' . ($cap->inTmux ? 'yes' : 'no'));
        $write('detect: summary=' . $cap->detectSummary());
        $write('cell-px: ' . ($cap->cellSize === null
            ? '(not probed)'
            : $cap->cellSize->cellWidth . 'x' . $cap->cellSize->cellHeight));
        $write('default-fg: ' . ($cap->defaultFg ?? '(unknown)') . '  default-bg: ' . ($cap->defaultBg ?? '(unknown)'));

        $da1 = Detect::lastDa1Reply();
        $write('da1-reply: ' . ($da1 === null ? '(none)' : str_replace("\x1b", 'ESC', $da1)));

        $mosaic = Mosaic::auto();
        $write('auto: protocol=' . $mosaic->protocol() . '  mode=' . ($mosaic->modeDetail() ?? '-'));
        $write('tmux-transaction: ' . (Detect::autoPassthrough() ? 'enabled' : 'disabled'));

        try {
            $report = Mosaic::diagnose();
            $lines = [];
            foreach ($report->allWithSource() as $capability => $source) {
                $lines[] = $capability . ' (' . $source . ')';
            }
            $write('palette: ' . ($lines === [] ? '(no capabilities)' : implode(', ', $lines)));
        } catch (\Throwable) {
            $write('palette: (probe failed)');
        }

        return self::EXIT_OK;
    }

    /**
     * Animation pass-through: decode via sugar-animation and hand the
     * frames to its Player inside a Program — the sugar-animation seam
     * sugar-video's Player-side playback uses; full playback controls
     * (fps/speed/duration, preset toggle) live in candy-gifview.
     *
     * @param resource $stdout
     * @param resource $stderr
     * @param ?\Closure(Player): int $play
     */
    private static function animate(string $file, int $cellsW, int $cellsH, $stdout, $stderr, ?\Closure $play): int
    {
        try {
            $frames = Decoder::decode($file, $cellsW, $cellsH);
        } catch (\RuntimeException $e) {
            \fwrite($stderr, $e->getMessage() . "\n");

            return self::EXIT_INPUT;
        }

        if ($frames === []) {
            \fwrite($stderr, Lang::t('cli.no_frames', ['path' => $file]) . "\n");

            return self::EXIT_INPUT;
        }

        $renderer = Renderer::withConstraints($cellsH, $cellsW);
        $player = new Player($frames, renderer: $renderer);

        if ($play !== null) {
            return $play($player);
        }

        (new Program($player, new ProgramOptions(useAltScreen: true)))->run();

        return self::EXIT_OK;
    }

    /**
     * Parse a positive cell count for --width/--height; anything else
     * (non-numeric, zero, negative, fractional) refuses with null.
     */
    private static function parseCells(string $raw): ?int
    {
        if (preg_match('/^\d+$/', $raw) !== 1) {
            return null;
        }
        $value = (int) $raw;

        return $value >= 1 ? $value : null;
    }

    /**
     * Parse chafa's --size=WxH box spelling into [width, height]; a
     * missing or non-positive leg refuses with null.
     *
     * @return array{int, int}|null
     */
    private static function parseSize(string $raw): ?array
    {
        if (preg_match('/^(\d+)x(\d+)$/', $raw, $m) !== 1) {
            return null;
        }
        $w = (int) $m[1];
        $h = (int) $m[2];
        if ($w < 1 || $h < 1) {
            return null;
        }

        return [$w, $h];
    }

    /**
     * Size the render box to the terminal viewport (cols, rows-1 status
     * allowance for the animate path). Falls back to 60x24 when STDOUT is
     * not a tty (e.g. piped/CI).
     *
     * Descriptor 1 is spelled as the literal — casting the STDOUT constant
     * to int yields the resource id, which names STDERR on a CLI process.
     * stream_isatty() is core PHP, so the gate needs no ext-posix either.
     *
     * @return array{int, int} [cellsW, cellsH]
     */
    public static function viewportCells(): array
    {
        if (\is_resource(\STDOUT) && stream_isatty(STDOUT)) {
            $size = SizeIoctl::query(1);

            return [$size['cols'], max(1, $size['rows'] - 1)];
        }

        return [self::FALLBACK_CELLS_W, self::FALLBACK_CELLS_H];
    }
}
