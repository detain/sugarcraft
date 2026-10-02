<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Providers;

use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Crush\Tools\ToolCall;
use SugarCraft\Crush\Usage;

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
        $spill = $this->invocation->spillSystemPrompt($request->systemPrompt);

        try {
            // The prompt is the stdin payload, never an argv string - see
            // ClaudeCodeInvocation's class docblock (audit 15a A12).
            $output = $this->invocation->execute(
                $this->invocation->printModeArgs($this->options('json', $request, $spill)),
                stdin: $prompt,
            );
        } finally {
            if ($spill !== null) {
                @unlink($spill);
            }
        }

        return $this->parseJsonResponse($output);
    }

    /**
     * The printModeArgs() options both paths share.
     *
     * @return array<string, mixed>
     */
    private function options(string $format, CompleteRequest $request, ?string $systemPromptFile): array
    {
        $options = [
            'format' => $format,
            'bare' => true,
            'systemPrompt' => $request->systemPrompt,
            'systemPromptFile' => $systemPromptFile,
        ];

        if ($request->tools !== null) {
            $toolNames = array_map(fn($t) => $t->name(), $request->tools);
            $options['allowedTools'] = implode(',', $toolNames);
        }

        return $options;
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $prompt = $this->buildPrompt($request->messages);

        // E672: the wrapper-fronts-spawn pre-check, twin of
        // ClaudeCodeInvocation::execute() — under `setsid` a bogus claudePath
        // starts the WRAPPER fine and the exec failure would surface only as
        // exit 127 inside the stream, not as this site's typed throw. Ahead of
        // the spill below, so a failed start leaves no temp file behind.
        $claudeBinary = $this->invocation->claudePath();
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($claudeBinary, '/')
                ? is_executable($claudeBinary)
                : ProcessContainment::locateOnPath($claudeBinary) !== '')
        ) {
            // E27(a): typed throw, exit code null = the child never spawned.
            throw new ProviderException('Failed to start Claude Code process');
        }

        // Audit 15a A12: `stream-json` with `--verbose` and
        // `--include-partial-messages` (printModeArgs() adds both), the prompt
        // on stdin, and an oversized system prompt spilled to a file - each
        // the fix for one of the three ways this path failed every turn.
        $spill = $this->invocation->spillSystemPrompt($request->systemPrompt);
        $args = $this->invocation->printModeArgs($this->options('stream-json', $request, $spill));

        // Open process directly - cannot use yield inside a closure passed to execute()
        $cmd = array_merge([$this->invocation->claudePath()], $this->invocation->baseArgs(), $args);

        // E672/E674: choke-point spec + env; the three auth keys ride as
        // overrides (see ClaudeCodeInvocation for the same routing).
        $process = proc_open(
            ProcessContainment::spawnSpec($cmd),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env([
                'ANTHROPIC_API_KEY' => getenv('ANTHROPIC_API_KEY') ?: '',
                'ANTHROPIC_AUTH_TOKEN' => getenv('ANTHROPIC_AUTH_TOKEN') ?: '',
                'ANTHROPIC_BASE_URL' => getenv('ANTHROPIC_BASE_URL') ?: '',
            ])
        );

        if (!is_resource($process)) {
            if ($spill !== null) {
                @unlink($spill);
            }

            // E27(a): typed throw, exit code null = the child never spawned.
            throw new ProviderException('Failed to start Claude Code process');
        }

        // NON-BLOCKING ON ALL THREE PIPES, so none can wedge another. See the
        // `try` body for the deadlock this closes. Stdin joins them because
        // the prompt now rides it and can be far larger than one pipe buffer:
        // a blocking write of all of it would stall while the child fills its
        // stdout, which this side would not yet be reading.
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $pending = ClaudeCodeInvocation::feedStdin($pipes[0], $prompt);
        $buffer = '';
        $errors = '';
        $resultError = null;
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
                $write = $pending !== '' ? [$pipes[0]] : [];
                $except = [];

                // `@`, because a signal arriving mid-select (a SIGCHLD, a
                // suite's `pcntl_alarm()`) makes `stream_select()` return false
                // with an `Interrupted system call` warning. An EINTR is a retry,
                // not an error — and under `failOnWarning="true"` the warning
                // alone would red a passing run.
                $ready = @stream_select($read, $write, $except, 1, 0);

                if ($ready === false) {
                    // EINTR, or a stream that has genuinely gone away. Drop
                    // whatever is at EOF, then yield the CPU.
                    //
                    // ⚠️ THIS USED TO SAY "so this cannot spin forever on a
                    // pipe `select()` will never report again", flat. That
                    // overstates what the loop below can promise, and the
                    // assumption it hides is worth naming. WHAT IS TRUE NOW:
                    // the exit depends on a pipe that will never be selectable
                    // again eventually answering `feof() === true`. A live pipe
                    // at EOF does. A pipe whose RESOURCE has been closed does
                    // not answer at all — MEASURED on this host, PHP 8.3.6,
                    // `feof()` on an fclose'd `proc_open()` pipe raises
                    // `TypeError: feof(): supplied resource is not a valid
                    // stream resource`, so it would leave this loop by throwing
                    // rather than by spinning. Nothing closes these pipes while
                    // this loop owns them, so that path is unreachable today.
                    // WHY THE GUARD STILL EARNS ITS PLACE: without it a genuine
                    // EOF-plus-EINTR combination has no exit at all, which is a
                    // real hang rather than a hypothetical one.
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

                if ($write !== []) {
                    $pending = ClaudeCodeInvocation::feedStdin($pipes[0], $pending);
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

                    // NDJSON, ONE JSON OBJECT PER LINE (audit 15a A12). This
                    // used to accept only `data: `-prefixed lines, the SSE
                    // framing of the HTTP API underneath; the CLI's
                    // stream-json prints bare objects, so every line was
                    // dropped and each turn streamed an empty reply.
                    while (($pos = strpos($buffer, "\n")) !== false) {
                        $line = substr($buffer, 0, $pos);
                        $buffer = substr($buffer, $pos + 1);

                        $chunk = $this->parseLine($line);
                        if ($chunk !== null) {
                            $resultError = $chunk->isError ? $chunk->errorMessage : $resultError;

                            yield $chunk;
                        }
                    }
                }
            }

            // A final object the child wrote without a closing newline is
            // still a whole object: the pipe hit EOF, nothing more is coming.
            $chunk = $this->parseLine($buffer);
            if ($chunk !== null) {
                $resultError = $chunk->isError ? $chunk->errorMessage : $resultError;

                yield $chunk;
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

            $exitCode = ProcessReaper::terminateAndClose($process, ProcessContainment::groupId($process));

            // After the reap, never before: the child reads the file at
            // startup, but nothing says when that is.
            if ($spill !== null) {
                @unlink($spill);
            }
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
            //
            // E27(a): the throw is now the typed {@see ProviderException}
            // carrying the exit code as structure rather than prose, and stays
            // a `\RuntimeException` so every existing catch keeps its contract.
            // The per-code retry decision (a spawn failure is transient, a
            // non-zero exit usually is not) is deliberately NOT taken here -
            // it belongs to TransientFailure's allow-list, and the open half is
            // pinned as a recorded decision in TransientFailureTest.
            $reason = ClaudeCodeInvocation::exitReason($errors, $resultError);

            throw new ProviderException("Claude Code exited with code $exitCode: $reason", exitCode: $exitCode);
        }
    }

    /**
     * One stream-json line as a chunk, or null for a line that carries nothing
     * for this consumer.
     *
     * - `stream_event`: the relayed Anthropic event ({@see parseChunk()}),
     *   which is where token deltas arrive under `--include-partial-messages`.
     * - `result`: the run's final record - its cost and token figures, and
     *   `is_error` for a failed run (exit status aside, a run can end in an
     *   error result; it is surfaced as an error chunk, the way Runtime reads
     *   a provider-reported failure).
     * - everything else - `system` (init, status, retries), and the whole
     *   `assistant`/`user` messages - is skipped. The `assistant` message
     *   repeats text the partial deltas already delivered, so yielding it too
     *   would print every reply twice.
     */
    private function parseLine(string $line): ?CompleteResponse
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }

        return match ($data['type'] ?? null) {
            'stream_event' => $this->parseChunk($data),
            'result' => $this->parseResult($data),
            default => null,
        };
    }

    /**
     * The final `result` line: the run's totals, and its error when it failed.
     *
     * @param array<string, mixed> $data
     */
    private function parseResult(array $data): CompleteResponse
    {
        $isError = ($data['is_error'] ?? false) === true;
        $costUsd = (float) ($data['total_cost_usd'] ?? 0.0);
        $usage = self::parseUsage($data['usage'] ?? null, $costUsd);

        return new CompleteResponse(
            content: '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: $usage?->totalTokens ?? 0,
            costUsd: $costUsd,
            isError: $isError,
            errorMessage: ClaudeCodeInvocation::resultErrorOf($data),
            truncated: ($data['stop_reason'] ?? null) === 'max_tokens',
            usage: $usage,
        );
    }

    /**
     * The CLI's `usage` document as a {@see Usage}, buckets and all, or null
     * when it reported nothing measurable.
     *
     * AUDIT A25. WHAT THIS USED TO DO: read `usage.total_tokens` and nothing
     * else, documented as a known gap — the measured CLI (2.1.287) prints no
     * `total_tokens`, only the Anthropic buckets (`input_tokens`,
     * `output_tokens`, `cache_read_input_tokens`,
     * `cache_creation_input_tokens`), so every real turn reported 0 tokens and
     * the token tracker, `/cost`'s token figures and Chat's context
     * calibration saw nothing (the cost, from `total_cost_usd`, was right).
     *
     * THE TOTAL IS ALL FOUR BUCKETS, cache sides included. Claude Code caches
     * aggressively, so a real turn is typically a handful of `input_tokens`
     * beside tens of thousands of `cache_read_input_tokens`; `input + output`
     * alone would report a 20k-token conversation as a few dozen tokens. This
     * is {@see Usage::promptTokens()}' identity (`cacheRead + cacheCreation +
     * input`) plus the output. An explicit `total_tokens` — the shape older
     * builds and the tests' fake CLI print — still wins when present.
     *
     * The carrier's total and cost equal the `tokensUsed`/`costUsd`
     * projections, which is what {@see \SugarCraft\Crush\Runtime}'s fold
     * requires of every carrier.
     */
    private static function parseUsage(mixed $usage, float $costUsd): ?Usage
    {
        if (!is_array($usage)) {
            return Usage::reported(0, $costUsd);
        }

        $input = self::usageInt($usage['input_tokens'] ?? null);
        $output = self::usageInt($usage['output_tokens'] ?? null);
        $cacheRead = self::usageInt($usage['cache_read_input_tokens'] ?? null);
        $cacheCreation = self::usageInt($usage['cache_creation_input_tokens'] ?? null);
        $total = self::usageInt($usage['total_tokens'] ?? null)
            ?? ($input ?? 0) + ($output ?? 0) + ($cacheRead ?? 0) + ($cacheCreation ?? 0);

        return Usage::reported($total, $costUsd, $input, $output, $cacheRead, $cacheCreation);
    }

    /**
     * One usage number as reported: absent, JSON null or non-numeric stays
     * `null` (unreported — never a measured zero), the same rule the other
     * providers' parse seams apply.
     */
    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
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

        // The `usage:` carrier rides here too since audit A25: the CLI's
        // usage document is the Anthropic bucket split, so this wire is a
        // split-reading one like the API providers (see {@see parseUsage()},
        // which also explains why the measured CLI's total used to read 0).
        //
        // E707: `stop_reason` rides this envelope only on CLI builds that
        // surface it; when the key is absent the flag stays false - the
        // honest "the wire did not say", never a count-derived guess.
        $costUsd = (float) ($data['total_cost_usd'] ?? 0.0);
        $usage = self::parseUsage($data['usage'] ?? null, $costUsd);

        return new CompleteResponse(
            content: $data['result'] ?? $data['content'] ?? '',
            reasoning: $data['reasoning'] ?? null,
            toolCalls: $this->parseToolCalls($data['tool_calls'] ?? []),
            tokensUsed: $usage?->totalTokens ?? 0,
            costUsd: $costUsd,
            truncated: ($data['stop_reason'] ?? null) === 'max_tokens',
            usage: $usage,
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

        // Extended thinking streams as its own delta type; it is reasoning,
        // not reply text, so it must not land in the transcript's content.
        if (isset($data['event']['delta']['type']) && $data['event']['delta']['type'] === 'thinking_delta') {
            return new CompleteResponse(
                content: '',
                reasoning: (string) ($data['event']['delta']['thinking'] ?? ''),
                toolCalls: null,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        // E707 (round 81): the stream relays the Anthropic event frame, and
        // the terminal `message_delta` carries `delta.stop_reason` - a
        // ceiling end rides the (otherwise inert) empty frame for this event.
        return new CompleteResponse(
            content: '',
            reasoning: null,
            toolCalls: null,
            tokensUsed: 0,
            costUsd: 0.0,
            truncated: ($data['event']['delta']['stop_reason'] ?? null) === 'max_tokens',
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
