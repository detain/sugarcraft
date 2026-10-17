<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Context\Pruning;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Context\Pruning\ContextLedger;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Context\Pruning\PruningPolicy;
use SugarCraft\Code\Context\Pruning\TurnStartPruning;
use SugarCraft\Code\Context\TurnContextBlock;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Tools\ToolCall;

/**
 * Roadmap 3.B-2: a session's pruning mode — its own (`/pruning`, kept on the
 * ledger and persisted with it) or the configured one (env over settings key
 * over `auto`) — and the batch an `auto` session prunes at a turn's start.
 */
final class PruningModeTest extends TestCase
{
    public function testTheEnvironmentWinsThenTheSettingThenAuto(): void
    {
        $this->assertSame(PruningMode::Off, PruningMode::configured(['contextPruning.mode' => 'manual'], 'off'));
        $this->assertSame(PruningMode::Manual, PruningMode::configured(['contextPruning.mode' => ' Manual '], false));
        $this->assertSame(PruningMode::Auto, PruningMode::configured([], false));
        $this->assertSame(PruningMode::Manual, PruningMode::configured(['contextPruning.mode' => 'manual'], 'nonsense'), 'a value naming no mode is ignored, not read as off');
        $this->assertSame(PruningMode::Auto, PruningMode::configured(['contextPruning.mode' => 'nonsense'], false));
        $this->assertSame(PruningMode::Auto, PruningMode::configured(['contextPruning.mode' => 3], false));
    }

    public function testWhatEachModeAllows(): void
    {
        $this->assertSame([true, true, true], [PruningMode::Auto->runsStrategies(), PruningMode::Auto->showsRefs(), PruningMode::Auto->allowsManualPruning()]);
        $this->assertSame([false, true, true], [PruningMode::Manual->runsStrategies(), PruningMode::Manual->showsRefs(), PruningMode::Manual->allowsManualPruning()]);
        $this->assertSame([false, false, false], [PruningMode::Off->runsStrategies(), PruningMode::Off->showsRefs(), PruningMode::Off->allowsManualPruning()]);
    }

    public function testOnlyAnAutoSessionLetsTheModelPrune(): void
    {
        $this->assertSame([true, false, false], [
            PruningMode::Auto->allowsModelPruning(),
            PruningMode::Manual->allowsModelPruning(),
            PruningMode::Off->allowsModelPruning(),
        ]);
    }

    public function testTheSchemaRowIsTheEnumsOwn(): void
    {
        $definition = SettingsSchema::byKey(PruningMode::SETTING);

        $this->assertNotNull($definition);
        $this->assertSame(PruningMode::DEFAULT->value, $definition->default);
        $this->assertSame(['auto', 'manual', 'off'], $definition->enumValues);
        $this->assertSame(PruningMode::ENV, $definition->envVar);
        $this->assertFalse($definition->projectSettable, 'a cloned project cannot change a person\'s pruning');
    }

    public function testTheSessionsOwnModeOutranksTheDefaultAndOnlyItIsPersisted(): void
    {
        $ledger = ContextLedger::new()->withDefaultMode(PruningMode::Off);
        $this->assertSame(PruningMode::Off, $ledger->effectiveMode());

        $chosen = $ledger->withMode(PruningMode::Manual);
        $this->assertSame(PruningMode::Manual, $chosen->effectiveMode());
        $this->assertSame(PruningMode::Off, $ledger->effectiveMode(), 'the original is untouched');
        $this->assertSame($chosen, $chosen->withMode(PruningMode::Manual), 'no change, same ledger');

        $read = ContextLedger::fromArray(json_decode((string) json_encode($chosen->toArray()), true));
        $this->assertSame(PruningMode::Manual, $read->mode);
        $this->assertSame(PruningMode::Off, $read->defaultMode, 'the configured default is resolved per turn by the host, never stored');
        $this->assertSame(PruningMode::Off, ContextLedger::new()->effectiveMode(), 'a ledger no host keeps shows no refs');
        $this->assertNull(ContextLedger::fromArray(['mode' => 'shredded'])->mode);
        $this->assertNull($chosen->withMode(null)->mode, '`/pruning default` forgets the choice');
    }

    public function testATurnStartDropsSupersededRowsOnlyOnceTheyAreWorthACacheRewrite(): void
    {
        $policy = PruningPolicy::new();
        $big = [self::contextRow('one', 50_000), self::contextRow('two', 50_000), new UserMessage('go'), self::contextRow('now', 10)];

        $delta = TurnStartPruning::propose($big, ContextLedger::new(), $policy);
        $this->assertNotNull($delta);
        $ledger = ContextLedger::new()->apply($delta);
        $this->assertTrue($ledger->dropsContextRow($big[0]->content()));
        $this->assertTrue($ledger->dropsContextRow($big[1]->content()));
        $this->assertFalse($ledger->dropsContextRow($big[3]->content()), 'the newest state is always kept');

        $small = [self::contextRow('one', 100), self::contextRow('now', 10)];
        $this->assertNull(TurnStartPruning::propose($small, ContextLedger::new(), $policy), 'under the floor nothing is rewritten');
        $this->assertNotNull(TurnStartPruning::propose($small, ContextLedger::new(), $policy->withMinFreedTokens(0)));
    }

    /** Roadmap 2.3 at the turn start: a re-read file's older output goes too, once. */
    public function testATurnStartRunsThePathKeyedRulesAndPrunesEachCallOnce(): void
    {
        $output = str_repeat('line of a.php ', 400);
        $rows = [
            new UserMessage('go'),
            new AssistantMessage('', [new ToolCall('r1', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('r1', $output),
            new AssistantMessage('', [new ToolCall('r2', 'Read', ['file_path' => 'a.php'])]),
            new ToolResultMessage('r2', $output),
        ];

        $delta = TurnStartPruning::propose($rows, ContextLedger::new(), PruningPolicy::new()->withMinFreedTokens(0));

        $this->assertNotNull($delta);
        $this->assertSame(['r1'], array_map(static fn ($e): string => $e->toolCallId, $delta->prunes), 'the newest read stays; the older one is pruned once');
    }

    private static function contextRow(string $state, int $bytes): UserMessage
    {
        return new UserMessage(TurnContextBlock::FENCE . "\n" . $state . str_repeat(' x', intdiv($bytes, 2)) . "\n</turn-context>");
    }
}
