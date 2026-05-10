<?php

declare(strict_types=1);

/**
 * Progress — animated bar from 0% to 100%, then a static showcase
 * of three styling variants (default, gradient, custom runes), then
 * a showcase of the three render modes (Block, Line, Slim).
 *
 *   php examples/progress.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Bits\Progress\Progress;
use SugarCraft\Bits\Progress\ProgressRenderMode;

// Animate fill 0 → 100.
$p = Progress::new()->withWidth(40)->withShowPercent(true);
for ($i = 0; $i <= 100; $i += 5) {
    printf("\r  %s", $p->withPercent($i / 100)->view());
    usleep(40_000);
}
echo "\n\n";

// Three variants side-by-side at 60%.
$variants = [
    'default'  => Progress::new()->withWidth(30),
    'gradient' => Progress::new()->withWidth(30)->withDefaultGradient(),
    'runes'    => Progress::new()->withWidth(30)->withRunes('▰', '▱'),
];
foreach ($variants as $label => $bar) {
    printf("  \x1b[36m%-9s\x1b[0m %s\n", $label, $bar->withPercent(0.6)->view());
}

echo "\n";

// Three render modes (Block, Line, Slim) at 60%.
$renderModes = [
    'Block' => ProgressRenderMode::Block,
    'Line'  => ProgressRenderMode::Line,
    'Slim'  => ProgressRenderMode::Slim,
];
foreach ($renderModes as $label => $mode) {
    $bar = Progress::new()
        ->withWidth(30)
        ->withRenderMode($mode)
        ->withShowPercent($mode !== ProgressRenderMode::Line)
        ->withPercentFormat('%d%%');
    printf("  \x1b[36m%-9s\x1b[0m %s\n", $label, $bar->withPercent(0.6)->view());
}
