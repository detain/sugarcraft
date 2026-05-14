<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Form\{Input, Checkbox, Toggle, Slider};
use SugarCraft\Dash\Components\Select\Select;

// Dashboard Form Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Form inputs
$nameInput = Input::new('John Doe');
$emailInput = Input::new('john@example.com');
$roleSelect = Select::new([
    ['label' => 'Admin'],
    ['label' => 'User'],
    ['label' => 'Guest'],
]);

$notificationsToggle = Toggle::on();
$darkModeToggle = Toggle::off();

$volumeSlider = Slider::new(75.0);

$formStack = VStack::spaced(2,
    $nameInput,
    $emailInput,
    Frame::new(
        VStack::spaced(1,
            new Text('Role'),
            $roleSelect
        )
    )->withPadding(1),
    $notificationsToggle,
    $darkModeToggle,
    Frame::new(
        VStack::spaced(1,
            new Text('Volume: 75'),
            $volumeSlider
        )
    )->withPadding(1)
);

$termsCheckbox = Checkbox::new([['label' => 'I agree to the terms and conditions', 'checked' => false]]);

$mainContent = VStack::spaced(2,
    Card::titled($formStack, 'User Settings'),
    Card::titled($termsCheckbox, 'Agreement')
);

$grid->addItem(
    Frame::new(HStack::new(new Text('Dashboard Form Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 30);
echo $grid->render();
