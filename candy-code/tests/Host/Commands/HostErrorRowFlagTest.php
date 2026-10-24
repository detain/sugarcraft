<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use React\EventLoop\Loop;
use SugarCraft\Code\App\BackgroundSessionSpawnedMsg;
use SugarCraft\Code\App\BackgroundSessionStoppedMsg;
use SugarCraft\Code\Backend\EchoBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Host\Commands\BackgroundCommand;
use SugarCraft\Code\Host\Commands\BtwHostCommand;
use SugarCraft\Code\Host\Commands\HandoffHostCommand;
use SugarCraft\Code\Host\Commands\HandoffSeededMsg;
use SugarCraft\Code\Host\Commands\WorkflowCommand;
use SugarCraft\Code\Host\SessionHost;
use SugarCraft\Code\Host\WorkspaceContext;
use SugarCraft\Code\Message;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Session\EnhancedSessionStore;
use SugarCraft\Code\Background\BackgroundStopOutcome;
use SugarCraft\Code\App\SideQuestionAnsweredMsg;
use SugarCraft\Code\Workflows\WorkflowEngineInterface;
use SugarCraft\Code\Workflows\WorkflowResult;
use SugarCraft\Code\Workflows\WorkflowStatus;

/**
 * ISS-097 (producer half): every host notice row that conveys a failure or
 * error carries the error flag AT ITS PRODUCER — the renderer paints
 * `Message::$error` rows in the error colour and never sniffs the text, and
 * the row builders' verdict parameters default to "fine", so a producer that
 * forgets compiles, runs, and quietly paints a refusal as an ordinary local
 * reply.
 *
 * The `chat.provider.switch_failed` and `host.command.error` keys are covered
 * by the sibling flagging test on the F2 branch; this file owns the
 * bg/workflow/handoff/btw surface and the wider `Host\Commands` reply flip.
 */
final class HostErrorRowFlagTest extends TestCase
{
    private string $dir;

