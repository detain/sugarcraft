<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame, Spacer};
use SugarCraft\Dash\Components\Card\{Text, Card, Badge, Tag, Chip, ChipGroup, Divider, Highlight, Comment, Testimonial};
use SugarCraft\Dash\Components\Toast\{Tooltip, Hint};
use SugarCraft\Dash\Components\Modal\Popover;

// Dashboard UI Components Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Tags and badges
$tags = HStack::spaced(1,
    Badge::new('NEW'),
    Badge::new('HOT'),
    Tag::new('feature'),
    Tag::new('bug'),
    Chip::new('PHP'),
    Chip::new('JavaScript')
);

// Dividers and spacers - remove Divider which has a bug
$dividers = VStack::spaced(1,
    Text::new('Above divider'),
    Text::new('Below divider'),
    Spacer::new(3),
    Text::new('After spacer')
);

// Highlight and hints
$highlight = Highlight::new('This is **important** text that stands out.', '**important**');
$hint = Hint::new('This is a helpful hint for the user.');

// Tooltip and popover
$tooltip = Tooltip::new('Hover me', 'Tooltip content here');
$popover = Popover::new('Click me', 'Popover content with more details');

// Comment and testimonial - use correct API
$comment = Comment::create('John Doe', 'Great work on this feature!');
$testimonial = Testimonial::single(['text' => 'SugarDash is amazing!', 'author' => 'Jane Smith', 'role' => 'CEO at TechCorp']);

$mainContent = VStack::spaced(2,
    Card::titled($tags, 'Badges & Tags'),
    Card::titled($dividers, 'Dividers & Spacers'),
    Card::titled($highlight, 'Highlight'),
    Card::titled($hint, 'Hint'),
    HStack::spaced(2,
        Card::titled($tooltip, 'Tooltip'),
        Card::titled($popover, 'Popover')
    ),
    HStack::spaced(2,
        Card::titled($comment, 'Comment'),
        Card::titled($testimonial, 'Testimonial')
    )
);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard UI Components Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(90, 35);
echo $grid->render();
