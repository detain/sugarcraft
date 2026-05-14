<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Text};
use SugarCraft\Dash\Components\Card\{Accordion};
use SugarCraft\Dash\Components\Tree\Timeline;

// Dashboard Interactive Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Accordion
$accordion = Accordion::new([
    ['title' => 'Section 1: Getting Started', 'content' => 'Welcome to SugarDash! This is the getting started guide.'],
    ['title' => 'Section 2: Features', 'content' => 'SugarDash provides 200+ TUI components for PHP.'],
    ['title' => 'Section 3: Examples', 'content' => 'Check out the examples directory for demos.'],
]);

// Timeline
$timeline = Timeline::new([
    ['title' => 'Project Start', 'time' => 'Jan 1, 2024'],
    ['title' => 'Alpha Release', 'time' => 'Mar 15, 2024'],
    ['title' => 'Beta Release', 'time' => 'Jun 30, 2024'],
    ['title' => 'v1.0 Launch', 'time' => 'Sep 1, 2024'],
]);

// Render the dashboard
$grid->addItem($accordion, new ItemOptions(column: 0, expandVertical: true));
$grid->addItem($timeline, new ItemOptions(column: 1));
$grid->setSize(80, 20);
echo $grid->render();
