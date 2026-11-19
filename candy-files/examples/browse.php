<?php

declare(strict_types=1);

// Renders the two-pane frame over an in-memory tree, so the example runs on any
// machine without touching the real filesystem. Usage: php examples/browse.php

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;

$tree = [
    '/work/left'  => [new Entry('docs', true, 0, 0), new Entry('notes.md', false, 12288, 0)],
    '/work/right' => [new Entry('photos', true, 0, 0), new Entry('todo.txt', false, 512, 0)],
];
$lister = static fn (string $path): array => $tree[$path] ?? [];

$manager = Manager::start('/work/left', '/work/right', $lister);

echo $manager->view(), "\n";
