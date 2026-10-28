<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CacheReusingSummaryBackend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * CLAW-new#5: a refused summarisation must never cost the user their prompt.
 * The three provider-side refusals that stand between a session and its
 * heuristic fold — a 408, a 504 and the engine's own idle-stall ceiling — are
 * pinned HERE, on both hosts, through the one wrapper that serves summaries:
 * {@see CacheReusingSummaryBackend}.
 *
 * The pins ride that pair on purpose (C-CTX-5.a). Its two new retry legs fire
 * only on a window overflow and on a failing `summaryModel` override; these
 * three errors are NEITHER (the stall text carries no overflow wording, and a
 * bare non-engine wrapper has no override to drop), so the honest expectation
 * is exactly the pre-C-CTX-5.a behaviour: ONE call, a rejection, a heuristic
 * fold, and the turn the user pressed Enter for still goes out — served or
 * in the terminal. That is also why the fold's history is asserted to be
 * UNCHANGED bytes: a retry that should not fire, did not.
 *
 * Scope note for the served leg: production hands SessionHost the conversation
 * backend itself (the session's own engine, which answers summaries through
 * {@see SummarisesWithCache}); the TUI routes its summaries through the cache-
 * reusing wrapper. The legs below pin both shapes: a refusal costs ONE call,
 * lands the heuristic fold, and the user's prompt still goes out — and because
 * the retry legs live in the wrapper, a plain refusal changes nothing about the
 * landing either way.
 */
final class CompactionFallbackParityTest extends TestCase
{
    /**
     * The provider's own words on the three refusals. The stall text is the
     * EXACT shape EngineBackend's watchdog teardown produces.
     *
     * @return array<string, array{0: string}>
     */
    public static function refusals(): array
    {
        return [
            '408 request timeout' => ['Gateway Timeout (HTTP 408): the summary request was refused'],
            '504 upstream timeout' => ['Gateway Timeout (HTTP 504): the summary request was refused'],
            'idle-stall ceiling' => ['Provider request timed out after 90s without progress'],
        ];
    }

