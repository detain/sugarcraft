<?php

declare(strict_types=1);

namespace SugarCraft\Code\Providers;

use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Support\ProcessContainment;
use SugarCraft\Code\Support\ProcessReaper;
use SugarCraft\Code\Usage;

/**
 * Talks to the OpenAI Codex CLI through `codex exec --json`.
 *
 * PRV-4 tier 1, the sibling of {@see ClaudeCodeProvider}: the subscription
 * auth rides the upstream CLI (`codex login`, whose token lives in ~/.codex)
 * and never passes through this process, so there is zero secret handling
 * here. The transport template — prompt on stdin, NDJSON events, the
 * deadlock-closed drain loop, the generator-owned reaper — is mirrored from
 * its claude-code twin, including the E672 spawn pre-check and the E27(a)
 * typed ProviderException shapes.
 *
 * WHAT THIS PROVIDER IS NOT (AUT-4 honesty doctrine, applied up front):
 * - NOT a function-calling relay. `supportsFunctionCalling()` answers false
 *   because candy-code's tool set is never bridged into the child: codex runs
 *   its OWN sandboxed agent upstream and we relay its text. Advertising the
 *   capability would repeat the exact lie round-65 caught in claude-code.
 * - NOT a schema channel — no `--output-schema` shape was probed against an
 *   authenticated run, so {@see supportsJsonSchema()} answers false.
 * - NOT a token-billed provider here: costPer1kTokens() returns 0.0 for the
 *   same reason claude-code's does — billing belongs to the subscription.
 */
