<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord;

use CandyCode\Bundles\Discord\Bridge\ConversationRouter;
use CandyCode\Bundles\Discord\Candy\ServeError;
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
use SugarCraft\Plugins\Bridge\HostServicesServeClient;
use SugarCraft\Plugins\Host\HostServices;
use SugarCraft\Plugins\PluginContext;
use SugarCraft\Plugins\Channel\ChannelInterface;
use SugarCraft\Plugins\Channel\CredentialWriter;
use SugarCraft\Plugins\Channel\WritesCredentials;

// Entrypoint self-bootstrap: the P1 loader require_once's THIS file and
// nothing else, so the bundle's sibling classes need their autoloader
// registered from inside the entrypoint (the candy-code loader deliberately
// knows nothing about bundle layouts — trust lives at the file door, not in
// a resolver). The defined-guard keeps double registration impossible, and
// __DIR__ is the INSTALL tree, so a marketplace copy resolves its own bytes.
require_once __DIR__ . '/autoload.php';

/**
 * The sugarcraft/candy-discord bundle's channel (docs/SPEC.md §11 P2 + docs/SPEC.md §7): Discord accounts
 * in, served candy-code sessions out, all inside the candy-code process.
 *
 * Shape law: the bundle calls the serve it runs inside through the
 * HostServices the host injects (PL-5d). No socket is dialled and no
 * discovery record is read: the host answers each call in process, and
 * events arrive by subscription. The wire protocol stays for external
 * clients, which are not bundles.
 *
 * Lifecycle per P1 (ChannelInterface): `start()` reads the settings tree
 * (never tokens — those live in the 0600 channels store per ruling D4=4b),
 * builds one DiscordAccount face per configured account, connects the
 * injected host, and arms the thread idle sweep and the dedupe prune.
 * `dispose()` cancels every timer and closes every socket — the
 * SharedLoopResidue law, pinned by tests.
 *
 * The disabled posture is absolute: `enabled:false` (the default) starts
 * ZERO timers, opens ZERO sockets, writes ZERO files.
 */
final class DiscordChannel implements ChannelInterface, WritesCredentials
{
    private ?LoopInterface $loop = null;

    private ?Log $log = null;

    private ?HostServices $host = null;

    private ?HostServicesServeClient $serve = null;

    private ?ConversationRouter $router = null;

    private ?QueueGate $queueGate = null;

    /** @var list<DiscordAccount> */
    private array $faces = [];

    private ?TimerInterface $sweepTimer = null;

    private ?TimerInterface $pruneTimer = null;

    /**
     * The boot hello's retry ladder. The pre-5d ServeBridge kept it (0.25 s doubling, 30 s cap,
     * 60 attempts); a hello refused at boot must not leave the channel dead until a serve restart.
     */
    private const DIAL_BACKOFF_BASE_SECONDS = 0.25;
    private const DIAL_BACKOFF_MAX_SECONDS = 30.0;
    private const DIAL_MAX_ATTEMPTS = 60;

    private int $dialAttempts = 0;

    private ?TimerInterface $dialTimer = null;

    private ?SessionMap $map = null;

    private ?TextSafety $safety = null;

    private bool $disposed = false;

    /** The settings section the host froze at launch (plan 4.14). */
    private array $cfg = [];

    public function name(): string
    {
        return 'discord';
    }

