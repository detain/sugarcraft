<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\AssistantMsg;
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
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * Audit SES-1: `/rewind` must undo the prompt, not just its reply.
 *
 * The auto-checkpoint used to serialise the history the turn was DISPATCHED
 * with — which ends on the user's line — next to a draft that IS that line, so
 * a rewind left the prompt in the transcript and re-seeded it into the box:
 * Enter sent it twice and the model saw two consecutive user rows. These drive
 * the real submit → settle → `/rewind` → Enter path through a real store and
 * assert on what the NEXT turn would send, which is where the duplicate hurt.
 *
 * Doubles are local anonymous classes for the reason
 * {@see ParkedCompactionHookTest} gives: the ones beside
 * {@see AutomaticCompactionModelSummaryTest} are not autoloadable by name.
 *
 * @see Chat::dispatchTurn()
 * @see Chat::handleRewindCommand()
 */
final class RewindDraftHistoryTest extends TestCase
{
    private const SESSION = 'rewind-session';

    private const PROMPT = 'fix the login bug';

    private const NOTE = 'SES1-HOOK-NOTE the login flow lives in src/Auth';

    private const WINDOW = 88_000;

    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rewind_draft_history_' . uniqid('', true);
        mkdir($this->dir, 0755, true);
        $this->store = new EnhancedSessionStore($this->dir . '/sessions.db');
        $this->store->createSession(self::SESSION, 'echo', 'echo');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * THE REGRESSION. Pre-fix: the restored transcript was
     * `[earlier pair, user PROMPT, user /rewind, assistant Rewound 1 …]` with
     * PROMPT also in the box, and Enter dispatched a turn holding PROMPT twice.
     */
    public function testARewoundPromptGoesBackInTheBoxAndOutOfTheTranscript(): void
    {
        $main = $this->recordingBackend();
        $chat = $this->chat([Message::user('earlier question'), Message::assistant('earlier answer')], $main);

        $settled = $this->sendAndSettle($chat, self::PROMPT, 'patched the login bug');
        $this->assertSame(1, $this->countUserRows($settled->history, self::PROMPT), 'fixture: the turn committed the prompt once');

        [$rewound, $rewindCmd] = $this->type($settled, '/rewind')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($rewindCmd);

        $this->assertSame(
            0,
            $this->countUserRows($rewound->history, self::PROMPT),
            'the undone prompt must not survive in the transcript — it is in the box instead',
        );
        $this->assertSame(self::PROMPT, $rewound->inputBuf, 'the draft comes back for editing');
        $this->assertSame(
            ['earlier question', 'earlier answer', '/rewind'],
            array_map(static fn(Message $m): string => $m->content, array_slice($rewound->history, 0, 3)),
            'the transcript is the one from before the prompt, then the command echo',
        );
        $this->assertStringContainsString(
            'Rewound 2 messages to checkpoint 0.',
            $rewound->history[3]->content,
            'the count is the prompt plus its reply, not just the reply',
        );

        // Enter on the restored draft: exactly ONE copy goes out.
        $before = $main->calls();
        [$resent, $cmd] = $rewound->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertInstanceOf(\Closure::class, $cmd, 'the restored draft dispatches a turn');
        $this->assertSame(1, $this->countUserRows($resent->history, self::PROMPT), 'committed once');
        $this->runCmd($cmd);
        $this->assertSame($before + 1, $main->calls());
        $this->assertSame(
            1,
            $this->countUserRows($main->lastHistory(), self::PROMPT),
            'the dispatched turn carries the prompt exactly once',
        );
        $wire = $main->lastHistory();
        $last = $wire[count($wire) - 1];
        $this->assertSame(Role::User, $last->role);
        $this->assertSame(self::PROMPT, $last->content);
        $previous = $wire[count($wire) - 2];
        $this->assertFalse(
            $previous->role === Role::User && $previous->content === self::PROMPT,
            'and never as two consecutive user rows',
        );
    }

    /**
     * Sessions saved before the fix still hold checkpoints whose `messages` end
     * on the prompt (plus the 70% reminder when it fired). Restoring one must
     * drop that tail, or every pre-fix session keeps the bug.
     */
    public function testAPreFixCheckpointStillEndingOnItsPromptHasThePromptDropped(): void
    {
        $this->store->saveCheckpoint(self::SESSION, [
            'messages' => [
                ['role' => 'user', 'content' => 'earlier question'],
                ['role' => 'assistant', 'content' => 'earlier answer'],
                ['role' => 'user', 'content' => self::PROMPT],
                ['role' => 'system', 'content' => 'Heads up: this conversation has grown to ~70123 estimated tokens.'],
            ],
            // The pre-fix shape: the draft is the trailing prompt, no marker key.
            'inputBuf' => self::PROMPT,
            'inputCursor' => 4,
            'agentContext' => ['currentSessionId' => self::SESSION],
        ]);

        $chat = $this->chat([
            Message::user('earlier question'),
            Message::assistant('earlier answer'),
            Message::user(self::PROMPT),
            Message::assistant('patched the login bug'),
        ], $this->recordingBackend());

        [$rewound] = $this->type($this->clearDraft($chat), '/rewind')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(0, $this->countUserRows($rewound->history, self::PROMPT));
        $this->assertSame(
            [Role::User, Role::Assistant, Role::User, Role::Assistant],
            array_map(static fn(Message $m): Role => $m->role, $rewound->history),
            'the earlier pair, then the /rewind echo and its answer — the reminder went with its prompt',
        );
        $this->assertSame(self::PROMPT, $rewound->inputBuf);
        $this->assertSame(4, $rewound->inputCursorOffset(), 'the caret still round-trips');
        $this->assertStringContainsString('Rewound 2 messages', $rewound->history[3]->content);
    }

