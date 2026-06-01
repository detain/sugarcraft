<?php

declare(strict_types=1);

/**
 * Generator script for sugar-dash component examples.
 *
 * This script generates a PHP example file for each visual component.
 * Run with: php generate-all.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Component definitions for generating examples.
 * Each entry: [class_name, factory_method, argument_code, description]
 */
$components = [
    // Layout components
    ['VStack', 'new', 'VStack::new(Text::new("Item 1"), Text::new("Item 2"))', 'Vertical stack of items'],
    ['HStack', 'new', 'HStack::new(Text::new("Left"), Text::new("Right"))', 'Horizontal stack of items'],
    ['ZStack', 'new', 'ZStack::new(Text::new("Back"), Text::new("Front"))', 'Layered stack'],
    ['StackedGrid', 'new', '$grid = new StackedGrid(new Options(fitScreen: true)); $grid->addItem(Frame::new(Text::new("Col 1"))->withPadding(1), new ItemOptions(column: 0, expandVertical: true)); $grid->addItem(Frame::new(Text::new("Col 2"))->withPadding(1), new ItemOptions(column: 1)); $grid', 'Multi-column grid layout'],
    ['Frame', 'new', 'Frame::new(Text::new("Framed Content"))', 'Bordered frame container'],
    ['Card', 'new', 'Card::new(Text::new("Card Content"))', 'Card with optional title'],
    ['Center', 'new', 'Center::new(Text::new("Centered Text"))', 'Center-aligned content'],
    ['Split', 'new', 'Split::horizontal(Text::new("Left Panel"), Text::new("Right Panel"))', 'Split pane layout'],

    // Basic components
    ['Text', 'new', 'Text::new("Hello, SugarDash!")', 'Basic text content'],
    ['Paragraph', 'new', 'Paragraph::new("This is a long paragraph that should wrap nicely within the allocated width.")', 'Word-wrapped paragraph'],
    ['Badge', 'new', 'Badge::new("NEW")', 'Status badge'],
    ['Tag', 'new', 'Tag::new("feature")', 'Tag/label component'],
    ['Label', 'new', 'Label::new("Username")', 'Form label'],
    ['Icon', 'new', 'Icon::new("★")', 'Icon display'],
    ['Emoji', 'new', 'Emoji::new("🚀")', 'Emoji display'],
    ['Kbd', 'new', 'Kbd::new("Ctrl+C")', 'Keyboard key display'],
    ['Code', 'new', 'Code::new("echo \\"Hello\\";")', 'Inline code display'],
    ['FigletText', 'new', 'FigletText::new("HELLO")', 'ASCII art text'],
    ['ASCIIBanner', 'new', 'ASCIIBanner::new("WELCOME")', 'ASCII banner'],
    ['BorderText', 'new', 'BorderText::new("Bordered")', 'Text with border'],
    ['Marquee', 'new', 'Marquee::new("Scrolling text from left to right...")', 'Marquee scrolling text'],
    ['LoadingText', 'new', 'LoadingText::new("Loading...")', 'Animated loading text'],

    // Input components
    ['Input', 'new', 'Input::new("john@example.com")', 'Text input field'],
    ['Input', 'labeled', 'Input::labeled("john@example.com", "Email")', 'Labeled input'],
    ['Textarea', 'new', 'Textarea::new("Multi-line text\narea content.")', 'Multi-line text input'],
    ['Select', 'new', 'Select::new([["label" => "Option 1"], ["label" => "Option 2"], ["label" => "Option 3"]])', 'Dropdown select'],
    ['Checkbox', 'new', 'Checkbox::new("Accept Terms", true)', 'Checkbox control'],
    ['Radio', 'new', 'Radio::new([["label" => "Option A"], ["label" => "Option B"], ["label" => "Option C"]], 0)', 'Radio buttons'],
    ['Slider', 'new', 'Slider::new(50)', 'Slider control'],
    ['Toggle', 'new', 'Toggle::new("Dark Mode", true)', 'Toggle switch'],
    ['ComboBox', 'new', 'ComboBox::new("Search...", [["label" => "Result 1"], ["label" => "Result 2"]])', 'Combo box'],
    ['DatePicker', 'new', 'DatePicker::new("2024-01-15")', 'Date picker'],

    // Button components
    ['CTA', 'new', 'CTA::new("Get Started")', 'Call to action button'],

    // Feedback components
    ['Spinner', 'new', 'Spinner::new()', 'Loading spinner'],
    ['Progress', 'new', 'Progress::new(0.65)', 'Progress indicator'],
    ['ProgressBar', 'new', 'ProgressBar::new(75)', 'Progress bar'],
    ['ProgressRing', 'new', 'ProgressRing::new(65)', 'Circular progress'],
    ['NProgress', 'new', 'NProgress::new(0.7)', 'Nano progress bar'],
    ['Gauge', 'new', 'Gauge::new(75)', 'Gauge meter'],
    ['GaugeChart', 'new', 'GaugeChart::new([["label" => "CPU", "value" => 80.0], ["label" => "Memory", "value" => 60.0], ["label" => "Disk", "value" => 45.0]])', 'Gauge chart'],
    ['GaugeCircle', 'new', 'GaugeCircle::new(80)', 'Circular gauge'],
    ['Meter', 'new', 'Meter::new(75)', 'Meter display'],
    ['Toast', 'new', 'Toast::new("Operation completed!")', 'Toast notification'],
    ['Alert', 'new', 'Alert::new("Warning: This action cannot be undone.")', 'Alert message'],
    ['Notification', 'new', 'Notification::new("3 new messages")', 'Notification badge'],
    ['EmptyState', 'new', 'EmptyState::new("No data available")', 'Empty state placeholder'],
    ['Skeleton', 'new', 'Skeleton::new()', 'Loading skeleton'],

    // Navigation components
    ['Tabs', 'new', 'Tabs::new([["label" => "Tab 1"], ["label" => "Tab 2"], ["label" => "Tab 3"]])', 'Tab navigation'],
    ['TabsVertical', 'new', 'TabsVertical::new([["label" => "Overview"], ["label" => "Settings"], ["label" => "Help"]])', 'Vertical tabs'],
    ['Navbar', 'new', 'Navbar::new("Dashboard")', 'Navigation bar'],
    ['Sidebar', 'new', 'Sidebar::new([["label" => "Home"], ["label" => "Profile"], ["label" => "Settings"]])', 'Sidebar menu'],
    ['Breadcrumb', 'new', 'Breadcrumb::new([["label" => "Home"], ["label" => "Products"], ["label" => "Details"]])', 'Breadcrumb navigation'],
    ['Menu', 'new', 'Menu::new([["label" => "File"], ["label" => "Edit"], ["label" => "View"]])', 'Menu navigation'],
    ['Drawer', 'new', 'Drawer::new(Text::new("Drawer Content"))', 'Drawer panel'],
    ['Accordion', 'new', 'Accordion::new([["label" => "Section 1", "content" => "Content 1"], ["label" => "Section 2", "content" => "Content 2"]])', 'Accordion component'],
    ['CommandPalette', 'new', 'CommandPalette::new()', 'Command palette'],

    // Data display
    ['TableBordered', 'new', 'TableBordered::new([["Name" => "Alice", "Age" => "30"], ["Name" => "Bob", "Age" => "25"], ["Name" => "Charlie", "Age" => "35"]])', 'Bordered table'],
    ['TableZebra', 'new', 'TableZebra::new([["Name" => "Alice", "Age" => "30"], ["Name" => "Bob", "Age" => "25"], ["Name" => "Charlie", "Age" => "35"]])', 'Zebra striped table'],
    ['ListComponent', 'new', 'ListComponent::new([["label" => "Item 1"], ["label" => "Item 2"], ["label" => "Item 3"]])', 'List component'],
    ['Tree', 'new', 'Tree::new("Root", [["label" => "Child 1"], ["label" => "Child 2"]])', 'Tree view'],
    ['GridLayout', 'new', 'GridLayout::new(3, 3)', 'Grid layout'],
    ['FlexLayout', 'new', 'FlexLayout::new([Text::new("Flex 1"), Text::new("Flex 2")])', 'Flex layout'],

    // Chart components
    ['Chart', 'new', 'Chart::new([new ChartDataPoint("Jan", 30.0), new ChartDataPoint("Feb", 45.0), new ChartDataPoint("Mar", 25.0)])', 'Bar/Line chart'],
    ['AreaChart', 'new', 'AreaChart::new([["label" => "Series A", "values" => [20.0, 40.0, 30.0, 50.0]])', 'Area chart'],
    ['Bar', 'new', 'Bar::new("Loading...")', 'Status bar'],
    ['Donut', 'new', 'Donut::mocha([["label" => "Category A", "value" => 35.0], ["label" => "Category B", "value" => 25.0], ["label" => "Category C", "value" => 40.0]])', 'Donut chart'],
    ['FunnelChart', 'new', 'FunnelChart::new([["label" => "Visitors", "value" => 1000.0], ["label" => "Signups", "value" => 500.0], ["label" => "Paying", "value" => 200.0]])', 'Funnel chart'],
    ['RadarChart', 'new', 'RadarChart::new([["label" => "Speed", "value" => 80.0], ["label" => "Reliability", "value" => 65.0], ["label" => "Comfort", "value" => 90.0]])', 'Radar chart'],
    ['Heatmap', 'new', 'Heatmap::new([[10.0, 20.0, 30.0], [15.0, 25.0, 35.0], [5.0, 15.0, 25.0]])', 'Heatmap'],
    ['Sparkline', 'new', 'Sparkline::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0, 7.0], 30)', 'Sparkline chart'],
    ['SparkArea', 'new', 'SparkArea::new([1.0, 2.0, 3.0, 2.5, 4.0, 3.5, 5.0], 30)', 'Spark area'],
    ['SparklineBar', 'new', 'SparklineBar::new([3.0, 5.0, 2.0, 8.0, 6.0, 4.0], 20)', 'Spark bar'],
    ['SparklineArea', 'new', 'SparklineArea::new([1.0, 2.0, 3.0, 2.5, 4.0], 25)', 'Sparkline area'],

    // Status & monitoring
    ['Stats', 'new', 'Stats::new()', 'Stats display'],
    ['Stat', 'new', 'Stat::new("Total Users", "1,234")', 'Single stat'],
    ['Metric', 'new', 'Metric::new("Revenue", "\$12,345")', 'Metric display'],
    ['MetricsGrid', 'new', 'MetricsGrid::new([["label" => "Users", "value" => "1.2K"], ["label" => "Revenue", "value" => "\$45K"]])', 'Metrics grid'],
    ['StatusIndicator', 'new', 'StatusIndicator::new("online")', 'Status indicator'],
    ['Leaderboard', 'new', 'Leaderboard::new([["rank" => 1, "label" => "Alice", "value" => "1500"], ["rank" => 2, "label" => "Bob", "value" => "1200"], ["rank" => 3, "label" => "Charlie", "value" => "1100"]])', 'Leaderboard'],
    ['ActivityFeed', 'new', 'ActivityFeed::new([["label" => "Alice posted a comment", "time" => "2m ago"], ["label" => "Bob updated status", "time" => "5m ago"]])', 'Activity feed'],
    ['LogViewer', 'new', 'LogViewer::new()', 'Log viewer'],
    ['Console', 'new', 'Console::new()', 'Console output'],
    ['Terminal', 'new', 'Terminal::new()', 'Terminal emulator'],
    ['HexDump', 'new', 'HexDump::new("Hello World!")', 'Hex dump display'],
    ['Diff', 'new', 'Diff::new("Line 1\nLine 2\nLine 3", "Line 1\nModified Line 2\nLine 3")', 'Diff view'],
    ['DotMatrix', 'new', 'DotMatrix::new("HELLO")', 'Dot matrix display'],

    // Media components
    ['QRCode', 'new', 'QRCode::new("https://example.com")', 'QR code'],
    ['Barcode', 'new', 'Barcode::new("123456789012")', 'Barcode'],
    ['Avatar', 'new', 'Avatar::new("JD")', 'User avatar'],
    ['AvatarGroup', 'new', 'AvatarGroup::new([["label" => "Alice"], ["label" => "Bob"], ["label" => "Charlie"]])', 'Avatar group'],
    ['Pictogram', 'new', 'Pictogram::new("warning")', 'Pictogram icon'],

    // Layout components (continued)
    ['BoxDrawing', 'new', 'BoxDrawing::new("┌──┐\n│  │\n└──┘")', 'Box drawing'],
    ['Boxer', 'new', 'Boxer::new(Text::new("Boxed Content"))', 'Box component'],
    ['Divider', 'new', 'Divider::new()', 'Horizontal divider'],
    ['Spacer', 'new', 'Spacer::new(5)', 'Spacer element'],
    ['Highlight', 'new', 'Highlight::new("This is **important** text.", "**important**")', 'Text highlight'],
    ['Hint', 'new', 'Hint::new("This is a helpful hint.")', 'Hint text'],
    ['Tooltip', 'new', 'Tooltip::new("Hover me", "Help text appears")', 'Tooltip'],
    ['Popover', 'new', 'Popover::new("Click me", "Popover content")', 'Popover'],
    ['Comment', 'new', 'Comment::new("Great work on this project!", "John Doe")', 'Comment display'],
    ['Testimonial', 'new', 'Testimonial::new("Excellent product, highly recommended!", "Jane Smith", "CEO at Company")', 'Testimonial'],
    ['Timeline', 'new', 'Timeline::new([["label" => "Event 1", "time" => "9:00 AM"], ["label" => "Event 2", "time" => "10:00 AM"]])', 'Timeline display'],
    ['Rating', 'new', 'Rating::new(4.5)', 'Star rating'],
    ['ChipGroup', 'new', 'ChipGroup::new([["label" => "PHP"], ["label" => "JavaScript"], ["label" => "Python"]])', 'Chip group'],
    ['Chip', 'new', 'Chip::new("Tag")', 'Chip/tag'],
    ['ProgressList', 'new', 'ProgressList::new([["label" => "Task 1", "progress" => 100], ["label" => "Task 2", "progress" => 50], ["label" => "Task 3", "progress" => 75]])', 'Progress list'],
    ['Bullet', 'new', 'Bullet::new("• First item\n• Second item\n• Third item")', 'Bullet list'],
    ['Jumbotron', 'new', 'Jumbotron::new("Welcome!", "To our awesome application")', 'Jumbotron hero'],
    ['Footer', 'new', 'Footer::new("© 2024 SugarCraft Inc.")', 'Footer'],
    ['Header', 'new', 'Header::new("Dashboard")', 'Header'],
    ['Panel', 'new', 'Panel::new(Text::new("Panel Content"))', 'Panel container'],
    ['Window', 'new', 'Window::new(Text::new("Window Content"))', 'Window frame'],
    ['Modal', 'new', 'Modal::new(Text::new("Modal Dialog Content"))', 'Modal dialog'],
    ['Cover', 'new', 'Cover::new(Text::new("Cover Overlay Content"))', 'Cover overlay'],
    ['Focus', 'new', 'Focus::new(Text::new("Focused Element"))', 'Focus highlight'],
    ['Scrollbar', 'new', 'Scrollbar::new(50)', 'Scrollbar'],
    ['Segment', 'new', 'Segment::new("Segment 1")', 'Segment display'],
    ['Stack', 'new', 'Stack::new([Text::new("Item 1"), Text::new("Item 2")])', 'Generic stack'],
    ['Calendar', 'new', 'Calendar::new()', 'Calendar'],
    ['Clock', 'new', 'Clock::new()', 'Clock display'],
    ['Timer', 'new', 'Timer::new()', 'Timer display'],
    ['Stopwatch', 'new', 'Stopwatch::new()', 'Stopwatch display'],
    ['Editor', 'new', 'Editor::new()', 'Text editor'],
    ['Log', 'new', 'Log::new("Log entry 1\nLog entry 2\nLog entry 3")', 'Log text'],
    ['Profile', 'new', 'Profile::new("John Doe", "john@example.com")', 'User profile'],
    ['State', 'new', 'State::new("active")', 'State display'],
    ['SwitchComponent', 'new', 'SwitchComponent::new("Option A")', 'Switch component'],
    ['Bubble', 'new', 'Bubble::new("Hello! How are you?")', 'Speech bubble'],
    ['WordCloud', 'new', 'WordCloud::new(["PHP" => 10, "JavaScript" => 8, "Python" => 6, "Go" => 5, "Rust" => 4])', 'Word cloud'],
    ['Image', 'new', 'Image::new("https://placehold.co/300x200.png")', 'Image display'],
    ['Video', 'new', 'Video::new("video.mp4")', 'Video player'],
    ['Audio', 'new', 'Audio::new("audio.mp3")', 'Audio player'],
    ['Picture', 'new', 'Picture::new("https://placehold.co/300x200.png")', 'Picture frame'],
];