    private EnhancedSessionStore $store;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/crush-bgflag-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
        $this->store = new EnhancedSessionStore($this->dir . '/session.db');
        $this->store->createSession('s', 'p', 'm');
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }

    // ── the not-configured refusals, end to end through a real host ──────

    public function testABgWithoutSupervisorLandsItsRowFlagged(): void
    {
        $host = $this->plainHost();

        $host->submit('/bg rewrite the changelog');

        $rows = $host->history();
        self::assertCount(2, $rows);
        self::assertFalse($rows[0]->error, 'the echo of what the user typed is not an error');
        self::assertTrue($rows[1]->error, 'the refusal IS one (ISS-097)');
        self::assertTrue($rows[1]->uiOnly);

        $host->release();
    }

    public function testAWorkflowWithoutEngineLandsItsRowFlagged(): void
    {
        $host = $this->plainHost();

        $host->submit('/workflow run deploy');

        $rows = $host->history();
        self::assertTrue($rows[1]->error, 'engine-not-configured is a failure the user must see as one');
        self::assertTrue($rows[1]->uiOnly);

        $host->release();
    }

    // ── the settled-spawn / settled-stop verdicts ─────────────────────────

    public function testASpawnedRowCarriesTheSpawnsOwnVerdict(): void
    {
        $failed = BackgroundCommand::spawnRow(new BackgroundSessionSpawnedMsg('/bg', 'job', null, 'socket refused'));
        self::assertInstanceOf(Message::class, $failed);
        self::assertTrue($failed->error, 'start_failed lands flagged (ISS-097)');
        self::assertStringContainsString('Could not start background session', $failed->content);

        $ok = BackgroundCommand::spawnRow(new BackgroundSessionSpawnedMsg('/bg', 'job', 'bg_1'));
        self::assertInstanceOf(Message::class, $ok);
        self::assertFalse($ok->error, 'a backgrounded session is an ordinary answer');

        self::assertNull(BackgroundCommand::spawnRow(new \stdClass()), 'another command\'s value gets no row');
    }

    public function testAStoppedRowFlagsOnlyTheOutcomesThatFailed(): void
    {
        $flagged = [BackgroundStopOutcome::UnknownSession, BackgroundStopOutcome::CouldNotStop];
        $plain = [
            BackgroundStopOutcome::AlreadyFinished,
            BackgroundStopOutcome::StoppedViaIpc,
            BackgroundStopOutcome::StoppedViaSignal,
        ];
        foreach ($flagged as $outcome) {
            $row = BackgroundCommand::stopRow(new BackgroundSessionStoppedMsg('bg_1', $outcome, 'job', 'boom'));
            self::assertInstanceOf(Message::class, $row);
            self::assertTrue($row->error, $outcome->name . ' did not achieve the stop (ISS-097)');
        }
        foreach ($plain as $outcome) {
            $row = BackgroundCommand::stopRow(new BackgroundSessionStoppedMsg('bg_1', $outcome, 'job'));
            self::assertInstanceOf(Message::class, $row);
            self::assertFalse($row->error, $outcome->name . ' is not a failure');
        }
    }

    public function testTheHandoffRowIsFlaggedExactlyWhenNoSessionOpened(): void
    {
        $failed = HandoffHostCommand::row(new HandoffSeededMsg('parent', null, 'seed', false, null, 'store rejected the write'));
        self::assertInstanceOf(Message::class, $failed);
        self::assertTrue($failed->error, 'a handoff that opened nothing FAILED');

        $ok = HandoffHostCommand::row(new HandoffSeededMsg('parent', 'child-1', 'seed', true));
        self::assertInstanceOf(Message::class, $ok);
        self::assertFalse($ok->error);

        self::assertNull(HandoffHostCommand::row(new \stdClass()));
    }

    public function testASideQuestionRowIsPlainBecauseItsVerdictIsUnCarried(): void
    {
        $row = BtwHostCommand::answerRowMessage(new SideQuestionAnsweredMsg(null, 'an answer'));
        self::assertInstanceOf(Message::class, $row);
        // Disclosed boundary: `host.btw.failed` is woven INTO the answer text
        // with no verdict field on the DTO, so the producer cannot tell an
        // answer from a failure without widening SideQuestionAnsweredMsg —
        // a ruling the row flagging must not preempt by guessing.
        self::assertFalse($row->error);
        self::assertTrue($row->uiOnly);
    }

    // ── the /workflow occupy channel: reports resolve, so the row carries ──

    public function testTheWorkflowErrorRowIsFlagged(): void
    {
        $row = WorkflowCommand::errorRow('engine exploded');
        self::assertTrue($row->error);
        self::assertTrue($row->uiOnly);
        self::assertStringContainsString('**Error:** engine exploded', $row->content);
    }

    public function testADrivenFiberPassesAMessageReportThroughVerbatimAndAStringStaysAString(): void
    {
        $loop = Loop::get();

        $thrown = new \Fiber(static fn () => WorkflowCommand::errorRow('the fiber threw'));
        $resolved = null;
        $timer = $loop->addTimer(5.0, static fn () => $loop->stop());
        WorkflowCommand::drive($thrown, 0.005)->then(static function (mixed $report) use ($loop, &$resolved): void {
            $resolved = $report;
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($timer);

        self::assertInstanceOf(Message::class, $resolved, 'the fiber\'s verdict survives the drive to the landing point');
        self::assertTrue($resolved->error);

        $plain = null;
        $okFiber = new \Fiber(static fn () => 'Workflow \'deploy\' completed');
        $guard = $loop->addTimer(5.0, static fn () => $loop->stop());
        WorkflowCommand::drive($okFiber, 0.005)->then(static function (mixed $report) use ($loop, &$plain): void {
            $plain = $report;
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($guard);
        self::assertSame('Workflow \'deploy\' completed', $plain, 'a successful report still rides the plain string channel');
    }

    public function testAPausedWorkflowRefusalLandsFlaggedThroughTheHost(): void
    {
        $engine = new class () implements WorkflowEngineInterface {
            public function run(string $workflowPath, array $context = [], ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult('wf-1', WorkflowStatus::Completed);
            }

            public function pause(string $workflowId): void
            {
                throw new \RuntimeException('no run under that id');
            }

            public function resume(string $workflowId, ?\SugarCraft\Code\Backend\CancellationToken $cancellation = null): WorkflowResult
            {
                return new WorkflowResult($workflowId, WorkflowStatus::Completed);
            }

            public function status(string $workflowId): WorkflowStatus
            {
                return WorkflowStatus::Running;
            }

            public function listWorkflows(): array
            {
                return [];
            }
        };
        $host = SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: new EchoBackend(), workflowEngine: $engine),
            customCommands: [],
        );

        $host->submit('/workflow pause nope');

        $rows = $host->history();
        self::assertTrue($rows[1]->error, 'the refusal sentence arrives flagged, not as an ordinary local reply');

        $host->release();
    }

    // ── the render half: the flag is what paints the row ──────────────────

    public function testTheFlaggedBgFailureRowRendersInTheErrorStyle(): void
    {
        $frame = explode("\n", Renderer::render(new Chat(history: [
            Message::user('/bg job'),
            BackgroundCommand::spawnRow(new BackgroundSessionSpawnedMsg('/bg', 'job', null, 'socket refused')),
            BackgroundCommand::spawnRow(new BackgroundSessionSpawnedMsg('/bg', 'job', 'bg_1')),
        ])));

        // renderErrorTurn emits its label on its own row above the body; a
        // flagged row is thus `error:` immediately over its text, while an
        // unflagged local row is led by `local` and trailed by no hint.
        $failedAt = $this->indexOfRowWith($frame, 'Could not start background session');
        self::assertNotNull($failedAt, 'the failed row is in the frame');
        self::assertSame('error', $this->plain($frame[$failedAt - 1]), 'the flagged row is led by the error label');
        self::assertMatchesRegularExpression('/\e\[[0-9;]*m/', $frame[$failedAt - 1], 'and the label carries styling, not bare text');

        $okAt = $this->indexOfRowWith($frame, 'Backgrounded as bg_1');
        self::assertNotNull($okAt);
        self::assertStringStartsWith('local', $this->plain($frame[$okAt - 1]), 'its successful sibling keeps the plain local label');
        self::assertStringNotContainsString('error', $this->plain($frame[$okAt - 1]));
    }

    /** @param list<string> $rows @return int|null the index of the first row whose plain text contains $needle */
    private function indexOfRowWith(array $rows, string $needle): ?int
    {
        foreach ($rows as $i => $row) {
            if (str_contains($this->plain($row), $needle)) {
                return $i;
            }
        }

        return null;
    }

    private function plain(string $row): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $row);
    }

    /**
     * The census half of the law: no failure-worded reply in the command
     * package may re-appear through the plain {@see CommandResult::reply()}
     * door. The roster is every host.* key whose text says the command did
     * not happen; each must be produced behind errorReply / respond(true) /
     * the flagged row builders, so a new unflagged call site reddens here.
     *
     * @return list<array{0: string}>
     */
    public static function failureKeyRoster(): array
    {
        return [
            ['host.bg.not_configured'],
            ['host.rename.no_store'],
            ['host.rename.no_session'],
            ['host.rename.error'],
            ['host.fork.no_store'],
            ['host.btw.needs_title_model'],
            ['host.handoff.no_store'],
            ['host.handoff.no_session'],
            ['host.handoff.nothing_yet'],
            ['host.workflow.not_configured'],
            ['host.workflow.error'],
            ['host.diff.error'],
            ['host.checkpoint.no_store'],
            ['host.checkpoint.no_session'],
            ['host.checkpoint.error'],
            ['host.redo.error'],
            ['host.undo.error'],
            ['host.undo.refused'],
            ['host.agents.not_configured'],
            ['host.permissions.mode_refused'],
            ['host.memory.not_configured'],
        ];
    }

    /** @param string $key one roster entry, as the provider lists it */
    #[\PHPUnit\Framework\Attributes\DataProvider('failureKeyRoster')]
    public function testNoFailureWordedReplyBuildsThroughThePlainReplyDoor(string $key): void
    {
        $dir = __DIR__ . '/../../../src/Host/Commands';
        foreach (scandir($dir) ?: [] as $entry) {
            if (!str_ends_with($entry, '.php')) {
                continue;
            }
            $file = $dir . '/' . $entry;
            $text = (string) file_get_contents($file);
            // The single-line `CommandResult::reply($text, Lang::t('key'` and
            // the multi-line refusal arms both start the same way; the flat
            // needle catches the one-line shape, and `reply($text, Lang::t(`
            // before the key names the door exactly.
            self::assertStringNotContainsString(
                "CommandResult::reply(\$text, Lang::t('" . $key . "'",
                $text,
                basename($file) . ' lands a failure row through the unflagged reply door (ISS-097)',
            );
        }
    }

    private function plainHost(): SessionHost
    {
        return SessionHost::new(
            's',
            WorkspaceContext::new(sessionStore: $this->store, backend: new EchoBackend()),
            customCommands: [],
        );
    }
}
