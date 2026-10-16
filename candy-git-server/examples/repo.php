<?php
/**
 * candy-git-server Repo + AccessControl example — build a Repo description,
 * resolve permissions for a User against it. No actual git server is
 * spawned; this exercises the metadata surface that ships in the lib.
 *
 * Run: php examples/repo.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GitServer\AccessControl;
use SugarCraft\GitServer\Config;
use SugarCraft\GitServer\Repo;
use SugarCraft\GitServer\User;

// Steer Config at a temp data dir.
$tmpDir = \sys_get_temp_dir() . '/candy-git-server-test-' . \uniqid();
\mkdir($tmpDir, 0755, true);
\putenv("CANDY_GIT_SERVER_DATA_PATH={$tmpDir}");
$config = Config::fromDefaults();

echo "=== Config ===\n";
echo "  reposPath: {$config->reposPath()}\n";
echo "  sshPath  : {$config->sshPath()}\n";
echo "  dbPath   : {$config->dbPath()}\n\n";

// Build a public, push-allowed Repo with one collaborator.
$repo = Repo::new('hello-world', $config->reposPath() . '/hello-world.git')
    ->withDescription('Greeting service for the docs site.')
    ->withPublic(true)
    ->withAllowPush(true)
    ->withHighlightLanguage('php')
    ->addCollaborator('alice');

echo "=== Repo metadata ===\n";
echo "  name           : {$repo->name}\n";
echo "  path           : {$repo->path()}\n";
echo "  description    : {$repo->description}\n";
echo "  public         : " . ($repo->isPublic ? 'yes' : 'no') . "\n";
echo "  allow push     : " . ($repo->allowPush ? 'yes' : 'no') . "\n";
echo "  highlight lang : {$repo->highlightLanguage}\n";
echo "  collaborators  : " . implode(', ', $repo->collaborators()) . "\n\n";

// Build users + an AccessControl, ask the access matrix.
$alice = User::new('alice');
$bob   = User::new('bob');

$ac = AccessControl::getInstance();
echo "=== Access matrix ===\n";
echo sprintf("  %-8s read=%-3s write=%-3s admin=%s\n",
    'alice', $ac->canRead($alice, $repo) ? 'yes' : 'no', $ac->canWrite($alice, $repo) ? 'yes' : 'no', $ac->canAdmin($alice, $repo) ? 'yes' : 'no');
echo sprintf("  %-8s read=%-3s write=%-3s admin=%s\n",
    'bob',   $ac->canRead($bob,   $repo) ? 'yes' : 'no', $ac->canWrite($bob,   $repo) ? 'yes' : 'no', $ac->canAdmin($bob,   $repo) ? 'yes' : 'no');
echo sprintf("  %-8s read=%-3s (anonymous read = %s)\n",
    'guest',
    $ac->canRead(null, $repo) ? 'yes' : 'no',
    $ac->allowAnonymousRead() ? 'on' : 'off');

// Cleanup
exec("rm -rf " . \escapeshellarg($tmpDir));
echo "\nDone.\n";
