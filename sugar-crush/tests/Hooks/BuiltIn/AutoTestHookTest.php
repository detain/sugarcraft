<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Hooks\BoundedHookInterface;
use SugarCraft\Crush\Hooks\BuiltIn\AutoTestEditHook;
use SugarCraft\Crush\Hooks\BuiltIn\AutoTestHook;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Lint\TestRunner;
use SugarCraft\Crush\Support\ForkedChild;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Step 3.H: the `Stop` half runs the test command after a turn that edited a
 * file and refuses to let the turn end while it fails — at most
 * {@see AutoTestHook::MAX_REFLECTIONS} times — and the `PostToolUse` half
 * notes the edits.
 *
 * @see AutoTestHook
 * @see AutoTestEditHook
 */
final class AutoTestHookTest extends TestCase
{
    use HomeSandboxTrait;
    use ReapsForkedChildrenTrait;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/sc-autotest-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach (glob($this->root . '/' . '*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->root);
        parent::tearDown();
    }

    public function testTheTwoHalvesSitOnStopAndOnTheEditingTools(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('true'), $edits);

        self::assertSame('auto-test', $hook->name());
        self::assertSame(HookEvent::Stop, $hook->event());
        self::assertInstanceOf(BoundedHookInterface::class, $hook);
        self::assertSame('auto-test-edits', $edits->name());
        self::assertSame(HookEvent::PostToolUse, $edits->event());
        self::assertSame('^(Write|Edit|ApplyPatch)$', $edits->matcher());
    }

    public function testTheEditHalfNotesEachEditAndChangesNothing(): void
    {
        $edits = AutoTestEditHook::new();

        $result = $edits->execute(new HookContext('s', 'Edit', ['file_path' => 'a.php'], '{}', 'Edited a.php', 'm', 'p', $this->root));
        $edits->execute(new HookContext('s', 'Write', ['file_path' => 'b.php'], '{}', 'Wrote b.php', 'm', 'p', $this->root));

        self::assertTrue($result->permitsExecution());
        self::assertSame('', $result->message);
        self::assertSame('', $result->additionalContext);
        self::assertSame(2, $edits->pendingEdits());
        self::assertSame(2, $edits->takeEdits());
        self::assertSame(0, $edits->pendingEdits());
    }

    public function testATurnWithoutAnEditRunsNothing(): void
    {
        $hook = new AutoTestHook(TestRunner::new()->withCommand('touch ran'), AutoTestEditHook::new());

        self::assertTrue($hook->execute($this->stopContext())->permitsExecution());
        self::assertFileDoesNotExist($this->root . '/ran');
    }

    public function testAPassingRunLetsTheTurnEnd(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('touch ran'), $edits);
        $this->edit($edits);

