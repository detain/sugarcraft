<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\NonInteractive;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookInterface;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Hooks\HookRegistry;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Step 3.D-2: `SessionEnd` fires once as the process exits, through
 * {@see NonInteractive::fireSessionEnd()} — called by `bin/sugarcrush` after
 * the TUI's program loop returns and by {@see NonInteractive::run()} after a
 * `-p` answer is out. Observe-only: whatever the chain says, the exit is not
 * changed, and an unhooked session does nothing.
 */
final class SessionEndHookTest extends TestCase
{
    use HomeSandboxTrait;

    private ?string $tempDir = null;

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

        self::assertSame("sugarcrush: SessionEnd hook \"archive\" refused: hook failed: RuntimeException: cannot archive\n", $line);
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
        mkdir($home . '/.sugar-crush', 0700, true);
        mkdir($this->tempDir . '/repo', 0700, true);
        $marker = $this->tempDir . '/ended';
        $this->useHomeSandbox($home);
        foreach (['SUGARCRUSH_PROVIDER', 'SUGARCRUSH_BACKEND_CMD_STREAM', 'SUGARCRUSH_BACKEND_CMD'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }
        // `cat` echoes the history back: an offline backend that really runs.
        putenv('SUGARCRUSH_BACKEND_CMD=cat');
        file_put_contents($home . '/.sugar-crush/hooks.yaml', \sprintf(
            "hooks:\n  SessionEnd:\n    - name: mark-end\n      command: 'printf %%s \"\$CRUSH_TOOL_INPUT\" > %s'\n",
            $marker, // a temp path with no quote or space in it: the YAML value is single-quoted
        ));

        ob_start();
        $code = NonInteractive::run(ArgvParser::parse(['sugarcrush', '-p', 'hello there', '--root', $this->tempDir . '/repo']));
        $stdout = (string) ob_get_clean();

        self::assertSame(NonInteractive::EXIT_OK, $code);
        self::assertStringContainsString('hello there', $stdout);
        self::assertFileExists($marker, 'the SessionEnd hook never ran');
        self::assertSame('{"reason":"other"}', file_get_contents($marker));
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
