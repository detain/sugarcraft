<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\Live\AgentRunCards;
use SugarCraft\Code\Agents\Live\AgentRunStatus;
use SugarCraft\Code\Host\Commands\AgentsHostCommand;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * `agents tree` renders the delegated runs' parent → children lineage (A3).
 *
 * The law here has two sides. The WRITE side: every launch card carries the
 * launcher's identity the moment TaskTool mints it — the structural pin reads
 * that stamp out of the launch itself, because a tree with no parent leg is a
 * flat list wearing an indent. The READ side: the fold renders every card
 * exactly once, in stable (startedAt, runId) order, indented under its
 * launcher — an orphan whose parent has no card rides the top level LOUDLY
 * rather than disappearing with its subtree, and forged cyclic lineage marks
 * instead of hanging the walk. The command half is the same rows as text, and
 * every card field crosses the one-line transcript filter, so a card cannot
 * forge a row or a color.
 */
final class AgentsTreeCommandTest extends TestCase
{
    use HomeSandboxTrait;

    /** A pid no live process can hold — the shape a settled run's card keeps. */
    private const DEAD_PID = 2_147_483_000;

    /** Fixed launch epoch: the tree's order is derived, never ambient. */
    private const T0 = 1_700_000_000_000;

    private string $sandbox = '';
    private string $home = '';
    private string $project = '';
    private string $session = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-agents-tree-' . bin2hex(random_bytes(6));
        $this->home = $this->useHomeSandbox($this->sandbox . '/home');
        $this->project = $this->sandbox . '/project';
        mkdir($this->project, 0700, true);
        $this->session = 'sess-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    // ------------------------------------------------------------------ write side

    /**
     * The parent leg is not a read-side guess — the launch itself writes it.
     * Structural pin over the one card-mint site: if the stamp is dropped,
     * every depth assertion below would still pass on a flat list, so THIS
     * one has to bind the source directly.
     */
    public function testTheLaunchStampsTheLauncherIdentityIntoTheCardItWrites(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../src/Tools/BuiltIn/TaskTool.php');

        $start = strpos($source, 'recordRun($cardScope, [');
        self::assertNotFalse($start, 'the card-mint site still opens with this call');
        $end = strpos($source, "            ]);", $start);
        self::assertNotFalse($end, 'the call still closes its argument list at this indent');
        $block = substr($source, $start, $end - $start);

        self::assertStringContainsString(
            "'parent' => \$this->parentAgentId ?? SendMessageTool::MAIN,",
            $block,
            'a launch without the launcher leg is a lineage the tree command cannot rebuild later',
        );
    }

    /** The field survives the disk round-trip the fold reads back through. */
    public function testTheParentFieldRoundTripsThroughTheCardStore(): void
    {
        $this->card('run-kid', AgentRunCards::MAIN, self::T0, ['description' => 'inspect the parser']);

        $children = AgentRunCards::childrenOf($this->session, AgentRunCards::MAIN);
        self::assertCount(1, $children);
        self::assertSame('run-kid', $children[0]['runId']);
        self::assertSame(AgentRunCards::MAIN, $children[0]['parent'], 'read back exactly what was written');
    }

    // ------------------------------------------------------------------- read side

    /** Three generations nest under the session; a second top-level run follows. */
    public function testEachGenerationIndentsBeneathItsLauncher(): void
    {
        $this->card('run-a', AgentRunCards::MAIN, self::T0);
        $this->card('run-b', 'run-a', self::T0 + 1);
        $this->card('run-c', 'run-b', self::T0 + 2);
        $this->card('run-d', AgentRunCards::MAIN, self::T0 + 3);

        $rows = AgentRunStatus::tree($this->session);
        self::assertSame(['run-a', 'run-b', 'run-c', 'run-d'], array_map(
            static fn (array $row): string => $row['card']['runId'],
            $rows,
        ), 'pre-order: a parent always prints above its children');
        self::assertSame([0, 1, 2, 0], array_column($rows, 'depth'));
        self::assertSame([false, false, false, false], array_column($rows, 'orphan'));
        self::assertSame([false, false, false, false], array_column($rows, 'cycle'));

        self::assertSame([
            'Agent run tree, oldest first (indent marks the launcher):',
            '- run-a · agent "coder" · lost',
            '  - run-b · agent "coder" · lost',
            '    - run-c · agent "coder" · lost',
            '- run-d · agent "coder" · lost',
        ], $this->replyLines($this->runAgents('/agents tree')));
    }

