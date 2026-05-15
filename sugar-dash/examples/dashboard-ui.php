<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame, Spacer};
use SugarCraft\Dash\Components\Card\{Text, Card, Badge, Tag, Chip, ChipGroup, Divider, Highlight, Comment, Testimonial};
use SugarCraft\Dash\Components\Toast\{Tooltip, Hint};
use SugarCraft\Dash\Components\Modal\Popover;

/**
 * Dashboard UI - showcasing UI components
 *
 * Shows Badge, Tag, Chip, Divider, Highlight, Hint, Tooltip, Popover, Comment, Testimonial.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('UI Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Badges & Tags (full width)
// ============================================
$tags = HStack::spaced(1,
    Badge::new('NEW'),
    Badge::new('HOT'),
    Tag::new('feature'),
    Tag::new('bug'),
    Chip::new('PHP'),
    Chip::new('JavaScript')
);
$tagsFrame = Card::titled($tags, 'Badges & Tags');

$grid->addItem(
    $tagsFrame,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 2: Highlight + Hint (2 columns)
// ============================================
$highlight = Highlight::new('This is **important** text that stands out.', '**important**');
$highlightFrame = Card::titled($highlight, 'Highlight');

$hint = Hint::new('This is a helpful hint for the user.');
$hintFrame = Card::titled($hint, 'Hint');

$grid->addItem(
    $highlightFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $hintFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: Tooltip + Popover (2 columns)
// ============================================
$tooltip = Tooltip::new('Hover me', 'Tooltip content here');
$tooltipFrame = Card::titled($tooltip, 'Tooltip');

$popover = Popover::new('Click me', 'Popover content with more details');
$popoverFrame = Card::titled($popover, 'Popover');

$grid->addItem(
    $tooltipFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $popoverFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 4: Comment + Testimonial (2 columns)
// ============================================
$comment = Comment::create('John Doe', 'Great work on this feature!');
$commentFrame = Card::titled($comment, 'Comment');

$testimonial = Testimonial::single(['text' => 'SugarDash is amazing!', 'author' => 'Jane Smith', 'role' => 'CEO at TechCorp']);
$testimonialFrame = Card::titled($testimonial, 'Testimonial');

$grid->addItem(
    $commentFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $testimonialFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->setSize(100, 35);
echo $grid->render();
