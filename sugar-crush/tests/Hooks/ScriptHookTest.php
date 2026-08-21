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
