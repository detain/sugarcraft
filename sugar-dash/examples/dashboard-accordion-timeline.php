<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Components\Card\{Text, Card, Accordion};
use SugarCraft\Dash\Components\Tree\Timeline;

/**
 * Dashboard Interactive - showcasing interactive components
 *
 * Shows Accordion and Timeline components in a framed layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Interactive Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Accordion + Timeline (2 columns)
// ============================================
$accordion = Accordion::new([
    ['title' => 'Section 1: Getting Started', 'content' => 'Welcome to SugarDash! This is the getting started guide.'],
    ['title' => 'Section 2: Features', 'content' => 'SugarDash provides 200+ TUI components for PHP.'],
    ['title' => 'Section 3: Examples', 'content' => 'Check out the examples directory for demos.'],
]);
$accordionFrame = Card::titled($accordion, 'Accordion');

$timeline = Timeline::new([
    ['title' => 'Project Start', 'time' => 'Jan 1, 2024'],
    ['title' => 'Alpha Release', 'time' => 'Mar 15, 2024'],
    ['title' => 'Beta Release', 'time' => 'Jun 30, 2024'],
    ['title' => 'v1.0 Launch', 'time' => 'Sep 1, 2024'],
]);
$timelineFrame = Card::titled($timeline, 'Timeline');

$grid->addItem(
    $accordionFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $timelineFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->setSize(100, 25);
echo $grid->render();
