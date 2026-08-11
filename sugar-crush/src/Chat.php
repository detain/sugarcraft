<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\SessionPicker;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Events\ToolStarted;
use SugarCraft\Crush\Hooks\HookContext;
use SugarCraft\Crush\Hooks\HookManager;
use SugarCraft\Crush\Tools\ToolCall as EngineToolCall;
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

    /**
     * Token budget used as the {@see ContextCompactor::shouldSendReminder()}
     * denominator. Mirrors the fixed 100,000-token proxy limit already used
     * by {@see shouldPromptIdleCompaction()} — so the 70% reminder tier
     * fires at ~70,000 estimated tokens, comfortably ahead of the 100,000
     * hard idle-compaction threshold.
     */
    private const REMINDER_TOKEN_LIMIT = 100000;

    /**
     * Wall-clock budget for {@see forkToolCalls()}'s forked children.
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
         * Bumped by every submit()/beginToolCalls()/finishToolCalls() call that schedules a
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
        /**
         * Real terminal dimensions, sourced from {@see WindowSizeMsg} - the
         * one size candy-core's Program actually dispatches at startup AND
         * again on every SIGWINCH resize (see Program::installSignalHandlers()).
         * Null until the first WindowSizeMsg arrives (or for a Chat built
         * directly in a test, never); {@see rows()}/{@see cols()} fall back
         * to {@see TuiRenderer::getTerminalSize()}'s own detection in that
         * case. Renderer MUST read this instead of querying terminal size
         * itself - a second, independent, statically-cached size source
         * (which is what caused #1403's fix to not fully land: it clipped
         * to a size that could silently disagree with the real terminal, or
         * never picked up a live resize).
         */
        private readonly ?int $rows = null,
        private readonly ?int $cols = null,
        /**
         * The candy-mosaic probe-once capability instance (W1.G2/E2 - see
         * {@see ToolResult::mosaic()}'s docblock), exposed here per
         * crush_feat.md section 9's literal `new Chat(..., mosaic: $mosaic)`
         * so a future renderer (E3) can read the SAME detected protocol an
         * image-bearing {@see ToolResult} was produced against instead of
         * re-probing the TTY independently. {@see
         * \SugarCraft\Crush\Cli\Bootstrap::chat()} passes {@see
         * ToolResult::mosaic()} here; null for a Chat built directly in a
         * test that never needs it.
         */
        private readonly ?\SugarCraft\Mosaic\Mosaic $mosaic = null,
        /**
         * Hook chain gating this Chat's OWN ({@see registerTool()}) tool
         * calls, so `PreToolUse`/`PostToolUse` fire for a call no matter
         * which of the two pipelines crush_feat.md §1 D describes dispatched
         * it. Before this, {@see Runtime}'s engine pipeline ran every call
         * through {@see HookManager} while the Chat-native pipeline called
         * the registered closure with zero gating - so the same `rm -rf`
         * argument was denied by {@see \SugarCraft\Crush\Hooks\BuiltIn\ConfirmRemoveHook}
         * on one path and executed on the other.
         *
         * Null (the default) keeps the pre-gating behaviour for tests and
         * embedders that never wire hooks; {@see \SugarCraft\Crush\Cli\Bootstrap::chat()}
         * passes the same built-in guard chain (`Bootstrap::hooks()`) it
         * hands the engine backend.
         */
        private readonly ?HookManager $hooks = null,
        /**
         * Tool-call ids whose output the user has explicitly expanded, keyed
         * by id ({@see ToolResult::$id}), value always true - a collapsed
         * call is simply absent rather than stored as false, so the map stays
         * the size of what the user actually opened rather than growing one
         * entry per tool call for the life of the session.
         *
         * {@see Renderer::renderToolResults()} hides a successful call's body
         * unless its id is in here (crush_feat.md §1 E5's "hide-on-success by
         * default"); Ctrl+O toggles it (see {@see toggleToolOutput()}).
         *
         * @var array<string, bool>
         */
        private readonly array $expanded = [],
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
                return $this->beginToolCalls($message);
            }

            return [$this->mutate([
                'history' => [...$this->history, $message],
                'inFlight' => false,
                'inFlightCancellation' => null,
            ]), null];
        }
        if ($msg instanceof ToolResultsMsg) {
            return $this->finishToolCalls($msg);
        }
        if ($msg instanceof BackendToolEventsMsg) {
            return $this->applyBackendToolEvent($msg);
        }
        if ($msg instanceof WindowSizeMsg) {
            // The one authoritative size - see the constructor docblock on
            // $rows/$cols for why Renderer must read these instead of
            // querying terminal size itself.
            return [$this->mutate(['rows' => $msg->rows, 'cols' => $msg->cols]), null];
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
            // Ctrl+O expands/collapses the most recent tool call's output
            // (crush_feat.md §1 E5) - successful tool bodies are hidden by
            // default, and this is the only way to see one. Checked before
            // the generic Char arm below, or the literal "o" would be typed
            // into the input buffer instead - same reasoning as Ctrl+P above.
            $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'o'
                => $this->toggleLatestToolOutput(),
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
     * Handle tool calls in an assistant message: show a "running" placeholder
     * for each one IMMEDIATELY (visible on the very next render, before any
     * of them execute), fork all of them right away (see {@see forkToolCalls()}),
     * and schedule a Cmd that waits for them off the render loop (see
     * {@see waitForToolChildrenAsync()}) - the same non-blocking-socket
     * rationale as {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()},
     * applied to tool execution instead of the provider call. Finishing is
     * handled by {@see finishToolCalls()} once the resulting
     * {@see ToolResultsMsg} arrives.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function beginToolCalls(Message $message): array
    {
        $placeholders = array_map(
            static fn(ToolCall $call): Message => Message::toolRunning($call),
            $message->toolCalls,
        );

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => [...$this->history, $message, ...$placeholders],
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
        ]);

        $jobs = $this->forkToolCalls($message->toolCalls);
        $cmd = Cmd::promise(function () use ($jobs, $cancellation, $message, $generation): PromiseInterface {
            return $this->waitForToolChildrenAsync($jobs, $cancellation)->then(
                static fn(array $results): Msg => new ToolResultsMsg($message, $results, $generation),
            );
        });

        return [$next, $cmd];
    }

    /**
     * Handle a completed {@see ToolResultsMsg}: replace each "running"
     * placeholder {@see beginToolCalls()} put in history with its real
     * result (matched by {@see Message::$pendingToolCallId}), then schedule
     * the follow-up backend call exactly like {@see submit()}'s tail does.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function finishToolCalls(ToolResultsMsg $msg): array
    {
        if ($msg->generation !== null && $msg->generation !== $this->generation) {
            return [$this, null];
        }

        $resultsById = [];
        foreach ($msg->results as $result) {
            $resultsById[$result->id ?? $result->name] = $result;
        }

        $newHistory = [];
        foreach ($this->history as $historyMessage) {
            $pendingId = $historyMessage->pendingToolCallId;
            if ($pendingId !== null && isset($resultsById[$pendingId])) {
                $result = $resultsById[$pendingId];
                $newHistory[] = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
                    ->withToolResults([$result]);

                continue;
            }
            $newHistory[] = $historyMessage;
        }

        $generation = $this->generation + 1;
        $cancellation = new CancellationToken();
        $next = $this->mutate([
            'history' => $newHistory,
            'inFlight' => true,
            'inFlightCancellation' => $cancellation,
            'generation' => $generation,
        ]);

        return [$next, $this->scheduleBackendCompletion($next, $cancellation, $generation)];
    }

    /**
     * Apply ONE queued backend tool-lifecycle event to history, then
     * re-dispatch whatever is left of the queue.
     *
     * This is the consuming half of the `$onEvent` seam {@see Backend} threads
     * through {@see Backend\EngineBackend}/{@see Runtime} (crush_feat.md §1 E1).
     * Before it, an agentic backend could run several rounds of tool calls
     * inside one `complete()` and the user saw nothing but a "thinking…"
     * spinner: only the final Message escaped, so none of {@see Renderer}'s
     * tool rendering ever fired for that pipeline.
     *
     * One event per `update()` (rather than folding the whole queue in a single
     * pass) is what makes the *running* half visible: each returned Chat is
     * rendered before the next event is applied, so an engine-dispatched call
     * walks through the same placeholder-then-replace states
     * {@see beginToolCalls()}/{@see finishToolCalls()} produce for a
     * {@see registerTool()} one. Note this cannot make {@see
     * Backend\EngineBackend}'s FORKED path retroactively live - it replays its
     * queue when the child's payload lands - but the transcript states it
     * produces are identical either way.
     *
     * @return array{0:Chat,1:?\Closure}
     */
    private function applyBackendToolEvent(BackendToolEventsMsg $msg): array
    {
        if ($msg->generation !== null && $msg->generation !== $this->generation) {
            return [$this, null];
        }

        $remaining = $msg->events;
        $event = array_shift($remaining);

        // Queue drained: hand the turn's reply to the ordinary AssistantMsg
        // arm so tool calls the model asked for on TOP of the engine's own
        // (Chat-native $tools) still get picked up by beginToolCalls().
        if ($event === null) {
            return [$this, Cmd::send(new AssistantMsg($msg->message, $msg->generation))];
        }

        $next = $event instanceof ToolStarted
            ? $this->appendToolRunningPlaceholder($event)
            : $this->replaceToolRunningPlaceholder($event);

        return [$next, Cmd::send(new BackendToolEventsMsg($remaining, $msg->message, $msg->generation))];
    }

    /**
     * Append the "running" placeholder for an engine-dispatched tool call -
     * {@see beginToolCalls()}'s first half, driven by a {@see ToolStarted}
     * instead of by a Message's own `$toolCalls`.
     *
     * The event's engine-side identity is converted through
     * {@see ToolCall::fromEngineCall()} rather than by hand so the placeholder's
     * `pendingToolCallId` keys exactly the way the rest of the Chat-side
     * pipeline keys (W2.S1b).
     */
    private function appendToolRunningPlaceholder(ToolStarted $event): self
    {
        $call = ToolCall::fromEngineCall(
            new EngineToolCall($event->toolCallId, $event->toolName, $event->arguments),
        );

        return $this->mutate(['history' => [...$this->history, Message::toolRunning($call)]]);
    }

    /**
     * Replace an engine-dispatched call's placeholder with its real result -
     * {@see finishToolCalls()}'s replace-by-id half, and deliberately building
     * the same `Message::assistant(…)->withToolResults([…])` shape so
     * {@see Renderer::renderToolResults()} renders both pipelines identically
     * (including the W1.F1 diff and the image bytes that ride along on
     * {@see ToolResult}).
     *
     * Correlation is on {@see ToolFinished::$toolCallId}, NOT on the adapted
     * result's own `id`: a tool never sees its own call id, so built-ins
     * routinely return an invented one and only the event carries the id the
     * placeholder was keyed with (see {@see ToolFinished::fromResult()}).
     *
     * An unmatched result is appended rather than dropped - losing a tool's
     * output entirely is worse than showing it without a preceding placeholder.
     */
    private function replaceToolRunningPlaceholder(ToolFinished $event): self
    {
        $result = ToolResult::fromEngineResult($event->result, $event->toolName);
        $message = Message::assistant($result->isError() ? "Tool error: {$result->error}" : $result->result)
            ->withToolResults([$result]);

        $newHistory = [];
        $replaced = false;
        foreach ($this->history as $historyMessage) {
            if (!$replaced && $historyMessage->pendingToolCallId === $event->toolCallId) {
                $newHistory[] = $message;
                $replaced = true;

                continue;
            }
            $newHistory[] = $historyMessage;
        }

        if (!$replaced) {
            $newHistory[] = $message;
        }

        return $this->mutate(['history' => $newHistory]);
    }

    /**
     * Look up and invoke the registered callback for a tool call, without
     * firing {@see $onToolCall} - the listener fires exactly once, in the
     * parent process, once {@see finishToolCalls()} collects this call's
     * real result (see {@see forkToolCalls()}'s docblock for why that can't
     * happen in the child that actually runs this).
     *
     * A callback that already returns a {@see ToolResult} (e.g. one built
     * via {@see ToolResult::okWithImage()}/{@see ToolResult::withImage()} -
     * see W1.G2) is passed through as-is instead of being re-wrapped by
     * {@see ToolResult::ok()}: re-wrapping would `json_encode()` the object
     * (serializing its public properties, including raw `imageBytes`, into
     * the text `result` string shown to the model/user) and silently drop
     * every field ok() doesn't accept. The tool call's own `$toolCall->id`
     * still wins over whatever id the callback set, matching ok()'s
     * previous behaviour of always stamping the real id.
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
            if ($raw instanceof ToolResult) {
                $result = $raw->id === $toolCall->id ? $raw : new ToolResult(
                    $raw->name,
                    $raw->result,
                    $raw->error,
                    $toolCall->id,
                    $raw->imageBytes,
                    $raw->imagePath,
                    $raw->imageProtocol,
                    $raw->diff,
                    $raw->durationMs,
                );
                return [$result, $raw, true];
            }
            $result = ToolResult::ok($name, is_string($raw) ? $raw : (json_encode($raw) ?: 'null'), $toolCall->id);
            return [$result, $raw, true];
        } catch (\Throwable $e) {
            return [ToolResult::error($name, $e->getMessage(), $toolCall->id), null, false];
        }
    }

    /**
     * Fork one child per tool call via a direct pcntl_fork() fan-out (or,
     * when forking isn't available, run it synchronously in-process right
     * here - see {@see executeToolSynchronously()}).
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
     * result to a temp file; {@see waitForToolChildrenAsync()} collects them
     * once every child has exited - genuinely concurrent, with the real
     * callback output, no registry needed.
     *
     * $onToolCall is deliberately NOT invoked inside a forked child: a
     * listener closure that mutates state by reference (e.g. a test's
     * `use (&$captured)` array) would mutate only the child's own
     * copy-on-write copy, invisible to the parent. It's invoked later, in
     * the parent, once {@see waitForToolChildrenAsync()} collects that
     * call's real result.
     *
     * Hook gating (crush_feat.md §1 E1) runs HERE, in the parent, before any
     * fork: a denied call must never reach a child at all, and a
     * {@see HookManager} whose hooks ran inside a forked child would have
     * every effect of that run (audit log, accumulated state) die with the
     * child's copy-on-write memory - the same reason $onToolCall is fired in
     * the parent rather than in {@see invokeTool()}.
     *
     * @param ToolCall[] $toolCalls
     * @return list<array{toolCall: ToolCall, file: ?string, pid: ?int, result: ?ToolResult, hookContext: ?HookContext}>
     */
    private function forkToolCalls(array $toolCalls): array
    {
        $canFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');

        $jobs = [];
        foreach ($toolCalls as $toolCall) {
            [$toolCall, $denied, $hookContext] = $this->gateToolCall($toolCall);

            if ($denied !== null) {
                // A denied call is never forked and never reaches its
                // callback, but still becomes a job carrying an honest error
                // ToolResult under the ORIGINAL call id - so finishToolCalls()
                // replaces beginToolCalls()'s "running" placeholder for it
                // exactly as it does for an executed call, instead of leaving
                // a spinner that never resolves.
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $denied, 'hookContext' => null];
                continue;
            }

            if (!$canFork) {
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext];
                continue;
            }

            $file = sys_get_temp_dir() . '/sc_chat_tool_' . bin2hex(random_bytes(8)) . '.json';
            $pid = pcntl_fork();

            if ($pid === -1) {
                // Fork failed for this call only - run it synchronously right
                // here, same as the no-pcntl fallback.
                $jobs[] = ['toolCall' => $toolCall, 'file' => null, 'pid' => null, 'result' => $this->executeToolSynchronously($toolCall), 'hookContext' => $hookContext];
                continue;
            }

            if ($pid === 0) {
                $this->storeToolResult($file, $toolCall);
                \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
            }

            $jobs[] = ['toolCall' => $toolCall, 'file' => $file, 'pid' => $pid, 'result' => null, 'hookContext' => $hookContext];
        }

        return $jobs;
    }

    /**
     * Run the `PreToolUse` hook chain for ONE Chat-native tool call and
     * report what should happen to it.
     *
     * Deliberately a mirror of {@see Runtime::executeToolCalls()}'s gating,
     * decision for decision, because the whole point of §1 E1 is that a
     * given tool call is treated identically whichever pipeline dispatched
     * it: an unknown tool is reported as unknown WITHOUT consulting hooks
     * (Runtime resolves the tool first and only then builds a HookContext),
     * only a true DENY blocks (a MODIFY is "allowed, with rewritten input",
     * and `isAllowed()` is false for it too), and an unparseable
     * `modifiedInput` falls back to the original arguments.
     *
     * @return array{0: ToolCall, 1: ?ToolResult, 2: ?HookContext} [the call
     *     to execute (arguments rewritten by a MODIFY hook), a pre-resolved
     *     error result when the call was DENIED, the context to hand
     *     `PostToolUse` once the call finishes (null when it will not run)]
     */
    private function gateToolCall(ToolCall $toolCall): array
    {
        if ($this->hooks === null || !isset($this->tools[$toolCall->name])) {
            return [$toolCall, null, null];
        }

        $context = new HookContext(
            sessionId: $this->currentSessionId ?? '',
            toolName: $toolCall->name,
            toolArgs: $toolCall->arguments,
            toolInput: json_encode($toolCall->arguments) ?: '{}',
            toolOutput: '',
            // Chat has no model/provider identity to report: Backend's whole
            // contract is complete(history), so neither ever reaches here.
            // Left empty rather than guessed at - every hook that ships with
            // sugar-crush gates on toolName/toolArgs/toolInput.
            model: '',
            provider: '',
            projectRoot: getcwd() ?: '',
        );

        $hookResult = $this->hooks->preToolUse($context);

        if (!$hookResult->isAllowed() && !$hookResult->isModified()) {
            return [
                $toolCall,
                ToolResult::error($toolCall->name, "Hook denied: {$hookResult->message}", $toolCall->id),
                null,
            ];
        }

        if ($hookResult->isModified()) {
            $decoded = json_decode($hookResult->modifiedInput ?? '', true);
            if (is_array($decoded)) {
                $toolCall = new ToolCall($toolCall->name, $decoded, $toolCall->id);
            }
        }

        return [$toolCall, null, $context];
    }

    /**
     * Run the `PostToolUse` hook chain over a finished tool call's output,
     * in the parent process, and return the result unchanged.
     *
     * Paired with {@see gateToolCall()}: `$context` is null exactly when the
     * pre-hook never allowed the call (no hooks wired, unknown tool, or a
     * DENY), which is also when {@see Runtime} skips its own postToolUse -
     * a call that never ran has no output to observe.
     */
    private function applyPostToolUse(?HookContext $context, ToolResult $result): ToolResult
    {
        if ($context !== null && $this->hooks !== null) {
            $this->hooks->postToolUse($context->withToolOutput($result->result));
        }

        return $result;
    }

    /**
     * Run a tool call that never crossed (or won't cross) a fork boundary -
     * pcntl unavailable, or this specific pcntl_fork() call failed. Safe,
     * and necessary, to fire $onToolCall directly here: there's no child
     * memory for its effects to be lost in (contrast {@see forkToolCalls()}'s
     * docblock on why a genuinely forked job can't do this).
     */
    private function executeToolSynchronously(ToolCall $toolCall): ToolResult
    {
        [$result, $raw, $succeeded] = $this->invokeTool($toolCall);

        if ($succeeded && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $raw);
        }

        return $result;
    }

    /**
     * Run inside a forked child (or synchronously, on fork failure): invoke
     * the real tool callback and write a JSON-safe payload the parent can
     * reconstruct via {@see collectToolResult()}. `raw` is best-effort
     * JSON-round-tripped for the parent's later $onToolCall call - tool
     * callbacks are documented as returning `mixed`, but anything that isn't
     * itself JSON-safe (a resource, a closure) can't survive any IPC
     * mechanism, forked or not, and isn't a realistic tool return value.
     *
     * `imageBytes` is base64-encoded before crossing this JSON-over-temp-file
     * boundary - raw binary (e.g. PNG bytes) is not valid UTF-8 and
     * `json_encode()` would fail/emit null for it otherwise, silently
     * dropping every image-bearing {@see ToolResult} (see {@see
     * ToolResult::okWithImage()}) once a call crosses the default
     * pcntl_fork() path (see W1.G2 reachability fix).
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
                'imageBytes' => $result->imageBytes === null ? null : base64_encode($result->imageBytes),
                'imagePath' => $result->imagePath,
                'imageProtocol' => $result->imageProtocol,
                'diff' => $result->diff,
                'durationMs' => $result->durationMs,
            ],
            'raw' => json_decode(json_encode($raw) ?: 'null', true),
        ];

        $json = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
        file_put_contents($file, $json === false ? '' : $json);
    }

    /**
     * Non-blocking counterpart to the old (removed) waitForToolChildren():
     * resolves once every forked job in $jobs has exited, collecting each
     * one's real result via {@see collectToolResult()} (which fires
     * $onToolCall in the parent - see {@see forkToolCalls()}'s docblock),
     * polling via a periodic timer instead of a blocking usleep() loop so
     * the render/input loop keeps running while tools execute - same
     * rationale as {@see \SugarCraft\Crush\Backend\EngineBackend::completeAsync()}'s
     * fork+socket rewrite, here via WNOHANG polling since these jobs report
     * through temp files, not a socket. A hung tool (e.g. a stuck shell
     * command) is SIGKILLed past {@see PARALLEL_TOOL_TIMEOUT_SECONDS}, same
     * ceiling the old blocking version used. Escape-Escape abort (see
     * Chat::update()) also lands here via $cancellation, same as it does
     * for the backend call.
     *
     * @param list<array{toolCall: ToolCall, file: ?string, pid: ?int, result: ?ToolResult, hookContext: ?HookContext}> $jobs
     * @return PromiseInterface<list<ToolResult>>
     */
    private function waitForToolChildrenAsync(array $jobs, CancellationToken $cancellation): PromiseInterface
    {
        $deferred = new Deferred();

        // PostToolUse runs here, on the parent's side of the fork boundary,
        // for the same reason PreToolUse runs before the fork - see
        // forkToolCalls()'s docblock.
        $collect = fn(array $job): ToolResult => $this->applyPostToolUse(
            $job['hookContext'] ?? null,
            $job['result'] ?? $this->collectToolResult((string) $job['file'], $job['toolCall']),
        );

        $pendingIndexes = [];
        foreach ($jobs as $index => $job) {
            if ($job['pid'] !== null) {
                $pendingIndexes[$index] = true;
            }
        }

        if ($pendingIndexes === []) {
            $deferred->resolve(array_map($collect, $jobs));

            return $deferred->promise();
        }

        $loop = Loop::get();
        $settled = false;
        $timer = null;
        $deadline = microtime(true) + self::PARALLEL_TOOL_TIMEOUT_SECONDS;

        $timer = $loop->addPeriodicTimer(0.05, function () use (&$pendingIndexes, $jobs, $deadline, $cancellation, $collect, $loop, &$settled, &$timer, $deferred): void {
            if ($settled) {
                return;
            }

            foreach ($pendingIndexes as $index => $_) {
                $status = 0;
                if (pcntl_waitpid($jobs[$index]['pid'], $status, WNOHANG) === $jobs[$index]['pid']) {
                    unset($pendingIndexes[$index]);
                }
            }

            $mustStop = $pendingIndexes === [] || microtime(true) >= $deadline || $cancellation->isCancelled();
            if (!$mustStop) {
                return;
            }

            foreach ($pendingIndexes as $index => $_) {
                if (function_exists('posix_kill')) {
                    posix_kill($jobs[$index]['pid'], SIGKILL);
                }
                $status = 0;
                pcntl_waitpid($jobs[$index]['pid'], $status);
            }

            $settled = true;
            $loop->cancelTimer($timer);
            $deferred->resolve(array_map($collect, $jobs));
        });

        return $deferred->promise();
    }

    /**
     * Read + decode + delete a forked child's result file, reconstruct its
     * ToolResult, and fire $onToolCall in THIS (the parent) process when the
     * underlying callback succeeded - see forkToolCalls()'s docblock
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
            isset($r['imageBytes']) && is_string($r['imageBytes']) ? base64_decode($r['imageBytes'], true) ?: null : null,
            isset($r['imagePath']) && is_string($r['imagePath']) ? $r['imagePath'] : null,
            isset($r['imageProtocol']) && is_string($r['imageProtocol']) ? $r['imageProtocol'] : null,
            isset($r['diff']) && is_string($r['diff']) ? $r['diff'] : null,
            isset($r['durationMs']) && is_int($r['durationMs']) ? $r['durationMs'] : null,
        );

        if (($decoded['succeeded'] ?? false) === true && $this->onToolCall !== null) {
            ($this->onToolCall)($toolCall->name, $toolCall->arguments, $decoded['raw'] ?? null);
        }

        return $result;
    }

    /**
     * Returns the full literal frame every call. This used to compute its
     * own cell-level diff (via {@see Buffer}/{@see DiffEncoder}) and return
     * only the changed bytes - but Program (see `bin/sugarcrush`, a plain
     * `new Program(Bootstrap::chat(), ...)`) ALSO diffs whatever a Model's
     * view() returns, line-by-line, against the previous call's return
     * value (candy-core's own Renderer::render(), which every other Model
     * in this framework relies on for exactly this). Chat's pre-diffed
     * cursor-jump escape bytes were never literal display text, so
     * Program's Renderer was diffing one diff's raw bytes against the
     * previous diff's raw bytes as if they were screen content - any time
     * the two differed (i.e. almost always) that produced cursor
     * placement that had no relationship to the actual frame, which is
     * what made typed input / replies appear to land in the wrong row
     * (e.g. the status bar) once a conversation grew past a single frame.
     * Program's Renderer already does correct, safe diffing on real text;
     * doing it a second time here was redundant at best.
     */
    public function view(): string
    {
        return Renderer::render($this);
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
     * Get the shared candy-mosaic probe-once capability instance, if wired
     * (see this class's `$mosaic` constructor docblock, W1.G2/E2).
     */
    public function mosaic(): ?\SugarCraft\Mosaic\Mosaic
    {
        return $this->mosaic;
    }

    /**
     * The hook chain gating this Chat's own tool calls, if wired (see the
     * `$hooks` constructor docblock).
     */
    public function hooks(): ?HookManager
    {
        return $this->hooks;
    }

    /**
     * Gate this Chat's {@see registerTool()} calls through `$hooks`, the
     * same {@see HookManager} the engine pipeline already runs its calls
     * through (crush_feat.md §1 E1).
     *
     * @return self A new Chat with the hook chain attached
     */
    public function withHooks(HookManager $hooks): self
    {
        return $this->mutate(['hooks' => $hooks]);
    }

    /**
     * Tool-call ids whose output the user has expanded (crush_feat.md §1 E5).
     * {@see Renderer::render()} reads this to decide which tool bodies to
     * paint in full; ids absent from the map are collapsed.
     *
     * @return array<string, bool>
     */
    public function expanded(): array
    {
        return $this->expanded;
    }

    /**
     * True when $id's tool output is currently expanded.
     */
    public function isToolOutputExpanded(string $id): bool
    {
        return ($this->expanded[$id] ?? false) === true;
    }

    /**
     * Flip one tool call's collapsed/expanded state. Collapsing REMOVES the
     * key rather than storing false - see the constructor's `$expanded`
     * docblock for why the map only ever holds what the user opened.
     *
     * @return self A new Chat with $id's expansion state flipped
     */
    public function toggleToolOutput(string $id): self
    {
        $expanded = $this->expanded;
        if (($expanded[$id] ?? false) === true) {
            unset($expanded[$id]);
        } else {
            $expanded[$id] = true;
        }

        return $this->mutate(['expanded' => $expanded]);
    }

    /**
     * Ctrl+O's target: every tool-call id carried by the most recent
     * tool-result message in history, or [] when the conversation has none.
     *
     * Chat has no cursor or selection model over history - the transcript is
     * a flat rendered string, not a navigable list - so "the last tool call"
     * is the only unambiguous referent a single keystroke can name, and it is
     * also the one a user pressing Ctrl+O right after a call almost always
     * means. A per-result selector belongs with a real history cursor, which
     * this item does not introduce.
     *
     * @return list<string>
     */
    private function latestToolResultIds(): array
    {
        foreach (array_reverse($this->history) as $msg) {
            if ($msg->toolResults === []) {
                continue;
            }

            $ids = [];
            foreach ($msg->toolResults as $result) {
                $ids[] = $result->id ?? $result->name;
            }

            return $ids;
        }

        return [];
    }

    /**
     * Toggle every id {@see latestToolResultIds()} returns as one unit, so a
     * batch of parallel tool calls opens and closes together instead of
     * needing one keypress each. The batch follows the FIRST id's current
     * state so a half-expanded batch converges rather than inverting into a
     * different half-expanded batch.
     *
     * @return array{0: self, 1: null}
     */
    private function toggleLatestToolOutput(): array
    {
        $ids = $this->latestToolResultIds();
        if ($ids === []) {
            return [$this, null];
        }

        $expand = !$this->isToolOutputExpanded($ids[0]);
        $expanded = $this->expanded;
        foreach ($ids as $id) {
            if ($expand) {
                $expanded[$id] = true;
            } else {
                unset($expanded[$id]);
            }
        }

        return [$this->mutate(['expanded' => $expanded]), null];
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
     * Only constructor-promoted properties are passed through.
     *
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): static
    {
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
            'rows' => $this->rows,
            'cols' => $this->cols,
            'mosaic' => $this->mosaic,
            'hooks' => $this->hooks,
            'expanded' => $this->expanded,
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

        return [$next, $this->scheduleBackendCompletion($next, $cancellation, $generation)];
    }

    /**
     * Build the Cmd that calls the backend with $next's history and
     * dispatches its outcome as an {@see AssistantMsg} stamped with
     * $generation - the common tail {@see submit()} and the tool-call
     * pipeline (see {@see beginToolCalls()}/{@see ToolResultsMsg}) both
     * schedule once their turn's history is settled.
     *
     * Also the point where the backend's `$onEvent` tool-lifecycle seam is
     * consumed (crush_feat.md §1 E1). The callback only QUEUES events: it runs
     * inside the backend, where there is no dispatcher and no way to mutate an
     * immutable Chat, so the queue rides out on the resolved Msg and
     * {@see applyBackendToolEvent()} turns it into transcript states one event
     * at a time. A turn that called no tools resolves to a plain
     * {@see AssistantMsg} exactly as before.
     */
    private function scheduleBackendCompletion(self $next, CancellationToken $cancellation, int $generation): \Closure
    {
        $backend = $next->backend;
        $history = $next->history;
        $onToken = $next->streaming ? $next->onToken : null;

        return Cmd::promise(static function () use ($backend, $history, $onToken, $cancellation, $generation): PromiseInterface {
            /** @var list<ToolStarted|ToolFinished> $events */
            $events = [];
            $onEvent = static function (ToolStarted|ToolFinished $event) use (&$events): void {
                $events[] = $event;
            };

            // Both handlers capture $events BY REFERENCE: the closures are
            // built before the backend has run, so a by-value capture would
            // freeze the queue while it is still empty.
            return $backend->completeAsync($history, $onToken, $cancellation, $onEvent)->then(
                static function (Message $msg) use (&$events, $generation): ?Msg {
                    return $events === []
                        ? new AssistantMsg($msg, $generation)
                        : new BackendToolEventsMsg($events, $msg, $generation);
                },
                static function (\Throwable $e) use (&$events, $generation): ?Msg {
                    // A turn that failed AFTER running tools still shows what
                    // those tools did - otherwise the placeholders queued for
                    // them would be the only trace and they never even render.
                    $message = Message::assistant('_[error: ' . $e->getMessage() . ']_');

                    return $events === []
                        ? new AssistantMsg($message, $generation)
                        : new BackendToolEventsMsg($events, $message, $generation);
                },
            );
        });
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
     * Real terminal row count, from the last {@see WindowSizeMsg} this Chat
     * received - falls back to {@see TuiRenderer::getTerminalSize()}'s own
     * detection only when no WindowSizeMsg has arrived yet (a Chat built
     * directly, e.g. in a test, without going through a real Program).
     */
    public function rows(): int
    {
        return $this->rows ?? TuiRenderer::getTerminalSize()['rows'];
    }

    /** @see rows() */
    public function cols(): int
    {
        return $this->cols ?? TuiRenderer::getTerminalSize()['cols'];
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
