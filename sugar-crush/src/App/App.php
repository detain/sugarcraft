<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Core\Model;
use SugarCraft\Core\Msg as CoreMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use DateTimeImmutable;

/**
 * Main application state - immutable with*() builders.
 * Mirrors the canonical candy-sprinkles/src/Style.php pattern.
 *
 * Also the root {@see Model} of the pane shell (crush_feat.md §5 E7, the
 * MERGE branch). §5 E7 offers two ways out of the two-parallel-UI-systems
 * drift risk: delete the `App`/`Pane` layer, or switch the app onto it.
 * The merge is what this is: `App` is the SHELL (pane focus, menu bar,
 * agent view mode, status bar) and hosts a {@see Chat} — the CONTENT model
 * that carries every Wave 1/2 feature — as a plain field. Shell-level
 * messages are answered here; everything else is handed straight to `Chat`
 * and the returned model folded back into a new `App`.
 *
 * `Chat` is deliberately untouched by this: it remains a standalone `Model`
 * that `bin/sugarcrush` and ~3,900 tests can drive on its own. Hosting is
 * composition, not absorption.
 */
final class App implements Model
{
    private function __construct(
        public readonly ProviderInterface $provider,
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools,
        public readonly Pane $pane,
        public readonly ?string $error,
        public readonly ?string $status,
        public readonly ?string $sessionId,
        public readonly array $contextFiles,
        public readonly array $enabledSkills,
        public readonly SkillRegistry $availableSkills,
        public readonly array $activeHooks,
        public readonly int $selectedAgentIndex,
        public readonly AgentViewMode $agentViewMode,
        public readonly ?DateTimeImmutable $lastActivityAt = null,
        public readonly array $skillPickerOptions = [],
        public readonly ?InstructionFileLoader $instructionLoader = null,
        /**
         * The hosted content model. Null keeps `App` usable as the plain
         * engine-state object {@see \SugarCraft\Crush\Runtime} and
         * {@see \SugarCraft\Crush\Backend\EngineBackend} already build.
         */
        public readonly ?Chat $chat = null,
        /**
         * Real terminal dimensions, sourced from {@see WindowSizeMsg} — the
         * one size candy-core's Program dispatches at startup AND again on
         * every SIGWINCH resize. Null until the first WindowSizeMsg arrives
         * (or for an App built directly in a test, never), in which case
         * {@see TuiRenderer::getTerminalSize()}'s own detection is the
         * fallback. The shell renderer MUST be handed these rather than
         * querying the terminal itself: that query is cached in a static that
         * is never invalidated, so it would keep drawing the chrome at the
         * boot-time size while the hosted Chat — which does read
         * WindowSizeMsg — draws at the new one.
         */
        public readonly ?int $rows = null,
        public readonly ?int $cols = null,
    ) {}

    public static function new(ProviderInterface $provider, string $model): self
    {
        return new self(
            provider: $provider,
            model: $model,
            messages: [],
            tools: [],
            pane: Pane::Chat,
            error: null,
            status: null,
            sessionId: null,
            contextFiles: [],
            enabledSkills: [],
            availableSkills: new SkillRegistry(),
            activeHooks: [],
            selectedAgentIndex: -1,
            agentViewMode: AgentViewMode::List,
            lastActivityAt: null,
            skillPickerOptions: [],
            instructionLoader: null,
            chat: null,
            rows: null,
            cols: null,
        );
    }

    // Immutable with*() builders
    public function withProvider(ProviderInterface $v): self
    {
        return $this->mutate(provider: $v);
    }

    public function withModel(string $v): self
    {
        return $this->mutate(model: $v);
    }

    public function withMessages(array $v): self
    {
        return $this->mutate(messages: $v);
    }

    public function withTools(array $v): self
    {
        return $this->mutate(tools: $v);
    }

    public function withPane(Pane $v): self
    {
        return $this->mutate(pane: $v);
    }

    public function withError(?string $v): self
    {
        return $this->mutate(error: $v);
    }

