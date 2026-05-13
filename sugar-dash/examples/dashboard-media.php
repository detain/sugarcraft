<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, Frame, Text, Options, ItemOptions, Card, QRCode, Barcode, Avatar, AvatarGroup, Pictogram, Image};

// Dashboard Media Components Example
$grid = new StackedGrid(new Options(fitScreen: true));

// QR Code
$qrCode = QRCode::new('https://sugarcraft.github.io');

// Barcode
$barcode = Barcode::new('123456789012');

// Avatar
$avatar = Avatar::new('JD');

// Avatar Group
$avatarGroup = AvatarGroup::new([
    ['label' => 'Alice'],
    ['label' => 'Bob'],
    ['label' => 'Charlie'],
    ['label' => 'Diana'],
]);

// Pictogram
$pictogram = Pictogram::new('info');

$topRow = HStack::spaced(2,
    Card::titled($qrCode, 'QR Code'),
    Card::titled($barcode, 'Barcode'),
    Card::titled($pictogram, 'Pictogram')
);

$bottomRow = HStack::spaced(2,
    Card::titled($avatar, 'Single Avatar'),
    Card::titled($avatarGroup, 'Avatar Group')
);

$mainContent = VStack::spaced(2, $topRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Media Components Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(90, 25);
echo $grid->render();
