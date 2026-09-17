<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\SuggestionsReadyMsg;
use SugarCraft\Async\TimeoutException;
use SugarCraft\Forms\Field\Input;
use SugarCraft\Forms\Field\Select;

/**
 * E736-F2 Phase-6 pins (round 85): async suggestion fetch lifecycle.
 *
 * 6.6 — a fetch superseded by the next keystroke must resolve quietly with
 *       null (Program discards null resolutions). It used to REJECT with
 *       RuntimeException('Async suggestions cancelled'), which Program's
 *       otherwise-arm surfaced as an ExceptionMsg per replaced keystroke —
 *       error-storm on normal typing.
 * 6.5 — opt-in per-fetch wall-bound via withAsyncSuggestions()'s 4th
 *       parameter (AsyncOps::withTimeout). Default null keeps the raw
 *       await byte-identical (E646: an explicit config knob, not a blanket
 *       kill — unset means never-timeout exactly as before).
 *
 * Loop hygiene mirrors {@see AsyncSuggestionsTest}: a fresh StreamSelectLoop
 * per test via Loop::set() in setUp (arm-time clock refresh; no
 * ext-uv/LoopPin exposure).
 */
final class AsyncSuggestionCancelTimeoutTest extends TestCase
{
    protected function setUp(): void
    {
        Loop::set(new StreamSelectLoop());
    }

    public function testSupersededFetchResolvesQuietlyWithNull(): void
    {
        $fetcher = static fn(string $v): PromiseInterface => (new Deferred())->promise();

        $f = Input::new('k')->withAsyncSuggestions($fetcher, 5);
        [$f] = $f->focus();
        [$f, $cmd1] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $async1 = self::f2AsyncCmdFrom($cmd1);
        $this->assertInstanceOf(AsyncCmd::class, $async1);

        $resolved = ['settled' => false, 'value' => 'unsentinel', 'error' => null];
        $async1->promise->then(
            static function ($v) use (&$resolved): void {
                $resolved['settled'] = true;
                $resolved['value']   = $v;
            },
            static function (\Throwable $e) use (&$resolved): void {
                $resolved['settled'] = true;
                $resolved['error']   = $e;
            },
        );

        // Second keystroke supersedes the first fetch's cancellation token.
        [$f, $cmd2] = $f->update(new KeyMsg(KeyType::Char, 'b'));
        self::f2RunLoopFor(0.1);

        $this->assertTrue($resolved['settled'], 'superseded fetch must settle, not hang');
        $this->assertNull($resolved['error'], 'cancellation must no longer reject (6.6)');
        $this->assertNull($resolved['value'], 'cancellation resolves with null');
        $this->assertNotNull(self::f2AsyncCmdFrom($cmd2), 'latest keystroke still schedules its own fetch');
    }

    public function testFetcherFailureStillRejectsThroughTheDeferred(): void
    {
        $fetcher = static fn(string $v): PromiseInterface => \React\Promise\reject(new \RuntimeException('boom-body'));

        $f = Input::new('k')->withAsyncSuggestions($fetcher, 1);
        [$f] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $async = self::f2AsyncCmdFrom($cmd);
        $error = null;
        $settledValue = 'unsentinel';
        $async->promise->then(
            static function ($v) use (&$settledValue): void { $settledValue = $v; },
            static function (\Throwable $e) use (&$error): void { $error = $e; },
        );
        self::f2RunLoopFor(0.15);

        // Polarity twin of the cancel pin: genuine fetch failures keep rejecting.
        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('boom-body', $error->getMessage());
        $this->assertSame('unsentinel', $settledValue);
    }

