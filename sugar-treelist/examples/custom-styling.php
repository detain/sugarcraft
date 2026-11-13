<?php

declare(strict_types=1);

/**
 * SugarTreelist — custom Prefixer and Suffixer demo.
 *
 * Run: php examples/custom-styling.php
 *
 * Pattern note: prefix()/suffix() returns must be plain text — the Model
 * sweeps control/ANSI bytes and zone sentinels and drops any line break from
 * them before they enter the row, so style through the Model's setters, not
 * through escape sequences here.
 *
 * Reservation note: initPrefixer()/initSuffixer() must declare the cell width
 * your widest emission can carry. Measure it with Width::string() — the same
 * oracle the Model uses — because East-Asian-Ambiguous glyphs (★ ✦ ◉) are
 * 1 cell on ordinary hosts yet 2 on ambiguous-wide CJK configs, where a
 * hand-typed constant silently desyncs from the renderer.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\TreeList\{Model, StringItem, Prefixer, Suffixer};
use SugarCraft\Core\Util\Width;

class StarsPrefixer implements Prefixer {
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height, int $totalItems
    ): int {
        return Width::string('★  '); // measured, never hand-computed: the glyph
                                     // is 3 cells here, 4 on ambiguous-wide hosts
    }
    public function prefix(int $currentLine, int $totalLines): string {
        return $currentLine === 0 ? '★  ' : '✦  ';
    }
}

class CursorMarker implements Suffixer {
    private int $itemIndex = 0;
    private int $cursorIndex = 0;
    private int $markerWidth = 0; // measured at init from the widest emission

    public function initSuffixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height
    ): int {
        $this->itemIndex   = $currentIndex;
        $this->cursorIndex = $cursorIndex;
        $this->markerWidth = Width::string(' ◉ '); // the width you can emit
        return $this->markerWidth;
    }

    public function suffix(int $currentLine, int $totalLines): string {
        if ($this->itemIndex === $this->cursorIndex && $currentLine === 0) {
            return ' ◉ ';
        }
        return ''; // empty suffix = no padding on this line
    }
}

$model = Model::new()
    ->withViewport(80, 25)
    ->withPrefixer(new StarsPrefixer())
    ->withSuffixer(new CursorMarker());

$items = [
    'File: main.php',
    'File: utils.php',
    'File: config.yaml',
    'Directory: src/',
    'Directory: tests/',
];

foreach ($items as $item) {
    $model = $model->withItem(new StringItem($item)); // rebind — immutable model
}

echo "=== Custom Prefixer (★) + Suffixer (◉) ===\n";
echo $model->view();
