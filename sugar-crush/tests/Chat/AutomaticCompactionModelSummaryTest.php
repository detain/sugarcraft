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
use SugarCraft\Crush\Context\IdleCompactionPolicy;
use SugarCraft\Crush\HistoryCompactedMsg;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Usage;
use SugarCraft\Crush\Util\TokenTracker;

/**
 * crush_code.md Phase 5 item 6, on the tier that actually fires in real use.
 *
 * `/compact` typed by hand already asked the model for its summaries
 * ({@see CompactModelSummaryTest}); the automatic 85% tier did not, and compacted
 * synchronously on the local heuristic instead. That is the LOSSIER of the two
 * and the one nobody elects — sessions fill up on their own — so the exchanges
 * replaced by `[exchanged information]` placeholders were precisely the ones no
 * user chose to condense.
 *
 * The tier now parks the submission behind the summarization round-trip. Four
 * properties carry the whole design and each is driven rather than described:
 *
 *  1. **Nothing is rewritten at park time.** The Cmd carries the provider call;
 *     the transcript still holds every original exchange verbatim.
 *  2. **The turn goes out exactly once, and the prompt is echoed exactly once.**
 *     Counted on a recording backend, not read off the transcript.
 *  3. **The dispatched history never ends on an assistant turn.** A trailing
 *     assistant message is a PREFILL the provider continues, not an instruction
 *     it reads.
 *  4. **With no model to ask, the tier is byte-for-byte what it was.** Same
 *     fixture, no summary backend, same synchronous heuristic.
 */
