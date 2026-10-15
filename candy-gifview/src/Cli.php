<?php

declare(strict_types=1);

namespace SugarCraft\GifView;

use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Flip\Decoder;
use SugarCraft\Flip\Player;
use SugarCraft\Flip\Renderer;
use SugarCraft\Pty\SizeIoctl;

/**
 * Command-line entry point: parse argv, size the decode grid to the viewport,
 * hand the frames to candy-flip's Player, and run the SugarCraft Program.
 *
 * Mirrors charmbracelet-style gifterm front-end behaviour; extracted verbatim
 * from candy-flip's `bin/candy-flip` when the viewer CLI split out into this
 * app package (rename campaign step 1.3). The bin is a two-line shim over
 * {@see main()} so every branch here is testable in-process.
 */
final class Cli
{
    /** Viewport used when STDOUT is not a tty (piped output, CI, examples). */
    public const FALLBACK_CELLS_W = 60;
    public const FALLBACK_CELLS_H = 24;

    private function __construct()
    {
    }

    /**
     * Run the viewer front end.
     *
     * @param list<string> $argv full argument vector, script name at index 0
     * @param resource $stdout stream for usage/help output
     * @param resource $stderr stream for usage/error output
     * @param ?\Closure(Player): int $play test seam: invoked with the wired
     *        Player instead of launching the blocking Program loop; its int
     *        becomes the exit code.
     */
    public static function main(array $argv, $stdout, $stderr, ?\Closure $play = null): int
    {
        $path = $argv[1] ?? null;

        if ($path === null) {
            \fwrite($stderr, Lang::t('cli.usage') . "\n");
            return 1;
        }

        if ($path === '--help' || $path === '-h') {
            \fwrite($stdout, Lang::t('cli.usage') . "\n");
            return 0;
        }

        $preset = ($argv[2] ?? '') === Renderer::PRESET_DENSITY
            ? Renderer::PRESET_DENSITY
            : Renderer::PRESET_SOLID;

        [$cellsW, $cellsH] = self::viewportCells();

        try {
            $frames = Decoder::decode($path, cellsW: $cellsW, cellsH: $cellsH);
        } catch (\RuntimeException $e) {
            \fwrite($stderr, $e->getMessage() . "\n");
            return 1;
        }

        $renderer = Renderer::withConstraints($cellsH, $cellsW);
        $player = new Player($frames, preset: $preset, renderer: $renderer);

        if ($play !== null) {
            return $play($player);
        }

        (new Program($player, new ProgramOptions(useAltScreen: true)))->run();

        return 0;
    }

    /**
     * Size the decode grid to the terminal viewport (cols, rows-1 for the
     * status line). Falls back to 60x24 when STDOUT is not a tty
     * (e.g. piped/CI).
     *
     * Descriptor 1 is spelled as the literal: `(int) STDOUT` is the RESOURCE
     * ID 2, which names STDERR — the same defect fixed in
     * Renderer::withAdaptiveSize() and pinned by BinSizeQueryPolarityTest.
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