    public function withStatus(?string $v): self
    {
        return $this->mutate(status: $v);
    }

    public function withSessionId(?string $v): self
    {
        return $this->mutate(sessionId: $v);
    }

    public function withContextFiles(array $v): self
    {
        return $this->mutate(contextFiles: $v);
    }

    public function withEnabledSkills(array $v): self
    {
        return $this->mutate(enabledSkills: $v);
    }

    public function withAvailableSkills(SkillRegistry $registry): self
    {
        return $this->mutate(availableSkills: $registry);
    }

    public function withActiveHooks(array $v): self
    {
        return $this->mutate(activeHooks: $v);
    }

    public function withSelectedAgentIndex(int $v): self
    {
        return $this->mutate(selectedAgentIndex: $v);
    }

    public function withAgentViewMode(AgentViewMode $v): self
    {
        return $this->mutate(agentViewMode: $v);
    }

    public function withLastActivity(DateTimeImmutable $lastActivityAt): self
    {
        return $this->mutate(lastActivityAt: $lastActivityAt);
    }

    /**
     * @param array<Skill> $v
     */
    public function withSkillPickerOptions(array $v): self
    {
        return $this->mutate(skillPickerOptions: $v);
    }

    /**
     * Attach the session's shared {@see InstructionFileLoader} so
     * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} can fold the
     * repo-root CLAUDE.md/AGENTS.md and the config-driven forced-instruction
     * globs into every system prompt.
     *
     * The loader is deliberately the SAME instance Read/Edit/Glob receive
     * from {@see \SugarCraft\Crush\Cli\Bootstrap::tools()}: loadForPath()'s
     * once-per-session dedup map lives on the instance, so a second loader
     * would give the on-touch path a different notion of "already injected".
     */
    public function withInstructionLoader(?InstructionFileLoader $v): self
    {
        return $this->mutate(instructionLoader: $v);
    }

    /**
     * Host a {@see Chat} inside the shell (crush_feat.md §5 E7, merge branch).
     *
     * The instance handed in is the SAME model `bin/sugarcrush` would have run
     * standalone — the shell adds layout around it and routes unhandled
     * messages into it, and never copies state out of it.
     */
    public function withChat(?Chat $v): self
    {
        return $this->mutate(chat: $v);
    }

    /**
     * Apply enabled skills to the base system prompt.
     *
     * Skills declared `context: fork` are excluded — they run as isolated
     * sub-agents via dispatchSkill()/AgentWorkerPool::executeOne() rather
     * than being inlined into the primary conversation's system prompt.
     */
    public function applySkillsToSystemPrompt(string $baseSystemPrompt): string
    {
        $result = $baseSystemPrompt;

        foreach ($this->enabledSkills as $skill) {
            if (!$skill instanceof Skill) {
                continue;
            }

            if ($this->availableSkills->isContextFork($skill->name)) {
                continue;
            }

            $result .= $skill->systemPromptContribution();
        }

        return $result;
    }

    /**
     * Find skills that match a task description.
     *
     * @return array<Skill>
     */
    public function findSkillsForTask(string $task): array
    {
        return $this->availableSkills->findForPrompt($task);
    }

    /**
     * Filter to the registry's user-invocable skills.
     *
     * A skill authored with `user-invocable: false` is excluded from
     * whatever list is passed through this filter, and remains reachable
     * only via auto-invocation or direct programmatic enable().
     *
     * This is consumed by the real command surface below: dispatching
     * OpenSkillPickerMsg through update() populates $skillPickerOptions
     * with exactly this filtered list, and SelectSkillMsg re-validates
     * against it before enabling a skill. `SkillsPane` renders the picker
     * whenever $skillPickerOptions is non-empty (Mirrors
     * charmbracelet/crush SourceSkillCmd's skill list). Physical keypress
     * reachability (wiring `KeyboardHandler`'s `SourceSkillCmd` into a
     * running main loop so pressing the real key emits OpenSkillPickerMsg)
     * is a pre-existing, repo-wide gap that predates this item — no `Cmd`
     * type is consumed by any main loop anywhere in `src/` yet, not
     * something this item introduced or narrowed the scope of. The
     * Model-layer command surface itself (this method, the two Msg
     * handlers, and the picker render) is real and covered by tests.
     *
     * @return array<Skill>
     */
    public function userInvocableSkills(): array
    {
        return $this->availableSkills->getUserInvocable();
    }

