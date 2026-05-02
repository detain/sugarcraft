<?php

declare(strict_types=1);

/**
 * Paginator — render dot- and arabic-style pagination at three
 * positions (start / middle / end).
 *
 *   php examples/paginator.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Bits\Paginator\Paginator;
use CandyCore\Bits\Paginator\Type;

$dots   = Paginator::new()->withTotalItems(50)->withPerPage(5)->withType(Type::Dots);
$arabic = Paginator::new()->withTotalItems(50)->withPerPage(5)->withType(Type::Arabic);

foreach ([0, 4, 9] as $page) {
    printf("  page %2d   %s    %s\n",
        $page + 1,
        $dots->withPage($page)->view(),
        $arabic->withPage($page)->view(),
    );
}
