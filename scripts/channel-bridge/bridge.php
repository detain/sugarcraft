<?php

declare(strict_types=1);

/**
 * candy-code channel bridge — the P0 Track-A daemon.
 *
 * One process: it follows `candy-code serve` over the product's own WS
 * protocol (`candy-code.v1`, authenticated exactly like `candy-code
 * attach`) and speaks Discord and Teams on the other side. Zero core
 * files change: the bridge is an EXTERNAL CONSUMER of the protocol.
 *
 *   php scripts/channel-bridge/bridge.php [--config=path]
 *   php scripts/channel-bridge/bridge.php creds set discord default <token>
 *
 * Secrets live in ~/.candy-code/channels.json (0600, ruling D4); the
 * config file carries enablement and policy only, never credentials.
 */

namespace ChannelBridge;

use ChannelBridge\Candy\ServeBridge as CandyServeBridge;
use ChannelBridge\Discord\DiscordAdapter;
use ChannelBridge\Discord\Gateway;
use ChannelBridge\Discord\Rest;
use ChannelBridge\Teams\BotToken;
use ChannelBridge\Teams\ConnectorApi;
use ChannelBridge\Teams\JwtValidator;
use ChannelBridge\Teams\TeamsAdapter;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;

require __DIR__ . '/src/autoload.php';

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$args = \array_slice($argv, 1);
$command = $args[0] ?? 'run';

if ($command === 'creds') {
    credCommand(\array_slice($args, 1));
    exit(0);
}
if ($command === '--help' || $command === 'help') {
    echo \file_get_contents(__DIR__ . '/README.md') ?: "see scripts/channel-bridge/README.md\n";
    exit(0);
}

$configPath = 'scripts/channel-bridge/config.json';
foreach ($args as $arg) {
    if (\str_starts_with($arg, '--config=')) {
        $configPath = \substr($arg, 9);
    }
}
if (!\is_file($configPath)) {
    \fwrite(\STDERR, "config not found: {$configPath} (copy config.sample.json and edit it)\n");
    exit(1);
}

$config = Config::readFile($configPath);
$log = new Log($config->path('log', 'scripts/channel-bridge/bridge.log'), $config->string('level', 'info'));
$log->open();
$log->info('bridge_boot', ['config' => $configPath]);

$credentials = new CredentialStore($config->path('credentials', CredentialStore::defaultPath()));
try {
    $secrets = $credentials->read();
} catch (\Throwable $e) {
    \fwrite(\STDERR, 'credential store refused: ' . $e->getMessage() . "\n");
    exit(1);
}

$map = new SessionMap($config->path('db', 'scripts/channel-bridge/bridge.sqlite'));
$policy = $config->section('policy');
$safety = (new TextSafety())->withSecrets(\array_map('strval', (array) ($policy['secrets'] ?? [])));

// The serve endpoint: discovery record, validated for liveness the product's
// own way (pid + process start time — a stale file is a corpse, not a door).
$stateDirPath = (string) ($config->section('serve')['stateDir'] ?? '');
if ($stateDirPath === '') {
    $stateDirPath = (string) (\getenv('CANDY_CODE_SERVER_DIR') ?: (\rtrim((string) (\getenv('HOME') ?: ''), '/') . '/.candy-code/server'));
}
$dir = StateDir::existing($stateDirPath);
$record = $dir === null ? null : DiscoveryFile::read($dir);
if ($record === null || !$record->isLive()) {
    \fwrite(\STDERR, "no live candy-code serve at {$stateDirPath} — start it with: candy-code serve --detach\n");
    exit(1);
}
$wsUrl = CandyServeBridge::wsUrlFrom((string) $record->toArray()['url']);
if ($wsUrl === null) {
    \fwrite(\STDERR, "serve discovery url is not translatable to ws: {$stateDirPath}\n");
    exit(1);
}
$serveToken = (string) ($config->section('serve')['token'] ?? '');
if ($serveToken === '') {
    $serveToken = (string) (\getenv('CANDY_CODE_SERVER_TOKEN') ?: '');
}
if ($serveToken === '' && $dir !== null) {
    $serveToken = \SugarCraft\Code\Server\Auth\TokenStore::new($stateDirPath)->token();
}
if ($serveToken === '') {
    \fwrite(\STDERR, "no serve token: set CANDY_CODE_SERVER_TOKEN or serve.stateDir with its token file\n");
    exit(1);
}

$loop = \React\EventLoop\Loop::get();
$serve = new CandyServeBridge($wsUrl, $serveToken, (string) ($config->section('serve')['authDoor'] ?? 'subprotocol'), $log, $loop);
$bridge = new Bridge($serve, $map, $log, $safety, $loop, $policy);

// ── Discord accounts ────────────────────────────────────────────────────
$discordCfg = $config->section('discord');
if (($discordCfg['enabled'] ?? false) === true) {
    foreach ((array) ($discordCfg['accounts'] ?? ['default' => []]) as $accountName => $accountCfg) {
        $accountName = (string) $accountName;
        $cred = $credentials->account($secrets, 'discord', $accountName);
        $token = (string) ($cred['token'] ?? '');
        if ($token === '') {
            $log->warn('discord_account_without_credentials', ['account' => $accountName]);
            continue;
        }
        $merged = $discordCfg + (\is_array($accountCfg) ? $accountCfg : []);
        $adapter = new DiscordAdapter(
            $accountName,
            new Rest($token, $log),
            new Gateway($token, $log, $loop, isset($merged['gatewayUrl']) ? (string) $merged['gatewayUrl'] : null),
            $bridge,
            $merged,
            $log,
        );
        $bridge->addChannel($adapter);
        $adapter->start();
    }
}