    #[DataProvider('refusals')]
    public function testTheParkedTurnProceedsOnAHeuristicFoldAfterARefusal(string $refusal): void
    {
        $engine = new ParityRejectingEngine($refusal);
        $chat = new Chat(
            history: self::overTheTier(),
            inputBuf: 'what changed in the router?',
            backend: new ParityTurnBackend(88_000, 9),
            summaryBackend: CacheReusingSummaryBackend::new(
                new ParityTurnBackend(88_000, 9),
                $engine,
            ),
        );

        [$parked, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertNotNull($cmd, 'the tier parks the turn behind the summary request');
        self::assertTrue($parked->inFlight);

        $landing = self::resolve($cmd);
        self::assertInstanceOf(HistoryCompactedMsg::class, $landing);
        self::assertSame($refusal, $landing->error, 'the refusal is reported, never swallowed');
        self::assertSame([], $landing->summaries, 'no records: the fold is the pre-model heuristic shape');
        self::assertSame('what changed in the router?', $landing->parkedSubmission, 'the prompt survives the failure');

        [$landed, $turn] = $parked->update($landing);
        self::assertNotNull($turn, 'the parked turn goes out against the heuristically-compacted history');
        self::assertTrue($landed->inFlight);

        $text = self::agentText($landed->history);
        self::assertStringContainsString('[summary]', $text, 'the heuristic lines landed');
        self::assertStringContainsString('what changed in the router?', $text, 'the echoed prompt is in the transcript');
        self::assertSame(1, $engine->calls, 'a refused summary is not retried through this layer when no retry leg applies');
    }

    #[DataProvider('refusals')]
    public function testTheServedTurnProceedsOnAHeuristicFoldAfterARefusal(string $refusal): void
    {
        $store = self::sandboxStore();
        // The shape production hands SessionHost: the conversation backend
        // itself answers summaries (the TUI leg above pins the same refusal
        // riding the cache-reusing wrapper). Either way the landing is the
        // service's fold — this leg pins that it lands and the turn goes out.
        $backend = new ParityTurnBackend(88_000, 1, $refusal);
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(
                sessionStore: $store,
                backend: $backend,
                hooks: null,
            ),
            customCommands: [],
        );
        self::setHistory($host, self::reminderBandPairs());

        // Turn one: the 70% tier asks for the background summary — and the
        // refusal lands in the slot as the EMPTY fold, exactly as the
        // safeguard-hold shape does, so the 85% tier never re-asks.
        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));
        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        $held = self::held($host);
        self::assertNotNull($held, 'the request was armed beside the turn');
        self::assertSame([], $held['summaries'], 'the refusal lands as the empty fold, not as model records');
        self::assertSame(1, $backend->summaryCalls, 'the refusal is not retried through this layer when no retry leg applies');

        // Turn two crosses the 85% tier (the first turn's fat reply is in the
        // history): with the empty fold held, the tier compacts with the
        // heuristic inline and SENDS — served never holds on compaction.
        $ticketTwo = $host->submit('x', SubmitOptions::new()->withIdempotencyKey('k2'));
        self::assertSame(TurnTicket::STARTED, $ticketTwo->admitted, 'the fold sends the turn; served never holds');
        self::assertNull(self::held($host), 'the empty fold is used up, never re-asked');
        $text = self::agentText($host->history());
        self::assertStringContainsString('[summary]', $text, 'the heuristic lines landed beside the turn');
        self::assertSame(
            1,
            $backend->summaryCalls,
            'the refusal costs exactly one billed summary across both served submits',
        );
        self::assertSame(2, $backend->turnCalls, 'both turns went out');
    }

    /**
     * The fold is the PRE-C-CTX-5.a shape: with nothing to retry on, the
     * history the engine was handed is the conversation byte-for-byte — no
     * clipping happened (clipping belongs to the overflow leg alone).
     */
    public function testTheRefusedSummaryWasAskedAgainstTheUnclippedConversation(): void
    {
        $engine = new ParityRejectingEngine('Gateway Timeout (HTTP 504): the summary request was refused');
        $history = self::overTheTier();
        $history[1] = $history[1]->withToolResults([
            new \SugarCraft\Code\ToolResult('Read', 'contents', arguments: ['file_path' => 'src/RouterTable.php']),
        ]);
        $chat = new Chat(
            history: $history,
            inputBuf: 'what changed in the router?',
            backend: new ParityTurnBackend(88_000, 1),
            summaryBackend: CacheReusingSummaryBackend::new(new ParityTurnBackend(88_000, 9), $engine),
        );

        [, $cmd] = $chat->update(new KeyMsg(KeyType::Enter, ''));
        self::assertNotNull($cmd);
        self::resolve($cmd);

        self::assertSame(1, $engine->calls);
        $seen = Message::agentVisible($engine->seen);
        self::assertSame(
            array_map(static fn (Message $m): string => $m->content, $history),
            array_map(static fn (Message $m): string => $m->content, $seen),
            'a plain refusal sends the same bytes, not a clipped second request',
        );
    }

    private static function resolve(\Closure $cmd): mixed
    {
        $asyncCmd = $cmd();
        self::assertInstanceOf(AsyncCmd::class, $asyncCmd);
        $resolved = null;
        $asyncCmd->promise->then(static function ($msg) use (&$resolved): void {
            $resolved = $msg;
        });

        return $resolved;
    }

    /** Twelve plain exchanges plus three heavy pairs: over the 85% tier of an 88,000-token window. */
    private static function overTheTier(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 52_000));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 52_000));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("question {$i} about the router");
            $history[] = Message::assistant("answer {$i}: the router reads routes.php");
        }

        return $history;
    }

    /**
     * The served reminder band of tests/Host/SessionHostAheadOfNeedTest.php:
     * three heavy exchanges and ten trivial ones — past the 70% tier, under
     * the 85% one, of an 88,000-token window.
     *
     * @return list<Message>
     */
    private static function reminderBandPairs(): array
    {
        $history = [];
        for ($i = 0; $i < 3; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 41_500));
            $history[] = Message::assistant(str_repeat(chr(110 + $i), 41_500));
        }
        for ($i = 0; $i < 10; $i++) {
            $history[] = Message::user("q{$i}");
            $history[] = Message::assistant("r{$i}");
        }

        return $history;
    }

    private static function sandboxStore(): EnhancedSessionStore
    {
        $dir = sys_get_temp_dir() . '/crush-fallback-parity-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $store = new EnhancedSessionStore($dir . '/session.db');
        $store->createSession('s', 'p', 'm');

        return $store;
    }

    /** @param list<Message> $history */
    private static function setHistory(SessionHost $host, array $history): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))->setValue($host, $history);
    }

    /** @return null|array{id:string,fingerprint:mixed,summaries:?array<string,string>} */
    private static function held(SessionHost $host): ?array
    {
        return (new \ReflectionProperty(SessionHost::class, 'aheadOfNeed'))->getValue($host);
    }

    /** @param list<Message> $history */
    private static function agentText(array $history): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, Message::agentVisible($history)));
    }
}

/**
 * A summary engine that refuses, once, with the provider's own words — the
 * shape every 408/504/stall rejection arrives in after the fork flattens it
 * ({@see CacheReusingSummaryBackend::retrySummaryOnce()} honest scope). It is
 * deliberately NOT an EngineBackend: the fallback leg needs a real override to
 * drop, so the only call this double answers is the one that refuses.
 */
final class ParityRejectingEngine implements SummarisesWithCache
{
    public int $calls = 0;

    /** @var list<Message> what the single refused call was sent */
    public array $seen = [];

    public function __construct(private readonly string $refusal)
    {
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->calls++;
        $this->seen = $history;

        return \React\Promise\reject(new \RuntimeException($this->refusal));
    }
}

/**
 * The turn backend beside the refusals: a fixed window and a fixed reply,
 * counting every turn dispatch so "the prompt still went out" is a number and
 * not a feeling. Given a refusal text it is also the served conversation
 * backend: its summary answer is that single rejection (the fat turn reply is
 * what pushes the next submit past the 85% tier).
 */
final class ParityTurnBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    public function __construct(
        private readonly int $window,
        private readonly int $which,
        private readonly ?string $refusal = null,
    ) {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->turnCalls++;

        return Message::assistant($this->reply());
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;

        return \React\Promise\resolve(Message::assistant($this->reply()));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;
        if ($this->refusal === null) {
            throw new \LogicException('This double answers summaries only when it carries a refusal.');
        }

        return \React\Promise\reject(new \RuntimeException($this->refusal));
    }

    private function reply(): string
    {
        // The served tier-crossing needs the fat answer (which=1); every other
        // consumer answers trivially so the counters stay the only variable.
        return $this->which === 1 ? str_repeat('z', 60_000) : 'ok';
    }
}
