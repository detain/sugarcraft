<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Context\Pruning\ContextLedger;
use SugarCraft\Crush\Context\Pruning\ContextProjector;
use SugarCraft\Crush\Context\Pruning\LedgerDelta;
use SugarCraft\Crush\Context\Pruning\NudgePolicy;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message as TypedMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Roadmap 3.B-4 (DCP §13.2 E / §4.7): the context-management reminder is
 * ANCHORED on one tool result or prompt and re-rendered there — never on an
 * assistant row, never moving along the tail — added only in `auto`, past the
 * minimum, spaced by the frequency, and cleared after the model managed its
 * context (the cooldown).
 */
final class NudgePolicyTest extends TestCase
{
    public function testNothingIsDueBelowTheMinimumOrOutsideAuto(): void
    {
        $rows = self::steps(3);

        $this->assertTrue(NudgePolicy::new()->decide(NudgePolicy::MIN_CONTEXT_TOKENS, $rows, self::auto())->isEmpty());
        $this->assertTrue(NudgePolicy::new()->decide(200_000, $rows, self::auto()->withMode(PruningMode::Manual))->isEmpty(), 'manual: the person manages');
        $this->assertTrue(NudgePolicy::new()->decide(200_000, $rows, ContextLedger::new())->isEmpty(), 'a ledger no host keeps');
    }

    public function testATurnsPromptGetsTheTurnNudge(): void
    {
        $rows = [...self::steps(3), new UserMessage('next task')];

        $delta = NudgePolicy::new()->decide(80_000, $rows, self::auto());

        $this->assertSame([ContextLedger::userRowKey('next task', 1) => NudgePolicy::KIND_TURN], $delta->nudges);
    }

    public function testALongRunGetsTheIterationNudgeOnItsNewestResult(): void
    {
        $this->assertTrue(NudgePolicy::new()->decide(80_000, self::steps(9), self::auto())->isEmpty(), 'nine results is not yet a long run');

        $delta = NudgePolicy::new()->decide(80_000, self::steps(10), self::auto());
        $this->assertSame(['c10' => NudgePolicy::KIND_ITERATION], $delta->nudges);
    }

    public function testPastTheMaximumTheNewestRowGetsTheLimitNudge(): void
    {
        $delta = NudgePolicy::new()->decide(NudgePolicy::MAX_CONTEXT_TOKENS + 1, self::steps(2), self::auto());

        $this->assertSame(['c2' => NudgePolicy::KIND_LIMIT], $delta->nudges);
    }

    public function testAnchorsAreSpacedByTheFrequency(): void
    {
        $ledger = self::auto()->withNudge('c8', NudgePolicy::KIND_ITERATION);

        $this->assertTrue(NudgePolicy::new()->decide(200_000, self::steps(12), $ledger)->isEmpty(), 'four rows since the last anchor');
        $this->assertSame(['c13' => NudgePolicy::KIND_LIMIT], NudgePolicy::new()->decide(200_000, self::steps(13), $ledger)->nudges, 'five');
    }

    public function testASuccessfulPruneStartsTheCooldown(): void
    {
        $ledger = self::auto()->withNudge('c1', NudgePolicy::KIND_ITERATION);
        $managed = [
            ...self::steps(12),
            new AssistantMessage('', [new ToolCall('p1', 'Prune', ['targets' => []])]),
            new ToolResultMessage('p1', 'Pruned 3 outputs'),
        ];

        $delta = NudgePolicy::new()->decide(200_000, $managed, $ledger);
        $this->assertTrue($delta->clearNudges);
        $this->assertSame([], $delta->nudges);
        $this->assertSame([], $ledger->apply($delta)->nudges);

        $failed = [...\array_slice($managed, 0, -1), new ToolResultMessage('p1', 'Error: nothing was pruned.', true)];
        $this->assertFalse(NudgePolicy::new()->decide(200_000, $failed, $ledger)->clearNudges, 'a refused call managed nothing');
    }

    public function testTheProjectionRendersAnAnchorOnItsRowOnlyAndStripsAnEcho(): void
    {
        $rows = [...self::steps(2), new AssistantMessage('Noted. ' . NudgePolicy::text(NudgePolicy::KIND_TURN))];
        $ledger = self::auto()->withNudge('c1', NudgePolicy::KIND_ITERATION);

        $projected = ContextProjector::new()->withRefTags()->project($rows, $ledger)->messages;
        $again = ContextProjector::new()->withRefTags()->project([...$rows, new UserMessage('more')], $ledger)->messages;

        $this->assertStringEndsWith(NudgePolicy::text(NudgePolicy::KIND_ITERATION), $projected[2]->content(), 'on the anchored result');
        $this->assertStringNotContainsString(NudgePolicy::OPEN, $projected[4]->content(), 'not on the next one');
        $this->assertSame('Noted. ', $projected[5]->content(), 'an echoed reminder is stripped from the model\'s own text');
        $this->assertSame(serialize($projected), serialize(\array_slice($again, 0, 6)), 'the anchored bytes do not move');
    }

    public function testAnchorsSurviveTheStoreAndTheFrame(): void
    {
        $ledger = self::auto()->withNudge('c1', NudgePolicy::KIND_LIMIT);
        $this->assertSame(['c1' => 'limit'], ContextLedger::fromArray(json_decode((string) json_encode($ledger->toArray()), true))->nudges);
        $this->assertSame([], ContextLedger::fromArray(['nudges' => ['c1' => 'bogus']])->nudges, 'an unknown kind is dropped');

        $delta = LedgerDelta::fromArray(LedgerDelta::new()->withNudgesCleared()->withNudge('c2', 'turn')->toArray());
        $this->assertTrue($delta->clearNudges);
        $this->assertSame(['c2' => 'turn'], $ledger->apply($delta)->nudges);
        $this->assertSame([], ContextLedger::new()->toArray()['nudges'] ?? [], 'a ledger without anchors keeps its stored shape');
    }

    public function testTheTextsAreFixedAndWrapped(): void
    {
        foreach ([NudgePolicy::KIND_TURN, NudgePolicy::KIND_ITERATION, NudgePolicy::KIND_LIMIT] as $kind) {
            $this->assertTrue(NudgePolicy::isKind($kind));
            $this->assertStringStartsWith(NudgePolicy::OPEN . "\n", NudgePolicy::text($kind));
            $this->assertStringEndsWith("\n" . NudgePolicy::CLOSE, NudgePolicy::text($kind));
            $this->assertStringContainsString('Prune', NudgePolicy::text($kind));
        }
        $this->assertSame('', NudgePolicy::text('nope'));
        $this->assertSame('x', NudgePolicy::appendTo('x', 'nope'));
    }

    private static function auto(): ContextLedger
    {
        return ContextLedger::new()->withDefaultMode(PruningMode::Auto);
    }

    /**
     * A prompt and then $results one-call steps, c1…cN.
     *
     * @return list<TypedMessage>
     */
    private static function steps(int $results): array
    {
        $rows = [new UserMessage('go')];
        for ($i = 1; $i <= $results; $i++) {
            $rows[] = new AssistantMessage('', [new ToolCall("c{$i}", 'Read', ['file_path' => "{$i}.php"])]);
            $rows[] = new ToolResultMessage("c{$i}", "contents {$i}");
        }

        return $rows;
    }
}
