<?php

declare(strict_types=1);

// S25 (finding 044) e2e child: instantiates a REAL MirrorPuller over the
// repo directory named in argv, with an exec stub that sleeps (simulating
// a live fetch) and writes a marker file if — and only if — the batch
// actually reaches the wire. A parent holding the mirror lock must see
// NO marker: the child skipped before its exec ran.

require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Jobs\MirrorPuller;
use SugarCraft\GitServer\Repo;

[$repoDir, $markerFile, $configPath] = [$argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? ''];
if ($repoDir === '' || $markerFile === '' || $configPath === '') {
    fwrite(STDERR, "usage: mirror-e2e-child.php <repoDir> <markerFile> <configPath>\n");
    exit(2);
}

$config = Config::load($configPath);

$puller = new MirrorPuller($config, static function (array $argv) use ($markerFile): array {
    // A fetch that would take long enough for the sibling tick to race it.
    usleep(1_500_000);
    file_put_contents($markerFile, 'pulled');

    return ['code' => 0, 'output' => []];
});

$repo = Repo::new(basename($repoDir), $repoDir)->withMirrorFrom('https://up.invalid/r.git');
$puller->registerRepo($repo);
$ok = $puller->runOnce();

fwrite(STDOUT, 'pulled=' . $ok . "\n");
exit(0);
