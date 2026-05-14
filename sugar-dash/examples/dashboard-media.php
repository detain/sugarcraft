<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, Options, ItemOptions, QRCode, Barcode, Pictogram};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame};
use SugarCraft\Dash\Components\Card\{Text, Card};

// Dashboard Media Components Example
$grid = new StackedGrid(new Options(fitScreen: true));

// QR Code
$qrCode = QRCode::new('https://sugarcraft.github.io');

// Barcode
$barcode = Barcode::new('123456789012');

// Pictogram - correct API: array of items with label/value
$pictogram = Pictogram::new([
    ['label' => 'Sales', 'value' => 75],
    ['label' => 'Marketing', 'value' => 45],
]);

$topRow = HStack::spaced(2,
    Card::titled($qrCode, 'QR Code'),
    Card::titled($barcode, 'Barcode'),
    Card::titled($pictogram, 'Pictogram')
);

$mainContent = VStack::spaced(2, $topRow);

$grid->addItem(
    Frame::new(HStack::new(new Text('Dashboard Media Components Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(90, 15);
echo $grid->render();
