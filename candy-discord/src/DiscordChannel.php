<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Candy\ServeBridge;
use CandyCode\Bundles\Discord\Discord\DiscordAccount;
use CandyCode\Bundles\Discord\Discord\Gateway;
use CandyCode\Bundles\Discord\Discord\Rest;
use CandyCode\Bundles\Discord\Support\CredentialStore;
use CandyCode\Bundles\Discord\Support\Endpoint;
use CandyCode\Bundles\Discord\Support\Keys;
use CandyCode\Bundles\Discord\Support\Log;
use CandyCode\Bundles\Discord\Support\PairingCodes;
use CandyCode\Bundles\Discord\Support\QueueGate;
use CandyCode\Bundles\Discord\Support\SessionMap;
use CandyCode\Bundles\Discord\Support\TextSafety;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Channels\ChannelInterface;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;

/**
 * The @candy/discord bundle's channel (§11 P2, spec §7): Discord accounts
 * in, served candy-code sessions out, all inside the candy-code process.
 *
 * Shape law: the bundle dials the LOCAL serve over the same WebSocket the
 * CLI attach uses (ServeBridge, with its follow-cursor and re-hello
 * ladder) rather than subscribing to the in-process event feed — a channel
 * that shares the process still speaks the PUBLIC protocol, which keeps
 * the bundle portable, testable against the fixture serve, and honest
 * about what any external bridge would see. Zero protocol methods added.
 *
 * Lifecycle per P1 (ChannelInterface): `start()` reads the settings tree
 * (never tokens — those live in the 0600 channels store per ruling D4=4b),
 * builds one DiscordAccount face per configured account, and arms two
 * loop timers: the serve-discovery poll (a serve that starts LATER is a
 * normal case, not an error — the reconnect ladder inside ServeBridge
 * covers the connection itself) and the optional thread idle sweep.
 * `dispose()` cancels every timer and closes every socket — the
 * SharedLoopResidue law, pinned by tests.
 *
 * The disabled posture is absolute: `enabled:false` (the default) starts
 * ZERO timers, opens ZERO sockets, writes ZERO files.
 */
final class DiscordChannel implements ChannelInterface
{
    private ?LoopInterface $loop = null;

    private ?Log $log = null;

    private ?ServeBridge $serve = null;

    private ?ConversationRouter $router = null;

    private ?QueueGate $queueGate = null;

    /** @var list<DiscordAccount> */
    private array $faces = [];

    private ?TimerInterface $pollTimer = null;

    private ?TimerInterface $sweepTimer = null;

    private ?SessionMap $map = null;

    private ?TextSafety $safety = null;

    private string $dialIdentity = '';

    private int $nextDialAt = 0;

    private bool $disposed = false;

    public function name(): string
    {
        return 'discord';
    }

