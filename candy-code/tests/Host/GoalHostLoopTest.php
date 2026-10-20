<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Goal\GoalMode;
use SugarCraft\Code\Goal\GoalState;
use SugarCraft\Code\GoalJudgedMsg;
use SugarCraft\Code\Goal\GoalVerdict;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Tests\Support\ScriptedProvider;
use SugarCraft\Code\Usage;

/**
 * W7a-3: the `/goal` continuation loop on a SERVED session. The decision
 * table is the shared {@see \SugarCraft\Code\Goal\GoalLoop}'s — these pins
 * prove the host runs it end to end: a missed goal sends the agent back to
 * work round after round until the budget is spent, a met verdict stops the
 * loop at once, a failed or absent judge call stops it with the reason, the
 * spend cap stops it BEFORE judging, and a prompt the user queued goes first
 * while the goal waits.
 *
 * The W7a-1 sink parity rides through here as well: every judge call is
 * billed to the JUDGE's model in usage_records, never the session's.
 */
final class GoalHostLoopTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-goalloop-' . bin2hex(random_bytes(6));
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

    public function testAMissedGoalSendsTheAgentBackUntilTheBudgetIsSpent(): void
    {
        $judge = new ScriptedProvider([self::verdictReply(false, 40, ['the demo still does not run'])]);
        $host = $this->loopHost($judge);

        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $listing = $this->listing($host);
        // The loop ground all ten rounds and the exhausted-budget stop fired
        // with the judge's still-missing list — GoalLoop's own sentence.
        self::assertStringContainsString('Goal not met after 10 follow-up rounds (score 40/100): ship the demo.', $listing);
        self::assertStringContainsString('Still missing: the demo still does not run.', $listing);
        self::assertStringContainsString('"round":10,"status":"stopped"', $listing);
        self::assertFalse($host->isBusy());

        // W7a-1 parity, served: every judge call bills the JUDGE's model —
        // the call itself happens in the turn's forked child, so the durable
        // usage rows, not a parent-side spy, are the count's ground truth.
        $models = $this->recordedModels();
        self::assertArrayHasKey('judge-model', $models);
        self::assertSame(11, $models['judge-model']);
        self::assertArrayHasKey('turn-model', $models);
        self::assertSame(11, $models['turn-model']);
    }

    public function testAMetVerdictEndsTheLoopAtOnce(): void
    {
        $judge = new ScriptedProvider([self::verdictReply(true, 95)]);
        $host = $this->loopHost($judge);

        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $listing = $this->listing($host);
        self::assertStringContainsString('Goal met (score 95/100): ship the demo', $listing);
        self::assertStringContainsString('"status":"met"', $listing);
        self::assertSame(2, substr_count($listing, GoalState::MARKER_PREFIX), 'the live round-0 marker and its met twin — nothing else was written');
        self::assertStringNotContainsString('follow-up 1 of 10', $listing, 'a met verdict sends no next round');
        self::assertSame(1, $this->recordedModels()['judge-model'] ?? 0, 'one turn settled, one judge call, loop over');
    }

    public function testAFailedJudgeCallStopsTheGoalNamingTheError(): void
    {
        $judge = new ScriptedProvider([new \RuntimeException('judge transport boom')]);
        $host = $this->loopHost($judge);

        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $listing = $this->listing($host);
        self::assertStringContainsString('Goal check stopped: the judge call failed: judge transport boom.', $listing);
        self::assertStringContainsString('"status":"stopped"', $listing);
    }

    public function testTheSpendCapStopsTheLoopBeforeTheJudgeIsDialed(): void
    {
        $judge = new ScriptedProvider([self::verdictReply(false, 10, ['more work'])]);
        // The kickoff turn itself costs past the cap: settle bills it, and
        // the schedule door refuses before the first judge call.
        $host = $this->loopHost($judge, cap: 0.1, turnCost: 0.5);

        $host->runCommand('goal', 'ship the demo');
        $this->drain($host);

        $listing = $this->listing($host);
        self::assertStringContainsString('Goal check stopped: the spend cap is reached.', $listing);
        self::assertStringContainsString('"status":"stopped"', $listing);
        self::assertArrayNotHasKey('judge-model', $this->recordedModels(), 'a capped session never spends on judging');
    }

    public function testTheUsersQueuedPromptGoesFirstAndTheGoalWaits(): void
    {
        // The land-side queue check is driven directly: while the host waits
        // on a verdict, the user's own next message is already admitted to the
        // queue, and the release rule is the terminal's — the user goes first.
        $judge = new ScriptedProvider([self::verdictReply(false, 10, ['more work'])]);
        $host = $this->loopHost($judge, verdictsAfterFirstTurn: false);
        $host->submit('a plain turn first');
        $this->drain($host);

        self::addMarker($host, GoalState::new(GoalMode::Goal, 'ship the demo'));
        $generation = self::property($host, 'generation');
        self::setProperty($host, 'turn', new CancellationToken());
        self::setProperty($host, 'queue', ['the user typed this next']);

        self::invoke($host, GoalState::new(GoalMode::Goal, 'ship the demo'), $generation);
        $this->drain($host);

        // The queued prompt took the slot and no follow-up round went out;
        // the user's turn carried the conversation on, and its own settle
        // judged the still-live goal — here with no title engine, so it stops
        // naming that, the faithful terminal semantics.
        $listing = $this->listing($host);
        self::assertStringContainsString('the user typed this next', $listing);
        self::assertStringNotContainsString('still missing', strtolower($listing), 'the goal does not spend a round while the user talks');
        self::assertStringNotContainsString('"round":1', $listing);
        self::assertStringContainsString('Goal check stopped: no title model is configured to judge it.', $listing);
        self::assertStringContainsString('"round":0,"status":"stopped"', $listing);
    }

    public function testALateVerdictIsBilledAndDropped(): void
    {
        $judge = new ScriptedProvider([self::verdictReply(true, 99)]);
        $host = $this->loopHost($judge, verdictsAfterFirstTurn: false);

        // Stale generation (a cancel already moved on): the usage still
        // lands — the call was really made — but the decision never does.
        self::setProperty($host, 'turn', new CancellationToken());
        $stale = new GoalJudgedMsg(self::property($host, 'generation') + 1, 's', GoalVerdict::new(true, 99, []), null, Usage::new(10, 0.002));
        self::invokeLand($host, $stale);

        $listing = $this->listing($host);
        self::assertStringNotContainsString('Goal met', $listing);
        self::assertSame(1, $this->recordedModels()['turn-model'] ?? 0, 'the dropped verdict is still billed, through the fallback sink');

        // Wrong session: same fate.
        $foreign = new GoalJudgedMsg(self::property($host, 'generation'), 'someone-elses-session', GoalVerdict::new(true, 99, []), null, Usage::new(10, 0.002));
        self::invokeLand($host, $foreign);
        self::assertStringNotContainsString('Goal met', $this->listing($host));
        self::assertTrue($host->isBusy(), 'a dropped verdict never releases a live turn');
    }

    public function testARefusedFollowUpRoundLandsItsReasonBesideTheMarker(): void
    {
        $judge = new ScriptedProvider([self::verdictReply(false, 10, ['more work'])]);
        // Cap reached AFTER a billed turn: schedule would have stopped, so
        // this drives the land-side continue directly into a refused submit.
        $host = $this->loopHost($judge, cap: 0.1, turnCost: 0.5, verdictsAfterFirstTurn: false);
        $host->submit('cost the cap');
        $this->drain($host);
        self::addMarker($host, GoalState::new(GoalMode::Goal, 'ship the demo'));

        self::setProperty($host, 'turn', new CancellationToken());
        self::invoke($host, GoalState::new(GoalMode::Goal, 'ship the demo'), self::property($host, 'generation'));

        $listing = $this->listing($host);
        self::assertStringContainsString('"round":1,"status":"active"', $listing, 'the round was spent the moment the decision was made');
        self::assertStringContainsString('this turn was not sent', $listing, 'the refusal reason lands as an error row, the way a refused command kickoff does');
    }

    public function testARefusedCommandKickoffLandsItsReasonBesideTheRows(): void
    {
        // W7a-4, the w6f-queued seam: the set rows land and the kickoff
        // submit itself is refused at admission - the reason belongs beside
        // the marker, not swallowed. The turn-costly host is already over
        // its cap when `/goal` arrives, so admit refuses the first round.
        $judge = new ScriptedProvider([self::verdictReply(false, 10, ['x'])]);
        $host = $this->loopHost($judge, cap: 0.1, turnCost: 0.5);
        $host->submit('cost the cap');
        $this->drain($host);

        $host->runCommand('goal', 'ship the demo');

        $listing = $this->listing($host);
        self::assertStringContainsString('Goal set: ship the demo', $listing);
        self::assertStringContainsString('"round":0,"status":"active"', $listing);
        self::assertStringContainsString('this turn was not sent', $listing);
        self::assertFalse($host->isBusy(), 'the refusal never started a turn');
    }

    /**
     * A host whose turns answer with $turnCost spend and whose title engine
     * answers with $judge. With verdictsAfterFirstTurn the settle tail must
     * NOT judge (no title engine), for the tests that drive the land side
     * directly.
     */
    private function loopHost(ScriptedProvider $judge, ?float $cap = null, float $turnCost = 0.001, bool $verdictsAfterFirstTurn = true): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(
                sessionStore: $this->store,
                backend: new EngineBackend(new ScriptedProvider([new CompleteResponse('did the thing', usage: Usage::new(100, $turnCost))]), 'turn-model'),
                titleBackend: $verdictsAfterFirstTurn ? new EngineBackend($judge, 'judge-model') : null,
                maxCostUsd: $cap,
            ),
            history: [],
            customCommands: [],
        );
    }

    private static function verdictReply(bool $complete, int $score, array $missing = []): CompleteResponse
    {
        return new CompleteResponse(
            json_encode(['complete' => $complete, 'score' => $score, 'missing' => $missing], JSON_THROW_ON_ERROR),
            usage: Usage::new(50, 0.002),
        );
    }

    private function listing(SessionHost $host): string
    {
        return implode("\n", array_map(static fn (Message $m): string => $m->content, $host->history()));
    }

    /** @return array<string,int> model => recorded rows */
    private function recordedModels(): array
    {
        $pdo = new PDO('sqlite:' . $this->dir . '/session.db');
        $rows = $pdo->query('SELECT model, COUNT(*) FROM usage_records GROUP BY model')->fetchAll(PDO::FETCH_NUM);
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row[0]] = (int) $row[1];
        }

        return $out;
    }

    private static function addMarker(SessionHost $host, GoalState $goal): void
    {
        $history = self::property($host, 'history');
        $history[] = $goal->toMessage();
        self::setProperty($host, 'history', $history);
    }

    private static function invoke(SessionHost $host, GoalState $goal, int $generation): void
    {
        $msg = new GoalJudgedMsg($generation, 's', GoalVerdict::new(false, 10, ['more work']), null, Usage::new(50, 0.002));
        self::invokeLand($host, $msg);
    }

    private static function invokeLand(SessionHost $host, GoalJudgedMsg $msg): void
    {
        (new \ReflectionMethod(SessionHost::class, 'landGoalVerdict'))->invoke($host, $msg);
    }

    private static function property(SessionHost $host, string $name): mixed
    {
        return (new \ReflectionProperty(SessionHost::class, $name))->getValue($host);
    }

    private static function setProperty(SessionHost $host, string $name, mixed $value): void
    {
        (new \ReflectionProperty(SessionHost::class, $name))->setValue($host, $value);
    }

    private function drain(SessionHost $host): void
    {
        $loop = \React\EventLoop\Loop::get();
        $poll = $loop->addPeriodicTimer(0.01, static function () use ($host, $loop): void {
            if (!$host->isBusy()) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static fn () => $loop->stop());
        $loop->run();
        $loop->cancelTimer($poll);
        $loop->cancelTimer($guard);

        self::assertFalse($host->isBusy(), 'the turn settled');
    }
}
