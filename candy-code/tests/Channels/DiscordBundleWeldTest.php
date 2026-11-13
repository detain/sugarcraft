<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Channels;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Bundles\Bundle;
use SugarCraft\Code\Channels\BundleChannelLoader;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Channels\ChannelInterface;
use SugarCraft\Code\Channels\ChannelsStore;
use SugarCraft\Code\Tests\Skills\TemporaryDirectoryTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Consumer window for the `@candy/discord` bundle (code-chat P2).
 *
 * The repo's real bundle directory is copied into a temporary install root
 * and driven through the shipped P1 trust door exactly as a marketplace
 * install would be: first sight quarantines, second launch loads, and the
 * loaded class satisfies the core contract. No network is dialled and no
 * credential is read — the default policy is dark, and the pin that matters
 * most here is that a dark channel touches NOTHING: no files, no timers.
 *
 * @see \SugarCraft\Code\Channels\BundleChannelLoader
 */
final class DiscordBundleWeldTest extends TestCase
{
    use TemporaryDirectoryTrait;
    use HomeSandboxTrait;

    private string $tempDir = '';

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/candy-code-discord-weld-' . uniqid((string) getmypid(), true);
        mkdir($this->tempDir, 0777, true);
        // Hermeticity, per the house sandbox law: every HOME-rooted reader
        // (trustedConfigDirPath, the raw user config behind
        // channelsDiscordSettings, the CredentialStore default path) must
        // resolve into the temp tree, so a developer whose real ~/.candy-code
        // happens to enable channels.discord cannot turn this window red or
        // have it write discord state into their home. The trait snapshots
        // the real HOME on first call, before touching it — the lane-cf
        // ordering law lives inside the trait now.
        $this->useHomeSandbox($this->tempDir . '/home');
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        $this->removeDirectory($this->tempDir);
    }

    public function testTheRepoBundleParsesAndNamesItself(): void
    {
        $bundle = Bundle::fromDir($this->installBundle(), 'user');
        self::assertNotNull($bundle, 'the repo bundle carries a readable manifest');
        self::assertSame('candy-discord', $bundle->name);
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
     * One test carries the whole load walk: the entrypoint is a REAL named
     * class (not a synthesized fixture), and a class name declared by
     * require_once lives for the whole PHP process — a second install of the
     * same bundle in a second test would fatal on re-declaration. Same
     * discipline as the fixture-class serial in BundleChannelLoaderTest,
     * arrived at from the other side.
     */
    public function testTheTrustDoorLoadsTheRealBundleOnSecondLaunchAndTheDarkChannelTouchesNothing(): void
    {
        $bundle = Bundle::fromDir($this->installBundle(), 'user');
        self::assertNotNull($bundle);
        $store = $this->store();

        $first = BundleChannelLoader::load([$bundle], ['candy-discord'], $store);
        self::assertSame([], $first['loaded'], 'launch one never runs entrypoint bytes it has not seen');
        self::assertCount(1, $first['quarantined']);
        self::assertSame('candy-discord', $first['quarantined'][0]['bundle']);
        self::assertSame('channel', $first['quarantined'][0]['kind']);

        $second = BundleChannelLoader::load([$bundle], ['candy-discord'], $store);
        self::assertSame([], $second['quarantined'], 'the ledger remembered the first sight');
        self::assertCount(1, $second['loaded'], 'the shipped bytes pass the private-bytes gate as installed');
        self::assertSame('channel', $second['loaded'][0]['kind']);

        $entryClass = $second['loaded'][0]['class'];
        self::assertTrue(class_exists($entryClass), 'the loaded entrypoint class is live in this process');
        $instance = new $entryClass();
        self::assertInstanceOf(ChannelInterface::class, $instance);
        self::assertSame('discord', $instance->name(), 'the channel claims the bare name the registry routes on');

        $lines = [];
        $context = ChannelContext::for(
            'candy-discord',
            'discord',
            $this->tempDir . '/root',
            new StreamSelectLoop(),
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        );

        $channel = $instance;
        $channel->start($context);
        self::assertSame(
            ['channels.discord: disabled posture, nothing started'],
            $lines,
            'with no user-tier policy the channel announces darkness and arms nothing',
        );
        self::assertSame([], $channel->faceNames(), 'no account face was built from a dark tree');
        self::assertDirectoryDoesNotExist(
            \SugarCraft\Code\Cli\Bootstrap::trustedConfigDirPath() . '/discord',
            'a dark channel must not create state directories under the (sandboxed) home',
        );
        $channel->dispose();
        $channel->dispose(); // idempotent by contract — a double dispose must not throw
        self::assertSame([], $channel->faceNames());
    }

    private function installBundle(): string
    {
        $source = dirname(__DIR__, 3) . '/candy-discord';
        self::assertDirectoryExists($source, 'the consumer window tests the bundle this repo ships');

        $target = $this->tempDir . '/bundles/candy-discord';
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
        return ChannelsStore::new(static fn (): string => $configDir);
    }
}