    public function start(ChannelContext $context): void
    {
        // Version-skew guard: a bundle newer than its host candy-code must
        // no-op loudly, not fatal the launch.
        if (!method_exists(Bootstrap::class, 'channelsDiscordSettings')) {
            ($context->log)('channels.discord: candy-code too old for this bundle — refusing to start');

            return;
        }
        $cfg = Bootstrap::channelsDiscordSettings();
        if (($cfg['enabled'] ?? false) !== true) {
            ($context->log)('channels.discord: disabled posture, nothing started');

            return;
        }

        $this->loop = $context->loop;
        $this->log = new Log(function (string $line) use ($context): void {
            ($context->log)($line);
        });

        $store = new CredentialStore();
        $accounts = $this->accountNames($cfg, $store);
        if ($accounts === []) {
            $this->log->warn('channels.discord.no_accounts', ['why' => 'no token in the channels store and no DISCORD_BOT_TOKEN']);

            return;
        }

        $transport = (array) ($cfg['transport'] ?? []);
        $apiBase = Endpoint::apiBase(
            (string) ($transport['apiBaseUrl'] ?? ''),
            (bool) ($transport['insecureLoopbackProxy'] ?? false),
        );
        $gatewayUrl = Endpoint::gatewayUrl(
            (string) ($transport['gatewayUrl'] ?? ''),
            (bool) ($transport['insecureLoopbackProxy'] ?? false),
        );

        $secrets = [];
        $tokens = [];
        foreach ($accounts as $account) {
            $token = $store->discordToken($account)
                ?? ($account === 'default' ? (self::nonEmptyString(getenv(CredentialStore::TOKEN_ENV))) : null);
            if ($token === null || $token === '') {
                $this->log->warn('channels.discord.account_without_credentials', ['account' => $account]);

                continue;
            }
            $tokens[$account] = $token;
            $secrets[] = $token;
        }
        if ($tokens === []) {
            $this->log->warn('channels.discord.no_usable_accounts');

            return;
        }

        // ONE shared TextSafety for the whole channel: pairing codes minted
        // later register into THIS instance (addSecret), so every face and
        // the router redact the same live roster. withSecrets() replaces —
        // build once with the tokens, then only add.
        $this->safety = (new TextSafety())->withSecrets($secrets);
        // The serve token joins the roster on every dial (below).
        $pairing = new PairingCodes($store, $this->safety);

        $dir = Bootstrap::trustedConfigDirPath() . '/discord';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->map = new SessionMap($dir . '/sessions.db');

        $intents = (array) ($cfg['intents'] ?? []);
        foreach ($tokens as $account => $token) {
            $rest = new Rest($token, $this->log, $apiBase);
            $gateway = new Gateway(
                $token,
                $this->log,
                $this->loop,
                $gatewayUrl === Endpoint::DEFAULT_GATEWAY ? null : $gatewayUrl,
                (bool) ($intents['messageContent'] ?? true),
            );
            $faceCfg = $this->faceConfig($cfg, $account);
            $this->faces[] = new DiscordAccount(
                $account,
                $rest,
                $gateway,
                // The router arrives with the serve; faces read it lazily.
                $this->pendingRouter(),
                $faceCfg,
                $this->log,
                $pairing,
                $this->safety,
                $this->loop,
            );
            $gateway->start();
        }

        $this->pollTimer = $this->loop->addPeriodicTimer(5.0, function (): void {
            $this->pollServe();
        });
        $this->pollServe();

        $threads = (array) ($cfg['threads'] ?? []);
        if (($threads['enabled'] ?? false) === true) {
            $idleHours = (int) ($threads['idleHours'] ?? 24);
            $this->sweepTimer = $this->loop->addPeriodicTimer(600.0, function () use ($idleHours): void {
                $this->sweepIdleThreads($idleHours);
            });
        }
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach ([$this->pollTimer, $this->sweepTimer] as $timer) {
            if ($timer !== null && $this->loop !== null) {
                $this->loop->cancelTimer($timer);
            }
        }
        $this->pollTimer = null;
        $this->sweepTimer = null;

        $this->queueGate?->cancelAll();
        foreach ($this->faces as $face) {
            $face->cancelAllTimers();
            $face->stop();
        }
        $this->serve?->stop();
        $this->serve = null;
        $this->router = null;
        // Faces hold a reference to the (possibly placeholder) router; the
        // placeholder refuses everything once disposed.
        $this->faces = [];
    }

    // ── serve discovery (boot-before-serve is the NORMAL case) ──────────

