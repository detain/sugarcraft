<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use React\Promise\PromiseInterface;
use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Diff\DiffEncoder;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Commands\AgentsCommand;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowLoadException;
use SugarCraft\Crush\Workflows\WorkflowNotFoundException;
use SugarCraft\Crush\Workflows\WorkflowNotRunningException;
use SugarCraft\Crush\Workflows\WorkflowResult;
use SugarCraft\Crush\Workflows\WorkflowStatus;
use SugarCraft\Crush\Context\ContextCompactor;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Session\SessionStore;

/**
 * The chat shell, as a SugarCraft {@see Model}.
 *
 * Three pieces of state:
 *
 *   - `history`    — `list<Message>` accumulated so far
 *   - `inputBuf`   — the user's in-progress draft of the next turn
 *   - `inFlight`   — `true` while a backend call is in progress.
 *                    Input is suppressed and the renderer shows a
 *                    "thinking…" indicator.
 *
 * Sending: pressing Enter on a non-empty input pushes the
 * Message onto history, clears the buffer, sets `inFlight`,
 * and schedules a Cmd that calls `Backend::complete()` and
 * dispatches the result back as an {@see AssistantMsg}.
 *
 * The Backend is held privately and isn't part of equality —
 * tests use {@see Backend\EchoBackend}, prod uses whatever
 * adapter the user wires in {@see bin/sugarcrush}.
 *
 * **Tool Use:** Callbacks can be registered via `registerTool()`.
 * When an assistant message contains tool calls, they are executed
 * and the results are appended to history before the next backend
 * call continues.
 */
final class Chat implements Model
{
    private readonly Backend $backend;

    /** @var WorkflowEngine|null Optional workflow engine for /workflow command */
    private readonly ?WorkflowEngine $workflowEngine;

    /** @var ContextCompactor Context compactor for /compact command and automatic compaction */
    private readonly ContextCompactor $compactor;

    /** @var AgentManager|null Agent manager for /agents command */
    private ?AgentManager $agentManager = null;

    /** @var MemoryStore|null Memory store for /memory command */
    private ?MemoryStore $memoryStore = null;

    /** @var SessionStore|EnhancedSessionStore|null Session store for /branch and /rename commands */
    private SessionStore|EnhancedSessionStore|null $sessionStore = null;

    /** @var string|null ID of the currently active session */
    private ?string $currentSessionId = null;

    /** @var Buffer|null Previous rendered frame for diff-based emission */
    private ?Buffer $previousFrame = null;

    /** @var int|null Previous output height for dimension-change detection */
    private ?int $prevHeight = null;

    /** @var int|null Previous terminal width for resize detection */
    private ?int $prevWidth = null;

    /** Terminal width for buffer rendering. Updated from Renderer on each view(). */
    private int $width = 80;