    /**
     * Dispatch a matched skill according to its declared execution context.
     *
     * Skills declaring `context: fork` must run isolated from the main
     * conversation: this runs them through AgentWorkerPool::executeOne() as
     * a standalone SubAgent and returns only the finished result, instead of
     * inlining the skill's content into the primary thread's system prompt.
     *
     * Returns null for anything that is not a fork-context skill so the
     * caller can distinguish "not handled here — fall back to the normal
     * inline path via applySkillsToSystemPrompt()" from "handled, here is
     * the result".
     */
    public function dispatchSkill(Skill $skill, AgentWorkerPool $pool, string $task): ?AgentResult
    {
        if (!$this->availableSkills->isContextFork($skill->name)) {
            return null;
        }

        $agent = new Agent(
            name: $skill->name,
            description: $skill->description,
            prompt: $skill->content,
            model: $skill->model ?? $this->model,
            provider: $this->provider->name(),
            tools: [],
            skillNames: [$skill->name],
            hooks: [],
            isActive: true,
        );

        $subAgent = new SubAgent(
            id: uniqid('skill_fork_'),
            agent: $agent,
            task: $task,
        );

        $request = new CompleteRequest(
            model: $agent->model,
            messages: [
                ['role' => 'user', 'content' => $task],
            ],
            systemPrompt: $skill->content,
        );

        return $pool->executeOne($subAgent, $request);
    }

    /**
     * Add a message to the conversation.
     */
    public function withMessage(Message $msg): self
    {
        return $this->mutate(messages: [...$this->messages, $msg]);
    }

    /**
     * The startup Cmd, delegated to the hosted {@see Chat}.
     *
     * The shell itself has no startup side effect: its whole initial state is
     * already in the constructor. Without a hosted chat there is nothing to
     * run, which is why this is null rather than a no-op closure.
     */
    public function init(): ?\Closure
    {
        return $this->chat?->init();
    }

