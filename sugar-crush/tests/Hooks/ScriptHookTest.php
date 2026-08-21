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
     * Same pipe pressure, but on the branch that reports stderr back: a denying
     * hook's message must survive the DRAIN intact — and is then clipped, once,
     * on its way into the model's context.
     *
     * The two halves are asserted separately because they have separate
     * mutations, and the second is why this test no longer asserts a length of
     * 262144. The drain reading everything is what the marker's own byte count
     * proves: `ScriptHook::clip()` can only name 262144 if 262144 bytes reached
     * it, so a drain that truncated at the first read would print a smaller
     * total there and red this test. The clip itself is what stops a hook that
     * writes a quarter of a megabyte and denies from spending a quarter of a
     * megabyte of prompt on saying so.
     */
    public function testALargeStderrMessageSurvivesTheDrainAndIsThenClipped(): void
    {
        $result = $this->runHookBounded('printf "%0262144d" 0 >&2; exit 2');

        $this->assertSame('deny', $result['action']);
        $this->assertMatchesRegularExpression(
            '/truncated: 16384 of 262144 bytes shown/',
            $result['message'],
            'the drain has to have read all 262144 bytes for the clip to be able to count them',
        );
        $this->assertLessThan(
            20000,
            $result['length'],
            'a 256 KiB deny reason went into the prompt whole',
        );
    }

    /**
     * A deny reason that FITS is not touched — the clip must not be a tax on
     * every hook that explains itself.
     */
    public function testAnOrdinaryDenyReasonIsNotClipped(): void
    {
        $result = $this->runHookBounded('printf "policy: /etc/passwd is off limits" >&2; exit 2');

        $this->assertSame('deny', $result['action']);
        $this->assertSame('policy: /etc/passwd is off limits', $result['message']);
    }

    /**
     * THE DRAIN MUST NOT BUSY-WAIT while it waits out a silent hook.
     *
     * This is the assertion `DRAIN_SLICE_SECONDS` did not have, and without it
     * the constant survives being mutated to `0.0`: every deadline assertion in
     * this file still passes, because a zero slice expires ON TIME — it just
     * spends the entire budget in a `stream_select()` that returns instantly,
     * at 100% of a core, on the TUI's own thread, for up to sixty seconds.
     * "Bounded" and "not spinning" are two properties and only one of them was
     * being tested.
     *
     * Measured in the CHILD, around `execute()` itself, so the figure is this
     * drain's CPU and not the harness's: the hook sleeps for two seconds and
     * writes nothing, so at the shipped 200ms slice the parent wakes about ten
     * times and does nothing else at all.
     */
    public function testTheDrainWaitsWithoutSpinning(): void
    {
        $result = $this->runHookBounded('sleep 2; printf "ok"; exit 0');

        $this->assertSame('allow', $result['action']);
        $this->assertGreaterThan(1.5, $result['elapsed'], 'the hook has to have actually been waited for');
        $this->assertLessThan(
            0.3,
            $result['cpu'],
            sprintf(
                'the drain span instead of waiting: %.3fs CPU over %.3fs wall',
                $result['cpu'],
                $result['elapsed'],
            ),
        );
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
     * @param float|null $hookTimeoutSeconds the hook's OWN budget, distinct
     *        from $seconds, which is this test's external clock on the whole
     *        child process
     *
     * @return array{action: string, message: string, length: int, elapsed: float, cpu: float}
     */
    private function runHookBounded(
        string $command,
        ?int $signalAfterMicroseconds = null,
        float $seconds = 30.0,
        ?float $hookTimeoutSeconds = null,
    ): array {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $signal = $signalAfterMicroseconds === null ? 'null' : (string) $signalAfterMicroseconds;
        $timeout = $hookTimeoutSeconds === null
            ? 'SugarCraft\Crush\Hooks\ScriptHook::DEFAULT_TIMEOUT_SECONDS'
            : \var_export($hookTimeoutSeconds, true);

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
                {$timeout},
            );

            \$cpu = static function (): float {
                \$u = getrusage() ?: [];

                return (\$u['ru_utime.tv_sec'] ?? 0) + ((\$u['ru_utime.tv_usec'] ?? 0) / 1e6)
                    + (\$u['ru_stime.tv_sec'] ?? 0) + ((\$u['ru_stime.tv_usec'] ?? 0) / 1e6);
            };

            \$cpuBefore = \$cpu();
            \$started = microtime(true);
            \$result = \$hook->execute(new SugarCraft\Crush\Hooks\HookContext(
                'test_session_123', 'TestTool', [], 'test input', 'test output',
                'test-model', 'test-provider', '/tmp',
            ));

            fwrite(STDOUT, json_encode([
                'action' => \$result->action,
                'message' => \$result->message,
                'length' => strlen(\$result->message),
                'elapsed' => microtime(true) - \$started,
                'cpu' => \$cpu() - \$cpuBefore,
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
        self::assertIsFloat($decoded['elapsed'] ?? null);
        self::assertIsFloat($decoded['cpu'] ?? null);

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

    // =========================================================================
    // The deadline. Every case here HUNG before it — the drain's
    // stream_select() was passed a null timeout and proc_close() below it
    // waits, so the two halves of the wait were unbounded independently.
    // =========================================================================

    /**
     * A hook that simply does not exit is killed at its deadline and DENIED.
     *
     * The unbounded half being pinned is the DRAIN: `sleep` holds both pipes
     * open and writes nothing, so the old `stream_select($read, $write,
     * $except, null)` never woke at all. Measured against the pre-fix class,
     * this command under a 5-second external clock returned exit 124.
     *
     * The verdict is the security half of the fix and is asserted as such: an
     * expired hook has answered nothing, and the only readings other than DENY
     * are "allow", which silently skips the guard that was written to stop this
     * call, and "ask", which puts a question to a user who may not exist.
     */
    public function testAHookThatNeverExitsIsKilledAndDenied(): void
    {
        $result = $this->runHookBounded('sleep 30', hookTimeoutSeconds: 0.4);

        $this->assertSame('deny', $result['action']);
        $this->assertStringContainsString('did not finish within', $result['message']);
        $this->assertLessThan(
            5.0,
            $result['elapsed'],
            'the hook outlived its deadline by more than the escalation could account for',
        );
    }

    /**
     * The OTHER unbounded half: a hook that closes both pipes and keeps
     * running. The drain finishes at EOF on its first iteration and every
     * remaining second is spent inside `proc_close()`, which waits.
     *
     * This is not an exotic shape — `hook.sh >/dev/null 2>&1` is how a hook
     * whose only product is its exit code gets written. Bounding only the
     * drain would leave this case exactly as broken as it was, which is why
     * one deadline spans both.
     */
    public function testAHookThatClosesItsPipesAndKeepsRunningIsAlsoKilled(): void
    {
        $result = $this->runHookBounded(
            'printf hi; exec 1>&- 2>&-; sleep 30',
            hookTimeoutSeconds: 0.4,
        );

        $this->assertSame('deny', $result['action']);
        $this->assertLessThan(5.0, $result['elapsed'], 'proc_close() held the call past the deadline');
    }

    /**
     * A hook that TRAPS SIGTERM is still killed, because the expiry path
     * escalates to signal 9.
     *
     * Without the escalation the timeout is theatre: `proc_terminate()` on a
     * process that ignores TERM changes nothing, and the `proc_close()` that
     * follows waits for it anyway — the bounded path would be unbounded again,
     * one layer down.
     */
    public function testAHookThatTrapsSigtermIsStillKilled(): void
    {
        $result = $this->runHookBounded(
            "trap '' TERM; sleep 30",
            hookTimeoutSeconds: 0.4,
        );

        $this->assertSame('deny', $result['action']);
        $this->assertLessThan(5.0, $result['elapsed'], 'the TERM-ignoring hook was never escalated to signal 9');
    }

    /**
     * Whatever the hook managed to say before it wedged is carried into the
     * deny message.
     *
     * A timed-out gate is the case where the user most needs to know WHICH
     * hook stopped them and how far it got; discarding a half-written reason
     * because the run did not finish would report "denied" with no subject.
     */
    public function testATimedOutHookStillReportsWhatItManagedToSay(): void
    {
        $result = $this->runHookBounded(
            'printf "deploy window is closed" >&2; sleep 30',
            hookTimeoutSeconds: 0.4,
        );

        $this->assertSame('deny', $result['action']);
        $this->assertStringContainsString('bounded_hook', $result['message']);
        $this->assertStringContainsString('deploy window is closed', $result['message']);
    }

    /**
     * A hook that finishes well inside its budget is untouched — same verdict,
     * same message, and no wait for the deadline.
     *
     * The deadline is a ceiling, not a schedule: an implementation that slept
     * out the whole budget before answering would satisfy every assertion
     * above and make every hook cost 60 seconds.
     */
    public function testAHookWellInsideItsBudgetIsNotDelayedByIt(): void
    {
        $result = $this->runHookBounded('printf ok', hookTimeoutSeconds: 10.0);

        $this->assertSame('allow', $result['action']);
        $this->assertSame('ok', $result['message']);
        $this->assertLessThan(5.0, $result['elapsed'], 'a fast hook waited on its own ceiling');
    }

    /**
     * The default is a real bound rather than the absence of one, and it is the
     * constant {@see \SugarCraft\Crush\Hooks\HookConfig} documents.
     */
    public function testTheDefaultTimeoutIsPositiveAndFinite(): void
    {
        $this->assertGreaterThan(0.0, ScriptHook::DEFAULT_TIMEOUT_SECONDS);
        $this->assertSame(
            ScriptHook::DEFAULT_TIMEOUT_SECONDS,
            ScriptHook::fromConfig(['command' => 'guard.sh'])->timeoutSeconds(),
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableConfigTimeouts(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'prose' => ['none'],
            'true' => [true],
            'null' => [null],
            'infinity' => [INF],
            'negative infinity' => [-INF],
            'NaN' => [NAN],
        ];
    }

    /**
     * `fromConfig()` NEVER reads a bad timeout as "no timeout".
     *
     * The parser refuses these loudly; this constructor also serves callers
     * that never saw a file, and there the fallback has to be the default
     * rather than the value — the one thing this field must not be able to
     * express is the unbounded wait it was added to remove.
     *
     * @dataProvider unusableConfigTimeouts
     */
    public function testFromConfigFallsBackToTheDefaultForAnUnusableTimeout(mixed $value): void
    {
        $hook = ScriptHook::fromConfig(['command' => 'guard.sh', 'timeout' => $value]);

        $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, $hook->timeoutSeconds());
    }

    /** A usable one is honoured, ints and floats alike. */
    public function testFromConfigHonoursAPositiveTimeout(): void
    {
        $this->assertSame(5.0, ScriptHook::fromConfig(['command' => 'g', 'timeout' => 5])->timeoutSeconds());
        $this->assertSame(0.5, ScriptHook::fromConfig(['command' => 'g', 'timeout' => 0.5])->timeoutSeconds());
    }

    /**
     * THE CONSTRUCTOR IS A DOOR TOO, and `INF` walked through it.
     *
     * {@see HookConfig::parse()} now refuses `timeout: .inf`, but this class is
     * constructed directly by callers that never saw a file — and `INF > 0.0` is
     * true, so `timeoutSeconds()` handed it straight back and
     * `microtime(true) + INF` is an instant no clock reaches. The double cover
     * is deliberate: the parser is where a user's file is judged, and this is
     * where the invariant lives.
     *
     * @dataProvider nonFiniteConstructorTimeouts
     */
    public function testANonFiniteConstructedTimeoutIsReadAsUnset(float $value): void
    {
        $hook = new ScriptHook(
            name: 'g',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'true',
            description: '',
            timeoutSeconds: $value,
        );

        $this->assertSame(ScriptHook::DEFAULT_TIMEOUT_SECONDS, $hook->timeoutSeconds());
    }

    /**
     * @return array<string, array{0: float}>
     */
    public static function nonFiniteConstructorTimeouts(): array
    {
        return ['INF' => [INF], '-INF' => [-INF], 'NAN' => [NAN]];
    }

    /**
     * A chain may take budget away from a hook and may never grant it more —
     * see {@see \SugarCraft\Crush\Hooks\BoundedHookInterface}.
     */
    public function testWithTimeoutSecondsOnlyEverShortens(): void
    {
        $hook = ScriptHook::fromConfig(['command' => 'g', 'timeout' => 5]);

        $this->assertSame(2.0, $hook->withTimeoutSeconds(2.0)->timeoutSeconds());
        $this->assertSame(5.0, $hook->withTimeoutSeconds(9.0)->timeoutSeconds(), 'a hook cannot be granted more');
        $this->assertSame(5.0, $hook->timeoutSeconds(), 'the original is untouched');
    }

    /**
     * ZERO REMAINING MUST NOT BECOME SIXTY SECONDS.
     *
     * `timeoutSeconds()` reads a zero as "unset" and answers the default, so a
     * chain handing over the nothing it had left would have been given a full
     * minute back — the fail-open direction. The floor is one
     * `EXIT_POLL_MICROSECONDS`, which is the shortest bound this class can tell
     * apart from zero anyway.
     */
    public function testAnExhaustedBudgetFloorsRatherThanRestoringTheDefault(): void
    {
        $hook = ScriptHook::fromConfig(['command' => 'g', 'timeout' => 5]);

        foreach ([0.0, -3.0, NAN, -INF] as $exhausted) {
            $clamped = $hook->withTimeoutSeconds($exhausted)->timeoutSeconds();

            $this->assertGreaterThan(0.0, $clamped);
            $this->assertLessThan(
                1.0,
                $clamped,
                'an exhausted chain budget was read as "unset" and restored the default',
            );
        }
    }

    // =========================================================================
    // Payload transport — E65. The tool input reaches the child through a FILE
    // as well as the environment, because one env entry is capped by the kernel
    // =========================================================================

    /**
     * THE REGRESSION THIS PINS IS A DENY, AND IT IS THE ONE NOBODY READS AS A
     * SIZE PROBLEM.
     *
     * `execute()` used to hand the tool input to the child through
     * `CRUSH_TOOL_INPUT` and nothing else. Linux caps ONE environment entry at
     * `MAX_ARG_STRLEN` (131,072 bytes for `NAME=VALUE\0` together), so past that
     * `proc_open()` fails with `E2BIG`, the hook never runs, and
     * {@see ScriptHook::execute()}'s fail-closed branch denies the call.
     * MEASURED at afe3c26b, against a hook whose whole script is `exit(0)`:
     * 131,054 bytes of value allowed, 131,055 denied, and 200,000 and 1,000,000
     * denied identically with `Hook audit could not be executed`.
     *
     * That is fail-CLOSED and so not a hole, but it is a daily-driver blocker:
     * with any script hook installed, a `Write` of a 128 KB file — or a `Bash`
     * heredoc that size — cannot run, and the refusal names neither the size nor
     * the cause.
     */
    public function testAToolInputOverTheEnvironmentCeilingStillRunsTheHook(): void
    {
        $hook = new ScriptHook(
            name: 'oversize_input',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'exit 0',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput(
            json_encode(['body' => str_repeat('A', 200_000)], JSON_THROW_ON_ERROR),
        ));

        $this->assertTrue(
            $result->isAllowed(),
            'a 200 KB tool input denied the call before the hook could say anything: ' . $result->message,
        );
    }

    /**
     * ...and the hook can still SEE it. Running is not enough — a guard that is
     * handed no arguments allows things it would have denied, so the bytes the
     * environment cannot carry have to arrive somewhere the hook can read.
     */
    public function testAnOversizeToolInputIsReadableFromTheFileTheChildIsPointedAt(): void
    {
        $input = json_encode(['body' => str_repeat('A', 200_000)], JSON_THROW_ON_ERROR);

        $hook = new ScriptHook(
            name: 'oversize_reader',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'wc -c < "$CRUSH_TOOL_INPUT_FILE" | tr -d " \n"',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput($input));

        $this->assertTrue($result->isAllowed(), $result->message);
        $this->assertSame((string) \strlen($input), $result->message);
    }

    /**
     * A tool input that FITS is still handed over in `CRUSH_TOOL_INPUT`
     * verbatim. Every hook anyone has already written reads that variable —
     * `docs/HOOKS.md` documents it — so the file is an ADDITION, and a fix that
     * moved the payload wholesale would be a silent breaking change to a public
     * contract for the 99% of calls that never came near the ceiling.
     */
    public function testAToolInputThatFitsIsStillPassedInTheEnvironmentVerbatim(): void
    {
        $hook = new ScriptHook(
            name: 'env_reader',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "%s" "$CRUSH_TOOL_INPUT"',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput('{"file_path":"/etc/hosts"}'));

        $this->assertSame('{"file_path":"/etc/hosts"}', $result->message);
    }

    /**
     * THE BOUNDARY ITSELF, because every other test in this group probes at
     * 200 KB and would stay green if the fit computation drifted by 100 KB.
     *
     * The kernel's check is on `NAME=VALUE\0` TOGETHER, not on the value:
     * `strlen('CRUSH_TOOL_INPUT') + 1 + value + 1 <= 131072`, which puts the
     * last value that fits at **131,054** bytes. Measured at afe3c26b against
     * the old env-only transport by bisection: 131,054 allowed, 131,055 denied.
     * The same two lengths now separate "handed over verbatim" from "replaced
     * by the marker", which is the same arithmetic on the safe side of it.
     *
     * `wc -c` counts what the hook actually received, so an off-by-one in
     * either direction shows up as a length rather than as a verdict.
     */
    public function testTheEnvironmentIsUsedUpToTheKernelBoundaryAndNotPastIt(): void
    {
        $hook = new ScriptHook(
            name: 'boundary',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "%s" "$CRUSH_TOOL_INPUT" | wc -c | tr -d " \n"',
            description: '',
        );

        $fits = $hook->execute($this->createContext()->withToolInput(str_repeat('B', 131_054)));
        $this->assertSame('131054', $fits->message, 'the last value that fits was not passed verbatim');

        $overflows = $hook->execute($this->createContext()->withToolInput(str_repeat('B', 131_055)));
        $this->assertNotSame('131055', $overflows->message, 'a value the kernel refuses was passed anyway');
        $this->assertSame(
            (string) \strlen('@@CRUSH_PAYLOAD_IN_FILE@@ 131055 bytes; read $CRUSH_TOOL_INPUT_FILE'),
            $overflows->message,
            'one byte over the boundary did not fall back to the marker',
        );
    }

    /**
     * What an OVERSIZE `CRUSH_TOOL_INPUT` carries instead, and why it is not
     * the empty string and not a prefix of the JSON.
     *
     * A prefix is the dangerous one: truncated JSON is not smaller JSON, and a
     * hook that decodes it leniently judges a call that does not exist. Empty
     * is merely indistinguishable from "this event has no input". So the value
     * is a marker that cannot parse as JSON and says, in the value itself, how
     * many bytes there are and which variable holds them.
     */
    public function testAnOversizeToolInputLeavesANonJsonMarkerInTheEnvironment(): void
    {
        $input = json_encode(['body' => str_repeat('A', 200_000)], JSON_THROW_ON_ERROR);

        $hook = new ScriptHook(
            name: 'marker_reader',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "%s" "$CRUSH_TOOL_INPUT"',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput($input));

        $this->assertNull(json_decode($result->message, true), 'the marker decoded as JSON');
        $this->assertStringContainsString((string) \strlen($input), $result->message);
        $this->assertStringContainsString('CRUSH_TOOL_INPUT_FILE', $result->message);
    }

    /**
     * `CRUSH_TOOL_OUTPUT` is the SAME env entry on the SAME line and it has the
     * same kernel ceiling — measured at afe3c26b, a 200,000-byte tool output
     * denied the PostToolUse chain exactly as an oversize input denied the pre
     * chain. A fix that bounded only the input would have left the identical
     * defect one line down, which is the recurring defect of this audit.
     *
     * A tool's own `maxOutputBytes` default is 65,536
     * ({@see \SugarCraft\Crush\Tools\Concerns\TruncatesOutput}), so this is
     * reachable only from a tool constructed with a larger cap or none — which
     * is a constructor argument, not an invariant.
     */
    public function testAnOversizeToolOutputTakesTheSameRouteAsTheInput(): void
    {
        $output = str_repeat('C', 200_000);

        $hook = new ScriptHook(
            name: 'post_reader',
            event: HookEvent::PostToolUse,
            matcher: '.*',
            command: 'wc -c < "$CRUSH_TOOL_OUTPUT_FILE" | tr -d " \n"',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolOutput($output));

        $this->assertTrue($result->isAllowed(), $result->message);
        $this->assertSame((string) \strlen($output), $result->message);
    }

    /**
     * The payload files are the hook's, not the session's: they hold whatever
     * the model asked to write, which is the one place a secret is most likely
     * to be, and leaving them behind turns every hook run into a copy of the
     * tool call in the shared temp directory.
     */
    public function testThePayloadFilesAreRemovedOnceTheHookHasRun(): void
    {
        $hook = new ScriptHook(
            name: 'path_reporter',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf "%s" "$CRUSH_TOOL_INPUT_FILE"',
            description: '',
        );

        $result = $hook->execute($this->createContext());

        $this->assertTrue($result->isAllowed(), $result->message);
        $this->assertNotSame('', $result->message, 'no input file was handed to the hook at all');
        $this->assertFileDoesNotExist($result->message);
    }

    /**
     * NEITHER ROUTE AVAILABLE IS A DENY, not a hook that runs blind.
     *
     * The environment cannot hold the value and the filesystem will not give us
     * a file, so the child would either fail to exec or start with the payload
     * missing — and a guard handed no arguments allows what it would have
     * denied. Same fail-closed rule as an unusable `cwd`.
     *
     * `TMPDIR` is pointed at a REGULAR FILE rather than at an unwritable
     * directory, deliberately: `tempnam()` into a path that is a file fails for
     * root as well, so this needs no `posix_geteuid()` guard and cannot turn
     * into a conditional skip on a CI runner that happens to be root.
     *
     * It runs in a CHILD because `sys_get_temp_dir()` caches its answer on the
     * first call, so a `putenv()` inside a suite that has already used a temp
     * file changes nothing.
     */
    public function testAPayloadThatFitsNeitherRouteFailsClosed(): void
    {
        $notADirectory = tempnam(sys_get_temp_dir(), 'scripthook_notadir_');
        self::assertIsString($notADirectory);

        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            \$hook = new SugarCraft\Crush\Hooks\ScriptHook(
                'starved_hook',
                SugarCraft\Crush\Hooks\HookEvent::PreToolUse,
                '.*',
                'exit 0',
                '',
            );

            \$result = \$hook->execute(new SugarCraft\Crush\Hooks\HookContext(
                'sid', 'Bash', [], str_repeat('B', 200000), '',
                'test-model', 'test-provider', '/tmp',
            ));

            fwrite(STDOUT, json_encode(['action' => \$result->action, 'message' => \$result->message]));
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'scripthook_starved_');
        self::assertIsString($file);
        file_put_contents($file, $script);

        try {
            $process = proc_open(
                [PHP_BINARY, $file],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                ['TMPDIR' => $notADirectory, 'PATH' => (string) getenv('PATH')],
            );
            self::assertIsResource($process);

            $stdout = (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            @unlink($file);
            @unlink($notADirectory);
        }

        $decoded = json_decode($stdout, true);
        self::assertIsArray($decoded, "the child reported nothing: {$stdout}");
        $this->assertSame('deny', $decoded['action']);
        $this->assertStringContainsString('CRUSH_TOOL_INPUT', (string) $decoded['message']);
        $this->assertStringContainsString('no temporary file could be created', (string) $decoded['message']);
    }

    // =========================================================================
    // Non-DENY output bounds — E60. ASK is clipped, MODIFY is refused
    // =========================================================================

    /**
     * AN `exit 3` QUESTION IS PROMPT TEXT AND IS NOW CLIPPED LIKE A DENY REASON.
     *
     * Measured at afe3c26b through the live path
     * (`HookManager::preToolUse()` → `HookRegistry::executeHooks()`): a hook
     * printing 200,000 bytes and exiting 3 produced a 200,000-byte
     * `HookResult::message`, and it reaches two places from there — the TUI's
     * permission modal, and {@see \SugarCraft\Crush\Runtime::settleAsk()},
     * which on a run with NO approver attached interpolates it whole into
     * `"Permission required and no approver is attached to this run: …"` and
     * hands that to the model as the tool result.
     *
     * The objection the old docblock raised — "clipping it changes what the
     * human is answering" — is real and is answered rather than ignored: the
     * clip announces itself, so the human and the model both see that the
     * question was cut, which is strictly more than a 200 KB modal tells
     * anybody. What is NOT answered by clipping is a question whose operative
     * clause is at the end; that is the accepted cost, and it is the same one
     * the deny path already accepts.
     */
    public function testALargeAskQuestionIsClipped(): void
    {
        $result = $this->runHookBounded('printf "%0262144d" 0; exit 3');

        $this->assertSame('ask', $result['action']);
        $this->assertMatchesRegularExpression(
            '/truncated: 16384 of 262144 bytes shown/',
            $result['message'],
            'the drain has to have read all 262144 bytes for the clip to be able to count them',
        );
        $this->assertLessThan(20000, $result['length'], 'a 256 KiB question went into the prompt whole');
    }

    /**
     * A question that FITS is untouched — the clip must not be a tax on every
     * hook that explains what it is asking.
     */
    public function testAnOrdinaryAskQuestionIsNotClipped(): void
    {
        $result = $this->runHookBounded('printf "Allow this write to /etc/hosts?"; exit 3');

        $this->assertSame('ask', $result['action']);
        $this->assertSame('Allow this write to /etc/hosts?', $result['message']);
    }

    /**
     * AN `exit 4` REWRITE IS REFUSED OVER ITS CEILING, NEVER CLIPPED — and the
     * two are not interchangeable here the way they are for a deny reason.
     *
     * `modifiedInput` is not prompt text. It is the ARGUMENT MAP that executes:
     * {@see \SugarCraft\Crush\Runtime::rewrittenArguments()} and
     * {@see \SugarCraft\Crush\Chat::applyRewrite()} decode it and run the tool
     * with it. Truncating it does not produce smaller arguments — it produces
     * invalid JSON, which {@see HookResult::rewrittenArgs()} reports as null,
     * and every consumer then falls back to the ORIGINAL arguments. That is
     * precisely the outcome {@see ScriptHook::modifyOrDeny()} exists to
     * prevent: a hook that meant to replace dangerous arguments having the
     * untouched originals run in its place.
     *
     * So the assertion is on BOTH halves: the call is denied, and the result
     * carries no rewrite at all.
     */
    public function testARewriteOverTheCeilingIsRefusedAndCarriesNoTruncatedArguments(): void
    {
        $hook = new ScriptHook(
            name: 'inflating_rewriter',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf \'{"command":"%0200000d"}\' 0; exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput('{"command":"ls"}'));

        $this->assertTrue($result->isDenied(), 'a 200 KB rewrite of a 16-byte call was accepted');
        $this->assertNull($result->modifiedInput, 'a refused rewrite still carried arguments');
        $this->assertStringContainsString('200014', $result->message, 'the refusal does not name the size');
    }

    /**
     * ...and the ceiling is DERIVED, not invented: a rewrite may be as large as
     * the arguments it replaces, or 16,384 bytes, whichever is larger.
     *
     * A flat 16 KiB cap would have broken the legitimate case outright — a
     * sanitiser that changes the `file_path` of a 300 KB `Write` and leaves the
     * body alone has to emit the body back, so its rewrite is necessarily about
     * the size of the call it replaces. Bounding it by that size instead stops
     * a hook turning a small call into an enormous one while leaving the large
     * call the hook was written for alone; and because the bound tracks the
     * input, the re-scan in {@see \SugarCraft\Crush\Hooks\HookRegistry::executeHooks()}
     * cannot ratchet it upward across passes.
     *
     * This test is also the one that would still be red if only E60 had been
     * fixed: 300 KB of tool input cannot reach a child through the environment
     * at all.
     */
    public function testARewriteNoLargerThanTheCallItReplacesIsAccepted(): void
    {
        $original = json_encode(['body' => str_repeat('A', 300_000)], JSON_THROW_ON_ERROR);

        $hook = new ScriptHook(
            name: 'sanitising_rewriter',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf \'{"body":"%0299000d"}\' 0; exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput($original));

        $this->assertTrue($result->isModified(), 'a same-size rewrite was refused: ' . $result->message);

        // The WHOLE rewrite survived — asserted through the decode rather than
        // on a byte count written out here, since a hand-copied length next to
        // a `printf` width is the exact class of stale figure this audit hunts.
        $rewritten = $result->rewrittenArgs();
        $this->assertIsArray($rewritten);
        $this->assertSame(299_000, \strlen((string) ($rewritten['body'] ?? '')));
    }

    /**
     * An ordinary small rewrite is unaffected — the floor is what keeps the
     * common case (a hook rewriting a two-line `Bash` call) from depending on
     * how long the original happened to be.
     */
    public function testAnOrdinarySmallRewriteIsAccepted(): void
    {
        $hook = new ScriptHook(
            name: 'small_rewriter',
            event: HookEvent::PreToolUse,
            matcher: '.*',
            command: 'printf \'{"command":"ls -l --safe"}\'; exit 4',
            description: '',
        );

        $result = $hook->execute($this->createContext()->withToolInput('{"command":"ls"}'));

        $this->assertTrue($result->isModified(), $result->message);
        $this->assertSame('{"command":"ls -l --safe"}', $result->modifiedInput);
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
