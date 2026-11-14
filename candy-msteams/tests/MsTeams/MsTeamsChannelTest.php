<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Jwk;
use CandyCode\Bundles\MsTeams\MsTeamsChannel;
use CandyCode\Bundles\MsTeams\Tests\Fixture\FakeServe;
use SugarCraft\Code\Server\Auth\TokenStore;
use SugarCraft\Code\Server\DiscoveryFile;
use SugarCraft\Code\Server\StateDir;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use CandyCode\Bundles\MsTeams\Support\TextSafety;
use GuzzleHttp\Psr7\ServerRequest;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * Policy-door pins for the channel root, bundle-local (the marketplace
 * trust walk lives in candy-code's MsTeamsBundleWeldTest). The HOME
 * sandbox is the law here — every reader resolves through
 * Bootstrap::trustedConfigDirPath() into the temp tree, so a developer's
 * real ~/.candy-code can neither turn these windows red nor receive
 * bundle state. Everything that must refuse BEFORE any crypto or platform
 * byte exists is owned by this file; the trust-door legs behind a verified
 * token (the activity parse door and the served-account door) ride here
 * too, on the local JWK fixture dance.
 */
final class MsTeamsChannelTest extends TestCase
{
    private string $homeRoot;

    private string $previousHome;

    private LoopInterface $loop;

    protected function setUp(): void
    {
        $this->previousHome = (string) (getenv('HOME') ?: '');
        // The sandbox HOME is the whole hermeticity story only if the
        // explicit server-dir override is dark too.
        putenv('CANDY_CODE_SERVER_DIR');
        $this->homeRoot = sys_get_temp_dir() . '/p3-chan-' . getmypid() . '-' . uniqid('', true);
        mkdir($this->homeRoot . '/.candy-code', 0o700, true);
        putenv('HOME=' . $this->homeRoot);
        $_SERVER['HOME'] = $this->homeRoot;
        $this->loop = Loop::get();
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->previousHome);
        $_SERVER['HOME'] = $this->previousHome;
        @unlink($this->homeRoot . '/.candy-code/config.json');
        @unlink($this->homeRoot . '/.candy-code/channels.json');
        @rmdir($this->homeRoot . '/.candy-code');
        @rmdir($this->homeRoot);
    }

    public function testADarkTreeStartsNothingAndTouchesNothing(): void
    {
        $lines = [];
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        self::assertSame(['channels.msteams: disabled posture, nothing started'], $lines);
        self::assertSame([], $channel->faceNames());
        self::assertDirectoryDoesNotExist(Bootstrap::trustedConfigDirPath() . '/msteams');

        $channel->dispose();
        $channel->dispose(); // idempotent by contract
        self::assertSame([], $channel->faceNames());
    }

    public function testAMultiTenantAuthorityNameRefusesToStartLoudly(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-common', 'appPassword' => 'common-secret', 'tenantId' => 'common',
        ]);

        $lines = [];
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        self::assertSame([], $channel->faceNames(), 'a non-GUID tenant boots no face');
        self::assertStringContainsString('refusing to start', \implode("\n", $lines));
        self::assertStringNotContainsString('common-secret', \implode("\n", $lines), 'the secret never reaches the log');
    }

    public function testAnEmptyTenantBootsNoFace(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-empty', 'appPassword' => 'empty-secret', 'tenantId' => '',
        ]);

        $lines = [];
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        self::assertSame([], $channel->faceNames());
        self::assertStringContainsString('no_usable_accounts', \implode("\n", $lines));
    }

    public function testABogusTransportOverrideRefusesToStartLoudly(): void
    {
        $this->writeUserConfig(['channels.msteams' => [
            'enabled' => true,
            'transport' => ['authBaseUrl' => 'http://evil.example'],
        ]]);

        $channel = new MsTeamsChannel();
        try {
            $channel->start($this->context(static function (string $line): void {
            }));
            self::fail('a plain-HTTP remote endpoint must refuse the start');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('must be https', $error->getMessage());
        }

        $channel->dispose();
        self::assertDirectoryDoesNotExist(
            Bootstrap::trustedConfigDirPath() . '/msteams',
            'the endpoint parse precedes any state-directory creation',
        );
    }

    public function testTheWebhookDoorIsShutBeforeStart(): void
    {
        $channel = new MsTeamsChannel();
        $response = $channel->handleRequest(new ServerRequest('POST', 'https://h.test/channels/msteams/api/messages'));

        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('channel_not_started', (string) $response->getBody());
    }

    public function testAnEnabledApprovalsDoorWithAnEmptyRosterAnnouncesItselfOnceAtBoot(): void
    {
        // msteams-08 CODE half: approvals default ON, roster default EMPTY
        // means any paired user in the ask's target conversation can answer.
        // The shipped default is the footgun; boot must say so once, loud,
        // content-free (account label only — never ids, never secrets).
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-open', 'appPassword' => 'open-roster-secret', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);

        $lines = [];
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $joined = \implode("\n", $lines);

        self::assertSame(['default'], $channel->faceNames(), 'the sanity leg: the face booted');
        self::assertSame(1, \substr_count($joined, 'channels.msteams.approvals_open_roster'), 'exactly once per boot');
        self::assertStringNotContainsString('open-roster-secret', $joined, 'content-free');
        $channel->dispose();
    }

    public function testANamedApproverRosterAndTheDisabledDoorBothBootQuietly(): void
    {
        foreach ([['approvers' => ['u1']], ['enabled' => false]] as $arm => $approvals) {
            $this->writeUserConfig(['channels.msteams' => ['enabled' => true, 'approvals' => $approvals]]);
            $store = new CredentialStore(CredentialStore::defaultPath());
            $store->writeMsteamsPairing('default', [], [], [
                'appId' => 'app-quiet-' . $arm, 'appPassword' => 'quiet-secret', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
            ]);

            $lines = [];
            $channel = new MsTeamsChannel();
            $channel->start($this->context(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }));
            $channel->dispose();

            self::assertSame(
                0,
                \substr_count(\implode("\n", $lines), 'approvals_open_roster'),
                'arm ' . $arm . ': roster named or door closed, nothing to warn',
            );
        }
    }

    public function testAReadOnlyConfigTreeRefusesToBootWithOneCleanLine(): void
    {
        // msteams-20 leg 030: the boot-time state mkdir ran unguarded — a
        // read-only config tree emitted a raw PHP warning INTO the TUI frame
        // and died at the SQLite open. The cure: one clean refusal line and a
        // stopped boot. Skipped as root: mode bits do not bind the superuser.
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores 0500 mode bits — the refusal cannot be provoked');
        }
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-fs', 'appPassword' => 'fs-secret', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);
        $configDir = $this->homeRoot . '/.candy-code';
        chmod($configDir, 0o500);

        $lines = [];
        try {
            $channel = new MsTeamsChannel();
            $channel->start($this->context(static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }));
            $channel->dispose();
        } finally {
            chmod($configDir, 0o700);
        }
        $joined = \implode("\n", $lines);

        self::assertSame(1, \substr_count($joined, 'cannot create the state directory'), 'exactly one clean refusal line');
        self::assertStringNotContainsString('PHP Warning', $joined);
        self::assertSame([], $channel->faceNames(), 'a boot that cannot create state must stand up no face');
        self::assertFileDoesNotExist($configDir . '/msteams/sessions.db');
    }

    public function testADuplicateAppIdRefusesTheLaterTripleAndTheFirstKeepsItsValidator(): void
    {
        // msteams-20 leg 032: two accounts claiming one appId used to SILENTLY
        // overwrite the validator map — LAST-wins routing meant the earlier
        // account's face verified against the other account's Jwt. Fail loud
        // at boot: the duplicate is named once, gets no face, and the first
        // validator entry survives.
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-dup', 'appPassword' => 'dup-secret-first', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);
        $store->writeMsteamsPairing('second', [], [], [
            'appId' => 'app-dup', 'appPassword' => 'dup-secret-second', 'tenantId' => '1a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4e',
        ]);

        $lines = [];
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));
        $joined = \implode("\n", $lines);

        self::assertSame(1, \substr_count($joined, 'channels.msteams.duplicate_appid_refused'), 'the clash names the refused account once at boot');
        self::assertStringContainsString('second', $joined, 'the LATER triple is the one refused');
        self::assertSame(['default'], $channel->faceNames(), 'only the first account stands up a face');

        $validators = (new \ReflectionObject($channel))->getProperty('validators')->getValue($channel);
        self::assertSame(['app-dup'], array_keys($validators), 'one validator for a shared appId');
        self::assertSame('default', $validators['app-dup']['account'], 'first-wins: the duplicate never rides over the established validator');
        $channel->dispose();
    }

    public function testEveryRefusalBeforeCryptoCostsTheLoudCodeNotTheQuietOne(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-chan', 'appPassword' => 'chan-secret', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);

        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line): void {
        }));
        self::assertSame(['default'], $channel->faceNames(), 'the store triple booted one face');

        $url = 'https://h.test/channels/msteams/api/messages';
        $wrongPath = $channel->handleRequest(new ServerRequest('POST', 'https://h.test/channels/msteams/some/other/path'));
        self::assertSame(404, $wrongPath->getStatusCode());
        self::assertStringContainsString('not_found', (string) $wrongPath->getBody());

        $getOnMount = $channel->handleRequest(new ServerRequest('GET', $url));
        self::assertSame(405, $getOnMount->getStatusCode(), 'the webhook answers POST only');

        $huge = new ServerRequest('POST', $url);
        $huge = $huge->withBody(\GuzzleHttp\Psr7\Utils::streamFor(str_repeat('x', 1_048_577)));
        $overCap = $channel->handleRequest($huge);
        self::assertSame(413, $overCap->getStatusCode(), 'the size cap is checked before any parse or compare');

        $noBearer = $channel->handleRequest(new ServerRequest('POST', $url));
        self::assertSame(401, $noBearer->getStatusCode());

        $garbageBearer = (new ServerRequest('POST', $url))
            ->withHeader('Authorization', 'Bearer not.a.token');
        $refused = $channel->handleRequest($garbageBearer);
        self::assertSame(401, $refused->getStatusCode());
        self::assertStringContainsString('unauthorized', (string) $refused->getBody());

        $channel->dispose();
        self::assertSame([], $channel->faceNames());
        // A started channel created its state dir; dispose must not delete
        // durable state (the bindings outlive the dial, docs/SPEC.md §9.1) — pin it.
        self::assertDirectoryExists(Bootstrap::trustedConfigDirPath() . '/msteams');
    }

    /**
     * MINOR-1 (p3msteams-r1): the activity parse door BEHIND a trusted
     * token. A body that decodes to anything short of a well-formed
     * Activity must cost a loud 400 before any face touches it — and the
     * polarity leg (a well-formed invoke under the SAME token) proves the
     * door refuses on body SHAPE, not on token presence.
     */
    public function testTheWebhookDoorShedsAFloodBeforeAnyRosterDial(): void
    {
        $keysFile = self::rosterFixturePath();
        $channel = $this->bootServedChannel($keysFile);
        try {
            $bearer = 'Bearer ' . self::signedToken(self::$fixturePem, 'app-p4b');
            $statuses = [];
            for ($i = 0; $i < 31; $i++) {
                $statuses[] = $channel->handleRequest(self::webhook(self::INVOKE_BODY, $bearer))->getStatusCode();
            }

            self::assertSame(200, $statuses[0]);
            self::assertSame(429, $statuses[30], 'the 31st request inside the window is shed with 429');
        } finally {
            $channel->dispose();
            @unlink($keysFile);
        }
    }

    public function testTheActivityParseDoorRefusesMalformedBodiesBehindATrustedToken(): void
    {
        $keysFile = self::rosterFixturePath();
        $channel = $this->bootServedChannel($keysFile);
        try {
            $bearer = 'Bearer ' . self::signedToken(self::$fixturePem, 'app-p4b');
            foreach (['not json at all', '[]', '{"from":{"id":"u-1"}}', '{"type":""}'] as $body) {
                $response = $channel->handleRequest(self::webhook($body, $bearer));
                self::assertSame(400, $response->getStatusCode(), $body);
                self::assertStringContainsString('bad_activity', (string) $response->getBody());
            }

            $accepted = $channel->handleRequest(self::webhook(self::INVOKE_BODY, $bearer));
            self::assertSame(200, $accepted->getStatusCode(), 'a well-formed activity is not refused by the parse door');
            self::assertStringNotContainsString('bad_activity', (string) $accepted->getBody());
            self::assertStringContainsString('"status":200', (string) $accepted->getBody());
        } finally {
            $channel->dispose();
            @unlink($keysFile);
        }
    }

    /**
     * MINOR-1 (p3msteams-r1): the served-account door. The credential walk
     * can only match an account whose face is absent when validators and
     * faces drift (defense-in-depth guard) — the reflection swap below is
     * the deliberate drift. The pin: that request costs a loud 404, never
     * a silent drop or a 500 storm; the polarity leg restores the roster
     * and the SAME token+body pair walks through to its fast-ack.
     */
    public function testTheServedAccountDoorRefusesWhenTheMatchedCredentialHasNoFace(): void
    {
        $keysFile = self::rosterFixturePath();
        $channel = $this->bootServedChannel($keysFile);
        try {
            $faces = new \ReflectionProperty(MsTeamsChannel::class, 'faces');
            $faces->setAccessible(true);
            self::assertCount(1, $faces->getValue($channel), 'the store triple booted exactly one face');

            $served = $faces->getValue($channel);
            $faces->setValue($channel, []);
            $bearer = 'Bearer ' . self::signedToken(self::$fixturePem, 'app-p4b');
            $refused = $channel->handleRequest(self::webhook(self::INVOKE_BODY, $bearer));
            self::assertSame(404, $refused->getStatusCode());
            self::assertStringContainsString('account_not_served', (string) $refused->getBody());

            $faces->setValue($channel, $served);
            $accepted = $channel->handleRequest(self::webhook(self::INVOKE_BODY, $bearer));
            self::assertSame(200, $accepted->getStatusCode(), 'the door is about the face roster, not the request');
            self::assertStringNotContainsString('account_not_served', (string) $accepted->getBody());
        } finally {
            $channel->dispose();
            @unlink($keysFile);
        }
    }

    /**
     * The tenant id names the OAuth authority URL that react/http
     * failure prose embeds, and that prose travels through the safety
     * fence and the outbound deliver path — so it must ride the redaction
     * roster beside the app password. The pin reads the roster the LIVE
     * channel built (no hand-constructed TextSafety) and pushes a
     * token-failure-shaped line through it.
     */
    public function testTheTenantIdRidesTheRedactionRosterBesideTheAppPassword(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-p4b', 'appPassword' => 'p4b-secret', 'tenantId' => '1d2c3b4a-5f6e-4d7c-8b9a-0f1e2d3c4b5a',
        ]);

        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line): void {
        }));
        try {
            $safety = new \ReflectionProperty(MsTeamsChannel::class, 'safety');
            $safety->setAccessible(true);
            $roster = $safety->getValue($channel);
            self::assertInstanceOf(TextSafety::class, $roster, 'the started channel owns the live roster');

            $tokenFailure = 'msteams token exchange failed: POST https://login.microsoftonline.com/'
                . '1d2c3b4a-5f6e-4d7c-8b9a-0f1e2d3c4b5a/oauth2/v2/token returned 400 Bad Request (client_secret p4b-secret)';
            $red = $roster->outbound($tokenFailure);

            self::assertStringNotContainsString('1d2c3b4a-5f6e-4d7c-8b9a-0f1e2d3c4b5a', $red, 'the tenant id is a roster secret');
            self::assertStringNotContainsString('p4b-secret', $red, 'the app password already was');
            self::assertStringContainsString('[redacted]', $red);
        } finally {
            $channel->dispose();
        }
    }

    /**
     * msteams-02: the 5 s discovery poll must dial ONE router per serve,
     * not one per tick. Two ticks over an unchanged discovery + token keep
     * the ServeBridge/ConversationRouter objects; flipping the token rotates
     * them. This is the finding's evidence gap: nothing on disk ever touched
     * dialIdentity/pollServe before this pin.
     */
    public function testAnUnchangedDiscoveryKeepsTheRouterAcrossPollTicks(): void
    {
        $serve = new FakeServe($this->loop, 'tick-token');
        $port = $serve->listen();
        $serverDir = $this->homeRoot . '/server-state';
        putenv('CANDY_CODE_SERVER_DIR=' . $serverDir);
        mkdir($serverDir, 0o700, true);
        $dir = StateDir::existing($serverDir);
        self::assertNotNull($dir, 'the state dir must be creatable under the sandbox HOME');
        DiscoveryFile::forThisProcess(
            '0.0.0-test',
            'http://127.0.0.1:' . $port,
            '127.0.0.1',
            $port,
            null,
            false,
            null,
        )->write($dir);
        $tokenPath = $dir->path . '/' . TokenStore::FILE;
        file_put_contents($tokenPath, 'tick-token');
        chmod($tokenPath, 0o600);

        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-tick', 'appPassword' => 'tick-secret', 'tenantId' => '9f8e7d6c-5b4a-4321-8765-4321fedcba09',
        ]);
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line): void {
        }));

        $dialProp = new \ReflectionProperty(MsTeamsChannel::class, 'dialIdentity');
        $dialProp->setAccessible(true);
        $serveProp = new \ReflectionProperty(MsTeamsChannel::class, 'serve');
        $serveProp->setAccessible(true);
        $routerProp = new \ReflectionProperty(MsTeamsChannel::class, 'router');
        $routerProp->setAccessible(true);
        $poll = new \ReflectionMethod(MsTeamsChannel::class, 'pollServe');
        $poll->setAccessible(true);

        try {
            // Boot already fired one pollServe; pump until that dial lands
            // (a rejected/never-dial serve would leave dialIdentity '' and
            // rebuild for the wrong reason).
            $deadline = microtime(true) + 5.0;
            while ($dialProp->getValue($channel) === '' && microtime(true) < $deadline) {
                $this->pump(100);
            }
            self::assertNotSame('', $dialProp->getValue($channel), 'the first dial never succeeded');

            $serveBefore = $serveProp->getValue($channel);
            $routerBefore = $routerProp->getValue($channel);
            self::assertNotNull($serveBefore);
            self::assertNotNull($routerBefore);

            $poll->invoke($channel);
            self::assertSame($serveBefore, $serveProp->getValue($channel), 'an unchanged discovery must not rebuild the serve bridge');
            self::assertSame($routerBefore, $routerProp->getValue($channel), 'an unchanged discovery must not rebuild the router');

            // A rotated token is a new dial: the objects must turn over.
            file_put_contents($tokenPath, 'tick-token-rotated');
            chmod($tokenPath, 0o600);
            $poll->invoke($channel);
            self::assertNotSame($serveBefore, $serveProp->getValue($channel), 'a rotated serve token must re-dial');
            self::assertNotSame($routerBefore, $routerProp->getValue($channel), 'a rotated serve token must rebuild the router');
        } finally {
            $channel->dispose();
            $serve->stop();
            @unlink($tokenPath);
            @unlink($dir->discoveryPath());
            @rmdir($serverDir);
            putenv('CANDY_CODE_SERVER_DIR');
        }
    }

    /**
     * msteams-18: the periodic ticks are fenced. With the session ledger
     * corrupted under a live connection, the prune tick must swallow at the
     * door, name itself in the log, and still be callable the next tick —
     * an escaping throw here would kill the SHARED loop.
     */
    public function testACorruptSessionDbCostsThePruneTickNotTheLoop(): void
    {
        $lines = [];
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-fence', 'appPassword' => 'fence-secret', 'tenantId' => '2b3c4d5e-6f70-4182-93a4-b5c6d7e8f901',
        ]);
        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        try {
            $mapProp = new \ReflectionProperty(MsTeamsChannel::class, 'map');
            $mapProp->setAccessible(true);
            $map = $mapProp->getValue($channel);
            self::assertNotNull($map, 'the boot must have minted the session ledger');

            // Rot the database under the live handle: settle the WAL into
            // the main file first (probe-proven — without the checkpoint the
            // cached pages never re-read the rot), then overwrite it.
            $settler = new \PDO('sqlite:' . $map->path);
            $settler->exec('PRAGMA wal_checkpoint(TRUNCATE)');
            $settler = null;
            file_put_contents($map->path, str_repeat('rot', 4000));

            $prune = new \ReflectionMethod(MsTeamsChannel::class, 'pruneSeenGuarded');
            $prune->setAccessible(true);
            $prune->invoke($channel);
            $prune->invoke($channel); // the next tick still runs
            $warned = \array_values(\array_filter($lines, static fn (string $l): bool => \str_contains($l, 'prune_tick_failed')));
            self::assertGreaterThanOrEqual(2, count($warned), 'each failed tick must name itself');

            // The poll fence holds the same contract on a clean tick.
            $poll = new \ReflectionMethod(MsTeamsChannel::class, 'pollServeGuarded');
            $poll->setAccessible(true);
            $poll->invoke($channel);
        } finally {
            $channel->dispose();
        }
    }

    /**
     * msteams-21 (leg b): codes minted by a previous process live in the
     * store, but their addSecret died with that process. Boot must re-register
     * the live pending roster or the first outbound chunk can echo a live
     * pairing code straight through the redaction door.
     */
    public function testAPendingCodeFromTheStoreIsRedactedFromBootOnward(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [
            'CD234567' => ['userId' => 'peer-x', 'createdAt' => time(), 'expiresAt' => time() + 3600, 'lastReminderAt' => time()],
        ], ['peer-x'], [
            'appId' => 'app-rer', 'appPassword' => 'rer-secret', 'tenantId' => '6a5b4c3d-2e1f-4a0b-9c8d-7e6f5a4b3c2d',
        ]);

        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line): void {
        }));
        try {
            $safetyProp = new \ReflectionProperty(MsTeamsChannel::class, 'safety');
            $safetyProp->setAccessible(true);
            $safety = $safetyProp->getValue($channel);
            self::assertNotNull($safety);

            self::assertStringNotContainsString('CD234567', $safety->outbound('is CD234567 the pairing code?'), 'the boot roster must already carry the stored code');
            self::assertStringContainsString('[redacted]', $safety->outbound('is CD234567 the pairing code?'));
        } finally {
            $channel->dispose();
        }
    }

    /** Run the shared loop for $ms of wall time (timer stops it). */
    private function pump(int $ms): void
    {
        $loop = $this->loop;
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }

    /**
     * msteams-19 (022): the mount door is EXACT. A suffixed lookalike
     * (/channels/msteams/api/messagesX) rode the old prefix match into the
     * whole auth+parse pipeline; it must 404 at the door like any foreign
     * path (the bundle's route table has no subroutes).
     */
    public function testASuffixedLookalikePathIsNotFoundAtTheDoor(): void
    {
        $keysFile = self::rosterFixturePath();
        $channel = $this->bootServedChannel($keysFile);
        try {
            $response = $channel->handleRequest(new ServerRequest('POST', 'https://h.test/channels/msteams/api/messagesX'));
            self::assertSame(404, $response->getStatusCode(), 'a suffixed lookalike must not reach the pipeline');

            $exact = $channel->handleRequest(new ServerRequest('POST', 'https://h.test/channels/msteams/api/messages'));
            self::assertNotSame(404, $exact->getStatusCode(), 'the true mount still answers');
        } finally {
            $channel->dispose();
            @unlink($keysFile);
        }
    }

    /** Well-formed invoke Activity — fast-ack law answers 200 inside the request. */
    private const INVOKE_BODY = '{"type":"invoke","id":"p4b-1","name":"adaptiveCard.action","from":{"id":"u-p4b"},"conversation":{"id":"c-p4b","conversationType":"personal"},"serviceUrl":"https://example.test","value":{}}';

    /** PEM of the fixture signing key, set by {@see rosterFixture()}. */
    private static string $fixturePem = '';

    private static function rosterFixturePath(): string
    {
        return sys_get_temp_dir() . '/p4b-keys-' . getmypid() . '-' . uniqid('', true) . '.json';
    }

    /**
     * Generate one throwaway RSA keypair and publish its public half as a
     * JWKS roster file at $keysFile; the private PEM is parked for
     * {@see signedToken()}. No network: the channel reads the file through
     * its file:// roster scheme, same dance the weld test runs.
     */
    private static function writeRosterFixture(string $keysFile): void
    {
        $pair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertNotFalse($pair, 'openssl must hand out an RSA fixture key');
        openssl_pkey_export($pair, $privatePem);
        $details = openssl_pkey_get_details($pair);
        self::assertIsArray($details);
        self::$fixturePem = (string) $privatePem;
        file_put_contents(
            $keysFile,
            (string) json_encode(['keys' => [Jwk::fromPublicKey($details['key'], 'kid-p4b')]]),
        );
    }

    /** RS256 token for $appId, signed by the fixture key, in a valid window. */
    private static function signedToken(string $privatePem, string $appId): string
    {
        $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
        $header = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-p4b']));
        $claims = $encode((string) json_encode([
            'aud' => $appId,
            'iss' => 'https://api.botframework.com',
            'sub' => 'p4b-sub',
            'nbf' => time() - 5,
            'exp' => time() + 600,
        ]));
        self::assertTrue(
            openssl_sign($header . '.' . $claims, $signature, $privatePem, OPENSSL_ALGO_SHA256),
        );

        return $header . '.' . $claims . '.' . $encode($signature);
    }

    private static function webhook(string $body, string $authorization): ServerRequest
    {
        $request = new ServerRequest('POST', 'https://h.test/channels/msteams/api/messages');

        return $request->withHeader('Authorization', $authorization)->withBody(Utils::streamFor($body));
    }

    /**
     * Boot the channel with one store triple AND a local JWKS roster, so
     * the crypto door opens only for {@see signedToken()} tokens; returns
     * the started channel (caller disposes and unlinks $keysFile).
     */
    private function bootServedChannel(string $keysFile): MsTeamsChannel
    {
        self::writeRosterFixture($keysFile);
        $this->writeUserConfig(['channels.msteams' => [
            'enabled' => true,
            'transport' => ['jwksUrl' => 'file://' . $keysFile],
        ]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-p4b', 'appPassword' => 'p4b-secret', 'tenantId' => '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d',
        ]);

        $channel = new MsTeamsChannel();
        $channel->start($this->context(static function (string $line): void {
        }));
        self::assertSame(['default'], $channel->faceNames(), 'the store triple booted one face');

        return $channel;
    }

    /** @param \Closure(string):void $sink */
    private function context(\Closure $sink): \SugarCraft\Code\Channels\ChannelContext
    {
        return \SugarCraft\Code\Channels\ChannelContext::for(
            'candy-msteams',
            'msteams',
            $this->homeRoot,
            $this->loop,
            $sink,
        );
    }

    /** @param array<string,mixed> $tree */
    private function writeUserConfig(array $tree): void
    {
        // FLAT dotted keys — 'channels.msteams' is the schema key verbatim,
        // exactly as the settings UI writes it.
        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) json_encode($tree),
        );
    }
}
