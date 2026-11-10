<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\DiscordChannel;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Code\Channels\ChannelContext;
use SugarCraft\Code\Cli\Bootstrap;

/**
 * The dark-channel law: a disabled (or absent) channels.discord tree must
 * start nothing and touch nothing — no accounts, no timers, no directories.
 * The transport-refusal pin proves a bad endpoint override is a loud start
 * failure (the registry's start-failed refusal), never a silently degraded
 * dial. Both legs swap HOME into a temp dir so the real user store is never
 * consulted; rawUserConfig re-reads per call, so the swap is live.
 */
final class DiscordChannelTest extends TestCase
{
    private LoopInterface $loop;
    private string $homeRoot;
    private string $previousHome;

    protected function setUp(): void
    {
        $this->loop = new StreamSelectLoop();
        $this->homeRoot = sys_get_temp_dir() . '/p2-chan-' . getmypid() . '-' . uniqid('', true);
        if (!mkdir($this->homeRoot . '/.candy-code', 0o700, true)) {
            self::fail('could not create the temporary home');
        }
        $this->previousHome = (string) (getenv('HOME') !== false ? getenv('HOME') : ($_SERVER['HOME'] ?? ''));
        putenv('HOME=' . $this->homeRoot);
        unset($_ENV['HOME'], $_SERVER['HOME']);
    }

    protected function tearDown(): void
    {
        putenv('HOME=' . $this->previousHome);
        $_SERVER['HOME'] = $this->previousHome;
        @unlink($this->homeRoot . '/.candy-code/config.json');
        @rmdir($this->homeRoot . '/.candy-code');
        @rmdir($this->homeRoot);
    }

    public function testADarkTreeStartsNothingAndTouchesNothing(): void
    {
        $lines = [];
        $channel = new DiscordChannel();
        $channel->start(ChannelContext::for(
            'candy-discord',
            'discord',
            $this->homeRoot,
            $this->loop,
            static function (string $line) use (&$lines): void {
                $lines[] = $line;
            },
        ));

        self::assertSame(['channels.discord: disabled posture, nothing started'], $lines);
        self::assertSame([], $channel->faceNames());
        self::assertDirectoryDoesNotExist(Bootstrap::trustedConfigDirPath() . '/discord');
        self::assertSame([], $channel->faceNames());

        $channel->dispose();
        $channel->dispose();
    }

    public function testABogusTransportOverrideRefusesToStartLoudly(): void
    {
        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            // The user config carries FLAT dotted keys — 'channels.discord'
            // is the schema key verbatim, exactly as the settings UI writes it.
            (string) json_encode(['channels.discord' => [
                'enabled' => true,
                'transport' => ['apiBaseUrl' => 'http://evil.example'],
            ]]),
        );

        $channel = new DiscordChannel();
        try {
            $channel->start(ChannelContext::for('candy-discord', 'discord', $this->homeRoot, $this->loop, static function (string $line): void {
            }));
            self::fail('a plain-http remote endpoint must refuse the start');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('must be https or wss', $error->getMessage());
        }

        // Dispose after a failed start is safe and still touches no state dir.
        $channel->dispose();
        self::assertDirectoryDoesNotExist(Bootstrap::trustedConfigDirPath() . '/discord');
    }
}
