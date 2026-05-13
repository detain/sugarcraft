<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Grid\{StackedGrid, VStack, HStack, Frame, Text, Options, ItemOptions, Card, TableBordered, TableZebra, ListComponent, Tree, GridLayout};

// Dashboard Data Display Example
$grid = new StackedGrid(new Options(fitScreen: true));

// Table with borders
$tableBordered = TableBordered::new([
    ['ID' => '1', 'Name' => 'Alice', 'Role' => 'Admin'],
    ['ID' => '2', 'Name' => 'Bob', 'Role' => 'User'],
    ['ID' => '3', 'Name' => 'Charlie', 'Role' => 'Editor'],
]);

// Zebra striped table
$tableZebra = TableZebra::new([
    ['Product' => 'Widget A', 'Sales' => '1,234', 'Revenue' => '$12,340'],
    ['Product' => 'Widget B', 'Sales' => '2,456', 'Revenue' => '$24,560'],
    ['Product' => 'Gadget X', 'Sales' => '789', 'Revenue' => '$7,890'],
]);

// List component
$list = ListComponent::new([
    ['label' => 'Alice - Admin'],
    ['label' => 'Bob - User'],
    ['label' => 'Charlie - Editor'],
    ['label' => 'Diana - Manager'],
]);

// Tree view
$tree = Tree::new('Root', [
    ['label' => 'Folder 1', 'children' => [
        ['label' => 'File 1.1'],
        ['label' => 'File 1.2'],
    ]],
    ['label' => 'Folder 2', 'children' => [
        ['label' => 'File 2.1'],
    ]],
]);

$topRow = HStack::spaced(2,
    Card::titled($tableBordered, 'Users (Bordered)'),
    Card::titled($tableZebra, 'Sales (Zebra)')
);

$bottomRow = HStack::spaced(2,
    Card::titled($list, 'User List'),
    Card::titled($tree, 'File Tree')
);

$mainContent = VStack::spaced(2, $topRow, $bottomRow);

$grid->addItem(
    Frame::new(HStack::new(Text::new('Dashboard Data Display Demo')))->withPadding(1),
    new ItemOptions(column: 0, expandVertical: false)
);

$grid->addItem(
    Frame::new($mainContent)->withPadding(1),
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