    /**
     * The legacy trim must not misfire on a CURRENT checkpoint: sending the same
     * prompt twice in a row leaves the first copy as the last user row of the
     * second turn's pre-turn transcript, and that copy is a real earlier turn.
     */
    public function testACurrentCheckpointKeepsAnEarlierTurnThatSentTheSamePrompt(): void
    {
        $chat = $this->chat([], $this->recordingBackend());

        $once = $this->sendAndSettle($chat, 'try again', 'tried once');
        $twice = $this->sendAndSettle($once, 'try again', 'tried twice');

        [$rewound] = $this->type($twice, '/rewind')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(
            ['try again', 'tried once'],
            array_map(static fn(Message $m): string => $m->content, array_slice($rewound->history, 0, 2)),
            'only the second turn is undone',
        );
        $this->assertSame(1, $this->countUserRows($rewound->history, 'try again'));
        $this->assertSame('try again', $rewound->inputBuf);
        $this->assertStringContainsString('Rewound 2 messages to checkpoint 1.', $rewound->history[3]->content);
    }

    /**
     * `/rewind 2` reaches the checkpoint before the earlier prompt, with that
     * prompt as the draft, and neither prompt left in the transcript.
     */
    public function testRewindingTwoStepsRestoresTheStateBeforeTheEarlierPrompt(): void
    {
        $chat = $this->chat([], $this->recordingBackend());
        $settled = $this->sendAndSettle($this->sendAndSettle($chat, 'first ask', 'first reply'), 'second ask', 'second reply');

        [$rewound] = $this->type($settled, '/rewind 2')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame('first ask', $rewound->inputBuf);
        $this->assertSame(0, $this->countUserRows($rewound->history, 'first ask'));
        $this->assertSame(0, $this->countUserRows($rewound->history, 'second ask'));
        $this->assertCount(2, $rewound->history, 'only the /rewind echo and its answer');
        $this->assertStringContainsString('Rewound 4 messages to checkpoint 0.', $rewound->history[1]->content);
    }

    /**
     * The synchronous 85% tier: the checkpoint keeps the compaction the
     * submission triggered — and the report that explains it — while dropping
     * the hook note and the prompt the submission itself added.
     */
    public function testTheHeuristicTierCheckpointKeepsTheCompactionAndDropsTheSubmission(): void
    {
        $main = $this->recordingBackend();
        $chat = $this->chat(self::compactablePairs(), $main, hookNote: self::NOTE);

        $settled = $this->sendAndSettle($chat, self::PROMPT, 'patched');
        $this->assertSame(1, $this->countSaying($settled->history, 'Context reached the automatic-compaction tier'), 'fixture: the tier compacted');

        [$rewound] = $this->type($settled, '/rewind')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(0, $this->countUserRows($rewound->history, self::PROMPT));
        $this->assertSame(0, $this->countSaying($rewound->history, self::NOTE), 'the hook note is regenerated on resend, so it goes');
        $this->assertSame(
            1,
            $this->countSaying($rewound->history, 'Context reached the automatic-compaction tier'),
            'the compaction stays, with its report',
        );
        $this->assertSame(self::PROMPT, $rewound->inputBuf);
        $this->assertMatchesRegularExpression('/Rewound [1-9]\d* messages/', $rewound->history[count($rewound->history) - 1]->content);
    }

