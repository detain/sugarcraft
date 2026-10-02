<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\ReportsContextWindow;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;

/**
 * Audit 15b-01: the UserPromptSubmit gate on the 85% tier's PARKED route.
 *
 * `submit()` used to return the parked pair from
 * {@see Chat::scheduleParkedCompaction()} ahead of its own hook call, and the
 * landing ({@see Chat::applyModelCompaction()}) dispatched the turn without ever
 * running one. So a secret-blocking hook that held below the tier let the same
 * prompt through above it, and an allowing hook's note never reached the model.
 *
 * The contract driven here: the hook judges every submission EXACTLY ONCE —
 * parked, unparked, or downgraded to the heuristic — and the refusals that turn a
 * prompt away unsubmitted still fire it zero times.
 *
 * Doubles are local anonymous classes rather than the ones beside
 * {@see AutomaticCompactionModelSummaryTest}: those live in that test's file, which
 * PSR-4 cannot autoload by their names, so borrowing them would make this file pass
 * or fail on test ORDER.
 */
final class ParkedCompactionHookTest extends TestCase
{
    private const PROMPT = 'deploy with AWS_SECRET_ACCESS_KEY=AKIAFAKEFAKEFAKE';

    private const NOTE = '15B01-PROMPT-NOTE this repository deploys through the staging gate';

    private const BLOCK_REASON = '15B01-BLOCK prompt contains a secret';

    private const WINDOW = 88_000;

    /** @var \ArrayObject<int, HookContext> every UserPromptSubmit context the hook was handed */
    private \ArrayObject $fired;

    protected function setUp(): void
    {
        $this->fired = new \ArrayObject();
    }

