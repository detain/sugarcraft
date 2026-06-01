<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Form\{Input, Checkbox, Toggle, Slider};
use SugarCraft\Dash\Components\Select\Select;

/**
 * Dashboard Form - showcasing form components
 *
 * Shows various form components including Input, Select, Toggle, Checkbox, and Slider
 * in a multi-column framed layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Form Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// LEFT COLUMN: User Settings Form
// ============================================
$nameInput = Input::new('John Doe');
$emailInput = Input::new('john@example.com');
$roleSelect = Select::new([
    ['label' => 'Admin'],
    ['label' => 'User'],
    ['label' => 'Guest'],
]);

$formStack = VStack::spaced(1,
    $nameInput,
    $emailInput,
    Text::new('Role:'),
    $roleSelect
);
$formFrame = Card::titled($formStack, 'User Information');

// ============================================
// RIGHT COLUMN: Settings Toggles
// ============================================
$notificationsToggle = Toggle::on();
$darkModeToggle = Toggle::off();
$volumeSlider = Slider::new(75.0);

$settingsStack = VStack::spaced(1,
    Text::new('Notifications:'),
    $notificationsToggle,
    Text::new('Dark Mode:'),
    $darkModeToggle,
    Text::new('Volume: 75'),
    $volumeSlider
);
$settingsFrame = Card::titled($settingsStack, 'Preferences');

$grid->addItem(
    $formFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $settingsFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// BOTTOM: Agreement Checkbox
// ============================================
$termsCheckbox = Checkbox::new([['label' => 'I agree to the terms and conditions', 'checked' => false]]);
$agreementFrame = Card::titled($termsCheckbox, 'Agreement');

$grid->addItem(
    $agreementFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(80, 30);
echo $grid->render();
