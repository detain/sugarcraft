<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams;

use CandyCode\Bundles\MsTeams\Bridge\ConversationRouter;
use CandyCode\Bundles\MsTeams\Candy\ServeBridge;
use CandyCode\Bundles\MsTeams\MsTeams\Commands;
use CandyCode\Bundles\MsTeams\MsTeams\Jwt;
use CandyCode\Bundles\MsTeams\MsTeams\Jwk;
use CandyCode\Bundles\MsTeams\MsTeams\JwtException;
use CandyCode\Bundles\MsTeams\MsTeams\MsTeamsAccount;
use CandyCode\Bundles\MsTeams\MsTeams\Rest;
use CandyCode\Bundles\MsTeams\MsTeams\TokenBroker;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use CandyCode\Bundles\MsTeams\Support\Endpoint;
use CandyCode\Bundles\MsTeams\Support\Keys;
use CandyCode\Bundles\MsTeams\Support\Log;
use CandyCode\Bundles\MsTeams\Support\PairingCodes;
use CandyCode\Bundles\MsTeams\Support\QueueGate;
use CandyCode\Bundles\MsTeams\Support\SessionMap;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use SugarCraft\Code\Channels\AcceptsHttpRequests;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Channels\ChannelInterface;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;

// Entrypoint self-bootstrap: the P1 loader require_once's THIS file and
// nothing else, so the bundle's sibling classes need their autoloader
// registered from inside the entrypoint (the candy-code loader deliberately
// knows nothing about bundle layouts — trust lives at the file door, not in
// a resolver). The defined-guard keeps double registration impossible, and
// __DIR__ is the INSTALL tree, so a marketplace copy resolves its own bytes.
require_once __DIR__ . '/autoload.php';

/**
 * The `@candy/msteams` channel root (§8): Microsoft Teams dialogs, over
 * the INVERSE transport.
 *
 * Discord keeps a websocket to its platform and dials the local serve;
 * Teams keeps a websocket to the local serve and lets Microsoft POST at
 * US: the daemon's `/channels/msteams/…` mount (§8.1) lands in
 * {@see handleRequest()}, where Bot Framework JWT validation TERMINATES —
 * the candy-code router never speaks Bot Framework, so every byte of that
 * trust door lives here, over an injected signing-key roster. Outbound
 * rides the activity's serviceUrl conversation reference (§8.6); the
 * serve leg is the same self-dial state machine the discord bundle runs,
 * because durable cursors do not care which side originally opened a
 * socket.
 *
 * Dark until `channels.msteams.enabled === true`; policy read from the
 * USER tier only; credentials only from the 0600 channels store (with the
 * default-account env fallback §8.2 — read HERE, bundle-side, costing the
 * host env roster zero churn). Groups are blocked by default (§8.3).
 * Graph/RSC media and file consent are P3.5 (§8.7): the v1 face fetches
 * platform-hosted attachment bytes only and REFUSES Graph-hosted content
 * with a disclosed note — never a silent drop.
 */
final class MsTeamsChannel implements ChannelInterface, AcceptsHttpRequests
{
    /** Activities are small JSON; inline attachment bytes are NOT v1 (§8.7). */
    private const MAX_BODY_BYTES = 1_048_576;

    private ?LoopInterface $loop = null;

    private ?Log $log = null;

    private ?TextSafety $safety = null;

    private ?SessionMap $map = null;

    private ?ServeBridge $serve = null;

    private ?ConversationRouter $router = null;

    private ?QueueGate $queueGate = null;

    /** @var list<MsTeamsAccount> */
    private array $faces = [];

    private ?ConversationRouter $placeholderRouter = null;

    /** @var array<string, array{account: string, jwt: Jwt}> appId => validator */
    private array $validators = [];

    private string $mountPath = '/channels/msteams/api/messages';

    private ?TimerInterface $pollTimer = null;

    private ?TimerInterface $pruneTimer = null;

    private int $nextDialAt = 0;

