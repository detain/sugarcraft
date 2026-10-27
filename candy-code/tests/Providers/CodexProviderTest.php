<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\CodexInvocation;
use SugarCraft\Code\Providers\CodexProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CompleteResponse;
use SugarCraft\Code\Providers\ProviderException;

/**
 * PRV-4 tier 1: the codex transport pair, pinned against a FAKE `codex`
 * placed on PATH — never the real CLI, which would spend credentials.
 *
 * The emitted event shapes are the ones live-probed against codex-cli 0.154.0
 * (thread.started / turn.started / item.completed / turn.completed /
 * turn.failed and bare error frames from an unauthenticated run, with the
 * agent_message text and TokenUsage bucket spellings taken from the binary's
 * serde schema). The probed-version const is pinned so the day the framing
 * drifts upstream, drift here is a named event, not a silent lie.
 */
final class CodexProviderTest extends TestCase
{
    private string $dir = '';

    private string|false $savedPath = false;

    /**
     * The fake: logs argv and stdin, then prints the fixture for its mode.
     * `pcntl_alarm` bounds it so a parent-side pipe deadlock fails the test
     * instead of hanging the suite.
     */
    private const FAKE_CODEX = <<<'PHP'
    #!PHP_BIN
    <?php
    if (function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGALRM, static function (): void { fwrite(STDERR, "fake-codex: alarm\n"); exit(9); }); pcntl_alarm(20); }
    $log = getenv('FAKE_CODEX_LOG');
    $mode = getenv('FAKE_CODEX_MODE') ?: 'happy';
    file_put_contents("$log/argv.json", json_encode($argv));
    $stdin = stream_get_contents(STDIN);
    file_put_contents("$log/stdin.txt", $stdin);
    $frame = static function (array $data): void { echo json_encode($data), "\n"; };
    if ($mode === 'fail') {
        $frame(['type' => 'thread.started', 'thread_id' => 'thr-fake']);
        $frame(['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'error', 'message' => 'connecting']]);
        $frame(['type' => 'error', 'message' => 'unauthorized']);
        $frame(['type' => 'turn.failed', 'error' => ['message' => 'log in required']]);
        exit(1);
    }
    if ($mode === 'garbage') {
        echo "not json at all\n";
        exit(0);
    }
    if ($mode === 'sizes') {
        $frame(['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'agent_message', 'text' => 'prompt=' . strlen($stdin)]]);
        $frame(['type' => 'turn.completed', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]]);
        exit(0);
    }
    if ($mode === 'slow') {
        file_put_contents("$log/pid.txt", (string) getmypid());
        $frame(['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'agent_message', 'text' => 'first']]);
        flush();
        sleep(30);
        exit(0);
    }
    // happy: the measured framing, chatter included, plus a narrating
    // mid-task agent_message the aggregator must not double-count.
    $frame(['type' => 'thread.started', 'thread_id' => 'thr-fake']);
    $frame(['type' => 'turn.started']);
    $frame(['type' => 'item.started', 'item' => ['id' => 'item_0', 'type' => 'command_execution']]);
    $frame(['type' => 'item.completed', 'item' => ['id' => 'item_0', 'type' => 'agent_message', 'text' => 'Looking now.']]);
    $frame(['type' => 'item.completed', 'item' => ['id' => 'item_1', 'type' => 'agent_message', 'text' => 'Done.']]);
    $frame(['type' => 'turn.completed', 'usage' => ['input_tokens' => 100, 'cached_input_tokens' => 40, 'cache_write_input_tokens' => 5, 'output_tokens' => 12, 'reasoning_output_tokens' => 4, 'total_tokens' => 112]]);
    exit(0);
    PHP;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_codex_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);

        $script = $this->dir . '/codex';
        file_put_contents($script, str_replace('#!PHP_BIN', '#!' . PHP_BINARY, self::FAKE_CODEX));
        chmod($script, 0o755);

        $this->savedPath = getenv('PATH');
        putenv('PATH=' . $this->dir . ($this->savedPath !== false ? ':' . $this->savedPath : ''));
        putenv('FAKE_CODEX_LOG=' . $this->dir);
        putenv('FAKE_CODEX_MODE');
    }

    protected function tearDown(): void
    {
        putenv($this->savedPath === false ? 'PATH' : 'PATH=' . $this->savedPath);
        putenv('FAKE_CODEX_LOG');
        putenv('FAKE_CODEX_MODE');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function codexProvider(string $model = CodexProvider::DEFAULT_MODEL, ?string $sandbox = null): CodexProvider
    {
        return new CodexProvider(new CodexInvocation(), $model, $sandbox);
    }

    /**
     * @param array<\SugarCraft\Code\Messages\Message> $messages
     */
    private function request(array $messages, ?string $system = null): CompleteRequest
    {
        return new CompleteRequest(model: 'probe-model', messages: $messages, systemPrompt: $system);
    }

    // =========================================================================
    // Pure shapes: capabilities, version pin, argv construction
    // =========================================================================

    public function testTheProbedVersionConstIsPinned(): void
    {
        $this->assertSame('0.154.0', CodexInvocation::PROBED_CODEX_VERSION);
    }

    public function testCapabilitiesTellTheHonestTier1Story(): void
    {
        $provider = $this->codexProvider();

        $this->assertSame('codex', $provider->name());
        $this->assertTrue($provider->supportsStreaming());
        // AUT-4 doctrine: no tool relay into the child means the answer is
        // false, not the aspirational true.
        $this->assertFalse($provider->supportsFunctionCalling());
        $this->assertFalse($provider->supportsDocuments());
        $this->assertFalse($provider->supportsVision());
        $this->assertFalse($provider->supportsJsonSchema());
        $this->assertSame(0, $provider->contextWindow(), 'unknown, never guessed');
        $this->assertSame(0.0, $provider->costPer1kTokens('anything', 'input'));
    }

    public function testInvocationDefaultsAndAccessors(): void
    {
        $invocation = new CodexInvocation();
        $this->assertSame('codex', $invocation->codexPath());
        $this->assertNull($invocation->sessionId());
        $this->assertSame([], $invocation->baseArgs());

        $resuming = new CodexInvocation(codexPath: '/opt/bin/codex', sessionId: 'thr-9');
        $this->assertSame('/opt/bin/codex', $resuming->codexPath());
        $this->assertSame(['resume', 'thr-9'], $resuming->baseArgs());
    }

    public function testExecArgsCarryFlagsAndEndInTheStdinOperand(): void
    {
        $plain = (new CodexInvocation())->execArgs(['model' => 'gpt-5-probe']);
        $this->assertSame(['exec', '--json', '-m', 'gpt-5-probe', '-'], $plain);

        $resuming = (new CodexInvocation(sessionId: 'thr-9'))->execArgs([]);
        $this->assertSame(['exec', 'resume', 'thr-9', '--json', '-'], $resuming);

        $sand = (new CodexInvocation())->execArgs(['model' => 'm', 'sandbox' => 'read-only']);
        $this->assertSame(['exec', '--json', '-m', 'm', '-s', 'read-only', '-'], $sand);

        // C-PRV-1.a: a shaped tier becomes one -c line between the sandbox
        // flag and the stdin operand - never a positional argument.
        $effort = (new CodexInvocation())->execArgs(['model' => 'm', 'reasoningEffort' => 'xhigh']);
        $this->assertSame(['exec', '--json', '-m', 'm', '-c', 'model_reasoning_effort=xhigh', '-'], $effort);
    }

    public function testOptionsThreadNamedEffortTiersAndRefuseTheRest(): void
    {
        $optionsOf = new \ReflectionMethod(CodexProvider::class, 'options');
        $optionsOf->setAccessible(true);

        $tiered = new CompleteRequest(model: 'probe-model', messages: [], reasoningEffort: 'high');
        $this->assertSame(
            ['model' => CodexProvider::DEFAULT_MODEL, 'reasoningEffort' => 'high'],
            $optionsOf->invoke($this->codexProvider(), $tiered),
        );

        $dial = new CompleteRequest(model: 'probe-model', messages: [], reasoningEffort: 0.5);
        $this->assertArrayNotHasKey('reasoningEffort', $optionsOf->invoke($this->codexProvider(), $dial));

        $unknown = new CompleteRequest(model: 'probe-model', messages: [], reasoningEffort: 'turbo');
        $this->assertArrayNotHasKey('reasoningEffort', $optionsOf->invoke($this->codexProvider(), $unknown));

        $unset = new CompleteRequest(model: 'probe-model', messages: []);
        $this->assertArrayNotHasKey('reasoningEffort', $optionsOf->invoke($this->codexProvider(), $unset));
    }

    public function testWithModelRebindsAndKeepsTheRestOfTheState(): void
    {
        $provider = $this->codexProvider('first', 'read-only');
        $rebound = $provider->withModel('second');

        $this->assertNotSame($provider, $rebound);
        $this->assertSame('read-only', $rebound->sandbox(), 'the sandbox choice rides the rebind');
    }

    // =========================================================================
    // complete(): aggregation of the probed framing
    // =========================================================================

    public function testCompleteKeepsTheLastAgentMessageAndTheTerminalUsage(): void
    {
        $response = $this->codexProvider()->complete($this->request([new UserMessage('hi')]));

        $this->assertFalse($response->isError);
        $this->assertSame('Done.', $response->content, 'narrating items are superseded; the LAST agent_message is the reply');
        $this->assertSame(112, $response->tokensUsed);
        $this->assertNotNull($response->usage);
        $this->assertSame(100, $response->usage->inputTokens);
        $this->assertSame(12, $response->usage->outputTokens);
        $this->assertSame(40, $response->usage->cacheReadTokens);
        $this->assertSame(5, $response->usage->cacheCreationTokens);
        $this->assertSame(4, $response->usage->reasoningTokens);
        $this->assertFalse($response->truncated, 'codex turn.completed carries no stop field: never guessed (E707 doctrine)');
    }

    public function testCompleteTurnsFailureIntoAnErrorResponse(): void
    {
        putenv('FAKE_CODEX_MODE=fail');

        // execute() throws on the non-zero exit before aggregation; the
        // typed exception carries the reason from the terminal turn.failed.
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('log in required');

        $this->codexProvider()->complete($this->request([new UserMessage('hi')]));
    }

    public function testCompleteWithoutAnyJsonFallsBackToRawContent(): void
    {
        putenv('FAKE_CODEX_MODE=garbage');

        $response = $this->codexProvider()->complete($this->request([new UserMessage('hi')]));

        $this->assertStringContainsString('not json at all', $response->content);
        $this->assertSame(0, $response->tokensUsed);
    }

    public function testThePromptTravelsOnStdinAndNeverInArgv(): void
    {
        $this->codexProvider()->complete($this->request([new UserMessage('the-marker-prompt')]));

        $this->assertStringContainsString('the-marker-prompt', (string) file_get_contents($this->dir . '/stdin.txt'));
        $argv = json_decode((string) file_get_contents($this->dir . '/argv.json'), true);
        foreach ((array) $argv as $arg) {
            $this->assertStringNotContainsString('the-marker-prompt', (string) $arg);
        }
    }

    public function testTheSystemPromptRidesTheStdinTranscript(): void
    {
        putenv('FAKE_CODEX_MODE=sizes');
        $system = 'You are terse.';

        $response = $this->codexProvider()->complete($this->request([new UserMessage('hi')], $system));

        $stdin = (string) file_get_contents($this->dir . '/stdin.txt');
        $this->assertStringStartsWith('System: ' . $system, $stdin);
        $this->assertSame('prompt=' . strlen($stdin), $response->content);
    }

    // =========================================================================
    // completeStream(): yields only reply and terminal total
    // =========================================================================

    public function testTheStreamYieldsTheReplyThenTheTerminalUsageOnce(): void
    {
        $chunks = iterator_to_array(
            $this->codexProvider()->completeStream($this->request([new UserMessage('hi')])),
        );

        $this->assertSame('Looking now.Done.', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));

        $terminals = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed > 0));
        $this->assertCount(1, $terminals, 'lifecycle chatter yields nothing; usage rides the terminal chunk exactly once');
        $this->assertSame(112, $terminals[0]->tokensUsed);
        $this->assertNotNull($terminals[0]->usage);
        $this->assertSame(4, $terminals[0]->usage->reasoningTokens);
    }

    public function testAnAbandonedStreamDoesNotOrphanTheChild(): void
    {
        putenv('FAKE_CODEX_MODE=slow');

        $generator = $this->codexProvider()->completeStream($this->request([new UserMessage('hi')]));
        foreach ($generator as $chunk) {
            $this->assertSame('first', $chunk->content);
            break;
        }
        unset($generator);

        $pid = (int) @file_get_contents($this->dir . '/pid.txt');
        $this->assertGreaterThan(0, $pid);

        // The generator's finally must have TERM/KILLed and reaped: poll for
        // the pid to be gone (fail loud and fast, never spin past the alarm).
        $deadline = microtime(true) + 10.0;
        while (microtime(true) < $deadline) {
            if (!file_exists("/proc/$pid")) {
                return;
            }
            usleep(20000);
        }

        $this->fail("codex child $pid outlived the abandoned generator");
    }

    public function testAChildFloodingStderrDoesNotDeadlockTheDrain(): void
    {
        // 100 KiB of stderr beside the JSONL: a parent that read only stdout
        // would hang once the 64 KiB pipe filled (the measured claude-code
        // lesson, mirrored). The fake's own alarm is the hang net.
        $script = $this->dir . '/codex';
        $body = (string) file_get_contents($script);
        file_put_contents($script, str_replace(
            '$frame = static function',
            'fwrite(STDERR, str_repeat("e", 100000));' . "\n" . '$frame = static function',
            $body,
        ));
        chmod($script, 0o755);

        $response = $this->codexProvider()->complete($this->request([new UserMessage('hi')]));

        $this->assertSame('Done.', $response->content);
    }

    public function testABinaryThatCannotSpawnRaisesTheTypedFailure(): void
    {
        // Absolute unlaunchable path: with the containment wrapper the E672
        // pre-check throws; without one, proc_open() warns and returns false
        // into the !is_resource branch. Same message either way (the
        // capture-outside-try idiom of ClaudeCodeInvocationThrowTest).
        $provider = new CodexProvider(new CodexInvocation(codexPath: $this->dir . '/no-such-codex-binary'));

        $caught = null;
        set_error_handler(static fn (): bool => true);

        try {
            $provider->complete($this->request([new UserMessage('hi')]));
        } catch (\RuntimeException $e) {
            $caught = $e;
        } finally {
            restore_error_handler();
        }

        $this->assertNotNull($caught, 'an unlaunchable codexPath must throw');
        $this->assertInstanceOf(ProviderException::class, $caught);
        $this->assertSame('Failed to start Codex process', $caught->getMessage());
        $this->assertNull($caught->exitCode, 'null exitCode means the child never spawned');
    }
}
