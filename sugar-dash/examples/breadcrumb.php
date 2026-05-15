<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dash\Components\Nav\Breadcrumb;

// Breadcrumb navigation
$component = Breadcrumb::new(['Home', 'Products', 'Details']);
$component->setSize(60, 15);
echo $component->render();
