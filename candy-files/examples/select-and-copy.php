<?php

declare(strict_types=1);

// Drives the Manager the way the event loop does: each key is a KeyMsg fed to
// update(), and the returned model is the next frame. Nothing is copied; the
// example stops at the confirm gate and prints the frame it arms.
// Usage: php examples/select-and-copy.php

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Files\Entry;
use SugarCraft\Files\Manager;

$tree = [
    '/work/left'  => [new Entry('docs', true, 0, 0), new Entry('notes.md', false, 12288, 0)],
    '/work/right' => [new Entry('photos', true, 0, 0)],
];
$lister = static fn (string $path): array => $tree[$path] ?? [];

$manager = Manager::start('/work/left', '/work/right', $lister);

// The cursor starts on "..". j moves to "docs"; Space selects it and advances.
// Space on "notes.md" selects the second entry, then c arms the copy gate.
foreach (['j', ' ', ' ', 'c'] as $key) {
    $type = $key === ' ' ? KeyType::Space : KeyType::Char;
    [$manager] = $manager->update(new KeyMsg($type, $key));
}

echo $manager->view(), "\n";
