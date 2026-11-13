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
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\TreeList\{Model, StringItem, Prefixer, Suffixer};

class StarsPrefixer implements Prefixer {
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height, int $totalItems
    ): int {
        return 3; // width for '★  ' / '✦  ' — each glyph string is 3 cells
    }
    public function prefix(int $currentLine, int $totalLines): string {
        return $currentLine === 0 ? '★  ' : '✦  ';
    }
}

class CursorMarker implements Suffixer {
    private int $itemIndex = 0;
    private int $cursorIndex = 0;
    private int $markerWidth = 3; // ' ◉ ' is 3 cells; reserve the real width

    public function initSuffixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height
    ): int {
        $this->itemIndex   = $currentIndex;
        $this->cursorIndex = $cursorIndex;
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