        self::assertTrue($hook->execute($this->stopContext())->permitsExecution());
        self::assertFileExists($this->root . '/ran');
        self::assertSame(0, $edits->pendingEdits(), 'the run covered the edit');
    }

    public function testAFailingRunRefusesWithAidersRunOutput(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('echo "1 failed"; exit 1'), $edits);
        $this->edit($edits);

        $verdict = $hook->execute($this->stopContext());

        self::assertFalse($verdict->permitsExecution());
        self::assertFalse($verdict->haltsTurn(), 'a refusal keeps the turn going');
        self::assertSame("I ran this command:\n\necho \"1 failed\"; exit 1\n\nAnd got this output:\n\n1 failed\n", $verdict->message);
        self::assertSame(1, $edits->reflections());
    }

    public function testAReflectionAnsweredWithoutAnEditEndsTheTurn(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('exit 1'), $edits);
        $this->edit($edits);
        self::assertFalse($hook->execute($this->stopContext())->permitsExecution());

        self::assertTrue($hook->execute($this->stopContext())->permitsExecution(), 'nothing new to test');
        self::assertSame(0, $edits->reflections(), 'the next failing run starts a fresh count');
    }

    public function testTheFourthFailingRunEndsTheTurnWithTheStopReason(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('exit 1'), $edits);

        for ($i = 1; $i <= AutoTestHook::MAX_REFLECTIONS; $i++) {
            $this->edit($edits);
            $verdict = $hook->execute($this->stopContext());
            self::assertFalse($verdict->permitsExecution(), "reflection {$i}");
            self::assertFalse($verdict->haltsTurn(), "reflection {$i}");
        }

        $this->edit($edits);
        $verdict = $hook->execute($this->stopContext());

        self::assertTrue($verdict->haltsTurn());
        self::assertSame('the test command `exit 1` still fails (exit 1) after 3 attempts to fix it', $verdict->stopReason);
        self::assertSame(0, $edits->reflections(), 'spent: the next turn starts a fresh count');
    }

    public function testACommandThatCannotStartEndsTheTurnAtOnce(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('sc-no-such-test-runner-' . bin2hex(random_bytes(3))), $edits);
        $this->edit($edits);

        $verdict = $hook->execute($this->stopContext());

        self::assertTrue($verdict->haltsTurn(), 'a typo in testCommand is not the model\'s to fix');
        self::assertStringContainsString('could not run (exit 127)', $verdict->stopReason);
        self::assertSame(0, $edits->reflections());
    }

    public function testARunPastItsBoundEndsTheTurnAtOnce(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = (new AutoTestHook(TestRunner::new()->withCommand('sleep 30'), $edits))->withTimeoutSeconds(0.3);
        $this->edit($edits);

        $verdict = $hook->execute($this->stopContext());

        self::assertTrue($verdict->haltsTurn());
        self::assertStringContainsString('did not finish within 0.3 seconds', $verdict->stopReason);
    }

    public function testTheStopChainsHeartbeatLetsTheRunOutlastTheIdleCeiling(): void
    {
        // A beatless run is cut IDLE_MARGIN_SECONDS inside the ceiling: a
        // ceiling of margin + 1 s leaves it 1 s, too short for the suite. The
        // suite sleeps 2 s, not 1: a cut due at 1 s racing a 1 s sleep let
        // `touch` win on a loaded CI runner.
        $runner = TestRunner::new()->withCommand('sleep 2; touch ran')->withinTurnIdleCeiling((int) TestRunner::IDLE_MARGIN_SECONDS + 1);
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook($runner, $edits);

        $this->edit($edits);
        $beatless = $hook->execute($this->stopContext());
        self::assertTrue($beatless->haltsTurn(), 'no beat: the run must end inside the ceiling');
        self::assertFileDoesNotExist($this->root . '/ran');

        $beats = 0;
        $manager = new HookManager(new \SugarCraft\Crush\Hooks\HookRegistry());
        $manager->register($hook);
        $this->edit($edits);
        $verdict = $manager->stop($this->stopContext(), static function () use (&$beats): void {
            $beats++;
        });

        self::assertTrue($verdict->permitsExecution(), 'with the turn\'s beat the run gets its whole bound');
        self::assertFileExists($this->root . '/ran');
        self::assertGreaterThan(0, $beats, 'HookManager::stop() hands the beat to the test run');
    }

    public function testTheChainMayShortenTheBoundAndTheCopySharesTheLedger(): void
    {
        $edits = AutoTestEditHook::new();
        $hook = new AutoTestHook(TestRunner::new()->withCommand('exit 1')->withTimeout(20.0), $edits);

        $shorter = $hook->withTimeoutSeconds(5.0);
        self::assertSame(5.0, $shorter->timeoutSeconds());
        self::assertSame(20.0, $hook->withTimeoutSeconds(60.0)->timeoutSeconds(), 'never lengthened');
        self::assertSame($edits, $shorter->edits());

        $this->edit($edits);
        self::assertFalse($shorter->execute($this->stopContext())->permitsExecution());
        self::assertSame(1, $edits->reflections(), 'what the copy spent is the original\'s count too');
    }

    public function testAnEditNotedInAnotherProcessIsNotThisProcesssEdit(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required to fork');
        }

        $edits = AutoTestEditHook::new();
        $this->edit($edits);
        $pipe = $this->root . '/child-saw';

        $pid = $this->forkTracked();
        if ($pid === 0) {
            file_put_contents($pipe, (string) $edits->pendingEdits());
            $edits->execute($this->editContext());
            ForkedChild::exitNow();
        }
        pcntl_waitpid($pid, $status);

        self::assertSame('0', file_get_contents($pipe), 'a forked turn child does not inherit its parent\'s edits as its own');
        self::assertSame(1, $edits->pendingEdits(), 'nor do the child\'s edits reach the parent');
    }

    public function testBootstrapRegistersBothHalvesOnlyWhenAutoTestIsOnWithACommand(): void
    {
        $cases = [
            'off by default' => [['testCommand' => 'composer test'], false],
            'on without a command' => [['autoTest' => true], false],
            'on with a command' => [['autoTest' => true, 'testCommand' => 'composer test'], true],
            'a truthy string is not on' => [['autoTest' => 'yes', 'testCommand' => 'composer test'], false],
        ];

        foreach ($cases as $label => [$config, $registered]) {
            $hooks = $this->launchChain($config);
            $stop = $hooks->hook(HookEvent::Stop->value, AutoTestHook::NAME);
            $edit = $hooks->hook(HookEvent::PostToolUse->value, AutoTestEditHook::NAME);

            self::assertSame($registered, $stop instanceof AutoTestHook, $label);
            self::assertSame($registered, $edit instanceof AutoTestEditHook, $label);
            if ($stop instanceof AutoTestHook) {
                self::assertSame($edit, $stop->edits(), 'the two halves share one ledger');
                self::assertSame('composer test', $stop->runner()->command());
                self::assertSame(TestRunner::DEFAULT_TIMEOUT_SECONDS, $stop->timeoutSeconds(), 'a run that beats keeps its whole bound');
                self::assertSame(\SugarCraft\Crush\Backend\EngineBackend::COMPLETE_TIMEOUT_SECONDS, $stop->runner()->idleCeilingSeconds(), 'the ceiling a beatless run is kept inside, resolved as the engine resolves it');
            }
        }
    }

    private function edit(AutoTestEditHook $edits): void
    {
        $edits->execute($this->editContext());
    }

    private function editContext(): HookContext
    {
        return new HookContext('s', 'Edit', ['file_path' => 'a.php'], '{}', 'ok', 'm', 'p', $this->root);
    }

    private function stopContext(): HookContext
    {
        return HookManager::eventContext(HookEvent::Stop, ['stop_hook_active' => false, 'last_assistant_message' => 'done'], 's', $this->root);
    }

    /**
     * Bootstrap's launch chain under a sandboxed HOME with $config.
     *
     * @param array<string, mixed> $config
     */
    private function launchChain(array $config): HookManager
    {
        $home = $this->root . '/home';
        @mkdir($home . '/.sugar-crush', 0o700, true);
        file_put_contents($home . '/.sugar-crush/config.json', (string) json_encode($config));
        chmod($home . '/.sugar-crush/config.json', 0o600);
        $this->useHomeSandbox($home);
        Bootstrap::useConfigPath(null);
        Bootstrap::useProjectRootForSettings(null);

        try {
            $hooks = (new \ReflectionMethod(Bootstrap::class, 'hooks'))->invoke(null, null, $this->root);
        } finally {
            ProcessContainment::useSecretEnvAllowlist([]);
            $this->restoreHomeSandbox();
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }
        self::assertInstanceOf(HookManager::class, $hooks);

        return $hooks;
    }
}
