<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions, QRCode, Barcode, Pictogram};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};

/**
 * Dashboard Media - showcasing media components
 *
 * Shows QRCode, Barcode, and Pictogram components in a framed layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Media Components Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: QR Code + Barcode + Pictogram (3 columns)
// ============================================
$qrCode = QRCode::new('https://sugarcraft.github.io');
$qrFrame = Card::titled($qrCode, 'QR Code');

$barcode = Barcode::new('123456789012');
$barcodeFrame = Card::titled($barcode, 'Barcode');

$pictogram = Pictogram::new([
    ['label' => 'Sales', 'value' => 75],
    ['label' => 'Marketing', 'value' => 45],
]);
$pictogramFrame = Card::titled($pictogram, 'Pictogram');

$mediaRow = HStack::spaced(2, $qrFrame, $barcodeFrame, $pictogramFrame);

$grid->addItem(
    $mediaRow,
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->setSize(100, 20);
echo $grid->render();