    /** A swept launcher must not take its still-listed delegations out of sight. */
    public function testAVanishedLauncherSurfacesItsChildrenLoudlyInsteadOfHidingThem(): void
    {
        $this->card('run-orphan', 'ghost-launcher', self::T0);
        $this->card('run-orphan-child', 'run-orphan', self::T0 + 1);

        $rows = AgentRunStatus::tree($this->session);
        self::assertSame(['run-orphan', 'run-orphan-child'], array_map(
            static fn (array $row): string => $row['card']['runId'],
            $rows,
        ));
        self::assertSame([0, 1], array_column($rows, 'depth'));
        self::assertSame([true, false], array_column($rows, 'orphan'));

        $lines = $this->replyLines($this->runAgents('/agents tree'));
        self::assertCount(3, $lines, 'heading plus both rows — nothing dropped');
        self::assertStringContainsString('- run-orphan', $lines[1]);
        self::assertStringContainsString('LAUNCHED BY ghost-launcher, WHICH HAS NO CARD HERE', $lines[1]);
        self::assertStringStartsWith('  - run-orphan-child', $lines[2], 'the child still rides under the loud orphan');
    }

    /** Mutual parents are a forged shape; the walk marks the island and moves on. */
    public function testForgedCyclicLineageRendersAndTerminates(): void
    {
        $this->card('run-loop-a', 'run-loop-b', self::T0);
        $this->card('run-loop-b', 'run-loop-a', self::T0 + 1);

        $rows = AgentRunStatus::tree($this->session);
        self::assertSame(['run-loop-a', 'run-loop-b'], array_map(
            static fn (array $row): string => $row['card']['runId'],
            $rows,
        ), 'each card once — a cycle that prints twice is worse than one that hangs');
        self::assertSame([0, 1], array_column($rows, 'depth'));
        self::assertSame([true, false], array_column($rows, 'cycle'));
        self::assertSame([false, false], array_column($rows, 'orphan'), 'a cycle is not an orphan — the parent card exists, it just never lands');

        $lines = $this->replyLines($this->runAgents('/agents tree'));
        self::assertStringContainsString('PARENT CYCLE', $lines[1]);
    }

    /** Same arrival instant: the run id decides, never the order files were written. */
    public function testSiblingsSharingAnArrivalStampAreOrderedByRunId(): void
    {
        $this->card('run-b', AgentRunCards::MAIN, self::T0);
        $this->card('run-a', AgentRunCards::MAIN, self::T0);

        $lines = $this->replyLines($this->runAgents('/agents tree'));
        self::assertStringContainsString('- run-a', $lines[1], 'ids ascending at a tie — byte-stable for two runs of the same cards');
        self::assertStringContainsString('- run-b', $lines[2]);
    }

    /** The row carries the status the reader gives, including the running/lost verdict. */
    public function testALiveRunReadsRunningAndASettledRunCarriesItsOutcome(): void
    {
        $this->card('run-live', AgentRunCards::MAIN, self::T0, ['pid' => (int) getmypid()]);
        $this->card('run-done', AgentRunCards::MAIN, self::T0 + 1, ['status' => 'complete']);

        $lines = $this->replyLines($this->runAgents('/agents tree'));
        self::assertStringContainsString('- run-live · agent "coder" · running', $lines[1]);
        self::assertStringContainsString('- run-done · agent "coder" · complete', $lines[2], 'a settled card is never rewritten');

        $this->card('run-lost', AgentRunCards::MAIN, self::T0 + 2);
        $lines = $this->replyLines($this->runAgents('/agents tree'));
        self::assertStringContainsString('- run-lost · agent "coder" · lost', $lines[3], 'running with a dead pid reads as lost, same as the tools answer');
    }