    /**
     * The 85% tier's PARKED route checkpoints at the landing, one update() after
     * the draft was consumed. Its draft must be the parked prompt — not the
     * scratch text typed while the summarization was out — and its transcript
     * must drop the echoed prompt and the hook note written beside it while
     * keeping the park notice and the landing report.
     */
    public function testTheParkedRouteCheckpointsThePromptAsTheDraftAndNotInTheTranscript(): void
    {
        $main = $this->recordingBackend();
        $chat = $this->chat(self::compactablePairs(), $main, $this->summarizer(), self::NOTE);

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($parked->inFlight, 'fixture: the prompt parked');
        $this->assertSame([], $this->store->listCheckpoints(self::SESSION), 'parking is not dispatching');

        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        // Scratch typed while parked: the old landing checkpointed THIS as the draft.
        $typing = $this->type($parked, 'scratch');
        [, $turnCmd] = $typing->update($msg);
        $this->assertNotNull($turnCmd, 'fixture: the landing dispatched');

        $checkpoints = $this->store->listCheckpoints(self::SESSION);
        $this->assertCount(1, $checkpoints);
        $state = $checkpoints[0]['state_data'];
        $this->assertSame(self::PROMPT, $state['inputBuf'], 'the parked prompt is the draft rewind re-seeds');
        $contents = array_map(static fn(array $m): string => (string) $m['content'], $state['messages']);
        $this->assertNotContains(self::PROMPT, $contents, 'the echoed prompt is not in the pre-turn transcript');
        $this->assertSame([], array_values(array_filter($contents, static fn(string $c): bool => str_contains($c, self::NOTE))), 'nor its hook note');
        $this->assertNotEmpty(
            array_filter($contents, static fn(string $c): bool => str_starts_with($c, 'Context reached the automatic-compaction tier at ~')),
            'the park notice stays: it describes the compaction the checkpoint keeps',
        );
        $this->assertNotEmpty(
            array_filter($contents, static fn(string $c): bool => str_starts_with($c, 'Context reached the automatic-compaction tier, so')),
            'and so does the landing report',
        );
    }

    // =====================================================================
    // helpers
    // =====================================================================

    /** @param list<Message> $history */
    private function chat(array $history, Backend $main, ?Backend $summarizer = null, ?string $hookNote = null): Chat
    {
        $hooks = null;
        if ($hookNote !== null) {
            $registry = new HookRegistry();
            $registry->register($this->noteHook($hookNote));
            $hooks = new HookManager($registry);
        }

        return new Chat(
            history: $history,
            inputBuf: self::PROMPT,
            backend: $main,
            summaryBackend: $summarizer,
            sessionStore: $this->store,
            currentSessionId: self::SESSION,
            // Named, so no title call is batched beside the turn and the Cmd a
            // dispatch returns is the completion alone.
            currentSessionName: 'rewind-test',
            hooks: $hooks,
        );
    }

    /** Submit $text from an empty box and land the reply it is answered with. */
    private function sendAndSettle(Chat $chat, string $text, string $reply): Chat
    {
        [$sent, $cmd] = $this->type($this->clearDraft($chat), $text)->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($sent->inFlight, "fixture: '{$text}' dispatched");
        $this->assertInstanceOf(\Closure::class, $cmd);
        [$settled] = $sent->update(new AssistantMsg(Message::assistant($reply)));
        $this->assertFalse($settled->inFlight);

        return $settled;
    }

    private function clearDraft(Chat $chat): Chat
    {
        while ($chat->inputBuf !== '') {
            [$chat] = $chat->update(new KeyMsg(KeyType::Backspace, ''));
        }

        return $chat;
    }

    private function type(Chat $chat, string $text): Chat
    {
        foreach (mb_str_split($text) as $rune) {
            [$chat] = $chat->update(new KeyMsg($rune === ' ' ? KeyType::Space : KeyType::Char, $rune));
        }

        return $chat;
    }

    private function runCmd(\Closure $cmd): void
    {
        $asyncCmd = $cmd();
        $this->assertInstanceOf(AsyncCmd::class, $asyncCmd);
    }

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

    /** @param list<Message> $history */
    private function countUserRows(array $history, string $text): int
    {
        return count(array_filter(
            $history,
            static fn(Message $m): bool => $m->role === Role::User && $m->content === $text,
        ));
    }

    /** @param list<Message> $history */
    private function countSaying(array $history, string $text): int
    {
        return count(array_filter(
            $history,
            static fn(Message $m): bool => str_contains((string) $m->content, $text),
        ));
    }

    /**
     * ~78k estimated tokens of an 88k window: past the 85% tier, comfortably
     * under 95% once the heavy exchanges are condensed (the shape
     * {@see ParkedCompactionHookTest} parks on).
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

    private function noteHook(string $note): HookInterface
    {
        return new class($note) implements HookInterface {
            public function __construct(private readonly string $note) {}

            public function name(): string
            {
                return 'note';
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
                return HookResult::allow('', $this->note);
            }
        };
    }

    private function recordingBackend(): Backend
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

    /** A summarizer with a record for every exchange the fixture offers. */
    private function summarizer(): Backend
    {
        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = "{$i}.\nasked: condensed exchange {$i}";
        }

        return new class(implode("\n", $records)) implements Backend {
            public function __construct(private readonly string $reply) {}

            public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
            {
                return Message::assistant($this->reply);
            }

            public function completeAsync(
                array $history,
                ?callable $onToken = null,
                ?CancellationToken $cancellation = null,
                ?callable $onEvent = null,
            ): PromiseInterface {
                return \React\Promise\resolve(Message::assistant($this->reply));
            }
        };
    }
}