final class AutomaticCompactionModelSummaryTest extends TestCase
{
    /**
     * 13 exchanges, of which THREE carry the weight: those are ~26,000 estimated
     * tokens each and the other ten are two characters apiece, for 78,280
     * estimated tokens in total. Over the 85% tier of the 88,000-token window
     * used throughout (74,800) and, once the older exchanges are condensed, well
     * under the 95% tier (83,600). Borrowed in shape from
     * {@see \SugarCraft\Crush\Tests\Integration\ContextWindowWiringTest}, whose
     * offline-tier assertions this must not disturb.
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
     * 13 EQUAL exchanges of ~15,000 estimated tokens each. Compaction preserves
     * the ten most recent pairs in full, and eight of those alone
     * (~120,000 estimated tokens) are past the 88,000-token window entirely, so
     * no amount of summarizing the rest gets back under the 95% blocking tier.
     *
     * The pair arithmetic is the part worth reading twice: the parked route
     * appends TWO messages (a notice and the echoed prompt) before it compacts,
     * which forms one extra pair and so pushes one more exchange out of the
     * preserved ten than the synchronous route would. A fixture sized against
     * the synchronous route's boundary can therefore slip UNDER the tier on this
     * route — this one is sized so it does not.
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

    /**
     * 13 exchanges of ~10,000 estimated tokens each: still over the 70% reminder
     * tier of the 88,000-token window (61,600) AFTER the parked compaction has
     * run, and under its 95% tier, so the turn goes out with a reminder beside
     * it.
     *
     * @return list<Message>
     */
    private static function stillFullAfterCompactionPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 13; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 20_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 20_000));
        }

        return $history;
    }

    /**
     * A history over the 85% tier with NOTHING a model could summarise: every
     * message is a standalone system turn, which
     * {@see \SugarCraft\Crush\Context\ContextCompactor::exchangesToSummarize()}
     * excludes because stage 2 truncates standalones rather than summarising
     * them.
     *
     * @return list<Message>
     */
    private static function nothingToSummarisePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 15; $i++) {
            $history[] = Message::system(str_repeat(chr(97 + $i), 30_000));
        }

        return $history;
    }

    /**
     * THE E18 SHAPE: twelve exchanges a summariser can condense, plus ONE exchange
     * larger than the window itself, newest so whole-exchange compaction preserves
     * it verbatim. The blocking tier therefore cannot be cleared by the
     * between-exchanges rewrite at all — only by the intra-exchange rescue — and
     * what that rescue leaves behind is a transcript that stays over the 85% tier
     * for the remainder of the session while every prompt still reaches the model.
     * That is precisely the state the breaker must not read as thrash
     * (ruling P8.S5-R6), and the shape `ContextCompactorTest` pins the E18 fix on.
     *
     * @return list<Message>
     */
    private static function oversizedExchangePairs(): array
    {
        $history = [];
        for ($i = 0; $i < 12; $i++) {
            $history[] = Message::user("question {$i} about the deployment");
            $history[] = Message::assistant("answer {$i} with the details of the run");
        }
        $history[] = Message::user(str_repeat('x', 800_000));
        $history[] = Message::assistant(str_repeat('y', 2_000));

        return $history;
    }

    private function chat(
        array $history,
        ?Backend $summaryBackend,
        ?RecordingTurnBackend &$main = null,
        ?TokenTracker $tracker = null,
        ?float $maxCostUsd = null,
    ): Chat {
        $main = new RecordingTurnBackend(88_000);

        return new Chat(
            history: $history,
            inputBuf: 'what changed in the router?',
            backend: $main,
            tokenTracker: $tracker,
            maxCostUsd: $maxCostUsd,
            summaryBackend: $summaryBackend,
        );
    }

    /** A stand-in summarization backend answering with a fixed reply. */
    private function summarizer(string $reply): CountingSummaryBackend
    {
        return new CountingSummaryBackend($reply);
    }

    /** A record for every exchange any test here offers, so every one gets a summary. */
    private function generousSummarizer(): CountingSummaryBackend
    {
        $records = [];
        for ($i = 1; $i <= 12; $i++) {
            $records[] = "{$i}.\nasked: condensed exchange {$i}";
        }

        return $this->summarizer(implode("\n", $records));
    }

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

    private function generationOf(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'generation'))->getValue($chat);
    }

    private function cancellationOf(Chat $chat): ?CancellationToken
    {
        return (new \ReflectionProperty(Chat::class, 'inFlightCancellation'))->getValue($chat);
    }

    /** @param list<Message> $history */
    private function countUserSaying(array $history, string $text): int
    {
        return count(array_filter(
            $history,
            static fn(Message $m): bool => $m->role === Role::User && $m->content === $text,
        ));
    }

    /**
     * How many transcript lines of ANY role contain `$says` — the check the rescue
     * tests need, because the sentence they are looking for is a refusal that must
     * never have been written anywhere, not one line in a particular place.
     *
     * @param list<Message> $history
     */
    private function countSayingAnything(array $history, string $says): int
    {
        return count(array_filter(
            $history,
            static fn(Message $m): bool => str_contains((string) $m->content, $says),
        ));
    }

    // =====================================================================
    // 1. Nothing is rewritten at park time
    // =====================================================================

    /**
     * The tier hands the provider call to a Cmd and rewrites NOTHING. Asserted
     * on the exchanges themselves, not on the Cmd being non-null: a synchronous
     * compaction that also happened to schedule something would satisfy
     * "non-null Cmd" while having already destroyed the originals.
     */
    public function testTheTierParksTheSubmissionAndRewritesNothingYet(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        $originals = $chat->history;

        [$parked, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd, 'the summarization must ride on a Cmd, not run inside update()');
        foreach ($originals as $i => $original) {
            $this->assertSame(
                $original->content,
                $parked->history[$i]->content,
                "history[{$i}] must still be the original exchange, byte for byte",
            );
        }
        $this->assertSame(
            count($originals) + 2,
            count($parked->history),
            'the transcript grows by the notice and the echoed prompt and by nothing else',
        );
        $this->assertSame(0, $main->calls(), 'and no turn has been sent to the conversation backend');
    }

    /**
     * `inFlight` is TRUE across the parked window even though no backend turn
     * exists yet. That is what stops a second turn being submitted on top of the
     * one about to go out — and the reason the double-Escape cancel arm had to
     * learn to release the summarization latch.
     *
     * `generation` belongs to a backend turn, so it is NOT consumed here. The
     * cancellation token is the other half of that sentence and it no longer holds:
     * the token belongs to the SUMMARIZATION call rather than to a turn, so it is
     * armed here, and this line used to assert the opposite. That assertion pinned
     * the §E32 defect — Escape abandoned the parked turn while the provider request
     * it had already paid for ran on — so P8.S5 replaces the pin with the property
     * it contradicted, and the two cancel tests below are what the armed token now
     * has to satisfy. Widening the pair of armed keys is a strengthening, not a
     * loosening: the token must EXIST and be uncancelled at park time, which is the
     * precondition the cancel arm depends on.
     */
    public function testTheParkedWindowHoldsInFlightWithoutArmingATurn(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        $before = $this->generationOf($chat);

        [$parked] = $this->submit($chat);

        $this->assertTrue($parked->inFlight, 'a turn IS going to happen, so Enter must be swallowed meanwhile');
        $this->assertNotNull($this->latchOf($parked), 'the summarization latch is armed');
        $this->assertSame($before, $this->generationOf($parked), 'no turn has started, so no generation was consumed');
        $parkedToken = $this->cancellationOf($parked);
        $this->assertInstanceOf(CancellationToken::class, $parkedToken, 'the parked summarization has a token to cancel');
        $this->assertFalse($parkedToken->isCancelled(), 'and nothing has cancelled it yet');
        $this->assertSame('', $parked->inputBuf, 'the draft was consumed by pressing Enter');
    }

    /**
     * The CLAIM this test protects is unchanged: the set of routes that can
     * abandon a parked turn is closed, so the double-Escape arm is the only one
     * that had to be taught about the `pendingCompactionId` latch. `/clear`,
     * `/rewind` and the palette's New session action must all be unreachable
     * across the parked window.
     *
     * The MECHANISM changed completely, which is why this replaces
     * `testTheParkedWindowSwallowsEveryKeystrokeThatCouldReachACommand()`. That
     * test asserted the blanket keystroke swallow in `Chat::update()` — typing
     * `/` left the draft empty, Enter did nothing, Ctrl+P did not open. The
     * swallow was a user-reported bug (the input box was dead for the length of a
     * turn) and is gone: typing, Enter and Ctrl+P all work mid-turn now. So each
     * route is re-driven to its NEW refusal, and the assertions are on the
     * transcript and the LATCH rather than on the keystroke being dropped — which
     * is the property that actually matters and which the old test only reached
     * through the swallow.
     */
    public function testTheParkedWindowRefusesEveryRouteThatCouldAbandonIt(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        [$parked] = $this->submit($chat);
        $latch = $this->latchOf($parked);
        $this->assertNotNull($latch, 'fixture: the summarization latch is armed');
        $parkedCount = count($parked->history);

        // Typing now WORKS across the parked window - that is the fix.
        [$typed, $typedCmd] = $parked->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame('/', $typed->inputBuf, 'a draft can be composed while the turn is parked');
        $this->assertNull($typedCmd);
        $this->assertSame($latch, $this->latchOf($typed), 'and composing one arms nothing');

        // ...but submitting a command is refused, VISIBLY, and the latch survives.
        foreach (['/clear', '/rewind'] as $command) {
            $draft = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($parked, ['inputBuf' => $command]);
            [$refused, $refusedCmd] = $draft->update(new KeyMsg(KeyType::Enter, ''));

            $this->assertNull($refusedCmd, "{$command} dispatched nothing");
            $this->assertSame($latch, $this->latchOf($refused), "{$command} did not release the latch");
            $this->assertTrue($refused->inFlight, "{$command} left the parked window intact");
            $this->assertSame(
                $parkedCount + 1,
                count($refused->history),
                "{$command} added the refusal notice and nothing else - the transcript was not rewritten",
            );
            $notice = $refused->history[$parkedCount];
            $this->assertSame(Role::System, $notice->role, 'the notice must not be a prefill');
            $this->assertStringContainsString($command, $notice->content, 'and it names what was refused');
        }

        // An ORDINARY prompt is queued rather than refused, and queueing still
        // starts no turn: the latch and the generation both stand.
        $ordinary = (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($parked, ['inputBuf' => 'and one more thing']);
        [$queued, $queuedCmd] = $ordinary->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($queuedCmd, 'queueing dispatches nothing');
        $this->assertSame(['and one more thing'], $queued->queuedPrompts());
        $this->assertSame($latch, $this->latchOf($queued), 'and it does not release the latch either');
        $this->assertSame(
            $this->generationOf($parked),
            $this->generationOf($queued),
            'no second turn was started on top of the parked one',
        );

        // Ctrl+P now opens the palette across the parked window, and its FIRST
        // row is New session - the one action that would wipe the transcript the
        // parked turn is about to be sent with. Enter on it is refused.
        [$palette, $paletteCmd] = $parked->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($paletteCmd);
        $this->assertNotNull($palette->palette(), 'Ctrl+P opens the palette mid-turn now');

        [$acted, $actedCmd] = $palette->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($actedCmd, 'the palette action dispatched nothing');
        $this->assertSame($latch, $this->latchOf($acted), 'the palette action did not release the latch');
        $this->assertTrue($acted->inFlight, 'nor did it end the parked window');
        $this->assertSame(
            $parkedCount + 1,
            count($acted->history),
            'the transcript grew by the refusal notice only - New session did not wipe it',
        );
        $this->assertStringContainsString(
            'New session',
            $acted->history[$parkedCount]->content,
            'and the notice names the row that was refused',
        );
    }

    /**
     * The park-time notice's two token figures are different KINDS of number - a
     * chars/4 estimate and a provider-advertised window. Each is read out BY the
     * label beside it and compared against the figure measured independently, so
     * swapping the two reds this even though every word survives.
     */
    public function testTheParkNoticeNamesEachFigureWithItsOwnUnit(): void
    {
        $history = self::compactablePairs();
        $chat = $this->chat($history, $this->generousSummarizer(), $main);

        [$parked] = $this->submit($chat);

        // The notice sits before the echoed prompt (see scheduleParkedCompaction()
        // on why that order is load-bearing).
        $notice = $parked->history[count($parked->history) - 2];
        $this->assertSame(Role::System, $notice->role, 'the app reporting on itself, like the other two tier notices');

        $estimate = (new Chat(history: $history))->contextTokens();
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
        // Five, and the arithmetic is checkable: the fixture is 13 pairs, the park
        // adds two more (the standalone notice and the unanswered prompt), and
        // compaction preserves the ten most recent - leaving five condensed, all
        // of them user/assistant exchanges a model can be asked about.
        $this->assertSame(
            5,
            self::figureLabelled($notice->content, '/Summarising (\d+) earlier/'),
            'the count is the size of the offered set, which is what the model is actually sent',
        );
    }

    // =====================================================================
    // 2. The turn goes out exactly once, echoed exactly once
    // =====================================================================

    /**
     * The parked prompt reaches the conversation backend EXACTLY ONCE, counted
     * on the backend rather than inferred from the transcript.
     */
    public function testTheLandingDispatchesTheParkedTurnExactlyOnce(): void
    {
        $chat = $this->chat(self::compactablePairs(), $summarizer = $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertSame('what changed in the router?', $msg->parkedSubmission);
        $this->assertSame(1, $summarizer->calls, 'the summarization went out once');

        [$dispatched, $turnCmd] = $parked->update($msg);
        $this->assertNotNull($turnCmd, 'the turn the user pressed Enter for must now go out');
        $this->assertSame(0, $main->calls(), 'and not before the Cmd is actually run');

        $turnCmd();
        $this->assertSame(1, $main->calls(), 'exactly one turn, not two and not zero');
        $this->assertTrue($dispatched->inFlight, 'which is a real turn now, with a real cancellation');
        $this->assertNotNull($this->cancellationOf($dispatched));
        $this->assertNull($this->latchOf($dispatched), 'and the summarization is no longer outstanding');
    }

    /**
     * One prompt, one `Role::User` line saying it — in the transcript AND on the
     * wire. The echo is written at park time and the dispatch happens in a
     * different `update()` call, so a second copy is easy to ship and invisible
     * without counting.
     */
    public function testTheParkedPromptIsEchoedExactlyOnce(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        $this->assertSame(1, $this->countUserSaying($parked->history, 'what changed in the router?'));

        [$dispatched, $turnCmd] = $parked->update($this->resolve($cmd));
        $turnCmd();

        $this->assertSame(1, $this->countUserSaying($dispatched->history, 'what changed in the router?'));
        $this->assertSame(1, $this->countUserSaying($main->lastHistory(), 'what changed in the router?'));
    }

    /**
     * The model's own lines really are what the compaction used, and the
     * `[exchanged information]` placeholder item 6 exists to remove is gone from
     * EVERY condensed exchange - not most of them. That is the property the
     * offered set has to be derived from the post-echo history for, and the
     * parked route appends two messages rather than `/compact`'s two, so its
     * probe has to mirror its own shape.
     */
    public function testTheParkedCompactionUsesTheModelsSummaries(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        [$dispatched] = $parked->update($this->resolve($cmd));

        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $dispatched->history));
        $this->assertStringContainsString('[summary] asked: condensed exchange 1 |', $text);
        $this->assertStringNotContainsString(
            '[exchanged information]',
            $text,
            'the offered set must be the set the compaction condenses, or one exchange per compaction '
            . 'falls back to the placeholder however cooperative the model was',
        );
        $this->assertStringContainsString(
            'Context reached the automatic-compaction tier, so older exchanges were summarized',
            $text,
            'and the tier reports the rewrite through the same notice its synchronous route uses',
        );
    }

    // =====================================================================
    // 3. The dispatched history never ends on an assistant turn
    // =====================================================================

    /**
     * The last message the provider is handed must not be a `Role::Assistant`
     * one. {@see \SugarCraft\Crush\Backend\EngineBackend::toTypedMessages()} maps
     * Role::Assistant to an AssistantMessage, and the Anthropic path renders that
     * as an assistant turn - i.e. a PREFILL the model continues, rather than an
     * instruction it reads. Role::System is hoisted out of `messages` entirely
     * there, so a trailing notice is safe and a trailing assistant line is not.
     *
     * Asserted about the wire rather than the transcript because the wire is what
     * has the consequence. It is NOT asserted as "the history ends on the user's
     * line": measured, it does not, on either route — the tier report and the 70%
     * reminder both land after the prompt, so the last wire role is `system`, and
     * submit()'s synchronous route ends on `system` too whenever the reminder
     * fires. What the design actually guarantees is the stronger-than-it-looks
     * property below: everything after the prompt is Role::System, i.e. an
     * instruction the provider reads, and nothing after it is Role::Assistant,
     * i.e. a prefill it continues.
     */
    public function testTheDispatchedHistoryPutsNoAssistantTurnAfterTheParkedPrompt(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        [, $turnCmd] = $parked->update($this->resolve($cmd));
        $turnCmd();

        $wire = $main->lastHistory();
        $promptIndex = null;
        foreach ($wire as $i => $msg) {
            if ($msg->role === Role::User && $msg->content === 'what changed in the router?') {
                $promptIndex = $i;
            }
        }
        $this->assertNotNull($promptIndex, 'fixture: the prompt must be on the wire');

        $after = array_slice($wire, $promptIndex + 1);
        $this->assertNotSame([], $after, 'fixture: something DOES follow the prompt, or this asserts nothing');
        $this->assertSame(
            [],
            array_values(array_filter($after, static fn(Message $m): bool => $m->role !== Role::System)),
            'everything after the prompt must be Role::System - an assistant turn there is a prefill '
            . 'the provider continues instead of an answer, and a second user turn is a turn nobody sent',
        );
        $this->assertSame(
            Role::System,
            $wire[count($wire) - 1]->role,
            'so the wire ends on system, not on the user line an earlier docblock claimed',
        );
    }

    /**
     * The park-time notice survives the compaction that follows it, which is not
     * free: {@see \SugarCraft\Crush\Context\ContextCompactor} drops a
     * non-user/non-assistant message that directly FOLLOWS a user turn (its pair
     * grouping has nowhere to put one), so a notice written after the echoed
     * prompt would vanish from the transcript when the summaries landed. Written
     * before it, the notice groups as a standalone and is flattened back out.
     */
    public function testTheParkNoticeSurvivesTheCompactionItAnnounces(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        [$dispatched] = $parked->update($this->resolve($cmd));

        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $dispatched->history));
        $this->assertStringContainsString(
            'Context reached the automatic-compaction tier at ~',
            $text,
            'the notice that told the user why their turn was held must still be in the scrollback',
        );
    }

    /**
     * The 70% reminder is judged on the parked route against the history it is
     * about to dispatch - i.e. AFTER the model compaction - so it cannot nag
     * about a state the compaction just fixed, and it does still fire when the
     * compaction could not fix it.
     */
    public function testTheReminderTierIsJudgedAgainstThePostCompactionHistory(): void
    {
        $roomy = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $roomyMain);
        [$parked, $cmd] = $this->submit($roomy);
        [$dispatched] = $parked->update($this->resolve($cmd));
        $this->assertStringNotContainsString(
            'past the context-usage reminder threshold',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $dispatched->history)),
            'the compaction freed 99% of the estimate, so there is nothing left to warn about',
        );

        $full = $this->chat(self::stillFullAfterCompactionPairs(), $this->generousSummarizer(), $fullMain);
        [$parkedFull, $cmdFull] = $this->submit($full);
        [$dispatchedFull, $fullTurn] = $parkedFull->update($this->resolve($cmdFull));
        $this->assertNotNull($fullTurn, 'fixture: this one is under the 95% tier and must dispatch');
        $this->assertStringContainsString(
            'past the context-usage reminder threshold',
            implode("\n", array_map(static fn(Message $m): string => $m->content, $dispatchedFull->history)),
            'a history still over 70% after compaction does get the reminder',
        );
    }

    // =====================================================================
    // The re-sited 95% blocking tier
    // =====================================================================

    /**
     * A history still over the 95% blocking tier once the model compaction has
     * run refuses the parked turn: no Cmd, no backend call, `inFlight` released
     * so the next keystroke is accepted, and still exactly one echo of the
     * prompt.
     *
     * The check has to live at the landing because that is where the compacted
     * history first exists - on this route the compaction happens in a different
     * `update()` call from the submission.
     */
    public function testAHistoryStillOver95PercentAfterModelCompactionRefusesTheParkedTurn(): void
    {
        $chat = $this->chat(self::unshrinkablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        $this->assertNotNull($cmd, 'fixture: the 85% tier must park this');

        [$refused, $turnCmd] = $parked->update($this->resolve($cmd));

        $this->assertNull($turnCmd, 'the turn is refused, so nothing is scheduled');
        $this->assertSame(0, $main->calls(), 'and the provider is never asked');
        $this->assertFalse($refused->inFlight, 'the parked window is released or the session wedges');
        $this->assertNull($this->latchOf($refused));
        $this->assertSame(1, $this->countUserSaying($refused->history, 'what changed in the router?'));
        $this->assertStringContainsString(
            'This turn was NOT sent',
            $refused->history[count($refused->history) - 1]->content,
        );
    }

    // =====================================================================
    // Abandoning a parked turn
    // =====================================================================

    /**
     * Double-Escape during the parked window cancels the turn, and the summary
     * that lands afterwards must NOT dispatch it.
     *
     * This is the defect the parked window creates: the cancel arm is reachable
     * only `if ($this->inFlight)`, and before this tier existed nothing held
     * `inFlight` true without a backend turn behind it. The generation bump the
     * arm already did is no help — the latch is `$pendingCompactionId`,
     * deliberately not the generation counter — so without releasing the latch
     * the summary still matched and sent the very prompt the user had just
     * cancelled.
     */
    public function testDoubleEscapeDuringTheParkedWindowStopsTheTurnFromEverGoingOut(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        [$parked, $cmd] = $this->submit($chat);

        [$firstEscape] = $parked->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertTrue($firstEscape->inFlight, 'one Escape arms the double-press, it does not cancel');
        [$cancelled] = $firstEscape->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertFalse($cancelled->inFlight);
        $this->assertNull($this->latchOf($cancelled), 'the summarization is abandoned with the turn');

        [$after, $afterCmd] = $cancelled->update($this->resolve($cmd));

        $this->assertNull($afterCmd, 'the cancelled turn must not be dispatched by the landing');
        $this->assertSame(0, $main->calls(), 'nor reach the provider by any other route');
        $this->assertSame(
            count($cancelled->history),
            count($after->history),
            'and the abandoned compaction must not rewrite the transcript either',
        );
        $this->assertFalse($after->inFlight);
    }

    /**
     * The same release, seen from the `/compact` side: a summarization scheduled
     * by the command and still outstanding when an unrelated turn is cancelled is
     * dropped too.
     *
     * That is a deliberate widening rather than an accident. The cancel arm cannot
     * tell a parked submission from a `/compact` running alongside a real turn
     * without new state on Chat, and of the two possible errors, abandoning a
     * compaction the user can simply re-run is strictly cheaper than sending a
     * prompt they cancelled. The call is still billed — `update()` accounts usage
     * ahead of the latch check — and the `/compact` line is still in the
     * transcript.
     */
    public function testCancellingATurnAlsoAbandonsAConcurrentCompactSummarization(): void
    {
        $main = new RecordingTurnBackend(88_000);
        $chat = new Chat(
            history: [Message::user('q'), Message::assistant('a')],
            inputBuf: '/compact',
            backend: $main,
            compactorConfig: \SugarCraft\Crush\Context\CompactorConfig::new()->withRecentPreserveCount(1),
            summaryBackend: $this->generousSummarizer(),
        );

        [$scheduled, $summaryCmd] = $this->submit($chat);
        $this->assertNotNull($summaryCmd, 'fixture: /compact must have taken the model route');
        $this->assertFalse($scheduled->inFlight, '/compact starts no turn');
        $this->assertNotNull($this->latchOf($scheduled));

        // A real turn, then cancel it.
        $running = new Chat(
            history: $scheduled->history,
            inputBuf: 'and now a real prompt',
            backend: $main,
            summaryBackend: $this->generousSummarizer(),
            pendingCompactionId: $this->latchOf($scheduled),
        );
        [$inFlight, $turnCmd] = $this->submit($running);
        $this->assertNotNull($turnCmd);
        $this->assertTrue($inFlight->inFlight);
        $this->assertSame($this->latchOf($scheduled), $this->latchOf($inFlight), 'the latch survives a turn');

        [$one] = $inFlight->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $one->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($this->latchOf($cancelled));

        $before = count($cancelled->history);
        [$after] = $cancelled->update($this->resolve($summaryCmd));
        $this->assertSame($before, count($after->history), 'the superseded summary is dropped, not applied');
    }

    // =====================================================================
    // 4. With no model to ask, the tier is what it always was
    // =====================================================================

    /**
     * The offline route: same fixture, no summary backend. The turn goes out in
     * ONE `update()` call, the compaction has already happened, and the shape is
     * the synchronous tier's - a `Role::System` notice, then the user's line.
     */
    public function testWithNoSummaryBackendTheTierIsSynchronousExactlyAsBefore(): void
    {
        $chat = $this->chat(self::compactablePairs(), null, $main);

        [$next, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd, 'the turn goes out on this very update()');
        $cmd();
        $this->assertSame(1, $main->calls());
        $this->assertNull($this->latchOf($next), 'nothing was asked of anyone, so nothing is outstanding');
        $this->assertLessThan(
            count(self::compactablePairs()),
            count($next->history),
            'the heuristic compaction ran in-line',
        );

        $last = $next->history[count($next->history) - 1];
        $this->assertSame(Role::User, $last->role);
        $this->assertSame('what changed in the router?', $last->content);
        $this->assertSame(Role::System, $next->history[count($next->history) - 2]->role);
        $this->assertStringContainsString(
            'Context reached the automatic-compaction tier, so older exchanges were summarized',
            $next->history[count($next->history) - 2]->content,
        );
    }

    /**
     * A history over the tier with nothing a model could usefully summarise takes
     * the synchronous route even WITH a backend, and the backend is never called.
     * Null from the request builder is the ordinary answer, not an error path.
     */
    public function testAHistoryWithNothingToSummariseFallsBackToTheHeuristic(): void
    {
        $summarizer = $this->generousSummarizer();
        $chat = $this->chat(self::nothingToSummarisePairs(), $summarizer, $main);

        [$next, $cmd] = $this->submit($chat);

        $this->assertNotNull($cmd);
        $this->assertSame(0, $summarizer->calls, 'there was nothing to ask about');
        $this->assertNull($this->latchOf($next));
        $cmd();
        $this->assertSame(1, $main->calls(), 'and the turn went out on this update()');
    }

    /**
     * A failed summarization still sends the turn the user pressed Enter for -
     * against a heuristically-compacted history, with the failure named. The
     * alternative is losing a submitted prompt to a provider hiccup.
     */
    public function testAFailedSummarizationStillSendsTheParkedTurnAndSaysWhy(): void
    {
        $chat = $this->chat(self::compactablePairs(), new FailingSummaryBackend('summariser exploded'), $main);

        [$parked, $cmd] = $this->submit($chat);
        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertSame('what changed in the router?', $msg->parkedSubmission, 'the parked turn survives a failure');

        [$dispatched, $turnCmd] = $parked->update($msg);
        $this->assertNotNull($turnCmd);
        $turnCmd();
        $this->assertSame(1, $main->calls());
        $this->assertSame(1, $this->countUserSaying($dispatched->history, 'what changed in the router?'));

        $text = implode("\n", array_map(static fn(Message $m): string => $m->content, $dispatched->history));
        $this->assertStringContainsString('Model summarisation failed (summariser exploded)', $text);
    }

    /**
     * A session already past its spend cap never reaches this tier at all, and so
     * never asks the model: {@see Chat::spendCapRefusal()} runs BEFORE the 85%
     * block in `submit()`, so the turn is refused outright and the draft kept.
     *
     * Worth pinning rather than assuming, because the `/compact` route reaches its
     * own spend-cap check past the refusal (the cap is evaluated after
     * `dispatchCommand()` so `/budget` still works while capped) and it is easy to
     * carry that reasoning across to a tier where the ordering makes it moot.
     */
    public function testASpendCappedSessionIsRefusedBeforeTheTierAndNeverAsksTheModel(): void
    {
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(1_000, 5.0);
        $summarizer = $this->generousSummarizer();
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker, 1.0);

        [$next, $cmd] = $this->submit($chat);

        $this->assertNull($cmd, 'the turn is refused, and nothing is scheduled');
        $this->assertSame(0, $summarizer->calls, 'the summarization model is never asked');
        $this->assertSame(0, $main->calls());
        $this->assertNull($this->latchOf($next));
        $this->assertFalse($next->inFlight);
        $this->assertStringContainsString(
            'Spend cap reached',
            $next->history[count($next->history) - 1]->content,
        );
        $this->assertSame(
            'what changed in the router?',
            $next->inputBuf,
            'and the draft is kept - the prompt was never sent',
        );
    }

    // =====================================================================
    // The spend cap, on the one route that starts a turn past submit()'s check
    // =====================================================================

    /**
     * The summarization is itself a billed provider call, so it can be the call
     * that CROSSES the cap — and when it has, the turn it was parked behind must
     * not go out.
     *
     * This route is the only one in the app that starts a turn without passing
     * {@see Chat::spendCapRefusal()}: that check ran in an earlier `update()`,
     * when the spend was still under the cap. Measured before the fix, with
     * spend $0.50 and a cap of $1.00: the summary reported $0.60, the session was
     * at $1.10 with the cap reached, and the parked turn was dispatched anyway
     * (one conversation-backend call) while a NEWLY typed prompt at the same
     * spend was correctly refused. The documented "the turn that crosses the cap
     * runs to completion" allowance does not cover it either — there the crossing
     * happens inside a turn already under way, whereas here it happened in a
     * previous `update()` and the app was electing to START a chargeable turn
     * with the cap known to be breached.
     *
     * The prompt is not discarded: it was echoed at park time and stays in the
     * transcript, exactly once, with the refusal after it.
     */
    public function testASummarizationThatCrossesTheSpendCapRefusesTheParkedTurn(): void
    {
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(500, 0.5);
        $summarizer = new BillingSummaryBackend($this->generousSummarizer()->reply(), 0.6);
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker, 1.0);

        [$parked, $cmd] = $this->submit($chat);
        $this->assertNotNull($cmd, 'fixture: the spend is under the cap, so the tier parks');
        $this->assertTrue($parked->inFlight);

        [$refused, $turnCmd] = $parked->update($this->resolve($cmd));

        $this->assertGreaterThanOrEqual(1.0, $refused->spentUsd(), 'fixture: the summary must cross the cap');
        $this->assertNull($turnCmd, 'the parked turn must not be dispatched past the cap');
        $this->assertSame(0, $main->calls(), 'and the conversation backend is never called');
        $this->assertFalse($refused->inFlight, 'the parked window is released or the session wedges');
        $this->assertNull($this->latchOf($refused));
        $this->assertSame(
            1,
            $this->countUserSaying($refused->history, 'what changed in the router?'),
            'the already-echoed prompt is neither dropped nor duplicated',
        );
        $last = $refused->history[count($refused->history) - 1];
        $this->assertStringContainsString('Spend cap reached', $last->content);
        $this->assertStringContainsString(
            'summarization this turn was parked behind',
            $last->content,
            'and it names what actually crossed the cap, which was not a turn',
        );
    }

    /**
     * The same fixture one cent cheaper still dispatches. Without this the test
     * above would pass just as well if the landing refused every parked turn.
     */
    public function testASummarizationThatStaysUnderTheSpendCapStillDispatchesTheParkedTurn(): void
    {
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(500, 0.5);
        $summarizer = new BillingSummaryBackend($this->generousSummarizer()->reply(), 0.4);
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker, 1.0);

        [$parked, $cmd] = $this->submit($chat);
        [$dispatched, $turnCmd] = $parked->update($this->resolve($cmd));

        $this->assertLessThan(1.0, $dispatched->spentUsd(), 'fixture: $0.90 of a $1.00 cap');
        $this->assertNotNull($turnCmd);
        $turnCmd();
        $this->assertSame(1, $main->calls());
    }

    // =====================================================================
    // What the messages this route newly writes actually say
    // =====================================================================

    /**
     * The refusal the re-sited 95% tier writes reads its two figures off the
     * PARKED route's own post-compaction history, and they are different kinds of
     * number: a chars/4 estimate of what would be sent, and the window the
     * provider advertises. Each is read out by the label beside it and compared
     * against the figure measured independently, so quoting the wrong one — or
     * swapping the two — reds this even though every word survives.
     *
     * The park notice got this treatment when it was written and this message did
     * not, though this bundle is what newly routes a refusal through it.
     */
    public function testTheRefusedParkedTurnNamesEachFigureWithItsOwnUnit(): void
    {
        $chat = $this->chat(self::unshrinkablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        [$refused] = $parked->update($this->resolve($cmd));

        $notice = $refused->history[count($refused->history) - 1]->content;
        // The estimate is of the history actually committed, less the refusal line
        // itself - which is what foregroundBlockedResponse() was handed.
        $committed = array_slice($refused->history, 0, count($refused->history) - 1);
        $estimate = (new Chat(history: $committed))->contextTokens();

        $this->assertSame(
            $estimate,
            self::figureLabelled($notice, '/~(\d+)\s+estimated tokens/'),
            'the ~N figure is the chars/4 estimate of the history this refusal commits',
        );
        $this->assertSame(
            $chat->contextTokenLimit(),
            self::figureLabelled($notice, '/(\d+)-token context window/'),
            'and the other is the provider-counted window',
        );
        $this->assertNotSame(
            $estimate,
            $chat->contextTokenLimit(),
            'fixture: the two figures must differ or a swap would be invisible',
        );
        $this->assertGreaterThan(0, $estimate, 'fixture: and neither may be zero');
    }

    /**
     * The refusal commits no empty user turn.
     *
     * `''` is this route's "the transcript already carries the prompt" signal, and
     * the guard that reads it is one line. Without it the refusal appends
     * `Message::user('')` — an empty user turn in the transcript and on the next
     * wire, not a second copy of the prompt.
     */
    public function testTheRefusedParkedTurnAppendsNoEmptyUserLine(): void
    {
        $chat = $this->chat(self::unshrinkablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        [$refused] = $parked->update($this->resolve($cmd));

        $this->assertSame(
            0,
            $this->countUserSaying($refused->history, ''),
            'an empty Role::User message is a turn the provider is asked to answer',
        );
    }

    /**
     * The tier report says the compaction SHRANK the history, and the two counts
     * are checked against the histories they describe rather than against each
     * other — so the report cannot claim `23 messages -> 26 messages`, i.e. that
     * a compaction grew what it condensed.
     */
    public function testTheTierReportCountsTheHistoryDownNotUp(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);

        [$parked, $cmd] = $this->submit($chat);
        $before = count($parked->history);
        [$dispatched] = $parked->update($this->resolve($cmd));

        $report = null;
        foreach ($dispatched->history as $message) {
            if (str_contains($message->content, 'older exchanges were summarized')) {
                $report = $message->content;
            }
        }
        $this->assertNotNull($report, 'fixture: the tier report must be in the transcript');

        $was = self::figureLabelled($report, '/summarized: (\d+) messages ->/');
        $now = self::figureLabelled($report, '/-> (\d+) messages/');
        $this->assertSame($before, $was, 'the "was" count is the history the compaction was handed');
        $this->assertLessThan($was, $now, 'a compaction that reported growth would be reporting a failure');
        $this->assertGreaterThan(0, $now);
    }

    // =====================================================================
    // Everything a turn needs, on the route that dispatches it later
    // =====================================================================

    /**
     * The parked turn's dispatch saves a session checkpoint, like the synchronous
     * route's does.
     *
     * Pinned on the STORE rather than on prose because the auto-save is the piece
     * of turn-dispatch with no other observable effect: `tests/Session/`
     * exercises `saveCheckpoint()` itself thoroughly and nothing exercised the
     * call. Measured, the whole block could be deleted and the suite stayed
     * green — which is exactly the argument for extracting one copy of the
     * dispatch tail, applied to the surviving copy.
     */
    public function testTheDispatchedParkedTurnSavesACheckpoint(): void
    {
        $dir = sys_get_temp_dir() . '/crush_parked_checkpoint_' . uniqid('', true);
        mkdir($dir, 0755, true);
        $dbPath = $dir . '/sessions.db';

        try {
            $store = new EnhancedSessionStore($dbPath);
            $store->createSession('parked-session', 'echo', 'echo');

            $main = new RecordingTurnBackend(88_000);
            $chat = new Chat(
                history: self::compactablePairs(),
                inputBuf: 'what changed in the router?',
                backend: $main,
                summaryBackend: $this->generousSummarizer(),
                sessionStore: $store,
                currentSessionId: 'parked-session',
            );

            [$parked, $cmd] = $this->submit($chat);
            $this->assertSame([], $store->listCheckpoints('parked-session'), 'parking is not dispatching');

            [, $turnCmd] = $parked->update($this->resolve($cmd));
            $this->assertNotNull($turnCmd, 'fixture: this history dispatches');

            $checkpoints = $store->listCheckpoints('parked-session');
            $this->assertCount(1, $checkpoints, 'the dispatch auto-saves exactly one checkpoint');

            // What it saved is the state BEFORE the parked prompt (audit SES-1):
            // the prompt is the draft `/rewind` re-seeds, and NOT also a row of
            // the transcript, which used to end on it — so a rewind left it in
            // both places and Enter sent it twice.
            $saved = $store->getCheckpoint('parked-session', 0);
            $this->assertNotNull($saved);
            $this->assertSame('what changed in the router?', $saved['inputBuf']);
            $this->assertNotContains(
                'what changed in the router?',
                array_map(static fn(array $m): string => (string) $m['content'], $saved['messages']),
                'the parked prompt is the draft, not a transcript row',
            );
        } finally {
            foreach (glob($dir . '/*') ?: [] as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    /**
     * Both writes of `streamingText => ''` do something: a half-streamed reply
     * left over from an earlier turn must not still be on screen when the next
     * turn starts, on either route. Driven through the public accessor, from a
     * Chat that really carries a partial.
     */
    public function testNeitherParkingNorDispatchingLeavesAStalePartialOnScreen(): void
    {
        $main = new RecordingTurnBackend(88_000);
        $chat = new Chat(
            history: self::compactablePairs(),
            inputBuf: 'what changed in the router?',
            backend: $main,
            summaryBackend: $this->generousSummarizer(),
            streamingText: 'half an answer from the turn before',
        );
        $this->assertSame('half an answer from the turn before', $chat->streamingText());

        [$parked, $cmd] = $this->submit($chat);
        $this->assertSame('', $parked->streamingText(), 'the park clears it');

        // And again on the dispatch, from a parked Chat that somehow carries one.
        $withPartial = new Chat(
            history: $parked->history,
            backend: $main,
            inFlight: true,
            summaryBackend: $this->generousSummarizer(),
            pendingCompactionId: $this->latchOf($parked),
            streamingText: 'another stale partial',
        );
        [$dispatched, $turnCmd] = $withPartial->update($this->resolve($cmd));
        $this->assertNotNull($turnCmd, 'fixture: this history dispatches');
        $this->assertSame('', $dispatched->streamingText(), 'and the dispatch clears it too');
    }

    /**
     * Parking counts as activity. It is the last thing that happens for as long
     * as the round-trip takes, and leaving `lastActivityAt` stale would leave the
     * session looking abandoned for the whole parked window — the state the
     * idle-compaction advisory reads.
     */
    public function testParkingStampsTheSessionAsActive(): void
    {
        $stale = new \DateTimeImmutable('-2 hours');
        $main = new RecordingTurnBackend(88_000);
        $chat = new Chat(
            history: self::compactablePairs(),
            inputBuf: 'what changed in the router?',
            backend: $main,
            summaryBackend: $this->generousSummarizer(),
            lastActivityAt: $stale,
        );

        [$parked] = $this->submit($chat);

        $this->assertNotNull($parked->lastActivityAt());
        $this->assertGreaterThan(
            $stale->getTimestamp(),
            $parked->lastActivityAt()->getTimestamp(),
            'the park is activity, not idleness',
        );
    }

    /**
     * An abandoned summarization is still BILLED. The cancel arm released the
     * latch, so the summaries are dropped — but `update()` accounts the usage
     * ahead of the latch check, because the call went out on the user's key and
     * cost the same whether or not its answer is still wanted.
     *
     * Asserted on the tracker. It was asserted in prose only, in a source comment
     * and in a test docblock, which is why moving the accounting below the latch
     * check changed nothing any test could see.
     */
    public function testAnAbandonedSummarizationIsStillBilled(): void
    {
        $tracker = new TokenTracker();
        $summarizer = new BillingSummaryBackend($this->generousSummarizer()->reply(), 0.25);
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker);

        [$parked, $cmd] = $this->submit($chat);
        [$one] = $parked->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $one->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertNull($this->latchOf($cancelled), 'fixture: the cancel released the latch');
        $this->assertSame(0.0, $cancelled->spentUsd(), 'fixture: nothing billed until the Msg lands');

        [$after] = $cancelled->update($this->resolve($cmd));

        $this->assertSame(0.25, $after->spentUsd(), 'the dropped summarization was still paid for');
        $this->assertSame(0, $main->calls(), 'and the cancelled turn still never went out');
    }

    /**
     * PageUp scrolls the transcript DURING the parked window. That is a
     * correction, not a feature: the scroll arm sits above `update()`'s `inFlight`
     * swallow, so the parked window leaves four keys live and not two, and two
     * docblocks said two. The conclusion the design rests on is unaffected —
     * scrolling cannot abandon a parked turn — and this pins the corrected
     * measurement so it cannot rot back.
     */
    public function testTheParkedWindowStillScrollsTheTranscript(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        [$parked] = $this->submit($chat);
        // A frame has to have been rendered for the scroll ceiling to exist.
        $parked->view();

        [$scrolled] = $parked->update(new KeyMsg(KeyType::PageUp, ''));

        $offset = (new \ReflectionProperty(Chat::class, 'scrollOffset'))->getValue($scrolled);
        $this->assertGreaterThan(0, $offset, 'PageUp is above the inFlight swallow, so it still acts');
        $this->assertNotNull($this->latchOf($scrolled), 'and it leaves the parked turn exactly where it was');
        $this->assertTrue($scrolled->inFlight);
    }

    // =====================================================================
    // 9. The compaction circuit breaker (prompt_expand.md §4.23)
    // =====================================================================

    /**
     * The thrash loop upstream shipped and then had to fix: when the recent window
     * the compaction preserves is itself over the tier, every rewrite comes straight
     * back over it, so the tier fires again on the next prompt, asks the model again,
     * and buys nothing again — §4.23's "context refills to the limit immediately
     * after compacting three times in a row".
     *
     * `unshrinkablePairs()` is exactly that shape, and the three refills are WALKED
     * rather than declared: three complete park/land cycles through the live
     * `submit()`, the counter asserted after each one, because the increment site is
     * the part most easily got wrong. A counter written on the object the landing
     * returns and read off an earlier clone sits at zero forever, and every other
     * behaviour in this file looks identical while it does.
     *
     * What the first three attempts do is UNCHANGED from before the breaker — model
     * asked, rewrite landed, turn refused at the 95% tier — which is the point of
     * asserting the count at each step instead of only at the end: the breaker must
     * not touch a tier that is still making progress, and it must not need a fourth
     * payment to say so.
     *
     * The prompt is 40,000 characters, and {@see thrashingDraft()} says why it has to
     * be that big rather than the two words every other test here types.
     */
    public function testThreeCompactionsThatRefillTheContextTripTheBreakerAndRefuseTheNextTurn(): void
    {
        $draft = self::thrashingDraft();
        $summarizer = $this->generousSummarizer();
        $chat = $this->chat(self::unshrinkablePairs(), $summarizer, $main);
        $this->assertSame(0, $this->refillCountOf($chat), 'a fresh session has thrashed zero times');

        for ($attempt = 1; $attempt <= IdleCompactionPolicy::REFILL_LIMIT; $attempt++) {
            [$parked, $cmd] = $this->submit($this->withDraft($chat, $draft));
            $this->assertNotNull($cmd, "attempt {$attempt}: the tier must still park the turn and ask the model");
            $this->assertSame(
                $attempt - 1,
                $this->refillCountOf($parked),
                "attempt {$attempt}: parking itself changes nothing about the run",
            );

            [$landed, $landedCmd] = $parked->update($this->resolve($cmd));

            $this->assertSame(
                $attempt,
                $this->refillCountOf($landed),
                "attempt {$attempt}: the rewrite left the context back over the tier, so the run is {$attempt} long",
            );
            $this->assertSame($attempt, $summarizer->calls, 'and the model was asked exactly once per attempt');
            $this->assertNull($landedCmd, 'the landing still ends at the 95% tier, exactly as it did before the breaker');
            $this->assertNull($this->latchOf($landed), 'the landing released the latch as it always did');
            $this->assertFalse($landed->inFlight, 'and no window is left open behind it');
            $chat = $landed;
        }

        $refusalDraft = $this->withDraft($chat, $draft);
        $historyBefore = count($refusalDraft->history);
        $turnCallsBefore = $main->calls();
        [$refused, $cmd] = $this->submit($refusalDraft);

        $this->assertNull($cmd, 'the fourth compaction is refused before anything is asked or rewritten');
        $this->assertSame(IdleCompactionPolicy::REFILL_LIMIT, $summarizer->calls, 'the summarizer is not called again');
        $this->assertSame($turnCallsBefore, $main->calls(), 'nor is the conversation backend');
        $this->assertNull($this->latchOf($refused), 'nothing is parked, so no latch is armed');
        $this->assertFalse($refused->inFlight, 'and the session is not left holding a window nobody will land');

        $notice = $refused->history[count($refused->history) - 1];
        $this->assertSame(Role::System, $notice->role, 'the tier reports about itself, it does not answer as the model');
        $this->assertStringContainsString(
            'Context compaction has run ' . IdleCompactionPolicy::REFILL_LIMIT . ' times in a row',
            $notice->content,
        );
        $this->assertStringContainsString('/rewind', $notice->content, 'and it names the exits that work from here');
        $this->assertStringContainsString('/clear', $notice->content);
        $this->assertStringContainsString('/model', $notice->content);
        $this->assertStringNotContainsString('/compact', $notice->content, 'which does not include the command that refills again');
        $this->assertStringNotContainsString('%', $notice->content, 'and no tier percentage the user never set');
        $this->assertSame(
            $draft,
            $refused->inputBuf,
            'the prompt is kept - nothing was sent, and the fix is a decision about the transcript',
        );
        $this->assertSame(
            $historyBefore + 1,
            count($refused->history),
            'the refusal appends its notice and rewrites nothing',
        );
        $this->assertSame(
            IdleCompactionPolicy::REFILL_LIMIT,
            $this->refillCountOf($refused),
            'a refusal does not clear the run it is refusing for',
        );
    }

    /**
     * The prompt the thrash test drives its three refills with: 40,000 characters,
     * about 10,000 estimated tokens.
     *
     * It has to be a big prompt, and the reason is the same arithmetic that makes
     * `unshrinkablePairs()` unshrinkable. Every round the compaction replaces one more
     * of the seed's 30,000-character exchanges with a `[summary]` line, so the
     * rewritten history shrinks by roughly 15,000 estimated tokens per attempt; typed
     * with the two-word draft every other test here uses, the third attempt lands UNDER
     * the 85% tier and the counter resets — correctly, because that compaction
     * genuinely made progress. What refills a compacted context and keeps refilling it
     * is the newest exchange, and on this route the newest exchange is whatever the
     * user just pressed Enter on. A pasted log is what §4.23 is describing, and it is
     * the only shape here that reaches three in a row.
     *
     * 40,000 is enough and not much more: measured, the three landings come in at
     * 130,600 / 110,900 / 91,200 estimated tokens, all clear of the 95% blocking tier
     * at 83,600. That clearance is load-bearing rather than tidy — a landing that got
     * under the blocking tier would DISPATCH the parked turn, and the next Enter would
     * queue behind that turn instead of reaching the tier, which is a different path
     * from the one under test.
     */
    private static function thrashingDraft(): string
    {
        return str_repeat('p', 40_000);
    }

    /**
     * One short of the limit, the tier still fires — and a compaction that actually
     * got under it breaks the run back to zero, restoring the tier with nothing
     * un-latched by hand. The counter starts at two because that is a fixture STATE
     * on an immutable object, not behaviour to fake: what is under test is precisely
     * that a measured, under-tier landing resets it, so arriving there by walking two
     * real refills first would only re-test the increment above.
     */
    public function testACompactionThatShrinksTheContextBreaksTheRunAndTheTierGoesOnWorking(): void
    {
        $chat = $this->chatWithRefills(self::compactablePairs(), $this->generousSummarizer(), 2, $main);

        [$parked, $cmd] = $this->submit($chat);
        $this->assertNotNull($cmd, 'two is under the limit, so the tier is not stopped yet');

        [$landed, $landedCmd] = $parked->update($this->resolve($cmd));

        $this->assertSame(0, $this->refillCountOf($landed), 'a rewrite that got under the tier breaks the run');
        $this->assertNotNull($landedCmd, 'and the parked turn goes out - the breaker never held it');
        $landedCmd();
        $this->assertSame(1, $main->calls(), 'the conversation backend saw it');
        $this->assertTrue($landed->inFlight);
    }

    /**
     * The OFFLINE half of the same rule: with no model to ask, the tier compacts
     * synchronously in `submit()`, and that rewrite is measured too. What counts is
     * the refill, not which backend produced the shrink — a definition that lived
     * only in the parked route would leave an offline session thrashing forever, and
     * that is the route which does it with no provider call to notice.
     */
    public function testTheSynchronousHeuristicRouteCountsRefillsToo(): void
    {
        [$refused] = $this->submit($this->chat(self::unshrinkablePairs(), null, $main));
        $this->assertSame(1, $this->refillCountOf($refused), 'the heuristic rewrite is measured like any other');
        $this->assertSame(0, $main->calls(), 'and the turn still ends at the blocking tier, exactly as before');

        [$dispatched, $cmd] = $this->submit($this->chatWithRefills(self::compactablePairs(), null, 2, $fitted));
        $this->assertNotNull($cmd, 'offline the tier dispatches the turn itself, with no round-trip to park behind');
        $this->assertSame(0, $this->refillCountOf($dispatched), 'and the same reset applies to its rewrite');
        $cmd();
        $this->assertSame(1, $fitted->calls(), 'the prompt went out on the rewritten history');
    }

    /**
     * A DISPATCHED ATTEMPT IS NOT A REFILL (ruling P8.S5-R6).
     *
     * `tests/Context/ContextCompactorTest::testAnOversizedExchangeStopsBeingRefusedAndTheWireReallyGetsShorter`
     * pins the E18 guarantee: an exchange larger than the window is truncated on
     * every attempt so the prompt keeps reaching the model rather than being
     * re-refused. The transcript that guarantee leaves behind is PERMANENTLY over the
     * 85% tier — the truncated giant sits in the preserved recent window for the rest
     * of the session — so measuring the run off the rewrite alone counts it as
     * thrash, and the breaker then refuses the one session the rescue exists to keep
     * running. That is backwards: §4.23 describes a loop that burns calls getting
     * nowhere, and an attempt whose prompt reached the model got somewhere. So the
     * run is a count of futility — extended only by an attempt that left the context
     * over its tier AND ended with the turn unsent, broken by an under-tier rewrite,
     * and left ALONE by everything in between.
     *
     * Driven here rather than in that file because this step's ceiling does not
     * include it; a pin belongs beside the behaviour it protects regardless.
     */
    public function testCompactionsThatRescueAnOversizedExchangeNeverBuildAThrashRun(): void
    {
        $summarizer = $this->generousSummarizer();
        $chat = $this->chat(self::oversizedExchangePairs(), $summarizer, $main);

        for ($attempt = 1; $attempt <= IdleCompactionPolicy::REFILL_LIMIT + 1; $attempt++) {
            [$parked, $cmd] = $this->submit($this->withDraft($chat, 'and now?'));
            $this->assertNotNull($cmd, "attempt {$attempt}: the tier still parks and asks the model");

            [$landed, $turn] = $parked->update($this->resolve($cmd));
            $this->assertNotNull(
                $turn,
                "attempt {$attempt}: the oversized exchange is truncated and the prompt goes out - E18, unchanged",
            );
            $this->assertSame(
                0,
                $this->refillCountOf($landed),
                "attempt {$attempt}: an attempt that got the user's prompt out is not a futile one",
            );
            $this->assertSame(
                0,
                $this->countSayingAnything($landed->history, 'Context compaction has run'),
                "attempt {$attempt}: and no thrash refusal has ever been written into this transcript",
            );

            [$chat] = $landed->update($this->resolve($turn));
            $this->assertSame($attempt, $main->calls(), 'the conversation backend was reached on every attempt');
        }

        $this->assertSame(
            IdleCompactionPolicy::REFILL_LIMIT + 1,
            $summarizer->calls,
            'the model was asked on every one of them: the tier kept working, which is the point',
        );
    }

    /**
     * The same exemption on the route with no model to ask: the synchronous tier
     * truncates and dispatches, so its run stays flat however many turns it takes.
     */
    public function testTheSynchronousRescueOfAnOversizedExchangeKeepsTheRunFlat(): void
    {
        $chat = $this->chat(self::oversizedExchangePairs(), null, $main);

        for ($attempt = 1; $attempt <= IdleCompactionPolicy::REFILL_LIMIT + 1; $attempt++) {
            [$after, $cmd] = $this->submit($this->withDraft($chat, 'and now?'));
            $this->assertNotNull($cmd, "attempt {$attempt}: the offline tier truncates and sends the turn itself");
            $this->assertSame(0, $this->refillCountOf($after), "attempt {$attempt}: dispatched, so uncounted");
            $this->assertSame(
                0,
                $this->countSayingAnything($after->history, 'Context compaction has run'),
                "attempt {$attempt}: and no refusal was written",
            );

            [$chat] = $after->update($this->resolve($cmd));
            $this->assertSame($attempt, $main->calls(), 'the prompt reached the conversation backend');
        }
    }

    /**
     * The breaker guards the tier that acts on its own and NOTHING else. A tripped
     * session must still be able to type `/compact` — the user may know something the
     * estimate does not, and a guard that also refused the manual escape hatch would
     * be the app refusing to obey the one command whose whole purpose is to be
     * obeyed. A `/compact` landing is equally untouched in the other direction: it
     * neither charges the run nor clears it, because the number records what the
     * automatic tier achieved.
     */
    public function testATrippedBreakerStopsTheAutomaticTierAndNotTheCommandTheUserTyped(): void
    {
        $summarizer = $this->generousSummarizer();
        $chat = $this->chatWithRefills(
            self::compactablePairs(),
            $summarizer,
            IdleCompactionPolicy::REFILL_LIMIT,
            $main,
        );

        [$scheduled, $cmd] = $this->submit($this->withDraft($chat, '/compact'));

        $this->assertNotNull($cmd, '/compact still schedules a model summary while the breaker is tripped');
        $this->assertSame(
            IdleCompactionPolicy::REFILL_LIMIT,
            $this->refillCountOf($scheduled),
            'and scheduling it charged the run nothing',
        );

        [$landed] = $scheduled->update($this->resolve($cmd));
        $this->assertSame(
            1,
            $summarizer->calls,
            'the model really was asked - once, by the command the user typed and not by the tier',
        );
        $this->assertSame(
            IdleCompactionPolicy::REFILL_LIMIT,
            $this->refillCountOf($landed),
            'nor does its landing clear the run: a manual compaction is not evidence about the automatic one',
        );
    }

    /**
     * `/clear` unarms the breaker beside the transcript it counted. This is the reset
     * the refusal above promises — a notice that named `/clear` as an exit while
     * `/clear` left the count standing would send the user to a command that does not
     * do the one thing it was named for.
     */
    public function testClearingTheTranscriptUnarmsTheBreaker(): void
    {
        $chat = $this->chatWithRefills(
            self::compactablePairs(),
            $this->generousSummarizer(),
            IdleCompactionPolicy::REFILL_LIMIT,
            $main,
        );

        [$cleared, $cmd] = $this->submit($this->withDraft($chat, '/clear'));

        $this->assertNull($cmd, '/clear starts no turn');
        $this->assertSame([], $cleared->history, 'the transcript is gone');
        $this->assertSame(0, $this->refillCountOf($cleared), 'and so is the run that was counted against it');

        [$next, $turnCmd] = $this->submit($this->withDraft($cleared, 'what changed in the router?'));
        $this->assertNotNull($turnCmd, 'the next prompt is a fresh session and not a tripped one');
        $this->assertCount(1, $next->history, 'the only line it appends is the echoed prompt - no refusal beside it');
        $this->assertSame(0, $this->refillCountOf($next), 'and the run stays broken');
    }

    // =====================================================================
    // 10. E31/E32 - what the parked tier says when it withholds, and what
    //     cancelling it actually stops
    // =====================================================================

    /**
     * The sentence both spend-cap compaction arms open with, at the figures this
     * fixture sets: $5.00 spent against a $1.00 cap. Written out here rather than
     * assembled from parts, because the whole point of the shared helper is that one
     * string exists, and a test that rebuilt it from the same pieces could not tell a
     * shared sentence from two similar ones.
     */
    private const CAP_SENTENCE = 'Spend cap reached ($5.0000 of $1.0000), so the model was not asked to '
        . 'summarise — compacted with the local heuristic instead. ';

    /**
     * Backlog §E31: the parked tier's spend-cap gate answered `null` in silence, so
     * the same blocked provider call was TOLD on the `/compact` route and invisible
     * here. The gate itself is dormant from `submit()` — the refusal upstream makes
     * the 85% block unreachable for a capped session — so it is driven DIRECTLY,
     * past that ordering, which is the only way to pin a gate that belongs to the
     * provider call rather than to the caller.
     *
     * Two things about the ANSWER are load-bearing and both are asserted. The return
     * stays null: "no model route" must keep meaning "take the synchronous
     * heuristic", which is what the capped caller still has to do, and returning a
     * finished turn instead would drop the prompt the user pressed Enter for
     * (`compactNow()` starts no turn — see the method docblock for that
     * reasoning). And the notice out of the by-reference parameter is the SHARED
     * sentence: `$capNotice` and the `/compact` route's own line are driven on the
     * SAME session, so their figures cannot differ by fixture accident, and the first
     * sentence must be byte-identical while only the advice after it diverges.
     *
     * What is NOT driven here, and cannot be honestly, is the ride through
     * `submit()`: the same spend that fills `$capNotice` is the spend
     * `spendCapRefusal()` refuses the turn on some forty lines earlier, so no session
     * can be both capped and inside this tier from a keyboard. The prepend beside the
     * compaction notice is continuation-for-when-the-ordering-moves — which is the
     * whole reason the gate belongs to the provider call rather than to the caller,
     * and why asserting it at the seam is the only test this fact can have until a
     * route exists that reaches it.
     */
    public function testTheParkedTierTellsTheUserWhenTheCapStoppedTheModelAsk(): void
    {
        $tracker = new TokenTracker();
        $tracker->addTotalUsage(1_000, 5.0);
        $summarizer = $this->generousSummarizer();
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker, 1.0);

        $capNotice = null;
        $parked = (new \ReflectionMethod(Chat::class, 'scheduleParkedCompaction'))
            ->invokeArgs($chat, ['what changed in the router?', 80_000, 88_000, &$capNotice]);

        $this->assertNull($parked, 'the tier still declines the model route, so the caller falls through to the heuristic');
        $this->assertSame(0, $summarizer->calls, 'and no summarization is asked for');
        $this->assertNull($this->latchOf($chat), 'nothing is armed on the session');
        $this->assertNotNull($capNotice, 'but the cap is named out loud');
        $this->assertStringStartsWith(self::CAP_SENTENCE, (string) $capNotice);
        $this->assertStringNotContainsString(
            'run /compact again',
            (string) $capNotice,
            'the parked route sends the prompt on the heuristic REGARDLESS, so that advice would advertise '
            . 'a second way to arrive exactly where the user already is',
        );

        // The sibling, from the same session at the same figures, by the route a user
        // actually takes: typing /compact past the refusal.
        [$answered, $cmd] = $this->submit($this->withDraft($chat, '/compact'));
        $this->assertNull($cmd, '/compact answered on the spot, on the heuristic');
        $sibling = null;
        foreach ($answered->history as $message) {
            if (str_starts_with($message->content, 'Spend cap reached')) {
                $sibling = $message->content;
            }
        }
        $this->assertNotNull($sibling, 'fixture: /compact says it out loud, as it always did');
        $this->assertStringStartsWith(
            self::CAP_SENTENCE,
            $sibling,
            'both routes open with the identical sentence, figures included',
        );
        $this->assertStringContainsString('run /compact again', $sibling, 'and only /compact can honestly advise it');
        $this->assertSame(0, $summarizer->calls, 'neither route asked the model');
    }

    /**
     * Backlog §E32, first polarity: the token armed at park time is the one the
     * double-Escape arm flips. The existing cancel tests assert what the CANCELLED
     * SESSION looks like — latch released, turn never dispatched — which a session
     * could achieve while leaving the provider request running. This asserts the
     * flag the provider holds, before and after, so the wiring cannot be dropped
     * without something here going red.
     */
    public function testDoubleEscapeInTheParkedWindowFlipsTheSummarizationToken(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        [$parked] = $this->submit($chat);
        $token = $this->cancellationOf($parked);
        $this->assertInstanceOf(CancellationToken::class, $token, 'fixture: the parked call carries a token');
        $this->assertFalse($token->isCancelled(), 'and it is not cancelled yet');

        [$first] = $parked->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertFalse($token->isCancelled(), 'a single Escape only arms the double-press');
        [$cancelled] = $first->update(new KeyMsg(KeyType::Escape, ''));

        $this->assertTrue($token->isCancelled(), 'the second Escape cancels the request the backend is holding');
        $this->assertNull($this->cancellationOf($cancelled), 'and the session drops the token with the turn');
        $this->assertNull($this->latchOf($cancelled), 'exactly as it drops the latch');
    }

    /**
     * Backlog §E32, second polarity: a provider that DOES honour the token — the
     * best-effort half of the {@see Backend} contract, which every other fake in this
     * file declines on purpose — costs the session nothing.
     *
     * The fake answers WITH a cost, which is what makes this non-vacuous. Drop the
     * token forwarding at the seam and the call resolves, `update()` accounts its
     * usage ahead of the latch check, and $0.20 is billed for a round-trip the user
     * cancelled: the assertion below is the one that goes red, and it is exactly the
     * money §E32 was filed about. That the ABANDONED-but-completed call stays billed
     * is still pinned by testAnAbandonedSummarizationIsStillBilled() beside this —
     * with a token-blind fake, which is now the honest way to spell that fixture.
     */
    public function testASummarizationStoppedByItsTokenCostsTheSessionNothing(): void
    {
        $tracker = new TokenTracker();
        $summarizer = new TokenPollingSummaryBackend($this->generousSummarizer()->reply(), 0.2);
        $chat = $this->chat(self::compactablePairs(), $summarizer, $main, $tracker);

        [$parked, $cmd] = $this->submit($chat);
        $token = $this->cancellationOf($parked);
        $this->assertInstanceOf(CancellationToken::class, $token, 'fixture: the parked call carries a token');

        [$first] = $parked->update(new KeyMsg(KeyType::Escape, ''));
        [$cancelled] = $first->update(new KeyMsg(KeyType::Escape, ''));
        $this->assertTrue($token->isCancelled(), 'fixture: Escape reached the request');

        $msg = $this->resolve($cmd);
        $this->assertInstanceOf(HistoryCompactedMsg::class, $msg);
        $this->assertNull($msg->usage, 'a call the provider stopped reports no figure at all');
        $this->assertNotNull($msg->error, 'and it reports why, rather than arriving as an empty success');

        [$after, $afterCmd] = $cancelled->update($msg);

        $this->assertSame(0.0, $after->spentUsd(), 'the session pays nothing for a call it cancelled');
        $this->assertNull($afterCmd, 'the cancelled turn still does not go out');
        $this->assertSame(0, $main->calls(), 'nor reaches the conversation backend');
        $this->assertSame(
            count($cancelled->history),
            count($after->history),
            'and the dropped landing rewrites nothing - the latch was released with the request',
        );
        $cancelledNotices = array_values(array_filter(
            $after->history,
            static fn(Message $m): bool => $m->content === '_Request cancelled._',
        ));
        $this->assertCount(1, $cancelledNotices, 'the cancellation is reported once, not twice');
    }

    /**
     * The breaker's counter read directly, since it is state and not behaviour. The
     * observable consequence — the fourth submit refusing — is asserted as a
     * consequence elsewhere; reading the number is what turns "it stopped" into "it
     * stopped after exactly this many", which is the claim §4.23 makes.
     */
    private function refillCountOf(Chat $chat): int
    {
        return (new \ReflectionProperty(Chat::class, 'consecutiveRefillCompactions'))->getValue($chat);
    }

    /**
     * A session with a draft in the box, through the same `mutate()` every other
     * route here uses. Needed by any test that drives MORE THAN ONE submit: parking
     * consumes the draft, and the second Enter would otherwise submit an empty line.
     */
    private function withDraft(Chat $chat, string $draft): Chat
    {
        return (new \ReflectionMethod(Chat::class, 'mutate'))->invoke($chat, ['inputBuf' => $draft]);
    }

    /**
     * The same session as {@see chat()}, partway through a run of refills. The
     * counter is a constructor argument because that is what it is on Chat —
     * promoted, immutable, carried by `mutate()` — and setting a fixture state
     * through the constructor is the honest version of setting it at all.
     *
     * @param list<Message> $history
     */
    private function chatWithRefills(
        array $history,
        ?Backend $summaryBackend,
        int $refills,
        ?RecordingTurnBackend &$main = null,
    ): Chat {
        $main = new RecordingTurnBackend(88_000);

        return new Chat(
            history: $history,
            inputBuf: 'what changed in the router?',
            backend: $main,
            summaryBackend: $summaryBackend,
            consecutiveRefillCompactions: $refills,
        );
    }

    /**
     * A figure read out of a message BY the label beside it. Returns -1 when the
     * label is absent, so a missing figure fails the comparison rather than
     * silently matching nothing.
     */
    private static function figureLabelled(string $text, string $pattern): int
    {
        return preg_match($pattern, $text, $m) === 1 ? (int) $m[1] : -1;
    }
}

/**
 * A conversation backend that records the history of every turn it is handed and
 * reports a fixed context window, so the tiers under test have a real number to
 * divide by.
 */
final class RecordingTurnBackend implements Backend, ReportsContextWindow
{
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

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->seen[] = $history;

        return Message::assistant('ok');
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->seen[] = $history;

        return \React\Promise\resolve(Message::assistant('ok'));
    }
}

