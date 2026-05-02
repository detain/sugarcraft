<?php

declare(strict_types=1);

/**
 * Picture — render a small RGB grid as a Sixel image.
 *
 * Most VHS-recordable terminals don't actually render Sixel
 * (vhs uses ttyd, which dumps the raw escape bytes). This demo
 * shows the encoded output as labelled hex so you can see the
 * structure even when the renderer can't paint it.
 *
 *   php examples/picture.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Charts\Picture\Picture;
use CandyCore\Charts\Picture\Protocol;
use CandyCore\Core\Util\Color;

// 16×6 gradient (red→blue, top→bottom darker)
$pixels = [];
for ($y = 0; $y < 6; $y++) {
    $row = [];
    for ($x = 0; $x < 16; $x++) {
        $r = (int) (255 * (1 - $x / 15));
        $b = (int) (255 * ($x / 15));
        $g = (int) (255 * (1 - $y / 5) * 0.3);
        $row[] = Color::rgb($r, $g, $b);
    }
    $pixels[] = $row;
}

echo "\x1b[36mSixel encoding for a 16×6 gradient\x1b[0m\n";
$picture = Picture::fromGrid($pixels)->withProtocol(Protocol::Sixel);
$bytes = $picture->view();
printf("  encoded length: %d bytes\n", strlen($bytes));
printf("  first 80 bytes (hex): %s…\n", bin2hex(substr($bytes, 0, 40)));
echo "\n";

// Picture::detect() picks the right protocol based on $TERM /
// $TERM_PROGRAM at the moment of the call.
$detected = Picture::detect();
echo "\x1b[36mProtocol auto-detect (TERM = " . (getenv('TERM') ?: '?') . ")\x1b[0m\n";
echo "  → " . ($detected?->value ?? 'none — no compatible protocol detected') . "\n";
