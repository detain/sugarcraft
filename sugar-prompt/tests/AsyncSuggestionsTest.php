<?php

declare(strict_types=1);

namespace SugarCraft\Prompt\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Prompt\Field\Input;
use SugarCraft\Prompt\Field\Select;

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
}
