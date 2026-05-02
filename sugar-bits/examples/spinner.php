<?php

declare(strict_types=1);

/**
 * Run every spinner style for a couple of seconds each. Useful for
 * picking a style that matches your CLI vibe.
 *
 *   php examples/spinner.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Spinner\Style as SpinStyle;

$styles = [
    'line'      => SpinStyle::line(),
    'dot'       => SpinStyle::dot(),
    'miniDot'   => SpinStyle::miniDot(),
    'points'    => SpinStyle::points(),
    'pulse'     => SpinStyle::pulse(),
    'globe'     => SpinStyle::globe(),
    'meter'     => SpinStyle::meter(),
    'jump'      => SpinStyle::jump(),
    'moon'      => SpinStyle::moon(),
    'monkey'    => SpinStyle::monkey(),
    'hamburger' => SpinStyle::hamburger(),
    'ellipsis'  => SpinStyle::ellipsis(),
];

foreach ($styles as $name => $style) {
    $deadline = microtime(true) + 1.5;
    $i = 0;
    while (microtime(true) < $deadline) {
        $glyph = $style->frames[$i++ % count($style->frames)];
        printf("\r  %-10s %s   ", $name, $glyph);
        usleep((int) ($style->interval() * 1_000_000));
    }
    echo "\n";
}