// ── Teams accounts ──────────────────────────────────────────────────────
$teamsCfg = $config->section('teams');
if (($teamsCfg['enabled'] ?? false) === true) {
    foreach ((array) ($teamsCfg['accounts'] ?? ['default' => []]) as $accountName => $accountCfg) {
        $accountName = (string) $accountName;
        $cred = $credentials->account($secrets, 'msteams', $accountName);
        $appId = (string) ($cred['appId'] ?? '');
        $appPassword = (string) ($cred['appPassword'] ?? '');
        $tenantId = (string) ($cred['tenantId'] ?? 'botframework.com');
        if ($appId === '' || $appPassword === '') {
            $log->warn('teams_account_without_credentials', ['account' => $accountName]);
            continue;
        }
        $merged = $teamsCfg + (\is_array($accountCfg) ? $accountCfg : []);
        $adapter = new TeamsAdapter(
            $accountName,
            new ConnectorApi(new BotToken($appId, $appPassword, $tenantId, $log), $log),
            new JwtValidator($appId, null, ($merged['allowUnsignedEmulator'] ?? false) === true),
            $bridge,
            $merged,
            $log,
            $loop,
        );
        $bridge->addChannel($adapter);
        $adapter->listen();
    }
}

if (\count(\array_filter([$discordCfg['enabled'] ?? false, $teamsCfg['enabled'] ?? false])) === 0) {
    $log->warn('bridge_no_channels_enabled');
}

// ── run ─────────────────────────────────────────────────────────────────
$serve->start(
    fn (string $why) => $log->warn('serve_down', ['why' => $why]),
    function (string $why) use ($log): void {
        $log->error('serve_gave_up', ['why' => $why]);
    },
)->then(null, fn (\Throwable $e) => $log->error('serve_failed', ['error' => $e->getMessage()]));

$GLOBALS['bridge_shutting_down'] = false;
$shutdown = function (int $signal) use ($log, $serve, $bridge, $map): void {
    if ($GLOBALS['bridge_shutting_down']) {
        return;
    }
    $GLOBALS['bridge_shutting_down'] = true;
    $log->info('bridge_shutdown', ['signal' => $signal]);
    // Cursors are written greatest-wins on every durable event, so a flush
    // is simply: stop accepting, stop talking. The next start resumes from
    // the stored seq — no gap replay into the channel, no double answers.
    $serve->stop();
    foreach ($bridge->allChannels() as $channel) {
        if (\method_exists($channel, 'stop')) {
            $channel->stop();
        }
    }
    $log->close();
    exit(0);
};
if (\function_exists('pcntl_signal') && \function_exists('pcntl_async_signals')) {
    \pcntl_async_signals(true);
    \pcntl_signal(15, $shutdown); // SIGTERM
    \pcntl_signal(2, $shutdown); // SIGINT
} else {
    $log->warn('pcntl_missing_signals_disabled');
}
if (\function_exists('pcntl_signal')) {
    \pcntl_signal(13, SIG_IGN); // SIGPIPE: a dead socket must not kill the daemon
}

$map->pruneSeen((int) $config->int('policy.dedupeKeepDays', 7));
$loop->addPeriodicTimer(3600, fn () => $map->pruneSeen((int) $config->int('policy.dedupeKeepDays', 7)));

$log->info('bridge_running', [
    'discord' => (int) (($discordCfg['enabled'] ?? false) === true),
    'teams' => (int) (($teamsCfg['enabled'] ?? false) === true),
    'serve' => $wsUrl,
]);

$loop->run();

/**
 * `bridge.php creds set <platform> <account> <token-or-json>` — writes the
 * 0600 store through the CredentialStore's own discipline (temp+rename).
 */
function credCommand(array $args): void
{
    require __DIR__ . '/src/autoload.php';
    $action = $args[0] ?? '';
    if ($action === 'path') {
        echo CredentialStore::defaultPath(), "\n";

        return;
    }
    if ($action !== 'set' || \count($args) < 4) {
        \fwrite(\STDERR, "usage: bridge.php creds set <discord|msteams> <account> <token | json>\n");
        exit(1);
    }
    [$platform, $account, $value] = [$args[1], $args[2], $args[3]];
    $decoded = \json_decode($value, true);
    $fields = \is_array($decoded) ? $decoded : ['token' => $value];
    $store = new CredentialStore(CredentialStore::defaultPath());
    $contents = \is_file($store->path()) ? $store->read() : ['version' => CredentialStore::CURRENT_VERSION, 'platforms' => []];
    $contents['platforms'][$platform]['accounts'][$account] = $fields + ($contents['platforms'][$platform]['accounts'][$account] ?? []);
    $store->write($contents);
    echo "stored {$platform}/{$account} credentials at ", $store->path(), "\n";
}