    public function testFetchTimeoutRejectsWithTimeoutException(): void
    {
        $fetcher = static fn(string $v): PromiseInterface => (new Deferred())->promise();

        $f = Input::new('k')->withAsyncSuggestions($fetcher, 1, null, 0.02);
        [$f] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $async = self::f2AsyncCmdFrom($cmd);
        $error = null;
        $resolvedValue = 'unsentinel';
        $async->promise->then(
            static function ($v) use (&$resolvedValue): void { $resolvedValue = $v; },
            static function (\Throwable $e) use (&$error): void { $error = $e; },
        );
        self::f2RunLoopFor(0.3);

        $this->assertInstanceOf(TimeoutException::class, $error, '6.5: opted-in ceiling must fire on a stalled fetch');
        $this->assertSame('unsentinel', $resolvedValue);
    }

    public function testNoTimeoutAwaitsTheFetcherAtItsOwnPace(): void
    {
        $fetcher = static function (string $v): PromiseInterface {
            $d = new Deferred();
            Loop::addTimer(0.04, static fn() => $d->resolve(['late-arrival']));
            return $d->promise();
        };

        // Default (null) timeout: a legitimately slow fetch still lands — the
        // wrapper must not exist on this path (byte-identical raw await).
        $f = Input::new('k')->withAsyncSuggestions($fetcher, 1);
        [$f] = $f->focus();
        [$f, $cmd] = $f->update(new KeyMsg(KeyType::Char, 'a'));

        $async = self::f2AsyncCmdFrom($cmd);
        $captured = null;
        $error    = null;
        $async->promise->then(
            static function ($v) use (&$captured): void { $captured = $v; },
            static function (\Throwable $e) use (&$error): void { $error = $e; },
        );
        self::f2RunLoopFor(0.4);

        $this->assertNull($error);
        $this->assertInstanceOf(SuggestionsReadyMsg::class, $captured);
        $this->assertSame(['late-arrival'], $captured->suggestions);
    }

    public function testSelectThreadsFetchTimeoutAndPreservesEnum(): void
    {
        $fetcher = static fn(string $v): PromiseInterface => (new Deferred())->promise();

        $s = Select::new('k')
            ->withEnum(F2LaneColorEnum::class)
            ->withAsyncSuggestions($fetcher, 7, null, 2.5);

        $read = static function (Select $field, string $prop) {
            $r = new \ReflectionProperty($field, $prop);
            $r->setAccessible(true);
            return $r->getValue($field);
        };

        // E741-sibling: withAsyncSuggestions used to DROP enumClass; the
        // timeout thread and the enum carry are pinned on one snapshot.
        $this->assertSame(2.5, $read($s, 'asyncSuggestionsFetchTimeoutSeconds'));
        $this->assertSame(F2LaneColorEnum::class, $read($s, 'enumClass'));

        // Plain mutate paths keep threading both ctor params.
        $s2 = $s->withTitle('T')->withDescription('D');
        $this->assertSame(2.5, $read($s2, 'asyncSuggestionsFetchTimeoutSeconds'));
        $this->assertSame(F2LaneColorEnum::class, $read($s2, 'enumClass'));
    }

    /**
     * Unwrap the AsyncCmd produced by an Input::update Cmd closure, whether
     * it was returned bare or inside a Cmd::batch (BatchMsg of closures).
     */
    private static function f2AsyncCmdFrom(?\Closure $cmd): AsyncCmd
    {
        self::assertNotNull($cmd, 'async scheduling must return a Cmd');
        $produced = $cmd();
        if ($produced instanceof AsyncCmd) {
            return $produced;
        }
        self::assertInstanceOf(BatchMsg::class, $produced);
        foreach ($produced->cmds as $sub) {
            $msg = $sub();
            if ($msg instanceof AsyncCmd) {
                return $msg;
            }
        }
        self::fail('batch carried no AsyncCmd');
    }

    private static function f2RunLoopFor(float $seconds): void
    {
        Loop::addTimer($seconds, static fn() => Loop::stop());
        Loop::run();
    }
}

/** Test-local enum: SelectEnumTest's ColorEnum lives in the same namespace, so the lane names its own. */
enum F2LaneColorEnum: string
{
    case Red   = 'red';
    case Green = 'green';
}
