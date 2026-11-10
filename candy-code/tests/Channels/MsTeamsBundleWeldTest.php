<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use CandyCode\Bundles\MsTeams\MsTeams\Jwk;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use GuzzleHttp\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\BundleChannelLoader;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Channels\ChannelInterface;
use SugarCraft\Code\Channels\ChannelRegistry;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Consumer window for the `@candy/msteams` bundle (code-chat P3) — the
 * twin of {@see DiscordBundleWeldTest}, plus what Discord could not have:
 * the FIRST live exercise of P1's HTTP-route mount.
 *
 * The load-walk legs are the house pattern (copy the repo bundle into a
 * temp install, quarantine then load, dark channel touches nothing). The
 * enabled walk goes further: it stands the webhook door behind
 * ChannelRegistry::httpRouter() against the loopback fake Teams API with
 * fixture RSA keys — Bot Framework JWT accept/refuse, the pairing ladder
 * minting and approving a real code through the real 0600 store, the
 * placeholder-serve refusal riding back as a disclosed warning, and the
 * invoke fast-ack. No Microsoft endpoint is ever dialled: the token
 * exchange rides the loopback fake and the signing roster rides an
 * absolute file:// path — the offline door the roster alone may use.
 *
 * @see \SugarCraft\Code\Channels\BundleChannelLoader
 */
final class MsTeamsBundleWeldTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy-code-msteams-weld-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        // Hermeticity, per the house sandbox law (the same ordering the
        // discord twin documents): every HOME-rooted reader —
        // trustedConfigDirPath, the raw user config behind
        // channelsMsteamsSettings, the CredentialStore default path —
        // resolves into the temp tree.
        $this->useHomeSandbox($this->tempDir . '/home');
        // The serve-discovery door stays dark for this window: there is no
        // live serve here, and the poll must prove it touches nothing.
        putenv('CANDY_CODE_SERVER_DIR=' . $this->tempDir . '/no-serve');
    }

    protected function tearDown(): void
    {
        putenv('CANDY_CODE_SERVER_DIR');
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
    }

    public function testTheRepoBundleParsesAndNamesItself(): void
    {
        $bundle = Bundle::fromDir($this->installBundle(), 'user');
        self::assertNotNull($bundle, 'the repo bundle carries a readable manifest');
        self::assertSame('candy-msteams', $bundle->name);

        // The manifest IS the trust contract's other half: name the exact
        // class the P1 gate will instantiate, and the single door it rides.
        $points = $bundle->entrypoints();
        self::assertSame(['channel'], array_keys($points), 'the manifest opens exactly one channel door');
        self::assertSame(
            'CandyCode\\Bundles\\MsTeams\\MsTeamsChannel',
            $points['channel'][0]['class'],
            'the entrypoint class matches the shipped class byte-for-byte',
        );
        self::assertStringEndsWith('src/MsTeamsChannel.php', $points['channel'][0]['path']);
        self::assertSame('0.1.0', $bundle->version);
    }

    public function testAnUntrustedNameOpensNothingAtAll(): void
    {
        $bundle = Bundle::fromDir($this->installBundle(), 'user');
        self::assertNotNull($bundle);
        $buckets = BundleChannelLoader::load([$bundle], [], $this->store());
        self::assertSame([], $buckets['loaded']);
        self::assertSame([], $buckets['quarantined'], 'the door is shut before any read: not even the ledger grows');
    }

    /**
     * One test carries the whole walk, dark and enabled: the entrypoint is
     * a REAL named class, a declared class name lives for the whole PHP
     * process, and the enabled webhook door needs the bytes the dark phase
     * already installed. Re-installing in a second test would fatal on
     * re-declaration — same discipline as the discord twin, extended to the
     * §8.1/§8.3 proof the core mount has never before given any bundle.
     */
    public function testTheTrustDoorLoadsTheRealBundleAndTheWebhookDoorKeepsItsLaw(): void
    {
        $bundle = Bundle::fromDir($this->installBundle(), 'user');
        self::assertNotNull($bundle);
        $store = $this->store();

        $first = BundleChannelLoader::load([$bundle], ['candy-msteams'], $store);
        self::assertSame([], $first['loaded'], 'launch one never runs entrypoint bytes it has not seen');
        self::assertCount(1, $first['quarantined']);
        self::assertSame('candy-msteams', $first['quarantined'][0]['bundle']);
        self::assertSame('channel', $first['quarantined'][0]['kind']);

        $second = BundleChannelLoader::load([$bundle], ['candy-msteams'], $store);
        self::assertSame([], $second['quarantined'], 'the ledger remembered the first sight');
        self::assertCount(1, $second['loaded'], 'the shipped bytes pass the private-bytes gate as installed');

        $entryClass = $second['loaded'][0]['class'];
        self::assertTrue(class_exists($entryClass), 'the loaded entrypoint class is live in this process');
        $instance = new $entryClass();
        self::assertInstanceOf(ChannelInterface::class, $instance);
        self::assertSame('msteams', $instance->name(), 'the channel claims the bare name the registry routes on');

        // ── dark posture (no settings written yet) ──
        $darkLines = [];
        $darkContext = ChannelContext::for(
            'candy-msteams',
            'msteams',
            $this->tempDir . '/root',
            new StreamSelectLoop(),
            static function (string $line) use (&$darkLines): void {
                $darkLines[] = $line;
            },
        );
        $instance->start($darkContext);
        self::assertSame(
            ['channels.msteams: disabled posture, nothing started'],
            $darkLines,
            'with no user-tier policy the channel announces darkness and arms nothing',
        );
        self::assertSame([], $instance->faceNames(), 'no account face was built from a dark tree');
        self::assertDirectoryDoesNotExist(
            \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath() . '/msteams',
            'a dark channel must not create state directories under the (sandboxed) home',
        );
        $instance->dispose();
        $instance->dispose(); // idempotent by contract — a double dispose must not throw
        self::assertSame([], $instance->faceNames());

        // ── enabled walk behind the REAL P1 mount ──
        require_once \dirname(__DIR__, 3) . '/candy-msteams/tests/Fixture/FakeTeamsApi.php';
        $loop = Loop::get();
        $api = new \CandyCode\Bundles\MsTeams\Tests\Fixture\FakeTeamsApi($loop);
        $port = $api->listen();
        $serviceUrl = 'http://127.0.0.1:' . $port;

        // Fixture RSA key: the signer here, the file:// roster the door reads.
        $pair = \openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        self::assertNotFalse($pair);
        \openssl_pkey_export($pair, $privatePem);
        $details = \openssl_pkey_get_details($pair);
        self::assertIsArray($details);
        $keysFile = $this->tempDir . '/keys.json';
        file_put_contents($keysFile, (string) json_encode(['keys' => [Jwk::fromPublicKey($details['key'], 'kid-weld')]]));

        // Credentials land in the SAME 0600 store the bundle reads, through
        // the bundle's own writer; the first approver is bootstrapped by
        // the operator (a store write), because pairing-mode refuses /pair
        // to the unpaired.
        $creds = new CredentialStore(CredentialStore::defaultPath());
        $creds->writeMsteamsPairing('default', [], ['u-approver'], [
            'appId' => 'app-weld',
            'appPassword' => 'weld-secret-app',
            'tenantId' => 'ten-weld',
        ]);

        $configDir = \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath();
        if (!is_dir($configDir)) {
            mkdir($configDir, 0o700, true);
        }
        file_put_contents($configDir . '/config.json', (string) json_encode([
            'channels.msteams' => [
                'enabled' => true,
                'transport' => [
                    'authBaseUrl' => $serviceUrl,
                    'jwksUrl' => 'file://' . $keysFile,
                    'insecureLoopbackProxy' => true,
                ],
                'access' => ['dmPolicy' => 'pairing'],
            ],
        ]));

        $channel = new $entryClass();
        $lightLines = [];
        $channel->start(ChannelContext::for(
            'candy-msteams',
            'msteams',
            $this->tempDir . '/root',
            new StreamSelectLoop(),
            static function (string $line) use (&$lightLines): void {
                $lightLines[] = $line;
            },
        ));
        self::assertSame(['default'], $channel->faceNames(), 'the store triple built exactly one face');

        $registry = new ChannelRegistry();
        $why = null;
        self::assertTrue($registry->register('candy-msteams', $channel, $why), 'the registry takes the bare name: ' . (string) $why);
        $router = $registry->httpRouter();

        // Request door: signed activity in, canned Response out — the router
        // closure hands the WHOLE request to the channel (P1 law), so this
        // is the mount, not a shortcut around it.
        $post = function (array $activity, string $jwt) use ($router): \Psr\Http\Message\ResponseInterface {
            $request = new ServerRequest(
                'POST',
                '/channels/msteams/api/messages',
                ['Authorization' => 'Bearer ' . $jwt],
                (string) json_encode($activity),
            );
            $response = $router($request);
            self::assertNotNull($response, 'the mount owns this path');

            return $response;
        };
        $token = function (string $aud) use ($privatePem): string {
            $encode = static fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
            $head = $encode((string) json_encode(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-weld']));
            $claims = $encode((string) json_encode([
                'aud' => $aud, 'iss' => 'https://api.botframework.com', 'sub' => 'sub-1',
                'nbf' => time() - 5, 'exp' => time() + 600,
            ]));
            $signature = '';
            self::assertNotFalse(openssl_sign($head . '.' . $claims, $signature, $privatePem, OPENSSL_ALGO_SHA256));

            return $head . '.' . $claims . '.' . $encode($signature);
        };
        $dm = static function (string $id, string $userId, string $text, string $convId) use (&$serviceUrl): array {
            return [
                'type' => 'message',
                'id' => $id,
                'serviceUrl' => $serviceUrl,
                'from' => ['id' => $userId, 'aadObjectId' => $userId, 'name' => 'Welder'],
                'conversation' => ['id' => $convId, 'conversationType' => 'personal'],
                'text' => $text,
                'entities' => [],
            ];
        };

        // (a) a garbage bearer is refused BEFORE any roster or token dial.
        $garbage = $post($dm('a-act-0', 'u-weld', 'sneak', '19:dm-conv@thread.v2'), 'junk.junk.junk');
        self::assertSame(401, $garbage->getStatusCode());
        self::runFor($loop, 60);
        self::assertSame([], $api->calls, 'an unverifiable token sends nothing anywhere');

        // (b) a token minted for ANOTHER bot verifies its signature and
        // still fails the audience door — the aud check is the wall.
        $foreign = $post($dm('a-act-0b', 'u-weld', 'sneak', '19:dm-conv@thread.v2'), $token('some-other-bot'));
        self::assertSame(401, $foreign->getStatusCode());

        // (c) the pairing ladder, end to end through the real store.
        self::assertSame(200, $post($dm('a-act-1', 'u-weld', 'hi there', '19:dm-conv@thread.v2'), $token('app-weld'))->getStatusCode());
        self::runFor($loop, 700);
        self::assertStringContainsString(
            'pairing_requested',
            implode("\n", $lightLines),
            'the gate logged its verdict: ' . implode(' | ', $lightLines),
        );
        $notice = $api->lastCallFor('/activities', 'POST');
        self::assertNotNull($notice, 'the unpaired DM answered with its code notice, not the model');
        self::assertStringContainsString('Pairing required', (string) $notice['body']);
        self::assertSame(1, preg_match('/Your code: `([A-Z2-9]{8})`/', $notice['body'], $m), 'the notice carries the 8-glyph code');
        $code = $m[1];
        self::assertArrayHasKey($code, (new CredentialStore(CredentialStore::defaultPath()))->read()['platforms']['msteams']['accounts']['default']['pairingPending'] ?? [], 'the pending row landed in the store');

        // (d) the approver answers in-chat; the store gains the peer.
        self::assertSame(200, $post($dm('a-act-2', 'u-approver', '/pair approve ' . $code, '19:dm-approver@thread.v2'), $token('app-weld'))->getStatusCode());
        self::runFor($loop, 700);
        self::assertStringContainsString('Paired u-weld.', (string) $api->lastCallFor('/activities', 'POST')['body'], 'the approve answer rode the same raw door');
        self::assertContains('u-weld', (new CredentialStore(CredentialStore::defaultPath()))->msteamsAllowUsers('default'), 'approval appended the allowlist atomically');

        // (e) the paired prompt meets the PLACEHOLDER serve — the refusal
        // is disclosed in-chat, which proves the pipeline reached the
        // router with store, sidecar, and redaction door all live.
        self::assertSame(200, $post($dm('a-act-3', 'u-weld', 'hello', '19:dm-conv@thread.v2'), $token('app-weld'))->getStatusCode());
        self::runFor($loop, 900);
        $failure = $api->lastCallFor('/activities', 'POST');
        self::assertNotNull($failure, 'the undialled placeholder serve refuses loudly into the channel');
        self::assertStringContainsString('Could not open a session', (string) $failure['body']);
        self::assertStringContainsString('not connected', (string) $failure['body']);

        // (f) an invoke fast-acks with the envelope even when the ask is
        // unknown to the placeholder router.
        $invoked = $post([
            'type' => 'invoke',
            'id' => 'a-act-4',
            'serviceUrl' => $serviceUrl,
            'name' => 'adaptiveCard.action',
            'from' => ['id' => 'u-approver', 'aadObjectId' => 'u-approver', 'name' => 'Approver'],
            'conversation' => ['id' => '19:dm-approver@thread.v2', 'conversationType' => 'personal'],
            'value' => ['candyApproval' => 'ask-9', 'verdict' => 'once'],
        ], $token('app-weld'));
        self::assertSame(200, $invoked->getStatusCode());
        self::assertStringContainsString('"status":200', (string) $invoked->getBody(), 'invoke rides the ActivityEnvelope answer');

        // Dispose closes the door; the state dir (durable bindings) outlives
        // the dial — the §8.6 posture in one line each.
        $channel->dispose();
        $afterDispose = $post($dm('a-act-5', 'u-weld', 'still there?', '19:dm-conv@thread.v2'), $token('app-weld'));
        self::assertSame(503, $afterDispose->getStatusCode());
        self::assertStringContainsString('channel_not_started', (string) $afterDispose->getBody());
        self::assertDirectoryExists($configDir . '/msteams');

        $api->stop();
    }

    private function installBundle(): string
    {
        $source = dirname(__DIR__, 3) . '/candy-msteams';
        self::assertDirectoryExists($source, 'the consumer window tests the bundle this repo ships');

        $target = $this->tempDir . '/bundles/candy-msteams';
        $this->copyDirectory($source, $target);

        return $target;
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0755, true);
        chmod($target, 0o755); // mkdir is umask-filtered, but stay explicit under the private-bytes gate
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '.phpunit.cache') {
                continue;
            }
            $path = $source . '/' . $entry;
            if (is_dir($path)) {
                $this->copyDirectory($path, $target . '/' . $entry);
                continue;
            }
            copy($path, $target . '/' . $entry);
            chmod($target . '/' . $entry, 0o644); // committed bundle bytes are world-readable, never world-WRITABLE
        }
    }

    private function store(): ChannelsStore
    {
        $configDir = $this->tempDir . '/cfg';
        mkdir($configDir, 0700, true);
        return ChannelsStore::create(static fn (): string => $configDir);
    }

    private static function runFor(LoopInterface $loop, int $ms): void
    {
        $timer = $loop->addTimer($ms / 1000.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);
    }
}
