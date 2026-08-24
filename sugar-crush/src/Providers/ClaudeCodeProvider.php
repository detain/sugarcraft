<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Crush\Tools\ToolCall;

final readonly class ClaudeCodeProvider implements ProviderInterface
{
    /**
     * How much of a failing child's stderr is kept for the exception message.
     * 64 KiB is one pipe buffer on this host, which is the natural unit: it is
     * what a child can write without any reader at all, so anything under it was
     * never at risk of being lost to the deadlock this bound's own loop closes.
     */
    private const MAX_STDERR_BYTES = 65536;

    public function __construct(
        private ClaudeCodeInvocation $invocation,
        private string $defaultModel = 'claude-sonnet-4-6',
    ) {}

    public function name(): string
    {
        return 'claude-code';
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return true;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return true;
    }

    public function contextWindow(): int
    {
        return match ($this->defaultModel) {
            'claude-sonnet-4-6' => 200_000,
            'claude-opus-4-6' => 200_000,
            'claude-sonnet-4-7' => 200_000,
            'claude-opus-4-7' => 200_000,
            'claude-haiku-4-7' => 200_000,
            default => 200_000,
        };
    }

    public function costPer1kTokens(string $model, string $direction): float
    {
        // Claude Code handles its own billing
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $prompt = $this->buildPrompt($request->messages);

        $options = [
            'format' => 'json',
            'bare' => true,
            'systemPrompt' => $request->systemPrompt,
        ];

        if ($request->tools !== null) {
            $toolNames = array_map(fn($t) => $t->name(), $request->tools);
            $options['allowedTools'] = implode(',', $toolNames);
        }

        $output = $this->invocation->execute(
            $this->invocation->printModeArgs($prompt, $options)
        );

        return $this->parseJsonResponse($output);
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $prompt = $this->buildPrompt($request->messages);

        $options = [
            'format' => 'stream-json',
            'bare' => true,
            'systemPrompt' => $request->systemPrompt,
        ];

        if ($request->tools !== null) {
            $toolNames = array_map(fn($t) => $t->name(), $request->tools);
            $options['allowedTools'] = implode(',', $toolNames);
        }

        $args = $this->invocation->printModeArgs($prompt, $options);

        // Open process directly - cannot use yield inside a closure passed to execute()
        $cmd = array_merge([$this->invocation->claudePath()], $this->invocation->baseArgs(), $args);

        $process = proc_open(
            $cmd,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            [
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
                'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
                'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
            ]
        );

        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start Claude Code process');
        }

        fclose($pipes[0]);

        // NON-BLOCKING ON BOTH PIPES, so neither can wedge the other. See the
        // `try` body for the deadlock this closes.
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $buffer = '';
        $errors = '';
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        try {
            // BOTH PIPES ARE DRAINED IN THE SAME LOOP. This loop used to read
            // stdout only, and stderr was read once — after `proc_close()` — so
            // a child that wrote more than one pipe buffer to stderr blocked in
            // its own `write()`, never closed stdout, and this loop never
            // reached EOF. MEASURED on this host (PHP 8.3.6, Linux 6.8, 64 KiB
            // pipe buffer) with a child that writes N bytes to stderr and then a
            // line to stdout: N = 1000 and N = 60000 both drain in 0.04s,
            // N = 100000 never completes. A `claude` invocation that fails
            // noisily — a stack trace, a node warning storm — is exactly the
            // case that produces six figures of stderr, so the hang was on the
            // failure path and only on the failure path.
            //
            // NO WALL-CLOCK CAP, deliberately: a completion is allowed to take
            // as long as it takes, and a blanket total-request timeout on an LLM
            // call abandons answers the user is paying for. The bound here is
            // liveness of the pipes, not duration.
            while ($open !== []) {
                $read = array_values($open);
                $write = [];
                $except = [];

                // `@`, because a signal arriving mid-select (a SIGCHLD, a
                // suite's `pcntl_alarm()`) makes `stream_select()` return false
                // with an `Interrupted system call` warning. An EINTR is a retry,
                // not an error — and under `failOnWarning="true"` the warning
                // alone would red a passing run.
                $ready = @stream_select($read, $write, $except, 1, 0);

                if ($ready === false) {
                    // EINTR, or a stream that has genuinely gone away. Drop
                    // whatever is at EOF so this cannot spin forever on a pipe
                    // `select()` will never report again, then yield the CPU.
                    foreach ($open as $fd => $pipe) {
                        if (feof($pipe)) {
                            unset($open[$fd]);
                        }
                    }
                    usleep(1000);

                    continue;
                }

                if ($ready === 0) {
                    continue;
                }

                foreach ($open as $fd => $pipe) {
                    if (!in_array($pipe, $read, true)) {
                        continue;
                    }

                    $chunk = fread($pipe, 8192);
                    if ($chunk === false || ($chunk === '' && feof($pipe))) {
                        unset($open[$fd]);

                        continue;
                    }
                    if ($chunk === '') {
                        // Readable, nothing there, not EOF: a spurious wakeup.
                        continue;
                    }

                    if ($fd === 2) {
                        $errors = self::clipStderr($errors . $chunk);

                        continue;
                    }

                    $buffer .= $chunk;

                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);

                        if (str_starts_with($line, 'data: ')) {
                            $data = json_decode(substr($line, 6), true);
                            if ($data !== null) {
                                yield $this->parseChunk($data);
                            }
                        }
                    }
                }
            }
        } finally {
            // A `finally` IN A GENERATOR, and it is load-bearing. A consumer that
            // `break`s out of `foreach ($provider->completeStream(...) as ...)`
            // destroys this generator mid-body, and PHP runs this block when it
            // does. Without it the `proc_open()` handle is simply dropped — and
            // MEASURED on this host, dropping a handle whose child is still
            // RUNNING takes 0.000s and leaves the child in state `S`. The
            // resource destructor reaps an already-exited child but never waits
            // for a live one, so an abandoned stream left a `claude` process
            // running under pid 1, holding every descriptor above 2 this process
            // had open when it spawned (E366). `terminateAndClose()` sends no
            // signal at all to a child that has already exited, so the normal
            // completion below pays nothing for this.
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $exitCode = ProcessReaper::terminateAndClose($process);
        }

        if ($exitCode !== 0 && $exitCode !== -1 && $exitCode !== null) {
            // STDERR IS READ ABOVE, NOT HERE, and that ordering is the fix.
            // This site used to `fclose($pipes[2])` and then call
            // `stream_get_contents($pipes[2])` on the next reachable line. That
            // is not an empty diagnostic — MEASURED on PHP 8.3.6, it raises
            // `TypeError: stream_get_contents(): supplied resource is not a
            // valid stream resource`, and `@` does not suppress a TypeError. So
            // the RuntimeException below was never CONSTRUCTED on any non-zero
            // exit: callers catching `\RuntimeException` caught nothing, and the
            // one path where the child's stderr is the only diagnostic there is
            // reported a type error about a stream instead.
            throw new \RuntimeException("Claude Code exited with code $exitCode: $errors");
        }
    }

    /**
     * Keep at most {@see MAX_STDERR_BYTES} of a child's stderr, THE TAIL.
     *
     * A cap because the buffer grows inside an unbounded read loop and a child
     * in a warning loop would otherwise be an unbounded allocation. The TAIL
     * rather than the head because this text exists to answer "why did it exit",
     * and the reason a process gives is the last thing it says — a truncated
     * head would reliably keep the banner and drop the error.
     */
    private static function clipStderr(string $errors): string
    {
        if (strlen($errors) <= self::MAX_STDERR_BYTES) {
            return $errors;
        }

        return '[stderr truncated]' . substr($errors, -self::MAX_STDERR_BYTES);
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // Claude Code doesn't directly support embeddings
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * Build a prompt string from messages.
     *
     * @param array<Message> $messages
     */
    private function buildPrompt(array $messages): string
    {
        $parts = [];

        foreach ($messages as $msg) {
            $parts[] = match (true) {
                $msg instanceof UserMessage => "User: {$msg->content()}",
                $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
                $msg instanceof SystemMessage => "System: {$msg->content()}",
                $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
                default => "User: {$msg->content()}",
            };
        }

        return implode("\n\n", $parts);
    }

    /**
     * Parse a JSON response from Claude Code.
     */
    private function parseJsonResponse(string $output): CompleteResponse
    {
        $data = json_decode($output, true);

        if ($data === null) {
            // On parse failure, return the raw output as content with error indicator
            return new CompleteResponse(
                content: $output,
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        // Check for error in response
        if (isset($data['error'])) {
            $errorMsg = is_string($data['error']) ? $data['error'] : ($data['error']['message'] ?? 'Unknown error');
            return new CompleteResponse(
                content: "[Error: $errorMsg]",
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        return new CompleteResponse(
            content: $data['result'] ?? $data['content'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: $this->parseToolCalls($data['tool_calls'] ?? []),
            tokensUsed: $data['usage']['total_tokens'] ?? 0,
            costUsd: $data['total_cost_usd'] ?? 0.0,
        );
    }

    /**
     * Parse a streaming chunk into a partial CompleteResponse.
     */
    private function parseChunk(array $data): CompleteResponse
    {
        if (isset($data['event']['delta']['type']) && $data['event']['delta']['type'] === 'text_delta') {
            return new CompleteResponse(
                content: $data['event']['delta']['text'] ?? '',
                reasoning: null,
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        return new CompleteResponse(
            content: '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * Parse tool calls from Claude Code response.
     *
     * @param array<array<string, mixed>> $toolCalls
     * @return array<ToolCall>|null
     */
    private function parseToolCalls(array $toolCalls): ?array
    {
        if (empty($toolCalls)) {
            return null;
        }

        return array_map(function ($tc) {
            return ToolCall::fromArray([
                // A fallback for a payload that omitted the id. It never reaches
                // a filename ({@see \SugarCraft\Crush\Support\ToolIpcFiles::reserve()}
                // names those), so the exposure is a duplicate id within one
                // response — but it DOES go on the wire, echoed back to the
                // provider as `tool_call_id`, and that is what decides the
                // alphabet.
                //
                // WHAT E329's FIX SAID, and why it is rewritten rather than
                // dropped: this site spelled `uniqid('tool_…_')`, a literal
                // prefix followed by the same microtime suffix the bare call
                // returns, so the prefix contributed ZERO cross-process entropy
                // and the site belonged to the family E329 swept. That reasoning
                // is still right and is why the bare form is not coming back.
                // WHAT IS TRUE NOW (E352): the sweep's replacement was
                // `uniqid(…, true)`, whose more-entropy flag appends a PERIOD and
                // eight more hex digits — putting a `.` into a protocol field
                // whose character set nobody had weighed. A downstream consumer
                // that splits on `.` would fail on the fallback path only, which
                // is the hardest kind of bug to reproduce. WHY THIS STILL EARNS
                // ITS PLACE: the requirement was never "call uniqid", it was
                // "cross-process entropy", and `bin2hex(random_bytes(8))` — the
                // shape `ToolIpcFiles::reserve()` already uses — gives 64 bits of
                // it from an alphabet chosen for a wire format, which is strictly
                // more than the microtime-plus-LCG form it replaces.
                'id' => $tc['id'] ?? 'tool_' . getmypid() . '_' . bin2hex(random_bytes(8)),
                'name' => $tc['name'] ?? $tc['function']['name'] ?? '',
                'arguments' => is_string($tc['arguments'] ?? null)
                    ? json_decode($tc['arguments'], true) ?? []
                    : ($tc['arguments'] ?? []),
            ]);
        }, $toolCalls);
    }
}
