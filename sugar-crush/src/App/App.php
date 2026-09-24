<?php

declare(strict_types=1);

namespace SugarCraft\Crush\App;

use SugarCraft\Core\Cmd as CoreCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg as CoreMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\BackgroundColorMsg;
use SugarCraft\Core\Msg\KeyboardEnhancementsMsg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseClickMsg;
use SugarCraft\Core\Msg\MouseMotionMsg;
use SugarCraft\Core\Msg\MouseMsg;
use SugarCraft\Core\Msg\MouseReleaseMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use SugarCraft\Core\Msg\WindowSizeMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentResult;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\SubAgent;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\InstructionFileLoader;
use SugarCraft\Crush\Context\RulesState;
use SugarCraft\Crush\Memory\MemoryStore;
use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Theme;
use SugarCraft\Crush\Tools\Tool;
use SugarCraft\Layout\Dock\DockLayout;
use SugarCraft\Layout\Dock\Side;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\Commands\CancelCmd;
use SugarCraft\Crush\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Crush\Tui\Commands\NewSessionCmd;
use SugarCraft\Crush\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Crush\Tui\Commands\SourceSkillCmd;
use SugarCraft\Crush\Tui\Components\MenuBar;
use SugarCraft\Crush\Tui\Components\MenuSelectedMsg;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Tui\KeyboardHandler;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Crush\Tui\PaneDragController;
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
        /**
         * The cross-session memory store THIS App's project-scope notes are
         * folded into the system prompt from (crush_code.md Phase 5 item 9).
         *
         * "This App", not "this session": the App a session's TUI holds
         * ({@see \SugarCraft\Crush\Cli\Bootstrap::app()}) never sets this and
         * does not need to, because it does not build prompts. The object that
         * carries a store in a real run is the per-turn App
         * {@see \SugarCraft\Crush\Backend\EngineBackend::complete()} builds, which
         * is where {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} reads it.
         *
         * Carried here for the same reason {@see $instructionLoader} is:
         * {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()} assembles the
         * prompt off this object and nothing else, so a collaborator that
         * contributes to the prompt has to arrive on it. Before this the store
         * was constructed by {@see \SugarCraft\Crush\Cli\Bootstrap::memoryStore()}
         * and reached {@see \SugarCraft\Crush\Chat} only, where `/memory` used
         * it as a CRUD surface — so a note the user had deliberately recorded
         * was never shown to the model that was supposed to act on it.
         *
         * Null leaves the prompt exactly as it was, which is what an App built
         * by a test or an embedder gets.
         */
        public readonly ?MemoryStore $memoryStore = null,
        /**
         * The rule packs THIS SESSION turned off, which the rules splice subtracts
         * from what the loader found (P6.S3).
         *
         * Carried for the same reason {@see $memoryStore} and
         * {@see $instructionLoader} are: {@see \SugarCraft\Crush\Runtime::buildSystemPrompt()}
         * assembles the prompt off this object and nothing else, so a fact that
         * changes the prompt has to arrive on it.
         *
         * It is a reference to one shared object rather than a copied value —
         * which is unlike every other field here — because the writer is
         * {@see \SugarCraft\Crush\Chat} (the `/rules` command) and the reader is
         * {@see \SugarCraft\Crush\Backend\EngineBackend} building THIS App fresh on
         * every turn. A value field would have to be re-pushed into the backend on
         * each toggle as well, and the two copies disagreeing is a silent bug: the
         * symptom is a prompt carrying a pack the user just switched off. The
         * reasoning, including what stays immutable despite this, is on
         * {@see \SugarCraft\Crush\Context\RulesState}.
         *
         * Null leaves the prompt exactly as it was: no pack is turned off. That is
         * what every App gets whose builder never heard of rulebooks — a test, an
         * embedder, and the per-turn App of a launch that wired no rules state.
         */
        public readonly ?RulesState $rulesState = null,
        /**
         * Which sidebar panes are docked into the frame, on which side, in
         * which order, and with what column/stack weights (pane-docking
         * phase 2). Null resolves to {@see App::defaultDock()} — the shape
         * every App gets whose builder never touched the dock, which is every
         * App but the one {@see \SugarCraft\Crush\Cli\Bootstrap::app()} loads
         * from the `layout` setting.
         *
         * The dock is the SOURCE OF TRUTH for side, stack order and size of
         * docked panes. Focus ({@see $pane}) stays a separate axis: a focused
         * sidebar pane that is not docked renders TRANSIENTLY on its home
         * side, exactly as today's single-pane-per-side sidebar did, so the
         * default frame is unchanged whether or not anyone has ever docked
         * anything.
         */
        public readonly ?DockLayout $dock = null,
        /**
         * Persistence hook for dock mutations, shaped exactly like
         * {@see \SugarCraft\Crush\Chat}'s `$onConfigChange`: invoked with the
         * manifest array ({@see DockLayout::toArray()}) whenever a model-level
         * mutation changes the dock. Null in every App that was not launched
         * by the CLI, so tests and embedders mutate freely without writing.
         */
        public readonly ?\Closure $onLayoutChange = null,
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
            memoryStore: null,
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

    public function withMemoryStore(?MemoryStore $v): self
    {
        return $this->mutate(memoryStore: $v);
    }

    /**
     * The session's rulebook toggle set, forwarded to the rules splice.
     *
     * NOTE for anyone tempted to reach for this with a plain array: the operand is
     * one shared object, and `mutate()` carries the reference. A frozen App that
     * holds it therefore sees a toggle made after this App was built — which is
     * the behaviour `/rules` exists to have, and is why the set is not copied in
     * here the way {@see withEnabledSkills()} copies its list. See
     * {@see \SugarCraft\Crush\Context\RulesState} for the two-owners argument.
     */
    public function withRulesState(?RulesState $v): self
    {
        return $this->mutate(rulesState: $v);
    }

    /**
     * The docked-pane layout in force, with the default folded in.
     *
     * The DEFAULT reproduces the pre-docking frame exactly: `files` docked
     * left — the pane `Tui\Renderer::leftSidebar()` has always painted when
     * nothing else was on — and nothing docked right, where today's sidebar
     * only ever renders while its pane has focus.
     */
    public function dock(): DockLayout
    {
        return $this->dock ?? self::defaultDock();
    }

    /**
     * The launch-default dock: Files left, right side empty.
     */
    public static function defaultDock(): DockLayout
    {
        return DockLayout::new('chat')->withSlotAdded(Side::Left, 'files');
    }

    /**
     * Whether a dock is byte-for-byte the untouched {@see defaultDock()} —
     * same slots AND same design shares. The renderer's width rule keys on
     * this (see {@see \SugarCraft\Crush\Tui\Renderer::sideWidth()}): while
     * nothing has ever been docked or resized, the frame keeps the exact
     * legacy quarter-measure it shipped with for a decade of snapshot pins.
     */
    public static function isUntouchedDefaultDock(DockLayout $dock): bool
    {
        return $dock->toArray() === self::defaultDock()->toArray();
    }

    /**
     * Snapshot the frame the eye last saw into the dock's column shares,
     * exactly once — at the moment the untouched default first acquires a
     * side's worth of slots.
     *
     * The default dock carries the library's 1/3 design shares, but the
     * shipped frame measures a sidebar as `max(20, floor(bandCols / 4))`.
     * Those disagree for most widths (100 columns: 25 vs 33), so the FIRST
     * user dock/undock mutation seeds each side that carries slots at the
     * mutation or is the docking target with the rational `(legacyWidth,
     * bandCols)` — the frame therefore starts where it visually was and
     * every later resize scales that proportion instead of snapping back to
     * a quarter or a third. A side being stripped keeps its snapshot because
     * a later re-add scales it (DockSeedTest pins that toggle-off behaviour),
     * and the carried share is render-inert meanwhile: resolve activates only
     * sides that have slots (candy-layout DockLayout.php:370-375).
     * A dock that is already non-default returns unchanged: seeding is a
     * one-time hand-off from the legacy measure, never a re-snapshot on
     * top of sizes the user has chosen.
     *
     * The write goes through the manifest parse rather than
     * {@see DockLayout::withColumnShare()} on purpose: withColumnShare
     * clamps the pair to 1/2 against the UNTOUCHED 1/3 sibling, which would
     * squash a 1/4 snapshot to 1/6 — a snapshot of drawn width is a
     * measurement, not the tightening request the clamp exists to police,
     * and the manifest path (the restore path, where exact carried values
     * are law) preserves the rational. Two seeded sides at 1/4 + 1/4 still
     * honour the 1/2 pair rule.
     *
     * `$bandCols` arrives as the App's last-known terminal width — before
     * the first `WindowSizeMsg` there is no measured frame to preserve, so
     * `0` (null cols) seeds nothing. The renderer's agent-split band can be
     * narrower than that whole width; the seeded value is a PROPORTION, so
     * the difference is one divider column's worth of scale, never a jump.
     * FORWARD POINTER for the gesture phase: any divider drag must size
     * against the LIVE bandCols the pointer lives in, re-evaluating this
     * seeding under an active agent split — the ±divider-column delta is
     * exactly where a whole-width measure would drift from the grabbed
     * column.
     */
    public static function seedSharesFromFrame(DockLayout $dock, int $bandCols, ?Side $docksInto = null): DockLayout
    {
        if ($bandCols < 1 || !self::isUntouchedDefaultDock($dock)) {
            return $dock;
        }

        $share = [max(20, intdiv($bandCols, 4)), $bandCols];
        $manifest = $dock->toArray();

        foreach (Side::cases() as $side) {
            if ($dock->slots($side) !== [] || $side === $docksInto) {
                $manifest['columnShare'][$side === Side::Left ? 'left' : 'right'] = $share;
            }
        }

        return DockLayout::fromArray($manifest);
    }

    public function withDock(?DockLayout $v): self
    {
        return $this->mutate(dock: $v);
    }

    public function withOnLayoutChange(?\Closure $v): self
    {
        return $this->mutate(onLayoutChange: $v);
    }

    /**
     * Dock `pane` on `side` — moving it there when it already sits in a slot.
     *
     * Not routed through `update()`: the dock changes when the GESTURE phase
     * (or the `/pane dock` command shipped this phase) asks for a specific
     * placement, and a message carrying a Side would name the layout enum
     * inside the message taxonomy. `Pane::dockSide()` refuses the panes with
     * no sidebar to speak of; Chat, Input, Help and Menu have no home column
     * and no renderer that would honour one, so an attempt is a programming
     * error, not a no-op.
     *
     * Persists through {@see $onLayoutChange} like every other mutation
     * entry point here — see {@see togglePaneDocking()} for why that is safe.
     * Seeds the drawn frame's sizes into otherwise-untouched default shares
     * on the way out — see {@see seedSharesFromFrame()}.
     *
     * `$index` is where a DROP lands in the side's slot stack — the gesture
     * phase computes it from the release row; null appends, which is what
     * every pre-gesture caller (and the command surface) wants. A concrete
     * index is clamped into the destination side's legal range here (see
     * {@see clampDropIndex()}) so a mouse gesture can never drive the dock's
     * fail-fast insertion guard.
     */
    public function setPaneSide(Pane $pane, Side $side, ?int $index = null): self
    {
        if (!$pane->dockable()) {
            throw new \InvalidArgumentException(
                'Pane ' . $pane->value . ' has no sidebar of its own, so it cannot be docked.',
            );
        }

        $dock = self::seedSharesFromFrame($this->dock(), $this->cols ?? 0, $side);
        $drop = $this->clampDropIndex($index, $dock, $pane, $side);
        $next = $this->dockIsOccupied($dock, $pane)
            ? $dock->withSlotMovedTo($pane->value, $side, $drop)
            : $dock->withSlotAdded($side, $pane->value, $drop);

        return $this->persistDock($this->mutate(dock: $next));
    }

    /**
     * Clamp a gesture-computed drop index into the target side's legal
     * insertion range, so a mouse release can never trip the dock library's
     * fail-fast `guardInsertion`.
     *
     * The pointer maps the release ROW onto a stack index using the side's
     * PAINTED slot tops — which, on a same-side reorder, still include the
     * dragged pane. But `withSlotMovedTo` removes the pane from its side
     * BEFORE re-splicing, so a same-side drop's valid range is one shorter
     * than the painted top count, and a drop released past the last slot asks
     * for exactly the index that removal makes illegal. A cross-side drop (or
     * an add onto an unoccupied side) keeps the full `0..count` range: the
     * dragged pane never sat in the destination's list.
     *
     * A null index means "append" and is every non-gesture caller's spelling,
     * so it passes straight through — the library's own `?? count` already
     * lands it at the safe end. The clamp never raises and never reorders a
     * legal drop; it only folds an overflowing pointer request onto the
     * nearest legal slot, honouring the pointer's intent (drop to the end).
     *
     * @param ?int             $index the raw painted-row index, or null
     * @param DockLayout       $dock  the destination dock (post-seed)
     * @param Pane             $pane  the pane being moved or added
     * @param Side             $side  the side the drop lands on
     */
    private function clampDropIndex(?int $index, DockLayout $dock, Pane $pane, Side $side): ?int
    {
        if ($index === null) {
            return null;
        }

        $count = count($dock->slots($side));

        // Same-side reorder: the dragged pane vacates a slot, so the deepest
        // legal insertion is one above the painted-top count.
        foreach ($dock->slots($side) as $slot) {
            if ($slot->paneId === $pane->value) {
                $count = max(0, $count - 1);

                break;
            }
        }

        return max(0, min($index, $count));
    }

    /**
     * Dock `pane` on its home side, or undock it when it sits in any slot.
     *
     * Undocking a FOCUSED pane also drops focus to Chat: the focused pane
     * renders transiently on its home side, so leaving it focused would make
     * the undock look inert — the pane the user just sent away would stay
     * painted.
     */
    public function togglePaneDocking(Pane $pane): self
    {
        if (!$pane->dockable()) {
            throw new \InvalidArgumentException(
                'Pane ' . $pane->value . ' has no sidebar of its own, so it cannot be docked.',
            );
        }

        if ($this->dockIsOccupied($this->dock(), $pane)) {
            $dock = self::seedSharesFromFrame($this->dock(), $this->cols ?? 0);
            $next = $dock->withSlotRemoved($pane->value);

            return $this->persistDock($this->mutate(
                dock: $next,
                pane: $this->pane === $pane ? Pane::Chat : $this->pane,
            ));
        }

        $side = $pane->dockSide();
        assert($side !== null); // guarded by dockable() above

        $dock = self::seedSharesFromFrame($this->dock(), $this->cols ?? 0, $side);

        return $this->persistDock($this->mutate(dock: $dock->withSlotAdded($side, $pane->value)));
    }

    /**
     * Return the dock to the launch default ({@see defaultDock()}).
     */
    public function layoutReset(): self
    {
        return $this->persistDock($this->mutate(dock: self::defaultDock()));
    }

    /**
     * Whether `pane` currently occupies a dock slot on either side.
     *
     * Public because the renderer's transient-focus rule needs it: a focused
     * pane that is already docked must not be painted twice on its side.
     */
    public function isDocked(Pane $pane): bool
    {
        return $this->dockIsOccupied($this->dock(), $pane);
    }

    private function dockIsOccupied(DockLayout $dock, Pane $pane): bool
    {
        foreach ([Side::Left, Side::Right] as $side) {
            foreach ($dock->slots($side) as $slot) {
                if ($slot->paneId === $pane->value) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The panes Tab can focus, in visual order: Chat, then the left column's
     * slots top-to-bottom, then the right column's.
     *
     * Dock-scoped by construction — an undocked pane is not in this list, so
     * the cycle only visits panes the frame persistently shows. The menu-bar
     * labels are the door for everything else: clicking one docks the pane
     * and focuses it ({@see dispatchChromeClick()}), which admits it to this
     * cycle. A malformed manifest id is skipped rather than fatal — focus
     * cannot name a pane the enum does not have.
     *
     * @return list<Pane>
     */
    public function paneCycleOrder(): array
    {
        $order = [Pane::Chat];

        foreach ([Side::Left, Side::Right] as $side) {
            foreach ($this->dock()->slots($side) as $slot) {
                $pane = Pane::tryFrom((string) $slot->paneId);

                if ($pane !== null && !in_array($pane, $order, true)) {
                    $order[] = $pane;
                }
            }
        }

        return $order;
    }

    /**
     * Move focus one step along {@see paneCycleOrder()} — +1 forward,
     * -1 backward, wrapping at both ends. The spelling is direction, not
     * delta: the caller says which way the user reached, the sign carries it.
     *
     * A focus that is not in the cycle — an undocked pane reached by a ctrl
     * chord, or one of the chrome-only panes — folds to Chat in BOTH
     * directions. That is the same anchor rule {@see \SugarCraft\Crush\Tui\Pane::step()}
     * set for off-ring panes, and for the same reason: the user is parked
     * somewhere the frame does not persistently show and reached for Tab to
     * get out; Chat is the pane that always draws.
     *
     * Focus is not layout: pure {@see withPane()}, no `persistDock` — the
     * dock manifest is unchanged by where the keyboard sits.
     */
    public function cyclePaneFocus(int $direction): self
    {
        $order = $this->paneCycleOrder();
        $index = array_search($this->pane, $order, true);

        if (!is_int($index)) {
            return $this->withPane(Pane::Chat);
        }

        $count = count($order);
        $step = $direction <=> 0;

        return $this->withPane($order[((($index + $step) % $count) + $count) % $count]);
    }

    /**
     * Hand the new manifest to the persistence hook, when the launch wired
     * one. Mirrors Chat's config-change call sites: a non-`update()` model
     * method invoking an injected persistence closure is the established shape
     * here, and the App holding the closure is the mutated COPY (readonly),
     * whose hook is the same object the builder installed.
     */
    private function persistDock(self $next): self
    {
        $hook = $next->onLayoutChange;
        if ($hook !== null) {
            // __invoke, not call(): Bootstrap wires a STATIC closure, and an
            // attempted rebind of a static closure is a silently-skipped
            // warning, not a call — the persistence would never fire.
            $hook->__invoke($next->dock()->toArray());
        }

        return $next;
    }

    /**
     * The colour theme the SHELL's chrome paints with.
     *
     * Delegated to the hosted {@see Chat} rather than stored here, because the
     * theme name is Chat state ({@see Chat::withThemeName()}, persisted by
     * `/theme` and reloaded by {@see \SugarCraft\Crush\Cli\Bootstrap}) and a
     * second copy on App would be a second source of truth that `/theme` does
     * not update — the shell would keep painting the old palette around a
     * re-themed transcript.
     *
     * {@see Theme::default()} covers the chat-less App: the shell is
     * constructible without a hosted chat (every `App::new()` in the suite, and
     * the split/agent-pane paths), and in that shape nothing has recorded a
     * theme preference at all.
     */
    public function theme(): Theme
    {
        return $this->chat?->theme() ?? Theme::default();
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
     *
     * The skill body is delivered through {@see Agent::systemPrompt()} rather
     * than as a bare CompleteRequest::$systemPrompt, so the payload this method
     * hands out is now correct and both of {@see ProcessExecutor}'s send sites
     * agree about it — see the note at the capture below for why the block is
     * taken at $root and at the fork's own model.
     *
     * No fork is oriented by it YET, and the correct tense here is future. This
     * method has no production CALLER: `src/` and `bin/` mention it in prose
     * only — cross-references from {@see applySkillsToSystemPrompt()} and from
     * {@see ProcessExecutor}'s own doc-blocks — and nothing invokes it; the only
     * caller anywhere is `tests/App/AppSkillDispatchTest.php`.
     *
     * ⚠️ A COUNT USED TO STAND HERE — "finds this definition and ONE docblock
     * cross-reference". It was true when written and false by the end of the
     * same round: the change that wrote the sentence added three more mentions,
     * making it eight occurrences in two files. It is dropped rather than
     * corrected, because a number no test derives rots whether or not anyone
     * mis-typed it, and the half that is load-bearing — that nothing CALLS this
     * — is pinned by a token-stream tripwire in
     * {@see \SugarCraft\Crush\Tests\App\AppSkillDispatchTest} instead.
     *
     * ## WHAT THIS USED TO SAY ABOUT THE EXECUTOR
     *
     * "The executor it would reach, ProcessExecutor::createInlineWorkerScript(),
     * is still the Phase-1 simulation — it reads `$agentConfig['name']` and the
     * task, and consumes neither `agent.prompt` nor `request.systemPrompt`."
     *
     * ## WHAT IS TRUE NOW
     *
     * That is no longer the executor this would reach. The shipped default is
     * {@see ProcessExecutor::createLiveWorkerScript()}, which constructs a real
     * `ProviderInterface` in the child and DOES consume both fields — the
     * orientation this method spent so much care assembling now has a consumer
     * at the far end. The simulation survives, but only behind an explicit
     * `simulatedWorker: true`, and nothing in `src/` passes it.
     *
     * What ALSO changed is what a call would do: with no `workerProvider`
     * configured — and nothing in `src/` configures one — the live worker
     * refuses with an `error` frame rather than answering, so a dispatch today
     * returns a FAILED AgentResult naming the absent provider. That is a
     * better position than the previous one, not a worse one: the old path
     * returned a fabricated success, which is indistinguishable from a real
     * one.
     *
     * ## WHY IT IS STILL DORMANT — THE THREE BLOCKERS, MEASURED
     *
     * Not intention, mechanism. Each of these is a fact about this class as it
     * stands, and each would have to be answered by whatever wires this up:
     *
     *  1. **No pool can reach it.** `App` has no `AgentWorkerPool` field, no
     *     constructor parameter and no `with*()` for one — which is exactly why
     *     this method takes the pool as an ARGUMENT. `Bootstrap` builds a pool
     *     for {@see \SugarCraft\Crush\Chat}, not for the shell.
     *  2. **No task exists at the only moment a user selects a skill.**
     *     {@see SelectSkillMsg} carries a skill NAME and nothing else; the third
     *     parameter here is the task the fork is oriented by, and at picker-Enter
     *     time the user has not typed one. A caller has to come from somewhere a
     *     prompt already exists.
     *  3. **`executeOne()` is synchronous, and `update()` must not block.**
     *     {@see handleSelectSkill()} runs inside the TEA update path;
     *     `AgentWorkerPool::executeOne()` blocks until the worker finishes, and
     *     {@see ProcessExecutor}'s default ceiling is 300 seconds. Calling this
     *     from `update()` would freeze the interface for the fork's whole life.
     *
     * MEASURED consequence of leaving that gap unstated, and the reason
     * {@see handleSelectSkill()} now says something different for these skills:
     * a `context: fork` skill IS user-invocable, so it appears in the picker,
     * and selecting it used to report `Enabled skill 'x'.` while
     * {@see applySkillsToSystemPrompt()} skipped it — the skill contributed
     * nothing to the prompt, nothing dispatched it, and the user was told it
     * had been enabled. Logged as §C8 in
     * docs/plans/crush_code_hardening_backlog.md, alongside §C4 for the
     * executor.
     *
     * Worth naming, because the mistake is easy to repeat: an earlier revision
     * of this comment described the outcome in the present tense while the
     * paragraph below it criticised a DIFFERENT mechanism for never being
     * reached. A fix that is mechanically right can still ship a false
     * sentence about what it accomplishes.
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

        // Attached BEFORE the SubAgent is built, because two different
        // consumers read the orientation back out and they must agree:
        // ProcessExecutor sends the request's systemPrompt AND, separately,
        // `$agent->agent->systemPrompt()`. Handing $skill->content straight to
        // CompleteRequest bypassed Agent::systemPrompt() altogether, so a
        // fork-context skill WOULD run with no cwd, no git state, no platform
        // and no date once anything called this — the same unreached-mechanism
        // defect as the root AGENTS.md-loading gap, resurfaced in a sibling
        // launch path (crush_code.md section 12 finding 4). Subjunctive on
        // purpose: see the method docblock. Nothing calls dispatchSkill() in
        // production yet, so this repairs the payload before the path opens
        // rather than fixing an observed misbehaviour.
        //
        // Captured here rather than left to Agent::systemPrompt()'s
        // last-resort `EnvironmentBlock::capture(getcwd(), ...)` for the two
        // reasons Bootstrap::agentManager() already established for the agent
        // roster:
        //
        //   - At $root, not the process directory. The block renders the
        //     "Working directory"/"Is directory a git repo" lines that orient
        //     the model, and on a `--root candy-shine` run they have to name
        //     the directory the tools are jailed to. The fallback spelling
        //     mirrors Runtime::projectRoot() exactly, including that null
        //     stays distinguishable from "explicitly rooted at getcwd()".
        //   - At THIS agent's model, not the session's. render() writes a
        //     `Model:` line, and a skill may declare its own `model:` in
        //     frontmatter — one shared session-wide instance would stamp the
        //     session's model onto a fork running a different one.
        $agent = $agent->withEnvironment(
            EnvironmentBlock::capture($this->root ?? (getcwd() ?: ''), $agent->model),
        );

        $subAgent = new SubAgent(
            // E329, same family as AgentManager's SubAgent id: a constant
            // literal prefix contributes no cross-process entropy at all.
            id: uniqid('skill_fork_' . getmypid() . '_', true),
            agent: $agent,
            task: $task,
        );

        $request = new CompleteRequest(
            model: $agent->model,
            messages: [
                ['role' => 'user', 'content' => $task],
            ],
            systemPrompt: $agent->systemPrompt(),
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
     * The startup Cmd: ask the terminal what colour it paints on and what
     * keyboard protocol it speaks, batched with whatever the hosted
     * {@see Chat} wants to run.
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
     * The Kitty push (E705) joins it as the second unconditional member: like
     * the background query, it is a property of the terminal, not of the
     * conversation, and unlike a per-frame write it must happen exactly once
     * per program start so the pop in bin/sugarcrush stays balanced.
     *
     * {@see CoreCmd::batch()} drops nulls, so a shell with no hosted chat (or a
     * chat with no startup Cmd of its own) still emits the query and the push.
     */
    public function init(): ?\Closure
    {
        return CoreCmd::batch(
            CoreCmd::requestBackgroundColor(),
            // E705: negotiate the Kitty progressive-keyboard protocol so
            // Shift/Ctrl+Enter arrive as distinguishable KeyMsgs instead of
            // colliding with plain Enter. DISAMBIGUATE only — deliberately NOT
            // REPORT_EVENT_TYPES, which would flood update() with release and
            // repeat frames no arm here consumes, and NOT modifyOtherKeys,
            // which must never ride alongside Kitty (its `CSI 27;2;13u`
            // spelling misparses as text). Non-Kitty terminals ignore the
            // `CSI > 1 u` push bytes and keep sending plain CR, so the
            // degradation path is byte-identical to before this line existed.
            // The matching pop is not here: it lives after `Program::run()`
            // returns in bin/sugarcrush, where it also covers the SIGINT and
            // kill() exits that never let a model emit a Cmd.
            CoreCmd::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE),
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
            $msg instanceof DockPaneMsg,
            $msg instanceof LayoutResetMsg,
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
     * The pane-gesture state machine, parked static for exactly the reason
     * {@see $chromeClickTracker} is: a drag spans several `update()` calls
     * and `App` is immutable, so the live {@see PaneDragController} instance
     * would be discarded with every intermediate copy. Transitions never
     * mutate in place — the controller is a value object and each gesture
     * event swaps in the copy it returned.
     */
    private static ?PaneDragController $paneDrag = null;

    /**
     * The dock as it stood when the gesture was armed. A resize PREVIEW rides
     * the model between motion events (that is what makes it visible on the
     * next repaint), so cancelling mid-drag has to put the shares back — the
     * snapshot is the only record of "before", because `App` itself is
     * immutable and every previewed frame is already the newest one.
     *
     * @see $paneDrag
     */
    private static ?DockLayout $paneDragOrigin = null;

    /** @see $paneDrag */
    public static function paneDragController(): PaneDragController
    {
        return self::$paneDrag ??= PaneDragController::idle();
    }

    /**
     * Drop any gesture in flight — the suite's isolation seam (a test that
     * builds frames without driving a full gesture must not inherit one),
     * and the shape Escape takes below.
     */
    public static function resetPaneDragController(): void
    {
        self::$paneDrag = null;
        self::$paneDragOrigin = null;
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
        $drag = self::paneDragController();

        if (!$drag->isIdle()) {
            return $this->advancePaneDrag($drag, $msg);
        }

        $press = $msg instanceof MouseClickMsg;
        $release = $msg instanceof MouseReleaseMsg;

        if ((!$press && !$release) || $msg->button !== MouseButton::Left) {
            return $this->delegateToChat($msg);
        }

        $zone = TuiRenderer::chromeZoneAt($msg->x, $msg->y);

        if ($press && $zone !== null) {
            $started = $this->beginPaneDrag($zone->id, $zone->startCol, $msg->x, $msg->y);

            if ($started !== null) {
                return $started;
            }
        }

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
     * A left press on the chrome asks the gesture phase first: the divider
     * column of a stacked band owns a live resize, a docked pane owns a
     * potential dock drag from EITHER of its header surfaces — the frame
     * header row (`pane:<id>`) and the menu-bar tab (L2's
     * {@see MenuBar::PANE_TAB_ZONE_PREFIX}, the live-report fix: the bar tab
     * is the header the eye reaches first, and until it armed the gesture a
     * press-drag-release there was a cancelled click with no visible answer)
     * — and an intra-stack gap row is consumed as a no-op (its height drag
     * is the documented follow-up on {@see PaneDragController}). Anything
     * else — including a tab of an UNDOCKED pane (its click still docks on
     * the home side; grabbing it to move would strand a pane the pointer
     * never saw docked) or a pane header that is only TRANSIENTLY focused —
     * returns null so the press continues down the plain click path it
     * always took.
     *
     * A resize press is fully swallowed (never fed to the chrome tracker):
     * the gesture owns the whole sequence from here, and a tracker left
     * holding the press could pair a later stray release against a zone that
     * has since re-rendered elsewhere. A dock-drag press deliberately does
     * NOT swallow — it arms the controller and falls through — because an
     * UNARMED release must complete exactly the click the tracker would have
     * completed before this phase existed: focus on a frame header, the
     * dock toggle on a bar tab.
     *
     * @return ?array{0: self, 1: ?\Closure}
     */
    private function beginPaneDrag(string $zoneId, int $startCol, int $pressX, int $pressY): ?array
    {
        $dividers = Renderer::DIVIDER_ZONE_PREFIX;

        if (str_starts_with($zoneId, $dividers)) {
            $side = self::sideFromZoneId(substr($zoneId, strlen($dividers)));

            if ($side === null) {
                return null;
            }

            self::$paneDrag = self::paneDragController()->beginResize($side, $startCol);
            self::$paneDragOrigin = $this->dock();

            return [$this, null];
        }

        if (str_starts_with($zoneId, Renderer::STACK_DIVIDER_ZONE_PREFIX)) {
            // Consumed, no state: pressing a gap row today did nothing
            // either (dispatchChromeClick fell through), so the frame's only
            // observable change is that the press never arms App's own
            // `chromeClickTracker` — which is exactly what a drag-in-waiting
            // wants.
            return [$this, null];
        }

        $pane = self::dragTargetPane($zoneId);

        if ($pane !== null && $this->isDocked($pane)) {
            self::$paneDrag = self::paneDragController()->beginDockDrag($pane->value, $pressX, $pressY);
            self::$paneDragOrigin = $this->dock();
        }

        return null;
    }

    /**
     * The pane a pressed chrome zone-id names, when it names one: the pane's
     * frame header ({@see Renderer::PANE_ZONE_PREFIX}) or the menu-bar tab
     * standing for it ({@see MenuBar::PANE_TAB_ZONE_PREFIX}) — the two
     * surfaces where “grab this pane and move it” is a legitimate reading of
     * the press. Any other id (menu title, session tab, divider remainders
     * handled above, a stray prefix that is not a Pane) resolves to null and
     * the press falls through to the plain click path.
     */
    private static function dragTargetPane(string $zoneId): ?Pane
    {
        foreach ([Renderer::PANE_ZONE_PREFIX, MenuBar::PANE_TAB_ZONE_PREFIX] as $prefix) {
            if (str_starts_with($zoneId, $prefix)) {
                return Pane::tryFrom(substr($zoneId, strlen($prefix)));
            }
        }

        return null;
    }

    /**
     * Run one event of an in-flight gesture.
     *
     * Motion previews: a resize mutates the dock state (no persist — see the
     * persist-only-on-release law), and candy-core's periodic repaint makes
     * the new widths visible on the very next frame, measured in the phase
     * plan's step 0. A dock drag's motion only decides arming. Release
     * commits or cancels and clears the state; Escape clears with zero
     * state change. A press mid-drag is consumed (terminals pair every
     * press with a release, so the drag can always end), and the wheel stays
     * the transcript's — scrolling mid-drag is how a user reads what they
     * are about to drop a pane onto.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function advancePaneDrag(PaneDragController $drag, MouseMsg $msg): array
    {
        if ($msg instanceof MouseWheelMsg) {
            return $this->delegateToChat($msg);
        }

        if ($msg instanceof MouseMotionMsg) {
            self::$paneDrag = $drag->withMotion($msg->x, $msg->y);

            if ($drag->isResizing()) {
                return [$this->previewColumnResize($drag, $msg->x), null];
            }

            return [$this, null];
        }

        if (!($msg instanceof MouseReleaseMsg)) {
            return [$this, null];
        }

        if ($drag->isResizing()) {
            // A press-and-release on the divider with no motion in between is
            // a plain click that owns no action: no preview ever rode the
            // model, so nothing is committed, re-rolled, or written to disk.
            if (!$drag->isPreviewed()) {
                self::$paneDrag = null;
                self::$paneDragOrigin = null;

                return [$this, null];
            }

            // A mode-switch mid-drag is the one release that CANNOT measure.
            // Rendering a full-band dashboard/overlay frame nulls
            // Tui\Renderer::$lastDockFrame (see that class's dashboard tail),
            // so the release below lands with no band to re-state the pointer
            // against and `previewColumnResize` takes its frame-null guard,
            // handing the model back untouched. Persisting there would freeze
            // the last previewed width as a manifest without a real
            // measurement — a stray write, so skip it and let the next framed
            // interaction commit. Note this is a FRAME-PRESENCE test, not
            // object identity: an ordinary release ALSO returns the live model
            // by identity (the same-value tail in `previewColumnResize`) and
            // MUST persist, so `$next === $this` cannot tell the two apart.
            $commitMeasured = TuiRenderer::lastDockFrame() !== null;
            $next = $this->previewColumnResize($drag, $msg->x);
            self::$paneDrag = null;
            self::$paneDragOrigin = null;

            if (!$commitMeasured) {
                return [$next, null];
            }

            // Persist once on release: the release re-states the pointer's
            // measurement over the origin snapshot and commits the final width.
            return [$next->persistDock($next), null];
        }

        self::$paneDrag = null;
        self::$paneDragOrigin = null;

        // An unarmed dock drag is a plain click: hand the release back to
        // the normal path (the controller is already cleared above) so the
        // chrome tracker completes the click-to-focus it always completed.
        if (!$drag->isArmed()) {
            return $this->handleShellMouse($msg);
        }

        return [$this->commitDockDrop($drag, $msg->x, $msg->y), null];
    }

    /**
     * Paint the resize preview: the grabbed side takes the width the pointer
     * asks for, sized against the LIVE band the pointer lives in — the
     * forward pointer on {@see seedSharesFromFrame()}, honoured: `App::$cols`
     * would over-count by the agent-split's columns and its divider, so the
     * grabbed cell and the number written would drift apart under a split.
     */
    private function previewColumnResize(PaneDragController $drag, int $releaseX): self
    {
        $frame = TuiRenderer::lastDockFrame();
        $side = $drag->dragSide();

        if ($frame === null || $side === null) {
            return $this;
        }

        $dock = $this->dock();
        $active = 0;

        foreach ([Side::Left, Side::Right] as $candidate) {
            if ($dock->slots($candidate) !== []) {
                $active++;
            }
        }

        // Content-space measurements come from the ORIGIN snapshot, never
        // the live (already-previewed) model: the pointer's travel maps onto
        // the width the band had when the grab happened, so restating the
        // same column — every motion plus the final release — is idempotent
        // instead of compounding. The share is still written onto the live
        // dock; $dock above and $this->dock() are the same object until the
        // first preview lands, after which only the measurement diverges by
        // design.
        $origin = self::$paneDragOrigin ?? $dock;
        $usable = max(1, $frame['bandCols'] - $active * $dock->dividerCols);
        $geometry = $origin->resolve(new \SugarCraft\Layout\Region(0, 0, $frame['bandCols'], $frame['paneRows']));
        $sideSlots = $origin->slots($side);
        $current = $sideSlots === []
            ? $dock->sideMinCols
            : ($geometry->regionFor($sideSlots[0]->paneId)?->width ?? $dock->sideMinCols);
        $centre = $geometry->regionFor($origin->centerPaneId)?->width ?? $dock->centerMinCols;
        $px = $drag->resizeColumns($releaseX, $current, $dock->sideMinCols, $centre - $dock->centerMinCols);

        $share = $dock->columnShare($side);
        if ($share['num'] === $px && $share['denom'] === $usable) {
            return $this;
        }

        return $this->mutate(dock: self::applyMeasuredColumnShare($dock, $side, $px, $usable));
    }

    /**
     * Write the drag's measured band width into the dock.
     *
     * The manifest round-trip is deliberate and follows the exact doctrine of
     * {@see seedSharesFromFrame()}, whose forward pointer this gesture is: a
     * dragged width is a MEASUREMENT the pointer stated, not the tightening
     * request {@see DockLayout::withColumnShare()}'s pair rule exists to
     * police. The pair rule reserves against the sibling's stored share even
     * while that side holds no slots at all — with the untouched 1/3 default
     * it would squash every request past 1/6, snapping the band the user is
     * dragging SMALLER the moment the drag starts. The gesture keeps the
     * centre honest itself: {@see PaneDragController::resizeColumns()} clamps
     * every request to `usable - centerMinCols`, and resolve()'s min-protection
     * and degradation ladder remain the hard floor no write can bypass.
     *
     * @param int $usable the band minus one divider per ACTIVE side — the
     *                    column budget shares actually split at resolve time
     */
    private static function applyMeasuredColumnShare(DockLayout $dock, Side $side, int $px, int $usable): DockLayout
    {
        $manifest = $dock->toArray();
        $manifest['columnShare'][$side === Side::Left ? 'left' : 'right'] = [$px, $usable];

        return DockLayout::fromArray($manifest);
    }

    /**
     * Land a dropped pane: left of the centre means the left band, right of
     * it means the right band, inside it cancels. The slot index counts the
     * drop side's painted slot tops above the release row, so the pane
     * lands where the pointer says within the stack.
     *
     * Tops come from the STORED dock (the renderer's transient-focus slot is
     * not a dock slot and a mid-drag focus change is rarer than a frame
     * tick), which is also what guarantees exactly one dock mutation and one
     * seed pass through {@see setPaneSide()}.
     */
    private function commitDockDrop(PaneDragController $drag, int $releaseX, int $releaseY): self
    {
        $frame = TuiRenderer::lastDockFrame();
        $paneId = $drag->dragPaneId();

        if ($frame === null || $paneId === null) {
            return $this;
        }

        $side = $drag->dockDropSide($releaseX, $frame['centerFrom'], $frame['centerTo']);
        $pane = Pane::tryFrom($paneId);

        if ($side === null || $pane === null || !$pane->dockable() || !$this->isDocked($pane)) {
            return $this;
        }

        // Slot tops in PAINTED space: the header zones the renderer stamped
        // this frame are where the eye actually sees each box begin. The
        // resolve() region rows are the content-layout truth, which drifts
        // from the painted box tops by each box's own decoration and
        // content height — a drop aimed beside the tools box must index
        // against where tools was PAINTED, not where its content region
        // starts. Fall back to the resolve row only if a header zone is
        // somehow absent (clicks were on to press the divider, so they
        // should be on for headers; the fallback keeps the drop total).
        $dock = $this->dock();
        $geometry = $dock->resolve(new \SugarCraft\Layout\Region(0, 0, $frame['bandCols'], $frame['paneRows']));
        $paintedRows = [];

        foreach (TuiRenderer::chromeScanner()->prefixed(Renderer::PANE_ZONE_PREFIX) as $id => $zone) {
            $paintedRows[substr($id, strlen(Renderer::PANE_ZONE_PREFIX))] = $zone->startRow - 1;
        }

        $tops = [];

        foreach ($dock->slots($side) as $slot) {
            if (isset($paintedRows[$slot->paneId])) {
                $tops[] = $paintedRows[$slot->paneId];
                continue;
            }

            $region = $geometry->regionFor($slot->paneId);

            if ($region !== null) {
                $tops[] = $frame['bandTop'] + $region->y;
            }
        }

        return $this->setPaneSide($pane, $side, PaneDragController::insertIndex($releaseY, $tops));
    }

    /**
     * `left` / `right` out of a zone id's remainder (`left:r12`), or null
     * for anything the gesture phase does not own — a malformed remainder
     * declines the drag rather than guessing a side.
     */
    private static function sideFromZoneId(string $remainder): ?Side
    {
        $side = explode(':', $remainder, 2)[0];

        return match ($side) {
            'left' => Side::Left,
            'right' => Side::Right,
            default => null,
        };
    }

    /**
     * Act on a completed click on a chrome zone.
     *
     * Every arm routes into the keyboard's or the dock's own entry point
     * rather than a parallel mouse path: a title click is
     * {@see MenuBar::openMenu()}, the toggle F10 already calls, and a row
     * click is {@see MenuBar::selectItem()}, which moves the same cursor the
     * arrows move and returns the same {@see MenuSelectedMsg} Enter produces
     * — so it is handed to {@see consumeShellCmd()}, the one place that runs
     * it. A pane-header click is the same `withPane` selection the
     * `tab`/`shift+tab` cycle makes, and a menu-bar pane-tab click is the
     * same {@see togglePaneDocking()} the `/pane dock` command calls (see
     * the arm comments for each rule's focus semantics).
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

        // A completed click on a docked pane's header (the drag phase handed
        // it here because the pointer never armed a move) focuses that pane —
        // the same selection `tab`/`shift+tab` cycle, routed through
        // `withPane` exactly as SelectPaneMsg is.
        $headers = Renderer::PANE_ZONE_PREFIX;
        if (str_starts_with($zoneId, $headers)) {
            $pane = Pane::tryFrom(substr($zoneId, strlen($headers)));

            if ($pane !== null && $pane->dockable() && $this->isDocked($pane)) {
                return [$this->withPane($pane), null];
            }

            return [$this, null];
        }

        // A completed click on a menu-bar pane-tab label toggles that pane's
        // docked visibility (docking L2): through togglePaneDocking — the ONE
        // entry point already carrying the seed-shares first-mutation rule
        // and the persist-once law — so a click and a `/pane dock` command
        // are indistinguishable downstream. Docking focuses the pane that
        // just appeared (the eye is where the click was); undocking lets
        // togglePaneDocking's own rule drop focus to Chat when the pane
        // being sent away held it. Chat's tab never toggles: the center
        // column is always visible, so its click is a pure focus move.
        $paneTabs = MenuBar::PANE_TAB_ZONE_PREFIX;
        if (str_starts_with($zoneId, $paneTabs)) {
            $pane = Pane::tryFrom(substr($zoneId, strlen($paneTabs)));

            if ($pane === null || !$pane->dockable()) {
                return $pane === Pane::Chat
                    ? [$this->withPane(Pane::Chat), null]
                    : [$this, null];
            }

            $wasDocked = $this->isDocked($pane);
            $next = $this->togglePaneDocking($pane);

            return [$wasDocked ? $next : $next->withPane($pane), null];
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
        // Escape is the drag's abort key while a gesture is in flight —
        // BEFORE the shell's own bindings, because mid-drag the keystroke
        // means "put the pane back", not "cancel the turn". The press
        // snapshotted the dock and every motion preview rode the model
        // (that preview IS the live feedback candy-core's repaint tick
        // shows), so cancelling hands back the snapshot: zero state change,
        // zero disk write — the release is the only path that persists.
        if (!self::paneDragController()->isIdle() && $msg->type === KeyType::Escape) {
            $origin = self::$paneDragOrigin;
            self::$paneDrag = null;
            self::$paneDragOrigin = null;

            return [$origin === null ? $this : $this->mutate(dock: $origin), null];
        }

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
     * Backspaces alone stopped being enough the moment the draft grew a
     * cursor ({@see Chat::$input}): they delete BEHIND it, so a draft the
     * user had arrowed into the middle of kept its whole tail and the menu
     * command was typed into the gap. The tail is deleted FORWARD instead —
     * one Delete per character after the cursor, both halves keystrokes the
     * draft editor really handles rather than a direct buffer write.
     *
     * @return list<KeyMsg>
     */
    private static function clearInputKeys(Chat $chat): array
    {
        $before = $chat->inputCursorOffset();
        $after = max(0, mb_strlen($chat->inputBuf) - $before);

        return [
            ...array_fill(0, $before, new KeyMsg(KeyType::Backspace)),
            ...array_fill(0, $after, new KeyMsg(KeyType::Delete)),
        ];
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
            $msg instanceof DockPaneMsg => $this->applyDockCommand($msg),
            $msg instanceof LayoutResetMsg => [$this->layoutReset()->withStatus('layout: reset to the launch default'), null],
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
     * Run a `/pane dock` command: parse, move, acknowledge.
     *
     * This is the keyboard twin of the drag drop — the same
     * {@see setPaneSide()}, the same one-mutation seed pass, the same
     * persist-to-`layout` write. The differences are the index (a command
     * has no pointer row to aim by, so the pane appends at the end of the
     * column) and the subject: with no name the FOCUSED pane moves, and a
     * focus that is not dockable is refused in words rather than silently
     * ignored, because the user just asked for something about a pane and
     * the shell knows which pane it meant.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    public function applyDockCommand(DockPaneMsg $msg): array
    {
        $action = strtolower($msg->action);

        if ($action === 'toggle') {
            return $this->applyPaneToggle($msg);
        }

        $side = self::dockSideFromWord($action);

        if ($side === null) {
            return [$this->withError("pane: dock side must be left or right, got '{$msg->action}'"), null];
        }

        $name = $msg->paneName ?? $this->pane->value;

        if ($msg->paneName === null && !$this->pane->dockable()) {
            return [$this->withError('pane: no dockable pane is focused — name one with /pane dock <left|right> <name>'), null];
        }

        $pane = $name !== null ? Pane::tryFrom($name) : null;

        if ($pane === null || !$pane->dockable()) {
            return [$this->withError("pane: '{$name}' is not a dockable pane"), null];
        }

        return [$this->setPaneSide($pane, $side)->withStatus("pane: {$pane->label()} docked {$side->name}"), null];
    }

    /**
     * The `toggle` verb of {@see applyDockCommand()}: dock a pane onto its
     * home side, or free it from whichever slot already holds it — routed to
     * the already-tested {@see togglePaneDocking()}. Same subject rule as the
     * dock arms: no name means the focused pane, a non-dockable focus is
     * refused in words, and an unknown name is named straight back. The
     * status line reports which arm ran, because the gesture has no undock
     * half (releasing centre cancels), so this is the only keyboard way to
     * free a single docked pane short of `/layout reset`.
     *
     * @return array{0: self, 1: ?Cmd}
     */
    private function applyPaneToggle(DockPaneMsg $msg): array
    {
        if ($msg->paneName === null && !$this->pane->dockable()) {
            return [$this->withError('pane: no dockable pane is focused — name one with /pane toggle <name>'), null];
        }

        $name = $msg->paneName ?? $this->pane->value;
        $pane = Pane::tryFrom($name);

        if ($pane === null || !$pane->dockable()) {
            return [$this->withError("pane: '{$name}' is not a dockable pane"), null];
        }

        $verb = $this->isDocked($pane) ? 'undocked' : 'docked';

        return [$this->togglePaneDocking($pane)->withStatus("pane: {$pane->label()} {$verb}"), null];
    }

    /**
     * `left`/`right` (any case) into a {@see Side}, null for anything else —
     * the boundary parse behind {@see applyDockCommand()}.
     */
    private static function dockSideFromWord(string $word): ?Side
    {
        return match (strtolower($word)) {
            'left' => Side::Left,
            'right' => Side::Right,
            default => null,
        };
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

        // E666 (the abandonment half of E12's stand-down): a palette opened
        // in the chat pane and left open across a hand-over to a
        // keyboard-owning view used to feed the fall-through chords into an
        // invisible modal. The first keystroke that would have been
        // swallowed instead CLOSES the palette — through Chat's own Escape
        // arm, the canonical close — and is itself consumed: the next
        // keystroke acts on a clean chat. Design decision (per-keystroke
        // live state over per-transition hooks) and scope guard live at
        // {@see KeyboardHandler::paletteIsAbandoned()}.
        if ($msg instanceof KeyMsg && KeyboardHandler::paletteIsAbandoned($this)) {
            [$closedChat] = $this->chat->update(new KeyMsg(KeyType::Escape));

            return [
                $closedChat instanceof Chat && $closedChat !== $this->chat
                    ? $this->withChat($closedChat)
                    : $this,
                null,
            ];
        }

        // Trackers #83/#85 (E12, stand-down route): the palette chord is
        // Chat's and stays claimed — yielding it measures WORSE (`/model`
        // instead of nothing; see KeyBindingRegistry) — but delivery is
        // withheld while one of the shell's own views owns the keyboard,
        // because those views never paint the overlay (Agents) or never
        // drive it (F10 menu, skill picker). Both doors pass through here:
        // the live chord via handleKey()'s fall-through, and the synthesized
        // one via feedChat()/CommandPaletteCmd.
        if ($msg instanceof KeyMsg && KeyboardHandler::paletteStandsDown($msg, $this)) {
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
        // E682: the composite adopts the abandonment signal. The predicate is
        // E666's, computed for this frame; the flag lives exactly as long as
        // the paint it governs (reset in the `finally`), so a standalone
        // Renderer path can never inherit a stale value.
        Renderer::setPaletteAbandoned(KeyboardHandler::paletteIsAbandoned($this));
        try {
            return TuiRenderer::renderView($this, $this->cols, $this->rows);
        } finally {
            Renderer::setPaletteAbandoned(false);
        }
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

        // A `context: fork` skill is still ENABLED — {@see dispatchSkill()} reads
        // the enabled set, so removing it here would close the seam rather than
        // describe it — but it is not enabled in the sense the plain message
        // means. MEASURED before this branch existed: selecting one reported
        // "Enabled skill 'x'." while applySkillsToSystemPrompt() skipped it and
        // nothing dispatched it, so the skill had NO EFFECT WHATSOEVER and the
        // status bar said otherwise. The three mechanisms that keep it dormant
        // are named on dispatchSkill(); this line's job is only to stop the
        // interface claiming an outcome that does not happen.
        $status = $this->availableSkills->isContextFork($skill->name)
            ? "Skill '{$skill->name}' declares context: fork — enabled, but not inlined, "
                . 'and no fork dispatch is wired yet.'
            : "Enabled skill '{$skill->name}'.";

        $next = $this->mutate(
            enabledSkills: $enabledSkills,
            skillPickerOptions: [],
            skillPickerIndex: 0,
            status: $status,
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
            memoryStore: array_key_exists('memoryStore', $changes) ? $changes['memoryStore'] : $this->memoryStore,
            rulesState: array_key_exists('rulesState', $changes) ? $changes['rulesState'] : $this->rulesState,
            dock: array_key_exists('dock', $changes) ? $changes['dock'] : $this->dock,
            onLayoutChange: array_key_exists('onLayoutChange', $changes) ? $changes['onLayoutChange'] : $this->onLayoutChange,
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

/**
 * Message carrying a `/pane` verb to the shell — the command twin of the
 * {@see \SugarCraft\Crush\Tui\PaneDragController} drop and of the keyboard
 * dock/undock arm. The verb travels as the `left`/`right`/`toggle` word the
 * user typed because `Chat` has no reason to know candy-layout's enum;
 * {@see App::applyDockCommand()} parses it at the boundary and refuses
 * anything else.
 *
 * `paneName` null means "whatever pane currently holds focus", the same
 * subject the mouse gestures take; the focus-not-dockable case is answered
 * with a status line rather than silence.
 */
final readonly class DockPaneMsg implements Msg
{
    public function __construct(public string $action, public ?string $paneName = null) {}
}

/**
 * Message to reset the dock to the launch default — the command twin of the
 * gesture phase's `layout reset`. No payload: the reset target is fixed.
 */
final readonly class LayoutResetMsg implements Msg
{
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
