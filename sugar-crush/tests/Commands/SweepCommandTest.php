<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\PruningCommand;
use SugarCraft\Crush\Commands\SweepCommand;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\PruneAuthor;
use SugarCraft\Crush\Context\Pruning\PruneEntry;
use SugarCraft\Crush\Context\Pruning\PruneKind;
use SugarCraft\Crush\Context\Pruning\PruneReason;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\ToolCall;
use SugarCraft\Crush\ToolResult;

/**
 * Roadmap 3.B-2: `/sweep [n]` prunes by hand the tool outputs since the last
 * prompt, or the last n, minus what it must not or need not touch; `/pruning`
 * shows and sets the session's mode. Both are pure over the rows and the
 * ledger, and say exactly what they did.
 */
final class SweepCommandTest extends TestCase
{
    public function testABareSweepPrunesTheOutputsSinceTheLastPrompt(): void
    {
        [$ledger, $reply] = SweepCommand::run(self::history(), self::hosted(), '');

        $this->assertSame(['new1', 'new2'], array_keys($ledger->prunes), 'only the newest turn\'s outputs');
        $entry = $ledger->prune('new1');
        $this->assertSame([PruneKind::Output, PruneReason::Swept, PruneAuthor::User], [$entry?->kind, $entry?->reason, $entry?->by]);
        $this->assertGreaterThan(0, $entry?->tokens);
        $this->assertStringStartsWith('Swept 2 tool outputs (~', $reply);
        $this->assertStringContainsString('Read ×1, Grep ×1; files: code ×1.', $reply, 'roadmap 3.B-5: the files the swept calls named, by category');
        $this->assertStringContainsString('Skipped: protected Task; 1 too small to be worth a placeholder.', $reply);
    }

    public function testACountReachesBackAcrossTurnsAndAlreadyPrunedIsSaid(): void
    {
        $ledger = self::hosted()->withPrune(new PruneEntry('new2', PruneKind::Output, PruneReason::Aged, PruneAuthor::Strategy, 1));

        [$swept, $reply] = SweepCommand::run(self::history(), $ledger, '5');

        $this->assertSame(['new2', 'old1', 'new1'], array_keys($swept->prunes));
        $this->assertSame(PruneReason::Aged, $swept->prune('new2')?->reason, 'an earlier prune is left as it was');
        $this->assertStringContainsString('1 already pruned', $reply);
        $this->assertStringContainsString('Swept 2 tool outputs', $reply);
    }

    public function testNothingToSweepAndBadArgumentsChangeNothing(): void
    {
        $ledger = self::hosted();
        $prompted = [...self::history(), Message::user('and now?')];

        [$same, $reply] = SweepCommand::run($prompted, $ledger, '');
        $this->assertSame($ledger, $same);
        $this->assertStringStartsWith('Nothing to sweep: no tool has answered since your last prompt.', $reply);

        foreach (['0', '-2', 'all', '1.5'] as $bad) {
            [$same, $reply] = SweepCommand::run(self::history(), $ledger, $bad);
            $this->assertSame($ledger, $same, $bad);
            $this->assertSame(SweepCommand::USAGE, $reply, $bad);
        }
    }

    public function testAnOffSessionRefusesToSweep(): void
    {
        $ledger = ContextLedger::new()->withMode(PruningMode::Off);

        [$same, $reply] = SweepCommand::run(self::history(), $ledger, '');

        $this->assertSame($ledger, $same);
        $this->assertStringStartsWith('Context pruning is off for this session', $reply);
    }

    public function testPruningShowsAndSetsTheSessionsMode(): void
    {
        $ledger = ContextLedger::new()->withDefaultMode(PruningMode::Manual);

        [$same, $reply] = PruningCommand::run($ledger, '');
        $this->assertSame($ledger, $same);
        $this->assertStringStartsWith('Context pruning: manual — nothing is pruned on its own', $reply);
        $this->assertStringContainsString('The configured mode (`contextPruning.mode` / `SUGARCRUSH_CONTEXT_PRUNING`).', $reply);

        [$set, $reply] = PruningCommand::run($ledger, ' OFF ');
        $this->assertSame(PruningMode::Off, $set->mode);
        $this->assertStringStartsWith('Context pruning: off — ', $reply);
        $this->assertStringEndsWith('Set for this session with /pruning.', $reply);

        [$cleared, $reply] = PruningCommand::run($set, 'default');
        $this->assertNull($cleared->mode);
        $this->assertStringStartsWith('This session now follows the configured mode. Context pruning: manual', $reply);

        [$same, $reply] = PruningCommand::run($ledger, 'sometimes');
        $this->assertSame($ledger, $same);
        $this->assertSame(PruningCommand::USAGE, $reply);
    }

    /** A session ledger as the host hands it over: following the configured `auto`. */
    private static function hosted(): ContextLedger
    {
        return ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    /**
     * An earlier turn that read a big file, then a turn that read, grepped,
     * delegated and listed a tiny directory; a legacy row (no step) between.
     *
     * @return list<Message>
     */
    private static function history(): array
    {
        $big = str_repeat('line of output ', 200);

        return [
            Message::user('first'),
            ...self::call('old1', 'Read', ['file_path' => 'old.php'], $big, 's_a_1'),
            Message::assistant('legacy output')->withToolResults([new ToolResult('Read', 'legacy output', null, 'legacy')]),
            Message::user('second'),
            ...self::call('new1', 'Read', ['file_path' => 'new.php'], $big, 's_b_1'),
            ...self::call('new2', 'Grep', ['pattern' => 'TODO'], $big, 's_b_1'),
            ...self::call('task', 'Task', ['description' => 'explore'], $big, 's_b_2'),
            ...self::call('tiny', 'Glob', ['pattern' => '*.md'], 'a.md', 's_b_3'),
            Message::assistant('done')->withStepId('s_b_4'),
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return list<Message>
     */
    private static function call(string $id, string $tool, array $arguments, string $output, string $step): array
    {
        return [
            Message::assistant('')->withToolCalls([new ToolCall($tool, $arguments, $id)])->withStepId($step)->withUserVisible(false),
            Message::assistant($output)->withToolResults([new ToolResult($tool, $output, null, $id)])->withStepId($step),
        ];
    }
}
