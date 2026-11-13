<?php

declare(strict_types=1);

/**
 * Runnable worked example — the Dock\* surface: an IDE-style center pane with
 * weighted vertical stacks on either side, resolved against a frame.
 *
 *     php examples/dock.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Constraints\Region;

$dock = DockLayout::new('chat')                 // center id; empty sides, 1/3 column shares
    ->withSlotAdded(Side::Left, 'files')
    ->withSlotAdded(Side::Left, 'git')
    ->withSlotAdded(Side::Right, 'term')
    ->withStackWeight(Side::Left, 0, 2, 1);     // files takes twice the rows of git

$dump = static function (string $label, Region $frame) use ($dock): void {
    $geometry = $dock->resolve($frame);
    echo "{$label}\n";
    foreach (['files', 'git', 'chat', 'term'] as $paneId) {
        $region = $geometry->regionFor($paneId);
        printf(
            "  %-6s => %s\n",
            $paneId,
            $region === null
                ? '(no region)'
                : sprintf('[x=%d y=%d w=%d h=%d]', $region->x, $region->y, $region->width, $region->height),
        );
    }
};

$dump('100 x 30:', Region::fromSize(100, 30));

// Divider columns the host should paint (the side gap is fixed at 1 column).
$wide = $dock->resolve(Region::fromSize(100, 30));
foreach ($wide->dividerColumns() as $divider) {
    printf("divider at x=%d (%s side)\n", $divider['x'], $divider['side']->name);
}

// Below the side minimums the ladder drops a side rather than throw —
// resolve() never raises on small frames.
$dump('30 x 10:', Region::fromSize(30, 10));
