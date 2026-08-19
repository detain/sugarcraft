<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\AssistantMsg;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\EchoBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\PermissionReply;
use SugarCraft\Crush\PermissionRequestMsg;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\SessionStore;
use SugarCraft\Crush\ToolCall;

/**
 * W2: typing, sending and browsing while a turn is in flight.
 *
 * THE REPORT, verbatim: *"when i send a chat message and its processing the
 * request im unable to type new text into the chat . im alaso unable to use
 * things like Ctrl-P to bring up the command pallete .. processsing the request
 * should not block the input like that... new messages should be typable and
 * sendable (well really queued for processing if its mid processing the previous
 * message) during that time"*.
 *
 * NOT an async defect, which is worth stating because it decides what these tests
 * measure. `Backend\EngineBackend::completeAsync()` already runs the turn in a
 * forked child and the loop was already delivering the keystrokes; `Chat::update()`
 * opened its mid-turn block with a blanket `return [$this, null];` and dropped
 * them on purpose. So every test here drives a real `KeyMsg` through the real
 * `update()` entry point — a test that reached `TextArea` or `submit()` directly
 * would have passed against the bug.
 *
 * The policy under test, in one place:
 *
 *   * ordinary prompts QUEUE (FIFO) and are dispatched when the turn ends;
 *   * `/`-prefixed drafts are REFUSED with a visible `Role::System` notice;
 *   * bare `/exit`/`/quit` still quit;
 *   * the palette and the session picker OPEN and BROWSE, but their dispatch
 *     arms are refused (Exit excepted);
 *   * the double-Escape cancel HOLDS the queue rather than sending or dropping it.
 */
