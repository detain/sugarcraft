<?php

declare(strict_types=1);

/**
 * skinny-kit — ONE require for every skinny library.
 *
 *   require_once __DIR__ . '/skinny-kit.php';
 *
 * Loads (all prefixes are distinct, no clashes; none print anything when required):
 *   skinny-raster-kit.php   sk*   parse ANSI → cells, exact GD render, sub-pixel re-raster,
 *                                 skWriteSkinny() verified write + single-line logos.jsonl append
 *   skinny-b-kit.php        skb*  cut/paste/rescale cells, skbLayoutRows(), skbArgs(),
 *                                 skbWriteSet() (also removes your stale other-size files)
 *   skinny-stroke-font.php  ssf*  centre-line font → distance field → braille/sextant/quadrant ink
 *   skinny-type-fit.php     stf*  fit TTF words into cell boxes, stfEmbolden() thin strokes
 *   skinny-palette.php      sp*   hue-faithful tc → 256/16 (bands, pins, spQuantise before cell fit)
 *   skinny-depth.php        sd*   ROLE-aware encode: pin screen/shadow cells per depth, hue-keep the rest
 *   skinny-btop-parts.php   sb*   btop frame/tabs/boxes, braille area graphs, ■ meters, fake series
 *
 * Plus logo-kit.php (lk*) and the sextant/quadrant/ttf rasterisers they depend on.
 * CLI front-end for all the command-line tools: skinny.php
 */

foreach ([
    'skinny-raster-kit', 'skinny-b-kit', 'skinny-stroke-font', 'skinny-type-fit',
    'skinny-palette', 'skinny-depth', 'skinny-btop-parts',
] as $lib) {
    require_once __DIR__ . "/$lib.php";
}
