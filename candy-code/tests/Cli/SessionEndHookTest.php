<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\ArgvParser;
use SugarCraft\Code\Cli\NonInteractive;
use SugarCraft\Code\Hooks\HookContext;
use SugarCraft\Code\Hooks\HookEvent;
use SugarCraft\Code\Hooks\HookInterface;
use SugarCraft\Code\Hooks\HookManager;
use SugarCraft\Code\Hooks\HookRegistry;
use SugarCraft\Code\Hooks\HookResult;
use SugarCraft\Code\Hooks\ScriptHook;
use SugarCraft\Code\Support\Daemonize;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * Step 3.D-2: `SessionEnd` fires once as the process exits, through
 * {@see NonInteractive::fireSessionEnd()} — called by `bin/candy-code` after
 * the TUI's program loop returns and by {@see NonInteractive::run()} after a
 * `-p` answer is out. Observe-only: whatever the chain says, the exit is not
 * changed, and an unhooked session does nothing.
 */
final class SessionEndHookTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $tempDir = null;

    private ?string $sandbox = null;

    /** @var array<string, string|false> */
    private array $savedEnv = [];

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            \is_string($value) ? putenv($key . '=' . $value) : putenv($key);
        }
        $this->savedEnv = [];
        $this->restoreHomeSandbox();
        if ($this->tempDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
        }
        if ($this->sandbox !== null) {
            exec('rm -rf ' . escapeshellarg($this->sandbox));
        }

        parent::tearDown();
    }

    public function testTheChainSeesTheSessionAndTheReason(): void
    {
        $seen = [];
        $manager = self::manager(static function (HookContext $context) use (&$seen): HookResult {
            $seen[] = [$context->toolName, $context->toolInput, $context->sessionId, $context->projectRoot];

            return HookResult::allow();
        });

        $line = NonInteractive::fireSessionEnd($manager, 'sess-1', '/project', 'prompt_input_exit');

        self::assertNull($line, 'a permitting chain writes nothing');
        self::assertSame([['SessionEnd', '{"reason":"prompt_input_exit"}', 'sess-1', '/project']], $seen);
    }

    public function testAThrowingHookIsOneOperatorLineAndDoesNotFailTheExit(): void
    {
        $manager = self::manager(static function (): HookResult {
            throw new \RuntimeException('cannot archive');
        });

        $line = NonInteractive::fireSessionEnd($manager, '', '/p', 'other');

        self::assertSame("candy-code: SessionEnd hook `archive` refused: hook failed: RuntimeException: cannot archive\n", $line);
    }

    public function testNoChainOrNoSessionEndHookIsANoOp(): void
    {
        $builtIns = new HookManager(new HookRegistry());
        $builtIns->registerBuiltIns();

        self::assertNull(NonInteractive::fireSessionEnd(null, '', '/p', 'other'));
        self::assertNull(NonInteractive::fireSessionEnd($builtIns, '', '/p', 'other'));
    }

    public function testAOneShotRunFiresTheUsersSessionEndHookAfterItsAnswer(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/session_end_hook_' . uniqid('', true);
        $home = $this->tempDir . '/home';
        mkdir($home . '/.candy-code', 0700, true);
        mkdir($this->tempDir . '/repo', 0700, true);
        $marker = $this->tempDir . '/ended';
        $this->useHomeSandbox($home);
        foreach (['CANDY_CODE_PROVIDER', 'CANDY_CODE_BACKEND_CMD_STREAM', 'CANDY_CODE_BACKEND_CMD'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        // `cat` echoes the history back: an offline backend that really runs.
        putenv('CANDY_CODE_BACKEND_CMD=cat');
        file_put_contents($home . '/.candy-code/hooks.yaml', \sprintf(
            "hooks:\n  SessionEnd:\n    - name: mark-end\n      command: 'printf %%s \"\$CRUSH_TOOL_INPUT\" > %s'\n",
            $marker, // a temp path with no quote or space in it: the YAML value is single-quoted
        ));

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['candy-code', '-p', 'hello there', '--root', $this->tempDir . '/repo']));
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('hello there', $stdout);
        self::assertFileExists($marker, 'the SessionEnd hook never ran');
        self::assertSame('{"reason":"other"}', file_get_contents($marker));
    }

    /**
     * x1102: the TUI's exit tail is the shape this test pins, because that is
     * where the minute used to live. The chain goes to
     * {@see NonInteractive::fireSessionEndDetached()}; a bare
     * {@see NonInteractive::fireSessionEnd()} there makes the shell wait out
     * the SUM of the hooks' own deadlines AFTER the screen is already back.
     */
    public function testTheTuiExitSeamHandsTheChainToTheDetachedDispatcher(): void
    {
        $bin = \file_get_contents(\dirname(__DIR__, 2) . '/bin/candy-code');
        self::assertIsString($bin, 'bin/candy-code has to be readable for its exit seam to be pinned');
        self::assertStringContainsString(
            'NonInteractive::fireSessionEndDetached(',
            $bin,
            'the TUI exits before the operator sees the shell prompt again',
        );
        self::assertStringNotContainsString(
            'NonInteractive::fireSessionEnd(',
            $bin,
            'the waiting form is what held the terminal (x1102): only the detached dispatcher belongs at the exit seam',
        );
    }

    /**
     * The other half of the exit seam's honesty (x1102 review, MINOR-1):
     * {@see Daemonize::detach()} throws `RuntimeException` when the FIRST FORK
     * fails, which is a different story from the pre-checked
     * {@see Daemonize::unavailableReason()} — a full process table is transient
     * and says nothing about whether the operator configured a `SessionEnd`
     * hook. Uncaught, it would print a fatal across a terminal the operator is
     * already typing in, exit 255, and drop the chain: the exact opposite
     * polarity this dispatcher argues for ("a hook that fires late beats one
     * that never fires"). So a refused fork must route to the SAME foreground
     * fire the no-log path uses.
     *
     * Pinned as a source scan because the throw cannot be provoked without
     * fault injection, and widening the dispatcher with a test-only detacher
     * seam to reach one line of error handling is the worse trade.
     */
    public function testAForkRefusedByTheKernelStillFiresTheChainHere(): void
    {
        $src = \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/NonInteractive.php');
        self::assertIsString($src, 'NonInteractive.php has to be readable for the exit seam to be pinned');

        self::assertSame(
            1,
            \preg_match('/catch \(\S*RuntimeException[^)]*\)\s*\{[^{}]*return false;/s', $src),
            'the detached handover must turn a refused fork into "run it here" — a missing catch, '
                . 'or one that reports success, leaves /exit holding a fatal instead of a chain',
        );

        self::assertStringContainsString(
            'Daemonize::detach(',
            $src,
            'the pin above reads a window around a call this file is expected to make',
        );

        self::assertSame(
            2,
            \substr_count($src, 'self::fireSessionEnd($hooks, $sessionId, $root, $reason);'),
            'exactly two foreground fires belong in this file: the one the daemon runs, and the ONE '
                . 'fallback every cannot-daemonize path shares. A third means the fallback was '
                . 'duplicated instead of reused, and a first copy is free to drift.',
        );
    }

    /**
     * The hook is configured to take two seconds, and the exit is not allowed
     * to care: control comes back first, the chain finishes anyway. On a build
     * that cannot daemonize the documented fallback runs the chain here, which
     * is the same assertion with the polarity flipped — no skipped test, no
     * silent weakening either way.
     */
    public function testTheDetachedChainGivesBackTheShellFirstAndCompletesAfterwards(): void
    {
        [$manager, $marker] = $this->slowSessionEndChain();
        $log = $this->sandbox . '/exit.log';
        $previousUmask = \umask();

        try {
            $started = \microtime(true);
            NonInteractive::fireSessionEndDetached($manager, 'sess-x1102', $this->sandbox, 'prompt_input_exit', $log);
            $elapsed = \microtime(true) - $started;

            if (Daemonize::unavailableReason() === null) {
                self::assertLessThan(1.0, $elapsed, \sprintf(
                    'a two-second chain cost %.3fs of the exit; the dispatcher is supposed to hand back at once', $elapsed,
                ));
                self::assertFileDoesNotExist($marker, 'the hook was run here after all, not detached');

                $deadline = \microtime(true) + 20.0;
                while (!\is_file($marker) && \microtime(true) < $deadline) {
                    \usleep(50_000);
                }

                self::assertFileExists($marker, 'the detached chain never completed: the hook was dropped, not deferred');
                self::assertFileExists($log, 'the daemon never opened the log it was handed: the positive control for '
                    . 'testAnUnhookedSessionEndForksNothing');

                return;
            }

            self::assertGreaterThan(1.5, $elapsed, 'without a daemon the chain runs in the foreground, as documented');
            self::assertFileExists($marker);
        } finally {
            \umask($previousUmask);
        }
    }

    /**
     * With nowhere to send the chain's output the dispatcher keeps the old
     * behavior rather than dropping the hook: a late-firing hook beats one that
     * never fires.
     */
    public function testWithoutALogToHandTheOutputToTheChainFiresHere(): void
    {
        $seen = [];
        $manager = self::manager(static function (HookContext $context) use (&$seen): HookResult {
            $seen[] = $context->sessionId;

            return HookResult::allow();
        });

        NonInteractive::fireSessionEndDetached($manager, 'sess-foreground', '/p', 'other', null);

        self::assertSame(['sess-foreground'], $seen);
    }

    /**
     * The unhooked exit must stay what it was: no fork, so nothing for the
     * shell to be shadowed by. The daemon opens its log on the way in, so the
     * absence of that file is the proof — a shape that cannot drift with timing.
     */
    public function testAnUnhookedSessionEndForksNothing(): void
    {
        $this->makeSandbox();
        $builtIns = new HookManager(new HookRegistry());
        $builtIns->registerBuiltIns();
        $log = $this->sandbox . '/should-not-exist.log';

        NonInteractive::fireSessionEndDetached($builtIns, '', $this->sandbox, 'other', $log);
        NonInteractive::fireSessionEndDetached(null, '', $this->sandbox, 'other', $log);

        self::assertFileDoesNotExist($log);
    }

    /**
     * A real {@see ScriptHook} whose script takes two seconds, plus the marker
     * file it touches when it gets there.
     *
     * @return array{HookManager, string}
     */
    private function slowSessionEndChain(): array
    {
        $this->makeSandbox();
        $marker = $this->sandbox . '/done';
        $script = $this->sandbox . '/slow.sh';
        \file_put_contents($script, "#!/bin/sh\nsleep 2\ntouch " . $marker . "\n");
        \chmod($script, 0o755);

        $manager = new HookManager(new HookRegistry());
        $manager->register(new ScriptHook('slow-finalize', HookEvent::SessionEnd, '.*', $script, '', 20.0));

        return [$manager, $marker];
    }

    private function makeSandbox(): void
    {
        if ($this->sandbox !== null) {
            return;
        }

        $this->sandbox = \sys_get_temp_dir() . '/session_end_detach_' . \uniqid('', true);
        \mkdir($this->sandbox, 0o700, true);
    }

    /**
     * @param \Closure(HookContext): HookResult $verdict
     */
    private static function manager(\Closure $verdict): HookManager
    {
        $manager = new HookManager(new HookRegistry());
        $manager->register(new class ($verdict) implements HookInterface {
            public function __construct(private \Closure $verdict) {}

            public function name(): string { return 'archive'; }

            public function event(): HookEvent { return HookEvent::SessionEnd; }

            public function matcher(): string { return '.*'; }

            public function execute(HookContext $context): HookResult
            {
                return ($this->verdict)($context);
            }
        });

        return $manager;
    }
}
