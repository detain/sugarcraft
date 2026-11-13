<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Discord;

use CandyCode\Bundles\Discord\DiscordChannel;
use CandyCode\Bundles\Discord\Support\SessionMap;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;
use React\EventLoop\TimerInterface;
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

    /**
     * The dedupe ledger law (m09): claimInbound() writes a seen_inbound row
     * for every delivery, and pruneSeen() — the only reclaim — had no caller
     * on this side, so the ledger grew forever. The Teams face solves the
     * same contract with a periodic sweep (msteams §9.5); this channel now
     * arms the same 6 h timer for the same 7-day keep. The recording loop
     * captures what start() wires WITHOUT ever running the loop — no dials,
     * no real time — and the fired callback is judged against live DB rows.
     */
    public function testEnabledBootWithPdoSqliteLoadedNeverFiresTheMissingExtensionEvent(): void
    {
        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) json_encode(['channels.discord' => ['enabled' => true]]),
        );

        $lines = [];
        $channel = new DiscordChannel();
        $channel->start(ChannelContext::for('candy-discord', 'discord', $this->homeRoot, $this->loop, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        self::assertSame([], \array_values(\array_filter($lines, static fn (string $l): bool => \str_contains($l, 'missing_extension'))), 'the extension guard must never misfire while pdo_sqlite is loaded');
        self::assertNotEmpty(\array_filter($lines, static fn (string $l): bool => \str_contains($l, 'channels.discord.no_usable_accounts')), 'boot proceeds past the guard to the credential step');
        $channel->dispose();
    }

    public function testMissingPdoSqliteRefusesToStartByNamedEvent(): void
    {
        if (\extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('discord-05: the refusal arm runs only on a host without pdo_sqlite (disclosed, not faked)');
        }

        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) json_encode(['channels.discord' => ['enabled' => true]]),
        );

        $lines = [];
        $channel = new DiscordChannel();
        $channel->start(ChannelContext::for('candy-discord', 'discord', $this->homeRoot, $this->loop, static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }));

        self::assertNotEmpty(\array_filter($lines, static fn (string $l): bool => \str_contains($l, 'channels.discord.missing_extension')), 'a missing pdo_sqlite is a NAMED refusal, not a raw PDOException');
        self::assertSame([], $channel->faceNames());
        self::assertSame([], \array_filter($lines, static fn (string $l): bool => \str_contains($l, 'channels.discord.no_accounts')), 'the refusal lands before any credential step');
        $channel->dispose();
    }

    public function testTheBottedTreeArmsTheSeenLedgerSweepAndItPrunesOnlyStaleRows(): void
    {
        putenv('DISCORD_BOT_TOKEN=' . 'xoxb-' . 'pinned-for-test');
        file_put_contents(
            $this->homeRoot . '/.candy-code/config.json',
            (string) json_encode(['channels.discord' => ['enabled' => true]]),
        );

        $loop = new class implements LoopInterface {
            /** @var list<array{interval: mixed, periodic: bool, callback: callable}> */
            public array $timers = [];

            /** @var list<TimerInterface> */
            public array $cancelled = [];

            public function addReadStream($stream, $listener)
            {
            }

            public function addWriteStream($stream, $listener)
            {
            }

            public function removeReadStream($stream)
            {
            }

            public function removeWriteStream($stream)
            {
            }

            public function addTimer($interval, $callback)
            {
                return $this->arm($interval, $callback, false);
            }

            public function addPeriodicTimer($interval, $callback)
            {
                return $this->arm($interval, $callback, true);
            }

            public function cancelTimer(TimerInterface $timer)
            {
                $this->cancelled[] = $timer;
            }

            public function futureTick($listener)
            {
            }

            public function addSignal($signal, $listener)
            {
            }

            public function removeSignal($signal, $listener)
            {
            }

            public function run()
            {
            }

            public function stop()
            {
            }

            private function arm($interval, $callback, bool $periodic): TimerInterface
            {
                $this->timers[] = ['interval' => $interval, 'periodic' => $periodic, 'callback' => $callback];

                return new class($interval, $callback, $periodic) implements TimerInterface {
                    public function __construct(private mixed $interval, private mixed $callback, private bool $periodic)
                    {
                    }

                    public function getInterval()
                    {
                        return $this->interval;
                    }

                    public function getCallback()
                    {
                        return $this->callback;
                    }

                    public function isPeriodic()
                    {
                        return $this->periodic;
                    }
                };
            }

            public function armedPeriodic(float $interval): bool
            {
                return $this->count($interval) > 0;
            }

            public function firePeriodic(float $interval): int
            {
                $fired = 0;
                foreach ($this->timers as $timer) {
                    if ($timer['periodic'] && (float) $timer['interval'] === $interval) {
                        ($timer['callback'])();
                        ++$fired;
                    }
                }

                return $fired;
            }

            public function cancelledIntervals(): array
            {
                return array_map(static fn (TimerInterface $t): float => (float) $t->getInterval(), $this->cancelled);
            }

            private function count(float $interval): int
            {
                $count = 0;
                foreach ($this->timers as $timer) {
                    if ($timer['periodic'] && (float) $timer['interval'] === $interval) {
                        ++$count;
                    }
                }

                return $count;
            }
        };

        $channel = new DiscordChannel();
        $channel->start(ChannelContext::for('candy-discord', 'discord', $this->homeRoot, $loop, static function (string $line): void {
        }));

        // Sanity: the tree genuinely booted (poll timer armed, state dir live)
        // — the dark path would arm nothing, so this leg passes before the fix.
        self::assertTrue($loop->armedPeriodic(5.0), 'a bottled tree must arm the serve poll');
        $db = Bootstrap::trustedConfigDirPath() . '/discord/sessions.db';
        self::assertFileExists($db);

        // Seed the ledger: one stale row (aged past any keep) and one fresh.
        $map = new SessionMap($db);
        self::assertTrue($map->claimInbound('discord', 'msg-stale'));
        self::assertTrue($map->claimInbound('discord', 'msg-fresh'));
        $age = new \PDO('sqlite:' . $db);
        $age->exec('UPDATE seen_inbound SET seen_at = 0 WHERE inbound_id = \'msg-stale\'');

        // The accusation (m09): without a sweep caller the ledger is unbounded.
        self::assertTrue(
            $loop->armedPeriodic(21600.0),
            'the seen_inbound ledger needs the periodic pruneSeen sweep the Teams face arms (§9.5); '
            . 'claimInbound rows otherwise accumulate forever',
        );

        self::assertSame(1, $loop->firePeriodic(21600.0));
        // The stale row was deleted (re-claimable); the fresh row survived.
        self::assertTrue($map->claimInbound('discord', 'msg-stale'), 'the sweep must delete rows past the keep');
        self::assertFalse($map->claimInbound('discord', 'msg-fresh'), 'the sweep must keep rows inside the keep');

        // Dispose releases the sweep beside its siblings.
        $channel->dispose();
        self::assertContains(21600.0, $loop->cancelledIntervals(), 'dispose must cancel the prune timer');
    }
}
