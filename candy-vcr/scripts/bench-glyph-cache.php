<?php

declare(strict_types=1);

/**
 * Benchmark: cross-frame glyph cache (vcr_use.md §6).
 *
 * Renders candy-vcr/.vhs/smoke.tape five times in two configurations:
 *  1. cache enabled  — the new default; Glyphs persists across snapshots.
 *  2. cache disabled — old behaviour; Glyphs is rebuilt every snapshot.
 *
 * Reports two measurements:
 *  - end-to-end (parse → compile → render → rasterize → encode → write)
 *  - rasterize-only (the part the cache actually affects)
 *
 * The end-to-end number is encoder-dominated for short tapes, so the
 * rasterize-only number is the honest demonstration of the cache's win.
 *
 * Usage: php candy-vcr/scripts/bench-glyph-cache.php
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoload)) {
    fwrite(STDERR, "bench: cannot locate vendor/autoload.php at {$autoload}\n");
    exit(1);
}
require $autoload;

use SugarCraft\Vcr\Encode\TapeToGif;
use SugarCraft\Vcr\Player;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vcr\Render\FrameDedup;
use SugarCraft\Vcr\Render\Renderer;
use SugarCraft\Vcr\Tape\Compiler;
use SugarCraft\Vcr\Tape\Lexer;
use SugarCraft\Vcr\Tape\Parser;
use SugarCraft\Vt\Terminal;
use SugarCraft\Vt\Theme;

$tape = __DIR__ . '/../.vhs/smoke.tape';
if (!is_file($tape)) {
    fwrite(STDERR, "bench: tape not found at {$tape}\n");
    exit(1);
}

$iters = 5;

// ----------------------------------------------------------------------
// End-to-end measurement: full TapeToGif::render() pipeline.
// ----------------------------------------------------------------------

$endToEnd = [];
foreach ([false, true] as $cacheDisabled) {
    $wallTimes = [];

    for ($i = 0; $i < $iters; $i++) {
        $t2g = TapeToGif::create([
            'encoder' => 'php',
            'backend' => 'gd',
        ]);

        // Reach into the rasterizer to toggle the cache for the bench harness.
        $reflection = new ReflectionClass($t2g);
        $rasterizerProp = $reflection->getProperty('rasterizer');
        $rasterizerProp->setAccessible(true);
        $rasterizer = $rasterizerProp->getValue($t2g);
        if ($rasterizer instanceof GdRasterizer) {
            $rasterizer->setCacheDisabled($cacheDisabled);
        }

        $output = sys_get_temp_dir() . '/bench-glyph-' . bin2hex(random_bytes(4)) . '.gif';

        $start = hrtime(true);
        try {
            $t2g->render($tape, $output, ['fps' => 30]);
        } catch (\Throwable $e) {
            fwrite(STDERR, "iteration {$i} (cacheDisabled=" . ($cacheDisabled ? 'true' : 'false') . ") failed: " . $e->getMessage() . "\n");
            @unlink($output);
            continue;
        }
        $elapsedNs = hrtime(true) - $start;
        @unlink($output);

        $wallTimes[] = $elapsedNs / 1e9;
    }

    $mean = array_sum($wallTimes) / count($wallTimes);
    $endToEnd[$cacheDisabled ? 'disabled' : 'enabled'] = [
        'mean_s' => $mean,
        'min_s' => min($wallTimes),
        'max_s' => max($wallTimes),
    ];
}

// ----------------------------------------------------------------------
// Rasterize-only measurement: loop just the rasterize() call across
// snapshots; this is the slice the cache actually affects.
// ----------------------------------------------------------------------

$source = (string) file_get_contents($tape);
$tokens = (new Lexer())->tokenize($source);
$ast = (new Parser())->parse($tokens);
$cassette = (new Compiler())->compile($ast, $tape);
$theme = Theme::tokyoNight();
$cellW = 8;
$cellH = 28;
$fonts = new FontLoader();

$renderer = new Renderer();
$term = Terminal::new($cassette->header->cols, $cassette->header->rows, $theme);
$stream = $renderer->render(new Player($cassette), $term, 30.0);
$snapshots = iterator_to_array(FrameDedup::dedup($stream), false);
$snapCount = count($snapshots);

$rasterizeOnly = [];
foreach ([false, true] as $cacheDisabled) {
    $wallTimes = [];

    for ($i = 0; $i < $iters; $i++) {
        $rasterizer = new GdRasterizer(14, 'JetBrainsMono', $theme);
        if ($cacheDisabled) {
            $rasterizer->setCacheDisabled(true);
        }

        $start = hrtime(true);
        foreach ($snapshots as $snap) {
            $image = $rasterizer->rasterize($snap, $cellW, $cellH, $fonts);
            imagedestroy($image);
        }
        $elapsed = (hrtime(true) - $start) / 1e9;
        $wallTimes[] = $elapsed;
    }

    $mean = array_sum($wallTimes) / count($wallTimes);
    $rasterizeOnly[$cacheDisabled ? 'disabled' : 'enabled'] = [
        'mean_s' => $mean,
        'min_s' => min($wallTimes),
        'max_s' => max($wallTimes),
    ];
}

// ----------------------------------------------------------------------
// Cache stats for transparency.
// ----------------------------------------------------------------------

$statsRasterizer = new GdRasterizer(14, 'JetBrainsMono', $theme);
foreach ($snapshots as $snap) {
    $image = $statsRasterizer->rasterize($snap, $cellW, $cellH, $fonts);
    imagedestroy($image);
}
$stats = $statsRasterizer->cacheStats();

// ----------------------------------------------------------------------
// Report.
// ----------------------------------------------------------------------

echo "candy-vcr glyph-cache benchmark\n";
echo str_repeat('=', 60) . "\n";
echo "tape:       " . realpath($tape) . "\n";
echo "snapshots:  {$snapCount} (post-dedup)\n";
echo "grid:       {$cassette->header->cols}x{$cassette->header->rows} cells\n";
echo "iters:      {$iters} per configuration\n\n";

echo "End-to-end (parse → compile → render → rasterize → encode → write):\n";
printf("  %-12s %-12s %-12s %-12s\n", 'config', 'mean (s)', 'min (s)', 'max (s)');
echo "  " . str_repeat('-', 48) . "\n";
foreach ($endToEnd as $label => $r) {
    printf("  %-12s %-12.4f %-12.4f %-12.4f\n", $label, $r['mean_s'], $r['min_s'], $r['max_s']);
}
$e2eSpeedup = $endToEnd['disabled']['mean_s'] / $endToEnd['enabled']['mean_s'];
printf("  Speedup: %.2fx (%.1f%% faster wall-clock)\n", $e2eSpeedup, (1 - 1 / $e2eSpeedup) * 100);
echo "\n";

echo "Rasterize-only (the slice the cache affects):\n";
printf("  %-12s %-12s %-12s %-12s\n", 'config', 'mean (s)', 'min (s)', 'max (s)');
echo "  " . str_repeat('-', 48) . "\n";
foreach ($rasterizeOnly as $label => $r) {
    printf("  %-12s %-12.4f %-12.4f %-12.4f\n", $label, $r['mean_s'], $r['min_s'], $r['max_s']);
}
$rSpeedup = $rasterizeOnly['disabled']['mean_s'] / $rasterizeOnly['enabled']['mean_s'];
printf("  Speedup: %.2fx (%.1f%% faster wall-clock)\n", $rSpeedup, (1 - 1 / $rSpeedup) * 100);
echo "\n";

echo "Cache stats on one full rasterize pass:\n";
printf("  hits=%d  misses=%d  hit-rate=%.1f%%\n", $stats['hits'], $stats['misses'], 100 * $stats['hits'] / max(1, $stats['hits'] + $stats['misses']));
