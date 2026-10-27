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

    /**
     * The builder families the composition-root wave moved (S1b W7-E C1-C5):
     * name => [whether the Bootstrap door was public before the move, whether
     * the door is the reason-SPEAKER (C-PRV-1.b) rather than a pure one-line
     * forward].
     *
     * @return array<string, array{0: string, 1: bool, 2?: bool}>
     */
    public static function migratedBuilders(): iterable
    {
        yield 'tools' => ['tools', true];
        yield 'unfilteredTools' => ['unfilteredTools', true];
        yield 'provider' => ['provider', true];
        yield 'selectedProviderLabel' => ['selectedProviderLabel', true];
        yield 'selectedProviderName' => ['selectedProviderName', true];
        yield 'selectedModelName' => ['selectedModelName', false];
        yield 'providerConfigFor' => ['providerConfigFor', true];
        yield 'summaryModel' => ['summaryModel', true];
        yield 'backendFor' => ['backendFor', true];
        yield 'taskWorkerPool' => ['taskWorkerPool', false];
        yield 'configRoot' => ['configRoot', false];
        yield 'withResolvedMaxToolSteps' => ['withResolvedMaxToolSteps', false];
        // C-PRV-1.b: this door is the reason-SPEAKER. The moved body returns
        // the [engine, ?reason] pair, and the door records the reason on the
        // launch shelf — it cannot stay a single forwarding return without
        // the launch going silent on an unshaped saved tier. Pinned as a
        // speaker below: one body call, the shelf funnel, nothing else.
        yield 'withResolvedReasoningEffort' => ['withResolvedReasoningEffort', false, true];
        yield 'withConsolePermissionPrompt' => ['withConsolePermissionPrompt', false];
        yield 'app' => ['app', true];
        yield 'settingsWriter' => ['settingsWriter', false];
        // Widened doors (C5 cure): the moved app() body reaches them, and
        // src/App/App.php cites the dock loader by its Bootstrap name.
        yield 'dockFromUserConfig' => ['dockFromUserConfig', true];
        yield 'paneAutoShowFromUserConfig' => ['paneAutoShowFromUserConfig', true];
    }

    /**
     * @dataProvider migratedBuilders
     */
    public function testEachMigratedBuilderKeepsAThinnestBootstrapDoorOverALaunchContextBody(
        string $name,
        bool $doorWasPublic,
        bool $reasonSpeaker = false,
    ): void {
        $door = new ReflectionMethod(Bootstrap::class, $name);
        $body = new ReflectionMethod(LaunchContext::class, $name);

        self::assertSame($doorWasPublic, $door->isPublic(),
            "Bootstrap::{$name}() changed visibility with the move — the settings-schema reader citations "
            . 'and the reflection pins reach it by that name');
        self::assertTrue($body->isPublic(),
            "LaunchContext::{$name}() must be public — private doors forward to it from another class");
        self::assertFalse($door->isStatic() !== $body->isStatic(), 'staticness must agree across the door');
        self::assertSame($door->getNumberOfParameters(), $body->getNumberOfParameters(),
            "Bootstrap::{$name}() no longer forwards every argument the body takes");
        if ($reasonSpeaker) {
            // The speaker door converts the body's [engine, ?reason] tuple
            // into the engine the whole launch chain threads — the return
            // types differ BY DESIGN; the tuple shape is pinned on the door's
            // own body below, not across the split.
            self::assertSame('array', (string) $body->getReturnType(),
                "LaunchContext::{$name}() stopped returning the [engine, ?reason] tuple its speaker door reads");
            self::assertSame('SugarCraft\Code\Backend\EngineBackend', (string) $door->getReturnType(),
                "Bootstrap::{$name}() stopped narrowing the tuple back to the engine");
        } else {
            self::assertSame((string) $door->getReturnType(), (string) $body->getReturnType(),
                "the door's return type drifted from the moved body's");
        }

        // THINNEST: the door body is one `return LaunchContext::name(...)` and
        // nothing else — a second truth living behind the door is exactly the
        // drift this file exists to refuse.
        $source = self::methodSource($door);
        $open = \strpos($source, '{');
        $close = \strrpos($source, '}');
        self::assertIsInt($open);
        self::assertIsInt($close);
        $text = \substr($source, $open + 1, $close - $open - 1);
        // Comments annotate the door; the executable part must still be ONE
        // forwarding return and nothing else.
        $code = \implode("\n", \array_filter(
            \explode("\n", $text),
            static fn (string $line): bool => !str_starts_with(ltrim($line), '//'),
        ));
        if ($reasonSpeaker) {
            // The speaker's allowance, pinned at its floor: one call into the
            // moved body, one funnel out (the shelf), one return. Anything
            // else reaching Bootstrap.php text here would re-grow the second
            // truth this file exists to refuse — and would move the stderr and
            // launch-format censuses, which C-PRV-1.b left exactly where they
            // were because the reason arrives PRE-BUILT.
            self::assertSame(1, substr_count($code, 'LaunchContext::' . $name),
                "Bootstrap::{$name}() must reach the moved body exactly once");
            self::assertSame(1, substr_count($code, 'self::warnLaunchRows('),
                "Bootstrap::{$name}() must use the shelf funnel, nothing louder");
            self::assertSame(1, substr_count($code, 'self::'),
                "Bootstrap::{$name}() sprouted a second self:: reach behind the door");
            self::assertMatchesRegularExpression(
                '/return\s+\$engine;\s*$/',
                $code,
                "Bootstrap::{$name}() must hand back exactly the narrowed engine",
            );

            return;
        }

        self::assertMatchesRegularExpression(
            '/^\s*return LaunchContext::' . preg_quote($name, '/') . '\([^;]*\);\s*$/',
            $code,
            "Bootstrap::{$name}() is no longer a single forwarding return — logic crept back behind the door",
        );
    }

    /**
     * The residents that must NEVER become delegates (S1c hand-off boundary,
     * pinned structurally): the transcript-seam census counts 31
     * `self::warnPermissionConfigInTranscript(` literals in Bootstrap.php's
     * text, so every method carrying one stays; `chat()` and `workspace()`
     * stay while the project-tier refusal ledger is inventory-frozen to this
     * file; `openSession()` keeps its two sprints inside the launch-format
     * window. If a future wave moves one, it must move its guards first and
     * re-key this roster in the same commit.
     *
     * @return array<string, array{0: string}>
     */
    public static function permanentResidents(): iterable
    {
        foreach (['backend', 'chat', 'workspace', 'hooks', 'permissionGate', 'lspClient',
            'mcpClient', 'promptEnabledSkills', 'filterToolSet', 'installSecretEnvAllowlist',
            'openSession',] as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @dataProvider permanentResidents
     */
    public function testAPermanentResidentStillBuildsItsOwnBodyInsideBootstrap(string $name): void
    {
        $stay = new ReflectionMethod(Bootstrap::class, $name);
        $bootFile = (new ReflectionMethod(Bootstrap::class, 'chat'))->getFileName();

        self::assertSame($bootFile, $stay->getFileName(),
            "Bootstrap::{$name}() was moved without re-keying its census guards — see the method's doc-block "
            . 'and this roster in the same commit');
        self::assertStringNotContainsString(
            'return LaunchContext::' . $name . '(',
            self::methodSource($stay),
            "Bootstrap::{$name}() became a forwarding delegate while still named a permanent resident here",
        );
    }

    /**
     * The declared source text of one method, from its own file, lines taken
     * from reflection (start line = the `function` line in this house style,
     * so the signature is included — every caller here needs only the body
     * shape and tolerates it).
     */
    private static function methodSource(ReflectionMethod $method): string
    {
        /** @var array<string, list<string>> $cache */
        static $cache = [];
        $file = (string) $method->getFileName();
        $lines = $cache[$file] ??= \file($file);

        return implode('', \array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));
    }
}