    /**
     * @param list<Message> $history
     * @param array<string, callable> $tools Map of tool name => callable(array $arguments): mixed
     * @param callable|null $onToolCall Optional callback called when tools are invoked
     */
    public function __construct(
        public readonly array $history = [],
        public readonly string $inputBuf = '',
        public readonly bool $inFlight = false,
        ?Backend $backend = null,
        private readonly bool $streaming = false,
        private readonly ?\Closure $onToken = null,
        private readonly array $tools = [],
        private readonly ?\Closure $onToolCall = null,
        private readonly ?\SugarCraft\Crush\Agents\AgentPoolConfig $agentPoolConfig = null,
        private readonly ?\SugarCraft\Crush\Agents\AgentWorkerPool $effectivePool = null,
        ?WorkflowEngine $workflowEngine = null,
        ?AgentManager $agentManager = null,
        ?CompactorConfig $compactorConfig = null,
        ?MemoryStore $memoryStore = null,
        \SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null $sessionStore = null,
        ?string $currentSessionId = null,
    ) {
        $this->backend = $backend ?? new Backend\EchoBackend();
        $this->workflowEngine = $workflowEngine;
        $this->agentManager = $agentManager;
        $this->compactor = new ContextCompactor($compactorConfig ?? CompactorConfig::new());
        $this->memoryStore = $memoryStore;
        $this->sessionStore = $sessionStore;
        $this->currentSessionId = $currentSessionId;
    }

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof AssistantMsg) {
            $message = $msg->message;

            // Check if the message has tool calls to execute
            if ($message->toolCalls !== [] && $this->tools !== []) {
                return $this->handleToolCalls($message);
            }

            return [new self(
                history: [...$this->history, $message],
                inputBuf: $this->inputBuf,
                inFlight: false,
                backend: $this->backend,
                streaming: $this->streaming,
                onToken: $this->onToken,
                tools: $this->tools,
                onToolCall: $this->onToolCall,
                agentPoolConfig: $this->agentPoolConfig,
                effectivePool: $this->effectivePool,
                workflowEngine: $this->workflowEngine,
                agentManager: $this->agentManager,
                memoryStore: $this->memoryStore,
                sessionStore: $this->sessionStore,
                currentSessionId: $this->currentSessionId,
            ), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === "\x03" /* ^C */) {
            return [$this, Cmd::quit()];
        }
        if ($this->inFlight) {
            // Ignore keystrokes while waiting for the backend
            // (avoids the user racing ahead and queuing another
            // turn into a half-formed history).
            return [$this, null];
        }

        return match (true) {
            $msg->type === KeyType::Enter
                => $this->submit(),
            $msg->type === KeyType::Char
                => [$this->withInputBuf($this->inputBuf . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withInputBuf($this->inputBuf . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withInputBuf(self::dropLast($this->inputBuf)), null],
            $msg->type === KeyType::Escape
                => [$this, Cmd::quit()],
            default => [$this, null],
        };
    }

    /**
     * Handle tool calls in an assistant message.
     * Executes each tool and schedules a follow-up backend call with results.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleToolCalls(Message $message): array
    {
        // P1.S10 TODO: When running multiple independent tools in parallel, delegate to
        //   AgentWorkerPool via a future AgentManager instance instead of executing them
        //   sequentially here. The migration path will be:
        //     1. Create SubAgent instances from each tool call
        //     2. Call AgentManager::executeAll($subAgents, $request) which uses the pool
        //   For now, tools execute sequentially in the current process.
        $toolResults = [];
        foreach ($message->toolCalls as $toolCall) {
            $result = $this->executeTool($toolCall);
            $toolResults[] = $result;
        }

        // Add assistant message and tool results to history
        $newHistory = [...$this->history, $message];
        foreach ($toolResults as $result) {
            $newHistory[] = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
                ->withToolResults([$result]);
        }

        // Schedule follow-up backend call with updated history
        $next = new self(
            history: $newHistory,
            inputBuf: $this->inputBuf,
            inFlight: true,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );

        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken): PromiseInterface {
            return $backend->completeAsync($history, $onToken)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_')),
            );
        });

        return [$next, $cmd];
    }

    /**
     * Execute a single tool call and return the result.
     */
    private function executeTool(ToolCall $toolCall): ToolResult
    {
        $name = $toolCall->name;
        $args = $toolCall->arguments;

        if (!isset($this->tools[$name])) {
            return ToolResult::error($name, "Unknown tool: {$name}", $toolCall->id);
        }

        try {
            $callback = $this->tools[$name];
            $result = $callback($args);

            // Notify tool call listener if set
            if ($this->onToolCall !== null) {
                ($this->onToolCall)($name, $args, $result);
            }

            return ToolResult::ok($name, is_string($result) ? $result : (json_encode($result) ?: 'null'), $toolCall->id);
        } catch (\Throwable $e) {
            return ToolResult::error($name, $e->getMessage(), $toolCall->id);
        }
    }

    public function view(): string
    {
        // Get actual terminal dimensions from TUI Renderer (queries Tty for real size).
        $size = TuiRenderer::getTerminalSize();
        $width = $size['cols'];
        $fullOutput = Renderer::render($this);
        $height = substr_count($fullOutput, "\n") + 1;

        // Detect terminal resize: reset diff state on width or height change.
        if ($this->previousFrame !== null
            && ($this->prevWidth !== null && $this->prevWidth !== $width)
        ) {
            $this->previousFrame = null;
        }
        $this->prevWidth = $width;
        $this->prevHeight = $height;

        // First frame or dimension change: emit full output and store as previousFrame.
        if ($this->previousFrame === null) {
            $this->previousFrame = $this->bufferFromOutput($fullOutput, $width, $height);
            return $fullOutput;
        }

        // Subsequent frames with same dimensions: compute diff and emit delta.
        $currentFrame = $this->bufferFromOutput($fullOutput, $width, $height);
        $ops = $currentFrame->diff($this->previousFrame);
        $this->previousFrame = $currentFrame;

        $encoder = new DiffEncoder();
        return $encoder->encode($ops);
    }

    public function backend(): Backend
    {
        return $this->backend;
    }

    /**
     * Get the agent pool config, if set.
     */
    public function agentPoolConfig(): ?\SugarCraft\Crush\Agents\AgentPoolConfig
    {
        return $this->agentPoolConfig;
    }

    /**
     * Get the effective worker pool, if set.
     */
    public function pool(): ?\SugarCraft\Crush\Agents\AgentWorkerPool
    {
        return $this->effectivePool;
    }

    /**
     * Get the agent manager, if set.
     */
    public function agentManager(): ?\SugarCraft\Crush\Agents\AgentManager
    {
        return $this->agentManager;
    }

    /**
     * Get the memory store, if set.
     */
    public function memoryStore(): ?MemoryStore
    {
        return $this->memoryStore;
    }

    /**
     * Get the session store, if set.
     */
    public function sessionStore(): ?\SugarCraft\Crush\Session\SessionStore
    {
        return $this->sessionStore;
    }

    /**
     * Create a new Chat with an explicit session store.
     */
    public function withSessionStore(\SugarCraft\Crush\Session\SessionStore $sessionStore): self
    {
        return $this->mutate(['sessionStore' => $sessionStore]);
    }

    /**
     * Get the current session ID, if any.
     */
    public function currentSessionId(): ?string
    {
        return $this->currentSessionId;
    }

    /**
     * Create a new Chat with an explicit current session ID.
     */
    public function withCurrentSessionId(string $currentSessionId): self
    {
        return $this->mutate(['currentSessionId' => $currentSessionId]);
    }

    /**
     * Backward-compatible alias for pool().
     *
     * @deprecated Use pool() instead. This alias exists to ease migration.
     */
    public function workerPool(): ?\SugarCraft\Crush\Agents\AgentWorkerPool
    {
        return $this->pool();
    }

    /**
     * Execute multiple agents in parallel via the worker pool.
     *
     * If an explicit $effectivePool was set via withWorkerPool(), it is used directly.
     * Otherwise, if $agentPoolConfig was set via withAgentPoolConfig(), a pool is
     * built from that config. If neither is set, throws \RuntimeException.
     *
     * @param \SugarCraft\Crush\Agents\SubAgent[] $agents
     * @return \Generator<AgentResult>
     * @throws \RuntimeException When no pool or config is available
     */
    public function executeAgents(array $agents, \SugarCraft\Crush\Providers\CompleteRequest $request): \Generator
    {
        $pool = $this->effectivePool;
        if ($pool === null) {
            if ($this->agentPoolConfig === null) {
                throw new \RuntimeException(
                    'Cannot execute agents: no AgentWorkerPool or AgentPoolConfig available. '
                    . 'Call withWorkerPool() or withAgentPoolConfig() first.'
                );
            }
            // Wire config fields inline: create executor with timeout, pass maxConcurrent
            // to constructor, and apply stopOnFirstFailure via the pool's fluent setter.
            $executor = new \SugarCraft\Crush\Agents\ProcessExecutor(
                timeoutSeconds: $this->agentPoolConfig->defaultTimeoutSeconds,
            );
            $pool = (new \SugarCraft\Crush\Agents\AgentWorkerPool(
                maxConcurrent: $this->agentPoolConfig->maxConcurrent,
                executor: $executor,
            ))->withStopOnFirstFailure($this->agentPoolConfig->stopOnFirstFailure);
        }

        return $pool->executeAll($agents, $request);
    }

    /**
     * Create a new Chat with an explicit worker pool.
     */
    public function withWorkerPool(\SugarCraft\Crush\Agents\AgentWorkerPool $pool): self
    {
        return $this->mutate(['effectivePool' => $pool]);
    }

    /**
     * Create a new Chat with an agent pool config (used to build the worker pool on demand).
     */
    public function withAgentPoolConfig(\SugarCraft\Crush\Agents\AgentPoolConfig $config): self
    {
        return $this->mutate(['agentPoolConfig' => $config]);
    }

    /**
     * Create a new Chat with an explicit memory store.
     */
    public function withMemoryStore(MemoryStore $memoryStore): self
    {
        return $this->mutate(['memoryStore' => $memoryStore]);
    }

    /**
     * Create a new Chat with an explicit workflow engine.
     */
    public function withWorkflowEngine(WorkflowEngine $engine): self
    {
        return $this->mutate(['workflowEngine' => $engine]);
    }

    /**
     * Get the workflow engine, if set.
     */
    public function workflowEngine(): ?WorkflowEngine
    {
        return $this->workflowEngine;
    }

    /**
     * Merge changes into a new Chat instance.
     *
     * Only constructor-promoted properties are passed through to avoid
     * leaking private fields like $previousFrame, $prevHeight, etc.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): static
    {
        // Only include constructor-promoted properties (excludes backend,
        // previousFrame, prevHeight, prevWidth, width)
        $constructorProps = [
            'history' => $this->history,
            'inputBuf' => $this->inputBuf,
            'inFlight' => $this->inFlight,
            'streaming' => $this->streaming,
            'onToken' => $this->onToken,
            'tools' => $this->tools,
            'onToolCall' => $this->onToolCall,
            'agentPoolConfig' => $this->agentPoolConfig,
            'effectivePool' => $this->effectivePool,
            'backend' => $this->backend,
            'workflowEngine' => $this->workflowEngine,
            'agentManager' => $this->agentManager,
            'compactorConfig' => null, // compactor is reconstructed from null config (uses default)
            'memoryStore' => $this->memoryStore,
            'sessionStore' => $this->sessionStore,
            'currentSessionId' => $this->currentSessionId,
        ];

        return new self(...array_merge($constructorProps, $changes));
    }

    public function withStreaming(bool $enable): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $enable,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
    }

    public function onToken(callable $callback): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
    }

    /**
     * Register a tool/function that the AI can call.
     *
     * @param string $name The tool name (must be unique)
     * @param callable(array $arguments): mixed $callback The function to call
     * @return self A new Chat with the tool registered
     */
    public function registerTool(string $name, callable $callback): self
    {
        $tools = $this->tools;
        $tools[$name] = $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback);
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
    }

    /**
     * Register a callback for tool call events.
     *
     * @param callable(string $name, array $arguments, mixed $result): void $callback
     * @return self
     */
    public function onToolCall(callable $callback): self
    {
        return new self(
            history: $this->history,
            inputBuf: $this->inputBuf,
            inFlight: $this->inFlight,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
    }

    public function isStreaming(): bool
    {
        return $this->streaming;
    }

    /**
     * @return array<string, callable>
     */
    public function getTools(): array
    {
        return $this->tools;
    }

    /**
     * @return array{0:Chat,1:?\Closure}
     */
    private function submit(): array
    {
        $text = trim($this->inputBuf);
        if ($text === '') {
            return [$this, null];
        }

        // Handle /compact command to manually compact chat history
        if (str_starts_with($text, '/compact')) {
            return $this->handleCompactCommand($text);
        }

        // Handle /workflow commands locally without calling the backend
        if (str_starts_with($text, '/workflow')) {
            return $this->handleWorkflowCommand($text);
        }

        // Handle /share commands locally
        if (str_starts_with($text, '/share')) {
            return $this->handleShareCommand($text);
        }

        // Handle /agent (and /agents) commands locally
        if (str_starts_with($text, '/agent')) {
            return $this->handleAgentsCommand($text);
        }

        // Handle /memory commands locally
        if (str_starts_with($text, '/memory')) {
            return $this->handleMemoryCommand($text);
        }

        // Handle /branch command (fork current session)
        if (str_starts_with($text, '/branch')) {
            return $this->handleBranchCommand($text);
        }

        // Handle /rename command (name current session)
        if (str_starts_with($text, '/rename')) {
            return $this->handleRenameCommand($text);
        }

        // Handle /rewind command (restore from checkpoint)
        if (str_starts_with($text, '/rewind')) {
            return $this->handleRewindCommand($text);
        }

        // Handle mcp auth commands
        if (str_starts_with($text, 'mcp auth')) {
            return $this->handleMcpAuthCommand($text);
        }

        $next = new self(
            history: [...$this->history, Message::user($text)],
            inputBuf: '',
            inFlight: true,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );

        // Auto-save checkpoint before processing prompt
        if ($this->sessionStore !== null && $this->currentSessionId !== null && method_exists($this->sessionStore, 'saveCheckpoint')) {
            $chatState = [
                'messages' => $next->history,
                'inputBuf' => $next->inputBuf,
                'inFlight' => false,
                'agentContext' => [
                    'currentSessionId' => $this->currentSessionId,
                ],
            ];
            try {
                $this->sessionStore->saveCheckpoint($this->currentSessionId, $chatState);
            } catch (\Throwable) {
                // Ignore checkpoint save errors - don't block the prompt
            }
        }

        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken): PromiseInterface {
            return $backend->completeAsync($history, $onToken)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_')),
            );
        });
        return [$next, $cmd];
    }

    /**
     * Handle /workflow commands locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleWorkflowCommand(string $inputText): array
    {
        // Check if workflow engine is configured
        if ($this->workflowEngine === null) {
            $response = "Workflow engine not configured. Set a WorkflowEngine to use /workflow commands.";
            return $this->workflowResponse($inputText, $response);
        }

        $afterWorkflow = ltrim(substr($inputText, 9));
        if ($afterWorkflow === '') {
            return $this->workflowHelpResponse($inputText);
        }

        $parts = preg_split('/\s+/', $afterWorkflow, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        return match ($command) {
            'run' => $this->workflowRun($inputText, $args),
            'pause' => $this->workflowPause($inputText, $args),
            'resume' => $this->workflowResume($inputText, $args),
            'status' => $this->workflowStatus($inputText, $args),
            'list' => $this->workflowList($inputText),
            default => $this->workflowHelpResponse($inputText, "Unknown command '{$command}'."),
        };
    }

    /**
     * Return a workflow command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowResponse(string $inputText, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputText), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }

    /**
     * Show help text for /workflow command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /workflow commands:**';
        $lines[] = '';
        $lines[] = '`/workflow run <name> [key=val ...]` — Run a workflow by name with optional context';
        $lines[] = '`/workflow pause <workflowId>` — Pause a running workflow';
        $lines[] = '`/workflow resume <workflowId>` — Resume a paused workflow';
        $lines[] = '`/workflow status <workflowId>` — Check workflow status';
        $lines[] = '`/workflow list` — List available workflows';
        $lines[] = '`/workflow` — Show this help text';

        return $this->workflowResponse($inputText, implode("\n", $lines));
    }

    /**
     * Handle /workflow run command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowRun(string $inputText, string $args): array
    {
        $argParts = preg_split('/\s+/', $args);
        $workflowName = $argParts[0] ?? '';

        if ($workflowName === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow run <name> [key=val ...]");
        }

        // Parse key=val context pairs
        $context = [];
        foreach (array_slice($argParts, 1) as $pair) {
            if (str_contains($pair, '=')) {
                [$k, $v] = explode('=', $pair, 2);
                $context[trim($k)] = trim($v);
            }
        }

        try {
            $result = $this->workflowEngine->run($workflowName, $context);
            $response = "**Workflow '{$workflowName}' completed**\n\n";
            $response .= "ID: `{$result->workflowId}`\n";
            $response .= "Status: {$result->status->value}\n";
            $response .= "Stages completed: " . count($result->stageResults) . "\n";
            $response .= "Total tokens: {$result->totalTokens}\n";
            $response .= "Total cost: \${$result->totalCost}";
        } catch (WorkflowNotFoundException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (WorkflowLoadException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow pause command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowPause(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow pause <workflowId>");
        }

        try {
            $this->workflowEngine->pause($workflowId);
            $response = "Workflow `{$workflowId}` has been paused.";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow resume command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowResume(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow resume <workflowId>");
        }

        try {
            $result = $this->workflowEngine->resume($workflowId);
            $response = "**Workflow '{$workflowId}' resumed and completed**\n\n";
            $response .= "ID: `{$result->workflowId}`\n";
            $response .= "Status: {$result->status->value}\n";
            $response .= "Stages completed: " . count($result->stageResults) . "\n";
            $response .= "Total tokens: {$result->totalTokens}\n";
            $response .= "Total cost: \${$result->totalCost}";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (WorkflowNotFoundException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow status command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowStatus(string $inputText, string $args): array
    {
        $workflowId = trim($args);

        if ($workflowId === '') {
            return $this->workflowHelpResponse($inputText, "Usage: /workflow status <workflowId>");
        }

        try {
            $status = $this->workflowEngine->getStatus($workflowId);
            $response = "Workflow `{$workflowId}` status: **{$status->value}**";
        } catch (WorkflowNotRunningException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /workflow list command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function workflowList(string $inputText): array
    {
        $workflows = $this->workflowEngine->registry->list();

        if ($workflows === []) {
            $response = "No workflows found in `~/.sugar-crush/workflows/`.";
        } else {
            $lines = ['**Available workflows:**'];
            foreach ($workflows as $i => $name) {
                $lines[] = ($i + 1) . ". `{$name}`";
            }
            $response = implode("\n", $lines);
        }

        return $this->workflowResponse($inputText, $response);
    }

    /**
     * Handle /share command locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleShareCommand(string $inputBuf): array
    {
        // Parse args from the command (after "/share")
        $afterShare = ltrim(substr($inputBuf, 6));
        $args = $afterShare !== '' ? preg_split('/\s+/', $afterShare) : [];

        // Execute the ShareCommand - it outputs directly to stdout, capture via output buffering
        ob_start();
        $shareCommand = new ShareCommand();
        $exitCode = $shareCommand->execute($this, $args);
        $output = ob_get_clean();

        // ShareCommand returns 0 for success, non-zero for errors
        // The output already contains the formatted response
        return $this->shareResponse($inputBuf, $output);
    }

    /**
     * Return a share command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function shareResponse(string $inputBuf, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }

    private function handleAgentsCommand(string $inputBuf): array
    {
        // Parse args from the command (after "/agent" or "/agents")
        // /agents is 7 chars, /agent is 6 chars - we detect which was used
        $afterCommand = ltrim(substr($inputBuf, 7)); // starts after "/agents "
        if (str_starts_with($inputBuf, '/agents ')) {
            $args = $afterCommand !== '' ? preg_split('/\s+/', $afterCommand) : [];
        } else {
            // /agent followed by something (not space) — single token
            $afterAgent = ltrim(substr($inputBuf, 6));
            $args = $afterAgent !== '' ? preg_split('/\s+/', $afterAgent) : [];
        }

        // Execute the AgentsCommand - it outputs directly to stdout, capture via output buffering
        ob_start();
        $agentsCommand = new AgentsCommand($this->agentManager ?? throw new \RuntimeException('AgentManager not set'));
        $exitCode = $agentsCommand->execute($this, $args);
        $output = ob_get_clean();

        return $this->agentsResponse($inputBuf, $output);
    }

    /**
     * Return an agents command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function agentsResponse(string $inputBuf, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }

    /**
     * Handle /compact command to manually compact chat history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleCompactCommand(string $inputText): array
    {
        $originalCount = count($this->history);

        // Convert history to wire format for the compactor
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $this->history
        );

        // Compact the history using all 5 stages
        $compactedWire = $this->compactor->compact($wireHistory);
        $savingsPercentage = $this->compactor->savingsPercentage();

        // Convert back to Message objects
        $compactedHistory = [];
        foreach ($compactedWire as $wire) {
            $role = \SugarCraft\Crush\Role::from($wire['role'] ?? 'assistant');
            $content = $wire['content'] ?? '';
            $compactedHistory[] = match ($role) {
                Role::User => Message::user($content),
                Role::Assistant => Message::assistant($content),
                default => new Message($role, $content, time()),
            };
        }

        $newCount = count($compactedHistory);

        // Build response message
        if ($originalCount === 0) {
            $response = "Nothing to compact: chat history is empty.";
        } else {
            $response = "Context compacted: was {$originalCount} messages, now {$newCount} messages (saved {$savingsPercentage}% tokens)";
        }

        $next = new self(
            history: [...$compactedHistory, Message::user($inputText), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );

        return [$next, null];
    }

    /**
     * Return a session command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function sessionResponse(string $inputText, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputText), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }

    /**
     * Handle /branch command — fork the current session.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleBranchCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /branch and /rename commands.');
        }

        // /branch takes no arguments
        $afterBranch = ltrim(substr($inputText, 7)); // after "/branch"

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        if ($afterBranch !== '') {
            return $this->sessionResponse($inputText, 'Usage: /branch (takes no arguments)');
        }

        try {
            $newSessionId = $this->sessionStore->forkSession($this->currentSessionId);
            $response = "Branch created: {$newSessionId}";
        } catch (\InvalidArgumentException $e) {
            $response = "Error: {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "Error: {$e->getMessage()}";
        }

        // Return Chat with same state but currentSessionId updated to the new branch
        $next = new self(
            history: [...$this->history, Message::user($inputText), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $newSessionId ?? $this->currentSessionId,
        );

        return [$next, null];
    }

    /**
     * Handle /rename command — rename the current session.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRenameCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /branch and /rename commands.');
        }

        // /rename requires exactly one argument: the new name
        $afterRename = ltrim(substr($inputText, 7)); // after "/rename"

        if ($afterRename === '') {
            return $this->sessionResponse($inputText, 'Usage: /rename <newName>');
        }

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        $newName = trim($afterRename);
        if ($newName === '') {
            return $this->sessionResponse($inputText, 'Usage: /rename <newName>');
        }

        try {
            $this->sessionStore->renameSession($this->currentSessionId, $newName);
            $response = "Session renamed to '{$newName}'";
        } catch (\InvalidArgumentException $e) {
            $response = "Error: {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "Error: {$e->getMessage()}";
        }

        return $this->sessionResponse($inputText, $response);
    }

    /**
     * Handle /rewind command — restore chat state from a checkpoint.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleRewindCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured.');
        }

        if (!method_exists($this->sessionStore, 'restoreCheckpoint')) {
            return $this->sessionResponse($inputText, 'Session store does not support checkpoints. Use an EnhancedSessionStore.');
        }

        if ($this->currentSessionId === null) {
            return $this->sessionResponse($inputText, 'No active session. Start a new conversation first.');
        }

        // Parse optional step count: /rewind or /rewind <n>
        $afterRewind = ltrim(substr($inputText, 7)); // after "/rewind"
        $stepsBack = 1;

        if ($afterRewind !== '') {
            $stepsBack = (int) trim($afterRewind);
            if ($stepsBack < 1) {
                $stepsBack = 1;
            }
        }

        try {
            // Get list of checkpoints to find the target
            $checkpoints = $this->sessionStore->listCheckpoints($this->currentSessionId, $stepsBack);

            if (empty($checkpoints)) {
                return $this->sessionResponse($inputText, 'No checkpoints available to rewind to.');
            }

            // Find the checkpoint N steps back (where N is stepsBack)
            $targetIndex = $checkpoints[min($stepsBack - 1, count($checkpoints) - 1)]['index'] ?? null;

            if ($targetIndex === null) {
                return $this->sessionResponse($inputText, 'Could not determine checkpoint index.');
            }

            // Restore the checkpoint
            $state = $this->sessionStore->restoreCheckpoint($this->currentSessionId, $targetIndex);

            if ($state === null) {
                return $this->sessionResponse($inputText, "Checkpoint {$targetIndex} not found.");
            }

            // Extract state data
            $messages = $state['state_data']['messages'] ?? $state['messages'] ?? [];
            // Convert raw arrays to Message objects before passing to Chat constructor
            $messages = array_map(fn(array $msg): Message => match($msg['role'] ?? '') {
                'user' => Message::user($msg['content'] ?? ''),
                'assistant' => Message::assistant($msg['content'] ?? ''),
                default => Message::user($msg['content'] ?? ''),
            }, $messages);
            $inputBuf = $state['state_data']['inputBuf'] ?? $state['inputBuf'] ?? '';

            // Build response
            $rewoundCount = count($this->history) - count($messages);
            $response = "Rewound {$rewoundCount} messages to checkpoint {$targetIndex}. Use /branch to save this state before continuing.";

            // Return Chat with restored state
            $next = new self(
                history: [...$messages, Message::user($inputText), Message::assistant($response)],
                inputBuf: '',
                inFlight: false,
                backend: $this->backend,
                streaming: $this->streaming,
                onToken: $this->onToken,
                tools: $this->tools,
                onToolCall: $this->onToolCall,
                agentPoolConfig: $this->agentPoolConfig,
                effectivePool: $this->effectivePool,
                workflowEngine: $this->workflowEngine,
                agentManager: $this->agentManager,
                memoryStore: $this->memoryStore,
                sessionStore: $this->sessionStore,
                currentSessionId: $this->currentSessionId,
            );

            return [$next, null];
        } catch (\Throwable $e) {
            return $this->sessionResponse($inputText, "Error during rewind: {$e->getMessage()}");
        }
    }

    /**
     * Handle /memory commands locally.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleMemoryCommand(string $inputText): array
    {
        if ($this->memoryStore === null) {
            return $this->memoryResponse($inputText, 'Memory store not configured. Set a MemoryStore to use /memory commands.');
        }

        $afterMemory = ltrim(substr($inputText, 7)); // after "/memory"
        if ($afterMemory === '') {
            return $this->memoryHelpResponse($inputText);
        }

        $parts = preg_split('/\s+/', $afterMemory, 2);
        $command = $parts[0];
        $args = $parts[1] ?? '';

        return match ($command) {
            'list' => $this->memoryList($inputText, $args),
            'add' => $this->memoryAdd($inputText, $args),
            'search' => $this->memorySearch($inputText, $args),
            'delete' => $this->memoryDelete($inputText, $args),
            'clear' => $this->memoryClear($inputText, $args),
            'edit' => $this->memoryEdit($inputText, $args),
            default => $this->memoryHelpResponse($inputText, "Unknown command '{$command}'."),
        };
    }

    /**
     * Return a memory command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryResponse(string $inputText, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputText), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }

    /**
     * Show help text for /memory command.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /memory commands:**';
        $lines[] = '';
        $lines[] = '`/memory list [scope]` — List all memories for a scope (default: user)';
        $lines[] = '`/memory add <content>` — Add a new memory entry';
        $lines[] = '`/memory search <query>` — Search memories by content';
        $lines[] = '`/memory delete <id>` — Delete a memory by ID';
        $lines[] = '`/memory edit <id> <new_content>` — Edit an existing memory';
        $lines[] = '`/memory clear --scope <scope> --confirm` — Clear all memories for a scope';
        $lines[] = '`/memory` — Show this help text';
        $lines[] = '';
        $lines[] = 'Scopes: `user` (default), `project`, `agent`';

        return $this->memoryResponse($inputText, implode("\n", $lines));
    }

    /**
     * Handle /memory add <content> [--scope <scope>].
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryAdd(string $inputText, string $args): array
    {
        if ($args === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory add <content> [--scope <scope>]');
        }

        // Parse --scope flag if present (can be before or after content)
        $scope = 'user';
        $content = $args;

        if (preg_match('/^--scope\s+(user|project|agent)\s+(.*)$/s', $args, $m)) {
            $scope = $m[1];
            $content = trim($m[2]);
        } elseif (preg_match('/^(.*?)\s+--scope\s+(user|project|agent)\s*$/s', $args, $m)) {
            $content = trim($m[1]);
            $scope = $m[2];
        }

        if ($content === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory add <content> [--scope <scope>]');
        }

        try {
            $id = $this->memoryStore->add($content, $scope);
            $response = "Memory created with ID: `{$id}` (scope: {$scope})";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory list [scope].
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryList(string $inputText, string $args): array
    {
        $scope = 'user';
        if ($args !== '') {
            $trimmed = trim($args);
            // Handle --scope <scope> syntax
            if (str_starts_with($trimmed, '--scope ')) {
                $scopeCandidate = trim(substr($trimmed, 8));
                if (in_array($scopeCandidate, ['user', 'project', 'agent'], true)) {
                    $scope = $scopeCandidate;
                }
            } elseif (in_array($trimmed, ['user', 'project', 'agent'], true)) {
                $scope = $trimmed;
            }
        }

        try {
            $entries = $this->memoryStore->list($scope);
            if ($entries === []) {
                $response = "No memories found for scope `{$scope}`.";
            } else {
                $lines = ["**Memories ({$scope}):**", ''];
                foreach ($entries as $entry) {
                    $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
                    $preview = mb_strlen($entry->content()) > 80
                        ? mb_substr($entry->content(), 0, 80) . '…'
                        : $entry->content();
                    $lines[] = '- **[' . $entry->type() . ']** `' . $entry->id() . '`' . $tags;
                    $lines[] = '  ' . $preview;
                }
                $response = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory search <query>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memorySearch(string $inputText, string $query): array
    {
        if ($query === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory search <query>');
        }

        try {
            $entries = $this->memoryStore->search($query);
            if ($entries === []) {
                $response = "No memories found matching `{$query}`.";
            } else {
                $lines = ["**Search results for `{$query}` ({$this->pluralize(count($entries), 'match')}):**", ''];
                foreach ($entries as $entry) {
                    $tags = empty($entry->tags()) ? '' : ' [' . implode(', ', $entry->tags()) . ']';
                    $preview = mb_strlen($entry->content()) > 80
                        ? mb_substr($entry->content(), 0, 80) . '…'
                        : $entry->content();
                    $lines[] = '- **[' . $entry->type() . ']** `' . $entry->id() . '` (scope: ' . $entry->scope() . ')' . $tags;
                    $lines[] = '  ' . $preview;
                }
                $response = implode("\n", $lines);
            }
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory delete <id>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryDelete(string $inputText, string $args): array
    {
        $id = trim($args);
        if ($id === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory delete <id>');
        }

        try {
            $entry = $this->memoryStore->get($id);
            if ($entry === null) {
                $response = "Memory `{$id}` not found.";
            } else {
                $this->memoryStore->delete($id);
                $response = "Memory `{$id}` deleted.";
            }
        } catch (\InvalidArgumentException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory clear --scope <scope> --confirm.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryClear(string $inputText, string $args): array
    {
        // Parse --scope and --confirm flags
        if (!preg_match('/--scope\s+(user|project|agent)\s+--confirm/s', $args, $m)) {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory clear --scope <scope> --confirm');
        }

        $scope = $m[1];

        try {
            $this->memoryStore->clear($scope);
            $response = "All memories cleared for scope `{$scope}`.";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Handle /memory edit <id> <new_content>.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function memoryEdit(string $inputText, string $args): array
    {
        // Parse: <id> <new_content> — split on first whitespace, id is first token, rest is content
        $firstSpace = strpos($args, ' ');
        if ($firstSpace === false) {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory edit <id> <new_content>');
        }

        $id = trim(substr($args, 0, $firstSpace));
        $newContent = trim(substr($args, $firstSpace + 1));

        if ($id === '' || $newContent === '') {
            return $this->memoryHelpResponse($inputText, 'Usage: /memory edit <id> <new_content>');
        }

        try {
            $entry = $this->memoryStore->get($id);
            if ($entry === null) {
                $response = "Memory `{$id}` not found.";
            } else {
                $this->memoryStore->update($id, $entry->withContent($newContent));
                $response = "Memory `{$id}` updated.";
            }
        } catch (\InvalidArgumentException $e) {
            $response = "**Error:** {$e->getMessage()}";
        } catch (\Throwable $e) {
            $response = "**Error:** {$e->getMessage()}";
        }

        return $this->memoryResponse($inputText, $response);
    }

    /**
     * Show help text for /session commands.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function sessionHelpResponse(string $inputText, ?string $error = null): array
    {
        $lines = [];
        if ($error !== null) {
            $lines[] = "**Error:** {$error}";
            $lines[] = '';
        }
        $lines[] = '**Available /session commands:**';
        $lines[] = '';
        $lines[] = '`/rename <name>` — Name the current session for easy resume';
        $lines[] = '`/branch` — Fork the current session into a new copy';
        $lines[] = '`/rewind [n]` — Rewind n steps (default: 1) to a previous checkpoint';
        $lines[] = '`/session` — Show this help text';

        return $this->sessionResponse($inputText, implode("\n", $lines));
    }

    /**
     * Helper to pluralize a word based on count.
     */
    private function pluralize(int $count, string $word): string
    {
        if ($count === 1) {
            return "1 {$word}";
        }
        // Handle words ending in ch, x, s, o → add 'es'
        if (preg_match('/[chxso]$/', $word)) {
            return "{$count} {$word}es";
        }
        return "{$count} {$word}s";
    }

    private function withInputBuf(string $buf): self
    {
        return $this->mutate(['inputBuf' => $buf]);
    }

    /**
     * Drop the last UTF-8 codepoint from `$s`. Plain `substr(-1)`
     * would corrupt multi-byte input — a backspace after typing
     * an emoji should remove the whole grapheme.
     */
    private static function dropLast(string $s): string
    {
        if ($s === '') {
            return $s;
        }
        $i = strlen($s) - 1;
        while ($i > 0 && (ord($s[$i]) & 0xc0) === 0x80) {
            $i--;
        }
        return substr($s, 0, $i);
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Build a Buffer from a multi-line string output.
     *
     * All cells are created with null style — the diff algorithm will
     * still work correctly for detecting changed character positions.
     *
     * Uses Buffer::fromGrid() for O(w×h) bulk construction instead of
     * O(w²×h) repeated withCellAt() calls, and mb_str_split per row
     * instead of per-cell mb_substr for O(w) vs O(w²) string ops.
     *
     * @param string $output Multi-line string from Renderer::render()
     * @param int    $width  Buffer width in cells
     * @param int    $height Buffer height in rows
     */
    private function bufferFromOutput(string $output, int $width, int $height): Buffer
    {
        $lines = \explode("\n", $output);
        $grid = [];

        for ($row = 0; $row < $height; $row++) {
            $line = $lines[$row] ?? '';
            // mb_str_split is O(width) per row vs mb_substr called width×height times (O(width²×height))
            $chars = \mb_str_split($line, 1) ?: [];
            for ($col = 0; $col < $width; $col++) {
                $char = $chars[$col] ?? ' ';
                $grid[] = Cell::new($char, null, null, 1);
            }
        }

        return Buffer::fromGrid($width, $height, $grid);
    }

    /**
     * Reset the previous-frame buffer, forcing the next view to emit
     * a full frame (used on window resize or cursor-position-lost events).
     */
    public function resetPreviousFrame(): void
    {
        $this->previousFrame = null;
    }

    /**
     * Handle `mcp auth` command for managing MCP server OAuth credentials.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleMcpAuthCommand(string $inputBuf): array
    {
        // Parse sub-command and args after "mcp auth"
        $afterMcpAuth = ltrim(substr($inputBuf, 8)); // after "mcp auth"
        $args = $afterMcpAuth !== '' ? preg_split('/\s+/', $afterMcpAuth) : [];

        ob_start();
        $authStore = \SugarCraft\Crush\MCP\McpAuthStore::create();
        $command = new McpAuthCommand($authStore);
        $exitCode = $command->execute($this, $args);
        $output = ob_get_clean();

        return $this->mcpAuthResponse($inputBuf, $output);
    }

    /**
     * Return an mcp auth command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function mcpAuthResponse(string $inputBuf, string $response): array
    {
        $next = new self(
            history: [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            inputBuf: '',
            inFlight: false,
            backend: $this->backend,
            streaming: $this->streaming,
            onToken: $this->onToken,
            tools: $this->tools,
            onToolCall: $this->onToolCall,
            agentPoolConfig: $this->agentPoolConfig,
            effectivePool: $this->effectivePool,
            workflowEngine: $this->workflowEngine,
            agentManager: $this->agentManager,
            memoryStore: $this->memoryStore,
            sessionStore: $this->sessionStore,
            currentSessionId: $this->currentSessionId,
        );
        return [$next, null];
    }
}