    public function start(PluginContext $context, ?CredentialWriter $writer = null): void
    {
        $cfg = $context->config;
        $this->cfg = $cfg;
        if (($cfg['enabled'] ?? false) !== true) {
            ($context->log)('channels.discord: disabled posture, nothing started');

            return;
        }

        $this->loop = $context->loop;
        $this->log = new Log(function (string $line) use ($context): void {
            ($context->log)($line);
        });

        if (!\extension_loaded('pdo_sqlite')) {
            // SessionMap and the pairing store open sqlite PDOs; name the
            // missing extension instead of letting a raw PDOException
            // surface mid-boot (candy-discord-047).
            $this->log->warn('channels.discord.missing_extension', ['why' => 'ext-pdo_sqlite is not loaded; the session and pairing store cannot be opened']);

            return;
        }

        if ($context->stateDir === null || $context->stateDir === '') {
            ($context->log)('channels.discord: no state directory, nothing started');

            return;
        }
        if ($context->host === null) {
            ($context->log)('channels.discord: no serve host wired in, nothing started');

            return;
        }
        $this->host = $context->host;
        $store = new CredentialStore(CredentialStore::pathIn($context->stateDir), $writer);
        $accounts = $this->accountNames($cfg, $store);
        if ($accounts === []) {
            $this->log->warn('channels.discord.no_accounts', ['why' => 'no token in the channels store and no DISCORD_BOT_TOKEN']);

            return;
        }

        $transport = (array) ($cfg['transport'] ?? []);
        // docs/SPEC.md §7.1/§7.2: the DISCORD_API_URL / DISCORD_GATEWAY_URL environment overrides
        // win over the settings tree. Both are read HERE, inside the bundle: the
        // candy-code env roster scans candy-code/src only, so a bundle-side getenv
        // costs zero documentation churn by design.
        $apiOverride = self::nonEmptyString(getenv('DISCORD_API_URL'))
            ?? self::nonEmptyString((string) ($transport['apiBaseUrl'] ?? ''));
        $gatewayOverride = self::nonEmptyString(getenv('DISCORD_GATEWAY_URL'))
            ?? self::nonEmptyString((string) ($transport['gatewayUrl'] ?? ''));
        $apiBase = Endpoint::apiBase(
            (string) ($apiOverride ?? ''),
            (bool) ($transport['insecureLoopbackProxy'] ?? false),
        );
        $gatewayUrl = Endpoint::gatewayUrl(
            (string) ($gatewayOverride ?? ''),
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
        // (040) codes minted before this restart must rejoin the fresh
        // redactor the moment pairing exists, or the restart window leaks them.
        $pairing->registerLiveSecrets(array_keys($tokens));

        $dir = $context->stateDir . '/discord';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->map = new SessionMap($dir . '/sessions.db');

        // The serve client and the router exist before any face: a face takes its router at
        // construction, so there is no placeholder to swap later.
        $this->serve = new HostServicesServeClient(
            $this->host,
            static fn (string $method, \Throwable $e): \Throwable => ServeError::fromHost($method, $e),
        );
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
            $parsed = Keys::parse($key);
            $actor = (string) ($ref['__actor']['userId'] ?? '');
            $isDm = \str_starts_with($parsed['rest'], 'dm:');
            foreach ($this->faces as $face) {
                if ($face->accountName() === $parsed['account']) {
                    $face->pairCommand($ref, $args, $actor, $isDm);

                    return;
                }
            }
        });

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
                $this->router,
                $faceCfg,
                $this->log,
                $pairing,
                $this->safety,
                $this->loop,
            );
            $gateway->start();
        }
        foreach ($this->faces as $face) {
            $this->router->addChannel($face, $face->accountName());
        }

        // The host is in process, so connecting is the hello and nothing more. A refused
        // hello leaves the faces up with no serve: the router answers nothing it cannot route,
        // until the retry ladder gets a hello through.
        $this->dialServe();

        $threads = (array) ($cfg['threads'] ?? []);
        if (($threads['enabled'] ?? false) === true) {
            $idleHours = (int) ($threads['idleHours'] ?? 24);
            $this->sweepTimer = $this->loop->addPeriodicTimer(600.0, function () use ($idleHours): void {
                $this->sweepIdleThreads($idleHours);
            });
        }

        // The dedupe ledger stays bounded without a platform-side sweep — the
        // same contract the Teams face meets (docs/SPEC.md §9.5). claimInbound()
        // wrote a seen_inbound row per delivery while pruneSeen() had no
        // caller here, so the ledger grew forever; 6 h is amply frequent for
        // the 7-day keep. (m09)
        $this->pruneTimer = $this->loop->addPeriodicTimer(21600.0, function (): void {
            $this->map?->pruneSeen(7);
            // (042) the bindings/reply-refs sweep rides the same cadence.
            $this->map?->sweepStaleBindings();
        });
    }

    /**
     * One boot hello, and on refusal the next one after the ladder's delay, up to DIAL_MAX_ATTEMPTS.
     * The router adopts its bindings on the first hello that lands.
     */
    private function dialServe(): void
    {
        if ($this->disposed || $this->host === null) {
            return;
        }
        $this->dialAttempts++;
        $this->host->connect()->then(function (): void {
            $this->dialTimer = null;
            $this->router?->adoptBindings();
            $this->log?->info('channels.discord.serve_connected');
        }, function (\Throwable $e): void {
            $this->dialTimer = null;
            $this->log?->warn('channels.discord.serve_dial_failed', ['error' => $e->getMessage(), 'attempt' => $this->dialAttempts]);
            if ($this->disposed) {
                return;
            }
            if ($this->dialAttempts >= self::DIAL_MAX_ATTEMPTS) {
                $this->log?->warn('channels.discord.serve_giveup', ['attempts' => $this->dialAttempts]);

                return;
            }
            $delay = \min(
                self::DIAL_BACKOFF_MAX_SECONDS,
                self::DIAL_BACKOFF_BASE_SECONDS * (2 ** \min(8, $this->dialAttempts - 1)),
            );
            $this->dialTimer = $this->loop?->addTimer($delay, fn () => $this->dialServe());
        });
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach ([$this->sweepTimer, $this->pruneTimer, $this->dialTimer] as $timer) {
            if ($timer !== null && $this->loop !== null) {
                $this->loop->cancelTimer($timer);
            }
        }
        $this->sweepTimer = null;
        $this->pruneTimer = null;
        $this->dialTimer = null;

        $this->queueGate?->cancelAll();
        foreach ($this->faces as $face) {
            $face->cancelAllTimers();
            $face->stop();
        }
        $this->host?->disconnect();
        $this->host = null;
        $this->serve = null;
        $this->router = null;
        // Faces hold a reference to the (possibly placeholder) router; the
        // placeholder refuses everything once disposed.
        $this->faces = [];
    }

    // ── threads (docs/SPEC.md §7.5) ──────────────────────────────────────────────────

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
     * account's override rows on top (docs/SPEC.md §7.11: accounts.<id>.* overrides
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

    /** Run the docs/SPEC.md §7.5 idle sweep now (test seam; the timer calls the same body). */
    public function sweepNow(int $idleHours): void
    {
        $this->sweepIdleThreads($idleHours);
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