    /**
     * Update the state from a message.
     * Returns [newApp, command] where command is a Cmd to execute or null.
     *
     * Shell-level messages are answered FIRST — pane selection, the skill
     * picker, and the engine's own user-input/tool-result/error/status
     * traffic. Anything left over belongs to the content model and is handed
     * to {@see Chat::update()}, whose returned model is folded back into a
     * new `App` and whose Cmd is passed straight through untouched (the Cmd
     * is a `Closure(): ?Msg` the Program runs; re-wrapping it here would
     * change when it runs).
     *
     * The parameter widened from this file's own `Msg` to candy-core's:
     * implementing {@see Model} requires accepting every Msg the Program can
     * deliver — KeyMsg, MouseMsg, WindowSizeMsg — not just this namespace's.
     * `Msg` now extends the core marker, so every existing caller still
     * type-checks.
     *
     * Agent-view transitions (list/peek/attach) are shell-level too but do
     * not arrive as a Msg: {@see \SugarCraft\Crush\Tui\KeyboardHandler}
     * applies them to the App directly. Routing that handler ahead of this
     * delegation is W3.M3's step, not this one.
     *
     * Element 1 is always `null` or a `\Closure` — never this namespace's
     * {@see Cmd}. See {@see dispatch()} for why, and for where the engine's
     * Cmd objects are still reachable.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function update(CoreMsg $msg): array
    {
        return match (true) {
            $msg instanceof WindowSizeMsg => $this->handleWindowSize($msg),
            $msg instanceof UserInputMsg,
            $msg instanceof SelectPaneMsg,
            $msg instanceof ToolResultMsg,
            $msg instanceof ErrorMsg,
            $msg instanceof StatusMsg,
            $msg instanceof OpenSkillPickerMsg,
            $msg instanceof SelectSkillMsg => self::withoutEngineCmd($this->dispatch($msg)),
            default => $this->delegateToChat($msg),
        };
    }

    /**
     * The engine-facing half of {@see update()}: answer a shell/engine message
     * and report the {@see Cmd} it asks for.
     *
     * This is deliberately NOT on the Model path. candy-core's
     * `Program::scheduleCmd()` is declared `private function
     * scheduleCmd(\Closure $cmd)` and is called for every non-null second
     * tuple element, so returning one of this namespace's plain `Cmd` objects
     * from `update()` would raise a TypeError and kill the loop the first time
     * a user typed. `Cmd` is a declarative instruction for an engine driver,
     * not a `Closure(): ?Msg` the Program can run, and wrapping it in a closure
     * would only hide that — there is nothing for the closure to do.
     *
     * Disclosure: no production caller drives this yet. Nothing in `src/` or
     * `bin/` constructs a `UserInputMsg`/`ToolResultMsg` today — the engine
     * loop in {@see \SugarCraft\Crush\Runtime} runs completions directly — so
     * this is exactly as reachable as the arms were before they moved here,
     * neither more nor less. Wiring a driver onto it is a later step.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    public function dispatch(Msg $msg): array
    {
        return match (true) {
            $msg instanceof UserInputMsg => $this->handleUserInput($msg),
            $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
            $msg instanceof ToolResultMsg => $this->handleToolResult($msg),
            $msg instanceof ErrorMsg => [$this->withError($msg->message), null],
            $msg instanceof StatusMsg => [$this->withStatus($msg->message), null],
            $msg instanceof OpenSkillPickerMsg => $this->handleOpenSkillPicker(),
            $msg instanceof SelectSkillMsg => $this->handleSelectSkill($msg),
            default => [$this, null],
        };
    }

    /**
     * Drop a {@see dispatch()} result's engine Cmd so the tuple satisfies the
     * {@see Model} contract. @see dispatch() for why it cannot be forwarded.
     *
     * @param array{0: self, 1: ?Cmd} $handled
     * @return array{0: self, 1: null}
     */
    private static function withoutEngineCmd(array $handled): array
    {
        return [$handled[0], null];
    }

    /**
     * Record the authoritative terminal size AND pass it on to the hosted chat.
     *
     * Both halves of the frame have to learn about a resize: the shell sizes
     * its chrome from {@see $rows}/{@see $cols} and the hosted Chat sizes its
     * content from its own copy, and a frame built from two different notions
     * of "how big is the terminal" is the row-collision this whole size
     * plumbing exists to prevent.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleWindowSize(WindowSizeMsg $msg): array
    {
        [$next, $cmd] = $this->delegateToChat($msg);

        return [$next->mutate(rows: $msg->rows, cols: $msg->cols), $cmd];
    }

    /**
     * Hand a non-shell message to the hosted {@see Chat}.
     *
     * Returns `$this` unchanged — not a fresh clone — when the chat answered
     * with the identical instance, so a no-op keystroke does not churn the
     * App identity that tests and the renderer compare against.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function delegateToChat(CoreMsg $msg): array
    {
        if ($this->chat === null) {
            return [$this, null];
        }

        [$next, $cmd] = $this->chat->update($msg);

        if (!$next instanceof Chat || $next === $this->chat) {
            return [$this, $cmd];
        }

        return [$this->withChat($next), $cmd];
    }

    /**
     * Render the whole shell: menu bar, sidebars, chat pane, input, status.
     *
     * {@see TuiRenderer::renderView()} is the pane compositor; the chat pane it
     * lays out delegates its body to the live
     * {@see \SugarCraft\Crush\Renderer} against {@see $chat}, so the shell
     * frames the content model's real output rather than a second, drifting
     * transcript renderer.
     *
     * A {@see View} rather than a plain string because the hosted chat's frame
     * can carry image markers: an image-bearing tool result leaves a
     * Private-Use-Area marker cell in the body, and only the placements riding
     * along on the View let `Program::renderFrame()` paint the blob and blank
     * the marker. Returning the body alone would paint nothing and emit the
     * raw marker bytes to the terminal.
     *
     * The size is passed down explicitly — see {@see $rows}.
     */
    public function view(): string|View
    {
        return TuiRenderer::renderView($this, $this->cols, $this->rows);
    }

