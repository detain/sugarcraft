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

/**
 * Tests for async suggestions with debounce.
 *
 * These tests verify the basic behavior of withAsyncSuggestions:
 * - Method exists and returns correct type
 * - API is correctly configured
 * - The scheduling mechanism works
 */
final class AsyncSuggestionsTest extends TestCase
{
    use LoopHygiene {
        setUp as private hygieneSetUp;
        tearDown as private hygieneTearDown;
    }

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
        $fetcherCalled = false;
        $fetcher = static function (string $input) use (&$fetcherCalled): PromiseInterface {
            $fetcherCalled = true;
            return \React\Promise\resolve(['suggestion']);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher);

        // The field should have the async suggestions configured
        // We verify by checking that update returns a Cmd when Char is typed
        [$f, ] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        // A cmd should be returned when async suggestions are configured
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testDebounceTimerIsScheduledOnCharKeystroke(): void
    {
        $fetcher = static function (string $input): PromiseInterface {
            return \React\Promise\resolve(["suggestion for: $input"]);
        };

        $f = Input::new('test')->withAsyncSuggestions($fetcher, 50); // 50ms debounce
        [$f, ] = $f->focus();

        // Type a character - this should NOT fire the fetcher yet
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'h'));
        [$f, $cmd2] = $f->update(new KeyMsg(KeyType::Char, 'i'));

        // Commands should be returned (one for each keystroke, the second one replaces the first)
        $this->assertNotNull($cmd);
        $this->assertNotNull($cmd2);
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
        $fetcherCalled = false;
        $fetcher = static function (string $input) use (&$fetcherCalled): PromiseInterface {
            $fetcherCalled = true;
            return \React\Promise\resolve(['suggestion']);
        };

        $f = Select::new('test')
            ->withOptions('PHP', 'Go', 'Rust')
            ->withAsyncSuggestions($fetcher);

        // Select doesn't have Char keystrokes like Input
        // The async suggestions are triggered when filter text changes
        // This test verifies the method can be called without error
        $this->assertInstanceOf(Select::class, $f);
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

        // This should accept null as workerPool without error
        $f = Input::new('test')->withAsyncSuggestions($fetcher, 50, null);
        $this->assertInstanceOf(Input::class, $f);
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

        // Run the event loop long enough for any uncancelled debounce to fire
        // (200ms = 2x debounce window to ensure the second debounce has time to fire if not cancelled)
        Loop::addTimer(0.25, static fn () => Loop::stop());
        Loop::run();

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

        // Run the event loop past the debounce window.
        Loop::addTimer(0.2, static fn () => Loop::stop());
        Loop::run();

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

        // Run the event loop past the debounce window.
        Loop::addTimer(0.2, static fn () => Loop::stop());
        Loop::run();

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

    private static function s02Pump(float $seconds): void
    {
        Loop::addTimer($seconds, static fn () => Loop::stop());
        Loop::run();
    }

    /**
     * @return array{0: mixed, 1: ?\Throwable} [fulfilment, rejection] captured from a promise
     */
    private static function s02Settle(AsyncCmd $async, float $pumpSeconds = 0.2): array
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
        self::s02Pump($pumpSeconds);
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

        // And the loop is still alive for the program's next beat.
        $alive = false;
        Loop::addTimer(0.01, static function () use (&$alive): void {
            $alive = true;
            Loop::stop();
        });
        Loop::run();
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
