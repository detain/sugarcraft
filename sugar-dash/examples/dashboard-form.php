<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Button};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Form\{Input, Select, Checkbox, Toggle, Slider};

// Dashboard Form Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Form inputs
$nameInput = Input::labeled('John Doe', 'Full Name');
$emailInput = Input::labeled('john@example.com', 'Email Address');
$roleSelect = Select::new([
    ['label' => 'Admin'],
    ['label' => 'User'],
    ['label' => 'Guest'],
]);

$notificationsToggle = Toggle::new('Enable Notifications', true);
$darkModeToggle = Toggle::new('Dark Mode', false);

$volumeSlider = Slider::new(75);

$formStack = VStack::spaced(2,
    $nameInput,
    $emailInput,
    Frame::new(
        VStack::spaced(1,
            Text::new('Role'),
            $roleSelect
        )
    )->withPadding(1),
    $notificationsToggle,
    $darkModeToggle,
    Frame::new(
        VStack::spaced(1,
            Text::new('Volume: 75'),
            $volumeSlider
        )
    )->withPadding(1),
    HStack::spaced(1,
        Button::new('Save'),
        Button::new('Cancel')
    )
);

$termsCheckbox = Checkbox::new('I agree to the terms and conditions', false);

$mainContent = VStack::spaced(2,
    Card::titled($formStack, 'User Settings'),
    Card::titled($termsCheckbox, 'Agreement')
);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Form Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(80, 30);
echo $grid->render();
