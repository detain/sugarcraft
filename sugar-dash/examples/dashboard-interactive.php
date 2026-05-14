<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Progress};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card, Bullet, Accordion};
use SugarCraft\Dash\Components\Tree\Timeline;
use SugarCraft\Dash\Components\Nav\Stepper;
use SugarCraft\Dash\Components\Form\{Rating, SwitchComponent, Toggle};
use SugarCraft\Dash\Components\System\ProgressBar;

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
    ['label' => 'Project Start', 'time' => 'Jan 1, 2024'],
    ['label' => 'Alpha Release', 'time' => 'Mar 15, 2024'],
    ['label' => 'Beta Release', 'time' => 'Jun 30, 2024'],
    ['label' => 'v1.0 Launch', 'time' => 'Sep 1, 2024'],
]);
