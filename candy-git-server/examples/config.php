<?php
/**
 * candy-git-server Config example — load/inspect server configuration.
 *
 * Run: php examples/config.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use SugarCraft\GitServer\Config;

// --- Demo 1: defaults ---
echo "=== Default Config ===\n\n";

$config = Config::fromDefaults();

echo "Server name : {$config->name}\n";
echo "Data path   : {$config->dataPath}\n";
echo "Repos path  : {$config->reposPath()}\n";
echo "SSH listen  : {$config->sshListenAddr}\n";
echo "SSH pub URL : {$config->sshPublicUrl}\n";
echo "Git listen  : {$config->gitListenAddr}\n";
echo "HTTP listen : {$config->httpListenAddr}\n";
echo "LFS enabled : " . ($config->lfsEnabled ? 'yes' : 'no') . "\n";
echo "Log format  : {$config->logFormat}\n";

// --- Demo 2: YAML config file ---
echo "\n=== YAML Config File ===\n\n";

// 136: a fixed predictable /tmp name is a symlink-squat target (attacker
// pre-creates the path, the example's write lands through their link).
// tempnam() creates exclusively (0600) and never reuses.
$tmpFile = \tempnam(\sys_get_temp_dir(), 'candy-git-cfg-');
$yaml = <<<'YAML'
name: "My CandyGitServer"
log_format: "json"      # parsed, but no shipped code path reads it yet

ssh:                    # parsed for the unwired SSH host; unread today
  listen_addr: ":2222"
  public_url: "ssh://localhost:2222"
  idle_timeout: 300

git:
  listen_addr: ":9419"
  max_connections: 16

http:
  listen_addr: ":8080"  # parsed; serve --http binds from its CLI flags
  public_url: "http://localhost:8080"

lfs:
  enabled: true
  ssh_enabled: true     # parsed, but no shipped code path reads it yet
YAML;
# No db: block — Config has no database concept and would silently
# ignore one (the README Configuration table is the key census).

\file_put_contents($tmpFile, $yaml);

try {
    $cfg = Config::load($tmpFile);
    echo "Loaded config: {$cfg->name}\n";
    echo "SSH listen   : {$cfg->sshListenAddr}\n";
    echo "Git listen   : {$cfg->gitListenAddr}\n";
    echo "HTTP listen  : {$cfg->httpListenAddr}\n";
    echo "LFS SSH      : " . ($cfg->lfsSshEnabled ? 'enabled' : 'disabled') . "\n";
} catch (\Throwable $e) {
    echo "Error loading config: {$e->getMessage()}\n";
}

@unlink($tmpFile);

echo "\nDone.\n";
