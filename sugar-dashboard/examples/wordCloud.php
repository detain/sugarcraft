<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Dashboard\Plot\Chart\WordCloud;

// Word cloud - WordCloud::new() wants {word, weight} shapes, not bare
// strings; shuffle is off so the demo (and its golden) stay deterministic
$component = WordCloud::new([
    ['word' => 'Alpha', 'weight' => 9.0],
    ['word' => 'Beta', 'weight' => 6.0],
    ['word' => 'Gamma', 'weight' => 4.0],
]);
$component = $component->withShuffle(false);
$component = $component->setSize(60, 15);
echo $component->render();
