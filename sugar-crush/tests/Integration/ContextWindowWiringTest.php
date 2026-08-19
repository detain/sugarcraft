<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\ContextWindow;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Runtime;

/**
 * crush_code.md Phase 5 item 4: every context threshold used to be a
 * percentage of a hardcoded 100,000, written three times in code and quoted
 * four more times in prose, while all seven providers reported a real
 * `contextWindow()` that nothing read. These tests drive the wiring end to
 * end — provider → EngineBackend → Chat's tiers — and pin the fallback that
 * has to hold when a backend cannot answer.
 */
final class ContextWindowWiringTest extends TestCase
{
    /**
     * ~280,000 chars ≈ 70,010 estimated tokens: over the 70% reminder tier of
     * the 100,000-token fallback (70,000) and under the 70% tier of any
     * realistic real window. So the same history reads as "reminder due" or
     * "plenty of room" depending purely on whether the real window is read,
     * which is what makes it the probe for this item.
     */
    private const REMINDER_TIER_CHARS = 280_000;

    /**
     * The real seam: a provider behind the EngineBackend that Chat holds.
     * Deliberately not a hand-rolled Backend stub — the point of item 4 is that
     * the provider's figure travels through EngineBackend into Chat's tiers.
     */
    private static function engineBackend(int $window): EngineBackend
    {
        return new EngineBackend(new FixedWindowProvider($window), 'test-model');
    }

    public function testEngineBackendReportsItsProvidersWindowThroughTheCapability(): void
    {
        $backend = new EngineBackend(new FixedWindowProvider(196_608), 'test-model');

        $this->assertInstanceOf(ReportsContextWindow::class, $backend);
        $this->assertSame(196_608, $backend->contextWindow());
        $this->assertSame(
            196_608,
            ContextWindow::ofBackend($backend),
            'the resolver must not second-guess a positive provider window',
        );
    }

    public function testChatsTokenLimitIsTheBackendsRealWindow(): void
    {
        $chat = new Chat(backend: new EngineBackend(new FixedWindowProvider(196_608), 'test-model'));

        $this->assertSame(196_608, $chat->contextTokenLimit());
    }

    public function testChatsTokenLimitFallsBackForABackendWithNoModelBehindIt(): void
    {
        $chat = new Chat(backend: new EchoBackend());

        $this->assertSame(ContextWindow::FALLBACK_TOKENS, $chat->contextTokenLimit());
    }

    /**
     * The reminder tier, driven through the real submit() path: a history that
     * trips the 70% tier of the 100,000-token fallback must NOT trip it against
     * a 1,000,000-token window. Behaviour, not the accessor: no Role::System
     * notice rides along with the turn.
     */
    public function testTheReminderTierMovesWithTheRealWindow(): void
    {
        $history = [Message::user(str_repeat('x', self::REMINDER_TIER_CHARS))];

        $roomy = new Chat(
            history: $history,
            inputBuf: 'hello',
            backend: self::engineBackend(1_000_000),
        );
        [$next] = $roomy->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(2, $next->history, 'no reminder is due at 7% of a 1M-token window');
        $this->assertSame(Role::User, $next->history[1]->role);

        // Same history, same estimated size, a window it nearly fills.
        $cramped = new Chat(
            history: $history,
            inputBuf: 'hello',
            backend: self::engineBackend(100_000),
        );
        [$crampedNext] = $cramped->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(3, $crampedNext->history);
        $this->assertSame(Role::System, $crampedNext->history[2]->role);
    }

    /**
     * The failure mode to guard hardest. A backend that reports 0 must NOT put
     * a 0 into the compactor's predicates: all three early-return false on
     * `$tokenLimit <= 0`, so the whole feature would switch off rather than on.
     * Driven behaviourally — the reminder still fires — so this cannot pass on
     * an accessor that returns the right number while the tiers use a
     * different one.
     */
    public function testAZeroWindowFallsBackInsteadOfSilentlyDisablingEveryTier(): void
    {
        $chat = new Chat(
            history: [Message::user(str_repeat('x', self::REMINDER_TIER_CHARS))],
            inputBuf: 'hello',
            backend: self::engineBackend(0),
        );

        $this->assertSame(ContextWindow::FALLBACK_TOKENS, $chat->contextTokenLimit());

        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(3, $next->history, 'the reminder tier must still be live');
        $this->assertSame(Role::System, $next->history[2]->role);
    }

