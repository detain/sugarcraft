<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;

/**
 * The v4 S1b parity pin: Bootstrap's composition-root surface still exists,
 * and every write through a delegate lands in the one store that now owns it.
 *
 * WHY A DEDICATED FILE when families A-D each re-passed their whole test
 * neighbourhood: those suites prove the delegates WORK, but only incidentally
 * — each drives the store through its own scenario. This file pins the SHAPE
 * of the split itself: which names moved, which stayed, which delegates must
 * keep existing on Bootstrap with which visibility, and that a write through
 * the old door is visible through the new one. A future wave that quietly
 * re-parses the delegate into a second store (drift of the
 * "two hand-maintained integers" kind the transcript census warns about) goes
 * red HERE, without depending on any consumer scenario still existing.
 *
 * THE FAMILY-A ROUNDTRIPS REST WHAT THEY TOUCHED through the public writers
 * (snapshot, write, assert, finally-restore) — these nine stores are live
 * process state other tests read, and this file has no licence to leave a
 * probe value behind. The shutdown managers are NEVER invoked: they arm real
 * signal/pcntl hooks; existence, visibility, and signature equality are the
 * whole of what a delegate owes.
 */
final class LaunchContextDelegatesTest extends TestCase
{
    /**
     * Bootstrap writer delegate => LaunchContext reader, with the accepted
     * probe value and the LaunchContext writer the restore uses.
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: mixed}>
     */
    public static function simpleWriterDelegates(): iterable
    {
        yield 'config path' => ['useConfigPath', 'configPathOverride', 'w7d-config.json', 'useConfigPath'];
        yield 'model' => ['useModel', 'modelOverride', 'w7d-model', 'useModel'];
        yield 'tui error log' => ['useTuiErrorLog', 'tuiErrorLog', '/tmp/w7d.log', 'useTuiErrorLog'];
        yield 'prompt history path' => ['pinPromptHistoryPath', 'promptHistoryPath', '/tmp/w7d-h.jsonl', 'pinPromptHistoryPath'];
        yield 'permission mode' => ['usePermissionMode', 'permissionModeOverride', 'acceptEdits', 'usePermissionMode'];
        yield 'max cost' => ['useMaxCost', 'maxCostOverride', '4.25', 'useMaxCost'];
        yield 'max turns' => ['useMaxTurns', 'maxTurnsOverride', '9', 'useMaxTurns'];
    }

    /**
     * The empty-string normalisation family: writers that fold '' to null
     * ("the flag was absent") — the fold must live in LaunchContext's store
     * method, not in a Bootstrap body that could drift from it.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function normalisingWriters(): iterable
    {
        yield 'model' => ['useModel', 'modelOverride'];
        yield 'tui error log' => ['useTuiErrorLog', 'tuiErrorLog'];
        yield 'permission mode' => ['usePermissionMode', 'permissionModeOverride'];
        yield 'max cost' => ['useMaxCost', 'maxCostOverride'];
        yield 'max turns' => ['useMaxTurns', 'maxTurnsOverride'];
    }

    /** @dataProvider simpleWriterDelegates */
    public function testAWriteThroughTheBootstrapDelegateLandsInLaunchContext(
        string $bootstrapWriter,
        string $accessor,
        string $probe,
        string $restoreWriter,
    ): void {
        $read = new ReflectionMethod(LaunchContext::class, $accessor);
        $write = new ReflectionMethod(LaunchContext::class, $restoreWriter);
        $door = new ReflectionMethod(Bootstrap::class, $bootstrapWriter);
        $before = $read->invoke(null);

        try {
            $door->invoke(null, $probe);
            self::assertSame($probe, $read->invoke(null),
                "Bootstrap::{$bootstrapWriter}() no longer speaks to LaunchContext::{$accessor}()");
        } finally {
            $write->invoke(null, $before);
        }
    }

    /** @dataProvider normalisingWriters */
    public function testTheBlankValueFoldLivesInTheMovedWriterNotTheDelegate(
        string $bootstrapWriter,
        string $accessor,
    ): void {
        $read = new ReflectionMethod(LaunchContext::class, $accessor);
        $door = new ReflectionMethod(Bootstrap::class, $bootstrapWriter);
        $before = $read->invoke(null);

        try {
            $door->invoke(null, 'set-before-blank');
            self::assertSame('set-before-blank', $read->invoke(null));

            $door->invoke(null, '');
            self::assertNull($read->invoke(null),
                "'' must fold to null in LaunchContext itself — a fold left in the delegate is a second truth");
        } finally {
            $door->invoke(null, $before);
        }
    }

