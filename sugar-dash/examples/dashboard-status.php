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

/**
 * Dashboard Status - showcasing status and feedback components
 *
 * Shows Spinner, Skeleton, Progress, Gauge, Toast, Alert, Notification components.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Status & Feedback Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Loading Indicators (4 columns)
// ============================================
$spinner = Spinner::new();
$spinnerFrame = Card::titled($spinner, 'Spinner');

$skeleton = Skeleton::new();
$skeletonFrame = Card::titled($skeleton, 'Skeleton');

$nProgress = NProgress::new(0.65);
$nProgressFrame = Card::titled($nProgress, 'Nano Progress');

$progress = Progress::new(0.75);
$progressFrame = Card::titled($progress, 'Progress');

$loadingRow = HStack::spaced(1, $spinnerFrame, $skeletonFrame, $nProgressFrame, $progressFrame);
$grid->addItem(
    $loadingRow,
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 2: Progress Indicators (4 columns)
// ============================================
$progressBar = ProgressBar::new(80);
$progressBarFrame = Card::titled($progressBar, 'Progress Bar');

$progressRing = ProgressRing::new(65);
$progressRingFrame = Card::titled($progressRing, 'Progress Ring');

$gauge = Gauge::new(85);
$gaugeFrame = Card::titled($gauge, 'Gauge');

$grid->addItem(
    $progressBarFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $progressRingFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->addItem(
    $gaugeFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $gaugeFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 3: Notifications (3 columns)
// ============================================
$toast = Toast::new('Changes saved successfully!');
$toastFrame = Card::titled($toast, 'Toast');

$alert = Alert::new('Warning: Your subscription expires in 3 days.');
$alertFrame = Card::titled($alert, 'Alert');

$notification = Notification::new('5 new messages');
$notificationFrame = Card::titled($notification, 'Notification');

$notificationRow = HStack::spaced(1, $toastFrame, $alertFrame, $notificationFrame);
$grid->addItem(
    $notificationRow,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(120, 30);
echo $grid->render();
