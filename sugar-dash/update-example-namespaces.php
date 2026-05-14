<?php
/**
 * Script to update example files from old Grid\* namespace to new namespaces.
 * 
 * Run from sugar-dash directory:
 * php ../update-example-namespaces.php
 */

declare(strict_types=1);

// Namespace mappings: 'OldClass' => 'NewNamespace\Class' or 'NewNamespace\Subdir\Class'
$mappings = [
    // Foundation
    'Theme' => 'SugarCraft\Dash\Foundation\Theme',
    'State' => 'SugarCraft\Dash\State\State',

    // Layout (all in Layout\)
    'Frame' => 'SugarCraft\Dash\Layout\Frame',
    'Window' => 'SugarCraft\Dash\Layout\Window',
    'VStack' => 'SugarCraft\Dash\Layout\VStack',
    'HStack' => 'SugarCraft\Dash\Layout\HStack',
    'ZStack' => 'SugarCraft\Dash\Layout\ZStack',
    'Split' => 'SugarCraft\Dash\Layout\Split',
    'Stack' => 'SugarCraft\Dash\Layout\Stack',
    'Spacer' => 'SugarCraft\Dash\Layout\Spacer',
    'Panel' => 'SugarCraft\Dash\Layout\Panel',
    'Sidebar' => 'SugarCraft\Dash\Layout\Sidebar',
    'GridLayout' => 'SugarCraft\Dash\Layout\GridLayout',
    'FlexLayout' => 'SugarCraft\Dash\Layout\FlexLayout',
    'Center' => 'SugarCraft\Dash\Layout\Center',

    // Components/Modal
    'Modal' => 'SugarCraft\Dash\Components\Modal\Modal',
    'Alert' => 'SugarCraft\Dash\Components\Modal\Alert',
    'Drawer' => 'SugarCraft\Dash\Components\Modal\Drawer',
    'Notification' => 'SugarCraft\Dash\Components\Modal\Notification',
    'Popover' => 'SugarCraft\Dash\Components\Modal\Popover',

    // Components/Toast
    'Toast' => 'SugarCraft\Dash\Components\Toast\Toast',
    'Hint' => 'SugarCraft\Dash\Components\Toast\Hint',
    'Tooltip' => 'SugarCraft\Dash\Components\Toast\Tooltip',

    // Components/Tabs
    'Tabs' => 'SugarCraft\Dash\Components\Tabs\Tabs',
    'TabsVertical' => 'SugarCraft\Dash\Components\Tabs\TabsVertical',

    // Components/Tree
    'Tree' => 'SugarCraft\Dash\Components\Tree\Tree',
    'Timeline' => 'SugarCraft\Dash\Components\Tree\Timeline',

    // Components/Select
    'Select' => 'SugarCraft\Dash\Components\Select\Select',
    'ComboBox' => 'SugarCraft\Dash\Components\Select\ComboBox',
    'Radio' => 'SugarCraft\Dash\Components\Select\Radio',
    'DatePicker' => 'SugarCraft\Dash\Components\Select\DatePicker',

    // Components/Form
    'Checkbox' => 'SugarCraft\Dash\Components\Form\Checkbox',
    'Input' => 'SugarCraft\Dash\Components\Form\Input',
    'Textarea' => 'SugarCraft\Dash\Components\Form\Textarea',
    'Slider' => 'SugarCraft\Dash\Components\Form\Slider',
    'Toggle' => 'SugarCraft\Dash\Components\Form\Toggle',
    'SwitchComponent' => 'SugarCraft\Dash\Components\Form\SwitchComponent',
    'Rating' => 'SugarCraft\Dash\Components\Form\Rating',
    'CommandPalette' => 'SugarCraft\Dash\Components\Form\CommandPalette',
    'Editor' => 'SugarCraft\Dash\Components\Form\Editor',

    // Components/Card
    'Card' => 'SugarCraft\Dash\Components\Card\Card',
    'Badge' => 'SugarCraft\Dash\Components\Card\Badge',
    'Chip' => 'SugarCraft\Dash\Components\Card\Chip',
    'ChipGroup' => 'SugarCraft\Dash\Components\Card\ChipGroup',
    'Code' => 'SugarCraft\Dash\Components\Card\Code',
    'Comment' => 'SugarCraft\Dash\Components\Card\Comment',
    'Cover' => 'SugarCraft\Dash\Components\Card\Cover',
    'CTA' => 'SugarCraft\Dash\Components\Card\CTA',
    'Diff' => 'SugarCraft\Dash\Components\Card\Diff',
    'Divider' => 'SugarCraft\Dash\Components\Card\Divider',
    'Highlight' => 'SugarCraft\Dash\Components\Card\Highlight',
    'Jumbotron' => 'SugarCraft\Dash\Components\Card\Jumbotron',
    'Kbd' => 'SugarCraft\Dash\Components\Card\Kbd',
    'Label' => 'SugarCraft\Dash\Components\Card\Label',
    'Leaderboard' => 'SugarCraft\Dash\Components\Card\Leaderboard',
    'Metric' => 'SugarCraft\Dash\Components\Card\Metric',
    'MetricsGrid' => 'SugarCraft\Dash\Components\Card\MetricsGrid',
    'Paragraph' => 'SugarCraft\Dash\Components\Card\Paragraph',
    'Profile' => 'SugarCraft\Dash\Components\Card\Profile',
    'Stat' => 'SugarCraft\Dash\Components\Card\Stat',
    'Stats' => 'SugarCraft\Dash\Components\Card\Stats',
    'Tag' => 'SugarCraft\Dash\Components\Card\Tag',
    'Testimonial' => 'SugarCraft\Dash\Components\Card\Testimonial',
    'Text' => 'SugarCraft\Dash\Components\Card\Text',
    'ActivityFeed' => 'SugarCraft\Dash\Components\Card\ActivityFeed',
    'BorderText' => 'SugarCraft\Dash\Components\Card\BorderText',
    'BoxDrawing' => 'SugarCraft\Dash\Components\Card\BoxDrawing',
    'Bullet' => 'SugarCraft\Dash\Components\Card\Bullet',

    // Components/Calendar
    'Calendar' => 'SugarCraft\Dash\Components\Calendar\Calendar',
    'ListComponent' => 'SugarCraft\Dash\Components\Calendar\ListComponent',

    // Components/Nav
    'Breadcrumb' => 'SugarCraft\Dash\Components\Nav\Breadcrumb',
    'Menu' => 'SugarCraft\Dash\Components\Nav\Menu',
    'Navbar' => 'SugarCraft\Dash\Components\Nav\Navbar',
    'Scrollbar' => 'SugarCraft\Dash\Components\Nav\Scrollbar',

    // Components/StatusBar
    'StatusIndicator' => 'SugarCraft\Dash\Components\StatusBar\StatusIndicator',

    // Components/Feedback
    'EmptyState' => 'SugarCraft\Dash\Components\Feedback\EmptyState',
    'LoadingText' => 'SugarCraft\Dash\Components\Feedback\LoadingText',
    'Skeleton' => 'SugarCraft\Dash\Components\Feedback\Skeleton',
    'Spinner' => 'SugarCraft\Dash\Components\Feedback\Spinner',

    // Components/GridTable
    'Footer' => 'SugarCraft\Dash\Components\GridTable\Footer',
    'Header' => 'SugarCraft\Dash\Components\GridTable\Header',

    // Components/Table
    'TableBordered' => 'SugarCraft\Dash\Components\Table\TableBordered',
    'TableZebra' => 'SugarCraft\Dash\Components\Table\TableZebra',

    // Components/System
    'Clock' => 'SugarCraft\Dash\Components\System\Clock',
    'Console' => 'SugarCraft\Dash\Components\System\Console',
    'HexDump' => 'SugarCraft\Dash\Components\System\HexDump',
    'Log' => 'SugarCraft\Dash\Components\System\Log',
    'LogViewer' => 'SugarCraft\Dash\Components\System\LogViewer',
    'NProgress' => 'SugarCraft\Dash\Components\System\NProgress',
    'ProgressBar' => 'SugarCraft\Dash\Components\System\ProgressBar',
    'ProgressList' => 'SugarCraft\Dash\Components\System\ProgressList',
    'Stopwatch' => 'SugarCraft\Dash\Components\System\Stopwatch',
    'Terminal' => 'SugarCraft\Dash\Components\System\Terminal',
    'Timer' => 'SugarCraft\Dash\Components\System\Timer',

    // Components/Media
    'Marquee' => 'SugarCraft\Dash\Components\Media\Marquee',
    'Pictogram' => 'SugarCraft\Dash\Components\Media\Pictogram',
    'Picture' => 'SugarCraft\Dash\Components\Media\Picture',
    'QRCode' => 'SugarCraft\Dash\Components\Media\QRCode',

    // Events
    'Focus' => 'SugarCraft\Dash\Events\Focus',
];

$examplesDir = __DIR__ . '/examples';
$files = glob($examplesDir . '/*.php');

$updatedCount = 0;
$totalUpdates = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    $originalContent = $content;
    
    // Replace use statements
    foreach ($mappings as $class => $newNamespace) {
        $pattern = '/^use SugarCraft\\Dash\\Grid\\' . $class . ';/m';
        $replacement = 'use ' . $newNamespace . ';';
        $content = preg_replace($pattern, $replacement, $content);
    }
    
    if ($content !== $originalContent) {
        file_put_contents($file, $content);
        $updates = substr_count($content, 'use SugarCraft\Dash\Layout') 
                 + substr_count($content, 'use SugarCraft\Dash\Foundation')
                 + substr_count($content, 'use SugarCraft\Dash\Components')
                 + substr_count($content, 'use SugarCraft\Dash\Events')
                 + substr_count($content, 'use SugarCraft\Dash\State');
        $updatedCount++;
        $totalUpdates += $updates;
        echo "Updated: " . basename($file) . "\n";
    }
}

echo "\nDone! Updated $updatedCount files with $totalUpdates namespace changes.\n";
