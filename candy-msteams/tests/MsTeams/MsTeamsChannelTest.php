<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\MsTeams;

use CandyCode\Bundles\MsTeams\MsTeamsChannel;
use CandyCode\Bundles\MsTeams\Support\CredentialStore;
use GuzzleHttp\Psr7\ServerRequest;
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
 * bundle state. The signed-activity legs need the JWK fixture dance and
 * ride the weld test instead; what this file owns is everything that must
 * refuse BEFORE any crypto or platform byte exists.
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
