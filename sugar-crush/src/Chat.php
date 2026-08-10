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
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Commands\AgentsCommand;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\McpAuthCommand;
use SugarCraft\Crush\Commands\ShareCommand;
use SugarCraft\Crush\Palette\PaletteAction;
use SugarCraft\Crush\Palette\PaletteState;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;
use SugarCraft\Crush\Workflows\WorkflowEngine;
use SugarCraft\Crush\Workflows\WorkflowEngineInterface;
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

    /** @var WorkflowEngineInterface|null Optional workflow engine for /workflow command */
    private readonly ?WorkflowEngineInterface $workflowEngine;

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
     * Token budget used as the {@see ContextCompactor::shouldSendReminder()}
     * denominator. Mirrors the fixed 100,000-token proxy limit already used
     * by {@see shouldPromptIdleCompaction()} — so the 70% reminder tier
     * fires at ~70,000 estimated tokens, comfortably ahead of the 100,000
     * hard idle-compaction threshold.
     */
    private const REMINDER_TOKEN_LIMIT = 100000;

    /**
     * Wall-clock budget for {@see executeToolsParallel()}'s forked children.
     * A tool call that never returns (e.g. a hung shell command) would
     * otherwise leave the parent blocked forever waiting on pcntl_waitpid();
     * past this deadline, stragglers are SIGKILLed and reported as timeouts.
     */
    private const PARALLEL_TOOL_TIMEOUT_SECONDS = 30;

    /**
     * Two Escape presses within this window while a request is in flight
     * abort it (see the Escape arm in {@see update()}). A single Escape
     * never quits the app any more - use /exit, Ctrl+C, or the palette's
     * Exit action for that.
     */
    private const DOUBLE_ESCAPE_WINDOW_SECONDS = 0.6;

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
        ?WorkflowEngineInterface $workflowEngine = null,
        ?AgentManager $agentManager = null,
        ?CompactorConfig $compactorConfig = null,
        ?MemoryStore $memoryStore = null,
        \SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null $sessionStore = null,
        ?string $currentSessionId = null,
        private readonly ?\DateTimeImmutable $lastActivityAt = null,
        /** Highlighted row in the "/" popup (see {@see slashMenuMatches()}). */
        private readonly int $slashMenuIndex = 0,
        /** Active {@see Theme} name (see {@see theme()}); resolved lazily, not stored as an object. */
        private readonly string $themeName = 'dark',
        /** Ctrl+P command palette state; null when closed. */
        private readonly ?PaletteState $palette = null,
        /**
         * Optional callable(string $key, string $value): void, fired when
         * the Switch Model/Switch Theme palette actions (or /theme) apply a
         * choice - the persistence side effect itself (writing to
         * ~/.sugar-crush/config.json) lives in Bootstrap::chat()'s wiring,
         * not here, so this stays a no-op by default for tests/embedders
         * that never call withOnConfigChange()/pass one to the constructor.
         */
        private readonly ?\Closure $onConfigChange = null,
        /**
         * Bumped by every submit()/handleToolCalls() call that schedules a
         * backend Cmd; stamped onto that Cmd's eventual {@see AssistantMsg}
         * so a reply for a turn that was later aborted (see Escape-Escape
         * handling below) or superseded is recognisable as stale and
         * dropped in update() rather than appended after newer messages.
         */
        private readonly int $generation = 0,
        /**
         * Shared cancel flag for the turn currently in flight; null when
         * idle. See {@see \SugarCraft\Crush\Backend\CancellationToken}'s
         * docblock for why this can't be a normal immutable value-object
         * field - Escape-Escape needs to mutate the SAME instance the
         * already-scheduled Cmd captured.
         */
        private readonly ?CancellationToken $inFlightCancellation = null,
        /**
         * Wall-clock timestamp (microtime(true)) of the most recent
         * un-paired Escape press; null once consumed/expired. Drives the
         * double-Escape-to-abort window in update().
         */
        private readonly ?float $lastEscapeAt = null,
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
            // A reply for a turn that was aborted (double-Escape) or
            // otherwise superseded arrives after inFlight/generation have
            // already moved on - drop it rather than appending it after
            // whatever the user has done since. See AssistantMsg's
            // docblock and the Escape arm below.
            if ($msg->generation !== null && $msg->generation !== $this->generation) {
                return [$this, null];
            }

            $message = $msg->message;

            // Check if the message has tool calls to execute
            if ($message->toolCalls !== [] && $this->tools !== []) {
                return $this->handleToolCalls($message);
            }

            return [$this->mutate([
                'history' => [...$this->history, $message],
                'inFlight' => false,
                'inFlightCancellation' => null,
            ]), null];
        }
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === "\x03" /* ^C */) {
            return [$this, Cmd::quit()];
        }
        // Escape is checked before the inFlight blanket-swallow below (like
        // Ctrl+C above) because its whole point while a request is running
        // is to let the user cancel it. A single Escape never quits the app
        // any more (see this arm's history - it used to, and Alt+Backspace's
        // terminal-decoding bug made Escape fire on its own by accident,
        // quitting unexpectedly). Two Escapes within
        // DOUBLE_ESCAPE_WINDOW_SECONDS while inFlight abort the in-progress
        // turn instead. Excluded while the palette is open so Escape keeps
        // its existing, more specific meaning there - see
        // handlePaletteKey()'s own Escape arm, reached below once this `if`
        // doesn't match.
        if ($msg->type === KeyType::Escape && $this->palette === null) {
            if (!$this->inFlight) {
                return [$this->mutate(['lastEscapeAt' => null]), null];
            }

            $now = microtime(true);
            $isSecondPress = $this->lastEscapeAt !== null
                && ($now - $this->lastEscapeAt) <= self::DOUBLE_ESCAPE_WINDOW_SECONDS;

            if (!$isSecondPress) {
                return [$this->mutate(['lastEscapeAt' => $now]), null];
            }

            $this->inFlightCancellation?->cancel();

            return [$this->mutate([
                'inFlight' => false,
                'inFlightCancellation' => null,
                'lastEscapeAt' => null,
                'generation' => $this->generation + 1,
                'history' => [...$this->history, Message::system('_Request cancelled._')],
            ]), null];
        }
        if ($this->inFlight) {
            // Ignore keystrokes while waiting for the backend
            // (avoids the user racing ahead and queuing another
            // turn into a half-formed history).
            return [$this, null];
        }

        // While the Ctrl+P command palette is open, every keystroke feeds
        // its own query/navigation/dispatch handling instead of inputBuf/the
        // "/" popup - see handlePaletteKey()'s docblock.
        if ($this->palette !== null) {
            return $this->handlePaletteKey($msg);
        }

        return match (true) {
            // Alt/Shift/Ctrl+Enter insert a newline instead of submitting.
            // Alt+Enter is the reliable one across plain terminals (ESC+CR,
            // now decoded correctly by InputReader - see candy-core's
            // Alt-prefixed-key fix); Shift/Ctrl+Enter only arrive
            // distinguishably on terminals that report the Kitty keyboard
            // protocol unprompted, but cost nothing to also honor.
            $msg->type === KeyType::Enter && ($msg->alt || $msg->shift || $msg->ctrl)
                => [$this->withInputBuf($this->inputBuf . "\n"), null],
            $msg->type === KeyType::Enter
                => $this->slashMenuShouldIntercept()
                    ? $this->completeSlashMenuSelection()
                    : $this->submit(),
            // Up/Down navigate the "/" popup while it's showing (see
            // slashMenuMatches()); otherwise fall through to the default
            // no-op arm below, unchanged from before this popup existed.
            $msg->type === KeyType::Up && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(-1), null],
            $msg->type === KeyType::Down && $this->slashMenuMatches() !== []
                => [$this->moveSlashMenuSelection(1), null],
            // Shell-history-style recall: Up on an empty input box (and no
            // "/" popup showing - the arm above already claimed that case)
            // fills inputBuf with the last message the user actually sent.
            $msg->type === KeyType::Up && $this->inputBuf === ''
                => [$this->withInputBuf($this->lastUserMessageContent()), null],
            // Ctrl+P opens the command palette. Checked before the generic
            // Char arm below, or the literal "p" would be typed into the
            // input buffer instead - same reasoning as Ctrl+A just below.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'p'
                => [$this->mutate(['palette' => PaletteState::root()]), null],
            // R20: Ctrl+A re-runs the exact same /agents dispatch submit()
            // already uses for typed input (handleAgentsCommand()), giving
            // KeyboardHandler's Ctrl+A shortcut (Pane::Agents in the
            // disconnected App/Tui system) a real, reachable equivalent on
            // this, the live, Chat path. Must be checked before the generic
            // Char arm below, or the literal "a" would be typed into the
            // input buffer instead.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'a'
                => $this->withInputBuf('/agents')->submit(),
            // Word-delete: Ctrl+W (the usual terminal-wide convention) or a
            // correctly alt-flagged Backspace (see candy-core's
            // Alt-prefixed-key fix - before it, Alt+Backspace mis-decoded
            // as a bare Escape and quit the app instead of reaching here at
            // all). Must be checked before the plain Backspace arm below.
            ($msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'w')
                || ($msg->type === KeyType::Backspace && $msg->alt)
                => [$this->withInputBuf(self::dropLastWord($this->inputBuf)), null],
            // R20: Ctrl+Tab / Ctrl+Shift+Tab cycle the active session
            // through the real SessionStore listing — see
            // cycleSessionTab()'s docblock for reachability caveats and how
            // this relates to Tui\SessionTabs.
            $msg->type === KeyType::Tab && $msg->ctrl
                => $this->cycleSessionTab($msg->shift ? -1 : 1),
            $msg->type === KeyType::Char
                => [$this->withInputBuf($this->inputBuf . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withInputBuf($this->inputBuf . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withInputBuf(self::dropLast($this->inputBuf)), null],
            default => [$this, null],
        };
    }

    /**
     * Cycle the active session forward ($direction=1) or backward
     * ($direction=-1) through {@see SessionStore::listSessions()}'s real,
     * persisted row order — the same order {@see Renderer}'s session tab
     * strip displays, so switching here and switching via the rendered
     * strip stay in sync. A no-op when there is no session store, no
     * current session, or fewer than 2 sessions to switch between.
     *
     * Mirrors {@see \SugarCraft\Crush\Tui\SessionTabs}'s
     * `cycleForward()`/`cycleBackward()` wraparound semantics and its
     * `CTRL_TAB`/`CTRL_SHIFT_TAB` key bindings, without persisting a
     * `SessionTabs` instance on `Chat` itself — adding one would widen
     * Chat's already-large immutable constructor/`mutate()` surface well
     * beyond this item's "KeyMsg dispatch site only" scope for this file.
     * `currentSessionId` is already a real, mutate()-able field
     * ({@see withCurrentSessionId()}), so no constructor change was needed.
     *
     * Reachability note: most terminals send Ctrl+Tab as a `CSI 1;5I`
     * sequence that candy-core's `InputReader` does not yet decode into a
     * `KeyMsg` (a separate, pre-existing gap in a file outside this item's
     * scope — Ctrl+Tab isn't in the generic modifier-key CSI table there).
     * That decoder gap is NOT the only thing standing between this handler
     * and a real `bin/sugarcrush` user, though — even a `KeyMsg` source that
     * bypasses it entirely (Kitty-protocol terminals, scripted input) hits
     * the early-return above, because `Cli\Bootstrap::chat()` (the live
     * construction path) never passes a `currentSessionId`, `init()` returns
     * `null` (no startup Cmd selects or creates one either), and nothing
     * else in the live path calls {@see withCurrentSessionId()} except this
     * method and `handleBranchCommand()` — which itself requires an
     * existing `currentSessionId` to fork from, a circular, pre-existing
     * gap of its own. So a freshly-launched `bin/sugarcrush` session has
     * `currentSessionId === null` for its entire lifetime unless the user
     * first runs `/branch` against a session id it has no way to have
     * started with. Concretely, this method is exercised and correct
     * against any Chat that already carries a non-null `currentSessionId`
     * (as every test below does), but is a guaranteed no-op for a Chat
     * built the way `Bootstrap::chat()` actually builds it. Wiring an
     * initial `currentSessionId` into `Bootstrap::chat()`/`init()` (e.g.
     * selecting the most-recent row from `SessionStore::listSessions()`, or
     * creating one) would close this, but touches `src/Cli/Bootstrap.php`,
     * which is outside this item's declared file scope — left as an
     * explicit follow-up rather than fixed here.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function cycleSessionTab(int $direction): array
    {
        if ($this->sessionStore === null || $this->currentSessionId === null) {
            return [$this, null];
        }

        $ids = array_column($this->sessionStore->listSessions(), 'id');
        $count = count($ids);
        if ($count < 2) {
            return [$this, null];
        }

        $currentIndex = array_search($this->currentSessionId, $ids, true);
        if ($currentIndex === false) {
            return [$this, null];
        }

        $nextIndex = ($currentIndex + $direction + $count) % $count;

        return [$this->withCurrentSessionId($ids[$nextIndex]), null];
    }

    /**
     * Handle tool calls in an assistant message.
     * Executes each tool and schedules a follow-up backend call with results.
     *
     * All tool calls execute sequentially in-process. See {@see executeToolsParallel()}
     * for why the AgentWorkerPool dispatch path is disabled.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleToolCalls(Message $message): array
    {
        // R14b.fix: genuine parallelism only pays for itself with 2+ calls and
        // only when a pool was actually opted into via withWorkerPool() - the
        // common single-tool-call/no-pool case stays on the cheaper sequential
        // path with zero fork overhead. See executeToolsParallel()'s docblock
        // for why this no longer routes through AgentWorkerPool/SubAgent.
        $toolResults = ($this->effectivePool !== null && count($message->toolCalls) > 1)
            ? $this->executeToolsParallel($message->toolCalls)
            : $this->executeToolsSequentially($message->toolCalls);

        // Add assistant message and tool results to history
        $newHistory = [...$this->history, $message];
        foreach ($toolResults as $result) {
            $newHistory[] = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
                ->withToolResults([$result]);
        }

        // Schedule follow-up backend call with updated history. A fresh
        // CancellationToken/generation bump for this leg, same as submit()
        // - see its docblock for why (Escape-Escape abort, stale-reply
        // dropping).
        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => $newHistory,
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
        ]);

        $backend = $this->backend;
        $history = $next->history;
        $onToken = $this->streaming ? $this->onToken : null;
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken, $cancellation, $generation): PromiseInterface {
            return $backend->completeAsync($history, $onToken, $cancellation)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg, $generation),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_'), $generation),
            );
        });

        return [$next, $cmd];
    }

    /**
     * Execute a single tool call and return the result.
     */
    private function executeTool(ToolCall $toolCall): ToolResult
    {
        [$result, $raw, $succeeded] = $this->invokeTool($toolCall);

        // Notify tool call listener if set - only on a genuinely successful
        // invocation, matching invokeTool()'s contract (see its docblock).
        if ($succeeded && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $raw);
        }

        return $result;
    }

    /**
     * @param ToolCall[] $toolCalls
     * @return ToolResult[]
     */
    private function executeToolsSequentially(array $toolCalls): array
    {
        $toolResults = [];
        foreach ($toolCalls as $toolCall) {
            $toolResults[] = $this->executeTool($toolCall);
        }
        return $toolResults;
    }

    /**
     * Look up and invoke the registered callback for a tool call, without
     * firing {@see $onToolCall} - the pure primitive shared by the sequential
     * path ({@see executeTool()}) and the forked-child path
     * ({@see executeToolsParallel()}), so the listener can be fired exactly
     * once, in the right process (see executeToolsParallel()'s docblock for
     * why that matters).
     *
     * @return array{0: ToolResult, 1: mixed, 2: bool} [result, raw callback
     *     output (only meaningful when $succeeded), succeeded]
     */
    private function invokeTool(ToolCall $toolCall): array
    {
        $name = $toolCall->name;
        $args = $toolCall->arguments;

        if (!isset($this->tools[$name])) {
            return [ToolResult::error($name, "Unknown tool: {$name}", $toolCall->id), null, false];
        }

        try {
            $callback = $this->tools[$name];
            $raw = $callback($args);
            $result = ToolResult::ok($name, is_string($raw) ? $raw : (json_encode($raw) ?: 'null'), $toolCall->id);
            return [$result, $raw, true];
        } catch (\Throwable $e) {
            return [ToolResult::error($name, $e->getMessage(), $toolCall->id), null, false];
        }
    }

    /**
     * Execute multiple tool calls in parallel via a direct pcntl_fork() fan-out.
     *
     * R14b.fix: the original design routed through AgentWorkerPool/SubAgent,
     * which - for its default ExecutorInterface (ProcessExecutor) - forks
     * once and then has that fork spawn a SECOND, unrelated process via
     * proc_open() to run an inline worker script. A closure cannot cross
     * that second boundary (proc_open starts a brand-new PHP process with no
     * shared memory, communicating only via JSON over pipes), so the worker
     * had no way to reach the real callback in $this->tools and fabricated
     * output instead - hence R14b's original fix disabled this path entirely.
     *
     * That whole detour was unnecessary: $this->tools' closures need to
     * survive only ONE process boundary - a direct pcntl_fork() of THIS
     * process - and a fork duplicates the entire process memory (copy-on-write),
     * so the child's copy of $this (and every closure it holds) is fully
     * intact and callable. This method forks one child per tool call, has each
     * child invoke the real closure via {@see invokeTool()} and write its
     * result to a temp file, then collects and returns them in the parent -
     * genuinely concurrent, with the real callback output, no registry needed.
     *
     * $onToolCall is deliberately NOT invoked inside a child: a listener
     * closure that mutates state by reference (e.g. a test's
     * `use (&$captured)` array) would mutate only the child's own
     * copy-on-write copy, invisible to the parent. It is invoked here in the
     * parent instead, once per call, after collecting that call's real result.
     *
     * @param ToolCall[] $toolCalls
     * @return ToolResult[]
     */
    private function executeToolsParallel(array $toolCalls): array
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            return $this->executeToolsSequentially($toolCalls);
        }

        $jobs = [];
        foreach ($toolCalls as $index => $toolCall) {
            $file = sys_get_temp_dir() . '/sc_chat_tool_' . bin2hex(random_bytes(8)) . '.json';
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed for this call only - run it synchronously right
                // here and store its result the same way a child would, so
                // the collection loop below treats every call uniformly.
                $this->storeToolResult($file, $toolCall);
                $jobs[$index] = ['toolCall' => $toolCall, 'file' => $file, 'pid' => null];
                continue;
            }

            if ($pid === 0) {
                $this->storeToolResult($file, $toolCall);
                exit(0);
            }

            $jobs[$index] = ['toolCall' => $toolCall, 'file' => $file, 'pid' => $pid];
        }

        $this->waitForToolChildren($jobs);

        $toolResults = [];
        foreach ($jobs as $job) {
            $toolResults[] = $this->collectToolResult($job['file'], $job['toolCall']);
        }

        return $toolResults;
    }

    /**
     * Run inside a forked child (or synchronously, on fork failure): invoke
     * the real tool callback and write a JSON-safe payload the parent can
     * reconstruct via {@see collectToolResult()}. `raw` is best-effort
     * JSON-round-tripped for the parent's later $onToolCall call - tool
     * callbacks are documented as returning `mixed`, but anything that isn't
     * itself JSON-safe (a resource, a closure) can't survive any IPC
     * mechanism, forked or not, and isn't a realistic tool return value.
     */
    private function storeToolResult(string $file, ToolCall $toolCall): void
    {
        [$result, $raw, $succeeded] = $this->invokeTool($toolCall);

        $payload = [
            'succeeded' => $succeeded,
            'result' => [
                'name' => $result->name,
                'result' => $result->result,
                'error' => $result->error,
                'id' => $result->id,
            ],
            'raw' => json_decode(json_encode($raw) ?: 'null', true),
        ];

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($file, $json === false ? '' : $json);
    }

    /**
     * Block (with a bounded wall-clock timeout) until every forked child in
     * $jobs has exited, SIGKILLing any stragglers past
     * {@see PARALLEL_TOOL_TIMEOUT_SECONDS} so a hung tool (e.g. a stuck shell
     * command) cannot block this request forever. Mirrors the WNOHANG-poll
     * pattern {@see \SugarCraft\Crush\Agents\AgentWorkerPool::waitForCompletion()}
     * already uses for the same reason.
     *
     * @param array<int, array{toolCall: ToolCall, file: string, pid: ?int}> $jobs
     */
    private function waitForToolChildren(array $jobs): void
    {
        $pending = array_filter($jobs, static fn(array $job): bool => $job['pid'] !== null);
        if ($pending === []) {
            return;
        }

        $deadline = microtime(true) + self::PARALLEL_TOOL_TIMEOUT_SECONDS;
        while ($pending !== [] && microtime(true) < $deadline) {
            foreach ($pending as $index => $job) {
                $status = 0;
                if (pcntl_waitpid($job['pid'], $status, WNOHANG) === $job['pid']) {
                    unset($pending[$index]);
                }
            }
            if ($pending !== []) {
                usleep(10000);
            }
        }

        foreach ($pending as $job) {
            if (function_exists('posix_kill')) {
                posix_kill($job['pid'], SIGKILL);
            }
            $status = 0;
            pcntl_waitpid($job['pid'], $status);
        }
    }

    /**
     * Read + decode + delete a forked child's result file, reconstruct its
     * ToolResult, and fire $onToolCall in THIS (the parent) process when the
     * underlying callback succeeded - see executeToolsParallel()'s docblock
     * for why that firing can't happen in the child itself. A missing or
     * unreadable file (the child never wrote one - killed by the timeout
     * above, or crashed) is reported as a timeout error rather than silently
     * dropped.
     */
    private function collectToolResult(string $file, ToolCall $toolCall): ToolResult
    {
        $data = is_file($file) ? file_get_contents($file) : false;
        if ($data !== false && $data !== '') {
            @unlink($file);
        }

        $decoded = ($data !== false && $data !== '') ? json_decode($data, true) : null;
        if (!is_array($decoded) || !is_array($decoded['result'] ?? null)) {
            return ToolResult::error($toolCall->name, 'Tool execution timed out or produced no result', $toolCall->id);
        }

        $r = $decoded['result'];
        $result = new ToolResult(
            (string) ($r['name'] ?? $toolCall->name),
            (string) ($r['result'] ?? ''),
            $r['error'] ?? null,
            $r['id'] ?? $toolCall->id,
        );

        if (($decoded['succeeded'] ?? false) === true && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $decoded['raw'] ?? null);
        }

        return $result;
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

    public function withBackend(Backend $backend): self
    {
        return $this->mutate(['backend' => $backend]);
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
    public function sessionStore(): \SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null
    {
        return $this->sessionStore;
    }

    /**
     * Create a new Chat with an explicit session store.
     */
    public function withSessionStore(\SugarCraft\Crush\Session\SessionStore|\SugarCraft\Crush\Session\EnhancedSessionStore|null $sessionStore): self
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
    public function withWorkflowEngine(WorkflowEngineInterface $engine): self
    {
        return $this->mutate(['workflowEngine' => $engine]);
    }

    /**
     * Get the workflow engine, if set.
     */
    public function workflowEngine(): ?WorkflowEngineInterface
    {
        return $this->workflowEngine;
    }

    /**
     * Timestamp of the last real user prompt submitted through submit(),
     * or null if none has been recorded yet on this instance.
     */
    public function lastActivityAt(): ?\DateTimeImmutable
    {
        return $this->lastActivityAt;
    }

    /**
     * Create a new Chat with an explicit last-activity timestamp. Mainly
     * useful for tests that need to simulate an idle session.
     */
    public function withLastActivity(\DateTimeImmutable $lastActivityAt): self
    {
        return $this->mutate(['lastActivityAt' => $lastActivityAt]);
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
            'lastActivityAt' => $this->lastActivityAt,
            'slashMenuIndex' => $this->slashMenuIndex,
            'themeName' => $this->themeName,
            'palette' => $this->palette,
            'onConfigChange' => $this->onConfigChange,
            'generation' => $this->generation,
            'inFlightCancellation' => $this->inFlightCancellation,
            'lastEscapeAt' => $this->lastEscapeAt,
        ];

        return new self(...array_merge($constructorProps, $changes));
    }

    public function withStreaming(bool $enable): self
    {
        return $this->mutate(['streaming' => $enable]);
    }

    public function onToken(callable $callback): self
    {
        return $this->mutate([
            'onToken' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
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
        return $this->mutate(['tools' => $tools]);
    }

    /**
     * Register a callback for tool call events.
     *
     * @param callable(string $name, array $arguments, mixed $result): void $callback
     * @return self
     */
    public function onToolCall(callable $callback): self
    {
        return $this->mutate([
            'onToolCall' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
    }

    /**
     * Register a callback(string $key, string $value): void fired when the
     * Switch Model/Switch Theme palette actions (or /theme) apply a choice
     * - see the constructor param's docblock for why the actual persistence
     * side effect lives in Bootstrap::chat()'s wiring, not this class.
     */
    public function withOnConfigChange(callable $callback): self
    {
        return $this->mutate([
            'onConfigChange' => $callback instanceof \Closure ? $callback : \Closure::fromCallable($callback),
        ]);
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

        // Handle /exit (and /quit) - same as Ctrl+C / the palette's Exit
        // action, just reachable without a modifier key.
        if ($text === '/exit' || $text === '/quit') {
            return [$this, Cmd::quit()];
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

        // Handle /sessions command (R20: list + render the real SessionPicker)
        if (str_starts_with($text, '/sessions')) {
            return $this->handleSessionsCommand($text);
        }

        // Handle /theme command (switch color theme)
        if (str_starts_with($text, '/theme')) {
            return $this->handleThemeCommand($text);
        }

        // Handle mcp auth commands
        if (str_starts_with($text, 'mcp auth')) {
            return $this->handleMcpAuthCommand($text);
        }

        // Idle-compaction check, once per turn, before dispatching a real
        // prompt to the backend. shouldPromptIdleCompaction() previously had
        // no live call site anywhere in the codebase — only tests invoked it
        // directly — so an idle, oversized session never actually got
        // nudged toward /compact.
        $tokenCount = $this->estimateTokenCount($this->history);
        if ($this->shouldPromptIdleCompaction($tokenCount, $this->lastActivityAt)) {
            return $this->idleCompactionPromptResponse($text, $tokenCount);
        }

        // Reminder-tier check (R21's ContextCompactor::shouldSendReminder(),
        // 70% of the token budget by default). Unlike the idle-compaction
        // prompt above — which short-circuits the turn entirely and never
        // calls the backend — this is a soft, non-blocking notice: the real
        // prompt still goes out, but a system-role warning is appended
        // alongside it so the user sees context is filling up well before
        // the hard 85%/95% compaction tiers would kick in.
        $wireHistory = array_map(
            static fn(Message $msg): array => $msg->toWire(),
            $this->history
        );
        $sendReminder = $this->compactor->shouldSendReminder($wireHistory, self::REMINDER_TOKEN_LIMIT);

        $newTurnMessages = [Message::user($text)];
        if ($sendReminder) {
            $newTurnMessages[] = $this->contextReminderMessage($tokenCount);
        }

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => [...$this->history, ...$newTurnMessages],
            'inputBuf' => '',
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

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
        $cmd = Cmd::promise(static function () use ($backend, $history, $onToken, $cancellation, $generation): PromiseInterface {
            return $backend->completeAsync($history, $onToken, $cancellation)->then(
                static fn(Message $msg): ?Msg => new AssistantMsg($msg, $generation),
                static fn(\Throwable $e): ?Msg => new AssistantMsg(Message::assistant('_[error: ' . $e->getMessage() . ']_'), $generation),
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
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
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
        $lines[] = '';
        $lines[] = "Note: pause/resume granularity is per-whole-stage only. A real interrupt "
            . "(Ctrl-C/SIGTERM) captures whatever stages have genuinely finished so far, but if it "
            . "lands while a 'parallel' stage is mid-flight, that stage's individual in-progress "
            . "agent results are NOT captured — the stage is simply re-run from scratch on resume. "
            . "There is no partial-credit resume for a parallel sub-stage.";

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
     * Pause (cooperative here, or via WorkflowEngine's real SIGINT/SIGTERM
     * handling on a genuine interrupt) captures whatever whole stages have
     * actually completed so far. Resume granularity stays per-whole-stage
     * only: if a 'parallel' stage is mid-flight when the pause happens, its
     * individual in-progress agent results are not captured and that stage
     * is re-run from scratch on resume — there is no partial-credit resume
     * for a parallel sub-stage. See WorkflowEngine's class docblock.
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
        $workflows = $this->workflowEngine->listWorkflows();

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
        if ($exitCode !== 0) {
            return [$this, static fn() => print $output];
        }

        return $this->shareResponse($inputBuf, $output);
    }

    /**
     * Return a share command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function shareResponse(string $inputBuf, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }

    private function handleAgentsCommand(string $inputBuf): array
    {
        // R20.fix: Bootstrap::chat() (the real construction path
        // `bin/sugarcrush` uses) never passes an `agentManager:` -- so this
        // was reachable with zero configuration via a typed "/agents" *and*,
        // since R20 added the Ctrl+A shortcut below in update(), via a
        // single accidental keystroke. The former "?? throw" here escaped
        // uncaught out of Chat::update(): candy-core's Program has no
        // try/catch around its synchronous update() dispatch, so the
        // exception propagated out of the event loop entirely, skipping
        // teardownTerminal() and leaving the real terminal in whatever
        // raw/alt-screen state it was in. Degrade gracefully instead, the
        // same "<thing> not configured" pattern every other optional
        // collaborator on this class already follows (see
        // handleWorkflowCommand()/handleSessionsCommand()/
        // handleMemoryCommand() above).
        if ($this->agentManager === null) {
            return $this->agentsResponse($inputBuf, 'Agent manager not configured. Set an AgentManager to use /agents commands.');
        }

        // Parse args from the command (after "/agent" or "/agents").
        // /agents is 7 chars, /agent is 6 chars - detect which alias was used
        // by full-prefix match (not just presence of a trailing space) so a
        // bare "/agents" with no arguments slices off all 7 chars and yields
        // an empty $afterCommand instead of the trailing "s" of "/agents".
        $prefixLength = str_starts_with($inputBuf, '/agents') ? 7 : 6;
        $afterCommand = ltrim(substr($inputBuf, $prefixLength));
        $args = $afterCommand !== '' ? preg_split('/\s+/', $afterCommand) : [];

        // Execute the AgentsCommand - it outputs directly to stdout, capture via output buffering
        ob_start();
        $agentsCommand = new AgentsCommand($this->agentManager);
        $exitCode = $agentsCommand->execute($this, $args);
        $output = ob_get_clean();

        // If command failed (non-zero exit code), output error but don't add to history
        if ($exitCode !== 0) {
            return [$this, static fn() => print $output];
        }

        return $this->agentsResponse($inputBuf, $output);
    }

    /**
     * Return an agents command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function agentsResponse(string $inputBuf, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
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

        $next = $this->mutate([
            'history' => [...$compactedHistory, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);

        return [$next, null];
    }

    /**
     * Handle /sessions command — list all sessions via the real
     * {@see SessionPicker}.
     *
     * Unlike /branch, /rename, and /rewind (which each act on the *current*
     * session), /sessions builds SessionPicker from every row
     * {@see SessionStore::listSessions()} actually returns and renders it
     * exactly as the interactive picker would — this is the concrete,
     * testable proof that R19's real `SessionStore` wiring is reachable for
     * more than checkpoint/branch bookkeeping. The rendered picker text is
     * folded into an assistant turn (same shape every other local command in
     * this file uses via `sessionResponse()`/`*Response()`), rather than
     * SessionPicker's own keyboard navigation being wired live — that would
     * need Chat to persist a `SessionPicker` instance across turns, widening
     * this file's constructor/`mutate()` surface well beyond this item's
     * "KeyMsg dispatch site / new /sessions command site" scope.
     *
     * R20.fix disclosure: in a real `bin/sugarcrush` run this will render
     * `SessionPicker::new([])` (an empty picker), not a populated list —
     * no production path (`Bootstrap::chat()`, `Chat::init()`, or any other
     * `src/`/`bin/` call site) ever calls
     * `SessionStore::createSession()`/`EnhancedSessionStore::createSession()`,
     * so `listSessions()` has no rows to return until that separate,
     * out-of-scope wiring lands. `SessionCommandTest` covers this method by
     * calling `createSession()` on the store directly, which is why the test
     * passes today despite this being unreachable with real data. See the
     * matching note on `Renderer::renderSessionTabStrip()`'s class docblock.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleSessionsCommand(string $inputText): array
    {
        if ($this->sessionStore === null) {
            return $this->sessionResponse($inputText, 'Session store not configured. Set a SessionStore to use /sessions.');
        }

        $rows = $this->sessionStore->listSessions();
        $sessions = array_map(
            static function (array $row): array {
                $id = (string) ($row['id'] ?? '');
                $name = (string) ($row['name'] ?? '');

                return [
                    'sessionId' => $id,
                    'sessionName' => $name !== '' ? $name : $id,
                    'summary' => (string) ($row['system_prompt'] ?? ''),
                    'gitBranch' => null,
                    'lastActivity' => (string) ($row['updated_at'] ?? ''),
                ];
            },
            $rows,
        );

        $picker = SessionPicker::new($sessions);
        $size = TuiRenderer::getTerminalSize();
        $response = $picker->render($size['cols'], $size['rows']);

        return $this->sessionResponse($inputText, $response);
    }

    /**
     * Return a session command response, adding both user command and assistant response to history.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function sessionResponse(string $inputText, string $response): array
    {
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
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
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
            'currentSessionId' => $newSessionId ?? $this->currentSessionId,
        ]);

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
            $next = $this->mutate([
                'history' => [...$messages, Message::user($inputText), Message::assistant($response)],
                'inputBuf' => '',
                'inFlight' => false,
            ]);

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
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
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
        // Resetting slashMenuIndex here (rather than only in the Up/Down
        // handlers) means every inputBuf change - not just the ones that
        // change the filtered match set - re-highlights the top match, so a
        // stale selection index from a previous, differently-filtered list
        // can never leak into the new one. See slashMenuMatches()'s docblock
        // for why this makes the stored index always valid without an
        // explicit clamp on every read.
        return $this->mutate(['inputBuf' => $buf, 'slashMenuIndex' => 0]);
    }

    /**
     * Commands from {@see CommandRegistry} matching the in-progress "/name"
     * being typed - the "/" popup's data source ({@see
     * Renderer::renderSlashMenu()}). Returns [] (hiding the popup) when
     * inputBuf isn't slash-prefixed, or once it contains a space: at that
     * point the command name is already fixed and the user is typing
     * arguments, so there is nothing left to filter/complete.
     *
     * @return list<\SugarCraft\Crush\Commands\CommandSpec>
     */
    public function slashMenuMatches(): array
    {
        if (!str_starts_with($this->inputBuf, '/') || str_contains($this->inputBuf, ' ')) {
            return [];
        }

        return CommandRegistry::filter(substr($this->inputBuf, 1));
    }

    /**
     * The "/" popup's currently-highlighted row index into {@see
     * slashMenuMatches()}'s current result - always in range for it, never
     * needs clamping by a caller (see {@see withInputBuf()}'s docblock).
     */
    public function slashMenuIndex(): int
    {
        return $this->slashMenuIndex;
    }

    /**
     * The active color theme, resolved from the stored name on every call -
     * cheap (a handful of Color/Theme factory calls, no I/O) and keeps
     * Chat's own stored state to a plain string rather than an object.
     */
    public function theme(): Theme
    {
        return Theme::byName($this->themeName);
    }

    public function withThemeName(string $themeName): self
    {
        return $this->mutate(['themeName' => $themeName]);
    }

    /**
     * Whether Enter should complete the "/" popup's selection instead of
     * submitting. False whenever the popup isn't showing, AND false when
     * the name typed so far is already an exact, complete match for one of
     * the registered commands - "/agents" + Enter should run /agents, not
     * silently re-fill the same text, even while the popup is still
     * technically showing that single match. Only a genuinely partial/
     * ambiguous prefix (e.g. "/age") intercepts Enter for completion.
     */
    private function slashMenuShouldIntercept(): bool
    {
        $matches = $this->slashMenuMatches();
        if ($matches === []) {
            return false;
        }

        $typed = strtolower(substr($this->inputBuf, 1));
        foreach ($matches as $spec) {
            if (strtolower($spec->name) === $typed) {
                return false;
            }
        }

        return true;
    }

    /**
     * Move the "/" popup's highlighted row by $direction, wrapping around
     * the current match list. A no-op (returns $this unchanged) when the
     * popup isn't showing.
     */
    private function moveSlashMenuSelection(int $direction): self
    {
        $count = count($this->slashMenuMatches());
        if ($count === 0) {
            return $this;
        }

        $next = ($this->slashMenuIndex + $direction + $count) % $count;

        return $this->mutate(['slashMenuIndex' => $next]);
    }

    /**
     * Enter, while the "/" popup is showing: complete the highlighted
     * command into inputBuf (with a trailing space, ready for arguments)
     * rather than submitting immediately - several commands take required
     * arguments (e.g. /rename <name>), so completing first and sending on a
     * second Enter is the more forgiving default.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function completeSlashMenuSelection(): array
    {
        $matches = $this->slashMenuMatches();
        $index = min($this->slashMenuIndex, count($matches) - 1);

        return [$this->withInputBuf('/' . $matches[$index]->name . ' '), null];
    }

    /**
     * The Ctrl+P command palette's current mode/query/selection, or null
     * when closed.
     */
    public function palette(): ?PaletteState
    {
        return $this->palette;
    }

    /**
     * Fuzzy-filtered (or full, when the query is empty) item labels for the
     * palette's current mode, ranked best-match-first via {@see
     * SmithWatermanMatcher} - the same matcher `phlix-console-client`'s own
     * Ctrl+P palette already uses for the same purpose. Returns [] when the
     * palette is closed.
     *
     * @return list<string>
     */
    public function paletteMatches(): array
    {
        if ($this->palette === null) {
            return [];
        }

        $items = $this->paletteItemLabels();
        $query = $this->palette->query;
        if ($query === '' || $items === []) {
            return $items;
        }

        $results = (new SmithWatermanMatcher())->matchAll($query, $items);

        return array_map(static fn($result) => $result->haystack, $results);
    }

    /**
     * @return list<string>
     */
    private function paletteItemLabels(): array
    {
        return match ($this->palette?->mode) {
            'providers' => array_keys(\SugarCraft\Crush\Cli\Bootstrap::availableProviders()),
            'themes' => Theme::names(),
            default => array_map(static fn(PaletteAction $a): string => $a->label(), PaletteAction::all()),
        };
    }

    /**
     * Route every keystroke while the palette is open - see the Ctrl+P
     * bind in update()'s main match(true) block for how it gets opened.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteKey(KeyMsg $msg): array
    {
        return match (true) {
            $msg->type === KeyType::Escape
                => [$this->mutate(['palette' => null]), null],
            $msg->type === KeyType::Up
                => [$this->movePaletteSelection(-1), null],
            $msg->type === KeyType::Down
                => [$this->movePaletteSelection(1), null],
            // A second Ctrl+P closes the palette rather than opening it
            // again on top of itself.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'p'
                => [$this->mutate(['palette' => null]), null],
            $msg->type === KeyType::Char
                => [$this->withPaletteQuery($this->palette->query . $msg->rune), null],
            $msg->type === KeyType::Space
                => [$this->withPaletteQuery($this->palette->query . ' '), null],
            $msg->type === KeyType::Backspace
                => [$this->withPaletteQuery(self::dropLast($this->palette->query)), null],
            $msg->type === KeyType::Enter
                => $this->runSelectedPaletteAction(),
            default => [$this, null],
        };
    }

    private function withPaletteQuery(string $query): self
    {
        return $this->mutate(['palette' => $this->palette->withQuery($query)]);
    }

    private function movePaletteSelection(int $direction): self
    {
        $count = count($this->paletteMatches());
        if ($count === 0) {
            return $this;
        }

        $next = ($this->palette->selectedIndex + $direction + $count) % $count;

        return $this->mutate(['palette' => $this->palette->withSelectedIndex($next)]);
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function runSelectedPaletteAction(): array
    {
        $matches = $this->paletteMatches();
        if ($matches === []) {
            return [$this->mutate(['palette' => null]), null];
        }

        $label = $matches[min($this->palette->selectedIndex, count($matches) - 1)];

        return match ($this->palette->mode) {
            'providers' => $this->selectPaletteProvider($label),
            'themes' => $this->selectPaletteTheme($label),
            default => $this->runRootPaletteAction($label),
        };
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function runRootPaletteAction(string $label): array
    {
        $action = PaletteAction::byLabel($label);
        if ($action === null) {
            return [$this->mutate(['palette' => null]), null];
        }

        // SwitchModel/SwitchTheme transition to a second-level list rather
        // than closing the palette - every other action below closes it
        // first (mutate() default-preserves $this->palette, so the handler
        // it delegates to must run against the ALREADY-closed copy, not
        // $this, or its own internal mutate() call would silently reopen
        // the palette in its result).
        if ($action === PaletteAction::SwitchModel) {
            return [$this->mutate(['palette' => $this->palette->withMode('providers')]), null];
        }
        if ($action === PaletteAction::SwitchTheme) {
            return [$this->mutate(['palette' => $this->palette->withMode('themes')]), null];
        }

        $closed = $this->mutate(['palette' => null]);

        return match ($action) {
            PaletteAction::ShareSession => $closed->handleShareCommand('/share'),
            PaletteAction::SwitchAgent => $closed->handleAgentsCommand('/agents'),
            PaletteAction::SwitchSession => $closed->handleSessionsCommand('/sessions'),
            PaletteAction::ToggleMcp => $closed->handleMcpAuthCommand('mcp auth list'),
            PaletteAction::NewSession => $closed->handlePaletteNewSession(),
            PaletteAction::OpenDocs => $closed->handlePaletteOpenDocs(),
            PaletteAction::Exit => [$closed, Cmd::quit()],
            default => [$closed, null],
        };
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function selectPaletteProvider(string $name): array
    {
        try {
            $backend = \SugarCraft\Crush\Cli\Bootstrap::backendFor($name);
        } catch (\Throwable $e) {
            return [$this->mutate([
                'palette' => null,
                'history' => [...$this->history, Message::assistant("Could not switch to provider '{$name}': {$e->getMessage()}")],
            ]), null];
        }

        $this->onConfigChange?->__invoke('provider', $name);

        return [$this->mutate([
            'palette' => null,
            'backend' => $backend,
            'history' => [...$this->history, Message::assistant("Switched to provider '{$name}'.")],
        ]), null];
    }

    private function selectPaletteTheme(string $name): array
    {
        $this->onConfigChange?->__invoke('theme', $name);

        return [$this->mutate([
            'palette' => null,
            'themeName' => $name,
            'history' => [...$this->history, Message::assistant("Theme set to '{$name}'.")],
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteNewSession(): array
    {
        if ($this->sessionStore === null) {
            return [$this->mutate([
                'history' => [...$this->history, Message::assistant('Session store not configured. Set a SessionStore to create sessions.')],
            ]), null];
        }

        // Chat's Backend interface exposes no provider/model name to record
        // here - 'sugarcrush'/'unknown' are honest placeholders, not
        // fabricated telemetry (same disclosed-gap pattern as Renderer's own
        // R20 docblock elsewhere in this class).
        $sessionId = bin2hex(random_bytes(8));
        $this->sessionStore->createSession($sessionId, 'sugarcrush', 'unknown');

        return [$this->mutate([
            'history' => [...$this->history, Message::assistant("New session created: {$sessionId}")],
            'currentSessionId' => $sessionId,
        ]), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handlePaletteOpenDocs(): array
    {
        $message = 'Docs: see README.md in this project, or '
            . 'https://sugarcraft.github.io/lib/sugar-crush.html';

        return [$this->mutate(['history' => [...$this->history, Message::assistant($message)]]), null];
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

    /**
     * Trim the trailing "word" off $s: any trailing whitespace, then any
     * trailing run of non-whitespace. Mirrors the usual terminal-wide
     * Ctrl+W convention. Multi-byte-safe by operating on whole characters
     * via preg (UTF-8 mode) rather than raw byte indices.
     */
    private static function dropLastWord(string $s): string
    {
        return (string) preg_replace('/[^\s]+\s*$/u', '', $s);
    }

    /**
     * The content of the most recently sent (Role::User) message in
     * history, or '' if none exists yet - backs the shell-history-style Up
     * arrow recall in update(). Only ever looks at real user turns, so it
     * skips over assistant replies and tool-result/system messages.
     */
    private function lastUserMessageContent(): string
    {
        for ($i = count($this->history) - 1; $i >= 0; $i--) {
            if ($this->history[$i]->role === Role::User) {
                return $this->history[$i]->content;
            }
        }

        return '';
    }

    public function subscriptions(): ?\SugarCraft\Core\Subscriptions
    {
        return null;
    }

    /**
     * Check if idle compaction should be prompted based on token count and idle time.
     *
     * This replicates the logic from Runtime::shouldPromptIdleCompaction() for use
     * in the TUI event loop, where Runtime instance is not directly available.
     *
     * Returns true when session has been idle for more than 3600 seconds (1 hour)
     * AND token count exceeds 100,000.
     *
     * Called once per turn from submit() (see {@see idleCompactionPromptResponse()})
     * right before a real prompt would be dispatched to the backend.
     *
     * @param int $tokenCount Estimated token count for the conversation
     * @param \DateTimeImmutable|null $lastActivityAt When the user was last active
     */
    public function shouldPromptIdleCompaction(int $tokenCount, ?\DateTimeImmutable $lastActivityAt = null): bool
    {
        if ($tokenCount <= 100000) {
            return false;
        }

        if ($lastActivityAt === null) {
            return false;
        }

        $idleSeconds = time() - $lastActivityAt->getTimestamp();

        return $idleSeconds > 3600;
    }

    /**
     * Estimate token count for a message history using the same
     * 1-token≈4-chars heuristic as {@see ContextCompactor}'s internal
     * countTokens(), so the idle-compaction threshold agrees with what
     * /compact itself would report.
     *
     * @param list<Message> $history
     */
    private function estimateTokenCount(array $history): int
    {
        $total = 0;
        foreach ($history as $msg) {
            $total += (int) ceil(mb_strlen($msg->content) / 4);
            $total += 10; // role overhead
        }
        return $total;
    }

    /**
     * Current history's estimated size as a fraction of {@see
     * REMINDER_TOKEN_LIMIT} - the same fixed proxy limit already used by
     * the 70% reminder tier and idle-compaction check - for the status
     * bar's context-usage indicator ({@see Renderer}). Not clamped to
     * [0, 1]: a value above 1.0 is real signal (context has grown past the
     * reminder threshold and compaction hasn't run yet), not a bug to hide.
     */
    public function contextUsagePercent(): float
    {
        return $this->estimateTokenCount($this->history) / self::REMINDER_TOKEN_LIMIT;
    }

    /**
     * Build the soft, non-blocking reminder message surfaced once
     * {@see ContextCompactor::shouldSendReminder()} reports the conversation
     * has crossed its 70%-of-budget tier. Rendered with a distinct
     * `Role::System` (a faint "system: …" line, see {@see Renderer}) rather
     * than the `Role::Assistant` bubble used for the hard idle-compaction
     * prompt, so the two are visually distinguishable and this one never
     * blocks the turn it rides along with.
     */
    private function contextReminderMessage(int $tokenCount): Message
    {
        return Message::system(
            "Heads up: this conversation has grown to ~{$tokenCount} estimated "
            . "tokens, past the 70% context-usage reminder threshold. Consider "
            . "running /compact soon to keep the session responsive."
        );
    }

    /**
     * Short-circuit a real prompt submission with an idle-compaction
     * advisory instead of calling the backend, mirroring how /compact
     * responds locally (see handleCompactCommand()). Also records this
     * submission as fresh activity, so the nudge does not repeat on the
     * very next message.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function idleCompactionPromptResponse(string $inputText, int $tokenCount): array
    {
        $response = "This session has been idle for over an hour and has grown to "
            . "~{$tokenCount} estimated tokens. Run /compact to shrink the context "
            . "before continuing, or send another message to proceed anyway.";

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
            'lastActivityAt' => new \DateTimeImmutable(),
        ]);

        return [$next, null];
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
    /**
     * Handle /theme command — switch the active color theme, or show the
     * current one + available choices when called with no argument.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function handleThemeCommand(string $inputText): array
    {
        $afterTheme = trim(ltrim(substr($inputText, 6))); // after "/theme"

        if ($afterTheme === '') {
            return $this->sessionResponse(
                $inputText,
                "Current theme: {$this->themeName}. Available: " . implode(', ', Theme::names()) . '.'
            );
        }

        try {
            Theme::byName($afterTheme);
        } catch (\InvalidArgumentException $e) {
            return $this->sessionResponse($inputText, $e->getMessage());
        }

        $this->onConfigChange?->__invoke('theme', $afterTheme);

        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputText), Message::assistant("Theme set to '{$afterTheme}'.")],
            'inputBuf' => '',
            'inFlight' => false,
            'themeName' => $afterTheme,
        ]);

        return [$next, null];
    }

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
        $next = $this->mutate([
            'history' => [...$this->history, Message::user($inputBuf), Message::assistant($response)],
            'inputBuf' => '',
            'inFlight' => false,
        ]);
        return [$next, null];
    }
}
