<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, Progress, ProgressRing, Gauge};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Feedback\{Spinner, Skeleton};
use SugarCraft\Dash\Components\System\{NProgress, ProgressBar};
use SugarCraft\Dash\Components\Toast\Toast;
use SugarCraft\Dash\Components\Modal\{Alert, Notification};

// Dashboard Status Indicators Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Loading indicators
$spinner = Spinner::new();
$skeleton = Skeleton::new();
$nProgress = NProgress::new(0.65);

// Progress indicators
$progress = Progress::new(0.75);
$progressBar = ProgressBar::new(80);
$progressRing = ProgressRing::new(65);
$gauge = Gauge::new(85);

// Notifications and alerts
$toast = Toast::new('Changes saved successfully!');
$alert = Alert::new('Warning: Your subscription expires in 3 days.');
$notification = Notification::new('5 new messages');

$topRow = HStack::spaced(2,
    Card::titled($spinner, 'Spinner'),
    Card::titled($skeleton, 'Skeleton'),
    Card::titled($nProgress, 'Nano Progress'),
    Card::titled($progress, 'Progress')
);

$middleRow = HStack::spaced(2,
    Card::titled($progressBar, 'Progress Bar'),
    Card::titled($progressRing, 'Progress Ring'),
    Card::titled($gauge, 'Gauge')
);

$bottomRow = HStack::spaced(2,
    Card::titled($toast, 'Toast'),
    Card::titled($alert, 'Alert'),
    Card::titled($notification, 'Notification')
);

$mainContent = VStack::spaced(2, $topRow, $middleRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Status Indicators Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
