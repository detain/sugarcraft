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

    /** Enough numbered lines that every offered exchange gets one. */
    private function generousSummarizer(): CountingSummaryBackend
    {
        $lines = [];
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = "{$i}. condensed exchange {$i}";
        }

        return $this->summarizer(implode("\n", $lines));
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
     * `generation` and the cancellation token belong to a backend turn, so
     * neither is armed yet.
     */
    public function testTheParkedWindowHoldsInFlightWithoutArmingATurn(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        $before = $this->generationOf($chat);

        [$parked] = $this->submit($chat);

        $this->assertTrue($parked->inFlight, 'a turn IS going to happen, so Enter must be swallowed meanwhile');
        $this->assertNotNull($this->latchOf($parked), 'the summarization latch is armed');
        $this->assertSame($before, $this->generationOf($parked), 'no turn has started, so no generation was consumed');
        $this->assertNull($this->cancellationOf($parked), 'and there is nothing to cancel yet');
        $this->assertSame('', $parked->inputBuf, 'the draft was consumed by pressing Enter');
    }

    /**
     * Every keystroke except Ctrl+C and Escape is swallowed while the submission
     * is parked. This is what makes the set of routes that can abandon a parked
     * turn a closed one — `/clear`, `/rewind` and the palette's New session
     * action are all typed or Ctrl+P'd, and none of them is reachable here — so
     * the double-Escape arm is the only one that had to be taught about the
     * latch.
     */
    public function testTheParkedWindowSwallowsEveryKeystrokeThatCouldReachACommand(): void
    {
        $chat = $this->chat(self::compactablePairs(), $this->generousSummarizer(), $main);
        [$parked] = $this->submit($chat);

        [$typed, $typedCmd] = $parked->update(new KeyMsg(KeyType::Char, '/'));
        $this->assertSame('', $typed->inputBuf, 'no draft can be started, so no command can be typed');
        $this->assertNull($typedCmd);

        [$entered, $enteredCmd] = $parked->update(new KeyMsg(KeyType::Enter, ''));
        $this->assertNull($enteredCmd, 'Enter cannot submit a second turn on top of the parked one');
        $this->assertSame(count($parked->history), count($entered->history));

        [$palette, $paletteCmd] = $parked->update(new KeyMsg(KeyType::Char, 'p', ctrl: true));
        $this->assertNull($paletteCmd);
        $this->assertNull(
            (new \ReflectionProperty(Chat::class, 'palette'))->getValue($palette),
            'Ctrl+P cannot open the palette, so its New session action is unreachable too',
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
        $this->assertStringContainsString('[summary] condensed exchange 1', $text);
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

            $saved = $store->getCheckpoint('parked-session', 0);
            $this->assertNotNull($saved);
            $this->assertStringContainsString(
                'what changed in the router?',
                json_encode($saved, JSON_THROW_ON_ERROR),
                'and what it saved is the history the parked turn went out with',
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
