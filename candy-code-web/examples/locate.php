<?php

declare(strict_types=1);

/**
 * Show how a server finds the bundle: the soft probe first, then the hard contract.
 *
 *   php examples/locate.php
 *
 * This mirrors what `candy-code serve` does with the web root. A null from
 * tryDistPath() means the UI is absent and the API should stay up without it.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\CodeWeb\Assets;

$webRoot = Assets::tryDistPath();
if ($webRoot === null) {
    echo "no bundle: serving the API without the browser UI\n";
    exit(0);
}

echo "web root: {$webRoot}\n";
echo "entry:    ", Assets::manifest()['index.html']['file'] ?? '(no index entry)', "\n";
echo "version:  ", Assets::version(), "\n";
