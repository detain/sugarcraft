<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Chat;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\AsyncCmd;
use SugarCraft\Core\BatchMsg;
use SugarCraft\Code\App\AssistantMsg;
use SugarCraft\Code\App\ContextMeasuredMsg;
use SugarCraft\Code\App\HistoryCompactedMsg;
use SugarCraft\Code\Backend;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Backend\ReportsPromptSections;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Context\Compaction\HistoryFingerprint;
use SugarCraft\Code\Context\Compaction\StateSummaryTemplate;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextBreakdown;
use SugarCraft\Code\Context\ContextCategories;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Context\ContextCompactor;
use SugarCraft\Code\Host\CompactionService;
use SugarCraft\Code\Host\TurnRunner;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;

/**
 * P-CTX.d: the context measurement is a scheduled Cmd, never a frame cost.
 * A turn's settle and every compaction landing answer with a
 * {@see ContextMeasuredMsg} nudge routed as a no-op repaint, and the
 * categories they land in {@see TurnRunner::heldContext()} are the fresh
 * ones — the pane reads, it never fetches.
 */
final class ContextSnapshotScheduleTest extends TestCase
{
    private string $dir;
    private string|false $suggestions;

    protected function setUp(): void
    {
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/ctx-sched-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);
        // One Cmd per settle where a sibling can help it, so the pins read plainly.
        $this->suggestions = getenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS');
        putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=1');
    }

    protected function tearDown(): void
    {
        $this->suggestions === false ? putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS') : putenv('CANDY_CODE_DISABLE_PROMPT_SUGGESTIONS=' . $this->suggestions);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
    }

    public function testTheSettledTurnSchedulesTheMeasurementOntoItsRunner(): void
    {
        $runner = TurnRunner::new();
        $chat = $this->chatWithRunner($runner, new SectionsDouble());

        [$settled, $cmd] = $chat->update(new AssistantMsg(Message::assistant('Done.')));

        self::assertFalse($settled->inFlight);
        self::assertNotNull($cmd, 'the settle schedules the pass the pane cannot run itself');
        $nudges = self::dispatchCmd($cmd);
        self::assertCount(1, $nudges, 'one nudge, carrying the session it measured');
        self::assertSame('ctx-a', $nudges[0]->sessionId);

        $snapshot = $runner->heldContext('ctx-a');
        self::assertInstanceOf(ContextCategories::class, $snapshot);
        self::assertSame(10, self::tokensFor($snapshot, ContextCategory::SystemPrompt), "the double's one measured layer priced through the whole chain");
    }

    public function testACompactionLandingRemeasuresOnTopOfTheStaleStamp(): void
    {
        $runner = TurnRunner::new();
        // A fold needs a condensible leading run: big early exchanges plus a
        // verbatim tail (the house's idle shape) — a two-row transcript has
        // nothing to fold and the landing releases without rewriting.
        $history = [];
        for ($i = 0; $i < 4; $i++) {
            $history[] = Message::user(str_repeat(chr(97 + $i), 51_000));
            $history[] = Message::assistant(str_repeat(chr(111 + $i), 51_000));
        }
        for ($i = 0; $i < 8; $i++) {
            $history[] = Message::user("late question {$i}");
            $history[] = Message::assistant("late answer {$i}");
        }
        $stale = ContextCategories::from(ContextBreakdown::measure($history, null, null, 100_000, 1));
        $runner->saveContext('ctx-a', $stale);
        $fingerprint = CompactionService::new()->historyFingerprint(new ContextCompactor(CompactorConfig::new()), $history);

        $chat = $this->chatWithRunner($runner, new EchoBackend(), [
            'history' => $history,
            'inFlight' => false,
            'compactorConfig' => CompactorConfig::new()->withIdleAuto(true),
            'pendingIdleCompaction' => ['id' => 'ic-1', 'fingerprint' => $fingerprint, 'sessionId' => 'ctx-a'],
        ]);

        [$next, $cmd] = $chat->update(new HistoryCompactedMsg('ic-1', self::foldRecords($fingerprint), fingerprint: $fingerprint));

        self::assertNotNull($cmd, 'the landing rides its own report Cmd and the remeasure together');
        $nudges = self::dispatchCmd($cmd);
        self::assertCount(1, $nudges, 'exactly one remeasure lands with the fold');
        self::assertNotSame($stale, $runner->heldContext('ctx-a'), 'the landing that changed the transcript replaced its stamp');
        self::assertSame('ctx-a', $nudges[0]->sessionId);
    }

    public function testTheNudgeRoutesAsANoOpRepaint(): void
    {
        $chat = $this->chatWithRunner(TurnRunner::new(), new EchoBackend());

        [$next, $cmd] = $chat->update(new ContextMeasuredMsg('ctx-a'));

        self::assertSame($chat, $next, 'the stamp lives on the runner, so the model itself does not move');
        self::assertNull($cmd, 'and it arms nothing — the paint the dispatch causes is the whole job');
    }

    public function testASnapshotForAnotherSessionIsNotTheOneThePaneReads(): void
    {
        $runner = TurnRunner::new();
        $chat = $this->chatWithRunner($runner, new SectionsDouble());

        [, $cmd] = $chat->update(new AssistantMsg(Message::assistant('Done.')));
        self::dispatchCmd($cmd);

        self::assertNotNull($runner->heldContext('ctx-a'));
        self::assertNull($runner->heldContext('ctx-b'), 'a second session reads its own empty slot, not the first one\'s stamp');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function chatWithRunner(TurnRunner $runner, Backend $backend, array $overrides = []): Chat
    {
        return new Chat(
            ...($overrides + [
                'history' => [Message::user('the prompt')],
                'inFlight' => true,
                'backend' => $backend,
                'currentSessionId' => 'ctx-a',
                'projectRoot' => $this->dir,
                'workspace' => WorkspaceContext::new(root: $this->dir)->withService(TurnRunner::class, $runner),
            ]),
        );
    }

    /**
     * Run one scheduled Cmd the way the loop would — batches fan out, async
     * factories settle — and return every {@see ContextMeasuredMsg} the chain
     * dispatches.
     *
     * @return list<ContextMeasuredMsg>
     */
    private static function dispatchCmd(\Closure $cmd): array
    {
        $produced = $cmd();
        if ($produced instanceof AsyncCmd) {
            $resolved = null;
            $produced->promise->then(static function (mixed $msg) use (&$resolved): void {
                $resolved = $msg;
            });
            $produced = $resolved;
        }

        if ($produced instanceof BatchMsg) {
            $msgs = [];
            foreach ($produced->cmds as $sub) {
                foreach (self::dispatchCmd($sub) as $msg) {
                    $msgs[] = $msg;
                }
            }

            return $msgs;
        }

        return $produced instanceof ContextMeasuredMsg ? [$produced] : [];
    }

    private static function foldRecords(HistoryFingerprint $fingerprint): array
    {
        $records = [];
        foreach ($fingerprint->exchangeKeys() as $key) {
            $records[$key] = 'one idle fold record ' . $key;
        }

        return [...$records, StateSummaryTemplate::SUMMARY_KEY => StateSummaryTemplate::new()->render()];
    }

    private static function tokensFor(ContextCategories $categories, ContextCategory $category): ?int
    {
        foreach ($categories->rows as $row) {
            if ($row['category'] === $category) {
                return $row['tokens'];
            }
        }

        throw new \LogicException('no row for ' . $category->value);
    }
}

/**
 * The one-layer prompt the measure prices: 'base' is a system-prompt layer,
 * and the pass must carry its 10 tokens into the snapshot unchanged.
 */
final class SectionsDouble implements Backend, ReportsPromptSections
{
    public function complete(array $history, ?callable $onToken = null, ?callable $onEvent = null): Message
    {
        throw new \LogicException('a settle measurement must not call the model');
    }

    public function completeAsync(array $history, ?callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): \React\Promise\PromiseInterface
    {
        throw new \LogicException('a settle measurement must not call the model');
    }

    public function promptSectionSizes(): array
    {
        return [['label' => 'base', 'stability' => 'static', 'sections' => 1, 'bytes' => 40, 'tokens' => 10]];
    }
}
