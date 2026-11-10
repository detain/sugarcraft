<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeams\Jwk;
use CandyCode\Bundles\MsTeams\MsTeamsChannel;
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

    public function testEveryRefusalBeforeCryptoCostsTheLoudCodeNotTheQuietOne(): void
    {
        $this->writeUserConfig(['channels.msteams' => ['enabled' => true]]);
        $store = new CredentialStore(CredentialStore::defaultPath());
        $store->writeMsteamsPairing('default', [], [], [
            'appId' => 'app-chan', 'appPassword' => 'chan-secret', 'tenantId' => 'ten-chan',
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
        // durable state (the bindings outlive the dial, §9.1) — pin it.
        self::assertDirectoryExists(Bootstrap::trustedConfigDirPath() . '/msteams');
    }

    /**
     * MINOR-1 (p3msteams-r1): the activity parse door BEHIND a trusted
     * token. A body that decodes to anything short of a well-formed
     * Activity must cost a loud 400 before any face touches it — and the
     * polarity leg (a well-formed invoke under the SAME token) proves the
     * door refuses on body SHAPE, not on token presence.
     */
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
            'appId' => 'app-p4b', 'appPassword' => 'p4b-secret', 'tenantId' => 'tenant-p4b-41',
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
                . 'tenant-p4b-41/oauth2/v2/token returned 400 Bad Request (client_secret p4b-secret)';
            $red = $roster->outbound($tokenFailure);

            self::assertStringNotContainsString('tenant-p4b-41', $red, 'the tenant id is a roster secret');
            self::assertStringNotContainsString('p4b-secret', $red, 'the app password already was');
            self::assertStringContainsString('[redacted]', $red);
        } finally {
            $channel->dispose();
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
            'appId' => 'app-p4b', 'appPassword' => 'p4b-secret', 'tenantId' => 'ten-p4b',
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