final class InFlightInputQueueTest extends TestCase
{
    private function generationOf(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'generation'))->getValue($chat);
    }

    private function cancellationOf(Chat $chat): ?CancellationToken
    {
        return (new \ReflectionProperty(Chat::class, 'inFlightCancellation'))->getValue($chat);
    }

    private function withDraft(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    /** A live turn, started the way the user starts one: a draft plus Enter. */
    private function inFlight(string $prompt = 'the first thing'): Chat
    {
        [$chat] = (new Chat(inputBuf: $prompt, backend: new EchoBackend()))
            ->update(new KeyMsg(KeyType::Enter, ''));
        self::assertTrue($chat->inFlight, 'fixture: a turn must actually be in flight');

        return $chat;
    }

    private function lastOf(Chat $chat): Message
    {
        $history = $chat->history;
        self::assertNotSame([], $history, 'fixture: the transcript cannot be empty here');

        return $history[count($history) - 1];
    }

    // =====================================================================
    // 1. The keyboard reaches the app again
    // =====================================================================

    /**
     * The first half of the report. Driven one keystroke at a time, and asserted
     * on the accumulated VALUE rather than on "the buffer changed": a routing bug
     * that delivered only the last rune would satisfy the weaker claim.
     */
    public function testTypedRunesAccumulateInTheDraftWhileATurnIsInFlight(): void
    {
        $chat = $this->inFlight();

        foreach (['h', 'e', 'l', 'l', 'o'] as $rune) {
            [$chat] = $chat->update(new KeyMsg(KeyType::Char, $rune));
        }

        $this->assertSame('hello', $chat->inputBuf, 'every rune reached the draft, in order');
        $this->assertSame(5, $chat->inputCursorOffset(), 'and the cursor tracked them');
        $this->assertTrue($chat->inFlight, 'without ending the turn that is running');
        $this->assertSame([], $chat->queuedPrompts(), 'typing alone queues nothing');
    }

    /**
     * The second half of the report, named in it explicitly ("im alaso unable to
     * use things like Ctrl-P").
     *
     * Asserted through the palette's own STATE and then through a second
     * keystroke reaching it, not merely on `palette() !== null`: an overlay that
     * opened but whose keys still fell into the input box would be the same bug
     * one layer down.
     */
    public function testCtrlPOpensAndDrivesThePaletteWhileATurnIsInFlight(): void
    {
        [$open] = $this->inFlight()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));

        $this->assertNotNull($open->palette(), 'Ctrl+P opens the palette mid-turn');
        $this->assertSame('', $open->palette()->query);

        [$filtered] = $open->update(new KeyMsg(KeyType::Char, 'e'));
        $this->assertSame('e', $filtered->palette()->query, 'and a rune filters the palette rather than typing into the draft');
        $this->assertSame('', $filtered->inputBuf, 'the draft is untouched while the overlay owns the keyboard');

        [$moved] = $open->update(new KeyMsg(KeyType::Down, ''));
        $this->assertSame(1, $moved->palette()->selectedIndex, 'and Down navigates it');
    }

    /**
     * The input caret. It was hidden for the whole turn (`$chat->inFlight ? ''
     * : '█'`), which was honest while nothing could be typed and became the
     * visible half of the bug the moment typing worked.
     *
     * Asserted on the ROW carrying the draft, so a caret painted somewhere else
     * in the frame cannot satisfy it.
     */
    public function testTheCaretIsPaintedOnTheDraftRowWhileATurnIsInFlight(): void
    {
        [$typed] = $this->inFlight()->update(new KeyMsg(KeyType::Char, 'q'));
        $frame = Renderer::render((new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($typed, ['rows' => 24, 'cols' => 100]));

        $draftRow = null;
        foreach (explode("\n", $frame) as $row) {
            if (str_contains($row, '> q')) {
                $draftRow = $row;
                break;
            }
        }

        $this->assertNotNull($draftRow, 'the mid-turn draft must be painted');
        $this->assertStringContainsString('> q█', $draftRow, 'with the caret after the rune just typed');
    }

    // =====================================================================
    // 2. Enter queues, and the queue is visible
    // =====================================================================

    /**
     * The queue itself. Four properties, because three of them can hold while the
     * fourth is broken: the text is on the queue, the draft is CONSUMED (a send
     * that left the line in the box would read as a send that failed), NOTHING is
     * dispatched, and the transcript says so.
     *
     * The notice's ROLE is asserted, not just its text. `Role::Assistant` after
     * the running turn's user message is a PREFILL the provider continues rather
     * than an instruction it reads (`EngineBackend::toTypedMessages()` maps it to
     * an AssistantMessage; VertexProvider's Anthropic path renders it as an
     * `assistant` turn), so a notice with the wrong role would corrupt the very
     * reply that is in flight.
     */
    public function testEnterQueuesThePromptAndSaysSoInTheTranscript(): void
    {
        $chat = $this->withDraft($this->inFlight(), 'and also this');
        $before = count($chat->history);

        [$queued, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertSame(['and also this'], $queued->queuedPrompts(), 'the prompt is held');
        $this->assertSame('', $queued->inputBuf, 'and the box empties, exactly as a real send empties it');
        $this->assertNull($cmd, 'nothing was dispatched');
        $this->assertTrue($queued->inFlight, 'and the running turn is untouched');

        $this->assertCount($before + 1, $queued->history, 'exactly one notice was added');
        $notice = $this->lastOf($queued);
        $this->assertSame(Role::System, $notice->role, 'a notice after the running prompt must not be a prefill');
        $this->assertStringContainsString('and also this', $notice->content, 'the notice quotes the queued message');
        $this->assertStringContainsString('Queued', $notice->content);
        $this->assertSame(
            0,
            count(array_filter($queued->history, static fn(Message $m): bool => $m->role === Role::User && $m->content === 'and also this')),
            'and it is NOT echoed as a second user turn, which would attach the running reply to the wrong prompt',
        );
    }

    /**
     * Visibility that survives scrollback: the transcript notice scrolls away,
     * the status bar does not.
     *
     * Asserted on the COUNT, against two different queue depths, so a hardcoded
     * string cannot pass — and asserted absent at depth zero, so the segment
     * cannot simply always be there.
     */
    public function testTheStatusBarCountsTheQueue(): void
    {
        $sized = static fn(Chat $chat): string => (static function (string $frame): string {
            $rows = explode("\n", $frame);

            return (string) preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($rows));
        })(Renderer::render($chat));

        $base = new Chat(history: [Message::user('hi')], inFlight: true, rows: 24, cols: 120);

        $this->assertStringNotContainsString('queued', $sized($base), 'no segment when nothing is queued');
        $this->assertStringContainsString(
            '1 queued',
            $sized(new Chat(history: [Message::user('hi')], inFlight: true, rows: 24, cols: 120, queuedPrompts: ['a'])),
        );
        $this->assertStringContainsString(
            '3 queued',
            $sized(new Chat(history: [Message::user('hi')], inFlight: true, rows: 24, cols: 120, queuedPrompts: ['a', 'b', 'c'])),
        );
    }

    /**
     * The queue segment must never deepen an over-run that already exists.
     *
     * Measured before the fit gate went in: the in-flight status bar is 36 columns
     * on a two-message fixture with no queue and 47 with one, and the too-small cue
     * only replaces the bar at `rows <= 4` or `cols <= 4` — so an unconditional
     * append widened the range of widths at which this un-wrappable row over-runs
     * the frame from `cols <= 35` to `cols <= 46`. The pre-existing over-run is a
     * separate finding; making it worse is this bundle's business.
     *
     * The invariant is stated as a COMPARISON against the same frame without a
     * queue rather than as "the bar fits", because the bar does not fit at every
     * width and asserting that it does would fail on the pre-existing defect. And
     * the second half is what stops the gate from passing vacuously: the segment
     * really is emitted once there is room for it.
     */
    public function testTheQueueSegmentNeverWidensTheBarPastTheTerminal(): void
    {
        $widest = static function (int $cols, array $queue): int {
            $frame = Renderer::render(new Chat(
                history: [Message::user('hi'), Message::assistant('there')],
                inFlight: true,
                rows: 24,
                cols: $cols,
                queuedPrompts: $queue,
            ));
            $worst = 0;
            foreach (explode("\n", $frame) as $row) {
                $worst = max($worst, Width::of((string) preg_replace('/\x1b\[[0-9;]*m/', '', $row)));
            }

            return $worst;
        };

        foreach ([5, 10, 20, 30, 34, 35, 36, 40, 46, 47, 48, 60, 80, 120, 200] as $cols) {
            $without = $widest($cols, []);
            foreach ([['a'], ['a', 'b', 'c'], array_fill(0, 12, 'x')] as $queue) {
                $with = $widest($cols, $queue);
                $this->assertLessThanOrEqual(
                    max($without, $cols),
                    $with,
                    sprintf(
                        'at %d columns a queue of %d widened the frame to %d, past both the %d columns it '
                        . 'occupies with no queue and the terminal itself',
                        $cols,
                        count($queue),
                        $with,
                        $without,
                    ),
                );
            }
        }

        $bar = static function (int $cols, array $queue): string {
            $rows = explode("\n", Renderer::render(new Chat(
                history: [Message::user('hi'), Message::assistant('there')],
                inFlight: true,
                rows: 24,
                cols: $cols,
                queuedPrompts: $queue,
            )));

            return (string) preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($rows));
        };

        $this->assertStringContainsString('1 queued', $bar(120, ['a']), 'the gate must not be vacuous');
        $this->assertStringNotContainsString('queued', $bar(20, ['a']), 'and it must actually drop the segment when there is no room');
    }

    // =====================================================================
    // 3. The drain, and that it really went through the turn-start path
    // =====================================================================

    /**
     * The settle. A null Cmd here is the whole failure mode: `update()`'s
     * AssistantMsg exit returned one, and that is where the drained turn's Cmd
     * had to go.
     */
    public function testTheQueuedPromptIsDispatchedWhenTheTurnSettles(): void
    {
        [$queued] = $this->withDraft($this->inFlight(), 'the second thing')
            ->update(new KeyMsg(KeyType::Enter, ''));

        [$settled, $cmd] = $queued->update(new AssistantMsg(Message::assistant('the first answer')));

        $this->assertNotNull($cmd, 'the drained turn must come back with a Cmd, or nothing ever sends it');
        $this->assertSame([], $settled->queuedPrompts(), 'the queue is empty');
        $this->assertTrue($settled->inFlight, 'because the queued prompt is now the turn in flight');
        $this->assertSame(
            'the second thing',
            $this->lastOf($settled)->content,
            'and the queued prompt is the newest thing on the wire',
        );
        $this->assertSame(Role::User, $this->lastOf($settled)->role, 'as a real user turn this time');
    }

    /**
     * That the drain went through {@see Chat::dispatchTurn()} — the ONE turn-start
     * path — rather than through a copy of it. Its docblock warns that a third
     * copy is where the generation stamp, the cancellation token, the checkpoint
     * or the title Cmd goes missing, and none of those omissions is visible to a
     * test that only asserts a Cmd came back.
     *
     * Asserted on VALUES, not on "a stamp exists":
     *
     *   * the generation is the parked turn's PLUS ONE, proved by feeding back an
     *     AssistantMsg carrying exactly that number and watching it be ACCEPTED —
     *     a stamp left unchanged, or bumped by two, fails this;
     *   * the OLD generation is now stale, proved by feeding one back and watching
     *     it be DROPPED;
     *   * the token is a fresh instance, not the settled turn's, and it is not
     *     already cancelled.
     */
    public function testTheDrainedPromptCarriesItsOwnGenerationAndCancellationToken(): void
    {
        $started = $this->inFlight();
        $firstGeneration = $this->generationOf($started);
        $firstToken = $this->cancellationOf($started);
        $this->assertNotNull($firstToken, 'fixture: the running turn has a token');

        [$queued] = $this->withDraft($started, 'the second thing')->update(new KeyMsg(KeyType::Enter, ''));
        [$settled] = $queued->update(new AssistantMsg(Message::assistant('the first answer'), generation: $firstGeneration));

        $this->assertSame(
            $firstGeneration + 1,
            $this->generationOf($settled),
            'the drained turn consumed exactly one generation, the way submit() does',
        );

        $token = $this->cancellationOf($settled);
        $this->assertInstanceOf(CancellationToken::class, $token, 'the drained turn is cancellable');
        $this->assertNotSame($firstToken, $token, 'with its OWN token, not the settled turn(s)');
        $this->assertFalse($token->isCancelled(), 'and it starts uncancelled');

        // The stamp, proved from both sides.
        $committed = count($settled->history);
        [$stale] = $settled->update(new AssistantMsg(Message::assistant('a reply for the OLD turn'), generation: $firstGeneration));
        $this->assertCount($committed, $stale->history, 'a reply stamped with the previous generation is stale and dropped');

        [$fresh] = $settled->update(new AssistantMsg(Message::assistant('the second answer'), generation: $firstGeneration + 1));
        $this->assertCount($committed + 1, $fresh->history, 'a reply stamped with the drained turn(s) generation lands');
        $this->assertSame('the second answer', $this->lastOf($fresh)->content);
        $this->assertFalse($fresh->inFlight, 'and settles the drained turn');
    }

    /**
     * FIFO, and the whole queue eventually goes out. Two entries, two settles,
     * in the order they were typed.
     *
     * The ORDER is what a single-slot implementation or a stack would get wrong
     * while still passing "the queue empties".
     */
    public function testTheQueueIsFifoAndDrainsOneTurnPerSettle(): void
    {
        $chat = $this->inFlight();
        foreach (['second', 'third'] as $text) {
            [$chat] = $this->withDraft($chat, $text)->update(new KeyMsg(KeyType::Enter, ''));
        }
        $this->assertSame(['second', 'third'], $chat->queuedPrompts(), 'queued in typing order');

        [$one, $cmdOne] = $chat->update(new AssistantMsg(Message::assistant('answer one')));
        $this->assertNotNull($cmdOne);
        $this->assertSame('second', $this->lastOf($one)->content, 'the OLDEST queued prompt goes first');
        $this->assertSame(['third'], $one->queuedPrompts(), 'and the rest wait for the turn it just started');

        [$two, $cmdTwo] = $one->update(new AssistantMsg(Message::assistant('answer two')));
        $this->assertNotNull($cmdTwo);
        $this->assertSame('third', $this->lastOf($two)->content);
        $this->assertSame([], $two->queuedPrompts());
    }

    /**
     * A drain must not eat the draft the user has typed since. Seeding the queued
     * text through `inputBuf` is what lets the drain reuse the real turn-start
     * path, and `dispatchTurn()` blanks `inputBuf` on its way out — so the widget
     * has to be put back, cursor column included.
     */
    public function testADrainRestoresTheDraftTheUserWasTypingIncludingTheCursor(): void
    {
        [$queued] = $this->withDraft($this->inFlight(), 'the second thing')
            ->update(new KeyMsg(KeyType::Enter, ''));

        $typing = $queued;
        foreach (['a', 'b', 'c', 'd'] as $rune) {
            [$typing] = $typing->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$typing] = $typing->update(new KeyMsg(KeyType::Left, ''));
        $this->assertSame('abcd', $typing->inputBuf, 'fixture: a live draft');
        $this->assertSame(3, $typing->inputCursorOffset(), 'fixture: with the cursor moved off the end');

        [$settled] = $typing->update(new AssistantMsg(Message::assistant('the first answer')));

        $this->assertSame('abcd', $settled->inputBuf, 'the half-typed draft survived the drain');
        $this->assertSame(3, $settled->inputCursorOffset(), 'and so did the cursor column');
    }

    // =====================================================================
    // 4. The subtle one: a tool-calling turn is not over
    // =====================================================================

    /**
     * THE SUBTLE ONE. `finishToolCalls()` writes `'inFlight' => true`, so a turn
     * that called tools keeps running and settles at a LATER AssistantMsg. A drain
     * hung off any AssistantMsg would therefore fire BETWEEN two tool steps —
     * sending the queued prompt into the middle of an agentic loop.
     *
     * Driven in both directions, because "does not drain" alone is also satisfied
     * by a drain that never happens at all: the tool-call reply must NOT drain,
     * and the reply after it MUST.
     */
    public function testAToolCallingReplyDoesNotDrainTheQueueButTheReplyAfterItDoes(): void
    {
        $chat = (new Chat(history: [Message::user('list files')], inFlight: true, backend: new EchoBackend()))
            ->registerTool('ls', static fn(array $args): string => 'a.txt');
        [$queued] = $this->withDraft($chat, 'and then summarise it')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertSame(['and then summarise it'], $queued->queuedPrompts(), 'fixture: something is queued');

        $withCalls = Message::assistant('let me look')->withToolCalls([new ToolCall('ls', [], 'call_1')]);
        [$mid] = $queued->update(new AssistantMsg($withCalls));

        $this->assertTrue($mid->inFlight, 'the tool-calling turn is still running');
        $this->assertSame(
            ['and then summarise it'],
            $mid->queuedPrompts(),
            'so the queue must NOT have drained - that would send the prompt mid-loop',
        );
        $this->assertSame(
            0,
            count(array_filter($mid->history, static fn(Message $m): bool => $m->role === Role::User && $m->content === 'and then summarise it')),
            'and the queued prompt is nowhere on the wire yet',
        );

        // The same turn, now answering without asking for another call.
        [$done, $cmd] = $mid->update(new AssistantMsg(Message::assistant('one file, a.txt')));

        $this->assertNotNull($cmd, 'the reply that really ends the turn drains the queue');
        $this->assertSame([], $done->queuedPrompts());
        $this->assertSame('and then summarise it', $this->lastOf($done)->content);
        $this->assertSame(Role::User, $this->lastOf($done)->role);
    }

    // =====================================================================
    // 5. Cancel HOLDS the queue
    // =====================================================================

    /**
     * Double-Escape keeps meaning "cancel the running turn", and the queue is
     * neither sent nor dropped. The user asked to stop the turn that is RUNNING,
     * which says nothing about a message they typed deliberately while it ran;
     * dispatching it here would send the one thing they may have been trying to
     * stop, and dropping it would destroy their text silently.
     *
     * All three outcomes are asserted, because "not dispatched" alone is also
     * satisfied by "dropped".
     */
    public function testADoubleEscapeCancelHoldsTheQueueRatherThanSendingOrDroppingIt(): void
    {
        [$queued] = $this->withDraft($this->inFlight(), 'the second thing')
            ->update(new KeyMsg(KeyType::Enter, ''));
        $token = $this->cancellationOf($queued);

        [$once] = $queued->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled, $cmd] = $once->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertFalse($cancelled->inFlight, 'the running turn died');
        $this->assertTrue($token?->isCancelled(), 'and its token was flipped');
        $this->assertNull($cmd, 'so nothing new was dispatched');
        $this->assertSame(['the second thing'], $cancelled->queuedPrompts(), 'the queued message is still queued');
        $this->assertSame(
            0,
            count(array_filter($cancelled->history, static fn(Message $m): bool => $m->role === Role::User && $m->content === 'the second thing')),
            'and it did not go out',
        );
        $this->assertStringContainsString('cancelled', $this->lastOf($cancelled)->content);
    }

    /**
     * The held queue is not stranded: it goes out at the NEXT turn's settle. That
     * is the residual of the decision above, and it is asserted rather than left
     * as prose because "still queued" is only acceptable if it is also "still
     * reachable".
     */
    public function testAQueueHeldThroughACancelGoesOutOnTheNextSettle(): void
    {
        [$queued] = $this->withDraft($this->inFlight(), 'the held one')
            ->update(new KeyMsg(KeyType::Enter, ''));
        [$once] = $queued->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $once->update(new KeyMsg(KeyType::Escape, ''));

        [$restarted] = $this->withDraft($cancelled, 'something else entirely')->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertTrue($restarted->inFlight, 'fixture: an idle submit dispatches immediately');
        $this->assertSame(['the held one'], $restarted->queuedPrompts(), 'and does not disturb the held queue');

        [$settled, $cmd] = $restarted->update(new AssistantMsg(Message::assistant('a reply')));
        $this->assertNotNull($cmd);
        $this->assertSame([], $settled->queuedPrompts(), 'the held message is released');
        $this->assertSame('the held one', $this->lastOf($settled)->content);
    }

    // =====================================================================
    // 6. Unsafe commands and overlay actions are refused, VISIBLY
    // =====================================================================

    /**
     * The refusal, asserted on the notice TEXT and on the transcript being intact
     * byte for byte. A silent no-op would satisfy "history was not rewritten",
     * which is precisely the failure mode this bundle exists to remove.
     */
    public function testAHistoryMutatingCommandIsRefusedWithANoticeNamingIt(): void
    {
        $chat = $this->inFlight();
        $before = $chat->history;

        [$refused, $cmd] = $this->withDraft($chat, '/clear')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertTrue($refused->inFlight, 'the running turn survives');
        $this->assertCount(count($before) + 1, $refused->history, 'one notice, and nothing else changed');
        foreach ($before as $i => $original) {
            $this->assertSame($original->content, $refused->history[$i]->content, "history[{$i}] byte for byte");
        }

        $notice = $this->lastOf($refused);
        $this->assertSame(Role::System, $notice->role);
        $this->assertStringContainsString('/clear', $notice->content, 'the notice names the command');
        $this->assertStringContainsString('in flight', $notice->content, 'and why it was refused');
        $this->assertStringContainsString('Esc Esc', $notice->content, 'and what to do about it');
        $this->assertSame('/clear', $refused->inputBuf, 'the draft is kept, so nothing is lost');
        $this->assertSame([], $refused->queuedPrompts(), 'and a command is never silently queued for later');
    }

    /**
     * The rule that decides refused-vs-queued, swept over every command the
     * registry advertises rather than over a list this test keeps its own copy of.
     * A registry row added tomorrow is covered the day it lands.
     *
     * `/exit` and `/quit` are the documented exceptions: they end the process, so
     * there is no state left for them to corrupt, and Ctrl+C already quits
     * mid-turn. They are asserted to come back WITH a Cmd, which is the property a
     * blanket refusal would break.
     */
    public function testEverySlashCommandIsRefusedMidTurnExceptTheTwoThatQuit(): void
    {
        $names = [];
        foreach (CommandRegistry::all() as $spec) {
            if ($spec->slashVisible) {
                $names[] = $spec->name;
            }
        }
        $this->assertGreaterThan(5, count($names), 'fixture: the registry must actually list commands');

        foreach ($names as $name) {
            $chat = $this->inFlight();
            $before = count($chat->history);
            [$after, $cmd] = $this->withDraft($chat, '/' . $name)->update(new KeyMsg(KeyType::Enter, ''));

            if ($name === 'exit' || $name === 'quit') {
                $this->assertNotNull($cmd, "/{$name} must still quit mid-turn");
                $this->assertSame($before, count($after->history), "/{$name} says nothing, it just goes");
                continue;
            }

            $this->assertNull($cmd, "/{$name} must not dispatch mid-turn");
            $this->assertSame([], $after->queuedPrompts(), "/{$name} must not be queued either");
            $this->assertSame($before + 1, count($after->history), "/{$name} must answer with exactly one notice");
            $this->assertSame(Role::System, $after->history[$before]->role, "/{$name}'s notice must not be a prefill");
            $this->assertStringContainsString('/' . $name, $after->history[$before]->content, "/{$name}'s notice must name it");
            $this->assertTrue($after->inFlight, "/{$name} must leave the running turn alone");
        }
    }

    /**
     * Ctrl+A is the other route into `submit()` — its arm is
     * `withInputBuf('/agents')->submit()`, so left alone it would both DESTROY the
     * draft and dispatch a command. Intercepted ahead of its arm, so the draft
     * never moves.
     */
    public function testCtrlAIsRefusedMidTurnWithoutTouchingTheDraft(): void
    {
        $chat = $this->withDraft($this->inFlight(), 'a draft worth keeping');

        [$after, $cmd] = $chat->update(new KeyMsg(KeyType::Char, 'a', ctrl: true));

        $this->assertNull($cmd);
        $this->assertSame('a draft worth keeping', $after->inputBuf, 'the draft was NOT replaced by /agents');
        $this->assertStringContainsString('/agents', $this->lastOf($after)->content, 'and the refusal names what it refused');
        $this->assertSame(Role::System, $this->lastOf($after)->role);
    }

    /**
     * Ctrl+Tab adopts another session's history and id wholesale, which is the
     * running turn's transcript replaced under it.
     */
    public function testCtrlTabIsRefusedMidTurn(): void
    {
        $chat = $this->inFlight();
        $before = $chat->history;

        [$after] = $chat->update(new KeyMsg(KeyType::Tab, '', ctrl: true));

        $this->assertCount(count($before) + 1, $after->history);
        $this->assertStringContainsString('Switch session', $this->lastOf($after)->content);
        $this->assertSame($before[0]->content, $after->history[0]->content, 'the transcript was not swapped');
    }

    /**
     * The palette OPENS mid-turn (that was half the report) but does not DISPATCH.
     * Every root action other than Exit delegates to a handler that writes
     * `inFlight`, wipes history, or swaps the backend the running agentic loop is
     * about to make its next call on.
     *
     * `?` is the deliberate silent exception among the mid-turn keys and is
     * covered separately below, so this test is about the palette only.
     */
    public function testAPaletteActionIsRefusedMidTurnAndTheOverlayCloses(): void
    {
        [$open] = $this->inFlight()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $before = count($open->history);

        [$acted, $cmd] = $open->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd, 'the action dispatched nothing');
        $this->assertNull($acted->palette(), 'the overlay closes, so the notice is not hidden behind it');
        $this->assertCount($before + 1, $acted->history);
        $this->assertSame(Role::System, $this->lastOf($acted)->role);
        $this->assertStringContainsString('in flight', $this->lastOf($acted)->content);
        $this->assertTrue($acted->inFlight, 'and the running turn is untouched');
    }

    /**
     * Exit is the palette's one live row mid-turn, for the same reason bare
     * `/exit` is: it ends the process. Asserted on the Cmd AND on the transcript
     * staying silent, because a refusal that happened to also return `Cmd::quit()`
     * is not what this claims.
     */
    public function testThePaletteExitRowStillQuitsMidTurn(): void
    {
        [$open] = $this->inFlight()->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $before = count($open->history);

        $filtered = $open;
        foreach (['e', 'x', 'i', 't'] as $rune) {
            [$filtered] = $filtered->update(new KeyMsg(KeyType::Char, $rune));
        }
        $matches = (new \ReflectionMethod(Chat::class, 'paletteMatches'))->invoke($filtered);
        $this->assertSame('Exit', $matches[0] ?? null, 'fixture: Exit must be the highlighted row');

        [$after, $cmd] = $filtered->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNotNull($cmd, 'Exit still quits mid-turn');
        $this->assertSame($before, count($after->history), 'and says nothing on its way out');
    }

    /**
     * `?` is the one mid-turn key that is refused SILENTLY, and that is a
     * preserved invariant rather than an oversight: the keybinding reference is
     * checked ABOVE the permission prompt in `update()`, and the pair "reference
     * up over a live prompt" is asserted unreachable by real input in
     * `Renderer\KeyHelpTest`. A prompt only ever exists mid-turn, so opening the
     * reference mid-turn would make that pair reachable.
     *
     * Asserted as a total no-op — no modal, no notice, no rune in the draft —
     * because each of those three is a different way of getting it wrong.
     */
    public function testAQuestionMarkOnABlankDraftStaysInertMidTurn(): void
    {
        $chat = $this->inFlight();

        [$after] = $chat->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertNull($after->keyHelp(), 'the reference must not open mid-turn');
        $this->assertSame('', $after->inputBuf, 'and the rune is not typed either');
        $this->assertCount(count($chat->history), $after->history, 'and no notice is written for a key that never did anything here');
    }

    /**
     * The `?` guard is on a BLANK draft only, exactly as it is when idle: with
     * something in the box `?` is an ordinary character and must type.
     */
    public function testAQuestionMarkTypesMidTurnOnceTheDraftIsNotBlank(): void
    {
        [$after] = $this->withDraft($this->inFlight(), 'why')->update(new KeyMsg(KeyType::Char, '?'));

        $this->assertSame('why?', $after->inputBuf, 'a question mark after text is just a character');
        $this->assertNull($after->keyHelp());
    }

    /**
     * The leading-slash-less `mcp auth …` spelling {@see Chat::dispatchCommand()}
     * accepts ahead of the parse. It is the one command form the `/` rule would
     * miss, so it is claimed explicitly and asserted explicitly.
     */
    public function testTheBareMcpAuthSpellingIsRefusedMidTurnToo(): void
    {
        $chat = $this->inFlight();
        $before = count($chat->history);

        [$after, $cmd] = $this->withDraft($chat, 'mcp auth list')->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertSame([], $after->queuedPrompts(), 'and it is not queued as prose either');
        $this->assertCount($before + 1, $after->history);
        $this->assertStringContainsString('mcp auth list', $this->lastOf($after)->content);
        $this->assertSame('mcp auth list', $after->inputBuf, 'draft kept');
    }

    /**
     * The session picker OPENS and BROWSES mid-turn (Ctrl+R), but `resume` adopts
     * another session's history and id wholesale — the running turn's transcript
     * replaced under it — so that one action is refused.
     *
     * Browsing is asserted first, because a picker that refused every key would
     * also pass the refusal half.
     */
    public function testThePickerBrowsesMidTurnButResumingIsRefused(): void
    {
        $store = new SessionStore(':memory:');
        $store->createSession('sess-a', 'sugarcrush', 'test-model', null, 'Alpha');
        $store->createSession('sess-b', 'sugarcrush', 'test-model', null, 'Beta');

        $chat = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke(
            $this->inFlight(),
            ['sessionStore' => $store, 'currentSessionId' => 'sess-a'],
        );

        [$open] = $chat->update(new KeyMsg(KeyType::Char, 'r', ctrl: true));
        $this->assertNotNull($open->sessionPicker(), 'Ctrl+R opens the picker mid-turn');

        [$browsed] = $open->update(new KeyMsg(KeyType::Down, ''));
        $this->assertNotNull($browsed->sessionPicker(), 'and Down browses rather than resuming');
        $this->assertSame('sess-a', $browsed->currentSessionId(), 'browsing switches nothing');

        $before = count($browsed->history);
        [$refused, $cmd] = $browsed->update(new KeyMsg(KeyType::Enter, ''));

        $this->assertNull($cmd);
        $this->assertSame('sess-a', $refused->currentSessionId(), 'the session was NOT switched under the running turn');
        $this->assertNull($refused->sessionPicker(), 'the overlay closes so the notice is visible');
        $this->assertCount($before + 1, $refused->history);
        $this->assertStringContainsString('Resume session', $this->lastOf($refused)->content);
        $this->assertTrue($refused->inFlight);
    }

    // =====================================================================
    // 7. The other drain sites
    // =====================================================================

    /**
     * A permission DENIAL ends the turn with no AssistantMsg to follow it, so a
     * queue released only at `update()`'s settle arm would strand here — and the
     * permission prompt is a mid-turn state by definition, which makes it one of
     * the likelier places for a queue to have accumulated.
     */
    public function testDenyingAPermissionPromptDrainsTheQueue(): void
    {
        $ask = new PermissionRequestMsg(
            Message::assistant('running it')->withToolCalls([new ToolCall('bash', ['command' => 'rm -rf /'], 'call_1')]),
            new ToolCall('bash', ['command' => 'rm -rf /'], 'call_1'),
            'bash wants to run rm -rf /',
        );
        $chat = new Chat(
            history: [Message::user('clean up')],
            inFlight: true,
            backend: new EchoBackend(),
            queuedPrompts: ['never mind, list the files instead'],
        );

        // Raised the way a producer raises it — through update() — rather than
        // seeded on the constructor, so the ask goes through
        // Chat::requestPermission() and this fixture stays the same shape as the
        // other files that build one (see
        // `Renderer\KeyHelpTest::testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg()`,
        // whose census this file joins: the ask here is UNSTAMPED, so no row of
        // `requestPermission()`'s mutation table touches it).
        [$pending] = $chat->update($ask);
        $this->assertNotNull($pending->pendingPermission(), 'fixture: the prompt must be up');
        $this->assertSame(
            ['never mind, list the files instead'],
            $pending->queuedPrompts(),
            'fixture: and the queue must survive the prompt going up',
        );

        [$denied, $cmd] = $pending->update(new \SugarCraft\Crush\PermissionReplyMsg(PermissionReply::Reject));

        $this->assertNotNull($cmd, 'the queued prompt has to be dispatched by something');
        $this->assertSame([], $denied->queuedPrompts(), 'the queue drained rather than stranding');
        $this->assertTrue($denied->inFlight, 'because the queued prompt is now the turn in flight');
        $this->assertSame('never mind, list the files instead', $this->lastOf($denied)->content);
    }

    /**
     * A drained prompt the spend cap refuses goes BACK on the queue rather than
     * vanishing. `spendCapTurnRefusal()` deliberately keeps the draft and writes
     * no `Message::user()` echo, so such a prompt lives nowhere but the input box
     * — and the box is about to be restored to the user's own draft.
     *
     * Asserted on the queue still holding it AND on the status bar still saying
     * so, because "not lost" is only true if it is also "not invisible".
     */
    public function testAQueuedPromptRefusedByTheSpendCapGoesBackOnTheQueue(): void
    {
        $tracker = new \SugarCraft\Crush\Util\TokenTracker();
        $tracker->addUsage(100, 100, 5.0);
        $chat = new Chat(
            history: [Message::user('the first thing')],
            inFlight: true,
            backend: new EchoBackend(),
            tokenTracker: $tracker,
            maxCostUsd: 1.0,
            queuedPrompts: ['the second thing'],
        );

        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant('the first answer')));

        $this->assertNull($cmd, 'a capped session dispatches nothing');
        $this->assertFalse($settled->inFlight, 'and no turn is running');
        $this->assertSame(
            ['the second thing'],
            $settled->queuedPrompts(),
            'the refused prompt is back on the queue, not lost with the restored draft',
        );
        $this->assertSame('', $settled->inputBuf, 'and the draft is the user(s), not the refused prompt');
        $frame = Renderer::render((new \ReflectionMethod(Chat::class, 'mutate'))
            ->invoke($settled, ['rows' => 24, 'cols' => 120]));
        $rows = explode("\n", $frame);
        $this->assertStringContainsString('1 queued', (string) preg_replace('/\x1b\[[0-9;]*m/', '', (string) end($rows)));
    }
}
