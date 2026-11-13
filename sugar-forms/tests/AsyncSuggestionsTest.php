<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Forms\Tests\Support\LoopHygiene;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\FormSuggestionsReadyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;
use SugarCraft\Forms\Lang;
use SugarCraft\Forms\Tests\Support\DeterministicPump;

/**
 * Tests for async suggestions with debounce.
 *
 * These tests verify the basic behavior of withAsyncSuggestions:
 * - Method exists and returns correct type
 * - API is correctly configured
 * - The scheduling mechanism works
 *
 * SF-31 (sugar-forms-037): every wait is ORDER-based — pumpUntil observes the
 * awaited transition, advance drains a few explicit iterations to prove what
 * did not happen did not happen — never a wall-clock race. The generous
 * budgets exist only to fail loud on a missing event.
 */
final class AsyncSuggestionsTest extends TestCase
{
    use LoopHygiene {
        setUp as private hygieneSetUp;
        tearDown as private hygieneTearDown;
    }
    use DeterministicPump;

    /**
     * SF-31 named budget: wall ceiling for a guarded-fetch settle pump
     * (debounce legs arm at 10-100 ms; this is 10x the jitter budget of the
     * widest of them — a margin, never the assertion).
     */
    private const S02_SETTLE_BUDGET = 2.0;

    protected function setUp(): void
    {
        $this->hygieneSetUp();

        // Ensure a fresh loop for each test — the instance is restored (and
        // the restored one residue-checked) by SF-30's LoopHygiene tearDown.
        Loop::set(new StreamSelectLoop());
    }

    protected function tearDown(): void
    {
        $this->hygieneTearDown();
    }

    public function testWithAsyncSuggestionsSetsFetcher(): void
    {
        $seenInput = null;
        $fetcher = static function (string $input) use (&$seenInput): PromiseInterface {
            $seenInput = $input;
            return \React\Promise\resolve(['suggestion']);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher); // default 150ms debounce

        // SF-32: "sets the fetcher" used to assert only that a Cmd closure
        // came back — nothing could flip. It now means the fetcher RUNS with
        // the live buffer and its list round-trips as the ready message.
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        $this->assertInstanceOf(\Closure::class, $cmd);

        $async = self::s02AsyncCmdFrom($cmd);
        [$fulfilled, ] = self::s02Settle($async);

        $this->assertSame('a', $seenInput, 'the configured fetcher must receive the current value');
        $this->assertInstanceOf(FormSuggestionsReadyMsg::class, $fulfilled);
        $this->assertSame('test', $fulfilled->fieldKey);
        $this->assertSame(['suggestion'], $fulfilled->suggestions);
    }

    public function testDebounceTimerIsScheduledOnCharKeystroke(): void
    {
        $fetcherInvokedCount = 0;
        $fetcher = static function (string $input) use (&$fetcherInvokedCount): PromiseInterface {
            $fetcherInvokedCount++;
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher, 50); // 50ms debounce
        [$f, ] = $f->focus();

        // Burst #1: 'h' then 'i'. The old leg asserted two non-null Cmds and
        // never ran the loop, so its "the second one replaces the first" was
        // prose. SF-32 makes the claim observable as a fire-COUNT over two
        // keystroke rounds, mirroring testDebounceCancelCancelsPreviousFetch.
        [$f, $cmd1] = $f->update(new KeyMsg(KeyType::Char, 'h'));
        [$f, $cmd2] = $f->update(new KeyMsg(KeyType::Char, 'i'));
        $this->assertNotNull($cmd1, 'first keystroke schedules a fetch');
        $this->assertNotNull($cmd2, 'second keystroke schedules its own fetch');
        $cmd1();
        $cmd2();

        self::assertTrue(
            self::pumpUntil(
                function () use (&$fetcherInvokedCount): bool {
                    return $fetcherInvokedCount >= 1;
                },
                self::jitterBudget(0.05),
            ),
            'the surviving debounce must fire',
        );
        self::advance();
        $this->assertSame(1, $fetcherInvokedCount, 'within one burst only the newest keystroke fetches');

        // Burst #2: another replaced pair adds exactly one more fire — the
        // replacement contract held a second time, not just the first.
        [$f, $cmd3] = $f->update(new KeyMsg(KeyType::Char, 'j'));
        [$f, $cmd4] = $f->update(new KeyMsg(KeyType::Char, 'k'));
        $cmd3();
        $cmd4();

        self::assertTrue(
            self::pumpUntil(
                function () use (&$fetcherInvokedCount): bool {
                    return $fetcherInvokedCount >= 2;
                },
                self::jitterBudget(0.05),
            ),
            'the second burst must fire its surviving fetch',
        );
        self::advance();
        $this->assertSame(2, $fetcherInvokedCount, 'a second burst adds one fire, not two');
    }