    /**
     * Read the same discovery record `candy-code serve` writes, validated
     * the product's own way (pid + process start time — a stale file is a
     * corpse, not a door). An endpoint+token CHANGE rebuilds the router:
     * the faces ride the same objects, only the serve leg is swapped, and
     * every bound session re-follows from its stored cursor.
     */
    private function pollServe(): void
    {
        if ($this->loop === null || $this->map === null || $this->safety === null) {
            return;
        }
        if (time() < $this->nextDialAt) {
            return;
        }
        $stateDirPath = (string) (getenv('CANDY_CODE_SERVER_DIR')
            ?: ( rtrim((string) (getenv('HOME') ?: ''), '/') . '/.candy-code/server'));
        try {
            $dir = StateDir::existing($stateDirPath);
        } catch (\Throwable $e) {
            $this->log?->warn('channels.discord.state_dir_refused', ['error' => $e->getMessage()]);
            $this->nextDialAt = time() + 30;

            return;
        }
        if ($dir === null) {
            return; // no serve yet — the 5s poll keeps trying (this is the boot order, not an error)
        }
        $record = DiscoveryFile::read($dir);
        if ($record === null || !$record->isLive()) {
            return;
        }
        $wsUrl = ServeBridge::wsUrlFrom((string) ($record->toArray()['url'] ?? ''));
        if ($wsUrl === null) {
            $this->log?->warn('channels.discord.discovery_url_untranslatable');

            return;
        }
        $tokenFile = $dir->path . '/' . TokenStore::FILE;
        $stat = @lstat($tokenFile);
        $serveToken = $stat !== false && (($stat['mode'] & 0170000) === 0100000)
            ? trim((string) @file_get_contents($tokenFile))
            : '';
        if ($serveToken === '') {
            return; // never-mints posture: only read a token an authorized serve wrote
        }

        $identity = $wsUrl . '|' . sha1($serveToken);
        if ($identity === $this->dialIdentity) {
            return;
        }
        $this->rebuildRouter($wsUrl, $serveToken);
    }

    private function rebuildRouter(string $wsUrl, string $serveToken): void
    {
        $cfg = method_exists(Bootstrap::class, 'channelsDiscordSettings') ? Bootstrap::channelsDiscordSettings() : [];
        $this->serve?->stop();
        $this->queueGate?->cancelAll();

        $this->serve = new ServeBridge($wsUrl, $serveToken, 'subprotocol', $this->log, $this->loop);
        $this->safety?->addSecret($serveToken);
        $this->router = new ConversationRouter(
            $this->serve,
            $this->map,
            $this->log,
            $this->safety,
            $this->loop,
            [
                'queue' => (array) ($cfg['queue'] ?? []),
                'approvals' => (array) ($cfg['approvals'] ?? []),
            ],
        );
        $this->queueGate = new QueueGate(
            $this->loop,
            (int) (((array) ($cfg['queue'] ?? []))['debounceMs'] ?? 500),
            (int) (((array) ($cfg['queue'] ?? []))['maxPending'] ?? 20),
        );
        $this->router->setQueueGate($this->queueGate);
        $this->router->setPairHandler(function (string $key, array $ref, array $args): void {
            $account = Keys::parse($key)['account'];
            foreach ($this->faces as $face) {
                if ($face->accountName() === $account) {
                    $face->pairCommand($ref, $args);

                    return;
                }
            }
        });
        foreach ($this->faces as $face) {
            $face->rebindRouter($this->router);
            $this->router->addChannel($face, $face->accountName());
        }

        $dial = $this->dialIdentity;
        $this->dialIdentity = ''; // any failure below re-dials fresh
        $this->serve->start(
            function (string $why): void {
                $this->log?->warn('channels.discord.serve_down', ['why' => $why]);
            },
            function () use ($dial): void {
                // The ladder gave up (gone serve / dead host): back off the
                // discovery poll itself, keep the channel alive.
                $this->nextDialAt = time() + 30;
                $this->dialIdentity = '';
                $this->log?->warn('channels.discord.serve_giveup', ['retry' => 'in 30s']);
            },
        )->then(function () use ($dial): void {
            $this->dialIdentity = $dial !== '' ? $dial : $this->dialIdentity;
            $this->router?->adoptBindings();
            $this->log?->info('channels.discord.serve_connected');
        }, function (\Throwable $e): void {
            $this->log?->warn('channels.discord.serve_dial_failed', ['error' => $e->getMessage()]);
        });
    }

    // ── threads (§7.5) ──────────────────────────────────────────────────

    /**
     * threadBindings idle sweep: a bound guild conversation that has been
     * quiet past `idleHours` gets its Discord thread archived (the human
     * re-opens by mentioning again — the session binding survives). The
     * PATCH shape follows Discord's channel-modify-for-thread docs and is
     * disclosed as offline-unverified in the lane report.
     */
    private function sweepIdleThreads(int $idleHours): void
    {
        if ($this->map === null) {
            return;
        }
        $limit = time() - max(1, $idleHours) * 3600;
        foreach ($this->faces as $face) {
            foreach ($face->idleThreads($limit) as $channelId) {
                $face->archiveThread($channelId);
            }
        }
    }

