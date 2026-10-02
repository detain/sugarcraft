<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\ClaudeCodeInvocation;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\ProviderException;

/**
 * Audit 15a A12: the `claude-code` streaming path — the only one Runtime uses,
 * since `supportsStreaming()` is true — failed every turn, three ways.
 *
 * 1. `-p --output-format stream-json` without `--verbose` is refused by the CLI
 *    (measured, 2.1.287: "When using --print, --output-format=stream-json
 *    requires --verbose", exit 1).
 * 2. stream-json is NDJSON, one bare object per line, and token deltas need
 *    `--include-partial-messages`; the parser accepted only SSE `data: ` lines,
 *    so even a working spawn streamed an empty reply.
 * 3. The transcript and the system prompt were argv strings, and Linux caps one
 *    at 128 KiB (`MAX_ARG_STRLEN`), so `exec` failed with E2BIG once the
 *    history grew past it.
 *
 * Every test here runs against a FAKE `claude` placed first on PATH — never the
 * real CLI, which would spend credentials. The fake enforces the real CLI's
 * `--verbose` rule, reads its prompt from stdin the way `claude -p` does, and
 * prints the measured stream-json line shapes. It arms `pcntl_alarm()` so a
 * pipe deadlock fails the test instead of hanging the suite.
 */
final class ClaudeCodeStreamJsonTest extends TestCase
{
    private string $dir = '';

