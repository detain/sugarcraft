<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\WordCloud;

// Word cloud
$component = WordCloud::new(["Alpha", "Beta", "Gamma"]);
$component = $component->setSize(60, 15);
echo $component->render();
