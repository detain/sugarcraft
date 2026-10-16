<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Components\Tree\Tree;
use SugarCraft\Dashboard\Components\Tree\TreeNode;

// Tree view
$component = Tree::new([
    TreeNode::new("Root")->withChildren([
        TreeNode::new("Child 1"),
        TreeNode::new("Child 2"),
    ]),
])->setSize(60, 15);
echo $component->render();