    private string|false $savedPath = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/sc_cc_ndjson_' . getmypid() . '_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o700, true);

        $this->savedPath = getenv('PATH');
        putenv('PATH=' . $this->dir . ($this->savedPath !== false ? ':' . $this->savedPath : ''));
        putenv('FAKE_CLAUDE_LOG=' . $this->dir);
        putenv('FAKE_CLAUDE_MODE');

        $script = $this->dir . '/claude';
        file_put_contents($script, '#!' . PHP_BINARY . "\n" . self::FAKE_CLAUDE);
        chmod($script, 0o755);
    }

    protected function tearDown(): void
    {
        putenv($this->savedPath === false ? 'PATH' : 'PATH=' . $this->savedPath);
        putenv('FAKE_CLAUDE_LOG');
        putenv('FAKE_CLAUDE_MODE');

        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);

        parent::tearDown();
    }

    public function testVerboseAndPartialMessagesRideExactlyTheStreamJsonFormat(): void
    {
        $invocation = new ClaudeCodeInvocation();

        $stream = $invocation->printModeArgs(['format' => 'stream-json']);
        $this->assertContains('--verbose', $stream);
        $this->assertContains('--include-partial-messages', $stream);

        foreach (['json', 'text'] as $format) {
            $args = $invocation->printModeArgs(['format' => $format]);
            $this->assertNotContains('--verbose', $args, "{$format} must not carry --verbose");
            $this->assertNotContains('--include-partial-messages', $args, "{$format} must not carry partial messages");
        }
    }

    public function testTheSpawnedArgvNamesTheOutputFormatExactlyOnce(): void
    {
        $invocation = new ClaudeCodeInvocation(sessionId: 'sess-1');
        $argv = array_merge($invocation->baseArgs(), $invocation->printModeArgs(['format' => 'stream-json']));

        $this->assertSame(1, count(array_keys($argv, '--output-format', true)));
        $this->assertSame('stream-json', $argv[array_search('--output-format', $argv, true) + 1]);
    }

    public function testTheStreamYieldsTheNdjsonTextDeltasOnceEach(): void
    {
        $chunks = $this->stream();

        $this->assertSame('Hello', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
        $this->assertSame(
            ['Hel', 'lo'],
            array_values(array_filter(array_map(static fn (CompleteResponse $c): string => $c->content, $chunks), static fn (string $t): bool => $t !== '')),
            'each delta arrives as its own chunk, and the whole `assistant` message is not replayed on top of them',
        );

        $argv = $this->loggedArgv();
        $this->assertContains('--verbose', $argv);
        $this->assertContains('--include-partial-messages', $argv);
    }

    public function testThePromptTravelsOnStdinAndNeverInArgv(): void
    {
        $this->stream('the-marker-prompt');

        $this->assertSame('User: the-marker-prompt', (string) file_get_contents($this->dir . '/stdin.txt'));
        foreach ($this->loggedArgv() as $arg) {
            $this->assertStringNotContainsString('the-marker-prompt', $arg);
        }
    }

    public function testAPromptFarAboveTheArgvStringCeilingStreams(): void
    {
        putenv('FAKE_CLAUDE_MODE=sizes');
        $prompt = str_repeat('x', 3 * 131072);

        $chunks = $this->stream($prompt);

        $this->assertSame(
            'prompt=' . strlen('User: ' . $prompt) . ';system=6',
            implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)),
        );
    }

    public function testAChildFloodingStdoutBeforeReadingStdinDoesNotDeadlock(): void
    {
        // Several pipe buffers each way: a parent that wrote the whole prompt
        // before reading anything would block on stdin while the child blocks
        // on stdout, and the fixture's alarm would kill it.
        putenv('FAKE_CLAUDE_MODE=flood');
        $prompt = str_repeat('y', 512 * 1024);

        $started = microtime(true);
        $chunks = $this->stream($prompt);

        $this->assertLessThan(10.0, microtime(true) - $started);
        $this->assertSame(
            'prompt=' . strlen('User: ' . $prompt) . ';system=6',
            implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)),
        );
    }

    public function testAnOversizedSystemPromptIsSpilledToAPrivateFileThatIsRemovedAfterTheRun(): void
    {
        putenv('FAKE_CLAUDE_MODE=sizes');
        $system = str_repeat('s', 200 * 1024);

        $this->stream('hi', $system);

        $argv = $this->loggedArgv();
        $this->assertNotContains('--system-prompt', $argv, 'the oversized prompt must not be an argv string');
        $flag = array_search('--system-prompt-file', $argv, true);
        $this->assertNotFalse($flag);
        $file = $argv[$flag + 1];

        $this->assertSame($system, (string) file_get_contents($this->dir . '/system.txt'));
        $this->assertSame('0600', (string) file_get_contents($this->dir . '/system-perms.txt'));
        $this->assertFileDoesNotExist($file, 'the spill file must be removed once the child has exited');
    }

    public function testASmallSystemPromptStaysInline(): void
    {
        $this->stream('hi', 'Be brief.');

        $argv = $this->loggedArgv();
        $this->assertNotContains('--system-prompt-file', $argv);
        $this->assertSame('Be brief.', $argv[array_search('--system-prompt', $argv, true) + 1]);
    }

    public function testTheResultLineReportsTokensAndCost(): void
    {
        $chunks = $this->stream();
        $last = end($chunks);

        $this->assertInstanceOf(CompleteResponse::class, $last);
        $this->assertSame(20, $last->tokensUsed);
        $this->assertSame(0.0123, $last->costUsd);
        $this->assertFalse($last->isError);
    }

    /**
     * Audit A25: the real CLI's `result` line carries only the Anthropic
     * buckets, no `total_tokens`, and every turn used to report 0 tokens.
     * The total is now all four buckets (cache sides included — Claude Code
     * caches most of the prompt), carried with the split, on BOTH paths.
     */
    public function testARealCliUsageDocumentWithOnlyBucketsReportsItsTokens(): void
    {
        putenv('FAKE_CLAUDE_MODE=buckets');

        $chunks = $this->stream();
        $streamed = end($chunks);
        $batch = $this->provider()->complete($this->request('hi'));

        foreach (['stream' => $streamed, 'batch' => $batch] as $path => $response) {
            $this->assertInstanceOf(CompleteResponse::class, $response);
            $this->assertSame(135, $response->tokensUsed, "{$path}: input + output + both cache sides");
            $this->assertSame(0.0123, $response->costUsd, $path);
            $this->assertNotNull($response->usage, "{$path}: the split must be carried, not only projected");
            $this->assertSame(135, $response->usage->totalTokens, "{$path}: carrier total must equal the projection");
            $this->assertSame(10, $response->usage->inputTokens, $path);
            $this->assertSame(5, $response->usage->outputTokens, $path);
            $this->assertSame(100, $response->usage->cacheReadTokens, $path);
            $this->assertSame(20, $response->usage->cacheCreationTokens, $path);
            $this->assertSame(130, $response->promptTokens(), "{$path}: cacheRead + cacheCreation + input");
        }
    }

    public function testThinkingDeltasAreReasoningNotContent(): void
    {
        putenv('FAKE_CLAUDE_MODE=thinking');

        $chunks = $this->stream();

        $this->assertSame('pondering', implode('', array_map(static fn (CompleteResponse $c): string => (string) $c->reasoning, $chunks)));
        $this->assertSame('ok', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
    }

    public function testAnErrorResultWithEmptyStderrNamesTheErrorInTheException(): void
    {
        putenv('FAKE_CLAUDE_MODE=error');

        $caught = null;
        $chunks = [];
        try {
            foreach ($this->provider()->completeStream($this->request('hi')) as $chunk) {
                $chunks[] = $chunk;
            }
        } catch (ProviderException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame(1, $caught->exitCode);
        $this->assertStringContainsString('API Error: Connection refused', $caught->getMessage());

        $errors = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->isError));
        $this->assertCount(1, $errors);
        $this->assertSame('API Error: Connection refused', $errors[0]->errorMessage);
    }

    public function testAFinalLineWithoutANewlineIsStillParsed(): void
    {
        putenv('FAKE_CLAUDE_MODE=noeol');

        $chunks = $this->stream();

        $this->assertSame('tail', implode('', array_map(static fn (CompleteResponse $c): string => $c->content, $chunks)));
    }

    public function testTheBatchPathAlsoSendsThePromptOnStdin(): void
    {
        $prompt = str_repeat('z', 3 * 131072);

        $response = $this->provider()->complete($this->request($prompt));

        $this->assertSame('Hello', $response->content);
        $this->assertSame(20, $response->tokensUsed);
        $this->assertSame('User: ' . $prompt, (string) file_get_contents($this->dir . '/stdin.txt'));
        $argv = $this->loggedArgv();
        $this->assertSame('json', $argv[array_search('--output-format', $argv, true) + 1]);
        $this->assertNotContains('--verbose', $argv);
    }

    public function testTheBatchPathNamesAnErrorResultWhenStderrIsEmpty(): void
    {
        putenv('FAKE_CLAUDE_MODE=error');

        $caught = null;
        try {
            $this->provider()->complete($this->request('hi'));
        } catch (ProviderException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught);
        $this->assertSame('Claude Code exited with code 1: API Error: Connection refused', $caught->getMessage());
    }

    /** @return list<CompleteResponse> */
    private function stream(string $prompt = 'hi', ?string $systemPrompt = 'system'): array
    {
        return iterator_to_array($this->provider()->completeStream($this->request($prompt, $systemPrompt)), false);
    }

    private function provider(): ClaudeCodeProvider
    {
        // A BARE name, resolved through PATH exactly as the default
        // `claude` is — the fake shadows any real CLI for this test only.
        return new ClaudeCodeProvider(new ClaudeCodeInvocation(claudePath: 'claude', configDir: $this->dir));
    }

    private function request(string $prompt, ?string $systemPrompt = 'system'): CompleteRequest
    {
        return new CompleteRequest(
            model: 'claude-sonnet-4-6',
            messages: [new UserMessage($prompt)],
            systemPrompt: $systemPrompt,
        );
    }

    /** @return list<string> */
    private function loggedArgv(): array
    {
        $argv = json_decode((string) file_get_contents($this->dir . '/argv.json'), true);
        $this->assertIsArray($argv, 'the fake claude never ran');

        return $argv;
    }

    /**
     * The fake CLI. Line shapes are the measured CLI 2.1.287 ones, trimmed to
     * the fields the provider reads.
     */
    private const FAKE_CLAUDE = <<<'PHP'
        <?php
        pcntl_alarm(15);
        $log = (string) getenv('FAKE_CLAUDE_LOG');
        $mode = (string) getenv('FAKE_CLAUDE_MODE');
        $argv = array_slice($_SERVER['argv'], 1);
        file_put_contents("$log/argv.json", json_encode($argv));
        $opt = static function (string $flag) use ($argv): ?string {
            $i = array_search($flag, $argv, true);
            return $i === false ? null : ($argv[$i + 1] ?? null);
        };
        $has = static fn (string $flag): bool => in_array($flag, $argv, true);
        $format = $opt('--output-format') ?? 'text';
        if (!$has('-p')) {
            fwrite(STDERR, "fake claude: -p required\n");
            exit(2);
        }
        if ($format === 'stream-json' && !$has('--verbose')) {
            fwrite(STDERR, "Error: When using --print, --output-format=stream-json requires --verbose\n");
            exit(1);
        }
        $out = static function (array $line): void {
            fwrite(STDOUT, json_encode($line) . "\n");
        };
        if ($mode === 'flood') {
            for ($i = 0; $i < 3000; $i++) {
                $out(['type' => 'system', 'subtype' => 'status', 'pad' => str_repeat('p', 100)]);
            }
        }
        $prompt = stream_get_contents(STDIN);
        file_put_contents("$log/stdin.txt", $prompt);
        $system = $opt('--system-prompt');
        if (($file = $opt('--system-prompt-file')) !== null) {
            $system = file_get_contents($file);
            file_put_contents("$log/system-perms.txt", sprintf('%04o', fileperms($file) & 0777));
        }
        file_put_contents("$log/system.txt", (string) $system);
        $result = ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => 'Hello',
            'total_cost_usd' => 0.0123, 'stop_reason' => 'end_turn',
            // An explicit `total_tokens` still wins; CLI 2.1.287 itself prints
            // only the buckets (the 'buckets' mode below, audit A25).
            'usage' => ['total_tokens' => 20]];
        if ($mode === 'buckets') {
            // The real CLI's usage document (2.1.287): Anthropic buckets, no
            // `total_tokens` - audit A25.
            $result['usage'] = ['input_tokens' => 10, 'output_tokens' => 5,
                'cache_read_input_tokens' => 100, 'cache_creation_input_tokens' => 20];
        }
        if ($mode === 'error') {
            $result = array_merge($result, ['is_error' => true, 'result' => 'API Error: Connection refused', 'total_cost_usd' => 0]);
        }
        if ($format === 'json') {
            fwrite(STDOUT, json_encode($result));
            exit($mode === 'error' ? 1 : 0);
        }
        $delta = static function (array $delta) use ($out): void {
            $out(['type' => 'stream_event', 'event' => ['type' => 'content_block_delta', 'index' => 0, 'delta' => $delta]]);
        };
        $out(['type' => 'system', 'subtype' => 'init']);
        $texts = match ($mode) {
            'sizes', 'flood' => ['prompt=' . strlen($prompt), ';system=' . strlen((string) $system)],
            'thinking' => ['ok'],
            'error', 'noeol' => [],
            default => ['Hel', 'lo'],
        };
        if ($has('--include-partial-messages')) {
            if ($mode === 'thinking') {
                $delta(['type' => 'thinking_delta', 'thinking' => 'ponder']);
                $delta(['type' => 'thinking_delta', 'thinking' => 'ing']);
            }
            foreach ($texts as $text) {
                $delta(['type' => 'text_delta', 'text' => $text]);
            }
            $out(['type' => 'stream_event', 'event' => ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn']]]);
        }
        $out(['type' => 'assistant', 'message' => ['role' => 'assistant', 'content' => [['type' => 'text', 'text' => implode('', $texts)]]]]);
        if ($mode === 'noeol') {
            fwrite(STDOUT, json_encode(['type' => 'stream_event', 'event' => ['delta' => ['type' => 'text_delta', 'text' => 'tail']]]));
            exit(0);
        }
        $out($result);
        exit($mode === 'error' ? 1 : 0);
        PHP;
}