    private string $dialIdentity = '';

    private bool $disposed = false;

    public function name(): string
    {
        return 'msteams';
    }

    public function start(ChannelContext $context): void
    {
        // Version-skew guard: a bundle newer than its host candy-code must
        // no-op loudly, not fatal the launch.
        if (!method_exists(Bootstrap::class, 'channelsMsteamsSettings')) {
            ($context->log)('channels.msteams: candy-code too old for this bundle — refusing to start');

            return;
        }
        $cfg = Bootstrap::channelsMsteamsSettings();
        if (($cfg['enabled'] ?? false) !== true) {
            ($context->log)('channels.msteams: disabled posture, nothing started');

            return;
        }

        $this->loop = $context->loop;
        $this->log = new Log(function (string $line) use ($context): void {
            ($context->log)($line);
        });

        $store = new CredentialStore(CredentialStore::defaultPath());
        $accounts = $this->accountNames($cfg, $store);

        $transport = (array) ($cfg['transport'] ?? []);
        $insecure = (bool) ($transport['insecureLoopbackProxy'] ?? false);
        $authBase = Endpoint::authBase(
            (string) (self::nonEmptyString(getenv('MSTEAMS_AUTH_BASE'))
                ?? $transport['authBaseUrl']
                ?? ''),
            $insecure,
        );
        $jwksUrl = Endpoint::jwksUrl(
            (string) (self::nonEmptyString(getenv('MSTEAMS_JWKS_URL'))
                ?? $transport['jwksUrl']
                ?? ''),
            $insecure,
        );

        $secrets = [];
        $credentials = [];
        foreach ($accounts as $account) {
            $triple = $store->msteamsCredentials($account);
            if ($triple === null && $account === 'default') {
                // §8.2 env fallback: default account only, store wins.
                $appId = self::nonEmptyString(getenv(CredentialStore::APP_ID_ENV));
                $appPassword = self::nonEmptyString(getenv(CredentialStore::APP_PASSWORD_ENV));
                $tenantId = self::nonEmptyString(getenv(CredentialStore::TENANT_ID_ENV));
                if ($appId !== null && $appPassword !== null && $tenantId !== null) {
                    $triple = ['appId' => $appId, 'appPassword' => $appPassword, 'tenantId' => $tenantId];
                }
            }
            if ($triple === null) {
                $this->log->warn('channels.msteams.account_without_credentials', ['account' => $account]);
                continue;
            }
            $credentials[$account] = $triple;
            $secrets[] = $triple['appPassword'];
        }
        if ($credentials === []) {
            $this->log->warn('channels.msteams.no_usable_accounts');

            return;
        }

        // ONE shared TextSafety for the whole channel: pairing codes minted
        // later register into THIS instance (addSecret), so every face and
        // the router redact the same live roster. withSecrets() replaces —
        // build once with the app passwords, then only add.
        $this->safety = (new TextSafety())->withSecrets($secrets);
        $pairing = new PairingCodes($store, $this->safety);

        $dir = Bootstrap::trustedConfigDirPath() . '/msteams';
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->map = new SessionMap($dir . '/sessions.db');

        $webhookPath = trim((string) ($transport['webhookPath'] ?? '/api/messages'));
        if ($webhookPath === '' || $webhookPath[0] !== '/') {
            $webhookPath = '/' . $webhookPath;
        }
        $this->mountPath = '/channels/msteams' . rtrim($webhookPath, '/');
        if ($this->mountPath === '/channels/msteams') {
            $this->mountPath = '/channels/msteams/api/messages';
        }

        $roster = self::rosterProvider($jwksUrl, $insecure);
        foreach ($credentials as $account => $triple) {
            $jwt = new Jwt($triple['appId'], $roster);
            $this->validators[$triple['appId']] = ['account' => $account, 'jwt' => $jwt];
            $broker = new TokenBroker($triple, $this->log, $authBase);
            $rest = new Rest($broker, $this->log, $insecure);
            $this->faces[] = new MsTeamsAccount(
                $account,
                $triple['appId'],
                $rest,
                // The router arrives with the serve; faces read it lazily.
                $this->pendingRouter(),
                $this->faceConfig($cfg, $account),
                $this->log,
                $pairing,
                $this->safety,
                $this->loop,
            );
        }

        // Faces exist before any dial: register them on the placeholder so
        // pre-serve inbound reaches the failWith door (loudly) instead of
        // dropping silently. rebuildRouter() swaps in the live router and
        // re-adds them there when serve appears.
        $placeholder = $this->pendingRouter();
        foreach ($this->faces as $face) {
            $placeholder->addChannel($face, $face->accountName());
        }

        $this->pollTimer = $this->loop->addPeriodicTimer(5.0, function (): void {
            $this->pollServe();
        });
        $this->pollServe();
        // The dedupe ledger stays bounded without a platform-side sweep
        // (§9.5); 6 h is amply frequent for a 7-day keep.
        $this->pruneTimer = $this->loop->addPeriodicTimer(21600.0, function (): void {
            $this->map?->pruneSeen(7);
        });
    }