final readonly class CodexProvider implements ProviderInterface, RebindsModel, ShapesReasoningEffort
{
    /**
     * The slug probed against codex-cli 0.154.0 on 2026-10-19.
     *
     * The CLI printed a "Model metadata not found" WARN for it and still
     * served the request — degraded metadata, not a refusal — which is the
     * honest floor for a default. Sourced as a const so
     * {@see ProviderFactory::defaultConfig()} cites the same string.
     */
    public const DEFAULT_MODEL = 'gpt-5.1-codex-mini';

    /**
     * How much of a failing child's stderr is kept for the exception message.
     * The 64 KiB rationale is {@see ClaudeCodeProvider}'s, unchanged.
     */
    private const MAX_STDERR_BYTES = 65536;

    /**
     * The levels `model_reasoning_effort` accepts, read from the
     * codex-cli 0.154.0 installed dist — the binary's strings carry the
     * Rust `ReasoningEffort` enum variants concatenated
     * (`minimallowmediumhighxhighmaxultra`), not a guess from prose. The
     * CLI parses `-c` values leniently (an unrecognized spelling falls
     * back raw rather than erroring at the flag), so the filter belongs
     * HERE, at the side that knows its own tier vocabulary: every
     * {@see EffortBudget} rung is a member of this table; `minimal` and
     * `ultra` are kept because they are real upstream spellings even
     * though no EffortBudget rung produces them. A float dial value is
     * not a level word and sends no line.
     *
     * @var list<string>
     */
    private const CODEX_EFFORT_LEVELS = ['minimal', 'low', 'medium', 'high', 'xhigh', 'max', 'ultra'];

    public function __construct(
        private CodexInvocation $invocation,
        private string $defaultModel = self::DEFAULT_MODEL,
        // Null means "say nothing": the child keeps the operator's own
        // ~/.codex/config.toml sandbox, the widest authority we ever grant a
        // spawn. A set value rides `-s` verbatim and codex validates it.
        private ?string $sandbox = null,
    ) {}

    public function name(): string
    {
        return 'codex';
    }

    public function shapesEffortFor(string $model): bool
    {
        // Every model the Codex CLI serves is a reasoning model, and the
        // level word rides `codex exec -c model_reasoning_effort=<level>`
        // whatever the served id — the filter that drops non-level values
        // (the float dial, an unrecognized spelling) lives in
        // {@see options()}, where the request's own value is visible, not
        // here where only the model id is.
        return true;
    }

    public function sandbox(): ?string
    {
        return $this->sandbox;
    }

    public function supportsStreaming(): bool
    {
        return true;
    }

    public function supportsFunctionCalling(): bool
    {
        return false;
    }

    public function supportsDocuments(): bool
    {
        return false;
    }

    public function supportsVision(): bool
    {
        return false;
    }

    public function supportsJsonSchema(): bool
    {
        return false;
    }

    /**
     * This provider answering for $model — same shape as
     * {@see ClaudeCodeProvider::withModel()}.
     */
    public function withModel(string $model): static
    {
        return new self(...array_merge(get_object_vars($this), ['defaultModel' => $model]));
    }

    /**
     * 0 = UNKNOWN, and that is the honest answer: the context window belongs
     * to whichever model slug the operator configured (and to the upstream
     * CLI's own metadata for it), neither of which this wire reports back.
     * {@see \SugarCraft\Code\Context\ContextWindow::resolve()} turns 0 into
     * the one named fallback; guessing large would switch off the reminder,
     * the compaction, and the blocking refusal at once.
     */
    public function contextWindow(): int
    {
        return 0;
    }

    public function costPer1kTokens(string $model, string $direction): ?float
    {
        // Codex handles its own billing (subscription or the operator's key).
        return 0.0;
    }

    public function complete(CompleteRequest $request): CompleteResponse
    {
        $prompt = $this->buildPrompt($request);

        $output = $this->invocation->execute(
            $this->invocation->execArgs($this->options($request)),
            stdin: $prompt,
        );

        return $this->parseNdjsonResponse($output);
    }

    /**
     * @return \Generator<int, CompleteResponse>
     */
    public function completeStream(CompleteRequest $request): \Generator
    {
        $prompt = $this->buildPrompt($request);

        // E672 pre-check, twin of ClaudeCodeInvocation::execute(): under the
        // containment wrapper a bogus codexPath would otherwise launder this
        // site's typed throw into an exit-127 inside the stream.
        $codexBinary = $this->invocation->codexPath();
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($codexBinary, '/')
                ? is_executable($codexBinary)
                : ProcessContainment::locateOnPath($codexBinary) !== '')
        ) {
            throw new ProviderException('Failed to start Codex process');
        }

        $cmd = array_merge([$codexBinary], $this->invocation->execArgs($this->options($request)));

        $process = proc_open(
            ProcessContainment::spawnSpec($cmd),
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            ProcessContainment::env()
        );

        if (!is_resource($process)) {
            throw new ProviderException('Failed to start Codex process');
        }

        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $pending = CodexInvocation::feedStdin($pipes[0], $prompt);
        $buffer = '';
        $errors = '';
        $failureError = null;
        $open = [1 => $pipes[1], 2 => $pipes[2]];

        try {
            // BOTH PIPES DRAINED IN ONE LOOP, EINTR retried, no wall-clock cap
            // — every clause of this loop earns its place exactly as spelled
            // out in ClaudeCodeProvider::completeStream()'s comments (the 64
            // KiB stderr deadlock, the EINTR-under-alarm warning, the ban on
            // a blanket timeout abandoning a paid answer).
            while ($open !== []) {
                $read = array_values($open);
                $write = $pending !== '' ? [$pipes[0]] : [];
                $except = [];

                $ready = @stream_select($read, $write, $except, 1, 0);

                if ($ready === false) {
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
                    $pending = CodexInvocation::feedStdin($pipes[0], $pending);
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

                        $chunk = $this->parseLine($line);
                        if ($chunk !== null) {
                            $failureError = $chunk->isError ? $chunk->errorMessage : $failureError;

                            yield $chunk;
                        }
                    }
                }
            }

            // A final object written without a closing newline is still whole.
            $chunk = $this->parseLine($buffer);
            if ($chunk !== null) {
                $failureError = $chunk->isError ? $chunk->errorMessage : $failureError;

                yield $chunk;
            }
        } finally {
            // Generator-owned teardown, the E366 lesson in full: a consumer
            // that breaks out of the foreach destroys this generator mid-body,
            // and dropping a proc_open handle whose child still runs leaves an
            // ORPHAN holding every inherited descriptor. terminateAndClose()
            // signals nothing to a child that already exited.
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $exitCode = ProcessReaper::terminateAndClose($process, ProcessContainment::groupId($process));
        }

        if ($exitCode !== 0 && $exitCode !== -1 && $exitCode !== null) {
            $reason = CodexInvocation::exitReason($errors, $failureError);

            throw new ProviderException("Codex exited with code $exitCode: $reason", exitCode: $exitCode);
        }
    }

    public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
    {
        // The Codex CLI has no embeddings surface.
        return new EmbeddingsResponse(embeddings: []);
    }

    /**
     * The execArgs() options both paths share.
     *
     * @return array<string, mixed>
     */
    private function options(CompleteRequest $request): array
    {
        $options = ['model' => $this->defaultModel];

        if ($this->sandbox !== null) {
            $options['sandbox'] = $this->sandbox;
        }

        if ($request->reasoningEffort !== null) {
            $tier = strtolower((string) $request->reasoningEffort);
            if (in_array($tier, self::CODEX_EFFORT_LEVELS, true)) {
                $options['reasoningEffort'] = $tier;
            }
        }

        return $options;
    }

    /**
     * One NDJSON event line as a chunk, or null for a line with nothing for
     * this consumer.
     *
     * The live-probed framing (0.154.0): `thread.started`, `turn.started`,
     * `item.started`, `item.updated` and bare `{"type":"error"}` lines are
     * lifecycle chatter — skipped. What IS surfaced:
     * - `item.completed` whose item type is `agent_message`: the reply text
     *   (serde field `item.text`).
     * - `turn.completed`: the terminal usage record (its own chunk, the
     *   once-only terminal-total rule in ProviderInterface).
     * - `turn.failed`: the run's failure, as an error chunk.
     * `item.completed` of type `reasoning` is deliberately NOT relayed: the
     * summary field spelling was read from the binary schema, never from a
     * live authenticated event, and half-probed wire fields do not ship as
     * claims.
     */
    private function parseLine(string $line): ?CompleteResponse
    {
        $data = json_decode($line, true);
        if (!is_array($data)) {
            return null;
        }

        return match ($data['type'] ?? null) {
            'item.completed' => $this->parseItem($data),
            'turn.completed' => $this->parseTurnCompleted($data),
            'turn.failed' => new CompleteResponse(
                content: '',
                tokensUsed: 0,
                costUsd: 0.0,
                isError: true,
                errorMessage: CodexInvocation::turnFailureError($line),
            ),
            default => null,
        };
    }

    /**
     * @param array<mixed> $data
     */
    private function parseItem(array $data): ?CompleteResponse
    {
        $item = $data['item'] ?? null;
        if (!is_array($item) || ($item['type'] ?? null) !== 'agent_message') {
            return null;
        }

        $text = $item['text'] ?? '';

        return new CompleteResponse(
            content: is_string($text) ? $text : '',
            tokensUsed: 0,
            costUsd: 0.0,
        );
    }

    /**
     * @param array<mixed> $data
     */
    private function parseTurnCompleted(array $data): CompleteResponse
    {
        $usage = self::parseUsage($data['usage'] ?? null);

        return new CompleteResponse(
            content: '',
            tokensUsed: $usage?->totalTokens ?? 0,
            costUsd: 0.0,
            // Honest-unflagged: the E707 stop-field doctrine says a ceiling
            // end must come from the wire, and codex's turn.completed carries
            // no such field (0.154.0 schema) — never guess it from counts.
            truncated: false,
            usage: $usage,
        );
    }

    /**
     * The CLI's usage document as a {@see Usage}, or null when it reported
     * nothing measurable.
     *
     * Buckets are the 0.154.0 serde names: `input_tokens` (with
     * `cached_input_tokens` a SUBSET of it) and `output_tokens` (with
     * `reasoning_output_tokens` a subset of IT). That subset shape is why the
     * absent-total fallback sums only input+output — summing the subsets in
     * too would bill the same bytes twice. `cache_write_input_tokens` maps to
     * the carrier's cache-creation leg; it is reported separately upstream.
     */
    private static function parseUsage(mixed $usage): ?Usage
    {
        if (!is_array($usage)) {
            return null;
        }

        $input = self::usageInt($usage['input_tokens'] ?? null);
        $output = self::usageInt($usage['output_tokens'] ?? null);
        $cacheRead = self::usageInt($usage['cached_input_tokens'] ?? null);
        $cacheCreation = self::usageInt($usage['cache_write_input_tokens'] ?? null);
        $reasoning = self::usageInt($usage['reasoning_output_tokens'] ?? null);
        $total = self::usageInt($usage['total_tokens'] ?? null)
            ?? ($input ?? 0) + ($output ?? 0);

        return Usage::reported($total, 0.0, $input, $output, $cacheRead, $cacheCreation, $reasoning);
    }

    private static function usageInt(mixed $value): ?int
    {
        return $value === null || !is_numeric($value) ? null : (int) $value;
    }

    /**
     * Keep at most {@see MAX_STDERR_BYTES} of a child's stderr, THE TAIL —
     * the why is ClaudeCodeProvider::clipStderr()'s, unchanged.
     */
    private static function clipStderr(string $errors): string
    {
        if (strlen($errors) <= self::MAX_STDERR_BYTES) {
            return $errors;
        }

        return '[stderr truncated]' . substr($errors, -self::MAX_STDERR_BYTES);
    }

    /**
     * Flatten the request into the stdin prompt.
     *
     * Two codex-specific differences from the claude-code twin:
     * - there is no probed `--system-prompt` flag on `codex exec`, so the
     *   system prompt rides the transcript as its leading `System:` part
     *   (buildPrompt already prefixes SystemMessage entries that way);
     * - tools are never mentioned: this relay is text-only by the
     *   supportsFunctionCalling() decision in the class docblock.
     *
     * @param array<Message> $messages
     */
    private function buildPrompt(CompleteRequest $request): string
    {
        $parts = [];

        if ($request->systemPrompt !== null && $request->systemPrompt !== '') {
            $parts[] = "System: {$request->systemPrompt}";
        }

        foreach ($request->messages as $msg) {
            $parts[] = match (true) {
                $msg instanceof UserMessage => "User: {$msg->textOnly()}",
                $msg instanceof AssistantMessage => "Assistant: {$msg->content()}",
                $msg instanceof SystemMessage => "System: {$msg->content()}",
                $msg instanceof ToolResultMessage => "Tool Result: {$msg->content()}",
                default => "User: {$msg->content()}",
            };
        }

        return implode("\n\n", $parts);
    }

    /**
     * Aggregate a whole `codex exec --json` stdout into one response: the
     * LAST agent_message item is the reply (earlier ones are the agent
     * narrating mid-task), the usage comes from `turn.completed`, and a
     * `turn.failed` outranks both.
     */
    private function parseNdjsonResponse(string $output): CompleteResponse
    {
        $content = null;
        $usage = null;
        $failure = null;
        $sawEvent = false;

        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $data = json_decode($line, true);
            if (!is_array($data) || ($data['type'] ?? null) === null) {
                continue;
            }
            $sawEvent = true;

            match (true) {
                $data['type'] === 'item.completed'
                    && is_array($data['item'] ?? null)
                    && ($data['item']['type'] ?? null) === 'agent_message' => $content = (string) ($data['item']['text'] ?? ''),
                $data['type'] === 'turn.completed' => $usage = self::parseUsage($data['usage'] ?? null),
                $data['type'] === 'turn.failed' => $failure = CodexInvocation::turnFailureError($line),
                default => null,
            };
        }

        if (!$sawEvent) {
            // No JSON at all — the claude-code twin's raw-output fallback:
            // surfacing the child's text as content keeps the failure visible
            // instead of answering the user with an empty turn.
            return new CompleteResponse(
                content: $output,
                tokensUsed: 0,
                costUsd: 0.0,
            );
        }

        if ($failure !== null) {
            return new CompleteResponse(
                content: '',
                tokensUsed: $usage?->totalTokens ?? 0,
                costUsd: 0.0,
                isError: true,
                errorMessage: $failure,
                usage: $usage,
            );
        }

        return new CompleteResponse(
            content: $content ?? '',
            tokensUsed: $usage?->totalTokens ?? 0,
            costUsd: 0.0,
            truncated: false,
            usage: $usage,
        );
    }
}
