<?php

declare(strict_types=1);

/**
 * The canonical "burger" form from huh's README. Builds a burger
 * order from three fields: bun + patty + extras.
 *
 * Renders the form's first frame; in a real interactive Program it
 * would Tab through each field collecting input.
 *
 *   php examples/burger.php
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Prompt\Field\Confirm;
use SugarCraft\Prompt\Field\MultiSelect;
use SugarCraft\Prompt\Field\Select;
use SugarCraft\Prompt\Form;

$form = Form::new(
    Select::new('bun')
        ->withTitle('Bun')
        ->withDescription('Pick the foundation of your sandwich.')
        ->withOptions('Brioche', 'Sesame', 'Pretzel', 'Lettuce wrap'),

    Select::new('patty')
        ->withTitle('Patty')
        ->withDescription('Hot off the griddle.')
        ->withOptions('Beef', 'Chicken', 'Veggie', 'Mushroom'),

    MultiSelect::new('extras')
        ->withTitle('Extras')
        ->withDescription('Pick as many as you like.')
        ->withOptions('Cheese', 'Lettuce', 'Tomato', 'Pickles', 'Onion', 'Bacon'),

    Confirm::new('confirm')
        ->withTitle('Place order?')
        ->withLabels('Yes, please', 'Cancel'),
);

echo $form->view() . "\n";