    public function testSuggestionsReadyMsgStructure(): void
    {
        $msg = new FormSuggestionsReadyMsg('myfield', ['alpha', 'beta'], seq: 7);
        $this->assertSame('myfield', $msg->fieldKey);
        $this->assertSame(['alpha', 'beta'], $msg->suggestions);
        $this->assertSame(7, $msg->seq, 'the generation rides the envelope (SF-52 gate input)');
    }

    public function testSelectWithAsyncSuggestionsSetsFetcher(): void
    {
        $seenFilter = null;
        $fetcher = static function (string $input) use (&$seenFilter): PromiseInterface {
            $seenFilter = $input;
            return \React\Promise\resolve(['suggestion']);
        };

        $f = Select::new('test')
            ->withOptions('PHP', 'Go', 'Rust')
            ->withAsyncSuggestions($fetcher);

        // SF-32: the old body was an assertInstanceOf("can be called without
        // error") that nothing could flip. Select fetches on FILTER-TEXT
        // change, so the live shape is: enter filter mode, type, and the
        // configured fetcher runs with the current filter.
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '/'));
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'P'));

        $async = self::s02AsyncCmdFrom($cmd);
        [$fulfilled, ] = self::s02Settle($async);

        $this->assertSame('P', $seenFilter, 'the fetcher must receive the live filter text');
        $this->assertInstanceOf(FormSuggestionsReadyMsg::class, $fulfilled);
        $this->assertSame('test', $fulfilled->fieldKey);
        $this->assertSame(['suggestion'], $fulfilled->suggestions);
    }

    public function testAsyncSuggestionsFactoryMethodExists(): void
    {
        $f = Input::new('test')->async(
            static fn(string $v): PromiseInterface => \React\Promise\resolve(['async-' . $v]),
            100
        );
        $this->assertInstanceOf(Input::class, $f);
    }

    public function testSelectAsyncFactoryMethodExists(): void
    {
        $f = Select::new('test')
            ->withOptions('A', 'B')
            ->async(
                static fn(string $v): PromiseInterface => \React\Promise\resolve(['async-' . $v]),
                100
            );
        $this->assertInstanceOf(Select::class, $f);
    }

    public function testWithAsyncSuggestionsAcceptsWorkerPool(): void
    {
        $fetcherCalled = false;
        $fetcher = static function (string $input) use (&$fetcherCalled): PromiseInterface {
            $fetcherCalled = true;
            return \React\Promise\resolve(['suggestion']);
        };

        // SF-32: "accepts null without error" was an assertInstanceOf nothing
        // could flip. The claim is that a null worker pool still wires a
        // working in-process fetch, so we drive one and assert the flag flips.
        $f = Input::new('test')->withAsyncSuggestions($fetcher, 50, null);
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $async = self::s02AsyncCmdFrom($cmd);
        [$fulfilled, ] = self::s02Settle($async);

        $this->assertTrue($fetcherCalled, 'a null worker pool must still invoke the in-process fetcher');
        $this->assertInstanceOf(FormSuggestionsReadyMsg::class, $fulfilled);
    }

    public function testInputHandlesSuggestionsReadyMsg(): void
    {
        $f = Input::new('test');
        $f2 = $f->withSuggestions(['alpha', 'beta']);
        [$f2, ] = $f2->focus();

        // The field should have suggestions set
        $this->assertSame(['alpha', 'beta'], $f2->input->availableSuggestions());
    }

    public function testDebounceTimerScheduling(): void
    {
        $fetcher = static function (string $input): PromiseInterface {
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher, 100); // 100ms debounce
        [$f, ] = $f->focus();

        // Type multiple characters in quick succession
        [$f, $cmd1] = $f->update(new KeyMsg(KeyType::Char, 'a'));
        [$f, $cmd2] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        [$f, $cmd3] = $f->update(new KeyMsg(KeyType::Char, 'c'));

        // All commands should be returned (debounce scheduling)
        $this->assertNotNull($cmd1);
        $this->assertNotNull($cmd2);
        $this->assertNotNull($cmd3);

        // The commands should be different closures (each keystroke creates new schedule)
        $this->assertNotSame($cmd1, $cmd2);
        $this->assertNotSame($cmd2, $cmd3);
    }

    public function testWithAsyncSuggestionsWithCustomDebounce(): void
    {
        $fetcher = static function (string $input): PromiseInterface {
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        // Custom 200ms debounce
        $f = Input::new('test')->withAsyncSuggestions($fetcher, 200);
        [$f, ] = $f->focus();

        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'x'));

        $this->assertNotNull($cmd);
    }

    public function testDebounceCancelCancelsPreviousFetch(): void
    {
        $fetcherInvokedCount = 0;
        $fetcher = static function (string $input) use (&$fetcherInvokedCount): PromiseInterface {
            $fetcherInvokedCount++;
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher, 100); // 100ms debounce
        [$f, ] = $f->focus();

        // First keystroke: schedules debounce #1
        [$f, $asyncCmd1] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        // Second keystroke immediately after (no delay): cancels debounce #1, schedules debounce #2
        [$f2, $asyncCmd2] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        // Execute both async commands to schedule their timers on the event loop
        // The first timer (debounce #1) should be cancelled before it fires
        $this->assertNotNull($asyncCmd1);
        $this->assertNotNull($asyncCmd2);

        // Execute the async commands to schedule timers on the global event loop
        $asyncCmd1();
        $asyncCmd2();

        // SF-31: order, not walls. Pump until the surviving (second) debounce
        // is OBSERVED to fire, then drain explicit iterations so a leaked
        // first-debounce fire would run before the count asserts. The
        // cancellation itself is semantic — the old 0.25s stop-timer only
        // raced jitter around the same guarantee.
        $budget = self::jitterBudget(0.1); // keystroke #2's debounce window
        self::assertTrue(
            self::pumpUntil(
                function () use (&$fetcherInvokedCount): bool {
                    return $fetcherInvokedCount >= 1;
                },
                $budget,
            ),
            'the surviving debounce must fire within its jitter budget',
        );
        self::advance();

        // Assert: the fetcher was only called once (for the second debounce),
        // because the first debounce was cancelled before its timer fired.
        // This verifies that rapid keystrokes cancel previous pending async operations.
        $this->assertSame(1, $fetcherInvokedCount, 'First debounced fetch should have been cancelled');
    }

    /**
     * Regression test: when both an inner Cmd (from the TextInput blink) and an
     * async suggestion Cmd are present, the async Cmd must not be silently dropped.
     * Before the fix, `fn() => $cmd()` discarded $asyncCmd entirely.
     * Mirrors the pattern from testDebounceCancelCancelsPreviousFetch.
     */
    public function testInputAsyncFiresWhenInnerCmdAlsoPresent(): void
    {
        $fetcherInvokedCount = 0;
        $fetcher = static function (string $input) use (&$fetcherInvokedCount): PromiseInterface {
            $fetcherInvokedCount++;
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher, 100);
        [$f, $innerCmd] = $f->focus();

        // Focusing Input returns a blink cmd (innerCmd is non-null).
        // Now type a Char — this schedules async suggestions AND returns a combined cmd.
        $this->assertNotNull($innerCmd, 'Focus should return a blink cmd');
        [$f, $combinedCmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        // The returned cmd must combine both the inner blink and the async fetch.
        // Before the fix: only fn()=>$cmd() was returned (async dropped).
        // After the fix: Cmd::batch($cmd, $asyncCmd) is returned (both included).
        $this->assertNotNull($combinedCmd, 'Combined cmd must not be null when inner cmd is present');

        // Execute the combined cmd (schedules debounce timer on the loop)
        $combinedCmd();

        // SF-31: observe the fire by ordering, not by wall racing.
        self::assertTrue(
            self::pumpUntil(
                function () use (&$fetcherInvokedCount): bool {
                    return $fetcherInvokedCount >= 1;
                },
                self::jitterBudget(0.1),
            ),
            'the debounce must fire within its jitter budget',
        );
        self::advance();

        // The fetcher must have been called exactly once.
        // Before the fix: $asyncCmd was dropped, so fetcher was never invoked.
        $this->assertSame(1, $fetcherInvokedCount, 'Async fetcher must fire even when inner cmd is present');
    }

    /**
     * Regression test for Select: when the inner Cmd from list->update() is present
     * AND the filter text change triggers async suggestions, both must run.
     * Before the fix, `fn() => $cmd()` discarded $asyncCmd.
     */
    public function testSelectAsyncFiresWhenInnerCmdAlsoPresent(): void
    {
        $fetcherInvokedCount = 0;
        $fetcher = static function (string $input) use (&$fetcherInvokedCount): PromiseInterface {
            $fetcherInvokedCount++;
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Select::new('test')
            ->withOptions('apple', 'banana', 'cherry')
            ->withAsyncSuggestions($fetcher, 100);
        [$f, ] = $f->focus();

        // Enter filter mode (first Char enters filter mode, second Char changes filter text
        // and triggers async scheduling when combined with an inner list cmd).
        // First: enter filter mode with '/'
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '/'));

        // Second: type 'a' — filterText changes from '' to 'a', and list->update returns
        // a non-null Cmd (the cursor-move or similar inner update). This triggers the
        // async scheduling with the inner cmd also present.
        [$f, $combinedCmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $this->assertNotNull($combinedCmd, 'Combined cmd must not be null when inner cmd is present and filter text changes');

        // Execute the combined cmd
        $combinedCmd();

        // SF-31: ordered observation replaces the wall-clock debounce race.
        self::assertTrue(
            self::pumpUntil(
                function () use (&$fetcherInvokedCount): bool {
                    return $fetcherInvokedCount >= 1;
                },
                self::jitterBudget(0.1),
            ),
            'the debounce must fire within its jitter budget',
        );
        self::advance();

        $this->assertSame(1, $fetcherInvokedCount, 'Async fetcher must fire in Select even when inner cmd is present');
    }

    // ---- SF-S02 (findings 004/019/082): the shared fetcher guard ----

    /**
     * Unwrap the AsyncCmd out of the (possibly batched) Cmd a keystroke
     * produced — same shape as AsyncSuggestionCancelTimeoutTest's helper.
     */
    private static function s02AsyncCmdFrom(?\Closure $cmd): AsyncCmd
    {
        self::assertNotNull($cmd, 'async scheduling must return a Cmd');
        $produced = $cmd();
        if ($produced instanceof AsyncCmd) {
            return $produced;
        }
        self::assertInstanceOf(\SugarCraft\Core\BatchMsg::class, $produced);
        foreach ($produced->cmds as $sub) {
            $msg = $sub();
            if ($msg instanceof AsyncCmd) {
                return $msg;
            }
        }
        self::fail('batch carried no AsyncCmd');
    }

    /**
     * @return array{0: mixed, 1: ?\Throwable} [fulfilment, rejection] captured from a promise
     */
    private static function s02Settle(AsyncCmd $async): array
    {
        $fulfilled = null;
        $rejected  = null;
        $async->promise->then(
            static function (mixed $v) use (&$fulfilled): void {
                $fulfilled = $v;
            },
            static function (?\Throwable $e) use (&$rejected): void {
                $rejected = $e;
            }
        );
        // SF-31: the settle is OBSERVED (pumpUntil), then explicit ticks are
        // drained so a double-settle could land before the asserts. The old
        // fixed 0.2s pump only raced the same guarantee.
        self::assertTrue(
            self::pumpUntil(
                function () use (&$fulfilled, &$rejected): bool {
                    return $fulfilled !== null || $rejected !== null;
                },
                self::S02_SETTLE_BUDGET,
            ),
            'the guarded fetch must settle within the named budget',
        );
        self::advance();
        return [$fulfilled, $rejected];
    }

    public function testThrowingFetcherRejectsInsteadOfUnwindingTheLoopInput(): void
    {
        $fetcher = static function (string $input): PromiseInterface {
            throw new \RuntimeException('boom-fetch');
        };

        $f = Input::new('repo')->withAsyncSuggestions($fetcher, 10);
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'r'));

        $async = self::s02AsyncCmdFrom($cmd);
        [$fulfilled, $rejected] = self::s02Settle($async);

        // The loop COMPLETED this pump — pre-guard the throw unwound
        // Loop::run() and the test died with it (mutation proof of SF-004).
        self::assertNull($fulfilled, 'a throwing fetcher must not fulfil');
        self::assertInstanceOf(\RuntimeException::class, $rejected);
        self::assertSame('boom-fetch', $rejected->getMessage());

        // And the loop is still alive for the program's next beat — observed
        // with a future-tick beat (SF-31: a timer here would re-arm the very
        // wall-clock admission this suite removes).
        $alive = false;
        Loop::futureTick(static function () use (&$alive): void {
            $alive = true;
        });
        self::advance(2);
        self::assertTrue($alive, 'the event loop must survive a throwing fetcher');
    }

    public function testThrowingFetcherRejectsInsteadOfUnwindingTheLoopSelect(): void
    {
        $fetcher = static function (string $input): PromiseInterface {
            throw new \RuntimeException('boom-select');
        };

        $f = Select::new('lang')
            ->withOptions('PHP', 'Go', 'Rust')
            ->withAsyncSuggestions($fetcher, 10);
        [$f, ] = $f->focus();
        [$f, ] = $f->update(new KeyMsg(KeyType::Char, '/'));
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'P'));

        $async = self::s02AsyncCmdFrom($cmd);
        [, $rejected] = self::s02Settle($async);

        self::assertInstanceOf(\RuntimeException::class, $rejected);
        self::assertSame('boom-select', $rejected->getMessage());
    }

    public function testNonPromiseFetcherReturnRejectsWithTypedMessage(): void
    {
        $fetcher = static function (string $input): mixed {
            return 'i-am-not-a-promise';
        };

        $f = Input::new('repo')->withAsyncSuggestions($fetcher, 10);
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'r'));

        [, $rejected] = self::s02Settle(self::s02AsyncCmdFrom($cmd));

        self::assertInstanceOf(\InvalidArgumentException::class, $rejected);
        self::assertSame(
            Lang::t('suggestions.async_return_type', ['key' => 'repo', 'type' => 'string']),
            $rejected->getMessage()
        );
    }

    public function testNonArrayFulfillmentRejectsWithTypedMessage(): void
    {
        // Pre-guard the strict `function (array $suggestions)` arm turned
        // this into an UNCONSUMED TypeError — suggestions silently never
        // arrived. The guard must surface a typed rejection instead.
        $fetcher = static function (string $input): PromiseInterface {
            return \React\Promise\resolve('not-a-list');
        };

        $f = Input::new('repo')->withAsyncSuggestions($fetcher, 10);
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'r'));

        [$fulfilled, $rejected] = self::s02Settle(self::s02AsyncCmdFrom($cmd));

        self::assertNull($fulfilled);
        self::assertInstanceOf(\InvalidArgumentException::class, $rejected);
        self::assertSame(
            Lang::t('suggestions.async_result_type', ['key' => 'repo', 'type' => 'string']),
            $rejected->getMessage()
        );
    }

    public function testArrayFulfillmentStillResolvesTheSuggestionsMessage(): void
    {
        // Zero-regression pin of the settled happy path.
        $fetcher = static function (string $input): PromiseInterface {
            return \React\Promise\resolve(['alpha', 'beta']);
        };

        $f = Input::new('repo')->withAsyncSuggestions($fetcher, 10);
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'r'));

        [$fulfilled, $rejected] = self::s02Settle(self::s02AsyncCmdFrom($cmd));

        self::assertNull($rejected);
        self::assertInstanceOf(FormSuggestionsReadyMsg::class, $fulfilled);
        self::assertSame(['alpha', 'beta'], $fulfilled->suggestions);
    }

    public function testConfigDoorRejectsBadTimeoutAtSetterTimeInput(): void
    {
        $fetcher = static fn (string $input): PromiseInterface => \React\Promise\resolve([]);
        $field   = Input::new('repo');

        foreach ([0.0, -1.0, NAN, INF] as $bad) {
            try {
                $field->withAsyncSuggestions($fetcher, 150, null, $bad);
                self::fail('timeout ' . var_export($bad, true) . ' must be refused at the config door');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(
                    Lang::t('suggestions.bad_timeout', ['key' => 'repo', 'seconds' => var_export($bad, true)]),
                    $e->getMessage()
                );
            }
        }
    }

    public function testConfigDoorRejectsBadTimeoutAndDebounceAtSetterTimeSelect(): void
    {
        $fetcher = static fn (string $input): PromiseInterface => \React\Promise\resolve([]);
        $field   = Select::new('lang')->withOptions('PHP', 'Go');

        try {
            $field->withAsyncSuggestions($fetcher, 150, null, NAN);
            self::fail('NAN timeout must be refused at the config door');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                Lang::t('suggestions.bad_timeout', ['key' => 'lang', 'seconds' => 'NAN']),
                $e->getMessage()
            );
        }

        try {
            $field->withAsyncSuggestions($fetcher, -1);
            self::fail('negative debounce must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame(
                Lang::t('suggestions.bad_debounce', ['key' => 'lang', 'ms' => '-1']),
                $e->getMessage()
            );
        }

        // The short alias delegates — same door, same refusals.
        try {
            $field->async($fetcher, 150, null, 0.0);
            self::fail('async() alias must carry the config door too');
        } catch (\InvalidArgumentException) {
            // expected
        }
    }

    public function testPositiveAndZeroDebounceStillPassTheConfigDoor(): void
    {
        // Zero debounce is a host-valid "no debounce"; a positive timeout
        // is the point of the knob. Both must stay allowed.
        $fetcher = static fn (string $input): PromiseInterface => \React\Promise\resolve([]);

        self::assertInstanceOf(
            Input::class,
            Input::new('repo')->withAsyncSuggestions($fetcher, 0, null, 2.5)
        );
    }
}
