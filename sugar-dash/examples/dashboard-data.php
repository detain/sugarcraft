<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Layout\Grid\{StackedGrid, Options, ItemOptions};
use SugarCraft\Dash\Layout\{VStack, HStack, Frame, GridLayout};
use SugarCraft\Dash\Components\Card\{Text, Card};
use SugarCraft\Dash\Components\Table\{TableBordered, TableZebra};
use SugarCraft\Dash\Components\Calendar\ListComponent;
use SugarCraft\Dash\Components\Tree\Tree;
use SugarCraft\Dash\Components\Tree\TreeNode;

/**
 * Dashboard Data Display - showcasing data presentation components
 *
 * Shows tables (bordered and zebra), list components, and tree views
 * in a multi-column framed layout.
 */

$grid = new StackedGrid(new Options(fitScreen: true));

// ============================================
// HEADER
// ============================================
$grid->addItem(
    Card::titled(Text::new('Data Display Dashboard'), ''),
    new ItemOptions(column: 0, expandVertical: false)
);

// ============================================
// ROW 1: Tables (2 columns)
// ============================================
// Table with borders
$tableBordered = TableBordered::new([
    ['ID' => '1', 'Name' => 'Alice', 'Role' => 'Admin'],
    ['ID' => '2', 'Name' => 'Bob', 'Role' => 'User'],
    ['ID' => '3', 'Name' => 'Charlie', 'Role' => 'Editor'],
]);
$tableBorderedFrame = Card::titled($tableBordered, 'Users (Bordered)');

// Zebra striped table
$tableZebra = TableZebra::new([
    ['Product' => 'Widget A', 'Sales' => '1,234', 'Revenue' => '$12,340'],
    ['Product' => 'Widget B', 'Sales' => '2,456', 'Revenue' => '$24,560'],
    ['Product' => 'Gadget X', 'Sales' => '789', 'Revenue' => '$7,890'],
]);
$tableZebraFrame = Card::titled($tableZebra, 'Sales (Zebra)');

$grid->addItem(
    $tableBorderedFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $tableZebraFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

// ============================================
// ROW 2: List + Tree (2 columns)
// ============================================
// List component
$list = ListComponent::new([
    ['label' => 'Alice - Admin'],
    ['label' => 'Bob - User'],
    ['label' => 'Charlie - Editor'],
    ['label' => 'Diana - Manager'],
]);
$listFrame = Card::titled($list, 'User List');

// Tree view with proper TreeNode objects
$tree = Tree::new([
    TreeNode::new('Root')->withChildren([
        TreeNode::new('Folder 1')->withChildren([
            TreeNode::new('File 1.1'),
            TreeNode::new('File 1.2'),
        ]),
        TreeNode::new('Folder 2')->withChildren([
            TreeNode::new('File 2.1'),
        ]),
    ]),
]);
$treeFrame = Card::titled($tree, 'File Tree');

$grid->addItem(
    $listFrame,
    new ItemOptions(column: 0, expandVertical: true)
);

$grid->addItem(
    $treeFrame,
    new ItemOptions(column: 1, expandVertical: true)
);

$grid->setSize(100, 30);
echo $grid->render();