    public function testTheSessionLaunchDelegateSetsModeAndTargetTogether(): void
    {
        $modeBefore = LaunchContext::sessionLaunchMode();
        $targetBefore = LaunchContext::sessionLaunchTarget();

        try {
            Bootstrap::useSessionLaunch(true);
            self::assertSame('continue', LaunchContext::sessionLaunchMode());
            self::assertNull(LaunchContext::sessionLaunchTarget(), 'no target with -c');

            Bootstrap::useSessionLaunch(false, true, 'w7d-row');
            self::assertSame('resume', LaunchContext::sessionLaunchMode());
            self::assertSame('w7d-row', LaunchContext::sessionLaunchTarget());

            Bootstrap::useSessionLaunch(false);
            self::assertSame('new', LaunchContext::sessionLaunchMode());
        } finally {
            LaunchContext::useSessionLaunch($modeBefore === 'continue', $modeBefore === 'resume', $targetBefore);
        }
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function shutdownManagerDelegates(): iterable
    {
        yield 'stopMcpServers' => ['stopMcpServers', true];
        yield 'registerMcpShutdown' => ['registerMcpShutdown', false];
        yield 'stopLspServers' => ['stopLspServers', true];
        yield 'registerLspShutdown' => ['registerLspShutdown', false];
        yield 'registerBackgroundShellShutdown' => ['registerBackgroundShellShutdown', false];
        yield 'stopBackgroundShells' => ['stopBackgroundShells', true];
    }

    /**
     * @dataProvider shutdownManagerDelegates
     */
    public function testEachShutdownManagerKeepsItsBootstrapDoorAndItsLaunchContextBody(
        string $name,
        bool $wasPublic,
    ): void {
        $old = new ReflectionMethod(Bootstrap::class, $name);
        $new = new ReflectionMethod(LaunchContext::class, $name);

        self::assertSame($wasPublic, $old->isPublic(),
            "Bootstrap::{$name}() changed visibility with the move — callers reach it by name");
        self::assertTrue($new->isPublic(),
            "the moved body must be public — the private doors forward to it from another class");
        self::assertFalse($old->isStatic() !== $new->isStatic(), 'staticness must agree across the door');
        self::assertSame($old->getNumberOfParameters(), $new->getNumberOfParameters(),
            "Bootstrap::{$name}() no longer forwards every argument the body takes");
        self::assertSame('void', (string) $old->getReturnType());
    }

    /** @return array<string, array{0: string}> */
    public static function migratedStores(): iterable
    {
        foreach ([
            // family A — launch wiring
            'configPathOverride', 'modelOverride', 'tuiErrorLog', 'sessionLaunchMode', 'sessionLaunchTarget',
            'promptHistoryPath', 'permissionModeOverride', 'maxCostOverride', 'maxTurnsOverride',
            // family B — service memos
            'mcpClients', 'mcpToolShapes', 'mcpConfigDigests', 'mcpShutdownRegistered', 'lspClients',
            'lspShutdownRegistered', 'readLedgers', 'shellsShutdownRegistered', 'shellRegistries',
            'bundlesCache', 'keybindingsAppliedDigest',
            // family C — skip/frontmatter report ledgers
            'skillSkips', 'reportedSkillSkips', 'reportedMemorySkips', 'commandSkips', 'reportedCommandSkips',
            'commandOverrides', 'reportedCommandOverrides', 'ignoredFrontmatter', 'reportedIgnoredFrontmatter',
            // family D — trust/policy freezes
            'reportedUntrustedHookFiles', 'trustedRoots', 'policyFreshnessChecked', 'policyChanged',
            'policyPreviousProjectHooks', 'policyPreviousTrustedRoots', 'trustedMcpRoots', 'trustedCommandRoots',
            'trustedSettingsRoots', 'projectRootForSettings', 'hookFileEntries',
        ] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @dataProvider migratedStores
     */
    public function testAMigratedStoreLivesPrivatelyOnLaunchContextAndNotOnBootstrap(string $name): void
    {
        $moved = new ReflectionProperty(LaunchContext::class, $name);

        self::assertTrue($moved->isStatic(), "LaunchContext::\${$name} must stay process-global static");
        self::assertTrue($moved->isPrivate(), "LaunchContext::\${$name} stayed public in the split — accessors are the surface");
        self::assertFalse(property_exists(Bootstrap::class, $name),
            "Bootstrap::\${$name} came BACK after the v4 S1b split; if a store legitimately belongs there, this test "
            . "and LaunchContext must both lose the row in the same commit");
    }

    public function testTheTwoInventoryPinnedStoresRemainOnBootstrap(): void
    {
        // The deliberate exceptions: ProjectTierRefusalInventoryTest reads the
        // declaration and doc-block of the project-tier refusal ledger OUT OF
        // Bootstrap.php's text, across nine files, so it is census-frozen to
        // its home. Recorded here so "every mutable store moved" stays an
        // honest, checkable claim — the 40/42, not 42/42.
        foreach (['projectTierRefusals', 'reportedProjectTierRefusals'] as $name) {
            $stay = new ReflectionProperty(Bootstrap::class, $name);
            self::assertTrue($stay->isPrivate() && $stay->isStatic());
            self::assertFalse(property_exists(LaunchContext::class, $name),
                "LaunchContext::\${$name} exists while the inventory census still reads Bootstrap's text — "
                . 'the freeze moved without moving its guards');
        }
    }
}
