<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Core\Cmd as CoreCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg as CoreMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
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
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\Renderer as TuiRenderer;
use SugarCraft\Crush\Tui\TerminalBackground;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\ZoneClickTracker;
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
        /**
         * Zero-based cursor into {@see $skillPickerOptions}. Without it the
         * picker could be opened but never moved through, so Enter had no row
         * to commit — the "skills pane cannot be selected from" gap.
         */
        public readonly int $skillPickerIndex = 0,
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
        /**
         * The project root this session was pointed at — `--root`'s value as
         * {@see \SugarCraft\Crush\Cli\Bootstrap::app()} resolved it — or null
         * when the App was built without one (a test, an embedder).
         *
         * Deliberately NOT pre-resolved to `getcwd()` here: null has to stay
         * distinguishable from "explicitly rooted at the process directory",
         * so consumers spell the fallback themselves as `$root ?? getcwd()`.
         *
         * This exists because `--root` used to reach only the TOOLS. A
         * `sugarcrush --root candy-shine` jailed Bash/Read/Edit/Glob to that
         * library while {@see \SugarCraft\Crush\Runtime}'s environment block
         * and hook contexts still reported the whole monorepo — so the model
         * reasoned about one repository while acting inside another, which is
         * worse than either being wrong on its own (crush_code.md Phase 0
         * item 6).
         */
        public readonly ?string $root = null,
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
            skillPickerIndex: 0,
            instructionLoader: null,
            chat: null,
            rows: null,
            cols: null,
            root: null,
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
     * Move the skill picker's cursor. Clamped to the option list rather than
     * trusting the caller, so an index can never point past the last row and
     * make Enter select nothing.
     */
    public function withSkillPickerIndex(int $v): self
    {
        $max = count($this->skillPickerOptions) - 1;

        return $this->mutate(skillPickerIndex: $max < 0 ? 0 : max(0, min($v, $max)));
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
     * Point this App at a project root — see {@see $root}.
     *
     * Accepts null so an embedder can explicitly clear an inherited root and
     * fall back to the process directory, matching every other nullable
     * field on this class.
     */
    public function withRoot(?string $v): self
    {
        return $this->mutate(root: $v);
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
     * charmbracelet/crush SourceSkillCmd's skill list).
     *
     * Physical keypress reachability is now real: Ctrl+S produces a
     * `SourceSkillCmd`, {@see consumeShellCmd()} turns it into
     * OpenSkillPickerMsg, and {@see KeyboardHandler::handleSkillPickerKey()}
     * moves the cursor and turns Enter into a SelectSkillMsg.
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
     * The startup Cmd: ask the terminal what colour it paints on, batched with
     * whatever the hosted {@see Chat} wants to run.
     *
     * The OSC 11 query is the shell's one genuine startup side effect. It is
     * asked once, here, because the answer is a property of the terminal this
     * process is attached to rather than of any one conversation — see
     * {@see TerminalBackground} for why the reply is memoised per-process
     * instead of being carried as model state. The reply comes back
     * asynchronously as a {@see BackgroundColorMsg}, handled in
     * {@see update()}; until it lands the `adaptive` theme runs on the
     * environment guess, which is why this is fire-and-forget rather than
     * something the first frame waits on.
     *
     * {@see CoreCmd::batch()} drops nulls, so a shell with no hosted chat (or a
     * chat with no startup Cmd of its own) still emits just the query.
     */
    public function init(): ?\Closure
    {
        return CoreCmd::batch(
            CoreCmd::requestBackgroundColor(),
            $this->chat?->init(),
        );
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
     * Keypresses are the third case, because agent-view transitions
     * (list/peek/attach) and pane focus are shell-level but never arrive as a
     * Msg — {@see KeyboardHandler} applies them to the App directly. A
     * {@see KeyMsg} therefore goes to {@see dispatchKey()} FIRST, and falls
     * through to the hosted chat untouched whenever the shell does not claim
     * it. That fallthrough is load-bearing: the palette, the "/" menu,
     * Ctrl+O, Escape-to-cancel, history recall and the mouse chain all live
     * on `Chat`, and a shell that answered every key would swallow them.
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
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof MouseMsg => $this->handleShellMouse($msg),
            $msg instanceof BackgroundColorMsg => $this->observeBackground($msg),
            default => $this->delegateToChat($msg),
        };
    }

    /**
     * Record the terminal's OSC 11 answer, then hand the message on unchanged.
     *
     * A separate method rather than an arm body because a `match` arm is an
     * expression and {@see TerminalBackground::observe()} returns void; this is
     * the void-swallowing wrapper that lets the arm still evaluate to the
     * fall-through result.
     *
     * The message is NOT consumed. Every other arm above CLAIMS its message —
     * it is the shell's to answer and the hosted chat never sees it. This one
     * does not: it observes a fact in passing and hands the message on, because
     * nothing on `Chat` answers a {@see BackgroundColorMsg} today but the shell
     * has no claim on it either, and swallowing it would make a later consumer
     * on the content model silently unreachable.
     *
     * That fall-through has no RUNTIME observable, and the pin for it is
     * therefore structural — see
     * {@see \SugarCraft\Crush\Tests\App\AppModelTest::testTheBackgroundColorArmHandsTheMessageOnRatherThanConsumingIt()}.
     * {@see Chat} is `final`, so no stub can be hosted in its place, and
     * `Chat::update()` returns `[$this, null]` for every message it does not
     * claim — byte-identical to what consuming the message here would return.
     * A test that drives `update()` cannot tell the two apart, so the delegation
     * is asserted on the method body instead. If `Chat` ever grows a consumer
     * for this message, replace that structural assertion with the behavioural
     * one it is standing in for.
     *
     * No re-render is forced: the answer is read through
     * {@see TerminalBackground::isDark()} on every theme resolution, so the
     * next frame the Program paints for any other reason already uses it.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function observeBackground(BackgroundColorMsg $msg): array
    {
        TerminalBackground::observe($msg);

        return $this->delegateToChat($msg);
    }

    /**
     * Press/Release pairing state for clicks on the shell's CHROME.
     *
     * Static for exactly the reason {@see Chat::clickTracker()} is: a click
     * spans two `update()` calls and `App` is immutable, so a field would be
     * discarded with the intermediate instance and no click could ever
     * complete. A tracker of its own rather than Chat's, because the two
     * registries are in different coordinate spaces (see
     * {@see TuiRenderer::chromeZoneAt()}) and the tracker re-tests the
     * PRESS's recorded box against the release event — one tracker fed boxes
     * from both spaces would reject every pair from one of them.
     */
    private static ?ZoneClickTracker $chromeClickTracker = null;

    /** @see $chromeClickTracker */
    public static function chromeClickTracker(): ZoneClickTracker
    {
        return self::$chromeClickTracker ??= new ZoneClickTracker();
    }

    /**
     * Click-to-open a menu title and click-to-run a dropdown row
     * (crush_feat.md §8's click-to-select pattern, applied to the one surface
     * its E-list never reached — the user report is "clicking the menu up top
     * with a mouse doesnt work").
     *
     * The shell gets first refusal on a left press/release, mirroring the
     * priority routing §8 C documents in charmbracelet/crush (chrome and
     * dialogs absorb mouse events before the chat sees them). Everything else
     * — wheel, motion, other buttons, and any left click that lands outside
     * the chrome — falls through to the hosted {@see Chat}, which owns the
     * transcript's own zones, its scroll offset and the §8 E8 drag-versus-
     * selection tolerance.
     *
     * A press that DID land on chrome is swallowed even though it dispatches
     * nothing on its own: forwarding it would arm Chat's tracker with a press
     * the matching release will never reach it, and the menu bar is not part
     * of the frame Chat's zones were recorded against anyway.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleShellMouse(MouseMsg $msg): array
    {
        $press = $msg instanceof MouseClickMsg;
        $release = $msg instanceof MouseReleaseMsg;

        if ((!$press && !$release) || $msg->button !== MouseButton::Left) {
            return $this->delegateToChat($msg);
        }

        $zone = TuiRenderer::chromeZoneAt($msg->x, $msg->y);
        $event = $press
            ? MouseEvent::press($msg->x, $msg->y)
            : MouseEvent::release($msg->x, $msg->y);

        $click = self::chromeClickTracker()->track($event, $zone);

        if ($click === null) {
            return $zone === null ? $this->delegateToChat($msg) : [$this, null];
        }

        return $this->dispatchChromeClick($click->zone->id);
    }

    /**
     * Act on a completed click on a chrome zone.
     *
     * Both arms route into the keyboard's own entry points rather than a
     * parallel mouse path: a title click is {@see MenuBar::openMenu()}, the
     * toggle F10 already calls, and a row click is
     * {@see MenuBar::selectItem()}, which moves the same cursor the arrows
     * move and returns the same {@see MenuSelectedMsg} Enter produces — so it
     * is handed to {@see consumeShellCmd()}, the one place that runs it.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchChromeClick(string $zoneId): array
    {
        $titles = MenuBar::MENU_TITLE_ZONE_PREFIX;
        if (str_starts_with($zoneId, $titles)) {
            MenuBar::openMenu((int) substr($zoneId, strlen($titles)));

            return [$this, null];
        }

        $items = MenuBar::MENU_ITEM_ZONE_PREFIX;
        if (str_starts_with($zoneId, $items)) {
            $selected = MenuBar::selectItem((int) substr($zoneId, strlen($items)));

            return $selected === null ? [$this, null] : $this->consumeShellCmd($selected);
        }

        return [$this, null];
    }

    /**
     * Offer a keypress to the pane shell, falling through to the hosted chat.
     *
     * The shell's command object is no longer dropped here: it is handed to
     * {@see consumeShellCmd()}, which TRANSLATES it into shell state changes
     * or into keystrokes the hosted {@see Chat} already answers. It is still
     * never returned: a `KeyCmd`/`MenuSelectedMsg` is a declarative
     * instruction object, not the `Closure(): ?Msg` candy-core's
     * `Program::scheduleCmd()` accepts, so returning one would TypeError and
     * kill the loop.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleKey(KeyMsg $msg): array
    {
        $handled = $this->dispatchKey($msg);

        if ($handled === null) {
            return $this->delegateToChat($msg);
        }

        [$next, $cmd] = $handled;

        return $cmd === null ? [$next, null] : $next->consumeShellCmd($cmd);
    }

    /**
     * Act on a command object the shell's keyboard layer produced.
     *
     * This is the fix for the systemic gap {@see dispatchKey()} used to
     * disclose ("the returned Cmd is inert today"): every command below now
     * has a real effect, expressed either as shell state or — for the
     * bindings whose behaviour lives on the content model — as the exact
     * keystrokes the hosted {@see Chat} already binds. Translation, not
     * pass-through: see {@see handleKey()} for why the object itself can
     * never be returned to the Program.
     *
     * Still deliberately inert, and honestly so:
     * {@see \SugarCraft\Crush\Tui\Commands\GroupInputCmd},
     * {@see \SugarCraft\Crush\Tui\Commands\CancelAgentCmd},
     * {@see \SugarCraft\Crush\Tui\Commands\ResumeAgentCmd},
     * {@see \SugarCraft\Crush\Tui\Commands\StopAllAgentsCmd} and
     * {@see \SugarCraft\Crush\Tui\Commands\QuitAgentViewCmd}. The first has no
     * counterpart anywhere in the live app to translate INTO, and the agent
     * four would have to reach into a worker pool the shell does not hold —
     * their pane/selection half is already applied by
     * {@see KeyboardHandler::handleAgentViewKey()}. Inventing a consumer for
     * them here would be a fabricated call path, not a fix.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function consumeShellCmd(object $cmd): array
    {
        return match (true) {
            // The keyboard layer speaks this namespace's own messages for the
            // skill picker, so route them through the same arm update() uses.
            $cmd instanceof Msg => self::withoutEngineCmd($this->dispatch($cmd)),
            $cmd instanceof SourceSkillCmd => self::withoutEngineCmd($this->dispatch(new OpenSkillPickerMsg())),
            $cmd instanceof MenuSelectedMsg => $this->dispatchMenuSelection($cmd),
            $cmd instanceof CommandPaletteCmd => $this->feedChat([self::ctrl('p')]),
            $cmd instanceof NewSessionCmd => $this->runRegistryCommand('new'),
            $cmd instanceof ProviderSelectCmd => $this->runRegistryCommand('model'),
            // Chat's Escape is the live "cancel the in-flight turn" binding.
            $cmd instanceof CancelCmd => $this->feedChat([new KeyMsg(KeyType::Escape)]),
            default => [$this, null],
        };
    }

    /**
     * Run the command a menu row names.
     *
     * The row label came from {@see \SugarCraft\Crush\Commands\CommandRegistry},
     * so it maps back to exactly one {@see CommandSpec} — which is what makes
     * "Enter dispatches the command it names" possible at all. Selecting also
     * closes the menu: leaving it open would keep the shell swallowing every
     * subsequent keypress while the command it launched runs.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchMenuSelection(MenuSelectedMsg $selected): array
    {
        MenuBar::closeMenu();

        if ($selected->item === '') {
            return [$this, null];
        }

        foreach (CommandRegistry::all() as $spec) {
            if ($spec->label() === $selected->item) {
                return $this->runRegistryCommand($spec->name);
            }
        }

        return [$this->withError("No command matches menu item '{$selected->item}'."), null];
    }

    /**
     * Dispatch a registry command through the hosted {@see Chat}.
     *
     * Chat::submit()'s own `str_starts_with()` chain is the single source of
     * truth for what a command does, so the shell drives it rather than
     * growing a second dispatcher — but only for rows the registry marks
     * `slashVisible`. A row flagged `slashVisible: false` is one that chain
     * has NO branch for (CommandRegistry's own docblock says so): submitting
     * its text would send a prompt to the model instead of running anything.
     * Those rows carry a palette action, so they are driven through Chat's
     * Ctrl+P palette, which does dispatch them.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function runRegistryCommand(string $name): array
    {
        $spec = null;
        foreach (CommandRegistry::all() as $candidate) {
            if ($candidate->name === $name) {
                $spec = $candidate;
                break;
            }
        }

        if ($spec === null) {
            return [$this->withError("Unknown command '{$name}'."), null];
        }

        if ($this->chat === null) {
            return [$this->withStatus("No chat is hosted — '{$name}' was not dispatched."), null];
        }

        $keys = $spec->slashVisible
            ? [...self::clearInputKeys($this->chat), ...self::typeKeys('/' . $spec->name)]
            : [self::ctrl('p'), ...self::typeKeys($spec->label())];
        $keys[] = new KeyMsg(KeyType::Enter);

        return $this->feedChat($keys);
    }

    /**
     * Backspaces enough to empty the chat's draft.
     *
     * A menu command is typed into the same input buffer the user's draft
     * lives in, so it has to start from empty or "/compact" appended to a
     * half-written sentence would be submitted as prose.
     *
     * @return list<KeyMsg>
     */
    private static function clearInputKeys(Chat $chat): array
    {
        return array_fill(0, mb_strlen($chat->inputBuf), new KeyMsg(KeyType::Backspace));
    }

    /**
     * The keystrokes that type $text one character at a time.
     *
     * @return list<KeyMsg>
     */
    private static function typeKeys(string $text): array
    {
        $keys = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $keys[] = $char === ' '
                ? new KeyMsg(KeyType::Space)
                : new KeyMsg(KeyType::Char, $char);
        }

        return $keys;
    }

    /** A Ctrl+<rune> chord as the live terminal decoder delivers it. */
    private static function ctrl(string $rune): KeyMsg
    {
        return new KeyMsg(KeyType::Char, $rune, ctrl: true);
    }

    /**
     * Replay a synthesized key sequence into the hosted {@see Chat}.
     *
     * Only the LAST non-null Cmd survives, because only the final Enter can
     * produce one — the typing keystrokes before it are pure state changes,
     * and candy-core's Program takes a single Cmd per update.
     *
     * @param list<KeyMsg> $keys
     * @return array{0: self, 1: ?\Closure}
     */
    private function feedChat(array $keys): array
    {
        $app = $this;
        $cmd = null;
        foreach ($keys as $key) {
            [$app, $next] = $app->delegateToChat($key);
            if ($next !== null) {
                $cmd = $next;
            }
        }

        return [$app, $cmd];
    }

    /**
     * Apply a keypress through {@see KeyboardHandler}, reporting the shell's
     * own {@see \SugarCraft\Crush\Tui\Commands\KeyCmd}.
     *
     * Returns null — not `[$this, null]` — when the shell does not claim the
     * key, so callers can tell "the shell handled it and nothing changed"
     * apart from "this key is not the shell's". {@see update()} relies on
     * that distinction to route unclaimed keys into {@see Chat}.
     *
     * The returned command is no longer inert: {@see handleKey()} hands it to
     * {@see consumeShellCmd()}. This method stays public so tests can assert
     * which command a shell key produces without also running its effect.
     *
     * @return array{0: self, 1: ?object}|null
     */
    public function dispatchKey(KeyMsg $msg): ?array
    {
        return (new KeyboardHandler())->handleKeyMsg($msg, $this);
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
        $next = $this->mutate(
            pane: Pane::Skills,
            skillPickerOptions: $options,
            skillPickerIndex: 0,
            error: null,
        );

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
            skillPickerIndex: 0,
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
            skillPickerIndex: array_key_exists('skillPickerIndex', $changes) ? $changes['skillPickerIndex'] : $this->skillPickerIndex,
            instructionLoader: array_key_exists('instructionLoader', $changes) ? $changes['instructionLoader'] : $this->instructionLoader,
            chat: array_key_exists('chat', $changes) ? $changes['chat'] : $this->chat,
            rows: array_key_exists('rows', $changes) ? $changes['rows'] : $this->rows,
            cols: array_key_exists('cols', $changes) ? $changes['cols'] : $this->cols,
            root: array_key_exists('root', $changes) ? $changes['root'] : $this->root,
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