/**
 * Check if a component class exists.
 */
function componentExists(string $className): bool
{
    $fullClass = "SugarCraft\\Dash\\Grid\\$className";
    return class_exists($fullClass);
}

/**
 * Generate a PHP example file for a component.
 */
function generateExample(string $className, string $description, string $argumentCode): string
{
    $slug = lcfirst($className);
    $filename = __DIR__ . "/{$slug}.php";

    $content = <<<PHP
<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\\$className;
use SugarCraft\Dash\Layout\Grid\ChartDataPoint;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Layout\Grid\ItemOptions;

// $description
\$component = $argumentCode;
\$component->setSize(60, 15);
echo \$component->render();

PHP;

    return $content;
}

// Generate examples for all components
$generated = 0;
$skipped = 0;
$errors = [];
$generatedFiles = [];

foreach ($components as $component) {
    [$className, , $argumentCode, $description] = $component;

    // Check if class exists
    if (!componentExists($className)) {
        $skipped++;
        echo "SKIPPED (class not found): $className\n";
        continue;
    }

    try {
        $slug = lcfirst($className);
        $filename = "{$slug}.php";
        $content = generateExample($className, $description, $argumentCode);
        file_put_contents(__DIR__ . "/$filename", $content);
        $generated++;
        $generatedFiles[] = $filename;
        echo "Generated: $filename\n";
    } catch (Exception $e) {
        $errors[] = "$className: " . $e->getMessage();
        echo "ERROR: $className - " . $e->getMessage() . "\n";
    }
}

echo "\n";
echo "Generated: $generated examples\n";
echo "Skipped: $skipped (class not found)\n";

if ($errors) {
    echo "\nErrors:\n";
    foreach ($errors as $error) {
        echo "  - $error\n";
    }
}

// Output list of generated files for tape generation
echo "\n--- Generated Files ---\n";
foreach ($generatedFiles as $f) {
    echo "$f\n";
}