    // ── config plumbing ─────────────────────────────────────────────────

    /** @return list<string> */
    private function accountNames(array $cfg, CredentialStore $store): array
    {
        $names = $store->discordAccounts();
        $names[] = 'default';
        foreach (array_keys((array) ($cfg['accounts'] ?? [])) as $extra) {
            $names[] = (string) $extra;
        }

        return array_values(array_unique(array_filter($names, static fn ($n): bool => $n !== '' && $n !== null)));
    }

    /**
     * Flatten the settings tree into the face's config, then lay the
     * account's override rows on top (§7.11: accounts.<id>.* overrides
     * EVERYTHING else). Unknown/misspelled keys fall through to the
     * face's own defaults — a bad row costs a setting, never a launch.
     *
     * @return array<string,mixed>
     */
    private function faceConfig(array $cfg, string $account): array
    {
        $access = (array) ($cfg['access'] ?? []);
        $guilds = (array) ($cfg['guilds'] ?? []);
        $outbound = (array) ($cfg['outbound'] ?? []);
        $media = (array) ($cfg['media'] ?? []);
        $approvals = (array) ($cfg['approvals'] ?? []);
        $slash = (array) ($cfg['slash'] ?? []);

        $face = [
            'dmPolicy' => (string) ($access['dmPolicy'] ?? 'pairing'),
            'dmScope' => (string) ($access['dmScope'] ?? Keys::DM_PER_PEER),
            'allowFrom' => (array) ($access['allowFrom'] ?? []),
            'groupPolicy' => (string) ($guilds['groupPolicy'] ?? 'allowlist'),
            'guilds' => (array) ($guilds['rows'] ?? []),
            'allowBots' => (string) ($access['allowBots'] ?? 'mentions'),
            'ignoreOtherMentions' => (bool) ($access['ignoreOtherMentions'] ?? true),
            'respondWithoutMention' => (bool) ($access['respondWithoutMention'] ?? false),
            'typing' => (bool) ($outbound['typing'] ?? true),
            'suppressEmbeds' => (bool) ($outbound['suppressEmbeds'] ?? true),
            'outbound' => $outbound,
            'media' => $media,
            'approvals' => $approvals,
            'approvalRoster' => (array) ($approvals['approvers'] ?? []),
            'appCommands' => (bool) ($slash['deploy'] ?? true),
            'slashEphemeral' => (bool) ($slash['ephemeral'] ?? true),
        ];

        $accounts = (array) ($cfg['accounts'] ?? []);
        $accounts += (array) (((array) ($cfg['access'] ?? []))['accounts'] ?? []);
        $override = (array) ($accounts[$account] ?? []);
        foreach ($override as $key => $value) {
            if (is_string($key)) {
                $face[$key] = $value;
            }
        }

        return $face;
    }

    /** @return list<string> accounts with a live face (test/telemetry surface). */
    public function faceNames(): array
    {
        return array_values(array_map(
            static fn (DiscordAccount $face): string => $face->accountName(),
            $this->faces,
        ));
    }

    public function queueGateOf(): mixed
    {
        return $this->queueGate;
    }

    /** Run the §7.5 idle sweep now (test seam; the timer calls the same body). */
    public function sweepNow(int $idleHours): void
    {
        $this->sweepIdleThreads($idleHours);
    }

    /**
     * Faces are built before the first serve exists; they take a
     * placeholder router that refuses politely until rebind. Constructing
     * the real router needs the ServeBridge, which needs discovery — which
     * may be a minute away.
     */
    private function pendingRouter(): ConversationRouter
    {
        if ($this->router !== null) {
            return $this->router;
        }
        $serve = new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', $this->log, $this->loop);
        // Never started, never dials: start() is only called on the
        // rebuilt instance; this one exists to satisfy the constructor.
        return new ConversationRouter($serve, $this->map, $this->log, $this->safety, $this->loop, []);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