    /**
     * Subscriptions the Program should pump, delegated to the hosted chat.
     *
     * The shell declares none of its own, so a hosted `Chat` keeps whatever
     * polling it declares standalone instead of losing it to the wrapper.
     */
    public function subscriptions(): ?Subscriptions
    {
        return $this->chat?->subscriptions();
    }

    /**
     * Open the skill picker: switch to the Skills pane and populate
     * $skillPickerOptions with the user-invocable skill list. Mirrors
     * charmbracelet/crush SourceSkillCmd.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleOpenSkillPicker(): array
    {
        $options = $this->userInvocableSkills();
        $next = $this->withPane(Pane::Skills)->withSkillPickerOptions($options)->withError(null);

        if ($options === []) {
            $next = $next->withStatus('No user-invocable skills are registered.');
        }

        return [$next, null];
    }

    /**
     * Select a skill from an open picker by name, enabling it for the
     * conversation. Re-validates against the user-invocable filter rather
     * than trusting the caller-supplied name, so a skill that opted out of
     * user invocation can never be enabled through this path even if the
     * picker's own options were bypassed.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleSelectSkill(SelectSkillMsg $msg): array
    {
        $skill = null;
        foreach ($this->userInvocableSkills() as $candidate) {
            if ($candidate->name === $msg->skillName) {
                $skill = $candidate;
                break;
            }
        }

        if ($skill === null) {
            return [$this->withError("Skill '{$msg->skillName}' is not user-invocable or does not exist."), null];
        }

        $alreadyEnabled = false;
        foreach ($this->enabledSkills as $enabled) {
            if ($enabled instanceof Skill && $enabled->name === $skill->name) {
                $alreadyEnabled = true;
                break;
            }
        }
        $enabledSkills = $alreadyEnabled ? $this->enabledSkills : [...$this->enabledSkills, $skill];

        $next = $this->mutate(
            enabledSkills: $enabledSkills,
            skillPickerOptions: [],
            status: "Enabled skill '{$skill->name}'.",
            error: null,
        );

        return [$next, null];
    }

    /**
     * Handle user input message.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleUserInput(UserInputMsg $msg): array
    {
        $userMsg = new UserMessage($msg->content);
        // A real user prompt is the activity signal Runtime::shouldPromptIdleCompaction()
        // measures idle time against - without this, lastActivityAt only ever got set
        // from test code and every session looked idle forever.
        $newApp = $this->withMessage($userMsg)->withLastActivity(new DateTimeImmutable());
        // The actual AI call happens in the runtime loop
        return [$newApp, new RunCompletionCmd($userMsg)];
    }

    /**
     * Handle tool result message.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function handleToolResult(ToolResultMsg $msg): array
    {
        $toolMsg = new ToolResultMessage($msg->toolCallId, $msg->content, $msg->isError);

        return [$this->withMessage($toolMsg), null];
    }

    /**
     * Rebuild the immutable App with the named changes applied.
     *
     * Uses array_key_exists (not ??) so that nullable fields — error,
     * status, sessionId — can be reset to null. A readonly property
     * cannot be reassigned after construction, so we always go through
     * the constructor rather than clone-and-mutate.
     */
    private function mutate(mixed ...$changes): self
    {
        return new self(
            provider: array_key_exists('provider', $changes) ? $changes['provider'] : $this->provider,
            model: array_key_exists('model', $changes) ? $changes['model'] : $this->model,
            messages: array_key_exists('messages', $changes) ? $changes['messages'] : $this->messages,
            tools: array_key_exists('tools', $changes) ? $changes['tools'] : $this->tools,
            pane: array_key_exists('pane', $changes) ? $changes['pane'] : $this->pane,
            error: array_key_exists('error', $changes) ? $changes['error'] : $this->error,
            status: array_key_exists('status', $changes) ? $changes['status'] : $this->status,
            sessionId: array_key_exists('sessionId', $changes) ? $changes['sessionId'] : $this->sessionId,
            contextFiles: array_key_exists('contextFiles', $changes) ? $changes['contextFiles'] : $this->contextFiles,
            enabledSkills: array_key_exists('enabledSkills', $changes) ? $changes['enabledSkills'] : $this->enabledSkills,
            availableSkills: array_key_exists('availableSkills', $changes) ? $changes['availableSkills'] : $this->availableSkills,
            activeHooks: array_key_exists('activeHooks', $changes) ? $changes['activeHooks'] : $this->activeHooks,
            selectedAgentIndex: array_key_exists('selectedAgentIndex', $changes) ? $changes['selectedAgentIndex'] : $this->selectedAgentIndex,
            agentViewMode: array_key_exists('agentViewMode', $changes) ? $changes['agentViewMode'] : $this->agentViewMode,
            lastActivityAt: array_key_exists('lastActivityAt', $changes) ? $changes['lastActivityAt'] : $this->lastActivityAt,
            skillPickerOptions: array_key_exists('skillPickerOptions', $changes) ? $changes['skillPickerOptions'] : $this->skillPickerOptions,
            instructionLoader: array_key_exists('instructionLoader', $changes) ? $changes['instructionLoader'] : $this->instructionLoader,
            chat: array_key_exists('chat', $changes) ? $changes['chat'] : $this->chat,
            rows: array_key_exists('rows', $changes) ? $changes['rows'] : $this->rows,
            cols: array_key_exists('cols', $changes) ? $changes['cols'] : $this->cols,
        );
    }
}

