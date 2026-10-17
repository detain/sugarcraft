<?php

declare(strict_types=1);

/**
 * List — three flavours: default bullet, custom enumerator, and a
 * nested list with mixed enumerators.
 *
 *   php examples/list.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Listing\Enumerator;
use SugarCraft\Style\Listing\ItemList;
use SugarCraft\Style\Style;

echo "\x1b[36mDefault\x1b[0m\n";
echo ItemList::new()
        ->items(['Sugar', 'Honey', 'Candy', 'Sprinkles'])
        ->render() . "\n\n";

echo "\x1b[36mRoman enumerator\x1b[0m\n";
echo ItemList::new()
        ->enumerator(Enumerator::roman())
        ->items(['First task', 'Second task', 'Third task'])
        ->enumeratorStyle(Style::new()->foreground(Color::hex('#ff5fd2')))
        ->render() . "\n\n";

echo "\x1b[36mNested\x1b[0m\n";
echo ItemList::new()
        ->item('Top-level item')
        ->item(
            ItemList::new()
                ->enumerator(Enumerator::dash())
                ->items(['nested A', 'nested B', 'nested C']),
        )
        ->item('Another top-level item')
        ->render() . "\n";