    /**
     * A small real window must not trip anything at session start. An 8,192-token
     * provider puts the reminder tier at 5,734 and the background tier at 6,963;
     * an empty history is 0 estimated tokens and a one-line prompt is nowhere
     * near either, so the turn goes out clean.
     */
    public function testATinyWindowTripsNothingOnAnEmptyHistory(): void
    {
        $chat = new Chat(inputBuf: 'hello', backend: self::engineBackend(8_192));

        $this->assertSame(8_192, $chat->contextTokenLimit());
        $this->assertSame(0.0, $chat->contextUsagePercent());

        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertInstanceOf(\Closure::class, $cmd);
        $this->assertTrue($next->inFlight);
        $this->assertCount(1, $next->history);
        $this->assertSame(Role::User, $next->history[0]->role);
    }

    /**
     * The three tiers must stay in ascending order for a real window, or the
     * blocking tier would fire before compaction had a chance. Read off the
     * config rather than restated, so a config change cannot leave this test
     * asserting yesterday's ordering.
     */
    public function testTheTiersStayOrderedForASmallRealWindow(): void
    {
        $config = CompactorConfig::new();
        $window = 8_192;

        $reminder = (int) ($window * $config->reminderThreshold / 100);
        $background = (int) ($window * $config->backgroundCompactionThreshold / 100);
        $foreground = (int) ($window * $config->foregroundBlockingThreshold / 100);

        $this->assertGreaterThan(0, $reminder);
        $this->assertGreaterThan($reminder, $background);
        $this->assertGreaterThan($background, $foreground);
        $this->assertLessThan($window, $foreground);
    }

    /**
     * Chat's idle-compaction threshold is the real window too, not the 100,000
     * it hardcoded independently of the reminder tier sitting beside it.
     * 12,511 estimated tokens is far under that old number and far over an
     * 8,192-token window.
     */
    public function testChatsIdleThresholdMovesWithTheRealWindow(): void
    {
        $history = [Message::user(str_repeat('x', 50_000))];
        $idle = new DateTimeImmutable('2 hours ago');

        $small = (new Chat(history: $history, inputBuf: 'hello', backend: self::engineBackend(8_192)))
            ->withLastActivity($idle);
        $this->assertTrue($small->shouldPromptIdleCompaction($small->contextTokens(), $idle));

        $large = (new Chat(history: $history, inputBuf: 'hello', backend: self::engineBackend(196_608)))
            ->withLastActivity($idle);
        $this->assertFalse($large->shouldPromptIdleCompaction($large->contextTokens(), $idle));
    }

    /**
     * Runtime's copy of the same predicate reads ITS provider's window. Both
     * copies now delegate to IdleCompactionPolicy, so this pins that Runtime
     * supplies a real limit rather than the hardcoded one it used to carry.
     */
    public function testRuntimesIdleThresholdMovesWithItsProvidersWindow(): void
    {
        $idleApp = static fn(ProviderInterface $p): App => App::new($p, 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));
        $hooks = new HookManager(new HookRegistry());

        $small = new Runtime(new FixedWindowProvider(8_192), $hooks);
        $this->assertTrue($small->shouldPromptIdleCompaction($idleApp(new FixedWindowProvider(8_192)), 10_000));

