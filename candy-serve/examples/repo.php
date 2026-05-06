<?php
/**
 * candy-serve Repo + AccessControl example — create repos and manage access.
 *
 * Run: php examples/repo.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Serve\Config;
use CandyCore\Serve\Repo;
use CandyCore\Serve\AccessControl;

$tmpDir = \sys_get_temp_dir() . '/candy-serve-test-' . \uniqid();
\mkdir($tmpDir, 0755, true);

// Demo with a temp config
$config = Config::fromDefaults();

// Reflect into dataPath for our temp dir
$ref = (new \ReflectionClass($config))->getProperty('dataPath');
$ref->setAccessible(true);
$ref->setValue($config, $tmpDir);

echo "=== Repo Management ===\n\n";

$reposPath = $config->reposPath();
echo "Repos path: {$reposPath}\n\n";

// Create a repo
try {
    $repo = Repo::create($config, 'hello-world');
    echo "Created repo: {$repo->name()}\n";
    echo "Path       : {$repo->path()}\n";
    echo "Is bare    : " . ($repo->isBare() ? 'yes' : 'no') . "\n";
    echo "Default branch: {$repo->defaultBranch()}\n";

    // Read a file (before any push)
    $content = $repo->readFile('README.md');
    echo "README.md  : " . ($content !== null ? '"' . \substr($content, 0, 40) . '..."' : '(not found)') . "\n";

    // List branches
    $branches = $repo->branches();
    echo "Branches   : " . \implode(', ', $branches) . "\n";

    // Add a collaborator
    $ac = new AccessControl();
    $ac = $ac->grantRead($repo->name(), 'alice@example.com');
    echo "Alice access: " . ($ac->canRead('alice@example.com', $repo->name()) ? 'read' : 'none') . "\n";

    // Grant write
    $ac = $ac->grantWrite($repo->name(), 'bob@example.com');
    echo "Bob access  : " . ($ac->canWrite('bob@example.com', $repo->name()) ? 'write' : 'read-only') . "\n";

    echo "\n--- All repos ---\n";
    $allRepos = Repo::listAll($config);
    echo "Total repos: " . \count($allRepos) . "\n";
    foreach ($allRepos as $r) {
        echo "  - {$r->name()}\n";
    }

} catch (\Throwable $e) {
    echo "Error: {$e->getMessage()}\n";
}

// Cleanup
exec("rm -rf " . \escapeshellarg($tmpDir));

echo "\nDone.\n";
