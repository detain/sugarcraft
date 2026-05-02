<?php

declare(strict_types=1);

/**
 * MultiSelect field — multi-choice with min/max bounds.
 *
 *   php examples/multi-select.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Prompt\Field\MultiSelect;

$field = MultiSelect::new('toppings')
    ->withTitle('Toppings')
    ->withDescription('Pick 1-3.')
    ->withOptions('cheese', 'mushrooms', 'olives', 'pepperoni', 'pineapple', 'jalapeño')
    ->withMin(1)
    ->withMax(3);

[$focused] = $field->focus();
echo $focused->view() . "\n";
