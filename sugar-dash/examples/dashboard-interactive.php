<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, Frame, Text, Options, ItemOptions, Card, Accordion, Timeline, Stepper, Bullet, Rating, ProgressBar, Progress, SwitchComponent, Toggle};

// Dashboard Interactive Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Accordion
$accordion = Accordion::new([
    ['label' => 'Section 1: Getting Started', 'content' => 'Welcome to SugarDash! This is the getting started guide.'],
    ['label' => 'Section 2: Features', 'content' => 'SugarDash provides 200+ TUI components for PHP.'],
    ['label' => 'Section 3: Examples', 'content' => 'Check out the examples directory for demos.'],
]);

// Timeline
$timeline = Timeline::new([
    ['label' => 'Project Start', 'time' => 'Jan 1, 2024'],
    ['label' => 'Alpha Release', 'time' => 'Mar 15, 2024'],
    ['label' => 'Beta Release', 'time' => 'Jun 30, 2024'],
    ['label' => 'v1.0 Launch', 'time' => 'Sep 1, 2024'],
]);

// Stepper
$stepper = Stepper::new('Step 2 of 5 - Configuration');

// Bullet list
$bullet = Bullet::new('• First important item
• Second key point
• Third critical note
• Fourth final item');

// Toggles and switches
$toggles = VStack::spaced(1,
    Toggle::new('Enable Feature A', true),
    Toggle::new('Enable Feature B', false),
    SwitchComponent::new('Mode: Standard'),
);

$mainContent = HStack::spaced(2,
    VStack::spaced(2,
        Card::titled($accordion, 'FAQ Accordion'),
        Card::titled($timeline, 'Project Timeline'),
        Card::titled($stepper, 'Setup Wizard')
    ),
    VStack::spaced(2,
        Card::titled($bullet, 'Key Points'),
        Card::titled($toggles, 'Feature Toggles')
    )
);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Interactive Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 35);
echo $grid->render();