    /**
     * Three heavy exchanges plus ten trivial ones: ~78k estimated tokens of an
     * 88k window — past the 85% tier, comfortably under 95% once the heavy ones
     * are condensed. The same shape AutomaticCompactionModelSummaryTest parks on.
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
     * Thirteen equal ~15k-token exchanges: the ten preserved recent pairs alone
     * overflow the window, so the synchronous tier's 95% refusal stands.
     *
     * @return list<Message>
     */
    private static function unshrinkablePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 30_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 30_000));
        }

        return $history;
    }

    // =====================================================================
    // (a) a blocking hook past the tier
    // =====================================================================

    public function testABlockingHookPastTheTierStopsThePromptBeforeItIsParked(): void
    {
        $main = $this->mainBackend();
        $summarizer = $this->summarizer();
        $chat = $this->chat(self::compactablePairs(), $main, $summarizer, HookResult::deny(self::BLOCK_REASON));
        $before = count($chat->history);

        [$after, $cmd] = $this->submit($chat);

        $this->assertNull($cmd, 'no summarization Cmd leaves for a prompt the hook blocked');
        $this->assertCount(1, $this->fired, 'the hook judged the parked submission');
        $this->assertSame(self::PROMPT, json_decode($this->fired[0]->toolInput, true)['prompt']);
        $this->assertSame(0, $summarizer->calls(), 'the summarizer was never asked');
        $this->assertSame(0, $main->calls(), 'and neither was the main model');

        $this->assertCount($before + 1, $after->history, 'only the refusal notice was appended — no park notice, no echo');
        $last = $after->history[count($after->history) - 1];
        $this->assertSame(Role::System, $last->role);
        $this->assertStringStartsWith('Hook denied:', $last->content);
        $this->assertStringContainsString(self::BLOCK_REASON, $last->content);
        $this->assertSame(0, $this->countSaying($after->history, 'AKIAFAKE'), 'the secret is nowhere in the transcript');

        $this->assertFalse($after->inFlight, 'nothing is parked, so nothing holds the turn open');
        $this->assertNull($this->latchOf($after), 'and no summarization is outstanding');
        $this->assertSame(self::PROMPT, $after->inputBuf, 'the draft stays in the box, as on the unparked route');
    }

    // =====================================================================
    // (b) an allowing hook with a note past the tier
    // =====================================================================

    public function testAnAllowingHookPastTheTierFiresOnceAcrossParkAndLandingAndItsNoteReachesTheTurn(): void
    {
        $main = $this->mainBackend();
        $summarizer = $this->summarizer();
        $chat = $this->chat(self::compactablePairs(), $main, $summarizer, HookResult::allow('', self::NOTE));

        [$parked, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd, 'the prompt is parked behind the summarization');
        $this->assertTrue($parked->inFlight);
        $this->assertCount(1, $this->fired, 'the hook fires when the prompt is parked');

        // The note sits immediately ahead of the echoed prompt — the slot the
        // unparked route gives it.
        $echoAt = $this->lastIndexOfUser($parked->history, self::PROMPT);
        $this->assertGreaterThan(0, $echoAt);
        $this->assertSame(Role::System, $parked->history[$echoAt - 1]->role);
        $this->assertSame(self::NOTE, $parked->history[$echoAt - 1]->content);

        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        [$dispatched, $turnCmd] = $parked->update($msg);
        $this->assertNotNull($turnCmd, 'the parked turn goes out at the landing');
        $turnCmd();

        $this->assertCount(1, $this->fired, 'the landing does not run the hook a second time');
        $this->assertSame(1, $main->calls(), 'exactly one turn reached the main model');

        $wire = $main->lastHistory();
        $promptAt = $this->lastIndexOfUser($wire, self::PROMPT);
        $this->assertGreaterThan(0, $promptAt, 'the prompt is in the dispatched turn');
        $this->assertSame(self::NOTE, $wire[$promptAt - 1]->content, 'with the hook note immediately ahead of it');
        $this->assertSame(Role::System, $wire[$promptAt - 1]->role);
        $this->assertSame(1, $this->countSaying($wire, self::NOTE), 'carried once, not duplicated by the landing');
        $this->assertSame(1, $this->countSaying($dispatched->history, self::NOTE));
    }

    // =====================================================================
    // (c) below the tier — unchanged
    // =====================================================================

    public function testBelowTheTierTheHookStillFiresExactlyOnceAheadOfThePrompt(): void
    {
        $main = $this->mainBackend();
        $summarizer = $this->summarizer();
        $chat = $this->chat(
            [Message::user('hi'), Message::assistant('hello')],
            $main,
            $summarizer,
            HookResult::allow('', self::NOTE),
        );

        [$turn, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd);
        $this->assertCount(1, $this->fired);
        $this->assertSame(0, $summarizer->calls(), 'no tier, no summarization');
        $this->assertSame(Role::System, $turn->history[2]->role);
        $this->assertSame(self::NOTE, $turn->history[2]->content);
        $this->assertSame(Role::User, $turn->history[3]->role);
        $this->assertSame(self::PROMPT, $turn->history[3]->content);

        $cmd();
        $this->assertCount(1, $this->fired);
        $this->assertSame(1, $main->calls());
    }

    public function testBelowTheTierABlockingHookStillRefuses(): void
    {
        $main = $this->mainBackend();
        $chat = $this->chat([Message::user('hi'), Message::assistant('hello')], $main, $this->summarizer(), HookResult::deny(self::BLOCK_REASON));

        [$after, $cmd] = $this->submit($chat);

        $this->assertNull($cmd);
        $this->assertCount(1, $this->fired);
        $this->assertSame(0, $main->calls());
        $this->assertStringStartsWith('Hook denied:', $after->history[count($after->history) - 1]->content);
    }

    // =====================================================================
    // the routes beside the parked one
    // =====================================================================

    /**
     * With no summarizer the tier compacts on the heuristic and dispatches from
     * submit()'s tail. scheduleParkedCompaction() returns null on this route, so
     * it must not have fired the hook — the tail does, once.
     */
    public function testTheHeuristicTierFiresTheHookExactlyOnce(): void
    {
        $main = $this->mainBackend();
        $chat = $this->chat(self::compactablePairs(), $main, null, HookResult::allow('', self::NOTE));

        [$turn, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd);
        $this->assertCount(1, $this->fired);
        $promptAt = $this->lastIndexOfUser($turn->history, self::PROMPT);
        $this->assertSame(self::NOTE, $turn->history[$promptAt - 1]->content);
        $this->assertSame(1, $this->countSaying($turn->history, self::NOTE));
    }

    /**
     * The synchronous 95% refusal turns the prompt away unsubmitted, and the gate
     * fires only for a submitted prompt — the fix must not move it ahead of that.
     */
    public function testTheSynchronousBlockingRefusalStillDoesNotFireTheHook(): void
    {
        $main = $this->mainBackend();
        $chat = $this->chat(self::unshrinkablePairs(), $main, null, HookResult::allow('', self::NOTE));

        [$after, $cmd] = $this->submit($chat);

        $this->assertNull($cmd, 'the 95% tier refused the prompt');
        $this->assertSame(0, $main->calls());
        $this->assertCount(0, $this->fired, 'a refused, unsubmitted prompt is not shown to the hook');
        $this->assertSame(0, $this->countSaying($after->history, self::NOTE));
    }

    /**
     * Double-Escape abandons the parked turn; the stale landing then dispatches
     * nothing. The note stays beside its own echoed prompt — where the unparked
     * route's cancel leaves it — and nothing carried across the park resurfaces.
     */
    public function testACancelledParkLeavesTheNoteBesideItsOwnPromptAndTheStaleLandingAddsNothing(): void
    {
        $main = $this->mainBackend();
        $chat = $this->chat(self::compactablePairs(), $main, $this->summarizer(), HookResult::allow('', self::NOTE));

        [$parked, $cmd] = $this->submit($chat);
        [$armed] = $parked->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $armed->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($cancelled->inFlight);

        [$after, $afterCmd] = $cancelled->update($this->resolve($cmd));

        $this->assertNull($afterCmd, 'the cancelled turn is not dispatched by the stale landing');
        $this->assertSame(0, $main->calls());
        $this->assertCount(1, $this->fired, 'and the landing ran no hook');
        $this->assertSame(count($cancelled->history), count($after->history), 'nor wrote any row');
        $this->assertSame(1, $this->countSaying($after->history, self::NOTE));
        $echoAt = $this->lastIndexOfUser($after->history, self::PROMPT);
        $this->assertSame(self::NOTE, $after->history[$echoAt - 1]->content);
    }

    // =====================================================================
    // helpers
    // =====================================================================

    /** @param list<Message> $history */
    private function chat(array $history, Backend $main, ?Backend $summarizer, HookResult $verdict): Chat
    {
        $registry = new HookRegistry();
        $registry->register($this->hook($verdict));

        return new Chat(
            history: $history,
            inputBuf: self::PROMPT,
            backend: $main,
            summaryBackend: $summarizer,
            hooks: new HookManager($registry),
        );
    }

    private function hook(HookResult $verdict): HookInterface
    {
        return new class($verdict, $this->fired) implements HookInterface {
            /** @param \ArrayObject<int, HookContext> $fired */
            public function __construct(private readonly HookResult $verdict, private readonly \ArrayObject $fired) {}

            public function name(): string
            {
                return 'secret-guard';
            }

            public function event(): HookEvent
            {
                return HookEvent::UserPromptSubmit;
            }

            public function matcher(): string
            {
                return '';
            }

            public function execute(HookContext $context): HookResult
            {
                $this->fired[] = $context;

                return $this->verdict;
            }
        };
    }

    private function mainBackend(): Backend
    {
        return new class(self::WINDOW) implements Backend, ReportsContextWindow {
            /** @var list<list<Message>> */
            private array $seen = [];

            public function __construct(private readonly int $window) {}

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function calls(): int
            {
                return count($this->seen);
            }

            /** @return list<Message> */
            public function lastHistory(): array
            {
                return $this->seen[count($this->seen) - 1] ?? [];
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->seen[] = $history;

                return Message::assistant('ok');
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->seen[] = $history;

                return \React\Promise\resolve(Message::assistant('ok'));
            }
        };
    }

    /** A summarizer with a record for every exchange the fixtures offer. */
    private function summarizer(): Backend
    {
        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = "{$i}.\nasked: condensed exchange {$i}";
        }

        return new class(implode("\n", $records)) implements Backend {
            private int $calls = 0;

            public function __construct(private readonly string $reply) {}

            public function calls(): int
            {
                return $this->calls;
            }

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                $this->calls++;

                return Message::assistant($this->reply);
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                $this->calls++;

                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }

    /** @return array{0: Chat, 1: ?\Closure} */
    private function submit(Chat $chat): array
    {
        return $chat->update(new KeyMsg(KeyType::Enter, ''));
    }

    /** Drive a Cmd built by Cmd::promise() and hand back the Msg it resolves to. */
    private function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    private function latchOf(Chat $chat): ?string
    {
        return (new \ReflectionProperty(Chat::class, 'pendingCompactionId'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private function lastIndexOfUser(array $history, string $text): int
    {
        for ($i = count($history) - 1; $i >= 0; $i--) {
            if ($history[$i]->role === Role::User && $history[$i]->content === $text) {
                return $i;
            }
        }

        return -1;
    }

    /** @param list<Message> $history */
    private function countSaying(array $history, string $text): int
    {
        return count(array_filter(
            $history,
            static fn(Message $m): bool => str_contains((string) $m->content, $text),
        ));
    }
}