    // ---------------------------------------------------------------- command shape

    /** `tree` takes no argument; one more word is the usage refusal its siblings give. */
    public function testExtraArgumentsRefuseWithTheUsageLine(): void
    {
        $result = $this->runAgents('/agents tree --everything');

        // CommandResult::failure carries the exit code in the row shape (the
        // notice row, not an assistant reply), not in ->errorCode — the same
        // shape the `proposed` sibling's usage refusal has.
        $rows = $result->rows;
        self::assertCount(2, $rows, 'the echo plus one notice row');
        self::assertSame('/agents tree --everything', (string) $rows[0]->content);
        self::assertSame(Lang::t('host.agents.usage'), (string) $rows[count($rows) - 1]->content);
    }

    /** Empty on record and nothing to look under are both answered in words. */
    public function testNothingOnRecordAndNothingToLookUnderAreBothSentences(): void
    {
        $this->card('run-here', AgentRunCards::MAIN, self::T0);

        $elsewhere = $this->runAgents('/agents tree', 'sess-not-mine-' . bin2hex(random_bytes(4)));
        self::assertNull($elsewhere->errorCode, 'an empty tree is an answer, never a failure');
        $rows = $elsewhere->rows;
        self::assertSame('No delegated agent runs are on record for this session.', (string) $rows[count($rows) - 1]->content);

        $noId = (new AgentsHostCommand())->run(CommandContext::new(root: $this->project), '/agents tree');
        self::assertNull($noId->errorCode);
        $rows = $noId->rows;
        self::assertSame(
            'No run cards to show: this session has no id to look its delegated runs up under.',
            (string) $rows[count($rows) - 1]->content,
        );
    }

    /**
     * Card text is untrusted on read: a forged agent name cannot mint a second
     * transcript row and cannot color the screen. The card reader's own clip
     * flattens whitespace runs — so the newline never reaches the line filter
     * as a newline at all — and the sanitizer strips the escape before print.
     * Both floors stay pinned here because the fold must survive either one
     * regressing.
     */
    public function testCardFieldsCannotForgeATranscriptLine(): void
    {
        $this->card('run-forge', AgentRunCards::MAIN, self::T0, ['agent' => "e\n\x1b[31mfake"]);

        $lines = $this->replyLines($this->runAgents('/agents tree'));

        self::assertCount(2, $lines, 'heading plus one row — the embedded newline cannot mint a third line');
        self::assertStringNotContainsString("\x1b", implode("\n", $lines), 'no escape sequence reaches the screen');
        self::assertSame('- run-forge · agent "e fake" · lost', $lines[1], 'the payload shows as flattened text, inert');
    }

    // -------------------------------------------------------------------- helpers

    /**
     * Mint a card in exactly the shape a launch writes: dead pid by default so
     * nothing here depends on ambient processes, live pid via override.
     *
     * @param array<string, mixed> $overrides
     */
    private function card(string $runId, string $parent, int $startedAt = self::T0, array $overrides = []): void
    {
        self::assertTrue(AgentRunCards::recordRun($this->session, $overrides + [
            'runId' => $runId,
            'agent' => 'coder',
            'description' => '',
            'parent' => $parent,
            'inboxSession' => $this->session,
            'status' => AgentRunCards::STATUS_RUNNING,
            'pid' => self::DEAD_PID,
            'startedAt' => $startedAt,
        ]));
    }

    private function runAgents(string $text, ?string $sessionId = null): CommandResult
    {
        return (new AgentsHostCommand())->run(
            CommandContext::new(root: $this->project, sessionId: $sessionId ?? $this->session),
            $text,
        );
    }

    /**
     * @return list<string>
     */
    private function replyLines(CommandResult $result): array
    {
        $rows = $result->rows;

        return explode("\n", (string) $rows[count($rows) - 1]->content);
    }
}
