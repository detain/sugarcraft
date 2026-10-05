<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\SessionSettings;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\EchoProvider;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap N-P4b remainder, driven through Chat: a `compaction.*` save
 * rebuilds the session's CompactorConfig ({@see Chat::applySettings()}), so
 * the keys apply to the next prompt; and the three settings that replaced
 * hard-coded behaviour — `compaction.idleOfferSeconds`, `compaction.mode`
 * and `compaction.refillLimit` — are read where that behaviour lives.
 */
final class CompactionLiveSettingsTest extends TestCase
{
    use HomeSandboxTrait;

    private const ONE_M_WINDOW = 1_000_000;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-compaction-live-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.sugar-crush', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
    }

    protected function tearDown(): void
    {
        SessionSettings::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        self::removeTree($this->home);

        parent::tearDown();
    }

    // ── the live apply ──────────────────────────────────────────────────

    public function testACompactionSaveRebuildsTheSessionsConfigAtOnce(): void
    {
        $this->writeConfig([
            'compaction.reminderPercent' => 50,
            'compaction.autoPercent' => 60,
            'compaction.mode' => 'heuristic',
        ]);
        $chat = new Chat(backend: new EchoBackend());
        self::assertSame(85, self::config($chat)->backgroundCompactionThreshold);

        [$after] = $chat->applySettings(['compaction.reminderPercent', 'compaction.autoPercent', 'compaction.mode'], '/x/config.json');

        self::assertSame([50, 60], [self::config($after)->reminderThreshold, self::config($after)->backgroundCompactionThreshold]);
        self::assertSame(CompactorConfig::MODE_HEURISTIC, self::config($after)->mode);
        self::assertStringContainsString('3 apply now', self::toastText($after));

        $this->writeConfig([]);
        [$reset] = $after->applySettings(['compaction.autoPercent', 'compaction.mode']);
        self::assertEquals(CompactorConfig::new(), self::config($reset), 'a reset is the defaults again');
    }

    public function testARunningTurnDoesNotHoldTheRebuildBack(): void
    {
        $this->writeConfig(['compaction.keepRecent' => 4]);
        [$after] = (new Chat(inFlight: true, backend: new EchoBackend()))->applySettings(['compaction.keepRecent'], 'cfg');

        self::assertSame(4, self::config($after)->recentPreserveCount, 'Chat state only: the running turn keeps the config it was dispatched with');
        self::assertSame([], $after->pendingSettingsApply());
    }

    public function testTheContextPruningRemindersRebuildItTooAndAreReportedNextTurn(): void
    {
        $this->writeConfig(['contextPruning.nudgeFrequency' => 2]);
        [$after] = (new Chat(backend: new EchoBackend()))->applySettings(['contextPruning.nudgeFrequency'], 'cfg');

        self::assertSame(2, self::config($after)->nudgeFrequency);
        self::assertStringContainsString('1 next turn', self::toastText($after));
    }

    public function testASessionTierValueIsReadToo(): void
    {
        SessionSettings::apply(['compaction.refillLimit' => 7]);
        [$after] = (new Chat(backend: new EchoBackend()))->applySettings(['compaction.refillLimit'], 'this session');

        self::assertSame(7, self::config($after)->refillLimit);
    }

    public function testThePerModelCapsStillFollowTheBackendAfterARebuild(): void
    {
        $this->writeConfig(['compaction.modelTokenCaps' => ['echo' => ['autoTokens' => 12_345]]]);
        [$after] = (new Chat(backend: new EngineBackend(new EchoProvider(), 'echo')))->applySettings(['compaction.modelTokenCaps'], 'cfg');

        self::assertSame(12_345, self::config($after)->backgroundCompactionTokens);
    }

    // ── compaction.idleOfferSeconds ─────────────────────────────────────

    public function testTheIdleOfferWaitsForTheConfiguredSecondsAndZeroNeverOffers(): void
    {
        $twoHoursAgo = new \DateTimeImmutable('-2 hours');
        $over = 2_000;
        $chat = new Chat(backend: self::backend(1_000));

        self::assertTrue($chat->shouldPromptIdleCompaction($over, $twoHoursAgo), 'the default hour has passed');

        $later = new Chat(backend: self::backend(1_000), compactorConfig: CompactorConfig::new()->withIdleOfferSeconds(3 * 3600));
        self::assertFalse($later->shouldPromptIdleCompaction($over, $twoHoursAgo), 'three hours have not');

        $never = new Chat(backend: self::backend(1_000), compactorConfig: CompactorConfig::new()->withIdleOfferSeconds(0));
        self::assertFalse($never->shouldPromptIdleCompaction($over, new \DateTimeImmutable('-30 days')));
    }

    // ── compaction.refillLimit ──────────────────────────────────────────

    public function testTheBreakerTripsAtTheConfiguredLimit(): void
    {
        $main = self::backend(self::ONE_M_WINDOW);
        $atDefault = new Chat(
            history: self::condensable(),
            inputBuf: 'go on',
            backend: $main,
            consecutiveRefillCompactions: IdleCompactionPolicy::REFILL_LIMIT,
        );
        [$refused] = $atDefault->update(new KeyMsg(KeyType::Enter, ''));
        self::assertSame(1, self::saying($refused->history, 'Context compaction has run'), 'the default limit is reached');

        $raised = new Chat(
            history: self::condensable(),
            inputBuf: 'go on',
            backend: $main,
            consecutiveRefillCompactions: IdleCompactionPolicy::REFILL_LIMIT,
            compactorConfig: CompactorConfig::new()->withRefillLimit(IdleCompactionPolicy::REFILL_LIMIT + 2),
        );
        [$sent, $cmd] = $raised->update(new KeyMsg(KeyType::Enter, ''));
        self::assertSame(0, self::saying($sent->history, 'Context compaction has run'), 'a raised limit is not reached yet');
        self::assertNotNull($cmd);
    }

    // ── compaction.mode ─────────────────────────────────────────────────

    public function testHeuristicModeCompactsWithoutAskingTheSummaryModel(): void
    {
        $main = self::backend(self::ONE_M_WINDOW);
        $summarizer = self::summarizer();
        $chat = new Chat(
            history: self::condensable(),
            inputBuf: 'what changed?',
            backend: $main,
            summaryBackend: $summarizer,
            compactorConfig: CompactorConfig::new()->withMode(CompactorConfig::MODE_HEURISTIC),
        );

        [$after, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertNotNull($cmd);
        self::resolve($cmd);

        self::assertSame(1, $main->calls, 'the prompt is not parked behind a summary');
        self::assertSame(0, $summarizer->calls, 'no summarisation call is made');
        self::assertGreaterThanOrEqual(1, self::saying($after->history, 'Context compacted'), 'the older exchanges were condensed, heuristically');
    }

    public function testLlmModeStillParksThePromptBehindTheSummaryModel(): void
    {
        $main = self::backend(self::ONE_M_WINDOW);
        $summarizer = self::summarizer();
        $chat = new Chat(
            history: self::condensable(),
            inputBuf: 'what changed?',
            backend: $main,
            summaryBackend: $summarizer,
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertNotNull($cmd);
        self::resolve($cmd);

        self::assertSame(0, $main->calls);
        self::assertSame(1, $summarizer->calls);
    }

    public function testOffModeLeavesTheAutomaticTierAloneAndRequestsNothingAhead(): void
    {
        $main = self::backend(self::ONE_M_WINDOW);
        $summarizer = self::summarizer();
        $history = self::condensable();
        $chat = new Chat(
            history: $history,
            inputBuf: 'what changed?',
            backend: $main,
            summaryBackend: $summarizer,
            compactorConfig: CompactorConfig::new()->withMode(CompactorConfig::MODE_OFF),
        );

        [$after, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertNotNull($cmd);
        self::resolve($cmd);

        self::assertSame(1, $main->calls, 'the prompt goes straight out');
        self::assertSame(0, $summarizer->calls, 'neither a parked nor an ahead-of-need summary');
        self::assertSame($history, \array_slice($after->history, 0, \count($history)), 'nothing was rewritten');
        self::assertSame(0, self::saying($after->history, 'Context compacted'));
    }

    public function testOffModeStillRefusesAPromptTheWindowCannotTake(): void
    {
        $main = self::backend(100_000);
        $chat = new Chat(
            history: self::condensable(),
            inputBuf: 'what changed?',
            backend: $main,
            summaryBackend: self::summarizer(),
            compactorConfig: CompactorConfig::new()->withMode(CompactorConfig::MODE_OFF),
        );

        [$after, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNull($cmd, 'nothing is sent');
        self::assertSame(0, $main->calls);
        self::assertFalse($after->inFlight);
        self::assertSame(1, self::saying($after->history, '/compact'), 'the refusal points at /compact');
    }

    public function testCompactAsksTheSummaryModelUnlessTheModeIsHeuristic(): void
    {
        foreach ([CompactorConfig::MODE_LLM => 1, CompactorConfig::MODE_OFF => 1, CompactorConfig::MODE_HEURISTIC => 0] as $mode => $asked) {
            $summarizer = self::summarizer();
            $chat = new Chat(
                history: self::condensable(),
                inputBuf: '/compact',
                backend: self::backend(self::ONE_M_WINDOW),
                summaryBackend: $summarizer,
                compactorConfig: CompactorConfig::new()->withMode($mode),
            );

            [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
            if ($cmd !== null) {
                self::resolve($cmd);
            }

            self::assertSame($asked, $summarizer->calls, "/compact under {$mode}");
        }
    }

    // =====================================================================

    private static function config(Chat $chat): CompactorConfig
    {
        $compactor = (new \ReflectionProperty(Chat::class, 'compactor'))->getValue($chat);

        return $compactor->config();
    }

    /** @param array<string, mixed> $data */
    private function writeConfig(array $data): void
    {
        file_put_contents($this->home . '/.sugar-crush/config.json', json_encode($data === [] ? new \stdClass() : $data));
    }

    private static function toastText(?Chat $chat): string
    {
        $toast = $chat?->settingsToast();
        self::assertNotNull($toast, 'a save always reports itself');

        return trim((string) preg_replace('/[│╭╮╰╯─✔ℹ\s]+/u', ' ', Ansi::strip($toast->view('', 400, 0))));
    }

    /** @return list<Message> eight older ~20k exchanges, then ten trivial ones: ~160k, condensable */
    private static function condensable(): array
    {
        $history = [];
        for ($i = 0; $i < 8; $i++) {
            $history[] = Message::user(str_repeat(\chr(97 + $i), 40_000));
            $history[] = Message::assistant(str_repeat(\chr(107 + $i), 40_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    private static function backend(int $window): Backend&ReportsContextWindow
    {
        return new class ($window) implements Backend, ReportsContextWindow {
            public int $calls = 0;

            public function __construct(private readonly int $window)
            {
            }

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant('ok');
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    private static function summarizer(): Backend
    {
        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = "{$i}.\nasked: condensed exchange {$i}";
        }
        $reply = implode("\n", $records);

        return new class ($reply) implements Backend {
            public int $calls = 0;

            public function __construct(private readonly string $reply)
            {
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant($this->reply);
            }

            public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
            {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    private static function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        if ($asyncCmd instanceof \SugarCraft\Core\BatchMsg) {
            $last = null;
            foreach ($asyncCmd->cmds as $inner) {
                if ($inner instanceof \Closure) {
                    $last = self::resolve($inner) ?? $last;
                }
            }

            return $last;
        }
        if (!$asyncCmd instanceof AsyncCmd) {
            return $asyncCmd;
        }
        $resolved = null;
        $asyncCmd->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /** @param list<Message> $history */
    private static function saying(array $history, string $says): int
    {
        return \count(array_filter($history, static fn (Message $m): bool => str_contains((string) $m->content, $says)));
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) && !is_link($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
