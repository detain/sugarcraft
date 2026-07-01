<?php

declare(strict_types=1);

/**
 * SugarStickers FlexBox demo — CSS flexbox-like layout.
 *
 * Run: php examples/flexbox.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Stickers\Flex\{Align, Direction, FlexBox, FlexItem};

// Row layout: 3 panels in ratio 1:2:1
$row = FlexBox::row(
    FlexItem::new("LEFT\npanel\ncontent")->withRatio(1)->withStyle('34'),  // blue
    FlexItem::new("CENTER\nwide\npanel")->withRatio(2)->withStyle('32'),   // green
    FlexItem::new("RIGHT\npanel")->withRatio(1)->withStyle('31'),          // red
)->withGap(1);

echo "=== FlexBox Row (ratio 1:2:1, gap=1) ===\n";
echo $row->render(70, 6) . "\n\n";

// Column layout
$col = FlexBox::column(
    FlexItem::new("TOP SECTION")->withStyle('35'),       // magenta
    FlexItem::new("MIDDLE SECTION")->withRatio(2)->withStyle('33'),  // yellow
    FlexItem::new("BOTTOM")->withStyle('36'),            // cyan
)->withGap(1);

echo "=== FlexBox Column (ratio 1:2:1, gap=1) ===\n";
echo $col->render(50, 10) . "\n";