/** A summarization backend answering with a fixed reply and counting its calls. */
final class CountingSummaryBackend implements Backend
{
    public int $calls = 0;

    public function __construct(private readonly string $reply) {}

    public function reply(): string
    {
        return $this->reply;
    }

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }
}

/**
 * A summarization backend that REPORTS what it cost, so the spend cap has a real
 * figure to cross. The plain CountingSummaryBackend answers with no usage at all,
 * which is a legitimate provider shape and accounts nothing.
 */
final class BillingSummaryBackend implements Backend
{
    public int $calls = 0;

    public function __construct(private readonly string $reply, private readonly float $costUsd) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply)->withUsage(Usage::new(1_000, $this->costUsd));
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(1_000, $this->costUsd)));
    }
}

/** A summarization backend whose call fails. */
final class FailingSummaryBackend implements Backend
{
    public function __construct(private readonly string $why) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        throw new \RuntimeException($this->why);
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        return \React\Promise\reject(new \RuntimeException($this->why));
    }
}

/**
 * A summarization backend that ACTS on the cancellation token — the best-effort half
 * of the {@see Backend} contract, which every other fake in this file declines on
 * purpose so that the latch, the billing order and the notice shapes can be tested
 * without a provider that stops mid-call.
 *
 * It answers WITH a usage figure on the path it does not cancel, so that a regression
 * which stops forwarding the token fails on the money rather than on a shape nobody
 * reads: the call would resolve, `update()` would account it ahead of the latch check,
 * and a cancelled round-trip would cost the session real dollars again — which is the
 * exact defect backlog §E32 was filed about.
 */
final class TokenPollingSummaryBackend implements Backend
{
    public int $calls = 0;

    public function __construct(private readonly string $reply, private readonly float $costUsd) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->calls++;

        return Message::assistant($this->reply)->withUsage(Usage::new(1_000, $this->costUsd));
    }

    public function completeAsync(
        array $history,
        callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->calls++;
        if ($cancellation !== null && $cancellation->isCancelled()) {
            return \React\Promise\reject(new \RuntimeException('summarisation stopped by its cancellation token'));
        }

        return \React\Promise\resolve(Message::assistant($this->reply)->withUsage(Usage::new(1_000, $this->costUsd)));
    }
}
