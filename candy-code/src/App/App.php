<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Cmd as CoreCmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg as CoreMsg;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\MouseMode;
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
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentResult;
use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\SubAgent;
use SugarCraft\Code\App\Tabs\CloseTabMsg;
use SugarCraft\Code\App\Tabs\CycleTabMsg;
use SugarCraft\Code\App\Tabs\OpenTabMsg;
use SugarCraft\Code\App\Tabs\SessionDeck;
use SugarCraft\Code\App\Tabs\TabScopedMsg;
use SugarCraft\Code\App\Tabs\TabScope;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Commands\CommandRegistry;
use SugarCraft\Code\Commands\KeyBindingRegistry;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\ContextCategory;
use SugarCraft\Code\Context\InstructionFileLoader;
use SugarCraft\Code\Context\RulesState;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Memory\MemoryStore;
use SugarCraft\Code\Messages\Message;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Messages\ToolResultMessage;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\ProviderInterface;
use SugarCraft\Code\Skills\Skill;
use SugarCraft\Code\Skills\SkillFork;
use SugarCraft\Code\Skills\SkillLoader;
use SugarCraft\Code\Skills\SkillManager;
use SugarCraft\Code\Skills\SkillRegistry;
use SugarCraft\Code\Theme;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\ToolResult;
use SugarCraft\Constraints\Dock\DockLayout;
use SugarCraft\Constraints\Dock\Side;
use SugarCraft\Constraints\Region;
use SugarCraft\Code\Tui\AgentViewMode;
use SugarCraft\Code\Tui\Commands\CancelCmd;
use SugarCraft\Code\Tui\Commands\CommandPaletteCmd;
use SugarCraft\Code\Tui\Commands\NewSessionCmd;
use SugarCraft\Code\Tui\Commands\ProviderSelectCmd;
use SugarCraft\Code\Tui\Commands\SourceSkillCmd;
use SugarCraft\Code\Tui\Components\AgentDashboardPane;
use SugarCraft\Code\Tui\Components\ContextPane;
use SugarCraft\Code\Tui\Components\FilesPane;
use SugarCraft\Code\Tui\Components\MenuBar;
use SugarCraft\Code\Tui\Components\MenuSelectedMsg;
use SugarCraft\Code\Tui\Components\SessionsPane;
use SugarCraft\Code\Tui\Components\TodoPane;
use SugarCraft\Code\Tui\Components\ToolsPane;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Tui\KeyboardHandler;
use SugarCraft\Code\Tui\Pane;
use SugarCraft\Code\Tui\PaneDragController;
use SugarCraft\Code\Tui\Renderer as TuiRenderer;
use SugarCraft\Code\Tui\TerminalBackground;
use SugarCraft\Code\Tui\TerminalNotifier;
use SugarCraft\Code\Tui\Window\WindowAttention;
use SugarCraft\Code\Tui\Window\WindowEntry;
use SugarCraft\Code\Tui\Window\WindowList;
use SugarCraft\Code\Tui\Window\WindowRef;
use SugarCraft\Code\Tui\Window\WindowStrip;
use SugarCraft\Code\Tui\Window\WindowViewport;
use SugarCraft\Code\Tui\Window\WindowViewports;
use SugarCraft\Code\Media\MediaRequest;
use SugarCraft\Code\Tui\DiffReview\DiffReview;
use SugarCraft\Code\Tui\DiffReview\DiffReviewAction;
use SugarCraft\Code\Tui\DiffReview\DiffReviewActionKind;
use SugarCraft\Code\Tui\DiffReview\DiffReviewSource;
use SugarCraft\Code\Tui\DiffReview\OpenDiffReviewMsg;
use SugarCraft\Code\Tui\MessagePicker\MessagePicker;
use SugarCraft\Code\Tui\MessagePicker\MessagePickerAction;
use SugarCraft\Code\Tui\MessagePicker\OpenMessagePickerMsg;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizard;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizardAction;
use SugarCraft\Code\Tui\FirstRunWizard\FirstRunWizardActionKind;
use SugarCraft\Code\Tui\FirstRunWizard\OpenFirstRunWizardMsg;
use SugarCraft\Code\Tui\MediaForm\ChoiceFeed;
use SugarCraft\Code\Tui\MediaForm\GenerateFromMediaFormMsg;
use SugarCraft\Code\Tui\MediaForm\ImportPng;
use SugarCraft\Code\Tui\MediaForm\MediaForm;
use SugarCraft\Code\Tui\MediaForm\MediaFormAction;
use SugarCraft\Code\Tui\MediaForm\MediaFormActionKind;
use SugarCraft\Code\Tui\MediaForm\OpenMediaFormMsg;
use SugarCraft\Code\Tui\VideoPlayer\OpenVideoPlayerMsg;
use SugarCraft\Code\Tui\VideoPlayer\PlayerReadyMsg;
use SugarCraft\Code\Tui\VideoPlayer\VideoPlayerKeys;
use SugarCraft\Code\Tui\VideoPlayer\VideoPlayerScreen;
use SugarCraft\Video\Msg\ResizeRebuildMsg as VideoResizeRebuildMsg;
use SugarCraft\Video\Msg\TickMsg as VideoTickMsg;
use SugarCraft\Video\Player as VideoPlayer;
use SugarCraft\Video\Render\Mode as VideoMode;
use SugarCraft\Video\Render\RendererFactory as VideoRendererFactory;
use SugarCraft\Code\Config\Trust\TrustTarget;
use SugarCraft\Code\Config\ProviderKeyStore;
use SugarCraft\Code\Tui\Settings\OpenSettingsMsg;
use SugarCraft\Code\Tui\Settings\SettingsEditor;
use SugarCraft\Code\Tui\Settings\SettingsFileEditedMsg;
use SugarCraft\Code\Tui\Settings\SettingsFileEditor;
use SugarCraft\Code\Tui\Settings\SettingsProfile;
use SugarCraft\Code\Tui\Settings\SettingsProfileMsg;
use SugarCraft\Code\Tui\Settings\SettingsSavedMsg;
use SugarCraft\Code\Tui\TrustDialog;
use SugarCraft\Code\Tui\Settings\SettingsSavePreview;
use SugarCraft\Code\Config\Settings\SettingSource;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Core\Util\AtomicJsonFile;
use SugarCraft\Code\Config\Settings\SettingsSchema;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Config\LayeredSettings;
use SugarCraft\Code\Media\ImageIngress;
use SugarCraft\Code\Support\ClipboardImage;
use SugarCraft\Code\Support\HomeDirectory;
use SugarCraft\Code\Support\MediaStore;
use SugarCraft\Code\Tui\MediaForm\MediaPresets;
use SugarCraft\Code\Tui\Settings\SettingsSources;
use SugarCraft\Image\ImageSource;
use SugarCraft\Mouse\MouseEvent;
use SugarCraft\Mouse\ZoneClickTracker;
use DateTimeImmutable;

/**
 * Main application state - immutable with*() builders.
 * Mirrors the canonical sugar-style/src/Style.php pattern.
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
 * that `bin/candy-code` and ~3,900 tests can drive on its own. Hosting is
 * composition, not absorption.
 */
final class App implements Model
{
    /** Rows a wheel notch scrolls a docked pane — the transcript's own step. */
    private const PANE_WHEEL_ROWS = 3;

    /**
     * How often an open Agent View reads its run's log (P-C2) — about the
     * chat's own 0.1 s pump, but a log only grows a line per tool step.
     */
    public const AGENT_VIEW_TICK_SECONDS = 0.2;

    /** The Agent View's tail tick, keyed per run so a switch cancels the old one. */
    public const AGENT_VIEW_SUBSCRIPTION = 'agent-view:';

    /**
     * How often the idle auto-compaction watch re-derives its verdict
     * (CTX-5). Coarse on purpose: the idle window it polices is an hour by
     * default, so a minute of slop on the fold timing is invisible, while a
     * missed minute after the user's answer is a tick that fires before the
     * first keystroke lands.
     */
    public const IDLE_COMPACT_TICK_SECONDS = 60.0;

    /** The idle watch's tick id — single per app, no keying needed. */
    public const IDLE_COMPACT_SUBSCRIPTION = 'idle-compact';

    /**
     * How long a busy tab's close stays armed for its confirming press
     * (P-TABS.e). Three seconds: long enough to read the status row and
     * press again, short enough that an abandoned arm silently expires
     * rather than silently closing a streaming tab minutes later.
     */
    private const TAB_CLOSE_ARM_SECONDS = 3.0;

    /**
     * Subscription ids a PARKED tab's Chat must never declare (P-TABS.e).
     * The background-poll and schedule-watch ticks both pump the SHARED
     * workspace supervisor (C-TOOL-3.c), so every parked tab declaring them
     * would multiply one poll across the deck; the status line is one
     * bottom-of-screen strip, foreground-only by physics. Their Chat-side
     * ids live in private consts on {@see Chat} — this list names the same
     * strings; {@see TabScopedRoutingTest} pins the behaviour (which ids the
     * deck keeps) independently of the spelling. The notice poll needs no
     * entry: {@see Chat::withNoticeDrain()} already dismisses parked tabs
     * from the one-owner inbox, and its poll rides that flag.
     *
     * @var list<string>
     */
    private const FOREGROUND_ONLY_TAB_SUBSCRIPTIONS = [
        'code.background-poll',
        'code.schedule-watch',
        'code.status-line',
    ];

    /**
     * Log windows read at once when a view opens, so a finished run's whole
     * transcript (up to 4 MB) is on screen at once instead of over the ticks.
     */
    private const AGENT_VIEW_OPEN_READS = 64;

    /** Newest log items the view keeps; a longer run drops its oldest rows. */
    private const AGENT_VIEW_MAX_ITEMS = 4000;

    /** Composer messages remembered for the view's `you → <agent>` rows ({@see $agentMessages}). */
    public const AGENT_MESSAGES_KEPT = 32;

    /** A second cancel of the same runs within this many seconds stops them at once ({@see $agentCancelArmed}). */
    public const AGENT_HARD_CANCEL_SECONDS = 3.0;

    /** A second `s` in the Agents dashboard within this many seconds stops every run ({@see $agentsStopArmedAt}). */
    public const STOP_ALL_ARMED_SECONDS = 3.0;

    /** Consecutive `Ctrl+O` presses within this many seconds walk up the transcript instead of retoggling the newest row ({@see $toolWalkState}). A SETTLED cursor — placed by `Ctrl+F` or a row click — ignores this window entirely. */
    public const TOOL_WALK_WINDOW_SECONDS = 3.0;

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
         * engine-state object {@see \SugarCraft\Code\Runtime} and
         * {@see \SugarCraft\Code\Backend\EngineBackend} already build.
         */
        public readonly ?Chat $chat = null,
        /**
         * Real terminal dimensions, sourced from {@see WindowSizeMsg} — the
         * one size sugar-core's Program dispatches at startup AND again on
         * every SIGWINCH resize. Null until the first WindowSizeMsg arrives
         * (or for an App built directly in a test, never), in which case
         * {@see TuiRenderer::terminalSize()}'s own detection is the
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
         * {@see \SugarCraft\Code\Cli\Bootstrap::app()} resolved it — or null
         * when the App was built without one (a test, an embedder).
         *
         * Deliberately NOT pre-resolved to `getcwd()` here: null has to stay
         * distinguishable from "explicitly rooted at the process directory",
         * so consumers spell the fallback themselves as `$root ?? getcwd()`.
         *
         * This exists because `--root` used to reach only the TOOLS. A
         * `candy-code --root sugar-markdown` jailed Bash/Read/Edit/Glob to that
         * library while {@see \SugarCraft\Code\Runtime}'s environment block
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
         * ({@see \SugarCraft\Code\Cli\Bootstrap::app()}) never sets this and
         * does not need to, because it does not build prompts. The object that
         * carries a store in a real run is the per-turn App
         * {@see \SugarCraft\Code\Backend\EngineBackend::complete()} builds, which
         * is where {@see \SugarCraft\Code\Runtime::buildSystemPrompt()} reads it.
         *
         * Carried here for the same reason {@see $instructionLoader} is:
         * {@see \SugarCraft\Code\Runtime::buildSystemPrompt()} assembles the
         * prompt off this object and nothing else, so a collaborator that
         * contributes to the prompt has to arrive on it. Before this the store
         * was constructed by {@see \SugarCraft\Code\Cli\Bootstrap::memoryStore()}
         * and reached {@see \SugarCraft\Code\Chat} only, where `/memory` used
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
         * {@see $instructionLoader} are: {@see \SugarCraft\Code\Runtime::buildSystemPrompt()}
         * assembles the prompt off this object and nothing else, so a fact that
         * changes the prompt has to arrive on it.
         *
         * It is a reference to one shared object rather than a copied value —
         * which is unlike every other field here — because the writer is
         * {@see \SugarCraft\Code\Chat} (the `/rules` command) and the reader is
         * {@see \SugarCraft\Code\Backend\EngineBackend} building THIS App fresh on
         * every turn. A value field would have to be re-pushed into the backend on
         * each toggle as well, and the two copies disagreeing is a silent bug: the
         * symptom is a prompt carrying a pack the user just switched off. The
         * reasoning, including what stays immutable despite this, is on
         * {@see \SugarCraft\Code\Context\RulesState}.
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
         * App but the one {@see \SugarCraft\Code\Cli\Bootstrap::app()} loads
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
         * {@see \SugarCraft\Code\Chat}'s `$onConfigChange`: invoked with the
         * manifest array ({@see DockLayout::toArray()}) whenever a model-level
         * mutation changes the dock. Null in every App that was not launched
         * by the CLI, so tests and embedders mutate freely without writing.
         */
        public readonly ?\Closure $onLayoutChange = null,
        /**
         * The skill budgets {@see \SugarCraft\Code\Runtime::buildSystemPrompt()}
         * holds enabled skill bodies to (audit 15d-09 residual R1). Null is
         * {@see CompactorConfig::new()}'s defaults — the figures every build
         * used while the App had no field to carry any other, so an App that
         * never sets one builds the byte-identical prompt it always did.
         * Carried here for the reason {@see $instructionLoader} is: the
         * Runtime reads the prompt's inputs off the App it is handed.
         */
        public readonly ?CompactorConfig $compactorConfig = null,
        /**
         * What has been pruned from the model's view of {@see $messages}
         * (roadmap 2.2-1): {@see \SugarCraft\Code\Runtime::buildMessages()}
         * projects the rows through it on every request. Null — every App
         * outside an engine turn that has pruned — sends the rows as they are.
         * Carried here for the reason {@see $compactorConfig} is.
         */
        public readonly ?\SugarCraft\Code\Context\Pruning\ContextLedger $contextLedger = null,
        /**
         * Delegated runs whose row the user expanded with a click (by
         * {@see \SugarCraft\Code\Agents\SubAgent::$id}), value always
         * true — the same shape as {@see \SugarCraft\Code\Chat::expanded()}.
         * View state, so it lives here beside the dashboard's selection.
         *
         * @var array<string, bool>
         */
        public readonly array $expandedAgents = [],
        /**
         * The run the live agents strip holds the keyboard on (roadmap P-B3),
         * by {@see \SugarCraft\Code\Agents\Live\AgentLiveState::$id}, or
         * null while the input box has it. `Alt+↓` sets it; `Esc`/`Alt+↑`,
         * opening the run, or any key the strip does not answer clear it.
         * Shell state, like the dashboard's selection, so the hosted chat's
         * own state is not widened for it.
         */
        public readonly ?string $agentStripFocus = null,
        /**
         * Finished runs the user took off the strip with `x` (P-B3), value
         * always true. The run itself is untouched — it stays in the
         * transcript and on the dashboard.
         *
         * @var array<string, true>
         */
        public readonly array $agentStripDismissed = [],
        /**
         * Rows (or tiles) a scrollable side surface is scrolled down by, keyed
         * by surface id — `pane:tools`, `pane:agents`, `split`. Absent means
         * the top; a renderer clamps an offset that outgrew its content.
         *
         * @var array<string, int>
         */
        public readonly array $paneScroll = [],
        /**
         * The live-agent column's width as the user last dragged its seam to,
         * in cells; null means the renderer's own proportion. Session view
         * state — the renderer clamps it to what the terminal can hold.
         */
        public readonly ?int $agentSplitCols = null,
        /**
         * The full-band settings view while it is open (roadmap N-P1), null
         * otherwise. Shell state like the skill picker: it writes no history
         * and sends nothing to the model, so it may open while a turn runs.
         */
        public readonly ?SettingsEditor $settingsEditor = null,
        /**
         * What the settings view reads, as a factory run each time it opens
         * (`\Closure(): SettingsSources`). The launch supplies one built with
         * its own project-trust answer and parsed flags; null falls back to
         * {@see SettingsSources::bestEffort()}, which reads the user's files and
         * the environment and says on its Files tab that the project's files
         * were not consulted.
         */
        public readonly ?\Closure $settingsSources = null,
        /**
         * The settings editor's write door (N-P2) — its own censused door,
         * separate from Chat's `onConfigChange`. Null (an embedder that wired
         * none) means the view can stage and preview but every save is refused.
         */
        public readonly ?SettingsWriter $settingsWriter = null,
        /**
         * The media generation form (crush-media W3.1, Shape A) while it is
         * open, null otherwise. Shell state exactly like the settings view:
         * it writes no history and dials nothing itself — its Generate action
         * hands the collected draft back to the /generate command pipeline —
         * so it may open while a turn runs.
         */
        public readonly ?MediaForm $mediaForm = null,
        /**
         * The full-screen video player (crush-media W5.4) while it is open,
         * null otherwise. Shell state on the settings/media-form law: it
         * writes no history and dials nothing — playback reads a finished
         * artifact — so it may open while a turn runs. The Player inside is
         * the single child-holding object; the shutdown teardown registered
         * at open is what reaps it when the frame loop dies mid-playback
         * (the global quit chord stops the loop without a model turn).
         */
        public readonly ?VideoPlayerScreen $videoPlayer = null,
        /**
         * The launch trust dialog while it is open (C-SEC-5.d), null
         * otherwise. A full-band modal on the settings-view law: the census
         * rows are answered row by row, and every grant leaves as a Cmd
         * through the one door {@see \SugarCraft\Code\Cli\Bootstrap::grantProjectTrust()}. Only an
         * interactive launch builds it — `-p` and serve never see a dialog.
         */
        public readonly ?TrustDialog $trustDialog = null,
        /**
         * The reasoning effort every request built off this App asks for
         * (roadmap 4.1-1) — a delegated sub-agent's preset `effort:`, carried
         * by {@see \SugarCraft\Code\Backend\EngineBackend::withReasoningEffort()}
         * onto each turn's App and by {@see \SugarCraft\Code\Runtime::run()}
         * onto {@see \SugarCraft\Code\Providers\CompleteRequest::$reasoningEffort}.
         * Null — every App but a sub-agent's — asks nothing, and the provider's
         * own tiers answer exactly as before.
         */
        public readonly string|float|null $reasoningEffort = null,
        /**
         * The delegated run the read-only Agent View shows in the main
         * transcript area (roadmap P-C2, Appendix P §5.1–5.2), by
         * {@see \SugarCraft\Code\Agents\Live\AgentLiveState::$id} — or the
         * dashboard row's key or name for a worker that is not one run — or
         * null while the parent transcript is on screen. Shell state, like the
         * dashboard's selection, so the hosted chat's state is not widened for
         * it: {@see view()} hands the frame to the chat's renderer for the one
         * paint ({@see Renderer::setAgentView()}).
         */
        public readonly ?string $agentViewTarget = null,
        /**
         * Where the view has read the run's JSONL log up to (P-C1's
         * {@see \SugarCraft\Code\Agents\Live\AgentTranscriptTail}), or null
         * when the view reads no log — a stored child session, or a worker that
         * wrote none.
         */
        public readonly ?\SugarCraft\Code\Agents\Live\AgentTranscriptTail $agentViewTail = null,
        /**
         * Every log item the view has read, in order — kept so a tool result
         * that arrives on a later tick still replaces its call's running row.
         *
         * @var list<array<string, mixed>>
         */
        public readonly array $agentViewItems = [],
        /**
         * The rows the view draws: {@see $agentViewItems} projected
         * ({@see \SugarCraft\Code\Agents\Live\AgentTranscriptTail::messages()}),
         * or a stored child session's transcript. Projected in update(), never
         * in view().
         *
         * @var list<\SugarCraft\Code\Message>
         */
        public readonly array $agentViewRows = [],
        /**
         * The open view's agent name when no live run carries one — a stored
         * child session opened from the session picker.
         */
        public readonly ?string $agentViewName = null,
        /**
         * What became of the messages the user sent delegated runs from the
         * Agent View composer (roadmap P-D2), newest last and capped at
         * {@see AGENT_MESSAGES_KEPT} — one {@see \SugarCraft\Code\App\AgentMessageSentMsg}
         * per run and message, a follow-up's later state replacing its
         * earlier one. The view draws each as a `you → <agent> · …` row
         * until the run's own log shows it delivered
         * ({@see agentViewSentRows()}).
         *
         * @var list<\SugarCraft\Code\App\AgentMessageSentMsg>
         */
        public readonly array $agentMessages = [],
        /**
         * `Ctrl+G` (roadmap P-D3): the composer sends to every running
         * agent of the batch on screen at once instead of the one on screen
         * ({@see agentComposerTargets()}). Off again on a second `Ctrl+G` or
         * when the view closes.
         */
        public readonly bool $agentBroadcast = false,
        /**
         * The Agent View's `Ctrl+X` leader is down (roadmap P-D3): the next
         * key is a control chord (`c`, `p`, `s`, `o`, `b`), never text.
         */
        public readonly bool $agentViewLeader = false,
        /**
         * The runs a soft cancel was just asked of, and when — a second
         * cancel of the same runs within {@see AGENT_HARD_CANCEL_SECONDS}
         * stops them at once (P-D3, Appendix P §5.5).
         *
         * @var array{ids: list<string>, at: float}|null
         */
        public readonly ?array $agentCancelArmed = null,
        /**
         * When the dashboard's `s` last armed a stop-all (rv-a11y M3a): a
         * second `s` within {@see STOP_ALL_ARMED_SECONDS} emits the real
         * `StopAllAgentsCmd`, mirroring the per-run soft/hard cancel pair.
         */
        public readonly ?float $agentsStopArmedAt = null,
        /**
         * Where the keyboard's transcript-row cursor stands (rv-a11y M3c,
         * v2:a11y-F1): index `0` is the newest unit (Chat's own route), a
         * `Ctrl+O` press within {@see TOOL_WALK_WINDOW_SECONDS} of the last
         * walks one row older and toggles as it moves, while `Ctrl+F` (or a
         * row click) SETTLES the cursor — `settled` true plus the unit's
         * identity `key` — and a `Ctrl+O` on a settled cursor toggles exactly
         * the focused row without moving. The key, not the index, is the
         * cursor's identity, so a newer row arriving above it does not make
         * it point somewhere else.
         *
         * @var array{at: float, index: int, settled?: bool, key?: string}|null
         */
        public readonly ?array $toolWalkState = null,
        /**
         * The runs the user paused and has not let go yet, so `Ctrl+X p`
         * knows which way it toggles. A run that hit the pause cap goes on by
         * itself; the next `Ctrl+X p` then only re-sends `resume`, which it
         * ignores.
         *
         * @var list<string>
         */
        public readonly array $agentPaused = [],
        /**
         * Auto-enable memory for the sidepanes (CL-2 FIX 1): which have
         * already taken their first content-driven dock, and which the user
         * touched by hand. Process-lifetime, rides every mutate.
         */
        public readonly PaneAutoShow $paneAutoShow = new PaneAutoShow(),
        /**
         * P-TABS.d: the tab strip's session factory — `LaunchContext::app()`
         * installs a closure over {@see \SugarCraft\Code\Cli\Bootstrap::tabChat()}
         * for the launch's root; null means this App was built without one.
         * Since P-TABS.e this is the DECK's arming signal: a launch carrying
         * one gets a {@see SessionDeck} seeded from the foreground Chat at
         * its first update ({@see armDeck()}); a launch without one keeps
         * the single-chat shape untouched. Rides every mutate.
         */
        public readonly ?\Closure $tabFactory = null,
        /**
         * P-CTX.e: whether the docked Context box shows its per-category
         * rows or only the summary line. View state, not persisted — like
         * the Tools pane's row cursor it rides every mutate and resets with
         * the process.
         */
        public readonly bool $contextPaneExpanded = false,
        /**
         * P-CTX.e: the category whose sub-rows the expanded box is showing,
         * null for the flat category list.
         */
        public readonly ?ContextCategory $contextDrill = null,
        /**
         * P-TABS.e: the many-Chats deck — one {@see Chat} in the foreground
         * plus parked turns still streaming in the background. Null means
         * this App has no deck (every launch until a tabFactory exists at
         * arm time): routing stays the single-chat shape byte-for-byte.
         * Armed lazily by {@see armDeck()} on the first update once a
         * tabFactory is installed. Rides every mutate.
         */
        public readonly ?SessionDeck $deck = null,
        /**
         * P-TABS.e: the busy tab whose close is armed, as
         * `[key => string, at => float]`, or null when nothing is armed.
         * {@see TAB_CLOSE_ARM_SECONDS} bounds how long the arm lives; the
         * confirming press closes, switching tabs or arming a different
         * target clears it early, and anything else lets it expire.
         *
         * @var array{key: string, at: float}|null
         */
        public readonly ?array $pendingTabClose = null,
        /**
         * P-TABS.e: whether the one "quit while a background tab is still
         * streaming" notice has been spent this session (ask ONCE, per the
         * plan — the second press quits unconditionally). Rides every
         * mutate; spent, never re-armed, by design.
         */
        public readonly bool $tabQuitWarned = false,
        /**
         * P-TABS.g: the last OSC 2 window title the deck choke emitted, or
         * null when nothing has been sent yet. The strip's whole title law is
         * "write only on change", and this prop is what makes "change"
         * decidable across turns. Rides every mutate.
         */
        public readonly ?string $deckTitle = null,
        /**
         * P-TABS.g: which background tabs have already had their desktop
         * notice fired, keyed by tab key with the attention value that was
         * announced as the payload. A tab re-enters Waiting from Idle and the
         * law is once per transition, so the map is overwritten per key and
         * pruned to the deck's live keys at every pass — never unbounded.
         *
         * @var array<string, string>
         */
        public readonly array $tabNotified = [],
        /**
         * P-AGENT.b: the per-window stash of {@see WindowViewport}s — what
         * each window (a tab's main transcript, an open Agent View) held
         * scrolled and drafted while the user was elsewhere. Keys are the
         * zoneable vocabulary the window surfaces already speak: `s:<tabKey>`
         * for a tab's main window, `a:<runId>` for a run's view. Null means
         * nothing has ever been swapped away — the deck's own law, so a
         * single-window launch never builds the map. Rides every mutate.
         */
        public readonly ?WindowViewports $windowViewports = null,
        /**
         * P-AGENT.b: the Agent View each tab left open, tab key => run id.
         * {@see openAgentView()} records it, a user {@see closeAgentView()}
         * forgets it, and {@see switchTab()} re-opens the remembered run on
         * the way back — the view is per-tab now. Pruned to the deck's live
         * keys at every switch, like {@see $tabNotified}.
         *
         * @var array<string, string>
         */
        public readonly array $agentViewByTab = [],
        /**
         * C-TUI-2.e: the open checkpoint-diff review (a bare `/diff [n]`).
         * A shell slot on the settings/media/player law: while it stands it
         * owns the band, the keys and the wheel; `Esc` (or a reload that
         * finds nothing to show) closes it. Null = no review open.
         */
        public readonly ?DiffReview $diffReview = null,
        /**
         * C-TUI-3.d: the open message picker (the idle double-Escape's
         * rewind-to-a-prompt list). A shell slot on the settings/media/
         * player/review law: while it stands it owns the keys and the band;
         * `Esc` closes it, `Enter` rewinds the hosted chat to the selected
         * checkpoint and seeds its prompt. Null = no picker open.
         */
        public readonly ?MessagePicker $messagePicker = null,
        /**
         * The first-run provider wizard while it is open (C-TUI-5.e), null
         * otherwise. A launch modal on the trust-dialog law: it covers the
         * band before the chat does, answers only keys, and what it decides
         * leaves as a FirstRunWizardAction the shell folds through the
         * {@see ProviderKeyStore} and Chat's public /model twin. Offered
         * once per install — the no-key exits stamp
         * {@see FirstRunWizard::markDismissed()} so a later launch stays quiet.
         */
        public readonly ?FirstRunWizard $firstRunWizard = null,
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

