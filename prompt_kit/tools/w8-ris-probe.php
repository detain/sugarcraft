<?php

declare(strict_types=1);

/*
 * W8-F differential probe: `ESC c` (RIS) on the two candy-vt engines.
 *
 * Feeds each probe stream through the emulator (`SugarCraft\Vt\Terminal\Terminal`)
 * and the vcr renderer path (`SugarCraft\Vt\Terminal`, composed in
 * `Terminal::new()` as Parser → RendererHandler → CsiHandlerImpl), then compares
 * every observable the two engines expose — normalised cell grid (char + masked
 * attrs), cursor home, DECTCEM visibility, the phantom-wrap flag, and the
 * post-RIS DECSC / pen / scroll-region / DECAWM / REP-memory behaviour that each
 * follow-up stream makes visible.
 *
 * Usage: php prompt_kit/tools/w8-ris-probe.php   (exit 0 = full agreement)
 */

$autoload = __DIR__ . '/../../candy-vcr/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "missing candy-vcr vendor autoload — run refresh-deps --mode=linked first\n");
    exit(2);
}
require $autoload;

use SugarCraft\Vt\Terminal as Renderer;
use SugarCraft\Vt\Terminal\Terminal as Emulator;

const COLS = 20;
const ROWS = 6;

/** @return array{grid: string, visible: bool, home: bool, wrapPending: bool} */
function observeRenderer(string $bytes): array
{
    $t = Renderer::new(COLS, ROWS)->feed($bytes);
    $grid = $t->grid();
    $dump = '';
    for ($r = 0; $r < $grid->rows; $r++) {
        for ($c = 0; $c < $grid->cols; $c++) {
            $cell = $grid->cell($r, $c);
            $dump .= sprintf('%s|%d|', $cell->char === '' ? ' ' : $cell->char, $cell->attrs & 0x1F);
        }
    }

    return [
        'grid' => $dump,
        'visible' => $t->cursor()->visible,
        'home' => $t->cursor()->row === 0 && $t->cursor()->col === 0,
        'wrapPending' => $t->isWrapPending(),
    ];
}

/** @return array{grid: string, visible: bool, home: bool, wrapPending: bool} */
function observeEmulator(string $bytes): array
{
    $t = Emulator::new(COLS, ROWS);
    $t->feed($bytes);
    $screen = $t->screen();
    $dump = '';
    for ($r = 0; $r < ROWS; $r++) {
        for ($c = 0; $c < COLS; $c++) {
            $cell = $screen->cell($r, $c);
            $grapheme = $cell->continuation || $cell->grapheme === '' ? ' ' : $cell->grapheme;
            $sgr = $cell->sgr();
            $mask = ($sgr->bold ? 1 : 0) | ($sgr->italic ? 2 : 0)
                | (($sgr->underline || $sgr->underlineStyle->value !== 0) ? 4 : 0)
                | ($sgr->reverse ? 8 : 0) | ($sgr->strikethrough ? 16 : 0);
            $dump .= sprintf('%s|%d|', $grapheme, $mask);
        }
    }

    return [
        'grid' => $dump,
        'visible' => $t->cursor()->visible,
        'home' => $t->cursor()->row === 0 && $t->cursor()->col === 0,
        'wrapPending' => $t->isWrapPending(),
    ];
}

$cases = [
    'RIS homes and clears the grid' => ['junk\x1bcNW', true],
    'RIS restores DECTCEM visibility' => ["\x1b[?25l\x1bc", true],
    'RIS restores the full-page scroll region' => ["\x1b[2;4r" . str_repeat('a', 60) . "\x1bc" . str_repeat('b', 25), true],
    'RIS restores DECAWM' => ["\x1b[?7l" . str_repeat('z', 25) . "\x1bc" . str_repeat('w', 25), true],
    'RIS disarms the phantom flag' => [str_repeat('x', 20) . "\x1bc", true],
    'RIS drops the DECSC slot (ESC 8 → home)' => ["AB\x1b7\x1bc\x1b8X", true],
    'RIS drops REP memory' => ["A\x1bc\x1b[3b", true],
    'RIS drops the pen incl. truecolour' => ["\x1b[1;38;2;9;8;7mP\x1bcQ", true],
];

$fails = 0;
foreach ($cases as $label => [$bytes, ]) {
    $bytes = str_contains($bytes, '\x') ? stripcslashes($bytes) : $bytes;
    $r = observeRenderer($bytes);
    $e = observeEmulator($bytes);
    $diffs = [];
    foreach (['grid', 'visible', 'home', 'wrapPending'] as $key) {
        if ($r[$key] !== $e[$key]) {
            $diffs[] = sprintf('%s: renderer=%s emulator=%s', $key, var_export($r[$key], true), var_export($e[$key], true));
        }
    }
    if ($diffs === []) {
        printf("PASS  %s\n", $label);
        continue;
    }
    ++$fails;
    printf("FAIL  %s\n      %s\n", $label, implode("\n      ", $diffs));
}

printf("\n%d/%d probe streams agree on emulator↔renderer RIS observables\n", count($cases) - $fails, count($cases));
exit($fails === 0 ? 0 : 1);
