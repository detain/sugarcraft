<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Hooks;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;

/**
 * @see ScriptHook
 */
final class ScriptHookTest extends TestCase
{
    // =========================================================================
    // fromConfig Tests
    // =========================================================================

    public function testFromConfig(): void
    {
        $config = [
            'event' => 'PreToolUse',
            'matcher' => '^Read$',
            'command' => 'my_script.sh',
            'description' => 'Test hook',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertInstanceOf(ScriptHook::class, $hook);
        $this->assertSame('my_script.sh', $hook->name());
        $this->assertSame(HookEvent::PreToolUse, $hook->event());
        $this->assertSame('^Read$', $hook->matcher());
    }

    public function testFromConfigPostToolUseEvent(): void
    {
        $config = [
            'event' => 'PostToolUse',
            'command' => 'post_hook.sh',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testFromConfigInvalidEventFallsBackToPreToolUse(): void
    {
        $config = [
            'event' => 'InvalidEvent',
            'command' => 'test.sh',
        ];

        $hook = ScriptHook::fromConfig($config);

        $this->assertSame(HookEvent::PreToolUse, $hook->event());
    }

    // =========================================================================
    // Accessor Tests
    // =========================================================================

    public function testName(): void
    {
        $hook = new ScriptHook(
            name: 'my_hook_name',
            event: HookEvent::PreToolUse,
            matcher: '^Read$',
            command: 'echo test',
            description: 'Test description',
        );

        $this->assertSame('my_hook_name', $hook->name());
    }

    public function testEvent(): void
    {
        $hook = new ScriptHook(
            name: 'test',
            event: HookEvent::PostToolUse,
            matcher: '.*',
            command: 'echo test',
            description: '',
        );

        $this->assertSame(HookEvent::PostToolUse, $hook->event());
    }

    public function testMatcher(): void
    {
        $hook = new ScriptHook(
            name: 'test',
            event: HookEvent::PreToolUse,
            matcher: '^File(Read|Write)$',
            command: 'echo test',
            description: '',
        );

        $this->assertSame('^File(Read|Write)$', $hook->matcher());
    }

    // =========================================================================
    // execute Tests
    // =========================================================================

    public function testExecuteAllow(): void
    {
        $hook = new ScriptHook(
            name: 'allow_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "allowed"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('allowed', $result->message);
    }

    public function testExecuteDeny(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "access denied" >&2 && exit 1',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('access denied', $result->message);
    }

    public function testExecuteDenyWithExitCode(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 42',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isDenied());
        $this->assertSame('Hook exited with code 42', $result->message);
    }

    public function testExecuteAllowWithEmptyOutput(): void
    {
        $hook = new ScriptHook(
            name: 'empty_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'true',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('', $result->message);
    }

    public function testExecutePassesEnvironmentVariables(): void
    {
        // This test verifies the hook receives env vars by running a script that outputs them
        $hook = new ScriptHook(
            name: 'env_check',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "$CRUSH_TOOL_NAME:$CRUSH_SESSION_ID"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('TestTool:test_session_123', $result->message);
    }

    public function testExecuteWithWhitespaceOutput(): void
    {
        $hook = new ScriptHook(
            name: 'whitespace_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "  hello world  \n\t"',
            description: '',
        );

        $context = $this->createContext();

        $result = $hook->execute($context);

        $this->assertTrue($result->isAllowed());
        $this->assertSame('hello world', $result->message);
    }

    // =========================================================================
    // Fail-closed Tests — an unusable projectRoot must not become an allow
    // =========================================================================

    /**
     * The regression this pins: `proc_open()` refuses to start a process whose
     * `cwd` does not exist, and ScriptHook used to translate that failure into
     * `HookResult::allow()`. A `--root /typo` therefore turned every DENYING
     * hook into an allow — the one direction a gate must never fail in
     * (crush_code.md Phase 0 item 6).
     */
    public function testADenyingHookStaysDenyingWhenTheProjectRootDoesNotExist(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "access denied" >&2 && exit 1',
            description: '',
        );

        $result = $hook->execute($this->createContext(
            sys_get_temp_dir() . '/sugarcrush_no_such_root_' . uniqid('', true),
        ));

        $this->assertTrue($result->isDenied(), 'a bogus --root must not downgrade a deny into an allow');
        $this->assertSame('access denied', $result->message);
    }

    /**
     * `TaskList::makeHookContext()` used to hardcode `projectRoot: ''`, which
     * hits the same `proc_open()` failure as a bogus `--root`.
     */
    public function testADenyingHookStaysDenyingWhenTheProjectRootIsEmpty(): void
    {
        $hook = new ScriptHook(
            name: 'deny_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 42',
            description: '',
        );

        $result = $hook->execute($this->createContext(''));

        $this->assertTrue($result->isDenied());
        $this->assertSame('Hook exited with code 42', $result->message);
    }

    /**
     * The fallback only replaces the DIRECTORY, never the verdict — a usable
     * root is still the directory the script runs in.
     */
    public function testAUsableProjectRootIsStillTheScriptsWorkingDirectory(): void
    {
        $root = sys_get_temp_dir() . '/sugarcrush_root_' . uniqid('', true);
        mkdir($root, 0755, true);

        $hook = new ScriptHook(
            name: 'pwd_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'pwd',
            description: '',
        );

        try {
            $result = $hook->execute($this->createContext($root));

            $this->assertTrue($result->isAllowed());
            $this->assertSame(realpath($root), realpath($result->message));
        } finally {
            rmdir($root);
        }
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    // =========================================================================
    // Exit-code contract: ask (3) and modify (4)
    //
    // 0/1/2 are HookDispatcher's already-documented allow/deny/block codes and
    // are covered above; these pin the two crush_code.md Phase 1 item 2 added
    // so a YAML-configured hook can reach ASK/MODIFY at all.
    // =========================================================================

    public function testExitThreeAsksWithStdoutAsTheQuestion(): void
    {
        $hook = new ScriptHook(
            name: 'ask_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "Deploy to production?" && exit 3',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isAsk());
        $this->assertFalse($result->permitsExecution());
        $this->assertSame('Deploy to production?', $result->message);
    }

    public function testExitThreeWithNoOutputFallsBackToTheDescription(): void
    {
        $hook = new ScriptHook(
            name: 'ask_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 3',
            description: 'Confirm before deploying',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isAsk());
        $this->assertSame('Confirm before deploying', $result->message);
    }

    public function testExitThreeWithNoOutputAndNoDescriptionStillAsksSomethingAnswerable(): void
    {
        $hook = new ScriptHook(
            name: 'ask_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 3',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isAsk());
        $this->assertSame('Hook ask_hook requires your approval', $result->message);
    }

    public function testExitFourModifiesWithStdoutJson(): void
    {
        $hook = new ScriptHook(
            name: 'modify_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf \'{"command":"ls -la"}\' && exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isModified());
        $this->assertTrue($result->permitsExecution());
        $this->assertSame('{"command":"ls -la"}', $result->modifiedInput);
        $this->assertSame(['command' => 'ls -la'], json_decode((string) $result->modifiedInput, true));
    }

    /**
     * The direction this must fail in: a rewrite hook that produced garbage
     * was trying to CHANGE the arguments, so running the originals in its
     * place is the one outcome it definitely did not ask for.
     *
     * @dataProvider unusableModifyPayloads
     */
    public function testExitFourWithAnUnusableJsonPayloadDeniesRatherThanAllows(string $stdout): void
    {
        $hook = new ScriptHook(
            name: 'modify_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf ' . escapeshellarg($stdout) . ' && exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isDenied());
        $this->assertFalse($result->permitsExecution());
        $this->assertNull($result->modifiedInput);
        $this->assertStringContainsString('did not print a JSON object', $result->message);
    }

    /**
     * `[]` is in here because it is a JSON LIST, which the contract rejects —
     * and because `json_decode('[]', true)` and `json_decode('{}', true)` are
     * the same PHP value, so a decoded-value test cannot tell them apart and
     * used to accept both. Accepting `[]` ran the tool with zero arguments off
     * a payload the hook's own contract calls invalid.
     *
     * @return array<string, array{0: string}>
     */
    public static function unusableModifyPayloads(): array
    {
        return [
            'syntax error' => ['{not json'],
            'empty output' => [''],
            'json list' => ['[1,2]'],
            'json scalar' => ['"a string"'],
            'empty json list' => ['[]'],
            'whitespace-padded json list' => ["  [1,2]  \n"],
        ];
    }

    /**
     * The mirror of the case above: an object whose keys happen to be numeric
     * strings decodes to something `array_is_list()` calls a list, so a
     * decoded-value test refused a rewrite the hook had every right to ask for.
     * The JSON text is the only place the object/list distinction survives.
     *
     * @dataProvider usableModifyPayloads
     */
    public function testExitFourAcceptsEveryRealJsonObject(string $stdout, array $expected): void
    {
        $hook = new ScriptHook(
            name: 'modify_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf ' . escapeshellarg($stdout) . ' && exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isModified());
        $this->assertSame($expected, json_decode((string) $result->modifiedInput, true));
    }

    /**
     * @return array<string, array{0: string, 1: array<array-key, mixed>}>
     */
    public static function usableModifyPayloads(): array
    {
        return [
            'numerically keyed object' => ['{"0":"a","1":"b"}', ['a', 'b']],
            // An explicit "run this tool with no arguments" — a rewrite the
            // hook deliberately asked for, unlike `[]`.
            'empty object' => ['{}', []],
            'leading whitespace' => ["  {\"command\":\"ls\"}", ['command' => 'ls']],
        ];
    }

    /**
     * A hook that is verbose on stderr must not wedge the CLI.
     *
     * Reading stdout to EOF and only then reading stderr deadlocks as soon as
     * the child fills the 64 KiB stderr pipe: the parent blocks on stdout, the
     * child blocks on a stderr pipe nobody is draining. A denying guard
     * explaining itself at length is exactly the hook most likely to do that.
     *
     * 256 KiB, four buffers' worth, so the test still bites on a platform with
     * a larger pipe than Linux's.
     *
     * Run through {@see runHookBounded()} rather than in-process, because the
     * failure mode being pinned is a HANG: `execute()` never returns, so an
     * in-process wall-clock assertion after it is never reached and a
     * regression takes out the whole suite on whatever external timeout CI
     * happens to have (exit 124), naming no test.
     */
    public function testAHookWritingMoreThanOnePipeBufferToStderrDoesNotDeadlock(): void
    {
        // stderr first and stdout last, so a stdout-then-stderr reader has to
        // survive a stderr pipe that filled before stdout said anything.
        $result = $this->runHookBounded('printf "%0262144d" 0 >&2; printf "ok"; exit 0');

        $this->assertSame('allow', $result['action']);
        $this->assertSame('ok', $result['message']);
    }

    /**
     * Same pipe pressure, but on the branch that reports stderr back: a
     * denying hook's message must survive the drain intact rather than being
     * truncated at the first read.
     */
    public function testALargeStderrMessageIsReportedInFull(): void
    {
        $result = $this->runHookBounded('printf "%0262144d" 0 >&2; exit 2');

        $this->assertSame('deny', $result['action']);
        $this->assertSame(262144, $result['length']);
    }

    /**
     * A signal delivered while the drain is parked in `stream_select()` must
     * not truncate the hook's output.
     *
     * `stream_select()` is not installed with `SA_RESTART`, so EINTR makes it
     * return false — and `SugarCraft\Core\Program` turns on
     * `pcntl_async_signals()` and installs SIGWINCH/SIGINT handlers for the
     * whole TUI, which makes a terminal RESIZE mid-hook routine rather than
     * exotic. Breaking out of the loop on that `false` dropped everything the
     * hook had not written yet: the first version of this drain returned
     * `'AAAA'` where `stream_get_contents()` had returned `'AAAABBBB'`, which
     * is a REGRESSION against the deadlocking code it replaced.
     *
     * The verdict is unaffected either way (it comes from `proc_close()`), so
     * this is asserted on the MESSAGE — the deny reason, the `exit 3`
     * question, and the `exit 4` rewrite JSON all live there, and a truncated
     * rewrite is invalid JSON, which {@see ScriptHook::modifyOrDeny()} turns
     * into a deny of a call the hook meant to permit.
     */
    public function testOutputSurvivesASignalDeliveredDuringTheDrain(): void
    {
        if (!\function_exists('pcntl_fork') || !\function_exists('pcntl_async_signals')) {
            $this->markTestSkipped('pcntl is required to deliver a signal mid-drain');
        }

        $result = $this->runHookBounded(
            'printf "AAAA"; sleep 1; printf "BBBB"; exit 0',
            signalAfterMicroseconds: 200_000,
        );

        $this->assertSame('allow', $result['action']);
        $this->assertSame('AAAABBBB', $result['message'], 'the drain dropped everything after the signal');
    }

    /**
     * Run one {@see ScriptHook} command in a CHILD PHP process under a wall
     * clock, so a drain that wedges fails this test instead of the suite.
     *
     * @param int|null $signalAfterMicroseconds when set, the child installs a
     *        SIGWINCH handler the way `Program` does and has a grandchild
     *        raise it that far into the hook run
     *
     * @return array{action: string, message: string, length: int}
     */
    private function runHookBounded(string $command, ?int $signalAfterMicroseconds = null, float $seconds = 30.0): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $signal = $signalAfterMicroseconds === null ? 'null' : (string) $signalAfterMicroseconds;

        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            \$signalAfter = {$signal};
            if (\$signalAfter !== null) {
                pcntl_async_signals(true);
                pcntl_signal(SIGWINCH, static function (): void {});
                \$parent = getmypid();
                \$pid = pcntl_fork();
                if (\$pid === 0) {
                    usleep(\$signalAfter);
                    posix_kill(\$parent, SIGWINCH);
                    exit(0);
                }
            }

            \$hook = new SugarCraft\Crush\Hooks\ScriptHook(
                'bounded_hook',
                SugarCraft\Crush\Hooks\HookEvent::PreToolUse,
                '.*',
                {$this->export($command)},
                '',
            );

            \$result = \$hook->execute(new SugarCraft\Crush\Hooks\HookContext(
                'test_session_123', 'TestTool', [], 'test input', 'test output',
                'test-model', 'test-provider', '/tmp',
            ));

            fwrite(STDOUT, json_encode([
                'action' => \$result->action,
                'message' => \$result->message,
                'length' => strlen(\$result->message),
            ]));
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'scripthook_bounded_');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $decoded = json_decode($this->runBounded([PHP_BINARY, $file], $seconds), true);
        } finally {
            @unlink($file);
        }

        self::assertIsArray($decoded, 'the bounded child did not report a hook result');
        self::assertIsString($decoded['action'] ?? null);
        self::assertIsString($decoded['message'] ?? null);
        self::assertIsInt($decoded['length'] ?? null);

        return $decoded;
    }

    /**
     * @param list<string> $argv
     */
    private function runBounded(array $argv, float $seconds): string
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $seconds;
        $out = '';
        $err = '';

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if ($status['running'] === false) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, \defined('SIGKILL') ? SIGKILL : 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                self::fail("the hook drain did not finish within {$seconds}s — it wedged");
            }

            usleep(10_000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', trim($err), 'the bounded child wrote to stderr');

        return $out;
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    public function testExitTwoStillHardDenies(): void
    {
        $hook = new ScriptHook(
            name: 'block_hook',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "blocked" >&2 && exit 2',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isDenied());
        $this->assertSame('blocked', $result->message);
    }

    public function testFromConfigHonoursAnExplicitName(): void
    {
        $hook = ScriptHook::fromConfig([
            'name' => 'confirm-deploy',
            'event' => 'PreToolUse',
            'matcher' => '^Bash$',
            'command' => './hooks/confirm.sh',
            'description' => '',
        ]);

        $this->assertSame('confirm-deploy', $hook->name());
    }

    public function testFromConfigFallsBackToTheCommandWhenNameIsBlank(): void
    {
        $hook = ScriptHook::fromConfig([
            'name' => '',
            'command' => './hooks/confirm.sh',
        ]);

        $this->assertSame('./hooks/confirm.sh', $hook->name());
    }

    private function createContext(string $projectRoot = '/tmp'): HookContext
    {
        return new HookContext(
            sessionId: 'test_session_123',
            toolName: 'TestTool',
            toolArgs: [],
            toolInput: 'test input',
            toolOutput: 'test output',
            model: 'test-model',
            provider: 'test-provider',
            projectRoot: $projectRoot,
        );
    }
}
