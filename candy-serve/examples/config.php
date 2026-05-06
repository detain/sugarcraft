<?php
/**
 * candy-serve Config example — load/inspect server configuration.
 *
 * Run: php examples/config.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CandyCore\Serve\Config;

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
echo "DB driver   : {$config->dbDriver}\n";
echo "DB path     : {$config->dbPath()}\n";
echo "LFS enabled : " . ($config->lfsEnabled ? 'yes' : 'no') . "\n";
echo "Log format  : {$config->logFormat}\n";

// --- Demo 2: YAML config file ---
echo "\n=== YAML Config File ===\n\n";

$tmpFile = \sys_get_temp_dir() . '/candy-serve-test-config.yaml';
$yaml = <<<'YAML'
name: "My CandyServe"
log_format: "json"

ssh:
  listen_addr: ":2222"
  public_url: "ssh://localhost:2222"
  idle_timeout: 300

git:
  listen_addr: ":9419"
  max_connections: 16

http:
  listen_addr: ":8080"
  public_url: "http://localhost:8080"

db:
  driver: "sqlite"
  data_source: "my-serve.db"

lfs:
  enabled: true
  ssh_enabled: true
YAML;

\fiel_put_contents($tmpFile, $yaml);

try {
    $cfg = Config::load($tmpFile);
    echo "Loaded config: {$cfg->name}\n";
    echo "SSH listen   : {$cfg->sshListenAddr}\n";
    echo "Git listen   : {$cfg->gitListenAddr}\n";
    echo "HTTP listen  : {$cfg->httpListenAddr}\n";
    echo "DB path      : {$cfg->dbPath()}\n";
    echo "LFS SSH      : " . ($cfg->lfsSshEnabled ? 'enabled' : 'disabled') . "\n";
} catch (\Throwable $e) {
    echo "Error loading config: {$e->getMessage()}\n";
}

@unlink($tmpFile);

echo "\nDone.\n";