    /**
     * The mounted webhook door (§8.1). The candy-code router delivers the
     * WHOLE request under `/channels/msteams/`; this method owns path
     * matching, size capping BEFORE any comparison, JWT validation, and
     * the activity fast-ack law (§8.5: the answer to the model is NOT
     * produced inside the request — 200 returns as soon as the activity
     * is accepted and queued).
     */
    public function handleRequest(ServerRequestInterface $request): ResponseInterface
    {
        if ($this->loop === null || $this->disposed) {
            return self::json(503, ['error' => 'channel_not_started']);
        }
        $path = rtrim($request->getUri()->getPath(), '/');
        if (!\str_starts_with($path, $this->mountPath)) {
            return self::json(404, ['error' => 'not_found']);
        }
        if ($request->getMethod() !== 'POST') {
            return self::json(405, ['error' => 'method_not_allowed']);
        }

        $body = (string) $request->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            // Over-cap BEFORE any parse or comparison (HookWebhooks doctrine).
            return self::json(413, ['error' => 'payload_too_large']);
        }
        $bearer = '';
        foreach ($request->getHeader('Authorization') as $header) {
            if (preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) === 1) {
                $bearer = $m[1];
                break;
            }
        }
        if ($bearer === '') {
            $this->log->warn('channels.msteams.webhook_unauthorized', ['why' => 'no bearer']);

            return self::json(401, ['error' => 'unauthorized']);
        }
        $matched = null;
        foreach ($this->validators as $entry) {
            try {
                $matched = ['claims' => $entry['jwt']->validate($bearer), 'account' => $entry['account']];

                break;
            } catch (JwtException $e) {
                if ($e->reason === 'roster') {
                    // Roster unreachable is the OPERATOR's problem, not a
                    // forged token: name it loudly, refuse quietly.
                    $this->log->error('channels.msteams.jwks_roster_unavailable', ['error' => $e->getMessage()]);
                }
            }
        }
        if ($matched === null) {
            $this->log->warn('channels.msteams.webhook_unauthorized', ['why' => 'token refused']);

            return self::json(401, ['error' => 'unauthorized']);
        }

        $activity = json_decode($body, true);
        if (!is_array($activity) || (string) ($activity['type'] ?? '') === '') {
            return self::json(400, ['error' => 'bad_activity']);
        }
        $face = $this->faceForAccount($matched['account']);
        if ($face === null) {
            return self::json(404, ['error' => 'account_not_served']);
        }
        // The serviceUrl the face will dial rides ACTIVITY data — pin it to
        // the same https floor Rest enforces, before the pipeline starts.
        $activity['serviceUrl'] = trim((string) ($activity['serviceUrl'] ?? ''));
        try {
            $face->handleActivity($activity);
        } catch (\Throwable $e) {
            // Fast-ack law: a pipeline slip never 500s the webhook into a
            // Microsoft retry storm; it LOUDS in the channel log.
            $this->log->error('channels.msteams.activity_failed', ['error' => $e->getMessage()]);
        }

        return self::json(200, $activity['type'] === 'invoke' ? ['status' => 200] : []);
    }

    public function dispose(): void
    {
        if ($this->disposed) {
            return;
        }
        $this->disposed = true;

        foreach ([$this->pollTimer, $this->pruneTimer] as $timer) {
            if ($timer !== null && $this->loop !== null) {
                $this->loop->cancelTimer($timer);
            }
        }
        $this->pollTimer = null;
        $this->pruneTimer = null;

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
        $this->placeholderRouter = null;
        $this->validators = [];
    }

    /** Faces built for the dark-posture e2e seam (tests only). */
    public function faceNames(): array
    {
        return array_map(static fn (MsTeamsAccount $face): string => $face->accountName(), $this->faces);
    }

    // ── serve leg (the shared P2 state machine) ──

    private function pollServe(): void
    {
        if ($this->loop === null || $this->map === null || $this->safety === null) {
            return;
        }
        if (time() < $this->nextDialAt) {
            return;
        }
        $stateDirPath = (string) (getenv('CANDY_CODE_SERVER_DIR')
            ?: (rtrim((string) (getenv('HOME') ?: ''), '/') . '/.candy-code/server'));
        try {
            $dir = StateDir::existing($stateDirPath);
        } catch (\Throwable $e) {
            $this->log?->warn('channels.msteams.state_dir_refused', ['error' => $e->getMessage()]);
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
            $this->log?->warn('channels.msteams.discovery_url_untranslatable');

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
        $cfg = method_exists(Bootstrap::class, 'channelsMsteamsSettings') ? Bootstrap::channelsMsteamsSettings() : [];
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
                $this->log?->warn('channels.msteams.serve_down', ['why' => $why]);
            },
            function () use ($dial): void {
                // The ladder gave up (gone serve / dead host): back off the
                // discovery poll itself, keep the channel alive.
                $this->nextDialAt = time() + 30;
                $this->dialIdentity = '';
                $this->log?->warn('channels.msteams.serve_giveup', ['retry' => 'in 30s']);
            },
        )->then(function () use ($dial): void {
            $this->dialIdentity = $dial !== '' ? $dial : $this->dialIdentity;
            $this->router?->adoptBindings();
            $this->log?->info('channels.msteams.serve_connected');
        }, function (\Throwable $e): void {
            $this->log?->warn('channels.msteams.serve_dial_failed', ['error' => $e->getMessage()]);
        });
    }

    // ── config shaping ──

    /** @return list<string> */
    private function accountNames(array $cfg, CredentialStore $store): array
    {
        $names = $store->msteamsAccounts();
        $names[] = 'default';
        foreach (array_keys((array) ($cfg['accounts'] ?? [])) as $extra) {
            $names[] = (string) $extra;
        }

        return array_values(array_unique(array_filter($names, static fn ($n): bool => $n !== '' && $n !== null)));
    }

    /** @return array<string,mixed> */
    private function faceConfig(array $cfg, string $account): array
    {
        $access = (array) ($cfg['access'] ?? []);
        $groups = (array) ($cfg['groups'] ?? []);
        $outbound = (array) ($cfg['outbound'] ?? []);
        $media = (array) ($cfg['media'] ?? []);
        $approvals = (array) ($cfg['approvals'] ?? []);

        $face = [
            'dmPolicy' => (string) ($access['dmPolicy'] ?? 'pairing'),
            'dmScope' => (string) ($access['dmScope'] ?? Keys::DM_PER_PEER),
            'allowFrom' => (array) ($access['allowFrom'] ?? []),
            'groupPolicy' => (string) ($groups['groupPolicy'] ?? 'allowlist'),
            // §8.3: rows are keyed by Teams conversation id; NO row means
            // the group is not served — the default is BLOCK, so an empty
            // roster here silences every channel and group chat.
            'groups' => (array) ($groups['rows'] ?? []),
            'degraded' => (bool) ($groups['degraded'] ?? false),
            'allowBots' => (string) ($access['allowBots'] ?? 'mentions'),
            'ignoreOtherMentions' => (bool) ($access['ignoreOtherMentions'] ?? true),
            'respondWithoutMention' => (bool) ($access['respondWithoutMention'] ?? false),
            'botName' => (string) ($access['botName'] ?? 'bot'),
            'typing' => (bool) ($outbound['typing'] ?? true),
            'outbound' => $outbound,
            'media' => $media,
            'approvals' => $approvals,
            'approvalRoster' => (array) ($approvals['approvers'] ?? []),
        ];

        // accounts.<id> rows override EVERYTHING above, per account (§7.11
        // multi-account flattening — same posture as the discord bundle).
        $row = (array) (((array) ($cfg['accounts'] ?? []))[$account] ?? []);
        foreach ($row as $key => $value) {
            $face[$key] = $value;
        }

        return $face;
    }

    private function faceForAccount(string $account): ?MsTeamsAccount
    {
        foreach ($this->faces as $face) {
            if ($face->accountName() === $account) {
                return $face;
            }
        }

        return null;
    }

    /**
     * kid → PEM signing roster, refreshed lazily by the Jwt cache. The
     * fetch is a bounded synchronous GET (5 s) on first use / stale kid —
     * inside the webhook handler that is a request-scoped cost, never a
     * model-path stall: after the first success the Jwt answers from
     * memory for ROSTER_REFRESH_SECONDS.
     *
     * @return \Closure(): array<string,string>
     */
    private static function rosterProvider(string $jwksUrl, bool $insecureLoopbackProxy): \Closure
    {
        unset($insecureLoopbackProxy); // already enforced by Endpoint::jwksUrl()
        return static function () use ($jwksUrl): array {
            $raw = @file_get_contents($jwksUrl, false, stream_context_create([
                'http' => ['timeout' => 5.0, 'header' => "User-Agent: candy-code-msteams-bundle/0.1\r\n"],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]));
            if ($raw === false) {
                throw new JwtException('signing-key roster unreachable', 'roster');
            }
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                throw new JwtException('signing-key roster is not JSON', 'roster');
            }
            $keys = [];
            foreach ((array) ($decoded['keys'] ?? []) as $jwk) {
                if (is_array($jwk) && ($jwk['use'] ?? 'sig') === 'sig') {
                    try {
                        $keys[(string) ($jwk['kid'] ?? '')] = Jwk::toPem($jwk);
                    } catch (JwtException) {
                        // A single unreadable roster entry never kills the
                        // set — Microsoft rotates keys by overlapping kids.
                        continue;
                    }
                }
            }

            return $keys;
        };
    }

    /** @param array<string,mixed> $payload */
    private static function json(int $status, array $payload): ResponseInterface
    {
        return new \GuzzleHttp\Psr7\Response(
            $status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($payload, \JSON_INVALID_UTF8_SUBSTITUTE),
        );
    }

    private static function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Faces take a router at construction; the real one arrives with the
     * first serve dial. The placeholder's bridge never starts, so every
     * call on it refuses 'not connected' — and the refusal still reaches
     * the channel, because start() registers the faces on this very router
     * (pre-dial inbound is LOUD, never a silent drop).
     */
    private function pendingRouter(): ConversationRouter
    {
        if ($this->placeholderRouter !== null) {
            return $this->placeholderRouter;
        }
        $serve = new ServeBridge('ws://127.0.0.1:1/never', '', 'subprotocol', $this->log, $this->loop);
        // Never started, never dials: start() is only called on the
        // rebuilt instance; this one exists to satisfy the constructor.
        $this->placeholderRouter = new ConversationRouter($serve, $this->map, $this->log, $this->safety, $this->loop, []);

        return $this->placeholderRouter;
    }
}