    /**
     * P-CTX.e: flip the Context box between summary and category rows.
     * Collapsing drops the drill too — the sub-rows live inside the
     * expanded body, and a drill that survives its own container would
     * resurrect on the next expand pointing at a row that was never shown.
     */
    public function contextPaneToggle(): self
    {
        $expand = !$this->contextPaneExpanded;

        return $this->mutate(
            contextPaneExpanded: $expand,
            contextDrill: $expand ? $this->contextDrill : null,
        );
    }

    /**
     * P-CTX.e: show one category's sub-rows (null returns to the flat list);
     * drilling implies expanding, the same containment the collapse rule
     * enforces from the other side.
     */
    public function contextPaneDrill(?ContextCategory $v): self
    {
        return $this->mutate(contextPaneExpanded: true, contextDrill: $v);
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

    /** @see $reasoningEffort */
    public function withReasoningEffort(string|float|null $v): self
    {
        return $this->mutate(reasoningEffort: $v);
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

    /** Expand a delegated run's row, or collapse it if it was expanded. */
    public function toggleAgentExpanded(string $runId): self
    {
        $expanded = $this->expandedAgents;
        if (isset($expanded[$runId])) {
            unset($expanded[$runId]);
        } else {
            $expanded[$runId] = true;
        }

        return $this->mutate(expandedAgents: $expanded);
    }

    public function isAgentExpanded(string $runId): bool
    {
        return isset($this->expandedAgents[$runId]);
    }

    /**
     * The runs the live agents strip shows (P-B3): the hosted chat's live
     * registry, minus what the user dismissed. Empty with no chat.
     *
     * @return list<\SugarCraft\Code\Agents\Live\AgentLiveState>
     */
    public function agentStripItems(): array
    {
        return $this->chat === null
            ? []
            : \SugarCraft\Code\Tui\AgentStrip::items($this->chat->agentLive(), $this->agentStripDismissed);
    }

    /** Give the strip the keyboard on run $id, or with null hand it back to the input. */
    public function withAgentStripFocus(?string $id): self
    {
        return $this->mutate(agentStripFocus: $id);
    }

    /** Take a run off the strip (`x` on a finished run); focus moves to a neighbour. */
    public function dismissFromAgentStrip(string $id): self
    {
        $items = $this->agentStripItems();
        $at = \SugarCraft\Code\Tui\AgentStrip::indexOf($items, $id);
        $dismissed = $this->agentStripDismissed;
        $dismissed[$id] = true;
        $left = array_values(array_filter($items, static fn ($state): bool => $state->id !== $id));
        $focus = $left === [] ? null : $left[min(max(0, $at), count($left) - 1)]->id;

        return $this->mutate(agentStripDismissed: $dismissed, agentStripFocus: $focus);
    }

    /**
     * P-AGENT.b: the window stash, normalised — a shell that has never
     * swapped windows holds no map, and "no map" is the empty one.
     */
    private function viewportStore(): WindowViewports
    {
        return $this->windowViewports ?? WindowViewports::empty();
    }

    /** The single-window stand-in for the main window's key when no deck exists. */
    private function mainWindowKey(): string
    {
        return 's:' . ($this->deck?->activeKey() ?? 'main');
    }

    /**
     * What the foreground {@see Chat} holds on screen right now — scroll and
     * composer — as the value object the stash keeps. No chat, nothing held:
     * the bottom viewport.
     */
    private function currentViewport(): WindowViewport
    {
        if ($this->chat === null) {
            return WindowViewport::bottom();
        }

        [$draft, $cursor] = $this->chat->draft();

        return new WindowViewport($this->chat->scrollOffset(), $draft, $cursor);
    }

    /**
     * P-AGENT.b: land on run $runId's view from either window-list surface —
     * the strip chip or a Sessions row — switching to the run's tab first
     * when it is a background one. An empty id opens nothing. A run the
     * projection no longer carries (the id may have settled between paint
     * and click) still opens, with a null name: the view's own read ladder
     * in {@see openAgentView()} answers from the log, the child session or
     * the live buffer, and a nameless view of a real run beats the silent
     * nothing a stale session row would otherwise answer.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function openRunFromWindow(string $runId): array
    {
        if ($runId === '') {
            return [$this, null];
        }

        $tab = null;
        $name = null;
        foreach ($this->windowList()?->entries() ?? [] as $entry) {
            $child = $entry->child($runId);
            if ($child !== null) {
                $tab = $child->ref->parentKey;
                $name = $child->ref->label;
                break;
            }
        }

        if ($tab === null || $tab === ($this->deck?->activeKey() ?? '')) {
            return [$this->openAgentView($runId, name: $name), null];
        }

        [$app, $cmd] = $this->switchTab($tab);

        return [$app->openAgentView($runId, name: $name), $cmd];
    }

    /**
     * Open run $id (P-B3's strip `Enter`): the read-only Agent View (P-C2).
     *
     * @see openAgentView()
     */
    public function openAgent(string $id): self
    {
        return $this->openAgentView($id);
    }

    /**
     * Open the read-only Agent View on run $id (roadmap P-C2, Appendix P
     * §5.1): the chat pane's transcript area shows that run's own transcript,
     * with {@see \SugarCraft\Code\Tui\AgentViewHeader} pinned above it,
     * until {@see closeAgentView()}. The input box becomes that run's
     * composer (P-D2): `Enter` sends the draft to it
     * ({@see agentComposerTargets()}).
     *
     * {@see AgentViewMode::Attach} now means exactly this — "the main area
     * shows the agent" — so the dashboard's Peek → Attach `Enter` lands here,
     * and its row for the run is selected, so the two surfaces agree.
     *
     * WHAT IT READS, in order:
     *  1. the run's JSONL log (P-C1), announced by its `started` frame and
     *     checked to lie under the transcript root before it is opened — read
     *     up to {@see AGENT_VIEW_OPEN_READS} windows now, then tailed;
     *  2. else the `subagent` child session the finished run was stored as
     *     ($childSessionId, the run's own, or the manager's record of it),
     *     read once from the session store;
     *  3. else nothing of its own: a dashboard worker that keeps no log (a
     *     workflow stage, a background session) is drawn from its live output
     *     buffer by {@see \SugarCraft\Code\Tui\AgentOutputPane}'s Attach mode.
     *
     * Opening the run that is already open is the view's refresh — see
     * {@see \SugarCraft\Code\App\OpenAgentViewMsg}.
     */
    public function openAgentView(string $id, ?string $childSessionId = null, ?string $name = null): self
    {
        if ($id === '') {
            return $this;
        }
        // A stored child session this launch still holds the live run of
        // opens as that run, so the view tails its log and knows its batch.
        if ($childSessionId !== null && $this->chat !== null) {
            foreach ($this->chat->agentLive()->all() as $state) {
                if ($state->childSessionId === $childSessionId) {
                    $id = $state->id;
                    break;
                }
            }
        }
        if ($this->agentViewTarget === $id) {
            return $this->refreshAgentView();
        }

        // P-AGENT.b: the window swap. What sits in the composer and how far
        // the transcript is scrolled belong to the WINDOW being left — the
        // tab's main transcript when the view was closed, the previous run's
        // view when this open is `Alt+N`/`Alt+P` navigation — and the
        // incoming window's own saved state is restored in its place, or the
        // bottom/empty one when it has never been opened. Without the stash
        // the main draft read as the agent's composer and the agent view's
        // scrolling leaked back into the transcript on close.
        $store = $this->viewportStore()->stash(
            $this->agentViewTarget === null ? $this->mainWindowKey() : 'a:' . $this->agentViewTarget,
            $this->currentViewport(),
        );
        $incoming = $store->restore('a:' . $id);
        $chat = $this->chat;
        $activeTab = $this->deck?->activeKey();

        $next = $this->mutate(
            pane: Pane::Chat,
            agentViewMode: AgentViewMode::Attach,
            agentStripFocus: null,
            agentViewTarget: $id,
            agentViewTail: null,
            agentViewItems: [],
            agentViewRows: [],
            agentViewName: $name,
            chat: $chat === null ? null : $chat->withDraft($incoming->draft, $incoming->cursor)->withScrollOffset($incoming->scrollOffset),
            windowViewports: $store,
            agentViewByTab: $activeTab === null ? $this->agentViewByTab : [...$this->agentViewByTab, $activeTab => $id],
        );
        foreach (\SugarCraft\Code\Tui\Components\AgentDashboardPane::entries($next) as $index => $entry) {
            if (($entry->key ?? $entry->name) === $id) {
                $next = $next->withSelectedAgentIndex($index);
                break;
            }
        }

        $log = $next->agentViewLog();
        if ($log !== null) {
            $next = $next->mutate(agentViewTail: \SugarCraft\Code\Agents\Live\AgentTranscriptTail::of($log));
            for ($read = 0; $read < self::AGENT_VIEW_OPEN_READS; $read++) {
                $before = $next->agentViewTail;
                $next = $next->refreshAgentView();
                if ($next->agentViewTail?->offset() === $before?->offset()) {
                    break;
                }
            }

            return $next;
        }

        return $next->readStoredAgentView($childSessionId);
    }

    /**
     * Read what the open view's log gained since the last read — one window of
     * {@see \SugarCraft\Code\Agents\Live\AgentTranscriptTail::MAX_BYTES_PER_READ}
     * at most — and re-project the rows. The shell's tail tick
     * ({@see subscriptions()}) calls it through {@see openAgentView()}.
     *
     * A view opened before the run announced its log (a click on a queued
     * line) picks the log up here once the `started` frame names it; one whose
     * run finished without a log falls back to the stored child session.
     */
    public function refreshAgentView(): self
    {
        if ($this->agentViewTarget === null) {
            return $this;
        }

        $tail = $this->agentViewTail;
        if ($tail === null) {
            $log = $this->agentViewLog();
            if ($log === null) {
                return $this->agentViewRows === [] ? $this->readStoredAgentView(null) : $this;
            }
            $tail = \SugarCraft\Code\Agents\Live\AgentTranscriptTail::of($log);
        }

        [$items, $advanced] = $tail->next();
        if ($items === [] && $advanced->offset() === $tail->offset() && $tail === $this->agentViewTail) {
            return $this;
        }

        $all = $this->agentViewItems;
        array_push($all, ...$items);
        if (\count($all) > self::AGENT_VIEW_MAX_ITEMS) {
            $all = \array_slice($all, -self::AGENT_VIEW_MAX_ITEMS);
        }

        return $this->mutate(
            agentViewTail: $advanced,
            agentViewItems: $all,
            agentViewRows: \SugarCraft\Code\Agents\Live\AgentTranscriptTail::messages($all),
        );
    }

    /**
     * Leave the Agent View: the parent transcript is back, and the dashboard
     * is no longer attached. Pane focus is left where it is. P-AGENT.b: the
     * leaving is a window swap — the run's view keeps whatever draft and
     * scroll it holds, and the tab's main window comes back exactly as it
     * was before the view opened.
     */
    public function closeAgentView(): self
    {
        if ($this->agentViewTarget === null && $this->agentViewMode !== AgentViewMode::Attach) {
            return $this;
        }

        $id = $this->agentViewTarget;
        if ($id === null) {
            // A dashboard worker attached without a run id has no window of
            // its own to keep: nothing was ever swapped into the chat.
            return $this->mutate(
                agentViewMode: AgentViewMode::List,
                agentViewTarget: null,
                agentViewTail: null,
                agentViewItems: [],
                agentViewRows: [],
                agentViewName: null,
                agentBroadcast: false,
                agentViewLeader: false,
            );
        }

        $store = $this->viewportStore()->stash('a:' . $id, $this->currentViewport());
        $main = $store->restore($this->mainWindowKey());
        $chat = $this->chat;
        $activeTab = $this->deck?->activeKey();

        return $this->mutate(
            agentViewMode: AgentViewMode::List,
            agentViewTarget: null,
            agentViewTail: null,
            agentViewItems: [],
            agentViewRows: [],
            agentViewName: null,
            agentBroadcast: false,
            agentViewLeader: false,
            chat: $chat === null ? null : $chat->withDraft($main->draft, $main->cursor)->withScrollOffset($main->scrollOffset),
            windowViewports: $store,
            agentViewByTab: $activeTab === null ? $this->agentViewByTab : array_diff_key($this->agentViewByTab, [$activeTab => true]),
        );
    }

    /**
     * The runs of the open run's Task batch, in spawn order, itself included —
     * what `Alt+N` / `Alt+P` and the header's `‹ i of N ›` walk. A single run,
     * or a worker that is not a run, has none.
     *
     * @return list<string> run ids
     */
    public function agentViewSiblings(): array
    {
        $state = $this->agentViewTarget === null ? null : $this->chat?->agentLive()->get($this->agentViewTarget);
        if ($state === null || $state->parentCallId === '') {
            return [];
        }

        $ids = [];
        foreach ($this->chat?->agentLive()->forCall($state->parentCallId) ?? [] as $sibling) {
            if (!$sibling->isQueued()) {
                $ids[] = $sibling->id;
            }
        }

        return $ids;
    }

    /**
     * The sibling $step places away from the open run (`Alt+N` is +1, `Alt+P`
     * is -1), or null at either end or with no siblings.
     */
    public function agentViewSibling(int $step): ?string
    {
        $siblings = $this->agentViewSiblings();
        $at = array_search($this->agentViewTarget, $siblings, true);

        return \is_int($at) ? ($siblings[$at + $step] ?? null) : null;
    }

    /**
     * The runs the Agent View's composer sends to (roadmap P-D2): the run on
     * screen — or, while `Ctrl+G`'s broadcast is on (P-D3), every running
     * run of its batch (every running run, for one not in a batch), and the
     * one on screen when none is running any more. Empty while no view is
     * open — the box is then the main chat's.
     *
     * @return list<string> run ids
     */
    public function agentComposerTargets(): array
    {
        $id = $this->agentViewTarget;
        if ($id === null) {
            return [];
        }
        if (!$this->agentBroadcast) {
            return [$id];
        }

        // A lone Task is a batch of one; its broadcast is every running run.
        $siblings = \count($batch = $this->agentViewSiblings()) > 1 ? $batch : [];
        $running = array_values(array_filter(
            $this->runningAgentIds(),
            static fn (string $run): bool => $siblings === [] || \in_array($run, $siblings, true),
        ));

        return $running === [] ? [$id] : $running;
    }

    /**
     * Every delegated run of this session that has not finished, oldest
     * first — what stop-all and a broadcast address.
     *
     * @return list<string> run ids
     */
    public function runningAgentIds(): array
    {
        $ids = [];
        foreach ($this->chat?->agentLive()->all() ?? [] as $state) {
            if (!$state->isFinished()) {
                $ids[] = $state->id;
            }
        }

        return $ids;
    }

    /** The same shell with the Agent View's `Ctrl+X` leader down or up ({@see $agentViewLeader}). */
    public function withAgentViewLeader(bool $down): self
    {
        return $down === $this->agentViewLeader ? $this : $this->mutate(agentViewLeader: $down);
    }

    /**
     * The same shell with the Agents dashboard stop-all arming stamp set or
     * cleared ({@see $agentsStopArmedAt}, rv-a11y M3a two-press `s`).
     */
    public function withAgentsStopArmedAt(?float $at): self
    {
        return $at === $this->agentsStopArmedAt ? $this : $this->mutate(agentsStopArmedAt: $at);
    }

    /** Whether the user paused run $id and has not let it go ({@see $agentPaused}). */
    public function isAgentPaused(string $id): bool
    {
        return \in_array($id, $this->agentPaused, true);
    }

    /**
     * `Ctrl+G` (roadmap P-D3, Appendix P §5.5): the composer's broadcast —
     * send to every running agent of the batch at once. Turned on, it opens
     * the Agent View on the newest running run when none is open, so the
     * box that broadcasts is the composer that says it does. Turned off by
     * the second press. With nothing running there is no one to address,
     * and the status line says so.
     */
    public function toggleAgentBroadcast(): self
    {
        if ($this->agentBroadcast) {
            return $this->mutate(agentBroadcast: false)->withStatus(Lang::t('tui.app.broadcast_off'));
        }

        $running = $this->runningAgentIds();
        if ($running === []) {
            return $this->withStatus(Lang::t('tui.app.broadcast_none'));
        }

        $app = $this->agentViewTarget === null || !\in_array($this->agentViewTarget, $running, true)
            ? $this->openAgentView($running[\count($running) - 1])
            : $this;
        $app = $app->mutate(agentBroadcast: true);

        return $app->withStatus(Lang::t('tui.app.broadcast_on', ['count' => \count($app->agentComposerTargets())]));
    }

    /**
     * Deliver a control to the hosted chat, which owns the runs' mailboxes
     * (roadmap P-D3) — after the shell's own half: a second cancel of the
     * same runs within {@see AGENT_HARD_CANCEL_SECONDS} becomes the hard
     * stop, pause/resume keep {@see $agentPaused} current, opening a run as
     * a session leaves the view, and the status line says what was asked.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function deliverAgentControl(\SugarCraft\Code\App\AgentControlMsg $control): array
    {
        if ($control->agentIds === []) {
            return [$this->withStatus(match ($control->verb) {
                \SugarCraft\Code\App\AgentControlMsg::RESUME => Lang::t('tui.app.no_agent.resume'),
                \SugarCraft\Code\App\AgentControlMsg::PAUSE => Lang::t('tui.app.no_agent.pause'),
                \SugarCraft\Code\App\AgentControlMsg::OPEN_SESSION => Lang::t('tui.app.no_agent.open'),
                \SugarCraft\Code\App\AgentControlMsg::MESSAGE => Lang::t('tui.app.no_agent.message'),
                \SugarCraft\Code\App\AgentControlMsg::INTERRUPT => Lang::t('tui.app.no_agent.message'),
                default => Lang::t('tui.app.no_agent.stop'),
            }), null];
        }

        $app = $this->mutate(agentCancelArmed: null);
        $names = $this->agentNames($control->agentIds);
        $now = microtime(true);
        switch ($control->verb) {
            case \SugarCraft\Code\App\AgentControlMsg::CANCEL:
                $armed = $this->agentCancelArmed;
                if ($armed !== null && $armed['ids'] === $control->agentIds && $now - $armed['at'] <= self::AGENT_HARD_CANCEL_SECONDS) {
                    $control = new \SugarCraft\Code\App\AgentControlMsg(\SugarCraft\Code\App\AgentControlMsg::STOP, $control->agentIds);
                    $app = $app->withStatus(Lang::t('tui.app.agent_stopping', ['names' => $names]));
                } else {
                    $app = $app->mutate(agentCancelArmed: ['ids' => $control->agentIds, 'at' => $now])
                        ->withStatus(Lang::t('tui.app.agent_cancel_armed', ['names' => $names]));
                }
                break;
            case \SugarCraft\Code\App\AgentControlMsg::PAUSE:
                $app = $app->mutate(agentPaused: array_values(array_unique([...$this->agentPaused, ...$control->agentIds])))
                    ->withStatus(Lang::t('tui.app.agent_pausing', ['names' => ucfirst($names)]));
                break;
            case \SugarCraft\Code\App\AgentControlMsg::RESUME:
                $app = $app->mutate(agentPaused: array_values(array_diff($this->agentPaused, $control->agentIds)))
                    ->withStatus(Lang::t('tui.app.agent_resumed', ['names' => ucfirst($names)]));
                break;
            case \SugarCraft\Code\App\AgentControlMsg::OPEN_SESSION:
                $app = $app->closeAgentView();
                break;
        }

        return $app->delegateToChat($control);
    }

    /**
     * "explore", "explore and reviewer" or "3 agents", for the status line.
     *
     * @param list<string> $ids
     */
    private function agentNames(array $ids): string
    {
        if (\count($ids) > 2) {
            return Lang::t('tui.app.agents_count', ['count' => \count($ids)]);
        }

        $names = [];
        foreach ($ids as $id) {
            $names[] = \SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames($this->chat?->agentLive()->get($id)?->name ?? $id);
        }

        return \count($names) === 2
            ? Lang::t('tui.app.names_pair', ['first' => $names[0], 'second' => $names[1]])
            : implode('', $names);
    }

    /**
     * The delegated run behind dashboard row $index, or no run: a worker
     * that is not one (a workflow stage, a background session) has no
     * mailbox to control (roadmap P-D3).
     *
     * @return list<string>
     */
    private function dashboardRunIds(int $index): array
    {
        $key = \SugarCraft\Code\Tui\Components\AgentDashboardPane::entries($this)[$index]->key ?? null;

        return $key !== null && $this->chat?->agentLive()->get($key) !== null ? [$key] : [];
    }

    /**
     * Keep what became of a composer message ({@see $agentMessages}): a later
     * state of the same message — a follow-up that replied or failed —
     * replaces the earlier one, so each message keeps one row.
     */
    public function recordAgentMessage(\SugarCraft\Code\App\AgentMessageSentMsg $sent): self
    {
        $kept = $this->agentMessages;
        if ($sent->status === \SugarCraft\Code\App\AgentMessageSentMsg::REPLIED || $sent->status === \SugarCraft\Code\App\AgentMessageSentMsg::FAILED) {
            for ($i = \count($kept) - 1; $i >= 0; $i--) {
                if ($kept[$i]->agentId === $sent->agentId && $kept[$i]->text === $sent->text
                    && $kept[$i]->status === \SugarCraft\Code\App\AgentMessageSentMsg::RESUMING) {
                    array_splice($kept, $i, 1);
                    break;
                }
            }
        }
        $kept[] = $sent;

        return $this->mutate(agentMessages: \array_slice($kept, -self::AGENT_MESSAGES_KEPT));
    }

    /**
     * The `you → <agent> · …` rows the open view draws below the run's own
     * transcript for run $id: every message the user sent it that its log
     * does not yet show delivered. A queued message drops out once the run
     * logs it (the log's own row then stands where it was read); a
     * follow-up's row stays, carrying how it ended.
     *
     * @return list<\SugarCraft\Code\Message>
     */
    private function agentViewSentRows(string $id): array
    {
        $delivered = [];
        foreach ($this->agentViewItems as $item) {
            if (($item['t'] ?? null) === \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog::T_INBOX && \is_string($item['msgId'] ?? null)) {
                $delivered[$item['msgId']] = true;
            }
        }

        $rows = [];
        foreach ($this->agentMessages as $sent) {
            if ($sent->agentId !== $id || ($sent->msgId !== null && isset($delivered[$sent->msgId]))) {
                continue;
            }
            // P-AGENT.b: an interrupt send rides the QUEUED status with its
            // detail set to `⚡ interrupt`, so the row tells the two
            // deliveries apart while both await the log's own line; every
            // other state keeps badge()'s read.
            $badge = $sent->status === \SugarCraft\Code\App\AgentMessageSentMsg::QUEUED
                && $sent->detail !== null && $sent->detail !== ''
                ? $sent->detail
                : $sent->badge();
            $rows[] = \SugarCraft\Code\Message::toAgent(\SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames($sent->name), \SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames($sent->text), $badge);
        }

        return $rows;
    }

    /**
     * What one frame of the open view draws, for
     * {@see Renderer::setAgentView()} — or null while no view is open. Read in
     * view(): it computes from state and reads nothing from disk.
     *
     * @return array{id: string, name: string, state: ?\SugarCraft\Code\Agents\Live\AgentLiveState, rows: list<\SugarCraft\Code\Message>, composer: ?string, attach: ?\SugarCraft\Code\Tui\AgentOutputState, siblings: list<string>, transcript: bool}|null
     */
    public function agentViewFrame(): ?array
    {
        $id = $this->agentViewTarget;
        if ($id === null || $this->chat === null) {
            return null;
        }

        $state = $this->chat->agentLive()->get($id);
        $attach = null;
        $transcript = $this->agentViewTail !== null || $this->agentViewRows !== [];
        if (!$transcript) {
            foreach (\SugarCraft\Code\Tui\Components\AgentDashboardPane::entries($this) as $entry) {
                if (($entry->key ?? $entry->name) === $id) {
                    $attach = $entry;
                    break;
                }
            }
        }

        return [
            'id' => $id,
            'name' => $state?->name ?? $this->agentViewName ?? $attach?->name ?? $this->chat->agentManager()?->subAgent($id)?->agent->name ?? $id,
            'state' => $state,
            'rows' => [...$this->agentViewRows, ...$this->agentViewSentRows($id)],
            // P-D3: what the composer's placeholder names — the broadcast's
            // audience, when Ctrl+G turned it on for more than this run.
            'composer' => $this->agentBroadcast && \count($targets = $this->agentComposerTargets()) > 1
                ? Lang::t('tui.app.composer_all', ['count' => \count($targets)])
                : null,
            'attach' => $attach,
            'siblings' => $this->agentViewSiblings(),
            'transcript' => $transcript,
        ];
    }

    /**
     * The open run's transcript log, when its frames announced one that lies
     * where {@see \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog} writes
     * — a path off a frame is checked before it is opened.
     */
    private function agentViewLog(): ?string
    {
        $log = $this->agentViewTarget === null ? null : $this->chat?->agentLive()->get($this->agentViewTarget)?->transcriptLog;

        return $log !== null && \SugarCraft\Code\Agents\Live\SubAgentTranscriptLog::isLogPath($log) ? $log : null;
    }

    /**
     * The view's rows from the stored `subagent` child session: $childSessionId,
     * else the one the run's finished frame named, else the manager's record.
     * One store read, in update(), on open or when a log-less run finished.
     */
    private function readStoredAgentView(?string $childSessionId): self
    {
        $id = (string) $this->agentViewTarget;
        $childSessionId ??= $this->chat?->agentLive()->get($id)?->childSessionId
            ?? $this->chat?->agentManager()?->childSessionIdOf($id);
        if ($childSessionId === null || $this->chat === null) {
            return $this;
        }

        try {
            $rows = Chat::loadTranscript($this->chat->sessionStore(), $childSessionId);
        } catch (\Throwable) {
            return $this;
        }

        return $rows === [] ? $this : $this->mutate(agentViewRows: array_values($rows));
    }

    /** How far the surface $id is scrolled down; 0 is the top. */
    public function paneScroll(string $id): int
    {
        return $this->paneScroll[$id] ?? 0;
    }

    /**
     * Scroll the surface $id by $delta (positive = down), never above the
     * top. The bottom bound is the renderer's: it knows the content height
     * and clamps an offset that outgrew it.
     */
    public function withPaneScroll(string $id, int $delta, ?int $max = null): self
    {
        $offset = max(0, $this->paneScroll($id) + $delta);
        if ($max !== null) {
            $offset = min($offset, max(0, $max));
        }

        $scroll = $this->paneScroll;
        if ($offset === 0) {
            unset($scroll[$id]);
        } else {
            $scroll[$id] = $offset;
        }

        return $this->mutate(paneScroll: $scroll);
    }

    /** Set (or with null, release) the live-agent column's dragged width. */
    public function withAgentSplitCols(?int $cols): self
    {
        return $this->mutate(agentSplitCols: $cols === null ? null : max(1, $cols));
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
     * {@see \SugarCraft\Code\Runtime::buildSystemPrompt()} can fold the
     * repo-root CLAUDE.md/AGENTS.md and the config-driven forced-instruction
     * globs into every system prompt.
     *
     * The loader is deliberately the SAME instance Read/Edit/Glob receive
     * from {@see \SugarCraft\Code\Cli\Bootstrap::tools()}: loadForPath()'s
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
     * The instance handed in is the SAME model `bin/candy-code` would have run
     * standalone — the shell adds layout around it and routes unhandled
     * messages into it, and never copies state out of it.
     */
    public function withChat(?Chat $v): self
    {
        // P-TABS.f: every chat that enters a deck-armed App is deck-hosted —
        // the appointment rides the swap itself, so the four session-open
        // routes answer with OpenTabMsg/CloseTabMsg instead of swapping the
        // session under the tab. tabFactory!==null is the deck's launch
        // condition (armDeck arms on exactly it), and withTabHosted is
        // identity-preserving, so a factory-less launch keeps its historic
        // object flow byte-identically.
        return $this->mutate(chat: $v === null ? null : $v->withTabHosted($this->tabFactory !== null));
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
     * {@see \SugarCraft\Code\Context\RulesState} for the two-owners argument.
     */
    public function withRulesState(?RulesState $v): self
    {
        return $this->mutate(rulesState: $v);
    }

    /** @param ?\Closure(): SettingsSources $v what the settings view reads when it opens */
    public function withSettingsSources(?\Closure $v): self
    {
        return $this->mutate(settingsSources: $v);
    }

    /** Replace the open settings view's state (null closes it). */
    public function withSettingsEditor(?SettingsEditor $v): self
    {
        return $this->mutate(settingsEditor: $v);
    }

    /** Replace the open media form's state (null closes it). */
    public function withMediaForm(?MediaForm $v): self
    {
        return $this->mutate(mediaForm: $v);
    }

    /** Replace the open player's state (null closes it — WITHOUT stopping; prefer closeVideoPlayer()). */
    public function withVideoPlayer(?VideoPlayerScreen $v): self
    {
        return $this->mutate(videoPlayer: $v);
    }

    public function withSettingsWriter(?SettingsWriter $v): self
    {
        return $this->mutate(settingsWriter: $v);
    }

    /** Replace the open launch trust dialog's state (null closes it). */
    public function withTrustDialog(?TrustDialog $v): self
    {
        return $this->mutate(trustDialog: $v);
    }

    /** Replace the open diff review's state (null closes it). */
    public function withDiffReview(?DiffReview $v): self
    {
        return $this->mutate(diffReview: $v);
    }

    /** Replace the open first-run wizard's state (null closes it; C-TUI-5.e). */
    public function withFirstRunWizard(?FirstRunWizard $v): self
    {
        return $this->mutate(firstRunWizard: $v);
    }

    /**
     * Build the open settings view's save preview from what its tier's file
     * holds now (N-P2). One small read, on an explicit request — the same
     * shape as {@see openSettings()} resolving the files when the view opens.
     */
    public function previewSettings(): self
    {
        $editor = $this->settingsEditor;
        if ($editor === null || !$editor->hasChanges()) {
            return $this;
        }

        $writer = $this->settingsWriter;
        $tier = $editor->tier;
        if ($writer === null) {
            return $this->mutate(settingsEditor: $editor->withPreview(
                SettingsSavePreview::blocked($tier, null, Lang::t('tui.app.settings_unsavable')),
            ));
        }

        try {
            $before = $writer->current($tier);
        } catch (\RuntimeException $e) {
            return $this->mutate(settingsEditor: $editor->withPreview(
                SettingsSavePreview::blocked($tier, $writer->targetPath($tier), $e->getMessage()),
            ));
        }

        $preview = SettingsSavePreview::new(
            $tier,
            $writer->targetPath($tier),
            $before,
            SettingsWriter::patched($before, $editor->set, $editor->unset),
            $editor->set,
            $editor->unset,
            $writer->refusals($tier, $editor->set, $editor->unset),
        );

        // The running turn keeps the settings it began with; the preview says
        // so (Appendix N §4.8), and only the shell knows a turn is running.
        return $this->mutate(settingsEditor: $editor->withPreview(
            $preview->withNotes(self::settingsShadowNotes($editor))->withTurnRunning($this->chat?->inFlight ?? false),
        ));
    }

    /**
     * What the preview should say about precedence: a "You" save that now
     * overrides a value in your `settings.json`, and a project-tier save that a
     * value in one of your own files (or the environment, or a flag) still
     * outranks — saved, but not what this launch will use.
     *
     * @return list<string>
     */
    private static function settingsShadowNotes(SettingsEditor $editor): array
    {
        $notes = [];
        foreach ($editor->set as $key => $value) {
            $resolved = $editor->resolved[$key] ?? null;
            if ($resolved === null) {
                continue;
            }

            $sources = [$resolved->source, ...$resolved->shadowed];
            if ($editor->tier === SettingsTier::You && \in_array(SettingSource::UserSettings, $sources, true)) {
                $notes[] = Lang::t('tui.app.settings_overrides', ['key' => $key]);
            }

            if ($resolved->source->precedence() > $editor->tier->source()->precedence()) {
                $notes[] = Lang::t('tui.app.settings_outranked', ['key' => $key, 'source' => $resolved->source->label()]);
            }
        }

        return $notes;
    }

    /**
     * Confirm the open preview: the write runs as a Cmd (it is I/O) and
     * answers with a {@see SettingsSavedMsg}. Nothing happens without a
     * preview that {@see SettingsSavePreview::canSave()}.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function confirmSettingsSave(): array
    {
        $preview = $this->settingsEditor?->preview;
        $writer = $this->settingsWriter;
        if ($preview === null || $writer === null || !$preview->canSave()) {
            return [$this, null];
        }

        $tier = $preview->tier;
        $set = $preview->set;
        $unset = $preview->unset;
        $changed = $preview->changed();

        return [$this, static function () use ($writer, $tier, $set, $unset, $changed): SettingsSavedMsg {
            try {
                return SettingsSavedMsg::saved($tier, $changed, $writer->write($tier, $set, $unset));
            } catch (\Throwable $e) {
                return SettingsSavedMsg::failed($tier, $changed, $e->getMessage());
            }
        }];
    }

    /**
     * A save finished: the view records the outcome and, after a write,
     * re-resolves so its rows show what the files now say — and the hosted
     * chat applies what was saved (roadmap N-P3): saved is not applied, and
     * {@see Chat::applySettings()} is where each key takes effect live, next
     * turn or after a restart.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function settingsSaved(SettingsSavedMsg $msg): array
    {
        $app = $this;
        $editor = $this->settingsEditor;
        if ($editor !== null) {
            $editor = $editor->withSaved($msg);
            if ($msg->ok()) {
                $editor = $editor->withResolved($this->settingsSources !== null
                    ? ($this->settingsSources)()
                    : SettingsSources::bestEffort($this->root));
            }

            $app = $this->mutate(settingsEditor: $editor);
        }

        if (!$msg->ok() || $app->chat === null) {
            return [$app, null];
        }

        [$chat, $cmd] = $app->chat->applySettings($msg->changed, $msg->path);

        return [$app->withChat($chat), $cmd];
    }

    /**
     * Open the settings view, its search pre-filled with `$query`.
     *
     * The values are resolved NOW, once: the view explains the launch's
     * settings as they stand when it is opened, and re-reading files on every
     * keystroke would be I/O in the update path for nothing.
     */
    public function openSettings(string $query = ''): self
    {
        $sources = $this->settingsSources !== null
            ? ($this->settingsSources)()
            : SettingsSources::bestEffort($this->root);

        MenuBar::closeMenu();

        return $this->mutate(settingsEditor: SettingsEditor::open($sources, $query));
    }

    public function closeSettings(): self
    {
        return $this->settingsEditor === null ? $this : $this->mutate(settingsEditor: null);
    }

    /**
     * Open the media generation form (crush-media W3.1), optionally seeded
     * with a draft. Pure state: the sections were built at open, the form
     * dials nothing — the Generate action comes back through
     * {@see applyMediaFormAction()}.
     */
    public function openMediaForm(?MediaRequest $request = null): self
    {
        MenuBar::closeMenu();
        // R13-m5 symmetry guard (prophylaxis — both-open is unreachable
        // today): the player door stops its live clip when a new clip
        // replaces it, and this door must not be the one hole where a
        // running player is orphaned behind the form. Player::stop is
        // latched and null-safe, so when nothing plays this costs nothing.
        $this->videoPlayer?->stop();

        // W3.4 runtime override: when an SD base is configured the live
        // /samplers + /schedulers sets replace the §2.9 enums (ChoiceFeed
        // caches per process and fails open to null on any error).
        //
        // crush-media W4.1 carried seam (lane d): the three media settings
        // the form renders — sd.presets, ui.mediaDisplayOverrides and
        // sd.savePattern — ride into open() here, parsed on this one
        // explicit request (the openSettings()/previewSettings() "one small
        // read" shape). Values that are not their declared type degrade to
        // null; a failing read fails open to a plain form. The persist door
        // keeps the W3.9 law that the form never names a SettingsWriter:
        // the hop lives in this seam, and refusals surface through the
        // form's own '!' status (SettingsWriter::write throws before it
        // writes anything).
        $settings = $this->mediaFormSettings();
        $writer = $this->settingsWriter;
        $door = $writer === null ? null : static function (array $map) use ($writer): void {
            $writer->write(SettingsTier::You, [MediaPresets::SETTINGS_KEY => $map]);
        };

        return $this->mutate(mediaForm: MediaForm::open(
            $request,
            ChoiceFeed::forForm(),
            // Absent on a young config = an EMPTY store, so the first save
            // through the door creates the key; present but not its Map
            // shape = null, and the form itself answers loudly.
            presets: \array_key_exists('sd.presets', $settings)
                ? (\is_array($settings['sd.presets']) ? $settings['sd.presets'] : null)
                : [],
            persistPresets: $door,
            displayOverrides: \is_array($settings['ui.mediaDisplayOverrides'] ?? null) ? $settings['ui.mediaDisplayOverrides'] : null,
            savePattern: \is_string($settings['sd.savePattern'] ?? null) && trim($settings['sd.savePattern']) !== ''
                ? $settings['sd.savePattern']
                : null,
        ));
    }

    /** The layered user config for form open; a failed read is an empty map. */
    private function mediaFormSettings(): array
    {
        try {
            return \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
        } catch (\Throwable) {
            return [];
        }
    }

    public function closeMediaForm(): self
    {
        return $this->mediaForm === null ? $this : $this->mutate(mediaForm: null);
    }

    /**
     * Raise the full-screen video player (crush-media W5.4) on a resolved
     * local path. Pure state plus one promise Cmd: the probe-and-spawn runs
     * inside {@see \SugarCraft\Core\Cmd::promise()} AFTER the "Preparing…"
     * placeholder is the painted frame, so a slow ffmpeg degrades to a
     * visible wait rather than a frozen UI. The build itself is the
     * synchronous, one-time cost the plan accepted; there is no wall-clock
     * deadline anywhere on it (E646 — the wait is bounded by the spawn, not
     * by a timer this shell invented).
     *
     * Replace-if-open law: opening a second clip stops the first Player
     * before the new build starts, so at most one decoder child lives per
     * slot at any instant.
     *
     * @param (callable(string,int,int,VideoMode):VideoPlayer)|null $playerFactory
     *        build seam (tests hand in a scripted decoder's Player; null
     *        selects {@see VideoPlayer::open()})
     * @return array{0: self, 1: ?\Closure}
     */
    public function openVideoPlayer(string $path, ?\Closure $playerFactory = null): array
    {
        MenuBar::closeMenu();
        $this->videoPlayer?->stop();

        $mode = self::videoPlayerMode();
        [$cellsW, $cellsH] = $this->videoPlayerCells();
        $factory = $playerFactory
            ?? static fn (string $p, int $w, int $h, VideoMode $m): VideoPlayer => VideoPlayer::open($p, $w, $h, mode: $m);

        return [
            $this->mutate(videoPlayer: VideoPlayerScreen::preparing($path, $mode)),
            CoreCmd::promise(static function () use ($factory, $path, $cellsW, $cellsH, $mode): \React\Promise\PromiseInterface {
                try {
                    $player = $factory($path, $cellsW, $cellsH, $mode);
                    // Adopt BEFORE the answer can be lost: an app exiting
                    // between build and dispatch still reaps the child.
                    VideoPlayerScreen::adopt($player);

                    return \React\Promise\resolve(new PlayerReadyMsg($player, null));
                } catch (\Throwable $error) {
                    return \React\Promise\resolve(new PlayerReadyMsg(null, $error->getMessage()));
                }
            }),
        ];
    }

    /** Close the player and stop its clip. The only path that drops the slot. */
    public function closeVideoPlayer(): self
    {
        if ($this->videoPlayer === null) {
            return $this;
        }

        $this->videoPlayer->stop();

        return $this->mutate(videoPlayer: null);
    }

    /**
     * Raise the checkpoint-diff review (C-TUI-2.e) on an already-loaded
     * model — the shell-slot twin of {@see openMediaForm()}: the command
     * dials {@see DiffReviewSource::load()} and hands the finished review
     * over as an {@see OpenDiffReviewMsg}; this only closes the menu and
     * takes the slot. Pure state: the patches are already in the model.
     */
    public function openDiffReview(DiffReview $review): self
    {
        MenuBar::closeMenu();

        return $this->mutate(diffReview: $review);
    }

    /** Drop the diff review if one stands (C-TUI-2.e); idempotent. */
    public function closeDiffReview(): self
    {
        return $this->diffReview === null ? $this : $this->mutate(diffReview: null);
    }

    /**
     * Raise the first-run wizard (C-TUI-5.e) — the shell-slot twin of
     * {@see openDiffReview()}: the launch command asks
     * {@see FirstRunWizard::shouldOffer()} and answers an
     * {@see OpenFirstRunWizardMsg}; this only closes the menu and takes the
     * slot with a fresh model. Already open → unchanged, so a doubled msg
     * never re-seeds the form under the user's keys.
     */
    public function openFirstRunWizard(): self
    {
        if ($this->firstRunWizard !== null) {
            return $this;
        }

        MenuBar::closeMenu();

        return $this->mutate(firstRunWizard: FirstRunWizard::open());
    }

    /** Drop the first-run wizard if one stands (C-TUI-5.e); idempotent. */
    public function closeFirstRunWizard(): self
    {
        return $this->firstRunWizard === null ? $this : $this->mutate(firstRunWizard: null);
    }

    /**
     * The launch's wizard offer (C-TUI-5.e), as a Cmd like every other
     * {@see init()} member — the opener decides, the shell owns the slot.
     * All three inputs are caller state, never model state
     * ({@see FirstRunWizard::shouldOffer()}): no provider was SELECTED for
     * this launch (env tier, `--provider`, or a persisted `provider` key —
     * the same reads {@see \SugarCraft\Code\Cli\Bootstrap} makes to build
     * the backend), no provider carries a usable API key in the user
     * config, and the terminal is a live interactive tty. Both seams are
     * injectable for pins: the tty probe is guarded by is_resource on each
     * stream because tests/bootstrap.php frees the PHP-level \STDIN
     * resource during its fd-0 repair — an unguarded stream_isatty(STDIN)
     * THROWS on that invalid resource under strict_types rather than
     * answering false — and with the guard the freed STDIN plus a piped
     * stdout answer false, so the default never fires in a test run; the
     * stamp path reads the real cache corner by default.
     */
    public function firstRunWizardOfferCmd(?bool $isInteractiveTty = null, ?string $stampPath = null): ?\Closure
    {
        $config = \SugarCraft\Code\Cli\Bootstrap::readUserConfig();
        $providers = $config['providers'] ?? null;
        $hasConfigured = false;
        if (is_array($providers)) {
            foreach ($providers as $block) {
                if (is_array($block) && is_string($block['apiKey'] ?? null) && $block['apiKey'] !== '') {
                    $hasConfigured = true;
                    break;
                }
            }
        }

        if (!FirstRunWizard::shouldOffer(
            \SugarCraft\Code\Cli\Bootstrap::selectedProviderName() !== null
                || \SugarCraft\Code\Cli\Bootstrap::backendCommandTierIsSelected(),
            $hasConfigured,
            // House pattern (HeadlessPermissionPrompt::isInteractive): the
            // probe must not touch a freed stream — tests/bootstrap.php
            // frees \STDIN during its fd-0 repair, and stream_isatty on the
            // invalid resource throws instead of answering false.
            $isInteractiveTty ?? ((\is_resource(STDIN) && stream_isatty(STDIN))
                || (\is_resource(STDOUT) && stream_isatty(STDOUT))),
            $stampPath,
        )) {
            return null;
        }

        return static fn (): CoreMsg => new OpenFirstRunWizardMsg();
    }

    /**
     * Land what the first-run wizard decided (C-TUI-5.e), the
     * {@see applyMediaFormAction()} twin for the wizard's three doors.
     * Finish stores the key through {@see ProviderKeyStore::store()} and
     * then folds the live chat through {@see \SugarCraft\Code\Chat::switchProvider()}
     * — the exact `/model <provider>` path — so the band closes on a working
     * provider without a restart; a store refusal closes the band too and
     * rides the status footer verbatim (the `login` door says the same
     * sentence and the typed key is not lost from the file's reach — the
     * user re-runs it with the fix the refusal names). Skip and Cancel both
     * close WITHOUT a key, the stamp's exact contract
     * ({@see FirstRunWizard::markDismissed()}): the wizard never re-offers
     * after either, and the 5.d model documents the dismissal as the
     * shared fate of both no-key exits, so the design's "Skip writes the
     * dismissal stamp" reads as the general no-key law here. Skip carries
     * the provider it was deferring and the status echoes the login command
     * for it; Cancel decided nothing at all.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function applyFirstRunWizardAction(FirstRunWizardAction $action): array
    {
        return match ($action->kind) {
            FirstRunWizardActionKind::Cancel => $this->dismissFirstRunWizard(status: null),
            FirstRunWizardActionKind::Skip => $this->dismissFirstRunWizard(
                status: Lang::t('keys.wizard.skip_note', ['command' => 'candy-code login' . ($action->provider !== null ? ' ' . $action->provider : '')]),
            ),
            FirstRunWizardActionKind::Finish => $this->finishFirstRunWizard($action),
        };
    }

    /**
     * The no-key exits: stamp the dismissal (the one write, best-effort
     * inside the model), drop the slot, optionally leave a status note.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function dismissFirstRunWizard(?string $status): array
    {
        FirstRunWizard::markDismissed();

        $closed = $this->closeFirstRunWizard();

        return [$status === null ? $closed : $closed->withStatus($status), null];
    }

    /**
     * The Finish door: store, then switch. Order matters — a refusal
     * before the store never touches the live backend, so a bad key can
     * not strand the chat on a provider whose credential was refused.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function finishFirstRunWizard(FirstRunWizardAction $action): array
    {
        $provider = (string) $action->provider;
        $closed = $this->closeFirstRunWizard();

        $refusal = ProviderKeyStore::store($provider, (string) $action->key);
        if ($refusal !== null) {
            return [$closed->withStatus($refusal), null];
        }

        if ($this->chat === null) {
            // No hosted chat to switch (a bare shell): the key is stored
            // and the next launch picks it up — say exactly that.
            return [$closed->withStatus(Lang::t('keys.wizard.stored', ['provider' => $provider])), null];
        }

        [$nextChat, $cmd] = $this->chat->switchProvider($provider);

        return [$closed->mutate(chat: $nextChat), $cmd];
    }

    /**
     * Hand one key to the open review and land what it decides
     * (C-TUI-2.e). The model never answers with a null next state — close
     * travels as the Close action — but the shell keeps the mutate honest
     * by routing through {@see applyDiffReviewAction()} either way.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function driveDiffReview(KeyMsg $msg): array
    {
        $review = $this->diffReview;
        \assert($review !== null);

        [$next, $action] = $review->update($msg);

        if ($action !== null) {
            return $this->applyDiffReviewAction($action, $next);
        }

        return [$this->mutate(diffReview: $next), null];
    }

    /**
     * Land a review decision (C-TUI-2.e). Close drops the slot; a reload
     * (the `-`/`+`/`t` keys moved the base or the turn-only door) redials
     * {@see DiffReviewSource} against the hosted chat's store — the model
     * carries no store of its own. When the checkpoints moved on under the
     * open review, the reason rides the status footer and the band closes;
     * with no store behind the window (a bare preview App) the head-moved
     * model stays as-is, the honest no-redial shape.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function applyDiffReviewAction(DiffReviewAction $action, DiffReview $next): array
    {
        if ($action->kind === DiffReviewActionKind::Close) {
            return [$this->closeDiffReview(), null];
        }

        $dialled = $this->dialDiffReview($action->stepsBack, $action->turnOnly);
        if ($dialled instanceof DiffReview) {
            return [$this->mutate(diffReview: $dialled), null];
        }

        if (is_string($dialled)) {
            return [$this->closeDiffReview(), static fn (): CoreMsg => new StatusMsg($dialled)];
        }

        return [$this->mutate(diffReview: $next), null];
    }

    /**
     * The shell-side reload dial (C-TUI-2.e): re-load the review at a new
     * base from the hosted chat's store, session and root — the exact
     * triple the command used at open. Null when the window has no
     * checkpointed store to ask (a bare preview App); a string is the
     * plain-English reason the load answered with.
     */
    private function dialDiffReview(int $stepsBack, bool $turnOnly): DiffReview|string|null
    {
        $store = $this->chat?->sessionStore();
        $sessionId = $this->chat?->currentSessionId();
        if (!$store instanceof \SugarCraft\Code\Session\EnhancedSessionStore || $sessionId === null || $sessionId === '') {
            return null;
        }

        return DiffReviewSource::load($store, $sessionId, $this->chat->projectRoot(), $stepsBack, $turnOnly);
    }

    /**
     * Open the message picker over these prompts, newest first (C-TUI-3.d).
     * Public for the shell's own dial below and for the wiring observation
     * in the key-binding drift guard; on the review's open law — close the
     * menu bar and take the slot, the rows being plain strings already read
     * from the store by the caller.
     *
     * @param list<string> $promptsNewestFirst
     */
    public function openMessagePicker(array $promptsNewestFirst): self
    {
        MenuBar::closeMenu();

        return $this->mutate(messagePicker: MessagePicker::open($promptsNewestFirst));
    }

    /** Drop the message picker if one stands (C-TUI-3.d); idempotent. */
    public function closeMessagePicker(): self
    {
        return $this->messagePicker === null ? $this : $this->mutate(messagePicker: null);
    }

    /**
     * Hand one key to the open picker and land what it decides (C-TUI-3.d)
     * — the review's drive law, one step down: the picker answers with a
     * close or an accept, never with a redial (its rows are frozen at the
     * press pair — see {@see OpenMessagePickerMsg}).
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function driveMessagePicker(KeyMsg $msg): array
    {
        $picker = $this->messagePicker;
        \assert($picker !== null);

        [$next, $action] = $picker->update($msg);

        if ($action !== null) {
            return $this->applyMessagePickerAction($action, $next);
        }

        return [$this->mutate(messagePicker: $next), null];
    }

    /**
     * Land a picker decision (C-TUI-3.d). Close drops the slot. Accept
     * rewinds the hosted chat through its public
     * {@see Chat::rewindToCheckpoint()} — the very `/rewind N --chat` host
     * command, so the restore seeds the checkpoint's prompt and caret into
     * the composer on the command's own effect path — and the chat's Cmd
     * (if any) leaves the shell exactly as it does on the delegation tail:
     * swapped into the App, panes re-armed, deck re-keyed, scoped.
     *
     * @return array{0: self, 1: (callable(): \SugarCraft\Core\Msg)|null}
     */
    private function applyMessagePickerAction(MessagePickerAction $action, MessagePicker $next): array
    {
        if ($action->kind === MessagePickerAction::CLOSE) {
            return [$this->closeMessagePicker(), null];
        }

        $chat = $this->chat;
        if ($chat === null) {
            return [$this->closeMessagePicker(), null];
        }

        [$settled, $chatCmd] = $chat->rewindToCheckpoint($action->stepsBack);
        $app = $settled instanceof Chat && $settled !== $chat
            ? $this->withChat($settled)->autoEnablePanes()->rekeyDeck()
            : $this;
        $app = $app->mutate(messagePicker: null);

        return [$app, $app->scopeForegroundCmd($chatCmd)];
    }

    /**
     * Answer the picker message with rows fresh from the hosted chat's
     * store (C-TUI-3.d): one row per conversation checkpoint, newest first,
     * labelled with the prompt that checkpoint restores. A window with no
     * checkpoints still opens — the picker's empty box is the honest answer
     * — while a bare preview App with no store at all keeps the door shut.
     */
    private function dialMessagePicker(): array
    {
        $store = $this->chat?->sessionStore();
        $sessionId = $this->chat?->currentSessionId();
        if (!$store instanceof \SugarCraft\Code\Session\EnhancedSessionStore || $sessionId === null || $sessionId === '') {
            return [$this, null];
        }

        return [$this->openMessagePicker(self::checkpointPromptRows($store->listCheckpoints($sessionId))), null];
    }

    /**
     * The picker's row labels: the draft each checkpoint re-seeds
     * ({@see \SugarCraft\Code\Host\Checkpoints}), read off the store's
     * newest-first rows in the order that makes position k mean "k steps
     * back" — the same counting a `/rewind k` takes.
     *
     * @param list<array<string, mixed>> $checkpoints
     *
     * @return list<string>
     */
    private static function checkpointPromptRows(array $checkpoints): array
    {
        $rows = [];
        foreach ($checkpoints as $checkpoint) {
            $state = $checkpoint['state_data'] ?? null;
            $rows[] = is_array($state) ? (string) ($state['inputBuf'] ?? '') : '';
        }

        return $rows;
    }

    /**
     * The render mode the player opens in (plan §W5.4 ruling): a crush
     * `ui.imageRenderMode` naming one of sugar-video's modes is honoured,
     * every other answer (the `auto` default and the `half`/`quarter`/
     * `chafa` spellings with no video analogue) fails soft to the player's
     * OWN auto-ladder — video-first sixel > kitty > iterm2 > truecolor
     * halfblock > ansi256 > ascii.
     */
    private static function videoPlayerMode(): VideoMode
    {
        $word = strtolower(trim(\SugarCraft\Code\ToolResult::renderMode()));

        return VideoMode::tryFrom($word) ?? VideoRendererFactory::autoMode();
    }

    /**
     * The cell grid handed to the Player: the shell band minus the chrome
     * the frame renderer stacks around it (menu bar, notice, bottom, the
     * scrubber row, one row of slack), floored so a tiny terminal still
     * gets a picture rather than a zero-height decode.
     *
     * @return array{0: int, 1: int} cellsW, cellsH
     */
    private function videoPlayerCells(): array
    {
        $size = TuiRenderer::terminalSize();
        $cols = $this->cols ?? (int) ($size['cols'] ?? 80);
        $rows = $this->rows ?? (int) ($size['rows'] ?? 24);

        return [max(20, $cols), max(4, $rows - 5)];
    }

    /**
     * Drive the open player with one message; with the slot empty the
     * message is DROPPED (a stray video tick must never reach Chat, which
     * has no answer for it) — and a Player built for a slot that closed
     * mid-build is stopped here rather than leaked.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function driveVideoPlayer(CoreMsg $msg): array
    {
        if ($this->videoPlayer === null) {
            if ($msg instanceof PlayerReadyMsg) {
                $msg->player?->stop();
            }

            return [$this, null];
        }

        [$screen, $cmd] = $this->videoPlayer->update($msg);

        return [$screen === $this->videoPlayer ? $this : $this->mutate(videoPlayer: $screen), $cmd];
    }

    /**
     * Act on what the open form decided. Generate closes the modal and feeds
     * the collected draft to the chat's GenerateFromMediaFormMsg arm — the
     * same {@see \SugarCraft\Code\Host\Commands\GenerateHostCommand}
     * execution path the typed command uses, one dispatch, never a fork.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function applyMediaFormAction(MediaFormAction $action): array
    {
        return match ($action->kind) {
            MediaFormActionKind::Cancel => [$this->closeMediaForm(), null],
            MediaFormActionKind::Generate => $this->generateFromMediaForm($action->request),
            // crush-media W4.1: `s` binds here. The form stays open — the
            // save is its own Presets-tab machinery running through the
            // door wired at openMediaForm(), and refusals land as its '!'
            // status. The carried request rides no further on purpose:
            // presets store collectValues() lattice data, never payload
            // bytes (ImageSection keeps source rows off the lattice).
            MediaFormActionKind::SavePreset => $this->savePresetFromForm(),
            // crush-media W4.4: `i` binds here. The import is local-first —
            // the attached PNG's own parameters chunk — and the server
            // png-info helper is consulted only when that chunk is absent
            // AND this launch configures an SD base over a family the
            // import speaks (the zero-dial law, ImportPng::serverInfoFor).
            // Refusals land as the form's '!' status; the arm can never
            // swallow the action silently.
            MediaFormActionKind::ImportPng => $this->importPngFromForm(),
        };
    }

    /**
     * The W4.4 `i` door: fold the attached PNG's parameters over the form's
     * draft ({@see MediaForm::importAttachedPng()}). The form stays open —
     * like the save door, everything visible about the outcome is the
     * status row; the draft is the wire truth Generate folds from.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function importPngFromForm(): array
    {
        $form = $this->mediaForm;
        if ($form === null) {
            return [$this, null];
        }

        $serverInfo = static fn (string $base64): ?string => ImportPng::serverInfoFor($base64);

        return [$this->mutate(mediaForm: $form->importAttachedPng($serverInfo)), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function savePresetFromForm(): array
    {
        $form = $this->mediaForm;
        if ($form === null) {
            return [$this, null];
        }

        return [$this->mutate(mediaForm: $form->saveFocusedPreset()), null];
    }

    /** One ceiling sentence for both the stat cap and the strlen backstop (R10-m2). */
    private const PASTE_CEILING_STATUS = '! clipboard image is over the ' . ImageSource::MAX_BYTES . '-byte ceiling';

    /**
     * The W4.2 paste landing. The clipboard temp file is volatile — the
     * bytes are admitted through {@see ImageIngress} (the single validation
     * door) and persisted into the MediaStore session directory BEFORE any
     * form state names them (volatile-temp law, plan W4.2 microstep 1).
     * The form then takes the slot through its own withClipboardImage();
     * the volatile path never enters form state, and a refused paste never
     * lands bytes in the store. Text/no-image clipboard answers with the
     * same sentence the chat surface shows.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function pasteIntoMediaForm(ClipboardImagePastedMsg $msg): array
    {
        $form = $this->mediaForm;
        if ($form === null) {
            return [$this, null];
        }

        // Cap before the read (R10-m2, mirroring ImageIngress::fromPath): a
        // multi-gigabyte clipboard temp must never be slurped into memory
        // just to be measured. The strlen pass below stays as the backstop
        // for temps whose size cannot be stat'ed.
        $size = $msg->path === null ? false : @filesize($msg->path);
        if ($size !== false && $size > ImageSource::MAX_BYTES) {
            return [$this->mutate(mediaForm: $form->withStatus(self::PASTE_CEILING_STATUS)), null];
        }

        $bytes = $msg->path === null ? false : @file_get_contents($msg->path);
        if ($bytes === false || $bytes === '') {
            return [$this->mutate(mediaForm: $form->withStatus(Lang::t('chat.clipboard.no_image'))), null];
        }

        if (\strlen($bytes) > ImageSource::MAX_BYTES) {
            return [$this->mutate(mediaForm: $form->withStatus(self::PASTE_CEILING_STATUS)), null];
        }

        try {
            // Validate-then-persist, in that order: the ingress door is
            // cheap enough to walk twice rather than leak refused bytes
            // into the store.
            $ingress = ImageIngress::fromBytes($bytes, MediaForm::CLIPBOARD_ORIGIN);
            MediaStore::forSession($this->sessionId ?? 'clipboard')->put($ingress->bytes, 'png', []);
        } catch (\InvalidArgumentException | \RuntimeException $error) {
            return [$this->mutate(mediaForm: $form->withStatus('! ' . $error->getMessage())), null];
        }

        return [$this->mutate(mediaForm: $form->withClipboardImage($ingress->bytes)), null];
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function generateFromMediaForm(?MediaRequest $request): array
    {
        if ($request === null) {
            throw new \LogicException('a Generate action must carry the collected request');
        }

        return $this->closeMediaForm()->delegateToChat(new GenerateFromMediaFormMsg($request));
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
     * this (see {@see \SugarCraft\Code\Tui\Renderer::sideWidth()}): while
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
     * sides that have slots (sugar-constraints DockLayout.php:370-375).
     * A dock that is already non-default still gets ONE further seeding, per
     * ISS-049: when a mutation ACTIVATES a side that is empty and still
     * carries the untouched 1/3 design share, that side receives the same
     * quarter measure. Without it, the first docked side seeds a quarter at
     * the legacy hand-off and every later side activates at thirds — the
     * "side docked by a later mutation keeps the 1/3 default" asymmetry the
     * issue names. A side whose share a divider drag has ever measured is
     * never re-snapshot: seeding stays a hand-off from the drawn frame, not
     * a overwrite of sizes the user has chosen.
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
        if ($bandCols < 1) {
            return $dock;
        }

        $share = [max(20, intdiv($bandCols, 4)), $bandCols];
        $manifest = $dock->toArray();

        if (self::isUntouchedDefaultDock($dock)) {
            foreach (Side::cases() as $side) {
                if ($dock->slots($side) !== [] || $side === $docksInto) {
                    $manifest['columnShare'][$side === Side::Left ? 'left' : 'right'] = $share;
                }
            }

            return DockLayout::fromArray($manifest);
        }

        // ISS-049: a side being newly activated on an already-handed-off dock
        // that still carries the design default was never drawn, so the same
        // quarter measure belongs to it.
        if (
            $docksInto !== null
            && $dock->slots($docksInto) === []
            && self::sideCarriesDesignDefault($dock, $docksInto)
        ) {
            $manifest['columnShare'][$docksInto === Side::Left ? 'left' : 'right'] = $share;

            return DockLayout::fromArray($manifest);
        }

        return $dock;
    }

    /**
     * Whether a side's column share is byte-identical to the library's
     * design default (thirds) — the marker of a side nobody has ever
     * measured.
     */
    private static function sideCarriesDesignDefault(DockLayout $dock, Side $side): bool
    {
        $share = $dock->columnShare($side);

        return $share['num'] === DockLayout::DEFAULT_SHARE_NUM
            && $share['denom'] === DockLayout::DEFAULT_SHARE_DENOM;
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
     * Replace the auto-show memory. The launch path uses this to re-seed the
     * panes the user toggled by hand in a previous session (ISS-049), so a
     * pane that was closed stays closed across restarts instead of riding
     * back in on the first content transition.
     */
    public function withPaneAutoShow(PaneAutoShow $v): self
    {
        return $this->mutate(paneAutoShow: $v);
    }

    /**
     * P-TABS.d: install the tab strip's session factory (field only — the
     * routing lands in P-TABS.e).
     */
    public function withTabFactory(?\Closure $v): self
    {
        return $this->mutate(tabFactory: $v);
    }

    public function withCompactorConfig(?CompactorConfig $v): self
    {
        return $this->mutate(compactorConfig: $v);
    }

    /** See {@see $contextLedger}. */
    public function withContextLedger(?\SugarCraft\Code\Context\Pruning\ContextLedger $v): self
    {
        return $this->mutate(contextLedger: $v);
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

        return $this->persistDock($this->mutate(
            dock: $next,
            paneAutoShow: $this->paneAutoShow->withUserToggled($pane),
        ));
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
                paneAutoShow: $this->paneAutoShow->withUserToggled($pane),
            ));
        }

        $side = $pane->dockSide();
        assert($side !== null); // guarded by dockable() above

        $dock = self::seedSharesFromFrame($this->dock(), $this->cols ?? 0, $side);

        return $this->persistDock($this->mutate(
            dock: $dock->withSlotAdded($side, $pane->value),
            paneAutoShow: $this->paneAutoShow->withUserToggled($pane),
        ));
    }

    /**
     * Return the dock to the launch default ({@see defaultDock()}).
     *
     * The auto-show memory resets with it (ISS-049): the toggled list rides
     * the same persisted `layout` blob ({@see layoutBlob()}), and a reset
     * that left the list standing would resurrect yesterday's "never show
     * this pane" verdicts into a layout the user just asked to have no
     * verdicts — the next activity auto-docks again, which is what "reset"
     * means for this feature.
     */
    public function layoutReset(): self
    {
        return $this->persistDock($this->mutate(
            dock: self::defaultDock(),
            paneAutoShow: PaneAutoShow::new(),
        ));
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

    /**
     * The panes that dock themselves the first time they have content
     * (CL-2 FIX 1). Attachments ride the Files pane; there is no dedicated
     * attachments surface to add here.
     */
    private const AUTO_ENABLE_PANES = [Pane::Todo, Pane::Agents, Pane::Tools, Pane::Files, Pane::Context, Pane::Sessions];

    /**
     * The frame width from which the Context pane may auto-dock (plan §6.1
     * ruling for P-CTX.e): the box earns its right-of-pane columns only on a
     * genuinely wide terminal, and the narrower the frame the earlier
     * {@see AUTO_DOCK_MIN_CHAT_COLS} refuses it anyway — this gate is the
     * explicit "wide terminals only". An unknown (null) width REFUSES here —
     * the predicate reads {@code $this->cols ?? 0} and 0 never reaches 120 —
     * the opposite polarity from {@see AUTO_DOCK_MIN_CHAT_COLS}'s stand-down:
     * an unmeasured frame leaves the offer pending, retried at the next resize.
     */
    public const AUTO_DOCK_CONTEXT_MIN_COLS = 120;

    /**
     * ISS-049: the auto-show never starves the center. Before an automatic
     * dock lands, the TRIAL layout (this pane's side seeded and slotted) is
     * resolved against the last measured frame; when the chat column would
     * fall below this many columns, the dock is skipped. The pane is NOT
     * marked settled by the skip — the guard re-evaluates at every later
     * Chat transition, so widening the terminal lets the pending pane dock
     * on its own once there is room for it. A manual dock through
     * {@see setPaneSide()} or {@see togglePaneDocking()} is user intent and
     * stays ungated; only this width-blind automation consults the floor.
     *
     * Before the first {@see \SugarCraft\Core\Msg\WindowSizeMsg} there is no
     * measured frame to resolve against — the same unknown that makes
     * {@see seedSharesFromFrame()} a no-op — and the guard stands down
     * (allow) rather than silently killing the feature on a headless or
     * not-yet-sized launch.
     */
    public const AUTO_DOCK_MIN_CHAT_COLS = 80;

    /**
     * Dock every roster pane whose predicate just turned true, once per pane
     * per process, on its default side ({@see Pane::dockSide()}).
     *
     * Settled panes — already auto-shown, or touched by the user by hand —
     * are never re-docked, so a manual close is honored for the remainder of
     * the process. Runs at the single choke every Chat transition lands on
     * ({@see delegateToChat()}); the dock write rides the same
     * {@see persistDock()} channel a menu toggle uses, so layout
     * persistence is ordinary, not special-cased.
     */
    public function autoEnablePanes(): self
    {
        $app = $this;
        foreach (self::AUTO_ENABLE_PANES as $pane) {
            $app = $app->autoEnablePane($pane);
        }

        return $app;
    }

    private function autoEnablePane(Pane $pane): self
    {
        if ($this->paneAutoShow->settled($pane) || $this->isDocked($pane)) {
            return $this;
        }

        $hasActivity = match ($pane) {
            Pane::Todo => !TodoPane::todos($this)->isEmpty(),
            Pane::Agents => AgentDashboardPane::entries($this) !== [],
            Pane::Tools => ToolsPane::hasCalls($this),
            Pane::Files => FilesPane::hasFiles($this),
            // P-CTX.e: "activity" is a settled measurement in hand — the
            // first reply's snapshot — plus the wide-terminal gate the §6.1
            // ruling attaches. Before either, the box would only advertise
            // "measuring…" in a column the user never asked for.
            Pane::Context => ($this->cols ?? 0) >= self::AUTO_DOCK_CONTEXT_MIN_COLS
                && ContextPane::heldCategories($this) !== null,
            // P-TABS.g: the Sessions pane earns its column the moment the
            // deck genuinely has somewhere to go — one tab has nowhere to
            // switch to, two is a window list worth showing. The design's
            // "auto-docks once when the second tab opens"; chat readability
            // stays gated by AUTO_DOCK_MIN_CHAT_COLS like every auto-dock,
            // and unlike Context there is no wide-terminal law to borrow.
            Pane::Sessions => ($this->deck?->count() ?? 0) >= 2,
            default => false,
        };
        if (!$hasActivity) {
            return $this;
        }

        $side = $pane->dockSide();
        assert($side !== null); // guarded: the roster is dockable-only

        $dock = self::seedSharesFromFrame($this->dock(), $this->cols ?? 0, $side);
        $trial = $dock->withSlotAdded($side, $pane->value);
        if (!$this->autoDockKeepsChatReadable($trial)) {
            return $this;
        }

        $next = $this->mutate(
            dock: $trial,
            paneAutoShow: $this->paneAutoShow->withAutoShown($pane),
        );

        return $this->persistDock($next);
    }

    /**
     * Resolve the trial dock against the last measured frame and check the
     * chat column against {@see AUTO_DOCK_MIN_CHAT_COLS}. See that constant
     * for the policy, the retry semantics, and the stand-down on unknown
     * width.
     */
    private function autoDockKeepsChatReadable(DockLayout $trial): bool
    {
        $bandCols = $this->cols ?? 0;
        if ($bandCols < 1) {
            return true;
        }

        $frame = new Region(0, 0, $bandCols, max(1, $this->rows ?? 1));

        return ($trial->resolve($frame)->regionFor($trial->centerPaneId)?->width ?? 0)
            >= self::AUTO_DOCK_MIN_CHAT_COLS;
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
     * cycle. A malformed manifest id — or one naming a pane that is not
     * dockable (input/help/menu have no dock slot the frame paints) — is
     * skipped rather than fatal, mirroring {@see \SugarCraft\Code\Tui\Renderer::sidePanes()}:
     * focus cannot name a pane the enum does not have, nor one it never
     * renders.
     *
     * @return list<Pane>
     */
    public function paneCycleOrder(): array
    {
        $order = [Pane::Chat];

        foreach ([Side::Left, Side::Right] as $side) {
            foreach ($this->dock()->slots($side) as $slot) {
                $pane = Pane::tryFrom((string) $slot->paneId);

                // Same dockable() filter Renderer::sidePanes() applies: a
                // stale or hand-edited manifest naming a non-dockable pane
                // (input/help/menu) must not park Tab focus on a pane the
                // frame never paints (renderPane defaults to ''). (lane p3)
                if ($pane !== null && $pane->dockable() && !in_array($pane, $order, true)) {
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
     * directions. That is the same anchor rule {@see \SugarCraft\Code\Tui\Pane::step()}
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
     * The blob the `layout` setting stores: the dock manifest plus — only
     * when non-empty, so a never-toggled session's blob stays byte-identical
     * to the pure manifest — the panes the user touched by hand
     * (ISS-049). {@see \SugarCraft\Code\Cli\Bootstrap::dockFromUserConfig()}
     * reads the manifest through the key paths
     * {@see \SugarCraft\Constraints\Dock\DockLayout::fromArray()} knows
     * (unknown keys are ignored), and the sibling reader folds `userToggled`
     * back onto the launch model.
     *
     * @return array<string,mixed>
     */
    public function layoutBlob(): array
    {
        $blob = $this->dock()->toArray();
        $toggled = $this->paneAutoShow->userToggled();
        if ($toggled !== []) {
            $blob['userToggled'] = $toggled;
        }

        return $blob;
    }

    /**
     * Hand the new layout blob to the persistence hook, when the launch
     * wired one. Mirrors Chat's config-change call sites: a non-`update()`
     * model method invoking an injected persistence closure is the
     * established shape here, and the App holding the closure is the
     * mutated COPY (readonly), whose hook is the same object the builder
     * installed.
     */
    private function persistDock(self $next): self
    {
        $hook = $next->onLayoutChange;
        if ($hook !== null) {
            // __invoke, not call(): Bootstrap wires a STATIC closure, and an
            // attempted rebind of a static closure is a silently-skipped
            // warning, not a call — the persistence would never fire.
            $hook->__invoke($next->layoutBlob());
        }

        return $next;
    }

    /**
     * The colour theme the SHELL's chrome paints with.
     *
     * Delegated to the hosted {@see Chat} rather than stored here, because the
     * theme name is Chat state ({@see Chat::withThemeName()}, persisted by
     * `/theme` and reloaded by {@see \SugarCraft\Code\Cli\Bootstrap}) and a
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
     * Skills declared `context: fork` are excluded from the splice — their
     * bodies belong to a forked sub-agent. The model-driven fork runs them
     * through the Skill tool on the turn's bound Task (C-EXT-2.c);
     * {@see dispatchSkill()} stays the embedder seam for a caller holding a
     * pool. Neither path inlines a fork's body into the primary
     * conversation's system prompt.
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

            // ISS-035: the picker enables stage-1 manifest rows whose content
            // is empty by construction; graft the body from disk so this
            // channel splices instructions, not a bare heading.
            $result .= $skill->withBodyResolved()->systemPromptContribution();
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
     * C-EXT-2.c added the OTHER fork path — the model-driven one: a
     * `context: fork` skill invoked through the Skill tool now runs on the
     * turn's bound Task ({@see \SugarCraft\Code\Tools\BuiltIn\SkillTool},
     * {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool::runForkedSkill()}). This
     * method stays what it always was — the embedder seam for a caller that
     * holds an {@see AgentWorkerPool} and the task in hand — and, as the
     * tripwire below pins, still has no production caller of its own.
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
     * {@see \SugarCraft\Code\Tests\App\AppSkillDispatchTest} instead.
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
     *     for {@see \SugarCraft\Code\Chat}, not for the shell.
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
     *
     * ## THE FORK'S TIME BUDGET (audit AG-5)
     *
     * `$timeoutSeconds` becomes the SubAgent's `timeout`, which the pool has
     * ENFORCED on its forking path since WF-1 (a): the worker and its whole
     * process tree are killed when it expires. The SubAgent constructor's
     * default is 300 seconds, and this path used to take it silently — so the
     * day a caller wires this up, every fork-context skill would have been
     * killed at five minutes whatever the configured pool timeout said. The
     * default here is 0, no per-agent bound, because this method has no
     * configuration of its own to read; a caller with an
     * {@see \SugarCraft\Code\Agents\AgentPoolConfig} passes its
     * `defaultTimeoutSeconds`, as `Chat::executeAgents()`' process branch does.
     *
     * @param int $timeoutSeconds the fork's wall-clock bound; 0 or less is none
     */
    public function dispatchSkill(Skill $skill, AgentWorkerPool $pool, string $task, int $timeoutSeconds = 0): ?AgentResult
    {
        if (!$this->availableSkills->isContextFork($skill->name)) {
            return null;
        }

        // C-EXT-2.c: the Agent construction — identity, the skill's declared
        // model over the session's, the environment block captured at $root
        // under that model, the declared effort — moved to
        // {@see SkillFork::agent()}, the shared shape behind both fork paths.
        // The reasons the capture happens here rather than at
        // `Agent::systemPrompt()`'s last resort are written down there,
        // verbatim; nothing about the payload this method hands the pool
        // changed.
        $agent = SkillFork::agent($skill, $this->model, $this->provider->name(), $this->root);

        $subAgent = new SubAgent(
            // E329, same family as AgentManager's SubAgent id: a constant
            // literal prefix contributes no cross-process entropy at all.
            id: uniqid('skill_fork_' . getmypid() . '_', true),
            agent: $agent,
            task: $task,
            timeout: $timeoutSeconds,
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
     * per program start so the pop in bin/candy-code stays balanced.
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
            // returns in bin/candy-code, where it also covers the SIGINT and
            // kill() exits that never let a model emit a Cmd.
            CoreCmd::pushKittyKeyboard(KeyboardEnhancementsMsg::DISAMBIGUATE),
            $this->chat?->init(),
            $this->pruneAgentArtifacts(),
            // C-TUI-5.e: the first-run wizard rides the same batch — a Cmd,
            // so nothing here blocks, and a null when the launch already
            // knows a provider. The trust dialog keeps FIRST refusal among
            // the modals (its own law at dispatchKey); the wizard opening
            // under an unresolved trust prompt answers trust first, exactly
            // like a launch with both stacked would.
            $this->firstRunWizardOfferCmd(),
        );
    }

    /**
     * The launch's sweep of sub-agent transcript logs and mailboxes whose
     * session is gone (roadmap P-D1's retention remainder,
     * {@see \SugarCraft\Code\Agents\AgentManager::pruneSessionArtifacts()}) — as
     * a Cmd, so the first frame never waits on the directory walk, and only
     * when the hosted chat has a session store to ask. A failure is
     * swallowed: stale files are not a reason to disturb a launch.
     */
    private function pruneAgentArtifacts(): ?\Closure
    {
        $store = $this->chat?->sessionStore();
        if ($store === null) {
            return null;
        }

        return static function () use ($store): null {
            try {
                \SugarCraft\Code\Agents\AgentManager::pruneSessionArtifacts(
                    static fn (string $sessionId): bool => $store->session($sessionId) !== null,
                );
            } catch (\Throwable) {
                // Best effort — see above.
            }

            return null;
        };
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
     * The parameter widened from this file's own `Msg` to sugar-core's:
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
        // P-TABS.e: the deck arms itself once, on the first message, on any
        // launch that carries a tabFactory. Arming here (not in
        // LaunchContext::app()) keeps that builder untouched and seeds from
        // the live foreground Chat — the only place the launch's session id
        // and tab name are known. With no factory the check is a single
        // null read and the shell below runs exactly as it did before.
        return $this->armDeck()->updateShell($msg);
    }

    /**
     * The shell's message routing — see the class docblock and
     * {@see update()} for the contract. Public only through {@see update()};
     * the wrapper above adds the one deck-arming check so this body never
     * has to.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function updateShell(CoreMsg $msg): array
    {
        // P-MENU.e hover: the bar's menus highlight rows under the pointer,
        // and button-less motion is reported ONLY in all-motion mouse mode —
        // CellMotion (the Chat's steady state) never sends it. So ONE
        // transition check here, comparing the open-menu count before and
        // after the message, swaps AllMotion in for every open path and back
        // out for EVERY close path — Esc, a row that ran, a click-away, a
        // drag that armed — without any of those sites knowing about it.
        // Skipped entirely when the mouse is Off (there is no mode to
        // restore and no motion to feed).
        $menuWasOpen = MenuBar::activeMenu() > 0;

        $result = match (true) {
            $msg instanceof WindowSizeMsg => $this->handleWindowSize($msg),
            $msg instanceof OpenSettingsMsg => [$this->openSettings($msg->query), null],
            $msg instanceof SettingsSavedMsg => $this->settingsSaved($msg),
            $msg instanceof SettingsFileEditedMsg => $this->settingsFileEdited($msg),
            $msg instanceof SettingsProfileMsg => [$this->settingsProfileDone($msg), null],
            $msg instanceof OpenMediaFormMsg => [$this->openMediaForm($msg->request), null],
            // C-TUI-5.e: the wizard opens like the form — command decides,
            // shell owns the slot — but with no payload to carry.
            $msg instanceof OpenFirstRunWizardMsg => [$this->openFirstRunWizard(), null],
            // crush-media W5.4: the player opens like the form — command
            // decides, shell owns the slot — and the Player's own tick /
            // rebuild messages are the shell's to route while it is up.
            $msg instanceof OpenVideoPlayerMsg => $this->openVideoPlayer($msg->path),
            $msg instanceof PlayerReadyMsg => $this->driveVideoPlayer($msg),
            $msg instanceof VideoTickMsg || $msg instanceof VideoResizeRebuildMsg => $this->driveVideoPlayer($msg),
            // crush-media W4.2: with the form up, a clipboard paste lands in
            // the img2img slot, not in the input box. Form down → the msg
            // rides on to Chat's @mention arm untouched.
            $msg instanceof ClipboardImagePastedMsg && $this->mediaForm !== null => $this->pasteIntoMediaForm($msg),
            // C-TUI-2.e: the review opens like the form and the player — the
            // command dials DiffReviewSource and answers this msg, the shell
            // owns the slot. A null review with a reason is the load failing
            // on a live store (a Reload after the checkpoints moved on): the
            // reason rides the status footer, the band closes.
            $msg instanceof OpenDiffReviewMsg => $msg->review !== null
                ? [$this->openDiffReview($msg->review), null]
                : ($msg->reason === '' ? [$this, null] : [$this->withStatus($msg->reason), null]),
            $msg instanceof OpenMessagePickerMsg => $this->dialMessagePicker(),
            $msg instanceof UserInputMsg,
            $msg instanceof SelectPaneMsg,
            $msg instanceof LayoutResetMsg,
            $msg instanceof ToolResultMsg,
            $msg instanceof ErrorMsg,
            $msg instanceof StatusMsg,
            $msg instanceof OpenSkillPickerMsg,
            $msg instanceof SelectSkillMsg => self::withoutEngineCmd($this->dispatch($msg)),
            // P-CTX.e: the docking entry point rides OUTSIDE withoutEngineCmd.
            // Its toggle arm is the only producer of a live second slot — the
            // one fresh-dock context measurement — and an engine Cmd was never
            // what the old swallow guarded here (every other arm returned
            // null); nulling it again would silently drop the measurement on
            // the meter, tab and slash-command paths alike. `SelectPaneMsg`
            // rides the batch arm above through withoutEngineCmd, not this one:
            // it is a focus chooser, not a docking mutation, and never carries
            // the closure.
            $msg instanceof DockPaneMsg => $this->applyDockCommand($msg),
            // A click on an `agent:` zone in the chat (P-B3), the picker's
            // sub-agent row, and the open view's own tail tick (P-C2).
            $msg instanceof \SugarCraft\Code\App\OpenAgentViewMsg => [$this->openAgentView($msg->agentId, $msg->childSessionId, $msg->name), null],
            $msg instanceof \SugarCraft\Code\App\CloseAgentViewMsg => $this->closeAgentView()->delegateToChat($msg),
            // P-D2: the chat's answer to the Agent View composer's send.
            $msg instanceof \SugarCraft\Code\App\AgentMessageSentMsg => [$this->recordAgentMessage($msg), null],
            // P-TABS.e: the deck's four routes. A scoped message is either
            // the foreground's own (unwrap, re-enter — every guard above
            // runs) or a parked tab's (route to the parked model, never the
            // foreground's Chat). Quit gets a deck-aware arm here: with no
            // deck, or after the one ask, it falls to the SAME
            // delegateToChat default the pre-deck tree used.
            $msg instanceof TabScopedMsg => $this->handleTabScoped($msg),
            $msg instanceof OpenTabMsg => $this->handleOpenTab($msg),
            $msg instanceof CloseTabMsg => $this->handleCloseTab($msg),
            $msg instanceof CycleTabMsg => $this->handleCycleTab($msg),
            $msg instanceof \SugarCraft\Core\Msg\QuitMsg => $this->handleQuit($msg),
            $msg instanceof KeyMsg => $this->handleKey($msg),
            $msg instanceof MouseMsg => $this->handleShellMouse($msg),
            $msg instanceof BackgroundColorMsg => $this->observeBackground($msg),
            default => $this->delegateToChat($msg),
        };

        $menuIsOpen = MenuBar::activeMenu() > 0;
        if ($menuWasOpen !== $menuIsOpen && Chat::mouseMode() !== MouseMode::Off) {
            $result[1] = CoreCmd::batch(
                $result[1],
                $menuIsOpen
                    ? CoreCmd::enableMouseAllMotion()
                    : CoreCmd::enableMouseCellMotion(),
            );
        }

        return $result;
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
     * {@see \SugarCraft\Code\Tests\App\AppModelTest::testTheBackgroundColorArmHandsTheMessageOnRatherThanConsumingIt()}.
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

    /**
     * A split-seam drag's snapshot from its press: the column width the
     * pointer's travel is measured against, and the override to hand back
     * on Escape (which may be null — the default proportion).
     *
     * @var ?array{cols: int, override: ?int}
     */
    private static ?array $splitDragOrigin = null;

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
        self::$splitDragOrigin = null;
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

        // P-MENU.e hover: while a menu is open the shell arms AllMotion (see
        // update()), so motion arrives — and the menu owns it. Over a
        // `menuitem:` or `panetab:` row it lights that row (a submenu row
        // previews); anywhere else it is SWALLOWED, never handed to the
        // chat, whose hover would otherwise move text-selection and caret
        // state under the open panel. Presses and releases keep falling
        // through: a click must still run a row, and a press-drag from a
        // docked row must still arm the dock drag below.
        if ($msg instanceof MouseMotionMsg && MenuBar::activeMenu() > 0) {
            $zone = TuiRenderer::chromeZoneAt($msg->x, $msg->y);
            if ($zone !== null
                && (str_starts_with($zone->id, MenuBar::MENU_ITEM_ZONE_PREFIX)
                    || str_starts_with($zone->id, MenuBar::PANE_TAB_ZONE_PREFIX))
            ) {
                MenuBar::hover($zone->id);
            }

            return [$this, null];
        }

        // A text selection the chat anchored owns the gesture until its
        // release, wherever the pointer wanders: a release over a sidebar or
        // the menu bar would otherwise be swallowed as a cancelled chrome
        // click, leaving the selection neither copied nor finished.
        if (!$msg instanceof MouseClickMsg && Chat::textSelectionInProgress()) {
            return $this->delegateToChat($msg);
        }

        // The settings view covers the band: the wheel moves its highlight, and
        // a click that lands on none of its zones (or the menu's) reaches no
        // chat — there is none on screen to receive it.
        if ($this->settingsEditor !== null && $msg instanceof MouseWheelMsg) {
            $notch = match ($msg->button) {
                MouseButton::WheelDown => 1,
                MouseButton::WheelUp => -1,
                default => 0,
            };

            return [$notch === 0 ? $this : $this->mutate(settingsEditor: $this->settingsEditor->wheel($notch)), null];
        }

        // The media form covers the band the same way (W3.1): its rows take
        // the wheel while it is up.
        if ($this->mediaForm !== null && $msg instanceof MouseWheelMsg) {
            $notch = match ($msg->button) {
                MouseButton::WheelDown => 1,
                MouseButton::WheelUp => -1,
                default => 0,
            };

            return [$notch === 0 ? $this : $this->mutate(mediaForm: $this->mediaForm->wheel($notch)), null];
        }

        // C-TUI-2.e: the review covers the band too — the wheel pages its
        // body, translated to the model's own paging keys (the review owns
        // no wheel surface of its own; ↑/↓, the keys, are its), and a click
        // lands on no zone: the whole rest of the gesture is inert over the
        // patch, on the player's law one step below.
        if ($this->diffReview !== null) {
            if (!$msg instanceof MouseWheelMsg) {
                return [$this, null];
            }

            $key = match ($msg->button) {
                MouseButton::WheelDown => new KeyMsg(KeyType::PageDown),
                MouseButton::WheelUp => new KeyMsg(KeyType::PageUp),
                default => null,
            };

            return $key === null ? [$this, null] : $this->driveDiffReview($key);
        }

        // C-TUI-3.d: the picker is a keyboard-only box — the whole rest of
        // the band, wheel included, is inert over it (its rows answer keys
        // alone; there is no surface a gesture could move).
        if ($this->messagePicker !== null) {
            return [$this, null];
        }

        // The player covers the band too (W5.4) — but it has no scrollable
        // surface yet: the wheel answers to nothing and a click lands on no
        // zone, so the whole gesture is inert over the picture.
        if ($this->videoPlayer !== null) {
            return [$this, null];
        }

        // The wheel scrolls whatever scrollable surface is under the pointer —
        // a docked pane, or the live-agent column — and only there; anywhere
        // else it stays the transcript's, as it always was. Down shows more
        // (older calls, later agents), up goes back toward the top.
        if ($msg instanceof MouseWheelMsg) {
            $region = TuiRenderer::scrollRegionAt($msg->x, $msg->y);
            $notch = match ($msg->button) {
                MouseButton::WheelDown => 1,
                MouseButton::WheelUp => -1,
                default => 0,
            };
            if ($region !== null && $notch !== 0) {
                // A tile is a whole agent; a pane row is a line, so a notch
                // moves a pane the transcript's usual three.
                $step = $region['id'] === TuiRenderer::SPLIT_SCROLL_ID ? 1 : self::PANE_WHEEL_ROWS;

                return [$this->withPaneScroll($region['id'], $notch * $step, $region['max']), null];
            }
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

        if ($press && $zone === null && MenuBar::activeMenu() > 0) {
            // P-MENU.e: a press OUTSIDE the chrome dismisses an open menu —
            // the Windows-95 law the hover feature leans on for its
            // click-away close path (the update() transition then swaps the
            // mouse mode back, exactly as for Esc). The press itself still
            // goes on to Chat below: dismissing the bar is not swallowing
            // the click.
            MenuBar::closeMenu();
        }

        $event = $press
            ? MouseEvent::press($msg->x, $msg->y)
            : MouseEvent::release($msg->x, $msg->y);

        $click = self::chromeClickTracker()->track($event, $zone);

        if ($click === null) {
            // C-TUI-5.e: with the wizard up, a stray click falls inert — the
            // band it covers is not the chat's while it is asked of the user
            // (the settings/media stand-down law; the wizard has no zones).
            return $zone === null && $this->settingsEditor === null && $this->mediaForm === null && $this->firstRunWizard === null
                ? $this->delegateToChat($msg)
                : [$this, null];
        }

        return $this->dispatchChromeClick($click->zone->id);
    }

    /**
     * A left press on the chrome asks the gesture phase first: a side's
     * resize seam (box border, divider, centre border — every band row) owns
     * a live resize, a docked pane owns a
     * potential dock drag from EITHER of its header surfaces — the frame
     * header row (`pane:<id>`) and its View ▸ Panes toggle row (the row's
     * {@see MenuBar::PANE_TAB_ZONE_PREFIX} node zone, the live-report fix
     * inherited from the old bar tab: a press-drag-release there arms the
     * gesture) — and an intra-stack gap row is consumed as a no-op (its height drag
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
     * dock toggle on a Panes row.
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

            // The PRESSED cell, not the zone's start: the seam is several
            // cells wide, and measuring travel from its first cell would jump
            // the band by the press's offset into it on the first motion.
            self::$paneDrag = self::paneDragController()->beginResize($side, $pressX);
            self::$paneDragOrigin = $this->dock();

            return [$this, null];
        }

        // The live-agent column's seam: the column takes the width the
        // pointer asks for, measured from the width it was painted at.
        if (str_starts_with($zoneId, TuiRenderer::SPLIT_DIVIDER_ZONE_PREFIX)) {
            $cols = TuiRenderer::lastDockFrame()['agentCols'] ?? 0;
            if ($cols < 1) {
                return null;
            }

            self::$paneDrag = self::paneDragController()->beginSplitResize($pressX);
            self::$splitDragOrigin = ['cols' => $cols, 'override' => $this->agentSplitCols];

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
            // P-MENU.e: a dock drag now STARTS from a View ▸ Panes row, so a
            // press here may land while the menu is open. The menu must step
            // aside once the drag ARMS — not on this press: a press with no
            // following motion is a plain click-to-toggle (the old bar tab's
            // stationary-click law), which keeps the submenu open (R-MENU-3).
            // The arming transition closes it inside advancePaneDrag().
        }

        return null;
    }

    /**
     * The pane a pressed chrome zone-id names, when it names one: the pane's
     * frame header ({@see Renderer::PANE_ZONE_PREFIX}) or a View ▸ Panes row
     * standing for it via its `panetab:` node zone
     * ({@see MenuBar::PANE_TAB_ZONE_PREFIX}) — the two
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
     * persist-only-on-release law), and sugar-core's periodic repaint makes
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

            // P-MENU.e: the gesture has just taken the pane — if that arming
            // happened inside an open menu (a drag begun on a View ▸ Panes
            // row), the menu steps aside NOW, on the transition, not on the
            // press: a stationary press-release must still read as a plain
            // toggle with the submenu kept open (R-MENU-3). Closing here
            // lets update()'s transition check restore CellMotion; motion
            // with the button held is still reported under CellMotion, so
            // the gesture survives its own success.
            if (!$drag->isArmed() && self::$paneDrag->isArmed()) {
                MenuBar::closeMenu();
            }

            if ($drag->isSplitResizing()) {
                return [$this->previewSplitResize($drag, $msg->x), null];
            }

            if ($drag->isResizing()) {
                return [$this->previewColumnResize($drag, $msg->x), null];
            }

            return [$this, null];
        }

        if (!($msg instanceof MouseReleaseMsg)) {
            return [$this, null];
        }

        // A seam release commits whatever the last preview set; a press and
        // release with no motion between is a click that changes nothing.
        if ($drag->isSplitResizing()) {
            $next = $drag->isPreviewed() ? $this->previewSplitResize($drag, $msg->x) : $this;
            self::$paneDrag = null;
            self::$splitDragOrigin = null;

            return [$next, null];
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
     * The live-agent column at the width the pointer asks for: the grab's
     * snapshot width plus the travel (left grows it), inside the bounds the
     * renderer will hold it to anyway.
     */
    private function previewSplitResize(PaneDragController $drag, int $pointerX): self
    {
        $origin = self::$splitDragOrigin;
        $frame = TuiRenderer::lastDockFrame();
        if ($origin === null || $frame === null) {
            return $this;
        }

        [$min, $max] = TuiRenderer::agentSplitBounds($frame['cols']);
        $cols = $drag->splitColumns($pointerX, $origin['cols'], $min, $max);

        return $cols === $this->agentSplitCols ? $this : $this->withAgentSplitCols($cols);
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
        $geometry = $origin->resolve(new \SugarCraft\Constraints\Region(0, 0, $frame['bandCols'], $frame['paneRows']));
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
        $geometry = $dock->resolve(new \SugarCraft\Constraints\Region(0, 0, $frame['bandCols'], $frame['paneRows']));
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
     * `tab`/`shift+tab` cycle makes, and a View ▸ Panes row click (the
     * `panetab:` zone, on the row since P-MENU.e) is the same
     * {@see togglePaneDocking()} the `/pane dock` command calls (see
     * the arm comments for each rule's focus semantics).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchChromeClick(string $zoneId): array
    {
        if (str_starts_with($zoneId, SettingsEditor::ZONE_PREFIX)) {
            return [
                $this->settingsEditor === null ? $this : $this->mutate(settingsEditor: $this->settingsEditor->click($zoneId)),
                null,
            ];
        }

        if (str_starts_with($zoneId, MediaForm::ZONE_PREFIX)) {
            return [
                $this->mediaForm === null ? $this : $this->mutate(mediaForm: $this->mediaForm->click($zoneId)),
                null,
            ];
        }

        $titles = MenuBar::MENU_TITLE_ZONE_PREFIX;
        if (str_starts_with($zoneId, $titles)) {
            MenuBar::openMenu((int) substr($zoneId, strlen($titles)));

            return [$this, null];
        }

        $items = MenuBar::MENU_ITEM_ZONE_PREFIX;
        if (str_starts_with($zoneId, $items)) {
            // P-MENU.d: the zone suffix is the 1-based dotted dropdown path
            // ('3', '3.1', …) the cascade paints — handed to selectItem() raw.
            $selected = MenuBar::selectItem(substr($zoneId, strlen($items)));

            return $selected === null ? [$this, null] : $this->consumeShellCmd($selected);
        }

        if ($zoneId === MenuBar::PANES_DOOR_ZONE) {
            // P-MENU.e (R-MENU-2): the bar's right-end `Currently:` indicator
            // is a door — one completed click opens View ▸ Panes with the
            // Panes row lit and its toggle list previewed.
            MenuBar::openPanesMenu();

            return [$this, null];
        }

        // P-TABS.g: the shell window strip's own zones. `+` opens, a session
        // row switches, its `×` closes — and closing is handleCloseTab's
        // busy-two-step untouched, so a strip click and a keystroke cannot
        // diverge. Order: the close prefixes are disjoint but checked first
        // for the same reason a classifier sorts longest-prefix first. Since
        // P-AGENT.b the agent chips answer: a click lands on that run's
        // Agent View, switching to its tab first when it is a background
        // one. The chip's `×` stays a deliberate no-op — closing a run is
        // the view's own cancel chords, not the strip's.
        foreach ([
            WindowStrip::ZONE_SESSION_CLOSE_PREFIX,
            WindowStrip::ZONE_SESSION_PREFIX,
            WindowStrip::ZONE_NEW,
            WindowStrip::ZONE_AGENT_PREFIX,
            WindowStrip::ZONE_AGENT_CLOSE_PREFIX,
        ] as $zone) {
            if (!str_starts_with($zoneId, $zone)) {
                continue;
            }

            $chat = $this->chat;
            if ($chat !== null && ($chat->keyHelp() !== null || $chat->pendingPermission() !== null)) {
                return [$this, null];
            }

            $rest = substr($zoneId, strlen($zone));
            if ($zone === WindowStrip::ZONE_SESSION_CLOSE_PREFIX && $rest !== '') {
                return $this->handleCloseTab(new CloseTabMsg($rest));
            }

            if ($zone === WindowStrip::ZONE_SESSION_PREFIX && $rest !== '' && ($this->deck?->has($rest) ?? false)) {
                return $this->switchTab($rest);
            }

            if ($zone === WindowStrip::ZONE_NEW) {
                return $this->handleOpenTab(new OpenTabMsg());
            }

            if ($zone === WindowStrip::ZONE_AGENT_PREFIX) {
                return $this->openRunFromWindow($rest);
            }

            return [$this, null];
        }

        // A click on a run's row in the full-pane agent dashboard
        // (`agent:<runId>`, AgentDashboardPane::zones()) opens that run's
        // Agent View — Enter on a selected row, by mouse. Refused under a
        // modal, as a side row is.
        $agentRows = \SugarCraft\Code\Tui\Components\AgentDashboardPane::ZONE_PREFIX;
        if (str_starts_with($zoneId, $agentRows) && strlen($zoneId) > strlen($agentRows)) {
            $chat = $this->chat;
            if ($chat !== null && ($chat->keyHelp() !== null || $chat->pendingPermission() !== null)) {
                return [$this, null];
            }

            return [$this->openAgentView(substr($zoneId, strlen($agentRows))), null];
        }

        // A completed click on a docked side pane's ROW expands or collapses
        // it: a Tools row toggles the transcript's own expansion entry for
        // that call (one state, so the row opens in both places), an Agents
        // row toggles the run's inline activity. Refused while an overlay owns
        // the screen — chrome clicks never pass through Chat's modal guard,
        // so this arm carries its own: the key-help sheet and a permission
        // prompt both cover the panes the click landed on.
        $rows = Renderer::SIDE_ROW_ZONE_PREFIX;
        if (str_starts_with($zoneId, $rows)) {
            $chat = $this->chat;
            if ($chat !== null && ($chat->keyHelp() !== null || $chat->pendingPermission() !== null)) {
                return [$this, null];
            }

            [$paneId, $key] = array_pad(explode(':', substr($zoneId, strlen($rows)), 2), 2, '');
            if ($key === '') {
                return [$this, null];
            }

            return match ($paneId) {
                Pane::Tools->value => [
                    $chat === null ? $this : $this->withChat($chat->toggleToolOutput($key))->withToolRowCursor($key),
                    null,
                ],
                Pane::Agents->value => [$this->toggleAgentExpanded($key), null],
                Pane::Context->value => $this->dispatchContextRow($key),
                Pane::Sessions->value => $this->dispatchSessionsRow($key),
                default => [$this, null],
            };
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

        // A completed click on a View ▸ Panes TOGGLE ROW — the `panetab:`
        // zone P-MENU.e moved off the menu-bar strip and onto the row —
        // toggles that pane's docked visibility (docking L2): through togglePaneDocking — the ONE
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

            return $pane === null ? [$this, null] : $this->togglePaneFromChrome($pane);
        }

        return [$this, null];
    }

    /**
     * The ONE chrome door that docks/undocks a pane (P-MENU.d). The
     * `panetab:` click arm above and the View > Panes menu rows' `pane:`
     * dispatch both come through here, so toggling a pane from the keyboard
     * and clicking its tab are the same code path — the equivalence
     * MenuActionDispatchTest pins row for row. Chat's tab never toggles: the
     * center column is always visible, so its press is a pure focus move.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function togglePaneFromChrome(Pane $pane): array
    {
        if (!$pane->dockable()) {
            return $pane === Pane::Chat
                ? [$this->withPane(Pane::Chat), null]
                : [$this, null];
        }

        $wasDocked = $this->isDocked($pane);
        $next = $this->togglePaneDocking($pane);

        return [
            $wasDocked ? $next : $next->withPane($pane),
            $this->contextMeasureOnDock($pane, $wasDocked),
        ];
    }

    /**
     * The Context box's side rows (P-CTX.e) — one per body line the keyed
     * layout stamped. The summary line and a category row toggle the box's
     * two view states; the `dump prompt` row runs the same
     * `/context --raw` the slash surface offers, through the one
     * {@see Chat::runCommand()} funnel so the dump and a typed command are
     * indistinguishable downstream.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchContextRow(string $key): array
    {
        if ($key === ContextPane::TOGGLE_KEY) {
            return [$this->contextPaneToggle(), null];
        }

        if ($key === ContextPane::DUMP_KEY) {
            $chat = $this->chat;
            if ($chat === null) {
                return [$this, null];
            }

            [$next, $cmd] = $chat->runCommand('/context --raw');

            return [$this->withChat($next)->autoEnablePanes(), $cmd];
        }

        if (str_starts_with($key, ContextPane::ROW_KEY_PREFIX)) {
            $category = ContextCategory::tryFrom(substr($key, strlen(ContextPane::ROW_KEY_PREFIX)));
            if ($category === null) {
                return [$this, null];
            }

            // A second click on the drilled row undrills it — rows toggle,
            // like every other side-row surface.
            return [$this->contextPaneDrill(
                $this->contextDrill === $category ? null : $category,
            ), null];
        }

        return [$this, null];
    }

    /**
     * A row click inside the docked Sessions pane (P-TABS.g). The pane's own
     * key vocabulary (SessionsPane::KEY_*) maps onto the same three deck
     * routes the strip and the keyboard use — open, switch, and open an
     * agent child — so mouse and keyboard are one code path. Agent rows
     * answer through {@see openRunFromWindow()}, keyed by run id exactly as
     * the strip chips and the dashboard rows above are; since P-AGENT.b the
     * pane lists children of EVERY tab, so a background tab's row switches
     * to its tab first and lands on the run's view.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchSessionsRow(string $key): array
    {
        if ($key === SessionsPane::KEY_NEW) {
            return $this->handleOpenTab(new OpenTabMsg());
        }

        if (str_starts_with($key, SessionsPane::KEY_SESSION_PREFIX)) {
            $tabKey = substr($key, strlen(SessionsPane::KEY_SESSION_PREFIX));
            if ($tabKey === '' || !($this->deck?->has($tabKey) ?? false)) {
                return [$this, null];
            }

            return $this->switchTab($tabKey);
        }

        if (str_starts_with($key, SessionsPane::KEY_AGENT_PREFIX)) {
            return $this->openRunFromWindow(substr($key, strlen(SessionsPane::KEY_AGENT_PREFIX)));
        }

        return [$this, null];
    }

    /**
     * The one docking-time measurement hook (P-CTX.e). An unmeasured box
     * reads "measuring…" forever unless the first dock asks for a figure,
     * so docking Context — by tab, menu row, status meter or command —
     * schedules {@see Chat::measureContextCmd()} the same way a settled
     * turn does. Undocking and every other pane stay silent: the promise
     * the closure carries assembles one prompt and costs nothing else.
     */
    private function contextMeasureOnDock(Pane $pane, bool $wasDocked): ?\Closure
    {
        return $pane === Pane::Context && !$wasDocked
            ? $this->chat?->measureContextCmd()
            : null;
    }

    /**
     * Offer a keypress to the pane shell, falling through to the hosted chat.
     *
     * A press claimed by a `command:` binding is the exception: it
     * short-circuits to {@see runBoundCommand()} before the shell, any modal
     * gate, or the alias funnel sees it (plan_comparison C-EXT-5).
     *
     * The shell's command object is no longer dropped here: it is handed to
     * {@see consumeShellCmd()}, which TRANSLATES it into shell state changes
     * or into keystrokes the hosted {@see Chat} already answers. It is still
     * never returned: a `KeyCmd`/`MenuSelectedMsg` is a declarative
     * instruction object, not the `Closure(): ?Msg` sugar-core's
     * `Program::scheduleCmd()` accepts, so returning one would TypeError and
     * kill the loop.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleKey(KeyMsg $msg): array
    {
        // C-EXT-5: a `command:<name>` binding claims its chord HERE, at the
        // very top of the funnel, BEFORE the alias translation below and
        // before every modal gate — the press runs `/<name>` instead of
        // reaching any surface. It must precede honourKeybindingAliases():
        // a command binding is its own dispatch, not an alias for a shipped
        // row, and translating first would hand it to the chat input as an
        // ordinary rune.
        $boundCommand = KeyBindingRegistry::commandFor($msg);
        if ($boundCommand !== null) {
            return $this->runBoundCommand($boundCommand);
        }

        // ISS-047 (rv5b): a `keybindings` override lands its chord here, at
        // the ONE user-keystroke funnel, before any surface reads the press.
        // Translating the alias to the binding's shipped rune means every
        // derived claim set and every handler keeps answering from the raw
        // rows — the override ADDS a chord, the shipped one still fires.
        // Synthetic internal keys (consumeShellCmd's ctrl(), feedChat) speak
        // the shipped spelling and pass through untouched.
        $msg = self::honourKeybindingAliases($msg);

        // Escape is the drag's abort key while a gesture is in flight —
        // BEFORE the shell's own bindings, because mid-drag the keystroke
        // means "put the pane back", not "cancel the turn". The press
        // snapshotted the dock and every motion preview rode the model
        // (that preview IS the live feedback sugar-core's repaint tick
        // shows), so cancelling hands back the snapshot: zero state change,
        // zero disk write — the release is the only path that persists.
        if (!self::paneDragController()->isIdle() && $msg->type === KeyType::Escape && self::paneDragController()->isSplitResizing()) {
            $origin = self::$splitDragOrigin;
            self::$paneDrag = null;
            self::$splitDragOrigin = null;

            return [$origin === null ? $this : $this->mutate(agentSplitCols: $origin['override']), null];
        }

        if (!self::paneDragController()->isIdle() && $msg->type === KeyType::Escape) {
            $origin = self::$paneDragOrigin;
            self::$paneDrag = null;
            self::$paneDragOrigin = null;

            return [$origin === null ? $this : $this->mutate(dock: $origin), null];
        }

        // The launch trust dialog (C-SEC-5.d) is MODAL on the same law, and
        // FIRST among the full-band modals: it covers the frame at launch,
        // so the user answers (or dismisses) it before reaching the settings
        // view or typing into the chat it covers. The component carries every
        // answer as a value; what it decides rides out as a Cmd through the
        // one grant door, answered as a status notice like every other write.
        if ($this->trustDialog !== null && !self::trustDialogYields($msg)) {
            $next = $this->trustDialog->update($msg);
            if (!$next->isResolved()) {
                return [$this->mutate(trustDialog: $next), null];
            }

            return $this->resolveTrustDialog($next);
        }

        // The first-run wizard (C-TUI-5.e) is MODAL on the same launch law
        // as the trust dialog, seated right behind it: it covers the band
        // at launch and every key except the gate exceptions is its own.
        // What it decides leaves as a FirstRunWizardAction — the store door
        // and the /model twin are the shell's, never the model's.
        if ($this->firstRunWizard !== null && !self::firstRunWizardYields($msg)) {
            [$next, $action] = $this->firstRunWizard->update($msg);

            if ($action !== null) {
                return $this->applyFirstRunWizardAction($action);
            }

            return [$next === null ? $this->closeFirstRunWizard() : $this->mutate(firstRunWizard: $next), null];
        }

        // The settings view is MODAL while it is open: every key is its own,
        // so nothing types into a chat it covers. Three exceptions keep the
        // app drivable — Ctrl+C still quits (Chat's binding), and F10 and an
        // open menu keep the menu bar the view leaves on screen.
        if ($this->settingsEditor !== null && !self::settingsEditorYields($msg)) {
            // The editor's keys that need the writer are the shell's, because
            // the writer is: `s` (preview), `y`/Enter (save the preview) and
            // `y` on the trust question (the confirmed trust action).
            $shell = $this->settingsShellKey($msg);
            if ($shell !== null) {
                return $shell;
            }

            $next = $this->settingsEditor->update($msg);

            return [$next === null ? $this->closeSettings() : $this->mutate(settingsEditor: $next), null];
        }

        // The media form is MODAL on the same law (crush-media W3.1,
        // mystage §6.2): every key except the gate exceptions below is its
        // own, and what it decides leaves as a MediaFormAction.
        if ($this->mediaForm !== null && !self::mediaFormYields($msg)) {
            // crush-media W4.2: while the form is up, Ctrl+V belongs to the
            // image paste — the form's own key table answers ctrl runes to
            // no one, and the clipboard read runs off the update path as a
            // Cmd, exactly like Chat's paste chord (plan W4.2 microstep 1).
            if ($msg->type === KeyType::Char && $msg->ctrl && !$msg->alt && $msg->rune === 'v') {
                return [$this, static fn (): CoreMsg => new ClipboardImagePastedMsg(ClipboardImage::save())];
            }

            [$next, $action] = $this->mediaForm->update($msg);

            if ($action !== null) {
                return $this->applyMediaFormAction($action);
            }

            return [$next === null ? $this->closeMediaForm() : $this->mutate(mediaForm: $next), null];
        }

        // The video player is MODAL on the same law (crush-media W5.4):
        // while its blob owns the band, every key except the gate
        // exceptions below is the screen's. The close trio is intercepted
        // HERE, before the Player ever sees it — forwarded, the Player
        // would stop the clip (right) and ask the Program to quit (wrong:
        // one screen, not the app). Ctrl+C stays the app-wide quit chord
        // the yields law lets through; the player rides out via the
        // shutdown teardown registered at open.
        if ($this->videoPlayer !== null && !self::videoPlayerYields($msg)) {
            if (VideoPlayerKeys::isClose($msg)) {
                return [$this->closeVideoPlayer(), null];
            }

            return $this->driveVideoPlayer($msg);
        }

        // C-TUI-2.e: the review is MODAL on the same law: while the patch
        // owns the band, every key except the gate exceptions below is the
        // screen's. What the model decides leaves as a DiffReviewAction —
        // `Esc` closes, the base/turn keys come back as a reload the shell
        // dials against the store it holds.
        if ($this->diffReview !== null && !self::diffReviewYields($msg)) {
            [$next, $action] = $this->diffReview->update($msg);

            if ($action !== null) {
                return $this->applyDiffReviewAction($action, $next);
            }

            return [$this->mutate(diffReview: $next), null];
        }

        // C-TUI-3.d: the picker owns the keys on the review's yield law
        // (the same quit/menu chords that pass through every shell slot
        // pass through it too); its own Esc/Enter/↑/↓/filter keys are
        // consumed, and Close/Accept land via applyMessagePickerAction().
        if ($this->messagePicker !== null && !self::messagePickerYields($msg)) {
            return $this->driveMessagePicker($msg);
        }

        // P-TABS.e: the deck answers its two chords before anyone else —
        // placed AFTER every modal gate above (a modal owns the keyboard,
        // deck included) and BEFORE dispatchKey, because the shell routes
        // Tab-with-modifiers down to Chat, where a streaming turn REFUSES
        // the cycle (`refuseWhileInFlight`). The whole point of the deck is
        // that switching away from a streaming turn is always legal, so the
        // refusal must never hear this press at all. With no deck the
        // chords fall through unchanged: legacy Ctrl+Tab still cycles
        // Chat's own strip, legacy Ctrl+N still runs the palette action.
        if ($this->deck !== null) {
            if ($msg->type === KeyType::Tab && $msg->ctrl && $this->deck->count() > 1) {
                return $this->handleCycleTab(new CycleTabMsg($msg->shift ? -1 : 1));
            }

            if ($msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'n') {
                return $this->handleOpenTab(new OpenTabMsg());
            }
        }

        // P-TABS.g: Alt+S toggles the vertical Sessions pane whether or not a
        // deck is armed yet — the pane over an unarmed deck honestly shows
        // just the `+` row, which is the promise the menu bar's View ▸ Panes
        // tab already makes for every dockable pane.
        if ($msg->type === KeyType::Char && $msg->alt && !$msg->ctrl && strtolower($msg->rune) === 's') {
            return $this->togglePaneFromChrome(Pane::Sessions);
        }

        $handled = $this->dispatchKey($msg);

        if ($handled === null) {
            // A key the focused agents strip does not answer hands the
            // keyboard back to the input box and lands there (P-B3), so
            // typing never goes nowhere.
            if ($this->agentStripFocus !== null) {
                return $this->withAgentStripFocus(null)->delegateToChat($msg);
            }

            return $this->delegateToChat($msg);
        }

        [$next, $cmd] = $handled;

        return $cmd === null ? [$next, null] : $next->consumeShellCmd($cmd);
    }

    /**
     * The ISS-047 alias step, widened by plan_comparison TUI-4c: a press an
     * override chord claims is rebuilt as the binding's SHIPPED chord — the
     * rune lane for bare `Ctrl+<rune>` pairs, the chord lane for every other
     * parseable shape (`Alt+Enter`, `Ctrl+Tab`, `F10`, …) — so the ordinary
     * claim/dispatch machinery answers it unchanged. Any press no override
     * touches is the same message. The whole consult lives in the registry
     * ({@see KeyBindingRegistry::aliasPress()}); this is the one funnel.
     */
    private static function honourKeybindingAliases(KeyMsg $msg): KeyMsg
    {
        return KeyBindingRegistry::aliasPress($msg) ?? $msg;
    }

    /**
     * The settings view's keys that reach the writer, or null for a key the
     * editor answers itself.
     *
     * - `s` with changes staged (from the list, or as the answer to the
     *   discard question): build the save preview ({@see previewSettings()}).
     * - `y` or `Enter` on an open preview: save it ({@see confirmSettingsSave()}).
     * - `y` on the trust question: the confirmed trust action
     *   ({@see grantProjectTrust()}).
     *
     * @return array{0: self, 1: ?\Closure}|null
     */
    private function settingsShellKey(KeyMsg $msg): ?array
    {
        $editor = $this->settingsEditor;
        if ($editor === null) {
            return null;
        }
        $rune = $msg->type === KeyType::Char && !$msg->ctrl && !$msg->alt ? $msg->rune : null;

        if ($rune === 's' && $editor->hasChanges()
            && ($editor->isIdle() || $editor->confirm === SettingsEditor::CONFIRM_DISCARD)) {
            return [$this->previewSettings(), null];
        }

        if ($editor->preview !== null && $editor->confirm === null && ($rune === 'y' || $msg->type === KeyType::Enter)) {
            return $this->confirmSettingsSave();
        }

        if ($editor->confirm === SettingsEditor::CONFIRM_TRUST && $rune === 'y') {
            return $this->grantProjectTrust();
        }

        // N-P5: the profile prompt's Enter, and the file / profile keys.
        if ($editor->profileAction() !== null && $msg->type === KeyType::Enter) {
            return $this->runSettingsProfile();
        }

        if ($editor->isIdle()) {
            return match ($rune) {
                'e' => $this->editSettingsFile(),
                'x' => [$this->mutate(settingsEditor: $editor->withProfilePrompt(SettingsEditor::PROFILE_EXPORT, $this->defaultProfilePath())), null],
                'p' => [$this->mutate(settingsEditor: $editor->withProfilePrompt(SettingsEditor::PROFILE_IMPORT, $this->defaultProfilePath())), null],
                default => null,
            };
        }

        return null;
    }

    /** Where the profile prompt starts: beside the file "You" saves to. */
    private function defaultProfilePath(): string
    {
        return SettingsProfile::defaultPath($this->settingsWriter?->targetPath(SettingsTier::You));
    }

    /**
     * `e` in the settings view (N-P5, "open the file in `$EDITOR`"): the
     * highlighted file on the Files tab, else the file the chosen tier saves
     * to, opened in the user's editor through a {@see SettingsFileEditor} Cmd
     * (the terminal is handed over and given back). What the file holds now is
     * read first — one small read on an explicit request, like
     * {@see previewSettings()} — so the answer can say what the edit changed.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function editSettingsFile(): array
    {
        $editor = $this->settingsEditor;
        if ($editor === null) {
            return [$this, null];
        }

        $path = $editor->selectedFilePath();
        if ($path === null) {
            $writer = $this->settingsWriter;
            if ($writer === null) {
                return [$this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.opened', Lang::t('tui.app.settings_unsavable')))), null];
            }

            if ($editor->tier === SettingsTier::Session) {
                return [$this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.opened', Lang::t('tui.app.settings_tier_no_file', ['tier' => SettingsTier::Session->label()])))), null];
            }

            $path = $writer->targetPath($editor->tier);
            if ($path === null) {
                return [$this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.opened', (string) $writer->tierRefusal($editor->tier)))), null];
            }

            // A project tier's `.candy-code` may not exist yet; the writer
            // vouched for the path, so the directory is made the way a save
            // would make it, or the editor could not write the file.
            $dir = \dirname($path);
            if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
                return [$this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.opened', Lang::t('tui.settings.profile.dir_not_created', ['dir' => $dir])))), null];
            }
        }

        $before = is_file($path) ? LayeredSettings::decodedFile($path) : [];

        return [$this, SettingsFileEditor::new()->cmd($path, $before)];
    }

    /**
     * The editor `e` opened has exited: re-read the layers so the rows show
     * what the files now say, name the settings the edit changed, and hand
     * those to the hosted chat to apply — the same path a save takes
     * ({@see settingsSaved()}), except that nothing staged is touched.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function settingsFileEdited(SettingsFileEditedMsg $msg): array
    {
        $editor = $this->settingsEditor;
        if (!$msg->ok()) {
            return [$editor === null ? $this : $this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.reread', (string) $msg->error))), null];
        }

        // Read strictly: a file the edit left unparseable is said so, and
        // nothing is applied from it.
        $after = [];
        $broken = false;
        if (is_file($msg->path)) {
            try {
                $after = AtomicJsonFile::new($msg->path)->read();
                $broken = $after !== [] && array_is_list($after);
            } catch (\Throwable) {
                $broken = true;
            }
        }

        $changed = [];
        foreach (array_unique([...array_keys($msg->before), ...array_keys($after)]) as $key) {
            $key = (string) $key;
            $was = \array_key_exists($key, $msg->before) ? $msg->before[$key] : null;
            $now = \array_key_exists($key, $after) ? $after[$key] : null;
            if (SettingsSchema::byKey($key) !== null && ($was !== $now || \array_key_exists($key, $msg->before) !== \array_key_exists($key, $after))) {
                $changed[] = $key;
            }
        }

        $app = $this;
        if ($editor !== null) {
            $editor = $editor->withResolved($this->settingsSources !== null
                ? ($this->settingsSources)()
                : SettingsSources::bestEffort($this->root));
            $status = match (true) {
                $broken => SettingsEditor::failure('tui.settings.failed.applied', Lang::t('tui.app.settings_file_broken', ['path' => $msg->path])),
                $changed === [] => Lang::t('tui.app.settings_file_unchanged', ['path' => $msg->path]),
                default => Lang::t(\count($changed) === 1 ? 'tui.app.settings_file_changed_one' : 'tui.app.settings_file_changed', [
                    'path' => $msg->path,
                    'count' => \count($changed),
                    'keys' => implode(', ', $changed),
                ]),
            };
            $app = $this->mutate(settingsEditor: $editor->withNotice($status));
        }

        if ($broken || $changed === [] || $app->chat === null) {
            return [$app, null];
        }

        [$chat, $cmd] = $app->chat->applySettings($changed, $msg->path);

        return [$app->withChat($chat), $cmd];
    }

    /**
     * `Enter` on the profile prompt: export what this launch's files and
     * session set ({@see SettingsProfile::values()}) to the path typed, or
     * read the profile there to stage it. Both are Cmds — file I/O — answered
     * with a {@see SettingsProfileMsg}.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function runSettingsProfile(): array
    {
        $editor = $this->settingsEditor;
        $action = $editor?->profileAction();
        if ($editor === null || $action === null) {
            return [$this, null];
        }

        $path = SettingsProfile::expand((string) $editor->profilePath(), HomeDirectory::owned());
        $editor = $editor->cancelEdit();
        if ($path === '') {
            return [$this->mutate(settingsEditor: $editor->withNotice(SettingsEditor::failure('tui.settings.failed.done', Lang::t('tui.app.settings_no_file_named')))), null];
        }

        if ($action === SettingsEditor::PROFILE_EXPORT) {
            $values = SettingsProfile::values($editor->resolved);

            return [$this->mutate(settingsEditor: $editor), static function () use ($path, $values): SettingsProfileMsg {
                try {
                    SettingsProfile::write($path, $values);

                    return SettingsProfileMsg::exported($path, $values);
                } catch (\Throwable $e) {
                    return SettingsProfileMsg::failed(SettingsProfileMsg::EXPORT, $path, $e->getMessage());
                }
            }];
        }

        return [$this->mutate(settingsEditor: $editor), static function () use ($path): SettingsProfileMsg {
            try {
                return SettingsProfileMsg::imported($path, SettingsProfile::read($path));
            } catch (\Throwable $e) {
                return SettingsProfileMsg::failed(SettingsProfileMsg::IMPORT, $path, $e->getMessage());
            }
        }];
    }

    private function settingsProfileDone(SettingsProfileMsg $msg): self
    {
        return $this->settingsEditor === null ? $this : $this->mutate(settingsEditor: $this->settingsEditor->withProfileResult($msg));
    }

    /**
     * The confirmed trust action (N-P2's dormant
     * {@see SettingsWriter::grantTrust()}, wired): add this launch's project
     * root to the trust list the view is asking about, in the user's
     * `config.json`. The write is a Cmd, answered with a
     * {@see SettingsSavedMsg} like a save; it applies from the next launch.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function grantProjectTrust(): array
    {
        $editor = $this->settingsEditor;
        $key = $editor?->pendingTrustKey();
        if ($editor === null || $key === null) {
            return [$this, null];
        }

        $editor = $editor->withConfirm(null);
        $writer = $this->settingsWriter;
        $root = $this->root;
        if ($writer === null || $root === null || $root === '') {
            return [$this->mutate(settingsEditor: $editor->withSaved(SettingsSavedMsg::failed(
                SettingsTier::You,
                [$key],
                Lang::t($writer === null ? 'tui.app.settings_unsavable' : 'tui.app.settings_no_root'),
            ))), null];
        }

        return [$this->mutate(settingsEditor: $editor), static function () use ($writer, $key, $root): SettingsSavedMsg {
            try {
                return SettingsSavedMsg::saved(SettingsTier::You, [$key], $writer->grantTrust($key, $root));
            } catch (\Throwable $e) {
                return SettingsSavedMsg::failed(SettingsTier::You, [$key], $e->getMessage());
            }
        }];
    }

    /**
     * Carry the resolved launch dialog's answers through the ONE grant door
     * ({@see \SugarCraft\Code\Cli\Bootstrap::grantProjectTrust()}) and close
     * the dialog (C-SEC-5.d). The write is a Cmd answered with a
     * {@see StatusMsg} notice — like a settings save — because the door
     * never throws and the user must be able to read WHY an answer failed.
     * A dismissal answers nothing, and a walk of rows the user all skipped
     * writes nothing either: refusal is the default and needs no sentence
     * repeating it. The lists are frozen at launch, so the notice says the
     * grant stands from the NEXT launch, never this one.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function resolveTrustDialog(TrustDialog $resolved): array
    {
        $granted = [];
        foreach ($resolved->choices() as $choice) {
            if ($choice['granted']) {
                $granted[] = $choice['target'];
            }
        }

        $closed = $this->mutate(trustDialog: null);
        if ($granted === []) {
            return [$closed, null];
        }

        $writer = $this->settingsWriter;
        $root = $resolved->root();

        return [$closed, static function () use ($granted, $root, $writer): StatusMsg {
            $labels = [];
            $errors = [];
            foreach ($granted as $target) {
                $result = \SugarCraft\Code\Cli\Bootstrap::grantProjectTrust($target, $root, $writer);
                if ($result['error'] !== null) {
                    $errors[] = $result['error'];
                } elseif ($result['granted']) {
                    $labels[] = Lang::t(self::trustTargetKey($target));
                }
            }

            if ($errors !== []) {
                return new StatusMsg(Lang::t('tui.trust.failed', ['error' => implode('; ', $errors)]));
            }

            return new StatusMsg(Lang::t('tui.trust.granted', ['targets' => implode(' · ', $labels)]));
        }];
    }

    /**
     * The dialog's row vocabulary, spelled as literals rather than built
     * from `$target->value`: the `tui.*` census in TuiLangTest walks quoted
     * strings (the same law TrustDialog::rowText() records for its rows), and
     * the exhaustive `match` is 5.a's own no-drift idiom.
     */
    private static function trustTargetKey(TrustTarget $target): string
    {
        return match ($target) {
            TrustTarget::Mcp => 'tui.trust.target.mcp',
            TrustTarget::McpPin => 'tui.trust.target.mcp_pin',
            TrustTarget::Hooks => 'tui.trust.target.hooks',
            TrustTarget::Commands => 'tui.trust.target.commands',
            TrustTarget::Settings => 'tui.trust.target.settings',
        };
    }

    /**
     * Whether a key goes past the open launch trust dialog: the exact
     * settings-view exception list (quit chord, menu key, live menu) —
     * one law for every full-band modal, so no shell ever invents its own
     * escape hatch.
     */
    private static function trustDialogYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
    }

    /**
     * Whether a key goes past the open settings view: the quit chord to Chat,
     * the menu key and anything an open menu answers to the shell.
     */
    private static function settingsEditorYields(KeyMsg $msg): bool
    {
        return MenuBar::activeMenu() > 0
            || $msg->type === KeyType::F10
            || ($msg->type === KeyType::Char && ($msg->rune === "\x03" || ($msg->ctrl && $msg->rune === 'c')));
    }

    /**
     * Whether a key goes past the open media form: the exact settings-view
     * exception list (quit chord, menu key, live menu) — one law for every
     * full-band modal, so no shell ever invents its own escape hatch.
     */
    private static function mediaFormYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
    }

    /**
     * Whether a key goes past the open video player: the exact
     * settings-view exception list (quit chord, menu key, live menu) —
     * one law for every full-band modal, so no shell ever invents its own
     * escape hatch.
     */
    private static function videoPlayerYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
    }

    /**
     * Whether a key goes past the open diff review (C-TUI-2.e): the exact
     * settings-view exception list (quit chord, menu key, live menu) —
     * one law for every full-band modal, so no shell ever invents its own
     * escape hatch.
     */
    private static function diffReviewYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
    }

    /** The picker yields the same chords the other shell slots do (C-TUI-3.d). */
    private static function messagePickerYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
    }

    /** The wizard yields the same chords the other shell slots do (C-TUI-5.e). */
    private static function firstRunWizardYields(KeyMsg $msg): bool
    {
        return self::settingsEditorYields($msg);
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
     * {@see \SugarCraft\Code\Tui\Commands\QuitAgentViewCmd} is no longer
     * among them (P-C2): leaving the dashboard also leaves an open Agent View.
     *
     * Nor, since roadmap P-D3, are the four that stayed inert for want of
     * anything to translate into: {@see \SugarCraft\Code\Tui\Commands\CancelAgentCmd},
     * {@see \SugarCraft\Code\Tui\Commands\ResumeAgentCmd} and
     * {@see \SugarCraft\Code\Tui\Commands\StopAllAgentsCmd} now become an
     * {@see \SugarCraft\Code\App\AgentControlMsg} for the runs they name —
     * delivered through each run's mailbox, which reaches a run wherever it
     * runs, so the shell needs no worker pool of its own — and
     * {@see \SugarCraft\Code\Tui\Commands\GroupInputCmd} turns the Agent
     * View composer's broadcast on ({@see toggleAgentBroadcast()}).
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
            $cmd instanceof NewSessionCmd => $this->deck !== null
                // P-TABS.e: with a deck, "New session" is a new TAB — the
                // same route the Ctrl+N chord takes. Without one it stays
                // the palette action on the single Chat, byte-for-byte.
                ? $this->handleOpenTab(new OpenTabMsg())
                : $this->runRegistryCommand('new'),
            $cmd instanceof ProviderSelectCmd => $this->runRegistryCommand('model'),
            // Chat's Escape is the live "cancel the in-flight turn" binding.
            $cmd instanceof CancelCmd => $this->feedChat([new KeyMsg(KeyType::Escape)]),
            // The live agents strip's stop (P-B3): the turn's own cancel_tool
            // for that run's Task call, which the chat owns.
            $cmd instanceof \SugarCraft\Code\App\CancelAgentRunMsg => $this->delegateToChat($cmd),
            // The Agent View composer (P-D2): the runs' mailboxes are the
            // chat's, so the shell names the runs and the chat sends.
            $cmd instanceof \SugarCraft\Code\App\AgentControlMsg => $this->deliverAgentControl($cmd),
            // P-D3: the four commands that used to be inert. The dashboard's
            // c/r name its selected row's run; s stops every running run;
            // Ctrl+G turns the composer's broadcast on or off.
            $cmd instanceof \SugarCraft\Code\Tui\Commands\CancelAgentCmd => $this->deliverAgentControl(
                new \SugarCraft\Code\App\AgentControlMsg(\SugarCraft\Code\App\AgentControlMsg::CANCEL, $this->dashboardRunIds($cmd->agentIndex)),
            ),
            $cmd instanceof \SugarCraft\Code\Tui\Commands\ResumeAgentCmd => $this->deliverAgentControl(
                new \SugarCraft\Code\App\AgentControlMsg(\SugarCraft\Code\App\AgentControlMsg::RESUME, $this->dashboardRunIds($cmd->agentIndex)),
            ),
            $cmd instanceof \SugarCraft\Code\Tui\Commands\StopAllAgentsCmd => $this->deliverAgentControl(
                new \SugarCraft\Code\App\AgentControlMsg(\SugarCraft\Code\App\AgentControlMsg::CANCEL, $this->runningAgentIds()),
            ),
            $cmd instanceof \SugarCraft\Code\Tui\Commands\GroupInputCmd => [$this->toggleAgentBroadcast(), null],
            // The Agent View (P-C2): the dashboard's Peek → Attach `Enter`
            // opens it, and `Esc`/`Alt+↑` in it, or the dashboard's `q`,
            // leave it — the chat hears the close, to disarm its `Esc` `Esc`.
            $cmd instanceof \SugarCraft\Code\App\OpenAgentViewMsg => [$this->openAgentView($cmd->agentId, $cmd->childSessionId, $cmd->name), null],
            $cmd instanceof \SugarCraft\Code\App\CloseAgentViewMsg,
            $cmd instanceof \SugarCraft\Code\Tui\Commands\QuitAgentViewCmd => $this->agentViewTarget === null
                ? [$this->closeAgentView(), null]
                : $this->closeAgentView()->delegateToChat(new \SugarCraft\Code\App\CloseAgentViewMsg()),
            default => [$this, null],
        };
    }

    /**
     * Run the command a menu row names.
     *
     * The row label came from {@see \SugarCraft\Code\Commands\CommandRegistry},
     * so it maps back to exactly one {@see CommandSpec} — which is what makes
     * "Enter dispatches the command it names" possible at all. Selecting also
     * closes the menu — EXCEPT a View ▸ Panes toggle, which keeps the
     * submenu open and flips its check in place (R-MENU-3) — since leaving
     * a command-runner's menu open would keep the shell swallowing every
     * subsequent keypress while the command it launched runs.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function dispatchMenuSelection(MenuSelectedMsg $selected): array
    {
        // R-MENU-3 (P-MENU.e): a pane TOGGLE keeps its submenu open and
        // flips the check in place — the eye is still reading the list.
        // Every other selection closes the menu as always (leaving a
        // command-runner open would keep the shell swallowing keys).
        if ($selected->action === null || !str_starts_with($selected->action, 'pane:')) {
            MenuBar::closeMenu();
        }

        if ($selected->item === '') {
            return [$this, null];
        }

        // P-MENU.d: the kit rows arrive with their node id — the same
        // `cmd:<name>` / `pane:<value>` vocabulary the click zones carry, so a
        // selection by keyboard and by mouse enter through the SAME doors. The
        // settings-view rule is dispatchMenuSelection's own: a row other than
        // settings steps the view aside first.
        if ($selected->action !== null) {
            if (str_starts_with($selected->action, 'cmd:')) {
                $name = substr($selected->action, strlen('cmd:'));

                return ($name === 'settings' ? $this : $this->closeSettings())->runRegistryCommand($name);
            }

            if (str_starts_with($selected->action, 'pane:')) {
                $pane = Pane::tryFrom(substr($selected->action, strlen('pane:')));
                if ($pane === null) {
                    return [$this->withError(Lang::t('tui.app.menu_no_command', ['item' => $selected->item])), null];
                }

                return $this->togglePaneFromChrome($pane);
            }

            return [$this, null];
        }

        foreach (CommandRegistry::all() as $spec) {
            if ($spec->label() === $selected->item) {
                // Anything but the settings row answers in the chat, which the
                // settings view would cover — so the view steps aside first.
                return ($spec->name === 'settings' ? $this : $this->closeSettings())->runRegistryCommand($spec->name);
            }
        }

        return [$this->withError(Lang::t('tui.app.menu_no_command', ['item' => $selected->item])), null];
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
     * Those rows carry a palette action, so they are dispatched as that
     * palette action instead.
     *
     * THROUGH CHAT'S COMMAND ENTRY POINTS, NOT SYNTHETIC KEYSTROKES (audit
     * 15b-05). This used to backspace the user's draft empty, type `/name`
     * and press Enter — so the draft was destroyed before Chat had decided
     * whether the command could run at all, and mid-turn the refusal then
     * told the user their draft was "still in the box" when the box held
     * `/name`. {@see Chat::runCommand()} and {@see Chat::runPaletteAction()}
     * refuse mid-turn before touching anything and leave the draft — text
     * and cursor — exactly where it was when the command runs idle.
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
            return [$this->withError(Lang::t('tui.app.unknown_command', ['name' => $name])), null];
        }

        // The settings view is shell state that writes nothing, so it opens
        // here, mid-turn included, rather than through Chat's command entry
        // point — which refuses every command while a turn runs.
        if ($spec->name === 'settings') {
            return [$this->openSettings(), null];
        }

        if ($this->chat === null) {
            return [$this->withStatus(Lang::t('tui.app.no_chat', ['name' => $name])), null];
        }

        // `new` is both `/new` (the folder picker) and the palette's
        // immediate "New session"; the menu row and Ctrl+N name the latter.
        [$next, $cmd] = $spec->slashVisible && $spec->name !== 'new'
            ? $this->chat->runCommand('/' . $spec->name)
            : $this->chat->runPaletteAction($spec->label());

        // REV-A MINOR-b: a command's answer is a Chat transition like any
        // keystroke's, so it crosses the SAME auto-dock choke delegateToChat
        // uses — without it, content a command lands (a provider list under
        // an agent run, say) keeps its pane side-logged until the next
        // unrelated keystroke docks it.
        $app = $next === $this->chat ? $this : $this->withChat($next)->autoEnablePanes();
        // P-TABS.e: the command ran on the FOREGROUND Chat, so its Cmd
        // takes the foreground's deck key and its session mint rekeys the
        // deck — exactly the delegateToChat rule, at the command's own
        // return.
        $app = $app->rekeyDeck();

        return [$app, $app->scopeForegroundCmd($cmd)];
    }

    /**
     * Run a `command:<name>` chord binding (plan_comparison C-EXT-5) by
     * handing `/<name>` to the hosted Chat's OWN command entry point — the
     * menu-bar tail of {@see runRegistryCommand()}, not its registry lookup.
     *
     * This is typing parity, and it is the point: a bound command answers
     * exactly what typing it would answer, including Chat's refusal of every
     * command mid-turn and the unknown-command notice for a name no longer
     * registered, and it preserves the input draft because that is what
     * `runCommand()` does with one. The shell-state special cases the menu
     * row needs (`settings` opening through the shell so it works mid-turn)
     * are deliberately NOT reproduced — a binding binds the command, not a
     * second menu row.
     */
    private function runBoundCommand(string $name): array
    {
        if ($this->chat === null) {
            return [$this->withStatus(Lang::t('tui.app.no_chat', ['name' => $name])), null];
        }

        [$next, $cmd] = $this->chat->runCommand('/' . $name);

        // The same rules runRegistryCommand applies at a command's return:
        // a Chat transition crosses the auto-dock choke, and its Cmd takes
        // the foreground's deck key.
        $app = $next === $this->chat ? $this : $this->withChat($next)->autoEnablePanes();
        $app = $app->rekeyDeck();

        return [$app, $app->scopeForegroundCmd($cmd)];
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
     * and sugar-core's Program takes a single Cmd per update.
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
     * own {@see \SugarCraft\Code\Tui\Commands\KeyCmd}.
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
     * This is deliberately NOT on the Model path. sugar-core's
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
     * loop in {@see \SugarCraft\Code\Runtime} runs completions directly — so
     * this is exactly as reachable as the arms were before they moved here,
     * neither more nor less. Wiring a driver onto it is a later step.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    public function dispatch(Msg $msg): array
    {
        return match (true) {
            $msg instanceof UserInputMsg => $this->handleUserInput($msg),
            $msg instanceof SelectPaneMsg => [$this->withPane($msg->pane)->withError(null), null],
            $msg instanceof DockPaneMsg => $this->applyDockCommand($msg),
            $msg instanceof LayoutResetMsg => [$this->layoutReset()->withStatus(Lang::t('tui.app.layout_reset')), null],
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
     * @param array{0: self, 1: ?\Closure} $handled
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
     * @return array{0: self, 1: ?\Closure}
     */
    public function applyDockCommand(DockPaneMsg $msg): array
    {
        $action = strtolower($msg->action);

        if ($action === 'toggle') {
            return $this->applyPaneToggle($msg);
        }

        $side = self::dockSideFromWord($action);

        if ($side === null) {
            return [$this->withError(Lang::t('tui.app.pane_bad_side', ['side' => $msg->action])), null];
        }

        $name = $msg->paneName ?? $this->pane->value;

        if ($msg->paneName === null && !$this->pane->dockable()) {
            return [$this->withError(Lang::t('tui.app.pane_none_focused_dock')), null];
        }

        $pane = $name !== null ? Pane::tryFrom($name) : null;

        if ($pane === null || !$pane->dockable()) {
            return [$this->withError(Lang::t('tui.app.pane_not_dockable', ['name' => $name])), null];
        }

        return [$this->setPaneSide($pane, $side)->withStatus(Lang::t($side === Side::Left ? 'tui.app.pane_docked_left' : 'tui.app.pane_docked_right', ['pane' => $pane->label()])), null];
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
     * @return array{0: self, 1: ?\Closure}
     */
    private function applyPaneToggle(DockPaneMsg $msg): array
    {
        if ($msg->paneName === null && !$this->pane->dockable()) {
            return [$this->withError(Lang::t('tui.app.pane_none_focused_toggle')), null];
        }

        $name = $msg->paneName ?? $this->pane->value;
        $pane = Pane::tryFrom($name);

        if ($pane === null || !$pane->dockable()) {
            return [$this->withError(Lang::t('tui.app.pane_not_dockable', ['name' => $name])), null];
        }

        $key = $this->isDocked($pane) ? 'tui.app.pane_undocked' : 'tui.app.pane_docked';
        $wasDocked = $this->isDocked($pane);

        return [
            $this->togglePaneDocking($pane)->withStatus(Lang::t($key, ['pane' => $pane->label()])),
            $this->contextMeasureOnDock($pane, $wasDocked),
        ];
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
        // ISS-049: the size lands on the model FIRST. The auto-dock choke
        // below (delegateToChat → autoEnablePanes) measures the resulting
        // chat column against App::$cols; when the mutate trailed the
        // delegation, every resize evaluated that guard against the PREVIOUS
        // frame — and the very first dispatch decided docks on an unmeasured
        // (null) width. Sizing first also means every instance the
        // delegation can hand back (its identity short-circuits included)
        // already carries the new frame, so no re-mutate is needed on the way out.
        $sized = $this->mutate(rows: $msg->rows, cols: $msg->cols);
        [$next, $cmd] = $sized->delegateToChat($msg);

        // An open Player resizes itself: the same WindowSizeMsg is its own
        // rebuild contract (decode geometry follows the band).
        if ($next->videoPlayer !== null) {
            [$screen, $playerCmd] = $next->videoPlayer->update($msg);
            $next = $screen === $next->videoPlayer ? $next : $next->mutate(videoPlayer: $screen);
            $cmd ??= $playerCmd;
        }

        return [$next, $cmd];
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

        // rv-a11y M3(c): `Ctrl+O` stops being newest-only at the shell. The
        // first press of a burst is delegated untouched — Chat's own
        // collapse/expand of the newest unit, byte-identical to yesterday —
        // and every further press within TOOL_WALK_WINDOW_SECONDS walks one
        // transcript row older, cycling. This is the one delivery choke point
        // both keyboard doors pass (the E666 note above), so claiming the
        // chord here cannot steal it from a modal: the abandoned-palette and
        // stand-down guards have already run, and every surface that would
        // answer Ctrl+O before the transcript expansion arm — an open palette,
        // session picker or directory picker inside Chat, or a settings,
        // media-form, video-player, diff-review or agent-view overlay above it
        // — disarms the walk, so that keystroke reaches the modal exactly as before.
        //
        // v2:a11y-F1 (fix1021): `Ctrl+F` shares this gate — and its refusals —
        // because it is the same conversation with the transcript: it moves
        // the row cursor ONE ROW OLDER without toggling anything, the click-
        // a-row equivalent the keyboard was missing; `Ctrl+O` on a settled
        // cursor then toggles exactly THAT row instead of the newest.
        if ($msg instanceof KeyMsg && (self::isToolWalkChord($msg) || self::isToolRowChord($msg))
            && $this->settingsEditor === null && $this->mediaForm === null
            && $this->videoPlayer === null && $this->diffReview === null && $this->messagePicker === null && $this->agentViewTarget === null
            && $this->chat?->palette() === null && $this->chat?->sessionPicker() === null
            && $this->chat?->dirPicker() === null
        ) {
            return self::isToolRowChord($msg)
                ? $this->focusToolRow($msg)
                : $this->walkToolOutput($msg);
        }

        [$next, $cmd] = $this->chat->update($msg);

        // P-TABS.e: a Cmd the foreground Chat returns is tagged with the
        // deck key that owns it, so its produced Msgs come back wrapped and
        // can never be applied to a tab the user switched to in the
        // meantime. No deck → TabScope never sees it, identity behaviour.
        // The rekey rides the same rule: `/branch` and a mint move the
        // FOREGROUND tab's session in place, and the deck must not go on
        // claiming the old id.
        if (!$next instanceof Chat || $next === $this->chat) {
            return [$this, $this->scopeForegroundCmd($cmd)];
        }

        $app = $this->withChat($next)->autoEnablePanes()->rekeyDeck();

        return [$app, $app->scopeForegroundCmd($cmd)];
    }

    /**
     * Is this keystroke exactly the `Ctrl+O` chord Chat's newest-expansion
     * arm answers? Mirrors that arm's shape so the walk intercepts precisely
     * the presses that would reach it — nothing wider.
     */
    private static function isToolWalkChord(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'o';
    }

    /**
     * Is this keystroke exactly the `Ctrl+F` chord that moves the transcript
     * row cursor? Shaped like {@see isToolWalkChord()} on purpose: the two
     * chords share the gate and its modal refusals, so neither can steal the
     * keystroke from a surface that answers it first.
     */
    private static function isToolRowChord(KeyMsg $msg): bool
    {
        return $msg->type === KeyType::Char && $msg->ctrl && $msg->rune === 'f';
    }

    /**
     * The transcript's expandable row units, newest first: one per history
     * message carrying a settled thought, a running placeholder, or tool
     * results. The keys are the same ones `Chat::$expanded` is keyed by (see
     * {@see Renderer::thoughtKey()} and Chat's `latestToolResultIds()` — the
     * identical `$result->id ?? $result->name` ladder), so the click zones,
     * Chat's own `Ctrl+O` and this walk drive ONE expansion state.
     *
     * @return list<list<string>>
     */
    private function toolWalkUnits(): array
    {
        $units = [];

        foreach (array_reverse($this->chat?->history ?? []) as $message) {
            $keys = [];
            if ($message->reasoning !== null && trim($message->reasoning) !== '') {
                $keys[] = Renderer::thoughtKey($message->reasoning);
            }
            if ($message->pendingToolCallId !== null) {
                $keys[] = $message->pendingToolCallId;
            }
            foreach ($message->toolResults as $result) {
                if ($result instanceof ToolResult) {
                    $keys[] = $result->id ?? $result->name;
                }
            }
            if ($keys !== []) {
                $units[] = $keys;
            }
        }

        return $units;
    }

    /**
     * The route behind `Ctrl+O` at the shell (rv-a11y M3c, v2:a11y-F1).
     *
     * With a SETTLED row cursor — a row `Ctrl+F` focused, or a click that
     * named one — the press toggles exactly THAT row unit and nothing else:
     * the older-row expand the mouse always had, now on the keyboard.
     *
     * Otherwise the FIRST press of a burst is delegated to Chat untouched —
     * the newest-unit behavior users already have, byte-identical. Each
     * further press within {@see TOOL_WALK_WINDOW_SECONDS} toggles the
     * next-older row unit ({@see toolWalkUnits()}) as one unit, following the
     * unit's first key exactly like Chat's own batch toggle, and cycles once
     * the transcript runs out. A burst that times out restarts at the newest
     * row; a cursor that outlives its row (the history was edited) falls back
     * to that burst logic rather than silently retargeting.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function walkToolOutput(KeyMsg $msg): array
    {
        $units = $this->toolWalkUnits();
        if ($units === []) {
            return [$this, null];
        }

        $now = microtime(true);
        $state = $this->toolWalkState;
        $focused = $this->focusedUnitIndex($units);

        if ($focused !== null) {
            $chat = $this->chat;
            $expand = !$chat->isToolOutputExpanded($units[$focused][0]);
            foreach ($units[$focused] as $id) {
                if ($chat->isToolOutputExpanded($id) !== $expand) {
                    $chat = $chat->toggleToolOutput($id);
                }
            }

            $app = $chat === $this->chat ? $this : $this->withChat($chat)->autoEnablePanes();

            return [$app->mutate(toolWalkState: [
                'at' => $now,
                'index' => $focused,
                'settled' => true,
                'key' => $units[$focused][0],
            ]), null];
        }

        $index = ($state !== null && $now - $state['at'] <= self::TOOL_WALK_WINDOW_SECONDS
            ? $state['index'] + 1
            : 0) % count($units);

        if ($index === 0) {
            [$next, $cmd] = $this->chat->update($msg);
            $app = $next instanceof Chat && $next !== $this->chat
                ? $this->withChat($next)->autoEnablePanes()
                : $this;

            return [$app->mutate(toolWalkState: ['at' => $now, 'index' => 0]), $cmd];
        }

        $chat = $this->chat;
        $expand = !$chat->isToolOutputExpanded($units[$index][0]);
        foreach ($units[$index] as $id) {
            if ($chat->isToolOutputExpanded($id) !== $expand) {
                $chat = $chat->toggleToolOutput($id);
            }
        }

        $app = $chat === $this->chat ? $this : $this->withChat($chat)->autoEnablePanes();

        return [$app->mutate(toolWalkState: ['at' => $now, 'index' => $index]), null];
    }

    /**
     * The route behind `Ctrl+F` at the shell (v2:a11y-F1): move the transcript
     * row cursor one unit older, cycling, WITHOUT touching any row's
     * expansion — the gesture that makes "which row does `Ctrl+O` answer" a
     * user choice instead of "whichever is newest". Seeding from a live burst
     * ({@see TOOL_WALK_WINDOW_SECONDS}) continues where that burst stands,
     * so the two chords compose; the first fresh press lands on the row one
     * older than the newest, because `Ctrl+O` already owns the newest.
     *
     * A settled cursor is exempt from the burst window: it decays only when
     * its row leaves the transcript ({@see focusedUnitIndex()}).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function focusToolRow(KeyMsg $msg): array
    {
        $units = $this->toolWalkUnits();
        if ($units === []) {
            return [$this, null];
        }

        $now = microtime(true);
        $state = $this->toolWalkState;
        $focused = $this->focusedUnitIndex($units);
        $current = $focused ?? ($state !== null && $now - $state['at'] <= self::TOOL_WALK_WINDOW_SECONDS
            ? $state['index']
            : 0);
        $index = ($current + 1) % count($units);

        return [$this->mutate(toolWalkState: [
            'at' => $now,
            'index' => $index,
            'settled' => true,
            'key' => $units[$index][0],
        ]), null];
    }

    /**
     * Where the settled row cursor points in the CURRENT units, or null when
     * there is no settled cursor. Identity is the stored key, not the index:
     * a newer row arriving above the cursor shifts its index, and the cursor
     * follows the ROW the user picked, not the number it happened to have.
     *
     * @param list<list<string>> $units
     */
    private function focusedUnitIndex(array $units): ?int
    {
        $state = $this->toolWalkState;
        if ($state === null || ($state['settled'] ?? false) !== true) {
            return null;
        }

        $key = $state['key'] ?? null;
        if (!is_string($key)) {
            return null;
        }

        foreach ($units as $index => $unit) {
            if (in_array($key, $unit, true)) {
                return $index;
            }
        }

        return null;
    }

    /**
     * The row-unit keys the SETTLED cursor stands on (W7-B), or the empty
     * list while none is settled — the render seam's read of
     * {@see focusedUnitIndex()}, in the identity the renderer keys rows by.
     * The whole unit, not just its lead key: the highlight marks exactly the
     * rows `Ctrl+O` will answer, so what the user sees and what the chord
     * does never disagree.
     *
     * @return list<string>
     */
    public function focusedToolRowKeys(): array
    {
        $units = $this->toolWalkUnits();
        $index = $this->focusedUnitIndex($units);

        return $index === null ? [] : $units[$index];
    }

    /**
     * Settle the row cursor on the unit that carries `$key` (v2:a11y-F1): a
     * click names the exact row, and after it the keyboard's `Ctrl+O` answers
     * that row — one conversation with the transcript, whichever hand steers.
     * A key that no longer belongs to any unit (a stale zone click) moves
     * nothing.
     */
    private function withToolRowCursor(string $key): self
    {
        foreach ($this->toolWalkUnits() as $index => $unit) {
            if (in_array($key, $unit, true)) {
                return $this->mutate(toolWalkState: [
                    'at' => microtime(true),
                    'index' => $index,
                    'settled' => true,
                    'key' => $unit[0],
                ]);
            }
        }

        return $this;
    }

    /**
     * Render the whole shell: menu bar, sidebars, chat pane, input, status.
     *
     * {@see TuiRenderer::renderView()} is the pane compositor; the chat pane it
     * lays out delegates its body to the live
     * {@see \SugarCraft\Code\Renderer} against {@see $chat}, so the shell
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
        // P-C2: the open Agent View rides the same per-paint seam — the chat's
        // renderer swaps the transcript area for it, for this frame only.
        Renderer::setAgentView($this->agentViewFrame());
        // CL-2 FIX 2: a docked Agents sidebar IS the frame's agent listing —
        // the same per-paint seam tells the transcript renderer to hold its
        // footer off so the frame never carries two listings.
        Renderer::setAgentsPaneDocked($this->isDocked(Pane::Agents));
        // W7-B: the settled Ctrl+F row cursor rides the same per-paint seam —
        // the transcript paints its unit's rows in the theme's active style,
        // for this frame only.
        Renderer::setFocusedToolRowKeys($this->focusedToolRowKeys());
        try {
            return TuiRenderer::renderView($this, $this->cols, $this->rows);
        } finally {
            Renderer::setPaletteAbandoned(false);
            Renderer::setAgentView(null);
            Renderer::setAgentsPaneDocked(false);
            Renderer::setFocusedToolRowKeys([]);
        }
    }

    /**
     * Subscriptions the Program should pump, delegated to the hosted chat.
     *
     * A hosted `Chat` keeps whatever polling it declares standalone instead of
     * losing it to the wrapper. The shell adds one tick of its own, the open
     * Agent View's tail.
     */
    public function subscriptions(): ?Subscriptions
    {
        $subscriptions = $this->chat?->subscriptions();

        // P-TABS.e: parked tabs keep pumping their own work — a live turn's
        // tool-event poll, its transcript flush, its session-lock retry —
        // each id welded to the tab that produced it, so the reconciliation
        // in Program sees one stable entry per tab per need and a switch
        // never tears down another tab's pump. The ids on
        // FOREGROUND_ONLY_TAB_SUBSCRIPTIONS are dropped for parked tabs:
        // they poll SHARED workspace state the foreground already polls, or
        // draw the one status line.
        if ($this->deck !== null) {
            $all = $subscriptions?->all() ?? [];

            foreach ($this->deck->keys() as $key) {
                if ($key === $this->deck->activeKey()) {
                    continue;
                }

                $parked = $this->deck->model($key);
                if (!$parked instanceof Chat) {
                    continue;
                }

                $scoped = TabScope::subscriptions($key, $parked->subscriptions(), self::FOREGROUND_ONLY_TAB_SUBSCRIPTIONS);
                if ($scoped !== null) {
                    $all = [...$all, ...$scoped->all()];
                }
            }

            $subscriptions = new Subscriptions($all);
        }

        // P-C2: the open Agent View's tail tick — declared only while a view
        // is open, keyed by the run so switching runs cancels the old tick.
        // The tick re-sends the open run's OpenAgentViewMsg, which reads what
        // its log gained ({@see refreshAgentView()}).
        $id = $this->agentViewTarget;
        if ($id !== null && $this->chat !== null) {
            $subscriptions = ($subscriptions ?? new Subscriptions())->withTick(
                self::AGENT_VIEW_SUBSCRIPTION . $id,
                self::AGENT_VIEW_TICK_SECONDS,
                static fn (): CoreMsg => new \SugarCraft\Code\App\OpenAgentViewMsg($id),
            );
        }

        // CTX-5: the idle auto-compaction watch (`compaction.idleAuto`,
        // default off). Declared while the Chat says a fold could ever land
        // — session-scoped conditions only, so the id churns a handful of
        // times per session, not per minute. The tick always dispatches an
        // IdleCompactTickMsg; EVERY condition that reads the transcript or
        // the keyboard lives in Chat::handleIdleCompactTick(), which is what
        // keeps this produce closure stateless — load-bearing, because
        // Program keeps the closure installed at arm time for as long as the
        // id is wanted (the E655 lesson the agent-view tick above already
        // documents).
        if ($this->chat?->shouldWatchIdleCompaction() === true) {
            $subscriptions = ($subscriptions ?? new Subscriptions())->withTick(
                self::IDLE_COMPACT_SUBSCRIPTION,
                self::IDLE_COMPACT_TICK_SECONDS,
                static fn (): CoreMsg => new \SugarCraft\Code\App\IdleCompactTickMsg(),
            );
        }

        return $subscriptions;
    }

    /**
     * Open the skill picker: switch to the Skills pane and populate
     * $skillPickerOptions with the user-invocable skill list. Mirrors
     * charmbracelet/crush SourceSkillCmd.
     *
     * @return array{0: self, 1: ?\Closure}
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
            $next = $next->withStatus(Lang::t('tui.app.no_skills'));
        }

        return [$next, null];
    }

    /**
     * Select a skill from an open picker by name, toggling it for the
     * conversation: an un-enabled skill is enabled, a re-selected enabled one
     * is switched off for the session (C-EXT-2.d, through the same
     * {@see SkillManager} pair that owns the vocabulary — the picker never
     * touches the persisted `disabledSkills` list; that is `/skills
     * disable`'s door). Re-validates against the user-invocable filter rather
     * than trusting the caller-supplied name, so a skill that opted out of
     * user invocation can never be enabled through this path even if the
     * picker's own options were bypassed.
     *
     * @return array{0: self, 1: ?\Closure}
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
            return [$this->withError(Lang::t('tui.app.skill_unknown', ['name' => $msg->skillName])), null];
        }

        $alreadyEnabled = false;
        foreach ($this->enabledSkills as $enabled) {
            if ($enabled instanceof Skill && $enabled->name === $skill->name) {
                $alreadyEnabled = true;
                break;
            }
        }

        // C-EXT-2.d: both directions of the picker route through the
        // {@see SkillManager} that owns the enabled-set vocabulary — the
        // accessor pair that shipped dormant until now. Re-selecting an
        // enabled skill toggles it OFF for the session; only `/skills
        // disable` touches the persisted `disabledSkills` list, which is the
        // documented split (the picker is the session half of the switch).
        $manager = new SkillManager(new SkillLoader(), $this->availableSkills);
        if ($alreadyEnabled) {
            $closed = $manager->disable($this, $skill->name)->mutate(
                skillPickerOptions: [],
                skillPickerIndex: 0,
                status: Lang::t('tui.app.skill_disabled', ['name' => $skill->name]),
                error: null,
            );

            return [$this->dropSelectedSkill($skill, $closed), null];
        }

        // A `context: fork` skill is still ENABLED — the Skill tool's fork
        // branch needs the skill registered and invocable, and
        // {@see dispatchSkill()} reads the enabled set as the embedder seam —
        // but it is not enabled in the sense the plain message means: a
        // fork's body is never spliced into the system prompt. It runs in a
        // forked sub-agent when invoked, through the Skill tool on the turn's
        // bound Task (C-EXT-2.c). The distinct status line outlived the
        // dormancy it was written for and says what still holds.
        $status = $this->availableSkills->isContextFork($skill->name)
            ? Lang::t('tui.app.skill_fork', ['name' => $skill->name])
            : Lang::t('tui.app.skill_enabled', ['name' => $skill->name]);

        $next = $manager->enable($this, $skill->name)->mutate(
            skillPickerOptions: [],
            skillPickerIndex: 0,
            status: $status,
            error: null,
        );

        // ISS-035: enabling a skill at the palette has to reach the MODEL.
        // Every turn's sessionApp() splices EngineBackend::$skills — fixed at
        // launch — into the prompt, so the picked skill is carried onto the
        // chat's engine beside the shell-side set. Without this the status
        // line said "Enabled" and the prompt never changed.
        return [$this->carrySelectedSkill($skill, $next), null];
    }

    /**
     * Carry a palette-enabled skill onto the chat's {@see EngineBackend} so
     * the next turn ships its prompt (ISS-035). The shell-side enabledSkills
     * list alone never reached the model: Runtime splices the backend's own
     * skills into sessionApp(), and that list was written once by Bootstrap's
     * launch filter. A chat-less App, or a backend without a prompt-skill
     * list (RemoteBackend), keeps the shell-only behaviour the picker had
     * before — no skill registry crosses that boundary from this side.
     */
    private function carrySelectedSkill(Skill $skill, self $next): self
    {
        $chat = $this->chat;
        if ($chat === null) {
            return $next;
        }
        $backend = $chat->backend();
        if (!$backend instanceof EngineBackend) {
            return $next;
        }
        foreach ($backend->skills() as $carried) {
            if ($carried->name === $skill->name) {
                return $next;
            }
        }

        return $next->withChat($chat->withBackend($backend->withSkills([...$backend->skills(), $skill])));
    }

    /**
     * The disable twin of {@see carrySelectedSkill()} (C-EXT-2.d): a skill the
     * picker toggles off leaves the engine's per-turn splice list too, so the
     * next turn's prompt drops it exactly when the status line says it did.
     * No chat, no engine-carried list, no change — the same guards the carry
     * runs, mirrored.
     */
    private function dropSelectedSkill(Skill $skill, self $next): self
    {
        $chat = $this->chat;
        if ($chat === null) {
            return $next;
        }
        $backend = $chat->backend();
        if (!$backend instanceof EngineBackend) {
            return $next;
        }
        $kept = \array_values(\array_filter(
            $backend->skills(),
            static fn(Skill $carried): bool => $carried->name !== $skill->name,
        ));

        return $next->withChat($chat->withBackend($backend->withSkills($kept)));
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
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleToolResult(ToolResultMsg $msg): array
    {
        $toolMsg = new ToolResultMessage($msg->toolCallId, $msg->content, $msg->isError);

        return [$this->withMessage($toolMsg), null];
    }

    /**
     * Seed the deck once, on the first update of a launch that carries a
     * tabFactory (P-TABS.e). The foreground Chat at that moment IS the
     * launch's first tab; its session id may still be null (a fresh session
     * mints on first persist), and the sentinel for "not yet minted" is the
     * empty string — {@see rekeyDeck()} replaces it the moment the id is
     * real. A factory-less launch returns `$this` forever: one null check,
     * zero new instances, the pre-deck tree untouched.
     */
    private function armDeck(): self
    {
        if ($this->deck !== null || $this->tabFactory === null || !$this->chat instanceof Chat) {
            return $this;
        }

        // P-TABS.f: the launch chat becomes the deck's FIRST foreground, and
        // withChat only fires on swaps — so the appointment is seeded here too
        // (withTabHosted is a no-op under a factory-less launch, which never
        // reaches this line). Without it, a picker resume on tab-1 would keep
        // switching in place under the deck key armDeck is about to write.
        $activeTab = $this->chat->openTabs()->activeTab();

        return $this->mutate(
            chat: $this->chat->withTabHosted(true),
            deck: SessionDeck::forLaunch(
                'tab-1',
                $this->chat->currentSessionId() ?? '',
                $activeTab === null ? Lang::t('tabs.name_new') : $activeTab->sessionName,
            ),
        );
    }

    /** The next minted deck key — deck keys are stable, never reused. */
    private function nextTabKey(): string
    {
        $highest = 0;
        foreach ($this->deck?->keys() ?? [] as $key) {
            if (preg_match('/^tab-([0-9]+)$/', $key, $m) === 1) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return 'tab-' . ($highest + 1);
    }

    /**
     * Tag a foreground Chat's Cmd with the deck key that owns it, so every
     * Msg its producers fire come back wrapped and route to THIS tab even
     * after the user switches. No deck → the Cmd passes through untouched.
     */
    private function scopeForegroundCmd(?\Closure $cmd): ?\Closure
    {
        $key = $this->deck?->activeKey();

        return $key === null ? $cmd : TabScope::cmd($key, $cmd);
    }

    /**
     * Follow a foreground session move that happened IN PLACE under the
     * active tab — `/branch`, the first-persist mint, a picker adoption.
     * The deck key never changes; only its session id does.
     */
    private function rekeyDeck(): self
    {
        $key = $this->deck?->activeKey();
        if ($key === null || !$this->chat instanceof Chat) {
            return $this;
        }

        $session = $this->chat->currentSessionId();
        if ($session === null || $this->deck->sessionOf($key) === $session) {
            return $this;
        }

        return $this->mutate(deck: $this->deck->rekey($key, $session));
    }

    /**
     * The deck projected as the shell's window list (P-TABS.g) — what the
     * horizontal strip and the vertical Sessions pane both render, and what
     * the OSC 2 title counts over. Null means no deck: every surface that
     * consumes this treats null as "the strip is not armed" and paints
     * nothing, the same shape the deck routing keeps for a single chat.
     *
     * Agent chips ride EVERY tab (P-AGENT.b): each parked model is its own
     * Chat with its own AgentLiveRegistry and its own pending permission
     * ask, so the census is readable straight off the deck. A run whose
     * prompt is waiting on the user carries Waiting (⏳) through the ask's
     * {@see \SugarCraft\Code\Permissions\AskOrigin}, which is the one state
     * a chip must show even on a background tab; running carries Working,
     * everything else Idle.
     */
    public function windowList(): ?WindowList
    {
        $deck = $this->deck;
        if ($deck === null) {
            return null;
        }

        $tabRows = [];
        foreach ($deck->tabs()->tabs() as $row) {
            $tabRows[$row->id] = $row;
        }

        $active = $deck->activeKey();
        $list = WindowList::empty();
        foreach ($deck->keys() as $key) {
            $tab = $tabRows[$key] ?? null;
            $agents = [];
            $model = $key === $active && $this->chat !== null ? $this->chat : $deck->model($key);
            if ($model instanceof Chat) {
                $session = $deck->sessionOf($key);
                $waitingOn = $model->pendingPermission()?->pendingAsk?->origin?->agentId;
                foreach ($model->agentLive()->forSession($session) as $state) {
                    $agents[] = WindowEntry::agent(
                        WindowRef::agent($state->id, $state->name, $key),
                        $state->id === $waitingOn
                            ? WindowAttention::Waiting
                            : ($state->isRunning() ? WindowAttention::Working : WindowAttention::Idle),
                    );
                }
            }

            $list = $list->add(WindowEntry::session(
                WindowRef::session($key, $tab === null ? $key : $tab->sessionName),
                WindowAttention::tryFrom($tab === null ? SessionDeck::ATTENTION_IDLE : $tab->attention) ?? WindowAttention::Idle,
                count($agents),
                $agents,
            ));
        }

        return $list->focus($active);
    }

    /**
     * The OSC 2 title for a projected list: the plain name while nothing
     * needs the user, `waiting · running` counts the moment something does.
     * Waiting sums the three states that block a tab (WAITING, UNSEEN,
     * FAILED); running counts WORKING. (P-TABS.g)
     */
    private function deckTitleFor(WindowList $list): string
    {
        $waiting = 0;
        $running = 0;
        foreach ($list->entries() as $entry) {
            match ($entry->attention) {
                WindowAttention::Waiting, WindowAttention::Unseen, WindowAttention::Failed => $waiting++,
                WindowAttention::Working => $running++,
                default => null,
            };
        }

        if ($waiting === 0 && $running === 0) {
            return Lang::t('tui.window.title.plain');
        }

        return Lang::t('tui.window.title.attention', ['waiting' => $waiting, 'running' => $running]);
    }

    /**
     * The deck choke every tab-mutating route lands on (P-TABS.g): re-derive
     * the window list from the NEW app, then two once-per-state effects —
     *
     *  - the OSC 2 title, emitted only when it actually changed, which is
     *    what makes the batch free of per-frame chatter;
     *  - the desktop notice for a BACKGROUND tab that reached Waiting or
     *    Unseen, fired once per transition through {@see $tabNotified} (the
     *    map is pruned to the deck's live keys every pass so it never grows).
     *
     * A null list (no deck) passes the command through untouched, and a
     * disabled notifier answers a null Cmd — nothing is booked then, so a
     * user who turns notifications on mid-session is told about the states
     * that are pending right now, once, and never about expired ones.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function withDeckEffects(self $app, ?\Closure $cmd): array
    {
        $list = $app->windowList();
        if ($list === null) {
            return [$app, $cmd];
        }

        $title = $app->deckTitleFor($list);
        if ($title !== $app->deckTitle) {
            $app = $app->mutate(deckTitle: $title);
            $cmd = CoreCmd::batch($cmd, CoreCmd::setWindowTitle($title));
        }

        $keys = [];
        $announce = [];
        foreach ($list->entries() as $entry) {
            $key = $entry->ref->key;
            $keys[] = $key;
            if ($key === $list->focusedKey()) {
                continue;
            }

            if (!$entry->attention->needsAttention()) {
                continue;
            }

            $state = $entry->attention->value;
            if (!in_array($state, [WindowAttention::Waiting->value, WindowAttention::Unseen->value], true)) {
                continue;
            }

            if (($app->tabNotified[$key] ?? '') === $state) {
                continue;
            }

            $appWorkspace = $app->chat?->workspace();
            $notice = TerminalNotifier::fromConfig($appWorkspace === null ? [] : $appWorkspace->userConfig)
                ->cmd($state === WindowAttention::Unseen->value
                    // Both spellings are whole literals: the TuiLang drift
                    // census reads quoted keys from source, and a
                    // concatenated one would count as a key nobody owns.
                    ? Lang::t('tui.window.attention.unseen', ['name' => $entry->ref->label])
                    : Lang::t('tui.window.attention.waiting', ['name' => $entry->ref->label]));
            if ($notice === null) {
                continue;
            }

            $announce[$key] = $state;
            $cmd = CoreCmd::batch($cmd, $notice);
        }

        $notified = array_intersect_key($app->tabNotified, array_flip($keys));
        if ($announce !== []) {
            $notified = array_merge($notified, $announce);
        }

        if ($notified !== $app->tabNotified) {
            $app = $app->mutate(tabNotified: $notified);
        }

        return [$app, $cmd];
    }

    /**
     * Move the foreground to $toKey: park the leaving Chat (its turn keeps
     * running — parking is a bookkeeping move, not a pause), unpark or
     * factory-build the target, hand it the notice-drain appointment and
     * the shell's current window size, and clear any spent close arm.
     *
     * The window-size replay goes straight to the Chat, not through
     * `handleWindowSize()`: the App's own rows/cols are already
     * authoritative (this is not a resize), and the Chat only needs the
     * frame it was last at re-stated — a parked turn rendered nothing, so
     * the first draw after unpark would otherwise be sized for a terminal
     * the tab never saw.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function switchTab(string $toKey): array
    {
        $deck = $this->deck;
        $from = $deck?->activeKey();
        if ($deck === null || !$deck->has($toKey) || $toKey === $from || !$this->chat instanceof Chat) {
            return [$this, null];
        }

        // P-AGENT.b: the Agent View is per-tab now. Leaving with a view open
        // closes it exactly the way the user's Esc does — the agent window
        // keeps its scroll and draft in the stash, the tab's main window
        // comes back — and the run is remembered for the way home.
        $leaving = $this;
        $outgoing = $this->chat;
        $openRun = $this->agentViewTarget;
        if ($openRun !== null) {
            $leaving = $leaving->closeAgentView();
            $leaving = $leaving->mutate(agentViewByTab: [...$leaving->agentViewByTab, (string) $from => $openRun]);
            $restored = $leaving->chat;
            if ($restored instanceof Chat) {
                $outgoing = $restored;
            }
        }

        $deck = $deck->park($from, $outgoing->withNoticeDrain(false));
        [$deck, $model] = $deck->unpark($toKey);

        $target = $model instanceof Chat
            ? $model
            : ($this->tabFactory !== null ? ($this->tabFactory)($deck->sessionOf($toKey)) : null);
        if (!$target instanceof Chat) {
            return [$this, null];
        }

        [$sized, $sizeCmd] = $target->withNoticeDrain(true)->update(new WindowSizeMsg($this->cols, $this->rows));
        if ($sized instanceof Chat) {
            $target = $sized;
        }

        $deck = $deck->attention(
            $toKey,
            $target->inFlight || $target->pendingPermission() !== null
                ? ($target->pendingPermission() !== null ? SessionDeck::ATTENTION_WAITING : SessionDeck::ATTENTION_WORKING)
                : SessionDeck::ATTENTION_IDLE,
        );

        // P-TABS.g: mutate the deck BEFORE autoEnablePanes so the Sessions
        // auto-dock arm sees the count this very route produced, and hand the
        // settled app through the deck choke for the title + notices.
        $app = $leaving->withChat($target)->mutate(deck: $deck, pendingTabClose: null)->autoEnablePanes();

        // P-AGENT.b: prune the per-tab memory to the deck's live keys (a
        // closed tab's remembered run must not outlive the tab, on the
        // $tabNotified law) and re-open the view this tab left, when its run
        // is still registered with the incoming chat.
        $app = $app->mutate(agentViewByTab: array_intersect_key($app->agentViewByTab, array_fill_keys($deck->keys(), null)));
        $remembered = $app->agentViewByTab[$toKey] ?? null;
        $rememberedState = $remembered === null ? null : $target->agentLive()->get($remembered);
        if ($remembered !== null && $rememberedState !== null) {
            $app = $app->openAgentView($remembered, name: $rememberedState->name);
        }

        return $this->withDeckEffects($app, $sizeCmd);
    }

    /**
     * Route one wrapped message. Foreground-scoped: unwrap and re-enter the
     * shell's match so every guard runs exactly as it would have unwrapped.
     * Parked: hand the inner Msg to the parked model, keep its reply in the
     * deck, and refresh the tab's attention — a settled parked turn is
     * UNSEEN, an error FAILED, a surfaced permission prompt WAITING, a live
     * turn WORKING. A parked tab can never pop a shell modal: the three
     * shell-claimed open messages raise attention instead of opening, and
     * the run-keyed {@see \SugarCraft\Code\App\AgentMessageSentMsg} is the
     * documented exception — the Agent View it feeds is global, not
     * per-tab, so dropping it would lose a message the foreground itself
     * would have recorded.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleTabScoped(TabScopedMsg $msg): array
    {
        $deck = $this->deck;
        if ($deck === null) {
            return [$this, null];
        }

        if ($msg->tabKey() === $deck->activeKey()) {
            return $this->update($msg->inner());
        }

        $model = $deck->model($msg->tabKey());
        if (!$model instanceof Chat) {
            return [$this, null];
        }

        $inner = $msg->inner();
        if ($inner instanceof OpenMediaFormMsg || $inner instanceof OpenSettingsMsg
            || $inner instanceof \SugarCraft\Code\App\OpenAgentViewMsg) {
            return $this->withDeckEffects($this->mutate(deck: $deck->attention($msg->tabKey(), SessionDeck::ATTENTION_WAITING)), null);
        }

        if ($inner instanceof \SugarCraft\Code\App\AgentMessageSentMsg) {
            // The exception this method promises: the Agent View ledger is
            // global, keyed by run. Chat only PRODUCES these rows (its own
            // match ignores them), so routing one into the parked model
            // would silently drop a row the foreground would have kept —
            // record it here, where the shell's unwrapped arm does.
            return [$this->recordAgentMessage($inner), null];
        }

        [$next, $cmd] = $model->update($inner);
        if (!$next instanceof Chat) {
            $next = $model;
        }

        $deck = $deck->park($msg->tabKey(), $next)->attention($msg->tabKey(), $this->attentionFor($next, $inner));
        $session = $next->currentSessionId();
        if ($session !== null && $deck->sessionOf($msg->tabKey()) !== $session) {
            $deck = $deck->rekey($msg->tabKey(), $session);
        }

        return $this->withDeckEffects($this->mutate(deck: $deck), TabScope::cmd($msg->tabKey(), $cmd));
    }

    /** The attention state a parked tab deserves after one routed message. */
    private function attentionFor(Chat $chat, CoreMsg $settled): string
    {
        if ($chat->pendingPermission() !== null) {
            return SessionDeck::ATTENTION_WAITING;
        }

        if ($settled instanceof ErrorMsg) {
            return SessionDeck::ATTENTION_FAILED;
        }

        if ($settled instanceof \SugarCraft\Code\App\AssistantMsg && !$chat->inFlight) {
            return SessionDeck::ATTENTION_UNSEEN;
        }

        return $chat->inFlight || $chat->isStreaming()
            ? SessionDeck::ATTENTION_WORKING
            : SessionDeck::ATTENTION_IDLE;
    }

    /**
     * Open a tab: park the foreground (drain appointment included), mint the
     * next key, build the Chat through the launch's factory — sized and
     * appointed — and make it active. `sessionId` null is a FRESH session
     * (mints on first persist; {@see handleTabScoped()} rekeys it in place).
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleOpenTab(OpenTabMsg $msg): array
    {
        $deck = $this->deck;
        if ($deck === null || $this->tabFactory === null || !$this->chat instanceof Chat) {
            return [$this, null];
        }

        $key = $this->nextTabKey();
        $target = ($this->tabFactory)($msg->sessionId);
        if (!$target instanceof Chat) {
            // A factory that answers with something other than a Chat is a
            // wiring bug, not a user event: refuse the open, change nothing.
            return [$this, null];
        }

        $from = $deck->activeKey();
        if ($from !== null) {
            $deck = $deck->park($from, $this->chat->withNoticeDrain(false));
        }

        // P-TABS.f note: `''` is the not-yet-minted sentinel — two id-less fresh tabs briefly share it until their first persist rekeys them.
        $deck = $deck->open($key, $msg->sessionId ?? '', $msg->sessionName ?? Lang::t('tabs.name_new'));

        [$sized, $sizeCmd] = $target->withNoticeDrain(true)->update(new WindowSizeMsg($this->cols, $this->rows));
        if ($sized instanceof Chat) {
            $target = $sized;
        }

        return $this->withDeckEffects($this->withChat($target)->mutate(deck: $deck)->autoEnablePanes(), $sizeCmd);
    }

    /**
     * Close a tab. Closing a BUSY tab needs a second press within
     * {@see TAB_CLOSE_ARM_SECONDS} naming the same tab — the first press
     * only arms and says so in the status row, because silently killing a
     * streaming turn is the kind of loss a user must see coming. The
     * confirming press cancels the turn (Chat's own Escape arm), flushes the
     * transcript, and drops the tab; closing the FOREGROUND first moves
     * away (a switch parks it exactly like Ctrl+Tab would), then the same
     * parked path removes it. The deck never closes its last tab — that
     * refusal is {@see SessionDeck::close()}'s, enforced here too.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleCloseTab(CloseTabMsg $msg): array
    {
        $deck = $this->deck;
        if ($deck === null || $deck->count() <= 1) {
            return [$this, null];
        }

        $key = $msg->tabKey ?? $deck->activeKey();
        if ($key === null || !$deck->has($key)) {
            return [$this, null];
        }

        $isForeground = $key === $deck->activeKey();
        $model = $isForeground ? $this->chat : $deck->model($key);
        $busy = $model instanceof Chat && ($model->inFlight || $model->isStreaming());

        if ($busy) {
            $armed = $this->pendingTabClose;
            if (!is_array($armed) || $armed['key'] !== $key || (microtime(true) - $armed['at']) > self::TAB_CLOSE_ARM_SECONDS) {
                return $this->withDeckEffects(
                    $this
                        ->mutate(pendingTabClose: ['key' => $key, 'at' => microtime(true)])
                        ->withStatus(Lang::t('tabs.close_arm')),
                    null,
                );
            }
        }

        if ($isForeground) {
            $moved = $deck->cycle(1)->activeKey();
            if ($moved === null || $moved === $key) {
                return [$this, null];
            }

            [$switched, $switchCmd] = $this->switchTab($moved);
            if ($switched === $this) {
                return [$this, null];
            }

            // switchTab() clears the arm as part of making the switch (a
            // switch is a fresh action); the recursion into the parked branch
            // must still carry it, or press two would only re-arm instead of
            // closing the tab the user just confirmed away.
            $carried = $switched->mutate(pendingTabClose: $this->pendingTabClose);

            [$closed, $closeCmd] = $carried->handleCloseTab(new CloseTabMsg($key));

            return $this->withDeckEffects($closed, CoreCmd::batch($switchCmd, $closeCmd));
        }

        if ($model instanceof Chat) {
            if ($busy) {
                // Best-effort synchronous cancel: the turn's token must be
                // released NOW because the tab is about to lose its routing
                // — a scoped Cmd for a closed key can never be delivered,
                // and dropping it un-cancelled would orphan the fork until
                // the process exits. The Cmd chain runs against the dying
                // model only to fire its side effects; its bookkeeping
                // returns are uninteresting a step before the tab is gone.
                for ($guard = 0; $guard < 4 && ($model->inFlight || $model->isStreaming()); $guard++) {
                    $result = $model->update(new KeyMsg(KeyType::Escape));
                    $model = $result[0] instanceof Chat ? $result[0] : $model;
                    if ($result[1] !== null) {
                        $result[1]();
                    }
                }
            }

            $model->flushTranscript();
        }

        [$closed] = $deck->close($key);

        return $this->withDeckEffects($this->mutate(deck: $closed, pendingTabClose: null), null);
    }

    /**
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleCycleTab(CycleTabMsg $msg): array
    {
        $deck = $this->deck;
        if ($deck === null || $deck->count() <= 1) {
            return [$this, null];
        }

        $target = $deck->cycle($msg->delta)->activeKey();
        if ($target === null || $target === $deck->activeKey()) {
            return [$this, null];
        }

        return $this->switchTab($target);
    }

    /**
     * Quit with busy background tabs asks ONCE (P-TABS.e): the first press
     * spends {@see $tabQuitWarned} and lands a status row instead of
     * forwarding; every press after — or any press with nothing streaming —
     * runs the pre-deck route, `delegateToChat`, untouched. Asking once and
     * never re-arming is the plan's shape: a user who waits for the notice
     * and presses again has read it.
     *
     * @return array{0: self, 1: ?\Closure}
     */
    private function handleQuit(\SugarCraft\Core\Msg\QuitMsg $msg): array
    {
        if ($this->deck !== null && !$this->tabQuitWarned && $this->deckHasBusyBackground()) {
            return [$this->mutate(tabQuitWarned: true)->withStatus(Lang::t('tabs.quit_arm')), null];
        }

        return $this->delegateToChat($msg);
    }

    /** Any PARKED tab still holding a turn? (Foreground busy is Chat's own quit story.) */
    private function deckHasBusyBackground(): bool
    {
        if ($this->deck === null) {
            return false;
        }

        foreach ($this->deck->keys() as $key) {
            if ($key === $this->deck->activeKey()) {
                continue;
            }

            $model = $this->deck->model($key);
            if ($model instanceof Chat && ($model->inFlight || $model->isStreaming())) {
                return true;
            }
        }

        return false;
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
            compactorConfig: array_key_exists('compactorConfig', $changes) ? $changes['compactorConfig'] : $this->compactorConfig,
            contextLedger: array_key_exists('contextLedger', $changes) ? $changes['contextLedger'] : $this->contextLedger,
            expandedAgents: array_key_exists('expandedAgents', $changes) ? $changes['expandedAgents'] : $this->expandedAgents,
            agentStripFocus: array_key_exists('agentStripFocus', $changes) ? $changes['agentStripFocus'] : $this->agentStripFocus,
            agentStripDismissed: array_key_exists('agentStripDismissed', $changes) ? $changes['agentStripDismissed'] : $this->agentStripDismissed,
            paneScroll: array_key_exists('paneScroll', $changes) ? $changes['paneScroll'] : $this->paneScroll,
            agentSplitCols: array_key_exists('agentSplitCols', $changes) ? $changes['agentSplitCols'] : $this->agentSplitCols,
            settingsEditor: array_key_exists('settingsEditor', $changes) ? $changes['settingsEditor'] : $this->settingsEditor,
            settingsSources: array_key_exists('settingsSources', $changes) ? $changes['settingsSources'] : $this->settingsSources,
            settingsWriter: array_key_exists('settingsWriter', $changes) ? $changes['settingsWriter'] : $this->settingsWriter,
            mediaForm: array_key_exists('mediaForm', $changes) ? $changes['mediaForm'] : $this->mediaForm,
            videoPlayer: array_key_exists('videoPlayer', $changes) ? $changes['videoPlayer'] : $this->videoPlayer,
            trustDialog: array_key_exists('trustDialog', $changes) ? $changes['trustDialog'] : $this->trustDialog,
            reasoningEffort: array_key_exists('reasoningEffort', $changes) ? $changes['reasoningEffort'] : $this->reasoningEffort,
            agentViewTarget: array_key_exists('agentViewTarget', $changes) ? $changes['agentViewTarget'] : $this->agentViewTarget,
            agentViewTail: array_key_exists('agentViewTail', $changes) ? $changes['agentViewTail'] : $this->agentViewTail,
            agentViewItems: array_key_exists('agentViewItems', $changes) ? $changes['agentViewItems'] : $this->agentViewItems,
            agentViewRows: array_key_exists('agentViewRows', $changes) ? $changes['agentViewRows'] : $this->agentViewRows,
            agentViewName: array_key_exists('agentViewName', $changes) ? $changes['agentViewName'] : $this->agentViewName,
            agentMessages: array_key_exists('agentMessages', $changes) ? $changes['agentMessages'] : $this->agentMessages,
            agentBroadcast: array_key_exists('agentBroadcast', $changes) ? $changes['agentBroadcast'] : $this->agentBroadcast,
            agentViewLeader: array_key_exists('agentViewLeader', $changes) ? $changes['agentViewLeader'] : $this->agentViewLeader,
            agentCancelArmed: array_key_exists('agentCancelArmed', $changes) ? $changes['agentCancelArmed'] : $this->agentCancelArmed,
            agentsStopArmedAt: array_key_exists('agentsStopArmedAt', $changes) ? $changes['agentsStopArmedAt'] : $this->agentsStopArmedAt,
            toolWalkState: array_key_exists('toolWalkState', $changes) ? $changes['toolWalkState'] : $this->toolWalkState,
            agentPaused: array_key_exists('agentPaused', $changes) ? $changes['agentPaused'] : $this->agentPaused,
            paneAutoShow: array_key_exists('paneAutoShow', $changes) ? $changes['paneAutoShow'] : $this->paneAutoShow,
            tabFactory: array_key_exists('tabFactory', $changes) ? $changes['tabFactory'] : $this->tabFactory,
            contextPaneExpanded: array_key_exists('contextPaneExpanded', $changes) ? $changes['contextPaneExpanded'] : $this->contextPaneExpanded,
            contextDrill: array_key_exists('contextDrill', $changes) ? $changes['contextDrill'] : $this->contextDrill,
            deck: array_key_exists('deck', $changes) ? $changes['deck'] : $this->deck,
            pendingTabClose: array_key_exists('pendingTabClose', $changes) ? $changes['pendingTabClose'] : $this->pendingTabClose,
            tabQuitWarned: array_key_exists('tabQuitWarned', $changes) ? $changes['tabQuitWarned'] : $this->tabQuitWarned,
            deckTitle: array_key_exists('deckTitle', $changes) ? $changes['deckTitle'] : $this->deckTitle,
            tabNotified: array_key_exists('tabNotified', $changes) ? $changes['tabNotified'] : $this->tabNotified,
            windowViewports: array_key_exists('windowViewports', $changes) ? $changes['windowViewports'] : $this->windowViewports,
            agentViewByTab: array_key_exists('agentViewByTab', $changes) ? $changes['agentViewByTab'] : $this->agentViewByTab,
            diffReview: array_key_exists('diffReview', $changes) ? $changes['diffReview'] : $this->diffReview,
            messagePicker: array_key_exists('messagePicker', $changes) ? $changes['messagePicker'] : $this->messagePicker,
            firstRunWizard: array_key_exists('firstRunWizard', $changes) ? $changes['firstRunWizard'] : $this->firstRunWizard,
        );
    }
}