        $large = new Runtime(new FixedWindowProvider(196_608), $hooks);
        $this->assertFalse($large->shouldPromptIdleCompaction($idleApp(new FixedWindowProvider(196_608)), 10_000));
    }

    public function testRuntimeFallsBackWhenItsProviderReportsNoWindow(): void
    {
        $runtime = new Runtime(new FixedWindowProvider(0), new HookManager(new HookRegistry()));
        $app = App::new(new FixedWindowProvider(0), 'test-model')
            ->withLastActivity(new DateTimeImmutable('2 hours ago'));

        // Past the fallback, so it fires; a pass-through 0 would disable it.
        $this->assertTrue($runtime->shouldPromptIdleCompaction($app, ContextWindow::FALLBACK_TOKENS + 1));
        $this->assertFalse($runtime->shouldPromptIdleCompaction($app, ContextWindow::FALLBACK_TOKENS));
    }

    /**
     * Custom thresholds have to survive a mutate() or wiring the tiers would
     * have wired them to the defaults. The Chat is mutated BEFORE the turn is
     * submitted, which is the whole point: mutate() used to pass `null` in the
     * config's place, so the first keystroke reverted a 1% reminder threshold
     * to 70%.
     */
    public function testCustomCompactorThresholdsSurviveAMutate(): void
    {
        $chat = new Chat(
            // 1,010 estimated tokens: past 1% of the 100,000-token fallback
            // (1,000), nowhere near the default 70% tier.
            history: [Message::user(str_repeat('x', 4_000))],
            inputBuf: 'hello',
            compactorConfig: CompactorConfig::new()->withReminderThreshold(1),
        );

        [$next] = $chat->withStreaming(false)->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertCount(3, $next->history);
        $this->assertSame(Role::System, $next->history[2]->role);

        // And the notice may not quote a threshold that disagrees with the one
        // that actually fired it. Not a keyword check: any percentage the message
        // names must be the CONFIGURED one, so both a hardcoded "70%" and a
        // correctly-derived "1%" are distinguishable, and today's wording (which
        // names no percentage at all) passes because it cannot be wrong.
        //
        // The pattern matches a decimal tail and the spelled-out unit as well as
        // the sign, and compares NUMERICALLY. Measured, the narrower
        // `/(\d+)%/` this replaces had two holes on top of matching nothing
        // today: "1.0%" captured the trailing "0" and would have compared equal
        // to a configured threshold of 0, and "70 percent" matched no pattern at
        // all and passed unread.
        preg_match_all('/(\d+(?:\.\d+)?)\s*(?:%|percent\b)/i', $next->history[2]->content, $found);
        foreach ($found[1] as $quoted) {
            $this->assertEquals(
                1,
                (float) $quoted,
                'the reminder quoted a threshold percentage other than the configured reminderThreshold',
            );
        }
    }

    /**
     * The USER-VISIBLE percentage, which is the other half of item 4's
     * observable effect and the half nothing noticed.
     *
     * Measured: reverting {@see Chat::contextUsagePercent()} to divide by
     * {@see ContextWindow::FALLBACK_TOKENS} left the entire suite
     * byte-identical - 6,918 tests, 70,996 assertions, exit 0 - because
     * `contextTokenLimit()` was pinned and the fraction built on it was not.
     * The one existing usage-percent assertion against a real window was
     * `assertSame(0.0, ...)` on an empty history, which is 0.0 under either
     * denominator.
     *
     * 22,000 estimated tokens against an 88,000-token window is 25%; against
     * the 100,000-token fallback it is 22%. Both the fraction and the string
     * the status bar actually prints are pinned, because the bar is where the
     * number is read.
     */
    public function testTheUsagePercentageDividesByTheRealWindowNotTheFallback(): void
    {
        $history = [];
        for ($i = 0; $i < 200; $i++) {
            $history[] = Message::user(str_repeat('x', 400));
        }
        $chat = new Chat(history: $history, backend: self::engineBackend(88_000));

        $this->assertSame(22_000, $chat->contextTokens(), 'ESTIMATED tokens (chars/4 + 10 per message)');
        $this->assertSame(88_000, $chat->contextTokenLimit(), 'PROVIDER-COUNTED window');
        $this->assertSame(22_000 / 88_000, $chat->contextUsagePercent());
        $this->assertNotSame(
            22_000 / ContextWindow::FALLBACK_TOKENS,
            $chat->contextUsagePercent(),
            'the readout must not still divide by the retired fixed budget',
        );

        [$sized] = $chat->update(new WindowSizeMsg(120, 40));
        $bar = self::statusBar(Renderer::render($sized));

        $this->assertStringContainsString('~22K / 88K context (25%)', $bar);
        $this->assertStringNotContainsString('22%', $bar, 'the fallback denominator would print 22%');
    }

    /**
     * The 85% notice's two token figures are different KINDS of number - a
     * chars/4 estimate and a provider-advertised window - and its docblock
     * claims "every figure names the domain it is true of". This pins the
     * association between each label and its figure, not the sentence: each
     * number is read out BY the label next to it and compared against the
     * figure measured independently, so swapping the two reds even though
     * every word survives.
     */
    public function testTheCompactionNoticePairsEachFigureWithItsOwnUnit(): void
    {
        $chat = new Chat(
            history: self::compactablePairs(),
            inputBuf: 'hello',
            backend: self::engineBackend(88_000),
        );
        [$next] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $noticeIndex = count($next->history) - 2; // the notice sits before the user turn
        $notice = $next->history[$noticeIndex];
        $this->assertSame(Role::System, $notice->role);

        // The estimate is of the history the notice was emitted ABOUT: what
        // survived compaction, before this turn's own two messages.
        $survived = array_slice($next->history, 0, $noticeIndex);
        $estimate = (new Chat(history: $survived))->contextTokens();

        $this->assertSame($estimate, self::figureLabelled($notice->content, '/~(\d+) estimated tokens/'));
        $this->assertSame(
            $chat->contextTokenLimit(),
            self::figureLabelled($notice->content, '/(\d+)-token context window/'),
        );
        $this->assertNotSame(
            $estimate,
            $chat->contextTokenLimit(),
            'fixture: the two figures must differ or a swap would be invisible',
        );
    }

    /**
     * Same pin on the 95% refusal, whose figures are the same two units.
     */
    public function testTheBlockingRefusalPairsEachFigureWithItsOwnUnit(): void
    {
        $chat = new Chat(
            history: self::unshrinkablePairs(),
            inputBuf: 'hello',
            backend: self::engineBackend(88_000),
        );
        [$next, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'fixture: this must reach the blocking tier');
        $refusal = $next->history[count($next->history) - 1];
        $this->assertSame(Role::Assistant, $refusal->role);

        // Everything before the notice, the user turn and the refusal itself.
        $survived = array_slice($next->history, 0, count($next->history) - 3);
        $estimate = (new Chat(history: $survived))->contextTokens();

        $this->assertSame($estimate, self::figureLabelled($refusal->content, '/~(\d+) estimated tokens/'));
        $this->assertSame(
            $chat->contextTokenLimit(),
            self::figureLabelled($refusal->content, '/(\d+)-token context window/'),
        );
        $this->assertNotSame($estimate, $chat->contextTokenLimit(), 'fixture: the two figures must differ');
    }

    /**
     * Every slash command the refusal names, DRIVEN - the escape hatches are
     * the whole reason the refusal is not a wedge, so "it says /clear" is not
     * the claim worth pinning; "what it says gets you out" is.
     *
     * Each command named anywhere in the message is submitted on the refused
     * chat and the next turn re-attempted; the set that actually dispatches is
     * asserted exactly, sorted so it does not couple to word order. That makes
     * three separate regressions visible: dropping a command (the set shrinks),
     * renaming one to something unrouted (same), and advertising one that frees
     * nothing. `/fork` was advertised here in an earlier draft and is measurably
     * NOT an escape - it spawns a background session and leaves this history in
     * place - so re-adding it reds this rather than passing a keyword check.
     */
    public function testEveryEscapeTheBlockingRefusalNamesActuallyGetsOutOfIt(): void
    {
        $backend = self::engineBackend(88_000);
        $chat = new Chat(history: self::unshrinkablePairs(), inputBuf: 'hello', backend: $backend);
        [$refused, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($cmd, 'fixture: this must reach the blocking tier');

        $refusal = $refused->history[count($refused->history) - 1]->content;
        preg_match_all('#/[a-z]+#', $refusal, $found);
        $named = array_values(array_unique($found[0]));
        $this->assertNotEmpty($named, 'the refusal must name at least one command');

        $escapes = [];
        foreach ($named as $command) {
            $afterCommand = new Chat(
                history: $refused->history,
                inputBuf: $command,
                backend: $backend,
            );
            [$cleared] = $afterCommand->update(new KeyMsg(KeyType::Enter, ''));

            $retry = new Chat(history: $cleared->history, inputBuf: 'hello', backend: $backend);
            [, $retryCmd] = $retry->update(new KeyMsg(KeyType::Enter, ''));

            if ($retryCmd !== null) {
                $escapes[] = $command;
            }
        }

        sort($escapes);
        $this->assertSame(
            ['/clear', '/compact'],
            $escapes,
            'exactly the commands the refusal names that really unblock the next turn',
        );
    }

    /**
     * A figure read out of a message BY the label beside it. Returns -1 when
     * the label is absent, so a missing figure fails the comparison rather
     * than silently matching nothing.
     */
    private static function figureLabelled(string $text, string $pattern): int
    {
        return preg_match($pattern, $text, $m) === 1 ? (int) $m[1] : -1;
    }

    /**
     * 13 exchanges totalling 79,000 estimated tokens, compactable to a few
     * hundred: over the 85% tier of the 88,000-token window used above
     * (74,800) and well under its 95% tier (83,600) once the three
     * non-preserved exchanges are summarized.
     *
     * @return list<Message>
     */
    private static function compactablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    /**
     * 13 EQUAL exchanges of ~10,000 estimated tokens each: the ten compaction
     * preserves in full are ~100,000 estimated tokens on their own, past the
     * 88,000-token window entirely, so no amount of summarizing the other three
     * gets back under the 95% tier.
     *
     * @return list<Message>
     */
    private static function unshrinkablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 20_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 20_000));
        }

        return $history;
    }

    private static function statusBar(string $frame): string
    {
        $lines = explode("\n", $frame);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($lines));

        return (string) preg_replace('/\x{E000}\/?[A-Za-z0-9._:-]*\x{E001}/u', '', (string) $plain);
    }
}

/**
 * A provider whose only interesting answer is its context window.
 */
final class FixedWindowProvider implements ProviderInterface
{
    public function __construct(private readonly int $window) {}

    public function name(): string
    {
        return 'fixed-window';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        return new CompleteResponse(content: 'ok');
    }

    public function completeStream(CompleteRequest $request): \Generator
    {
        yield new CompleteResponse(content: 'ok');
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        throw new \LogicException('not used');
    }
}
