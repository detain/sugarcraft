<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\ReportsContextWindow;
use SugarCraft\Code\Backend\SummarisesWithCache;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\SubmitOptions;
use SugarCraft\Code\Host\TurnTicket;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Role;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Usage;

/**
 * ISS-064: the host that never compacts ahead of need. Two dormant TUI paths
 * gain production callers on {@see SessionHost}: the idle-compaction advisory
 * (the offer the TUI prints when a session sits idle past its whole window)
 * and the ahead-of-need summary (roadmap 2.10 — the 70% reminder tier asks for
 * model-written exchange records in the background so the 85% tier splices
 * them in instead of waiting on a round-trip).
 *
 * Mirrors the numbers of tests/Chat/BackgroundSummarySpliceTest.php: an
 * 88,000-token window puts the tiers at 61,600 / 74,800 / 83,600 estimated
 * tokens, and the 13-pair band below estimates ~62,500 — past the reminder
 * tier, under the blocking one.
 */
final class SessionHostAheadOfNeedTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-host-ahead-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->dir);
    }

    public function testAnIdleOverWindowSessionIsAdvisedBeforeItsPromptRuns(): void
    {
        $host = $this->host(new AheadBackend(1_000, 'ok', 'unused'));
        // One heavy pair plus ten trivial ones (10 exchanges are preserved
        // verbatim, so exactly the heavy one condenses): ~2,200 estimated
        // tokens past
        // the WHOLE 1,000-token window, which is what the idle offer reads —
        // and compaction frees the leading pair, so the turn is admitted.
        $rows = [Message::user(str_repeat('a', 4_000)), Message::assistant(str_repeat('b', 4_000))];
        for ($i = 0; $i < 10; $i++) {
            $rows[] = Message::user("q{$i}");
            $rows[] = Message::assistant("r{$i}");
        }
        self::setHistory($host, $rows);
        self::setLastPromptAt($host, new \DateTimeImmutable('-2 hours'));

        $ticket = $host->submit('still here?', SubmitOptions::new()->withIdempotencyKey('k'));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the host does not hold the prompt');
        $advisories = array_values(array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::System
                && str_contains($row->content, 'has been idle for over an hour'),
        ));
        self::assertCount(1, $advisories, 'the offer lands in the transcript');
        self::assertStringContainsString(' estimated tokens, past its 1000-token context window.', $advisories[0]->content, 'with the token figures filled in');

        // The stamp moved: a second submit, seconds later, must not advise again.
        $host->submit('and again', SubmitOptions::new()->withIdempotencyKey('k2'));
        $after = array_values(array_filter(
            $host->history(),
            static fn (Message $row): bool => $row->role === Role::System
                && str_contains($row->content, 'has been idle for over an hour'),
        ));
        self::assertCount(1, $after, 'the offer fires per idle gap, not per prompt');
    }

    public function testTheReminderTierAsksForTheSummaryBesideTheTurnAndHoldsIt(): void
    {
        $backend = new AheadBackend(88_000, 'ok', self::summaryReply());
        $host = $this->host($backend);
        self::setHistory($host, self::reminderBandPairs());

        $ticket = $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted);
        self::assertFalse($host->isBusy(), 'the immediate-resolve double settled inline');
        self::assertSame(1, $backend->summaryCalls, 'the 70% tier asked once, beside the turn');
        self::assertSame(
            array_map(static fn (Message $m): string => $m->content, self::reminderBandPairs()),
            array_map(static fn (Message $m): string => $m->content, Message::agentVisible($backend->summarySeen)),
            'the summarizer reads the conversation as it stood before this turn',
        );

        $held = self::held($host);
        self::assertNotNull($held, 'the request is held for the 85% tier');
        self::assertNotNull($held['summaries'], 'the immediate-resolve double landed it');
        self::assertArrayHasKey(\SugarCraft\Code\Context\Compaction\StateSummaryTemplate::SUMMARY_KEY, $held['summaries']);
        self::assertCount(4, $held['fingerprint']->exchangeKeys(), 'the three heavy exchanges and q0 leave the verbatim tail');
        foreach ($host->history() as $row) {
            self::assertStringNotContainsString('[summary]', $row->content, 'holding applies nothing');
        }
    }

    /**
     * CTX-4 on the served route: a HOLD lands in the slot as a discarded
     * summary plus its detail. The 85% tier never holds and never refuses on
     * account of the audit — the request was already accepted, and holding it
     * would 400 the client — so the tier folds on the heuristic exactly as it
     * would with no summary at all, but the fold's report carries the
     * advisory naming the audit, and the slot is used up rather than re-asked.
     */
    public function testAHeldSafeguardHoldFoldsTheTierAndSaysWhy(): void
    {
        $backend = new AheadBackend(88_000, str_repeat('z', 60_000), self::collapsedSummaryReply());
        $host = $this->host($backend);
        $rows = self::reminderBandPairs();
        $rows[1] = $rows[1]->withToolResults([
            new \SugarCraft\Code\ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Router.php']),
            new \SugarCraft\Code\ToolResult('Read', 'contents', arguments: ['file_path' => 'src/Handler.php']),
        ]);
        self::setHistory($host, $rows);

        $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));
        $held = self::held($host);
        self::assertNotNull($held['safeguardHold'] ?? null, 'the HOLD detail is remembered in the slot');
        self::assertSame([], $held['summaries'], 'the collapsed summaries are discarded, never held for splice');

        $ticket = $host->submit('x', SubmitOptions::new()->withIdempotencyKey('k2'));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the fold sends the turn; served never holds');
        self::assertNull(self::held($host), 'the slot is used up by the fold it announced');
        $advisories = array_values(array_filter(
            $host->history(),
            static fn (Message $row): bool => str_contains($row->content, 'discarded by the safeguard audit'),
        ));
        self::assertCount(1, $advisories, 'the advisory lands once, beside the fold');
        self::assertStringContainsString('compacted with the local heuristic instead', $advisories[0]->content);
        $text = self::agentText($host->history());
        self::assertStringContainsString('[summary]', $text, 'the heuristic lines landed');
        self::assertStringNotContainsString('collapsed record', $text, 'the model records did not');
        self::assertSame(1, $backend->summaryCalls, 'the hold does not re-ask the summarizer');
    }

    /**
     * A block writing three of the eight model sections and carrying neither
     * of the two file bullets the fallback records from the tool rows: the
     * only shape the audit calls collapse. The records beside it are sound —
     * the block arm is what fires.
     */
    private static function collapsedSummaryReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = "{$i}.\nasked: collapsed record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nwork happened\n## Progress\nsome exchanges\n"
            . "## Next step\ncontinue\n</session-state>";
    }

    public function testTheEightyFiveTierSplicesTheHeldSummaryWithoutReasking(): void
    {
        $backend = new AheadBackend(88_000, str_repeat('z', 60_000), self::summaryReply());
        $host = $this->host($backend);
        self::setHistory($host, self::reminderBandPairs());

        // Turn one: the 70% tier arms the request, the large reply pushes the
        // history past the 85% tier for the next submit.
        $host->submit('first question', SubmitOptions::new()->withIdempotencyKey('k'));
        self::assertIsArray(self::held($host)['summaries'] ?? null, 'the background summary landed');

        $ticket = $host->submit('x', SubmitOptions::new()->withIdempotencyKey('k2'));

        self::assertSame(TurnTicket::STARTED, $ticket->admitted, 'the 85% tier sent at once, not parked');
        self::assertNull(self::held($host), 'the summary is used up');
        $text = self::agentText($host->history());
        self::assertStringContainsString('ahead-of-need record 1', $text, "the model's records were spliced in");
        self::assertStringContainsString('keep the router fast', $text, 'and its state block');
        self::assertStringNotContainsString(str_repeat('a', 1_000), $text, 'the heavy exchanges were condensed');
        self::assertSame(1, $backend->summaryCalls, 'no second summarization was paid for');
        self::assertStringContainsString('ahead-of-need record 2', self::agentText($backend->lastHistory), 'the provider read the summaries');
    }

    /**
     * 13 exchanges: three of ~20,750 estimated tokens and ten trivial ones, for
     * ~62,500 — over the 70% tier of an 88,000-token window, under the 85% one.
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

    /** One record per offered exchange, and a state block. */
    private static function summaryReply(): string
    {
        $records = [];
        for ($i = 1; $i <= 6; $i++) {
            $records[] = "{$i}.\nasked: ahead-of-need record {$i}";
        }

        return implode("\n", $records)
            . "\n<session-state>\n## Goal\nkeep the router fast\n</session-state>";
    }

    private function host(Backend $backend): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: $backend, hooks: null),
            customCommands: [],
        );
    }

    /** @param list<Message> $history */
    private static function setHistory(SessionHost $host, array $history): void
    {
        (new \ReflectionProperty(SessionHost::class, 'history'))->setValue($host, $history);
    }

    private static function setLastPromptAt(SessionHost $host, ?\DateTimeImmutable $at): void
    {
        (new \ReflectionProperty(SessionHost::class, 'lastPromptAt'))->setValue($host, $at);
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
 * The one backend the host shares for turns and summarization: a fixed window,
 * a fixed turn reply, an immediate-resolve summary reply. It takes the
 * cache-reusing route (roadmap 2.4-2) like the launch's, so the summary call
 * lands on `summariseAsync` and never disturbs the turn counters.
 */
final class AheadBackend implements Backend, ReportsContextWindow, SummarisesWithCache
{
    public int $turnCalls = 0;

    public int $summaryCalls = 0;

    /** @var list<Message> what the last turn dispatch sent to the provider */
    public array $lastHistory = [];

    /** @var list<Message> what the summarizer was sent */
    public array $summarySeen = [];

    public function __construct(
        private readonly int $window,
        private readonly string $reply,
        private readonly string $summaryReply,
    ) {
    }

    public function contextWindow(): int
    {
        return $this->window;
    }

    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->turnCalls++;
        $this->lastHistory = $history;

        return Message::assistant($this->reply);
    }

    public function completeAsync(
        array $history,
        ?callable $onToken = null,
        ?CancellationToken $cancellation = null,
        ?callable $onEvent = null,
    ): PromiseInterface {
        $this->turnCalls++;
        $this->lastHistory = $history;

        return \React\Promise\resolve(Message::assistant($this->reply));
    }

    public function summariseAsync(array $history, string $instruction, ?CancellationToken $cancellation = null): PromiseInterface
    {
        $this->summaryCalls++;
        $this->summarySeen = $history;

        return \React\Promise\resolve(Message::assistant($this->summaryReply)->withUsage(Usage::new(100, 0.0)));
    }
}
