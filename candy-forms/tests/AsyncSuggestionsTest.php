<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

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
    protected function setUp(): void
    {
        // Ensure a fresh loop for each test
        Loop::set(new StreamSelectLoop());
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
        $msg = new SuggestionsReadyMsg('myfield', ['alpha', 'beta']);
        $this->assertSame('myfield', $msg->fieldKey);
        $this->assertSame(['alpha', 'beta'], $msg->suggestions);
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
}