/**
 * Marker for this namespace's shell/engine messages.
 *
 * Extends candy-core's marker so {@see App::update()} can satisfy the
 * {@see Model} contract (which accepts any core Msg) while every existing
 * `App\*Msg` still reaches its own arm.
 */
interface Msg extends CoreMsg {}

/**
 * Message from user input.
 */
final readonly class UserInputMsg implements Msg
{
    public function __construct(public string $content) {}
}

/**
 * Message to select a pane.
 */
final readonly class SelectPaneMsg implements Msg
{
    public function __construct(public Pane $pane) {}
}

/**
 * Message containing tool execution result.
 */
final readonly class ToolResultMsg implements Msg
{
    public function __construct(
        public string $toolCallId,
        public string $content,
        public bool $isError = false,
    ) {}
}

/**
 * Error message.
 */
final readonly class ErrorMsg implements Msg
{
    public function __construct(public string $message) {}
}

/**
 * Status update message.
 */
final readonly class StatusMsg implements Msg
{
    public function __construct(public string $message) {}
}

/**
 * Open the user-invocable skill picker. Mirrors charmbracelet/crush
 * SourceSkillCmd — emitted once the keyboard layer is wired to dispatch it.
 */
final readonly class OpenSkillPickerMsg implements Msg
{
}

/**
 * Select and enable a skill by name from an open picker.
 */
final readonly class SelectSkillMsg implements Msg
{
    public function __construct(public string $skillName) {}
}

// Cmd types (side-effects to execute)
interface Cmd {}

/**
 * Command to run completion.
 */
final readonly class RunCompletionCmd implements Cmd
{
    public function __construct(public Message $userMessage) {}
}

/**
 * Command to call a tool.
 */
final readonly class CallToolCmd implements Cmd
{
    public function __construct(public string $toolName, public array $args) {}
}
