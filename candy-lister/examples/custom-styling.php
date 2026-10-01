<?php

declare(strict_types=1);

/**
 * CandyLister — custom Prefixer and Suffixer demo.
 *
 * Run: php examples/custom-styling.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Lister\{Model, StringItem, Prefixer, Suffixer};

class StarsPrefixer implements Prefixer {
    public int $cursorIndex = 0;
    public function initPrefixer(
        \Stringable $value, int $currentIndex, int $cursorIndex,
        int $lineOffset, int $width, int $height, int $totalItems
    ): int {
        $this->cursorIndex = $currentIndex;
        return 3; // width for "*** "
    }
    public function prefix(int $currentLine, int $totalLines): string {
        return $currentLine === 0 ? '★  ' : '✦  ';
    }
}

class CursorMarker implements Suffixer {
    private int $itemIndex = 0;
    private int $cursorIndex = 0;
    private int $markerWidth = 2;

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
        return '   ';
    }
}

$model = Model::new()
    ->setViewport(80, 25)
    ->setPrefixer(new StarsPrefixer())
    ->setSuffixer(new CursorMarker());

$items = [
    'File: main.php',
    'File: utils.php',
    'File: config.yaml',
    'Directory: src/',
    'Directory: tests/',
];

foreach ($items as $item) {
    $model->addItem(new StringItem($item));
}

echo "=== Custom Prefixer (★) + Suffixer (◉) ===\n";
echo $model->View();
