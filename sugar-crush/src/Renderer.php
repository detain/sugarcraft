<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\MouseMode;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mouse\Mark;
use SugarCraft\Mouse\Scanner;
use SugarCraft\Mouse\Sentinel;
use SugarCraft\Fuzzy\Highlighter;
use SugarCraft\Shine\Renderer as Markdown;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Agents\AgentManager;
use SugarCraft\Crush\Commands\KeyBindingRegistry;
use SugarCraft\Crush\Permissions\PermissionPromptStage;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentStatusBar;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\DiffGutter;
use SugarCraft\Crush\Tui\Pane;

/**
 * Pure view function for {@see Chat} — the renderer that actually paints
 * the transcript a real user running `bin/sugarcrush` sees.
 *
 * It is reached two ways, and both are live: `Chat::view()` calls
 * {@see self::render()} for a Chat driven standalone, and the pane shell
 * `bin/sugarcrush` runs today ({@see \SugarCraft\Crush\Cli\Bootstrap::app()}
 * -> {@see \SugarCraft\Crush\App\App::view()} ->
 * {@see \SugarCraft\Crush\Tui\Renderer::renderView()}) delegates its chat
 * pane's BODY back to this class against the hosted `Chat`. So
 * `src/Tui/Renderer.php` is the surrounding chrome/compositor rather than a
 * rival transcript renderer — the "second, parallel renderer nothing ever
 * constructs" this docblock used to describe was merged onto, not deleted.
 * This class still holds no reference to it (see the "R20 wiring decision"
 * note below); the dependency runs one way only.
 *
 * Lays out the conversation scrollback (with each turn rendered through
 * CandyShine) above a fixed input area at the bottom, plus — when the
 * matching {@see Chat} state is present — a session tab strip and an agent
 * status/view section.
 *
 * Rendered shape:
 *
 *   session-a | [session-b] | session-c        ← only when ≥2 sessions exist
 *   ┌─ SugarCrush ───────────────────────┐
 *   │ user> hello                        │
 *   │ assistant: ## Hi there!             │
 *   │            paragraph of markdown    │
 *   │ user> question                     │
 *   │ assistant: …                        │
 *   ├─────────────────────────────────────┤
 *   │ > █                                 │   ← input area
 *   └─────────────────────────────────────┘
 *   Enter to send · Esc / ^C to quit
 *   ● reviewer [working] Reviews code…  0s  0 tok | $0.0000   ← only when
 *   ┌─ agents ────────────────────────────┐                     Chat has an
 *   │ ● reviewer [working]  Reviews code… │                     AgentManager
 *   └──────────────────────────────────────┘                    with active agents
 *
 * The CandyShine renderer is constructed once per call (cheap;
 * just holds a theme reference). Only the assistant's Markdown gets
 * rendered through CandyShine; the raw user/system turns and the
 * in-progress input are run through {@see Sanitize::untrusted()}
 * first (see the render methods for why).
 *
 * ## R20 wiring decision (agent status/view + session tabs)
 *
 * {@see \SugarCraft\Crush\Tui\AgentStatusBar} and
 * {@see \SugarCraft\Crush\Tui\AgentViewPane} already accept plain
 * `list<AgentDisplayState>` + primitives as their render() arguments, NOT
 * an `App` — so option (a) from the R20 brief ("adapt the components to
 * accept the specific Chat-derived data they actually need") was already
 * true for them with zero changes to those two classes. That made it the
 * smaller move versus option (b) (building a throwaway `App::new(...)`
 * adapter here): `App::new()` requires a real `ProviderInterface`, which
 * `Chat` does not hold (it holds the unrelated `Backend` interface), so
 * satisfying that constructor here would mean fabricating a fake provider
 * purely to appease a type signature we don't otherwise need. This class
 * builds `AgentDisplayState` values directly from
 * `Chat::agentManager()->active()` (real `Agent` registrations) instead.
 *
 * ### R20.fix, CLOSED: `agentManager` IS populated in production
 *
 * The rendering below is only reachable when `Chat::agentManager()` is
 * non-null, and until crush_code.md Phase 1 item 1 it never was:
 * `SugarCraft\Crush\Cli\Bootstrap::chat()` — the construction path
 * `bin/sugarcrush` actually runs — passed no `agentManager:` argument, so
 * `renderAgentView()` returned `''` for every real user regardless of
 * config, and the path was exercised only by tests constructing
 * `new Chat(agentManager: ...)` directly. That item added
 * `Bootstrap::agentManager()` (built from `Bootstrap::provider()` +
 * `Bootstrap::skillRegistry()`, the two collaborators this note used to
 * record as unavailable) and passes it in, so the strip below is now
 * REACHABLE — which is not the same as populated. Nothing in `src/` or
 * `bin/` calls `AgentManager::createSubAgent()`/`executeSubAgent()` yet:
 * there is no Task/Agent tool, `Chat::executeAgents()` (the one production
 * route into `AgentManager::executeAll()`) has no caller, and
 * `WorkflowEngine` is never constructed. So the null check no longer stops
 * this code, but a real user still has no way to create the sub-agent that
 * `active()` derives liveness from. What would populate it is crush_code.md
 * #45 (the Task tool that delegates to a registered agent) and #13
 * (constructing `WorkflowEngine`); until one of those lands the strip is
 * exercised only by tests and by an embedder driving the manager directly.
 *
 * What that does NOT mean is a permanent agent strip on every launch:
 * `Bootstrap::agentRoster()` registers its agents INACTIVE, and
 * `AgentManager::active()` promotes one only while it has a live sub-agent.
 * A session where nothing has been delegated still renders `''` here — the
 * same blank frame as before, now for the right reason, and with no live
 * delegation path yet (see above) that is every session. `handleAgentsCommand()`
 * keeps its "not configured" degradation for embedders that construct a
 * `Chat` without a manager.
 *
 * `elapsedSeconds`/`tokensUsed`/`costUsd` have been real per-agent telemetry
 * since W3.S5b (see {@see agentDisplayState()}), and Phase 1 item 1 added the
 * missing fourth: `AgentManager::liveOutput()`/`liveOutputs()`, the public
 * "current live output buffer" accessor this note used to say had to exist
 * before {@see \SugarCraft\Crush\Tui\AgentOutputPane} or the P5.S7/S8
 * split-pane renderer (`self::renderWithSplit()`/`renderForCurrentEnvironment()`
 * on `Tui\Renderer`, meant for laying out *multiple* agents' live output side
 * by side) could be wired to anything but empty tiles.
 * {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane} consumes it
 * already. Neither of those two is wired into `render()` HERE, and that is now
 * a layout decision rather than a missing-data one: this class renders the
 * in-transcript single-column strip, and whether the compositor replaces it is
 * crush_code.md Phase 8 item 4's call.
 * `src/Tui/Components/AgentsPane.php` — also in R20's file list
 * — is untouched by THIS class for a different reason than the one recorded
 * here originally. The `App`-keyed pane system it belongs to is no longer
 * disconnected (see the opening paragraph), but that does NOT mean the shell
 * paints `AgentsPane`: {@see \SugarCraft\Crush\Tui\Renderer::renderView()}
 * diverts `Pane::Agents` to the full-width
 * {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane} before any
 * sidebar is built, so the `Pane::Agents` arm of that class's
 * `rightSidebar()` is never reached on a real frame. `AgentsPane` is an
 * intentionally preserved DORMANT SEAM — the sidebar-sized agents widget,
 * kept as the re-entry point for a future side-by-side layout — not dead
 * code and not to be deleted. `Tui\Renderer::rightSidebar()`'s own docblock
 * records the same thing from the other side; the two are meant to agree.
 *
 * Do not "confirm" the opposite by eye: `AgentDashboardPane` delegates to
 * {@see \SugarCraft\Crush\Tui\AgentViewPane}, which draws an identically
 * worded `' agents '` border title and `(no active agents)` empty state. On
 * a 120-column terminal the box actually in the frame is 120 wide and the
 * 34-column `AgentsPane` block appears nowhere in it — width, not wording,
 * is what tells the two apart.
 *
 * `Tui\SessionTabs` is not instantiated here either: its constructor always
 * seeds one synthetic "main" tab when started empty, a shape built for a
 * fresh single-session boot rather than for hydrating N pre-existing rows
 * from a `SessionStore`. Retrofitting that would mean changing
 * `SessionTabs.php` itself (not in this item's file scope) or fabricating
 * and discarding a placeholder tab. Its real, tested key surface
 * (`CTRL_TAB`/`CTRL_SHIFT_TAB`, `cycleForward()`/`cycleBackward()`'s
 * wraparound semantics) is instead the design this renderer's tab strip and
 * {@see Chat}'s Ctrl+Tab handling both follow directly against
 * `SessionStore::listSessions()`'s real, persisted row order — see
 * `Chat::cycleSessionTab()`'s docblock for the matching switching half.
 *
 * ### Session rows are created in production (was: "no production code path
 * ever calls `createSession()`")
 *
 * `renderSessionTabStrip()` reads real rows from
 * {@see \SugarCraft\Crush\Session\SessionStore::listSessions()}, and since
 * 737da6413 (W3.S1) those rows exist on a real run:
 * `Cli\Bootstrap::seedSession()` resumes the most recent session or creates
 * one before `Bootstrap::chat()` constructs the Chat, and the Ctrl+P
 * palette's "New session" (`Chat::handlePaletteNewSession()`, which really
 * does call `createSession()`) and `/branch` add more. The note that used
 * to sit here — claiming nothing in `src/` or `bin/` ever called
 * `createSession()`, so `listSessions()` returned `[]` for the whole
 * process lifetime — was written four days before that commit and is simply
 * no longer true.
 *
 * `count($rows) < 2` still suppresses the strip, and that is the intended
 * shape rather than the leftover of the old gap: a boot seeds ONE row, and
 * a single-session strip would be chrome that never changes. The strip
 * appears the moment a second session exists — the same threshold
 * {@see Chat::cycleSessionTab()} switches at, so the strip and the Ctrl+Tab
 * binding that moves through it become useful together.
 */
final class Renderer
{
    /** Maximum rows AgentViewPane renders before clipping (see AgentViewPane::render()). */
    private const AGENT_VIEW_MAX_ROWS = 10;

    /**
     * Maximum diff rows {@see renderDiff()} paints before it clips and prints
     * an "N more lines" trailer. A single Edit can rewrite hundreds of lines;
     * without a cap the diff alone would fill the viewport and evict the whole
     * transcript once {@see render()}'s tail-clipping runs.
     */
    private const DIFF_MAX_ROWS = 24;

    /**
     * Columns of diff text {@see renderDiff()} insists on keeping before it
     * will spend any on {@see DiffGutter}'s line numbers. Below this the gutter
     * would be eating a third of a narrow viewport to annotate rows that have
     * been truncated back to their `+`/`-` marker.
     */
    private const DIFF_MIN_BODY_COLS = 24;

    /**
     * Columns the shell's border + padding(1, 2) consume, subtracted before
     * anything inside it is truncated to width.
     */
    private const SHELL_CHROME_COLS = 6;

    /**
     * Narrowest tail {@see toolCallSuffix()} will draw. Below this the row is
     * left as-is: four columns of an elided command tells the user nothing
     * and only costs the status marker its breathing room.
     */
    private const TOOL_DESCRIPTION_MIN_COLS = 8;

    /**
     * Cell width a tool-result image ({@see renderToolImage()}) is scaled to
     * before its height is derived from the source aspect ratio. Literally
     * crush_feat.md §9 E3's `$w = 40`: wide enough for a screenshot to be
     * legible, narrow enough that it still fits inside the chat shell on an
     * 80-column terminal without pushing the transcript off-screen.
     */
    private const IMAGE_COLS = 40;

    /**
     * Distinct encoded pictures {@see $imageCache} keeps before it starts
     * evicting the least recently used one. A handful is enough to cover every
     * image still on screen (the transcript is tail-clipped to one viewport)
     * while keeping the retained blob bytes bounded in a session that scrolls
     * hundreds of screenshots past.
     */
    private const IMAGE_CACHE_MAX = 8;

    /**
     * Encoded pictures, keyed by source bytes + cell box + protocol, in
     * least-recently-used-first order.
     *
     * `Program::renderFrame()` calls `Chat::view()` on EVERY dirty frame - each
     * keystroke, each streaming chunk, each spinner tick - so without this an
     * image-bearing tool result would re-decode its bytes through ext-gd and
     * re-encode the picture on every one of those frames, for every image still
     * in the transcript. That is single-digit milliseconds for half-block but
     * hundreds of milliseconds for Sixel, i.e. exactly the pixel-graphics
     * protocols this feature exists to enable would be the ones that make the
     * TUI unusable. The output is a pure function of the key, so memoizing it
     * is safe.
     *
     * @var array<string, array{ok: bool, body: string}>
     */
    private static array $imageCache = [];

    /**
     * Inner width of the permission modal, in cells. Wider than the palette's
     * 50 because a prompt body is prose (a hook's question plus the tool
     * call's own arguments), not a list of short command labels.
     */
    private const PERMISSION_MODAL_COLS = 60;

    /**
     * Rows of the hook's question {@see renderPermissionPrompt()} paints
     * before it clips. A hook is free to hand back an arbitrarily long
     * message (it can quote the whole command it objected to); an unbounded
     * modal would grow past the viewport and push its own answer keys
     * off-screen, leaving the user blocked on a prompt whose options they
     * cannot see.
     */
    private const PERMISSION_PROMPT_MAX_ROWS = 8;

    /**
     * The answer keys {@see renderPermissionPrompt()} advertises while the
     * prompt is ARMED, as `[keys, label]` pairs.
     *
     * Deliberately a table rather than a formatted string literal: it has to
     * stay in lockstep with `Chat::handlePermissionKey()`'s arms, and a list of
     * the exact accepted keys is far easier to check against those arms than
     * prose is. Only keys that DO something in this stage are listed - every
     * other key disarms the prompt rather than answering it, so advertising it
     * would promise an answer that never arrives.
     *
     * `a` is labelled as a request rather than as the grant it used to be:
     * pressing it raises {@see PERMISSION_CONFIRM_OPTIONS}, and a label that
     * still read "allow always" would make the confirm look like a bug.
     */
    private const PERMISSION_OPTIONS = [
        ['y', 'allow once'],
        ['a', 'allow always (this session) — asks first'],
        ['n / Esc', 'reject'],
    ];

    /**
     * The confirm's own keys, shown instead of {@see PERMISSION_OPTIONS} while
     * `a` is waiting on its second keystroke
     * ({@see \SugarCraft\Crush\Permissions\PermissionPromptStage::ConfirmingAlways}).
     *
     * A separate table because in this stage the same letters mean different
     * things - `y` is not "allow once" here, it is the session grant - and one
     * table with stage-dependent labels is exactly the drift this pair of
     * constants exists to prevent.
     */
    private const PERMISSION_CONFIRM_OPTIONS = [
        ['y', 'yes — every later call to this tool, this session'],
        ['n / Esc', 'no — back to the question'],
    ];

    /**
     * What a DISARMED prompt advertises: the two keys that still do something,
     * and by omission the fact that the answer letters do not.
     *
     * A prompt that silently ate keys would be a worse defect than the one the
     * arm rule closes, so the state is on screen rather than only in the model
     * ({@see PERMISSION_DISARMED_NOTICE} carries the "why nothing is
     * happening" half).
     */
    private const PERMISSION_DISARMED_OPTIONS = [
        ['Enter', 'listen for an answer again'],
        ['Esc', 'reject (always live)'],
    ];

    /**
     * The line that tells a user their keystrokes are going nowhere, painted
     * above {@see PERMISSION_DISARMED_OPTIONS}.
     *
     * Public so a test can assert the cue by the same string the renderer
     * paints instead of by a hand-copied literal that can drift out from under
     * it - the treatment {@see KEY_HELP_OVER_PROMPT} already gets.
     */
    public const PERMISSION_DISARMED_NOTICE = 'keys ignored — this prompt is no longer listening';

    /**
     * Rows/characters {@see collapseToolOutput()} keeps of a tool body before
     * it clips and prints an overflow trailer (crush_feat.md §1 E5). Both
     * limits matter independently: a `Grep` result can be 400 short lines
     * (blows the line budget) and a `Bash` result can be one 200KB line
     * (blows the character budget while still being "1 line").
     */
    private const TOOL_OUTPUT_MAX_LINES = 10;

    private const TOOL_OUTPUT_MAX_CHARS = 2000;

    /**
     * Zone-id prefix every session tab carries (crush_feat.md §8 E2). Public
     * because {@see Chat::update()} parses it back off a click's zone id —
     * one literal, defined next to the code that writes it, rather than the
     * same string spelled out independently on both sides of the hit test.
     */
    public const SESSION_TAB_ZONE_PREFIX = 'tab:';

    /**
     * Zone-id prefix every clickable pane region carries (crush_feat.md §8
     * E3). The suffix is always a {@see Pane} case's own `value`, so
     * {@see Chat::update()} can turn a click straight back into the enum
     * case rather than matching hand-spelled strings on both sides.
     */
    public const PANE_ZONE_PREFIX = 'pane:';

    /**
     * Zone-id prefix every clickable command-palette / picker row carries
     * (crush_feat.md §8 E6, which names `picker-item:{$index}` literally).
     * The suffix is the row's index into {@see Chat::paletteMatches()}, i.e.
     * exactly what {@see \SugarCraft\Crush\Palette\PaletteState::$selectedIndex}
     * holds, so a click can move the selection and then run the SAME confirm
     * path Enter runs instead of a click-only duplicate of it.
     */
    public const PALETTE_ITEM_ZONE_PREFIX = 'picker-item:';

    /**
     * Zone-id prefix every clickable tool-call row carries (crush_feat.md §8
     * E5). The suffix is the SAME key {@see Chat::expanded()} is keyed by
     * (`ToolResult::$id`, falling back to its name), so a click can be handed
     * straight to {@see Chat::toggleToolOutput()} — §8 E5's note is explicit
     * that this reuses §1 E5's expanded map rather than introducing a second
     * expansion mechanism.
     */
    public const TOOL_CALL_ZONE_PREFIX = 'toolcall:';

    /**
     * The zone-id charset {@see Mark::wrap()} accepts, duplicated here
     * because Mark's own copy is private and it THROWS on a violation.
     * Session ids arrive from disk (`SessionStore::listSessions()`), so an id
     * carrying anything outside this set would take the whole TUI down from
     * inside `view()`; {@see markSessionTab()} checks first and renders that
     * tab unmarked (still visible, still keyboard-switchable) instead.
     */
    private const ZONE_ID_CHARSET = '/\A[A-Za-z0-9._:-]+\z/';

    /**
     * Zone registry the root scan pass writes into, shared across renders
     * because a mouse event arrives *between* frames: the click handler has
     * only the previously-painted frame's boxes to hit-test against.
     * Mirrors bubblezone's single global manager.
     */
    private static ?Scanner $scanner = null;

    /**
     * Zone registry produced by the most recent {@see scanRoot()} pass.
     * Hit-tested by {@see Chat::zoneAt()}.
     */
    public static function scanner(): Scanner
    {
        return self::$scanner ??= Scanner::new();
    }

    /**
     * Where the scanned frame's top-left cell sits on the TERMINAL, as
     * `[col, row]` deltas (0-based; `[0, 0]` when this renderer's frame IS
     * the root frame).
     *
     * {@see scanRoot()} records zones in the coordinate space of the string it
     * scanned, and when a shell hosts this renderer that string is a SUB-frame:
     * {@see \SugarCraft\Crush\Tui\Components\ChatPane} draws it inside a box and
     * {@see \SugarCraft\Crush\Tui\Renderer} composes that box behind a menu bar
     * and beside a sidebar. `MouseMsg` x/y stay terminal-absolute, so without
     * this delta every hosted click hit-tests against the wrong cell — clicks on
     * real targets do nothing and clicks on blank chrome fire actions the user
     * never asked for.
     *
     * Applied at hit-test time ({@see Chat::zoneAt()}) rather than baked into
     * the recorded boxes because the shell only knows the final delta AFTER it
     * has composed the frame — the frame it composes from this renderer's
     * output. That makes it every mouse consumer's job to rebase, not just the
     * hit-test's: `Chat` also translates the event it hands
     * {@see \SugarCraft\Mouse\ZoneClickTracker}, which pairs a release with its
     * press by re-testing the recorded box against that event.
     *
     * @var array{0: int, 1: int}
     */
    private static array $zoneOrigin = [0, 0];

    /**
     * Declare where the frame {@see scanRoot()} just scanned ended up on the
     * terminal, in 0-based column/row deltas.
     *
     * Called once per frame by the shell compositor, after the composite is
     * final. {@see scanRoot()} resets the origin to `[0, 0]` at the start of
     * every scan, so a standalone `Chat` — and a frame the shell stops hosting
     * — can never inherit a stale offset.
     */
    public static function setZoneOrigin(int $col, int $row): void
    {
        self::$zoneOrigin = [$col, $row];
    }

    /** @return array{0: int, 1: int} @see setZoneOrigin() */
    public static function zoneOrigin(): array
    {
        return self::$zoneOrigin;
    }

    /**
     * Drop the click-zone registry and reset the origin.
     *
     * For a shell frame that does NOT contain this renderer's output at all —
     * the full-pane agent dashboard (crush_feat.md §5 E5) is the first one.
     * {@see scanRoot()} only clears the registry when it runs, so a frame that
     * never calls it would leave the PREVIOUS frame's boxes hit-testable
     * underneath content that never drew them, and a click would fire whatever
     * action last occupied that cell.
     */
    public static function clearZones(): void
    {
        self::scanner()->clear();
        self::$zoneOrigin = [0, 0];
    }

    /**
     * Tool-call rows the current frame wants clickable, collected while the
     * transcript is built and consumed once the shell around it is drawn.
     *
     * This detour exists because the markers are NOT free to the layout.
     * `Style::render()` measures every line to size the shell's border and
     * pad the short ones, and a sentinel pair is ~29 codepoints of
     * Private-Use text it happily counts as content: marking the label
     * in-place (the way {@see markSessionTab()} can, being outside any box)
     * widens the whole box by the marker length and leaves the marked row's
     * right border ~29 columns short of every other row. Marking the row
     * AFTER it has been measured and padded costs nothing and is what
     * bubblezone recommends for width-sensitive containers.
     *
     * @var list<array{id: string, label: string}> in document order
     */
    private static array $toolCallZones = [];

    /**
     * Palette rows the current frame wants clickable, as the FULLY rendered
     * box lines they became (border + padding + row), collected by
     * {@see renderPalette()} and consumed by {@see markPaletteItems()} once
     * the palette has been composited over the frame.
     *
     * The detour exists because the palette is an OVERLAY, and {@see Veil}
     * measures it: `composite()` takes the widest foreground line as the
     * box's width and centres by it, then clips each line to the room left
     * on its row. A sentinel pair measures ~29 columns of Private-Use text,
     * so a row marked before compositing makes Veil believe the box is 29
     * columns wider than it is — the whole palette shifts ~15 columns left
     * and every row is clipped short. Marking after the composite costs
     * nothing and leaves the geometry Veil computed untouched.
     *
     * @var list<array{id: string, line: string}> in row order
     */
    private static array $paletteItemZones = [];

    /**
     * How many content lines the most recent frame had to drop off the top
     * to fit the terminal — i.e. the largest {@see Chat::scrollOffset()}
     * that still shows a full screen of transcript.
     *
     * Static for the same reason {@see $scanner} is: a wheel event arrives
     * *between* frames, so the only content height it can be clamped
     * against is the one already painted. {@see Chat} is immutable and
     * would discard a per-instance copy of it anyway.
     */
    private static int $maxScrollOffset = 0;

    /**
     * Upper clamp for {@see Chat::scrollOffset()}, measured on the last
     * rendered frame. 0 when the transcript fits the window (nothing to
     * scroll) or when nothing has been rendered yet.
     */
    public static function maxScrollOffset(): int
    {
        return self::$maxScrollOffset;
    }

    /**
     * Content width the keybinding reference aims for, before its own border
     * and padding — see {@see renderKeyHelp()}. Chosen to hold the widest
     * declared row ({@see KeyBindingRegistry}'s key column plus its longest
     * description) without truncation on any terminal wide enough for it:
     * measured at 58 columns (a 14-column key field for `Ctrl+Shift+Tab`, one
     * space, and `Send, or accept the highlighted "/" command`).
     * `KeyHelpTest::testScrollingThroughItShowsEveryLiveBinding()` is what
     * keeps that headroom honest — it asserts every description is painted in
     * FULL, so a row grown past this width fails rather than being clipped.
     */
    private const KEY_HELP_COLS = 64;

    /**
     * The keybinding reference box's own chrome, in columns: 2 border columns
     * plus the 2 of `padding(0, 1)`. Taken off the terminal width before
     * {@see Style::width()} is given a content width, which the box then grows
     * past by exactly this much.
     *
     * NOT {@see SHELL_CHROME_COLS}, whose 6 is the same arithmetic for a box
     * with `padding(1, 2)` ({@see renderPermissionPrompt()}). Two boxes, two
     * paddings, two numbers — sharing one would make the reference 2 columns
     * narrower than it can afford, and would break the moment either box's
     * padding changed.
     */
    private const KEY_HELP_CHROME_COLS = 4;

    /**
     * What the status bar says when the keybinding reference is open but the
     * window is too small to paint it ({@see keyHelpGeometry()} returns null).
     *
     * The whole bar, not an addition to it: the readouts it replaces are about
     * a chat the reference is currently swallowing every keystroke for, and the
     * bar is the one line that may not wrap — so this must be SHORTER than the
     * text it stands in for, not appended to it.
     */
    private const KEY_HELP_TOO_SMALL = 'keys: window too small · ? closes';

    /**
     * What the status bar says when the keybinding reference is open OVER a
     * blocking permission prompt — the one overlay it outranks that is itself
     * waiting on the user.
     *
     * Same reasoning as {@see KEY_HELP_TOO_SMALL}, applied to the same hazard:
     * the reference wins both the slot and the keyboard (see
     * {@see renderView()}'s chain), so the prompt is invisible AND its `y`/`n`/
     * `a` do nothing while the turn stays `inFlight`. A modal that is invisible
     * and silent is a stuck terminal as far as the user can tell, so the bar
     * says the prompt is there and `?` is what gets to it.
     *
     * The whole bar, and SHORTER than what it replaces: measured with
     * `Width::of`, the bar this stands in for is never narrower than 36 columns
     * while a prompt pends (`0% · ⠴ thinking… · Esc Esc to cancel` — 2 + 3 + 31,
     * and `contextIndicator()`'s last resort `"{$percent}%"` can never be empty,
     * so 2 really is its floor), against this cue's 35. The bar is the one line
     * this renderer never truncates, so that 1-column margin is load-bearing.
     * Swept and asserted, over app states rather than terminal sizes alone, by
     * `KeyHelpTest::testTheCuesFitTheNarrowestBarAnyAppStateCanProduce()` — which
     * exists because {@see renderStatusBar()}'s comment carried 54 here, the
     * IDLE floor, for a round while this docblock had 36 right.
     *
     * Both figures are COLUMN counts, not byte counts: `strlen` reads this cue
     * as 36 and would sit exactly on the boundary, reporting a margin that is
     * not there.
     *
     * NOT run through `Lang::t()`, and deliberately noted rather than fixed:
     * `grep -rl 'Lang::t' src/` finds no PHP file in this lib, so hardcoded
     * English is a lib-wide deviation from the project rule rather than one
     * introduced here, and starting an i18n migration from one status-bar string
     * is not the shape of that fix. But the 1-column margin above is measured
     * against THIS literal. The day sugar-crush adopts `Lang::t()`, a translated
     * cue can exceed the bar it replaces at translation time, where no PHP test
     * sees it — so the bound must then be asserted against the RENDERED string
     * rather than reasoned about here.
     */
    private const KEY_HELP_OVER_PROMPT = 'keys: ? closes · permission waiting';

    /**
     * How far the LAST rendered keybinding reference overflowed its box,
     * in lines. Static for the same reason {@see $maxScrollOffset} is: the
     * keystroke that scrolls arrives between frames, so the only content
     * height it can be clamped against is the one already painted.
     *
     * Process-global and read by production code ({@see Chat::withKeyHelp()}
     * clamps against it), which is the shape of the three statics this feature's
     * review round had to add resets for — and it needs no reset seam, for a
     * reason that is a property rather than a habit: {@see renderKeyHelp()} runs
     * FIRST in {@see renderView()}'s overlay chain, so it runs on every frame
     * THIS renderer paints, and its two early returns (reference closed, or box
     * does not fit) both zero this before returning ''. One such frame therefore
     * clears it, which is asserted rather than assumed, by
     * `KeyHelpTest::testTheOverflowCeilingResetsItselfOnAFrameWithoutTheReference()`.
     *
     * "Every frame this renderer paints" is the honest bound, not "every frame":
     * under the pane shell, `Pane::Agents` builds a bespoke frame that never
     * enters this chain (`Tui\Renderer::renderAgentDashboard()`), so painted
     * frames exist that leave the ceiling alone. The conclusion survives on a
     * second guarantee rather than on this one — nothing in that pane can reach a
     * `withKeyHelp()` call at all. Swept, rather than argued: over the 95
     * printable runes x Ctrl on/off plus the nine named keys x Ctrl on/off,
     * `KeyboardHandler::claims()` in `Pane::Agents` lets exactly SIX chords
     * through to `Chat` — `Ctrl+A`, `Ctrl+C`, `Ctrl+O`, `Ctrl+P`, `Ctrl+W`,
     * `Ctrl+Tab` — and none of the six is a `withKeyHelp()` caller: they dispatch
     * `/agents`, quit, toggle a tool's output, open the palette, delete a word
     * and cycle sessions. Both routes to the reference are claimed by the shell
     * there: `?` (unmodified, so rule 2 takes it) and `Enter`, without which
     * `submit()`'s `/keys` arm is unreachable too. And {@see renderKeyHelp()}
     * re-clamps `$start` against a freshly measured ceiling regardless.
     *
     * A stale value can therefore only be READ between a frame that painted the
     * reference and a `withKeyHelp()` call with no frame in between.
     */
    private static int $keyHelpMaxOffset = 0;

    /**
     * Upper clamp for {@see Chat::keyHelp()}, measured on the last rendered
     * reference. 0 when the whole list fits, or when it has never been drawn.
     */
    public static function keyHelpMaxOffset(): int
    {
        return self::$keyHelpMaxOffset;
    }

    /**
     * The reference box's `[contentWidth, boxRows]` for this terminal, or null
     * when it does not fit and must not be drawn at all.
     *
     * Neither bound has a floor, and that is deliberate in both directions:
     *
     * - width: {@see Style::width()} sets the CONTENT width and the box grows
     *   past it by {@see KEY_HELP_CHROME_COLS}. A floor would produce a
     *   five-column box on a four-column terminal, and one column over is
     *   still an over-wide frame line — the absolute-cursorTo row collision
     *   {@see renderView()}'s tail clip exists to prevent.
     * - height: the box may never be taller than `rows - 2`. Veil composites
     *   the overlay without truncating the row underneath, and the status bar
     *   on the last row is deliberately not width-truncated
     *   ({@see renderStatusBar()}) — so a box that reached that row would cover
     *   its first `$width` columns and leave the rest of the bar hanging past
     *   the box's right edge. A floor that let the box grow into those rows on
     *   a very short terminal is exactly what once made that happen.
     *
     * So a terminal under 5 columns or under 5 rows gets no box. Read by
     * {@see renderKeyHelp()} to size it and by {@see renderStatusBar()} to
     * decide whether the reference needs announcing in words instead.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function keyHelpGeometry(Chat $chat): ?array
    {
        $width = min(self::KEY_HELP_COLS, $chat->cols() - self::KEY_HELP_CHROME_COLS);
        $boxRows = $chat->rows() - 2;

        return $width < 1 || $boxRows < 3 ? null : [$width, $boxRows];
    }

    /**
     * The zone-scan pass, run exactly ONCE per frame and only at the root.
     *
     * candy-mouse (like bubblezone) records absolute bounding boxes as it
     * walks the string, so scanning a sub-widget's output would register
     * boxes relative to that widget's own origin rather than the terminal's
     * — every nested scan would have to be thrown away and redone here
     * anyway. Scanning after the last compositing step (including the
     * palette overlay) is therefore both the cheapest and the only correct
     * placement.
     *
     * "Root" is this renderer's own frame, which is the terminal's root only
     * while `Chat` runs standalone. When the pane shell hosts it the frame is
     * a sub-frame, and re-scanning the composed shell frame instead is not an
     * option: the markers are ~29 Private-Use cells that
     * `Style::render()`/`Layout::joinHorizontal()`/{@see
     * \SugarCraft\Crush\Tui\Renderer} would all measure as content, breaking
     * the pane box the same way marking a tool row in place breaks it (see
     * {@see $toolCallZones}). So the scan stays here and the shell declares
     * the sub-frame's terminal position through {@see setZoneOrigin()}, which
     * {@see Chat::zoneAt()} subtracts before hit-testing. The origin is reset
     * here, on every frame, so the standalone path is always `[0, 0]`.
     *
     * The scan is skipped outright when `SUGARCRUSH_DISABLE_MOUSE` is set —
     * with tracking off no mouse coordinates ever arrive, so keeping a stale
     * zone registry around would be pure waste. Sentinel *stripping* is
     * unconditional: the markers are Private-Use codepoints a terminal would
     * paint as a replacement glyph, and candy-core's line diff counts them
     * as content.
     *
     * Marker-free frames take a `str_contains()` fast path (~0.0004ms) and
     * skip both the parse and the strip. That is not a micro-optimisation:
     * {@see \SugarCraft\Mouse\Scan::parse()} walks the frame cluster by
     * cluster through `grapheme_extract()`, which measures ~24ms on a
     * full-screen frame — roughly doubling the cost of a keystroke repaint.
     * {@see markSessionTab()} is currently the only caller of
     * {@see \SugarCraft\Mouse\Mark::zone()}, and it only fires when the
     * session tab strip is drawn at all (≥2 sessions on disk), so a
     * single-session run still takes this branch on every frame.
     *
     * The scan is also non-fatal. `Scan::parse()` throws on malformed markup
     * (duplicate/unclosed ids), and this runs inside `Chat::view()`, where an
     * escaping exception kills the whole TUI. Degrading to "no zones this
     * frame" costs at most an unclickable frame; the alternative is a crash.
     * Untrusted text is sentinel-stripped on the way in (see
     * {@see untrusted()}), so a throw here means OUR markup is wrong, not
     * that a model injected something.
     *
     * @param string $frame The fully-composited root frame.
     * @param int    $width Viewport width; zone end columns clamp to it.
     */
    public static function scanRoot(string $frame, int $width): string
    {
        // Every recorded box below is relative to THIS frame; a host that
        // composes it elsewhere re-declares the offset after compositing.
        self::$zoneOrigin = [0, 0];

        if (!str_contains($frame, Sentinel::OPEN) && !str_contains($frame, Sentinel::CLOSE)) {
            self::scanner()->clear();

            return $frame;
        }

        if (Chat::mouseMode() === MouseMode::Off) {
            self::scanner()->clear();
        } else {
            try {
                self::scanner()->scan(self::maskImageMarkers($frame), $width);
            } catch (\Throwable) {
                self::scanner()->clear();
            }
        }

        return self::stripZoneMarkers($frame);
    }

    /**
     * Blank every Private-Use cell that is NOT part of a well-formed zone
     * sentinel, for the scan copy only.
     *
     * The image overlay and the zone marker landed on the same codepoints:
     * `ImageOverlay::MARKER_BASE` is U+E000 and an image's marker cell is
     * `MARKER_BASE + id`, so the first picture {@see renderToolImage()} places
     * in a frame emits a byte-identical copy of {@see Sentinel::OPEN} and the
     * second emits {@see Sentinel::CLOSE}. Handed to the scanner verbatim,
     * those stray sentinels parse as zone markup: measured, one image marker
     * sitting between two marked regions makes the scan drop every zone after
     * it — session tabs, tool rows, and the status bar's `pane:menu` all stop
     * responding to clicks the moment a screenshot is on screen.
     *
     * Only the string the scanner reads is masked; the frame that goes to the
     * terminal keeps its real markers, because `Program` still has to resolve
     * them into paints. A marker is a single width-1 cell and is replaced by a
     * single space, so every zone's column arithmetic is unchanged — which is
     * the whole reason this is a mask rather than a strip.
     *
     * Sentinel triples are matched first in the alternation so genuine markup
     * survives; anything else in the PUA block (an image marker, or a Nerd
     * Font glyph in model output) becomes a space. A frame carrying invalid
     * UTF-8 makes the `/u` match fail, and the unmasked frame is scanned
     * instead — the pre-existing behaviour, and the caller already treats a
     * throw from the scan as "no zones this frame".
     */
    private static function maskImageMarkers(string $frame): string
    {
        $masked = preg_replace_callback(
            '/(\x{E000}\/?[A-Za-z0-9._:-]*\x{E001})|[\x{E000}-\x{F8FF}]/u',
            static fn(array $m): string => ($m[1] ?? '') !== '' ? $m[1] : ' ',
            $frame,
        );

        return $masked ?? $frame;
    }

    /**
     * {@see Sanitize::untrusted()} plus zone-sentinel removal, for every
     * string that originated outside this process (model replies, tool
     * output, pasted keystrokes).
     *
     * The sentinel strip is the security half. `Sanitize::untrusted()` only
     * removes ANSI/C0/C1/DEL; `U+E000`/`U+E001` are well-formed 3-byte UTF-8
     * Private-Use codepoints, so they survive it untouched and would reach
     * {@see scanRoot()}'s parser verbatim. A model reply — or any tool output
     * echoed into a message: a file read, a web fetch, a shell command run in
     * a hostile repo — could then either crash the render (duplicate ids make
     * `Scan::parse()` throw) or, worse, register attacker-chosen boxes in the
     * hit-test registry {@see Chat::zoneAt()} reads, hijacking clicks meant
     * for real UI. Stripping at the boundary keeps the invariant that only
     * {@see \SugarCraft\Mouse\Mark}-emitted markers ever reach the scan.
     */
    private static function untrusted(string $text): string
    {
        return self::stripSentinels(Sanitize::untrusted($text));
    }

    /**
     * Remove bare zone sentinels, for content that must NOT go through
     * {@see untrusted()} — assistant Markdown, which CandyShine renders into
     * legitimate SGR that `Sanitize::untrusted()` would strip back out.
     */
    private static function stripSentinels(string $text): string
    {
        return str_replace([Sentinel::OPEN, Sentinel::CLOSE], '', $text);
    }

    /**
     * Remove every `U+E000 <id> U+E001` open/close sentinel pair emitted by
     * {@see \SugarCraft\Mouse\Mark}. Matched byte-wise against Mark's own id
     * charset (plus the closing `/`) so a frame carrying invalid UTF-8 can
     * never make the pattern fail open and leak markers to the terminal.
     */
    private static function stripZoneMarkers(string $frame): string
    {
        return (string) preg_replace(
            '/\xEE\x80\x80\/?[A-Za-z0-9._:-]*\xEE\x80\x81/',
            '',
            $frame
        );
    }

    /**
     * The frame's text bytes only, discarding any pixel-graphics image layer
     * {@see renderView()} collected.
     *
     * Kept as the entry point for every caller that only wants the literal
     * frame (tests, and anything composing the frame into something else):
     * an image layer is meaningless without a {@see \SugarCraft\Core\Program}
     * to paint it, and a plain string is what candy-core's `Model::view()`
     * contract calls the simple case.
     */
    public static function render(Chat $chat): string
    {
        return self::renderView($chat)->body;
    }

    /**
     * The full frame plus the pixel-graphics layer for any image-bearing tool
     * result in the transcript (crush_feat.md §9 E3).
     *
     * Sixel/Kitty/iTerm2 blobs are not text and cannot be diffed by
     * candy-core's line renderer, so — exactly as `sugar-gallery`'s
     * `PosterCard` does — each blob is registered with a per-frame
     * {@see ImageLayer}, which hands back a marker block to sit in the text
     * frame, and the collected {@see ImageLayer::placements()} ride out on the
     * {@see View}. `Program::renderFrame()` resolves those markers to screen
     * positions and paints the blobs on top of the text frame, so nothing
     * beyond returning them is needed here. A fresh layer per call is required:
     * ids are positional to THIS frame, and a reused layer would keep painting
     * images whose markers have since scrolled out of the transcript.
     */
    public static function renderView(Chat $chat): View
    {
        $theme = $chat->theme();
        $images = new ImageLayer();
        // Both per-frame registries are cleared before the transcript is walked:
        // their entries are positional to the frame being built, so a leftover
        // row from the previous frame would be marked at the wrong place (or
        // registered twice, which makes the zone scan throw).
        self::$toolCallZones = [];
        self::$paletteItemZones = [];
        $body = self::renderHistory(
            $chat->history,
            $theme,
            max(20, $chat->cols() - self::SHELL_CHROME_COLS),
            $chat->expanded(),
            $images,
            $chat->mosaic(),
            // Row budget for a single picture: anything taller is clipped off
            // the frame's tail anyway, so encoding it would be pure waste.
            max(1, $chat->rows() - 2),
        );
        if ($chat->inFlight) {
            // The reply so far, when the model has started writing one
            // (crush_code.md Phase 0 item 13). It sits ABOVE the spinner
            // rather than replacing it: the turn is still running - more text
            // is coming, and possibly tool calls after it - so removing the
            // only "still working" affordance the moment the first token
            // landed would make a mid-stream pause look like a finished
            // answer.
            //
            // Rendered here rather than pushed into $chat->history because a
            // half-written reply is not a turn: see Chat::$streamingText for
            // why it must not be checkpointed, compacted or re-sent.
            $partial = $chat->streamingText();
            if ($partial !== '') {
                $stream = self::renderStreamingTurn($partial, $theme);
                $body = $body === '' ? $stream : $body . "\n\n" . $stream;
            }
            // Visible in the chat window itself, not just the status bar -
            // a spinner-only status line is easy to miss; this sits right
            // where the reply is about to appear.
            $thinking = Style::new()->foreground($theme->assistantLabel)->faint()->render('⠴ assistant is thinking…');
            $body = $body === '' ? $thinking : $body . "\n\n" . $thinking;
        }
        $input = self::renderInput($chat, $theme);
        $slashMenu = self::renderSlashMenu($chat, $theme);

        $shell = Style::new()
            ->border(Border::rounded())
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->render($body);
        $shell = self::markToolCalls($shell);

        $content = $shell . "\n" . $input . ($slashMenu !== '' ? "\n" . $slashMenu : '');

        $tabStrip = self::renderSessionTabStrip($chat);
        if ($tabStrip !== '') {
            $content = $tabStrip . "\n" . $content;
        }

        $agentView = self::renderAgentView($chat);
        if ($agentView !== '') {
            $content .= "\n" . $agentView;
        }

        // Full-window usage: fit the frame to exactly $rows lines, always.
        // candy-core's Renderer repaints changed rows via an ABSOLUTE
        // cursorTo($row, 1) - it has no concept of scrolling. If $content
        // is ever taller than the real terminal, every cursorTo() past the
        // terminal's last row gets silently clamped there by the terminal
        // itself, so distinct logical rows (input box, status bar, the
        // newest history lines) all collide on that one physical row -
        // which is exactly what "text/cursor ends up in the status bar"
        // looks like once a conversation grows past one screen. Clipping
        // to the tail keeps the input box (the last part of $content)
        // and the newest history visible, scrolling older turns off the
        // top - the same tradeoff any fixed-viewport TUI makes. Short
        // conversations still get padded so the status bar lands on the
        // true last line instead of leaving most of the window blank.
        //
        // $chat->rows() (sourced from WindowSizeMsg - the size candy-core's
        // Program actually dispatches, live, on every resize) is the
        // authoritative value here - NOT a second, independent
        // TuiRenderer::getTerminalSize() query. That second query has its
        // own statically-cached, never-invalidated detection of the SAME
        // terminal that can silently disagree with what Program itself
        // knows (and never learns about a live resize either), which
        // reintroduces the exact row-collision this clipping is meant to
        // prevent even after clipping was added.
        //
        // Which $available-line window of $content is shown is the one thing
        // the tail clip above leaves to the user: $chat->scrollOffset() is a
        // distance in lines from the BOTTOM (0 = pinned to the newest line,
        // the historical behaviour), so scrolling back moves the window's
        // start earlier by exactly that many lines (crush_feat.md §8 E4).
        // It is re-clamped against THIS frame's overflow rather than trusted:
        // the offset was clamped against the frame that was on screen when
        // the wheel turned, and the transcript can have shrunk since (/clear,
        // a session switch, a resize), which would otherwise slice past the
        // start of the content and show a short frame.
        $rows = $chat->rows();
        $available = max(1, $rows - 1);
        $contentLines = explode("\n", $content);
        $overflow = max(0, count($contentLines) - $available);
        self::$maxScrollOffset = $overflow;
        if ($overflow > 0) {
            $offset = max(0, min($chat->scrollOffset(), $overflow));
            $contentLines = array_slice($contentLines, $overflow - $offset, $available);
        } else {
            while (count($contentLines) < $available) {
                $contentLines[] = '';
            }
        }

        $frame = implode("\n", $contentLines) . "\n" . self::renderStatusBar($chat);

        // ONE rule orders this chain: the overlay on screen must be the one
        // Chat::update() routes the next keystroke to, because an overlay the
        // keyboard is not driving misrepresents what the next key does. So the
        // order here is that routing order, read off Chat::update() rather than
        // asserted: keyHelp (checked immediately after Ctrl+C) → permission
        // prompt → palette → session picker.
        //
        // Four links make SIX pairs, and they are not all the same kind of
        // pair. "Every pair here is genuinely reachable" was the earlier
        // sentence and it was false — only the two involving the reference were
        // ever substantiated. What each pair actually is, measured:
        //
        // - reference + palette — REACHABLE, and the route is the mouse: with
        //   the reference up its box never reaches the status bar, so the bar's
        //   `pane:` click zone stays live underneath it, and clicking
        //   "Ctrl+P menu" at 100x30 opens the palette with keyHelp still 0. The
        //   reference keeps both the slot and the keyboard, which is the honest
        //   outcome; before this ordering the palette painted while the
        //   reference ate the keys.
        // - reference + permission prompt — reachable only from the ENGINE path:
        //   the reference opens from an idle turn (Chat::update()'s "?" arm,
        //   submit()'s /keys branch) and a prompt only exists mid-turn. No
        //   producer that exists today can put the pair up (see
        //   Chat::requestPermission(), which documents why its generation guard
        //   is dormant defence rather than the thing that closed a live path);
        //   an unstamped ASK from a future pipeline still can. So the pair is
        //   ordered by the rule rather than assumed away, and the status bar
        //   says the prompt is there rather than leaving it invisible AND
        //   silent (see KEY_HELP_OVER_PROMPT).
        // - the other four pairs — reference + picker, prompt + palette,
        //   prompt + picker, palette + picker — are FIXED PRECEDENCE FOR
        //   DETERMINISM, not reachable states. Chat.php says so of the last of
        //   them at its own routing site: the palette and the picker "cannot
        //   both be open". A documented fixed order between two modals that
        //   cannot coexist is worth having anyway — it is what stops the frame
        //   from depending on `if` order nobody chose, the day a fifth overlay
        //   or a new producer makes one of these pairs reachable after all.
        //
        // Being dormant is not being unpinned: KeyHelpTest::
        // testTheOverlayChainPaintsInRoutingOrderRightDownTheChain() drives all
        // four overlays up at once — a state the front door cannot reach, built
        // deliberately — and walks the whole chain, so every one of the six
        // pairs fails if this order changes.
        //
        // renderKeyHelp() being first also means it runs on every frame THIS
        // method paints, which is what keeps its $keyHelpMaxOffset ceiling from
        // going stale: were it last, an earlier overlay winning the slot would
        // leave the ceiling at whatever the last painted reference measured, and
        // Chat::withKeyHelp() clamps against it. Not every frame the app paints
        // goes through here — $keyHelpMaxOffset's own docblock names the hosted
        // Pane::Agents exception and why it is harmless.
        $overlay = self::renderKeyHelp($chat, $theme);
        if ($overlay === '') {
            $overlay = self::renderPermissionPrompt($chat, $theme);
        }
        if ($overlay === '') {
            $overlay = self::renderPalette($chat, $theme);
        }
        if ($overlay === '') {
            $overlay = self::renderSessionPicker($chat);
        }
        if ($overlay !== '') {
            // A fresh Veil per render call (rather than one persisted on
            // Chat) means its own frame-diffing never kicks in - fine here,
            // since Chat already does its own diffing at a higher level in
            // view() and double-diffing isn't needed for correctness.
            // Veil clips the overlay to the background's widest line, and the
            // frame's lines are only as wide as their own content - so a modal
            // wider than the current transcript would lose its right border
            // (most visibly mid-turn, which is exactly when a permission
            // prompt appears). Widen the backdrop to fit first.
            $backdrop = self::padForOverlay($frame, $overlay, $chat->cols());
            $frame = Veil::new()->withBackdrop(50)->composite(
                $overlay,
                $backdrop,
                Position::CENTER,
                Position::CENTER,
                self::overlayLeftShift($backdrop, $overlay, $chat->cols()),
            );
            // No-op unless the overlay was the palette: only renderPalette()
            // records item zones, and an overlay earlier in the chain above
            // takes the slot before it is ever called.
            $frame = self::markPaletteItems($frame);
        }

        return new View(self::scanRoot($frame, $chat->cols()), images: $images->placements());
    }

    /**
     * The bottom status bar: the existing processing indicator/help text,
     * plus a context-usage readout ({@see contextIndicator()}) so a user can
     * see how full the context window is without running /compact
     * speculatively.
     *
     * It is also where the two states in which the keybinding reference hides
     * something announce themselves — {@see KEY_HELP_TOO_SMALL} when the box
     * does not fit and {@see KEY_HELP_OVER_PROMPT} when it covers a blocking
     * permission prompt. Both swallow every keystroke
     * ({@see Chat::handleKeyHelpKey()}) while leaving the rest of the frame
     * saying nothing about it, and a terminal that silently eats input reads as
     * a hung app rather than as an open modal.
     */
    private static function renderStatusBar(Chat $chat): string
    {
        // Shorter than the bar it replaces, so a narrow terminal cannot be
        // made to overflow by MORE than it already does — the bar is the one
        // line this renderer does not truncate, a pre-existing property the
        // reference must not worsen.
        //
        // Instrument: Width::of() AFTER stripZoneMarkers() — the columns the
        // bar actually paints. The unstripped string reads 23 columns wider
        // (the `pane:menu` sentinel pair and the zone id inside it, which
        // Program resolves into a paint and never puts on screen), and strlen
        // reads the multi-byte glyphs as bytes; the same instrument
        // KEY_HELP_OVER_PROMPT's floor is measured with, for the same reason.
        //
        // The bound is two numbers, and the second one is only meaningful with
        // its DOMAIN attached, because the bar's width depends on the app state
        // and not just on the terminal. This cue is a CONSTANT 33 columns. The
        // narrowest bar it can replace is 36 — the in-flight bar,
        // `0% · ⠴ thinking… · Esc Esc to cancel`, measured at cols 1. So the
        // margin is 3 columns, and replacing the bar with the cue can never
        // widen the frame.
        //
        // 36, not 54. 54 is the floor of the IDLE bar, which is the only bar
        // KeyHelpTest::chat()'s two-message fixture can produce, and the previous
        // revision quoted it under a conclusion that ranged over every app state.
        // A fixture that cannot enter the narrow state cannot bound it: an
        // in-flight turn and a pending permission prompt both render the 36-column
        // bar, and BOTH have this cue substituted for it (`rows <= 4` or
        // `cols <= 4`, which a small terminal reaches in either state). The sister
        // cue {@see KEY_HELP_OVER_PROMPT} is tighter still — 35 columns against
        // the same 36-column bar, because it fires precisely when a prompt is
        // pending, i.e. only ever against the narrow bar. One column of margin.
        //
        // Worth noting how the wrong figure survived: 36 was ALREADY in this file,
        // correct, in {@see KEY_HELP_OVER_PROMPT}'s own docblock, which had to
        // measure the narrow bar because its cue only ever meets that one. Two
        // copies of one measurement, 500 lines apart, and only the copy whose cue
        // could also meet the WIDE bar went stale — because its fixture reached
        // the wide bar first and stopped there.
        //
        // Every figure here is swept and ASSERTED rather than restated, over app
        // STATES as well as terminal sizes, by
        // testTheCuesFitTheNarrowestBarAnyAppStateCanProduce(). The
        // size-only sweep stays beside it as
        // testTheBarIsNeverNarrowerThanTheTooSmallCueAtAnySize() (which pins that
        // on the idle fixture the bar tracks COLUMNS only and takes exactly four
        // values), and testTheTooSmallCueIsNeverWiderThanTheBarItReplaces() does
        // the four-sample per-size comparison. Deliberately NO width table in
        // this comment: it has now carried a wrong bar figure in three consecutive
        // rounds, because a range written in prose has nothing reading it back.
        // First it said "73-94", which matches no instrument at all; then "54 at
        // every width below 79 and 75 at 79 and above", wrong in both halves; then
        // 54 as a floor over a domain the fixture could not cover.
        if ($chat->keyHelp() !== null && self::keyHelpGeometry($chat) === null) {
            return self::KEY_HELP_TOO_SMALL;
        }

        // Ordered after the too-small cue rather than combined with it: that
        // one already says the reference is swallowing every keystroke, which
        // is the more urgent half when nothing is painted at all, and two
        // messages on one un-wrappable line would not fit anyway.
        if ($chat->keyHelp() !== null && $chat->pendingPermission() !== null) {
            return self::KEY_HELP_OVER_PROMPT;
        }

        // The "Ctrl+P menu" hint is the live path's only affordance for
        // Pane::Menu (the palette is what the disconnected App system's
        // MenuBar pane would have been), so it is the region that carries
        // the `pane:menu` click zone — crush_feat.md §8 E3's "click the
        // pane's title region to jump straight to it". While a request is in
        // flight the hint is not drawn at all, so no zone is marked either.
        $processing = $chat->inFlight
            ? '⠴ thinking… · Esc Esc to cancel'
            : 'Enter to send · ' . self::markPane(Pane::Menu, 'Ctrl+P menu') . ' · /exit or ^C to quit';
        // The readout is sized against the room the rest of the row leaves
        // rather than being emitted at full length and hoping it fits: it is
        // the widest variable-length piece of the bar, and the bar is the one
        // line that must never wrap (see below).
        //
        // The scroll readout's WIDEST form is reserved up front even though
        // it is prepended afterwards, so that adding the token count can only
        // ever shrink the context readout — never crowd the scroll position
        // off the row. Scroll position is transient and only shown when the
        // newest output is off-screen, which makes it the more urgent of the
        // two; context usage still reports its percentage at any width.
        $separator = ' · ';
        $indicators = self::scrollIndicators($chat);
        $room = $chat->cols()
            - Width::of(self::stripZoneMarkers($processing))
            - Width::of($separator)
            - ($indicators === [] ? 0 : Width::of($indicators[0]));
        $bar = self::contextIndicator($chat, $room) . $separator . $processing;

        // The bar is the frame's LAST line, so it is the one line that must
        // never wrap: a wrapped bar makes the frame rows+1 physical rows tall,
        // which is precisely the absolute-cursorTo row collision render()'s
        // tail clip exists to prevent (renderDiff() guards the same
        // one-logical-line-per-row invariant with Width::truncate). The scroll
        // readout is therefore fitted to whatever room the bar leaves instead
        // of being prepended unconditionally — the bar is already ~62 columns,
        // and any transcript tall enough to scroll produces 2-3 digit offsets,
        // so the long form alone pushes it past 80 columns.
        //
        // "Fitted" means picking a narrower form or dropping it — never
        // truncating the assembled string. $bar carries markPane(Pane::Menu)'s
        // sentinel PAIR, and a cut between them leaves an unmatched open
        // marker, which makes Scan::parse() throw and costs the WHOLE frame
        // its click zones (same failure mode markPaneHeader() documents). The
        // sentinels are invisible on screen, so they come off before measuring.
        $room = $chat->cols() - Width::of(self::stripZoneMarkers($bar));
        foreach ($indicators as $indicator) {
            if (Width::of($indicator) <= $room) {
                return $indicator . $bar;
            }
        }

        return $bar;
    }

    /**
     * The status bar's context-usage readout: an absolute token count in K
     * alongside the percentage, because a bare "37%" is unactionable unless
     * the user already knows the budget by heart.
     *
     * Both numbers come from Chat ({@see Chat::contextTokens()} /
     * {@see Chat::contextTokenLimit()}) rather than being re-derived from the
     * percentage — the renderer must not hardcode a budget that lives as a
     * constant on Chat. They carry a leading `~` because neither is measured:
     * the count is a chars/4 approximation and the limit is this app's fixed
     * compaction threshold, not the provider's advertised window. Labelling
     * the estimate is the honest option; printing "12.4K" unqualified would
     * read as a figure the provider reported.
     *
     * $room is the columns the rest of the bar leaves. Forms are tried
     * widest-first and the bare percentage is always emitted as a last
     * resort: the bar may not wrap (see {@see renderStatusBar()}), but
     * dropping the readout outright would leave a narrow terminal with no
     * context signal at all.
     */
    private static function contextIndicator(Chat $chat, int $room): string
    {
        $percent = (int) round($chat->contextUsagePercent() * 100);
        $used = self::formatTokenCount($chat->contextTokens());
        $limit = self::formatTokenCount($chat->contextTokenLimit());

        $forms = [
            "~{$used} / {$limit} context ({$percent}%)",
            "~{$used}/{$limit} ({$percent}%)",
            "{$percent}% context",
        ];
        foreach ($forms as $form) {
            if (Width::of($form) <= $room) {
                return $form;
            }
        }

        return "{$percent}%";
    }

    /**
     * A token count as the compact "12.4K" the one-line status bar has room
     * for. A round value loses its ".0" so a whole budget reads "100K"
     * rather than the noisier "100.0K".
     */
    private static function formatTokenCount(int $tokens): string
    {
        $thousands = number_format($tokens / 1000, 1, '.', '');

        return rtrim(rtrim($thousands, '0'), '.') . 'K';
    }

    /**
     * "How far back am I?" readout, shown only while the transcript is
     * scrolled off the bottom (crush_feat.md §8 E4's scrollbar-during-scroll).
     *
     * A fixed-height frame has no spare column for crush's real scrollbar
     * gutter, and the frame is clipped to the terminal anyway, so the
     * position is reported as text on the status bar the frame already
     * reserves. It hides on the state that matters — being back at the
     * newest line — rather than on a timer: the offset persists until the
     * user scrolls back down, so a timed hide would blank the only clue
     * that the newest output is off-screen while it still is.
     *
     * Reads {@see maxScrollOffset()} rather than recomputing: this runs
     * inside {@see render()}, AFTER the window slice recorded this frame's
     * own overflow.
     *
     * Returns the candidate forms widest-first so {@see renderStatusBar()} can
     * take the most informative one the row still has room for; an empty list
     * means "not scrolled, draw nothing".
     *
     * @return list<string>
     */
    private static function scrollIndicators(Chat $chat): array
    {
        $max = self::maxScrollOffset();
        $offset = max(0, min($chat->scrollOffset(), $max));
        if ($offset === 0) {
            return [];
        }

        // The compact fallback keeps the number that actually matters — how
        // far back the window is — when the full "of how many" readout would
        // not fit. Losing the readout entirely on a narrow terminal would
        // leave no clue at all that the newest output is off-screen.
        return ["↑ {$offset}/{$max} scrolled · ", "↑{$offset} "];
    }

    /**
     * Render the agent status line + agent list pane, or '' when Chat has
     * no AgentManager or the manager has no active agents. See the "R20
     * wiring decision" note on this class's docblock for why AgentOutputPane
     * / the split-pane renderer are not called here; the elapsed/token/cost
     * columns are real as of W3.S5b — see {@see agentDisplayState()}.
     *
     * This is the in-transcript strip. The full-pane dashboard the same data
     * feeds is {@see \SugarCraft\Crush\Tui\Components\AgentDashboardPane},
     * reached through the shell's `Pane::Agents`.
     */
    private static function renderAgentView(Chat $chat): string
    {
        $manager = $chat->agentManager();
        if ($manager === null) {
            return '';
        }

        $agents = $manager->active();
        if ($agents === []) {
            return '';
        }

        $states = array_map(
            static fn(Agent $agent): AgentDisplayState => self::agentDisplayState($agent, $manager),
            $agents,
        );

        $cols = $chat->cols();
        $width = max(40, $cols - 4);

        // The status bar's first row is this pane's header, so it carries the
        // `pane:agents` click zone (crush_feat.md §8 E3) — clicking it runs
        // the same /agents dispatch Ctrl+A does. See {@see markPaneHeader()}
        // for why the whole block is not marked.
        return self::markPaneHeader(Pane::Agents, AgentStatusBar::render($states))
            . "\n" . AgentViewPane::render($states, -1, $width, self::AGENT_VIEW_MAX_ROWS);
    }

    /**
     * Map a real registered {@see Agent} to the display-state shape
     * AgentStatusBar/AgentViewPane render.
     *
     * W3.S5b: elapsedSeconds/tokensUsed/costUsd are now read off
     * {@see AgentManager}'s real per-agent telemetry (added by W3.F2, which
     * stamps `startedAt` and accumulates tokens/cost per streamed chunk in
     * `executeSubAgent()`), replacing the literal `0, 0, 0.0` this method
     * previously reported. They still read 0 for an agent that has never
     * spawned a sub-agent — that is a genuine "no work done yet", not a
     * placeholder.
     */
    private static function agentDisplayState(Agent $agent, AgentManager $manager): AgentDisplayState
    {
        return AgentDisplayState::new(
            name: $agent->name,
            status: $agent->isActive ? 'working' : 'stopped',
            operation: $agent->description,
            elapsedSeconds: $manager->elapsedSeconds($agent->name),
            tokensUsed: $manager->tokensUsed($agent->name),
            costUsd: $manager->costUsd($agent->name),
        );
    }

    /**
     * Render a one-line session tab strip from real {@see Chat::sessionStore()}
     * rows, with the current session bracketed. Returns '' when there is no
     * session store or fewer than 2 sessions exist — a single session isn't
     * worth a tab strip, and {@see Chat}'s Ctrl+Tab handler is itself a no-op
     * below 2 sessions (see `Chat::cycleSessionTab()`). See the "R20 wiring
     * decision" note on this class's docblock for why `Tui\SessionTabs`
     * itself is not instantiated to build this strip.
     */
    private static function renderSessionTabStrip(Chat $chat): string
    {
        $store = $chat->sessionStore();
        if ($store === null) {
            return '';
        }

        $rows = $store->listSessions();
        if (count($rows) < 2) {
            return '';
        }

        $current = $chat->currentSessionId();
        $labels = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            $rawName = (string) ($row['name'] ?? '');
            $name = $rawName !== '' ? $rawName : $id;
            $label = ($id !== '' && $id === $current) ? "[{$name}]" : " {$name} ";
            $labels[] = self::markSessionTab($id, $label);
        }

        return implode('|', $labels);
    }

    /**
     * Wrap one session tab label in a `tab:<id>` click zone (crush_feat.md
     * §8 E2), so the cells it lands on can be turned back into the session
     * they belong to by {@see Chat::update()}.
     *
     * Only the label is marked, never the `|` separators: a click on the gap
     * between two tabs is ambiguous, and leaving it unmarked makes it a no-op
     * rather than a coin flip.
     *
     * Marking is skipped entirely when clicks are off. That is not just an
     * optimisation of a dead feature: with no marker anywhere in the frame,
     * {@see scanRoot()} keeps its `str_contains()` fast path and skips the
     * ~24ms grapheme walk, so disabling the mouse also buys back the render
     * cost of supporting it.
     */
    private static function markSessionTab(string $id, string $label): string
    {
        $zoneId = self::SESSION_TAB_ZONE_PREFIX . $id;

        if (
            $id === ''
            || !Chat::mouseClicksEnabled()
            || preg_match(self::ZONE_ID_CHARSET, $id) !== 1
            || strlen($zoneId) > Mark::MAX_ID_BYTES
        ) {
            return $label;
        }

        return Mark::zone($zoneId, $label);
    }

    /**
     * Wrap one on-screen region in a `pane:<name>` click zone (crush_feat.md
     * §8 E3), so a click on it can be turned back into the {@see Pane} it
     * belongs to by {@see Chat::update()}.
     *
     * No id validation here, unlike {@see markSessionTab()}: a pane id is a
     * `Pane` case's own `value` (a lowercase ASCII literal in the enum), not
     * a string that arrived from disk, so it cannot fall outside
     * {@see Mark}'s charset and make `Mark::wrap()` throw inside `view()`.
     *
     * Marking is skipped when clicks are off, for the reason spelled out on
     * {@see markSessionTab()}: with no marker anywhere in the frame
     * {@see scanRoot()} keeps its `str_contains()` fast path.
     *
     * @param string $content MUST be a single line — see {@see markPaneHeader()}.
     */
    private static function markPane(Pane $pane, string $content): string
    {
        if ($content === '' || !Chat::mouseClicksEnabled()) {
            return $content;
        }

        return Mark::zone(self::PANE_ZONE_PREFIX . $pane->value, $content);
    }

    /**
     * Remember that $label's row should become a `toolcall:<key>` click zone
     * (crush_feat.md §8 E5). Called as the transcript is built; the actual
     * {@see Mark::zone()} happens later in {@see markToolCalls()}, for the
     * layout reason documented on {@see $toolCallZones}.
     *
     * $key is validated the way {@see markSessionTab()} validates a session
     * id, and for the same reason: it is `ToolResult::$id`, which is whatever
     * the provider (ultimately the model) put in the tool call, so an id
     * carrying anything outside {@see Mark}'s charset would throw from inside
     * `view()` and take the TUI down. An unmarkable row simply stays
     * unclickable — Ctrl+O still expands it.
     *
     * A key already recorded this frame is skipped rather than recorded
     * twice: duplicate ids make {@see \SugarCraft\Mouse\Scan::parse()} throw,
     * and {@see scanRoot()} answers a throw by clearing the registry, which
     * would cost the whole frame its zones. Keys collide when a result has no
     * id and shares its name with an earlier one — those two rows already
     * share a single expanded-state entry, so only one of them could have
     * shown an independent result anyway.
     */
    private static function recordToolCallZone(string $key, string $label): void
    {
        $zoneId = self::TOOL_CALL_ZONE_PREFIX . $key;

        if (
            $key === ''
            || !Chat::mouseClicksEnabled()
            || preg_match(self::ZONE_ID_CHARSET, $key) !== 1
            || strlen($zoneId) > Mark::MAX_ID_BYTES
        ) {
            return;
        }

        foreach (self::$toolCallZones as $zone) {
            if ($zone['id'] === $key) {
                return;
            }
        }

        self::$toolCallZones[] = ['id' => $key, 'label' => $label];
    }

    /**
     * Turn each row recorded by {@see recordToolCallZone()} into a click zone
     * on the already-bordered, already-padded shell (crush_feat.md §8 E5).
     *
     * Rows are located by their label text rather than by index because the
     * shell adds its own border/padding rows and a tool block is preceded by
     * a variable number of transcript lines. Each shell row is claimed at
     * most once and searching resumes from the row after the last claim, so
     * two results that render an identical label (same tool, same status, no
     * ids) map to the two rows in the order they were emitted instead of both
     * resolving to the first one.
     *
     * The WHOLE row is wrapped, borders included: the label already spans
     * most of it, and a click landing on the padding beside a tool row has no
     * other meaning, so a generous target beats an exact one here. It stays a
     * single-line zone, which is what keeps it clip-safe — see
     * {@see markPaneHeader()} for what a multi-row zone does when
     * {@see render()} slices the frame to the terminal's height.
     */
    private static function markToolCalls(string $shell): string
    {
        if (self::$toolCallZones === []) {
            return $shell;
        }

        $lines = explode("\n", $shell);
        $from  = 0;
        foreach (self::$toolCallZones as $zone) {
            for ($i = $from, $n = count($lines); $i < $n; $i++) {
                if (str_contains($lines[$i], $zone['label'])) {
                    $lines[$i] = Mark::zone(self::TOOL_CALL_ZONE_PREFIX . $zone['id'], $lines[$i]);
                    $from      = $i + 1;

                    break;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Mark only the FIRST line of a multi-line block as the pane's zone —
     * its header row, which is the "title/border region" §8 E3 asks for.
     *
     * Deliberately not the whole block. {@see render()} clips `$content` to
     * the terminal's height by dropping leading LINES, so a zone spanning
     * several rows can lose its opening sentinel while the closing one
     * survives. That unmatched close makes {@see \SugarCraft\Mouse\Scan::parse()}
     * throw, and {@see scanRoot()} answers a throw by clearing the registry
     * — costing the WHOLE frame its zones, session tabs included. A
     * single-line zone is clipped whole or not at all, so it can never
     * desync.
     */
    private static function markPaneHeader(Pane $pane, string $block): string
    {
        $lines = explode("\n", $block);
        $lines[0] = self::markPane($pane, $lines[0]);

        return implode("\n", $lines);
    }

    /**
     * @param list<Message>       $history
     * @param int                 $width     usable columns inside the shell's border +
     *                                       padding, so nested boxes (tool diffs) can
     *                                       truncate rather than wrap into a second row
     * @param array<string, bool> $expanded  {@see Chat::expanded()} - tool-call ids the
     *                                       user has expanded, keyed by id
     * @param ImageLayer          $images    this frame's pixel-graphics layer, threaded
     *                                       down to {@see renderToolImage()}
     * @param Mosaic|null         $mosaic    the probe-once terminal image capability off
     *                                       {@see Chat::mosaic()}; null disables images
     * @param int                 $imageRows tallest cell box a single tool image may
     *                                       be encoded at, see {@see renderToolImage()}
     */
    private static function renderHistory(array $history, Theme $theme, int $width, array $expanded, ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
    {
        if ($history === []) {
            return '_(empty conversation — type a question and press Enter)_';
        }
        $md = new Markdown($theme->markdown);
        $blocks = [];
        foreach ($history as $msg) {
            // Defense-in-depth (candy-buffer #1362): User and System content is
            // untrusted and reaches the terminal wire verbatim. A raw ESC would
            // desync the frame-diff line model or forge SGR that escapes the
            // renderer's own styling (e.g. a smuggled reset() breaking out of the
            // system FAINT wrapper); NUL/BEL/DEL garble or beep the terminal.
            // These turns are plain text with no legitimate SGR, so untrusted()
            // (full ANSI + C0/DEL/lone-C1 strip) is correct — the Assistant path
            // stays raw because CandyShine emits legitimate, already-processed SGR.
            if ($msg->toolResults !== []) {
                $blocks[] = self::renderToolResults($msg, $theme, $width, $expanded, $images, $mosaic, $imageRows);

                continue;
            }
            if ($msg->pendingToolCallId !== null) {
                $blocks[] = self::renderPendingToolCall($msg, $theme);

                continue;
            }
            $blocks[] = match ($msg->role) {
                Role::User      => Style::new()->foreground($theme->userLabel)->bold()->render('user>') . " " . self::untrusted($msg->content),
                Role::Assistant => self::renderAssistantTurn($msg, $theme, $md),
                Role::System    => Style::new()->foreground($theme->systemLabel)->faint()->render("system: " . self::untrusted($msg->content)),
            };
        }
        return implode("\n\n", $blocks);
    }

    /**
     * An assistant turn's label + (when present) its {@see Message::$reasoning}
     * line + rendered Markdown body. §12 D3's final wiring step - the
     * extractor already splits reasoning out at the provider layer and
     * {@see \SugarCraft\Crush\Backend\EngineBackend} threads it onto the root
     * {@see Message} DTO; this is where it actually reaches the user instead
     * of being computed and discarded.
     */
    private static function renderAssistantTurn(Message $msg, Theme $theme, Markdown $md): string
    {
        $label = Style::new()->foreground($theme->assistantLabel)->bold()->render('assistant');
        // Sentinels stripped BEFORE CandyShine, not after: the rendered output
        // is legitimate SGR that untrusted() would destroy, but the model's
        // raw text can still smuggle U+E000/U+E001 into the frame.
        $body = trim($md->render(self::stripSentinels($msg->content)));

        if ($msg->reasoning === null || trim($msg->reasoning) === '') {
            return $label . "\n" . $body;
        }

        return $label . "\n" . self::renderReasoning($msg->reasoning, $theme) . "\n" . $body;
    }

    /**
     * The in-flight reply as far as it has arrived, painted with the same
     * label and Markdown treatment a settled assistant turn gets - so the
     * moment the turn lands, the text does not visibly re-flow into a
     * different shape.
     *
     * Markdown rendering is guarded, which {@see renderAssistantTurn()} does
     * not need to be: a partial reply is a genuinely different input class -
     * an unterminated code fence, a half-written table row, a lone `[` - and
     * every frame of a streaming turn feeds one to the parser. A renderer
     * exception here would take down the whole TUI mid-answer, so a partial
     * that cannot be parsed yet is shown as plain (sanitized) text until the
     * next chunk completes the construct. Untrusted-stripped in that fallback
     * because CandyShine is what normally neutralises raw model output.
     */
    private static function renderStreamingTurn(string $partial, Theme $theme): string
    {
        $label = Style::new()->foreground($theme->assistantLabel)->bold()->render('assistant');
        // Sentinels stripped BEFORE the renderer, same order and reason as
        // renderAssistantTurn(): the model's raw text can smuggle
        // U+E000/U+E001 into the frame and break the mouse-zone scan.
        $raw = self::stripSentinels($partial);

        try {
            $body = rtrim((new Markdown($theme->markdown))->render($raw));
        } catch (\Throwable) {
            $body = self::untrusted($raw);
        }

        return $label . "\n" . $body;
    }

    /**
     * Dimmed, single-line, collapsed rendering of a model's extracted
     * "thinking" text - per crush_feat.md §12 D3 ("surface the result
     * rendered dimmed/collapsed in the TUI"). Collapsed to one flattened,
     * truncated line rather than rendered in full: a MiniMax-M2.7 thinking
     * trace can run to thousands of tokens, and showing it verbatim would
     * push the actual answer off-screen turn after turn. Reasoning is raw
     * model output that never passes through CandyShine's Markdown renderer,
     * so - like every other untrusted turn in this method - it goes through
     * {@see Sanitize::untrusted()} before display.
     */
    private static function renderReasoning(string $reasoning, Theme $theme): string
    {
        $flat = trim(preg_replace('/\s+/', ' ', self::untrusted($reasoning)) ?? '');
        if (mb_strlen($flat) > 120) {
            $flat = mb_substr($flat, 0, 120) . '…';
        }

        return Style::new()->foreground($theme->systemLabel)->faint()->render('💭 ' . $flat);
    }

    /**
     * A message carrying {@see ToolResult}s (see {@see Message::withToolResults()})
     * gets a distinct "🔧 tool" marker per result instead of the plain
     * assistant bubble {@see renderHistory()} uses for real replies -
     * otherwise a tool call is visually indistinguishable from the model's
     * own words, which is exactly what made tool execution look silent.
     *
     * A result that carries a unified diff ({@see ToolResult::hasDiff()} -
     * `Edit`/`Write` produce one, see `Tools\BuiltIn\Edit::unifiedDiff()`)
     * additionally gets that diff painted below the marker, per crush_feat.md
     * §1 E3. The diff is consumed verbatim from the result; it is never
     * recomputed here, because the renderer has neither the pre-edit file
     * contents nor any business touching the filesystem.
     *
     * A result carrying image bytes ({@see ToolResult::hasImage()} - the
     * `/doctor` built-in is a real producer) additionally gets the picture
     * itself painted below the marker, via {@see renderToolPicture()}
     * (crush_feat.md §9 E3), but only once the user has EXPANDED that call.
     *
     * User-reported: running `/doctor` "shows just a big green box for output,
     * nothing else, not collapsable or expandable". Two causes, both fixed
     * here. First, a picture is budgeted a whole viewport minus two rows
     * ({@see renderView()}), and the transcript is tail-clipped, so an
     * unconditional picture EVICTED its own icon+name row - the box arrived
     * with nothing identifying it. Second, the image bypassed §1 E5's
     * collapse machinery entirely, so Ctrl+O (and the click zone that shares
     * its key) genuinely did nothing to it. An image-bearing result therefore
     * now collapses exactly like a text body does: one faint
     * {@see collapsedImageNotice()} line while collapsed, the real picture
     * once expanded.
     *
     * The same result's text is that picture's only caption (`/doctor`
     * returns a real summary string saying which protocol was detected), so -
     * like an error body, and unlike an ordinary successful one - it is kept
     * rather than hidden when the call is collapsed. A captionless swatch is
     * what read as a glitch in the first place.
     *
     * Bodies are no longer dumped in full forever (crush_feat.md §1 E5). A
     * SUCCESSFUL result is the case where the output is least likely to be
     * worth screen space - the model already read it, and the user mostly
     * needs to know the call happened - so its body is hidden entirely until
     * the tool-call id appears in $expanded ({@see Chat::toggleToolOutput()},
     * Ctrl+O). An ERROR body is never hidden, because that is precisely the
     * output the user is looking for, but it is still clipped through
     * {@see collapseToolOutput()} so a multi-megabyte stderr can't evict the
     * conversation it belongs to. Expanding shows the body verbatim.
     *
     * A result that never ran gets its own visual state rather than the
     * generic error one (crush_feat.md §1 E7): both a refusal
     * ({@see Chat::isDeniedResult()} - the user rejected the prompt, or a
     * hook/permission gate stopped it) and a restart-orphaned call
     * ({@see Chat::isInterruptedResult()}) draw the icon+name row STRUCK
     * THROUGH. The body is left un-struck because for these two states the
     * body is the reason, which is exactly what the user needs to read.
     *
     * @param array<string, bool> $expanded {@see Chat::expanded()}
     */
    private static function renderToolResults(Message $msg, Theme $theme, int $width, array $expanded, ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
    {
        $lines = [];
        foreach ($msg->toolResults as $result) {
            // The name is model-chosen, not ours: Chat::executeToolCall() copies
            // it verbatim off the parsed tool call, so an unknown-tool reply can
            // carry an OSC title-set or a screen-clear. Unlike assistant
            // Markdown it has no legitimate SGR of its own, so it takes the full
            // {@see untrusted()} scrub rather than only the sentinel strip.
            // A refused or interrupted call is not just a failed one: it never
            // ran, so its whole icon+text row is struck through rather than
            // merely recoloured (crush_feat.md §1 E7).
            $denied = Chat::isDeniedResult($result);
            $stopped = $denied || Chat::isInterruptedResult($result);
            $status = match (true) {
                $denied            => Style::new()->foreground($theme->systemLabel)->bold()->strikethrough()->render('⊘ denied'),
                $stopped           => Style::new()->foreground($theme->systemLabel)->bold()->strikethrough()->render('⊘ interrupted'),
                $result->isError() => Style::new()->foreground($theme->systemLabel)->bold()->render('✗ error'),
                default            => Style::new()->foreground($theme->assistantLabel)->bold()->render('✓ ok'),
            };
            $label = Style::new()->foreground($theme->systemLabel)->faint()->strikethrough($stopped)->render('🔧 tool: ' . self::untrusted($result->name)) . ' ' . $status;
            $row = $label . self::toolCallSuffix($result, $theme, $width, Width::of($label));
            $body = self::untrusted($result->isError() ? ($result->error ?? '') : $result->result);
            $key = $result->id ?? $result->name;
            $isExpanded = ($expanded[$key] ?? false) === true;
            // §8 E5: the same key Ctrl+O toggles, so a click and the keystroke
            // drive one expansion mechanism rather than two.
            self::recordToolCallZone($key, $label);

            $hasImage = $result->hasImage();

            $block = $row;
            if ($body !== '') {
                $block .= "\n" . self::renderToolBody($body, $result->isError() || $hasImage, $isExpanded, $theme);
            }

            if ($result->hasDiff()) {
                $block .= "\n" . self::renderDiff((string) $result->diff, $theme, $width);
            }

            if ($hasImage) {
                $picture = self::renderToolPicture($result, $theme, $width, $images, $mosaic, $imageRows, $isExpanded);
                if ($picture !== '') {
                    $block .= "\n" . $picture;
                }
            }

            $lines[] = $block;
        }

        return implode("\n\n", $lines);
    }

    /**
     * The " — <what actually ran>" tail appended to a finished tool row.
     *
     * User-reported gap behind crush_feat.md §3 E2: the running placeholder
     * has always shown {@see Message::describeToolCall()}'s one-liner (e.g.
     * `bash(command: "ls -la")`, or the model-authored description when the
     * turn sent one), but the finished row replaced it with just the tool
     * NAME - so once §1 E5's collapse hid the body, a row said `bash ✓ ok`
     * and never which command that was. {@see Chat} now carries the
     * placeholder's one-liner onto the result ({@see ToolResult::$description})
     * and this is where it reaches the user.
     *
     * Appended AFTER the status rather than between name and status on
     * purpose: {@see recordToolCallZone()} registers the un-suffixed label and
     * {@see markToolCalls()} locates the row by `str_contains()`, so keeping
     * the recorded label a verbatim PREFIX of the rendered row is what keeps
     * click-to-expand pointing at the right line.
     *
     * The string is model-authored, so it is {@see untrusted()}-scrubbed (it
     * was already flattened to one line upstream by
     * {@see Message::describeToolCall()}, but this renderer never trusts that)
     * and hard-truncated to whatever the row has left, preserving the
     * one-logical-line-per-row invariant {@see renderDiff()} documents. Returns
     * '' when there is no description or no room for a useful amount of it -
     * a couple of columns of an elided command is worse than none.
     *
     * @param int $used display columns the label already occupies on this row
     */
    private static function toolCallSuffix(ToolResult $result, Theme $theme, int $width, int $used): string
    {
        if (!$result->hasDescription()) {
            return '';
        }

        $separator = ' — ';
        $room = $width - $used - Width::of($separator);
        if ($room < self::TOOL_DESCRIPTION_MIN_COLS) {
            return '';
        }

        $text = Width::truncate(self::untrusted((string) $result->description), $room);
        if (trim($text) === '') {
            return '';
        }

        return Style::new()->foreground($theme->systemLabel)->faint()->render($separator . $text);
    }

    /**
     * The picture half of an image-bearing tool result, under §1 E5's
     * collapse policy: the real {@see renderToolImage()} encode once the
     * user has expanded the call, a one-line {@see collapsedImageNotice()}
     * affordance until then.
     *
     * Gating here rather than inside {@see renderToolImage()} keeps the
     * expensive path honest: a collapsed picture is never decoded, never
     * encoded and never registered with the {@see ImageLayer}, so a
     * transcript full of screenshots costs one faint line each per frame.
     *
     * Returns '' with no protocol at all, in BOTH states: with a null
     * {@see Chat::mosaic()} expanding could only ever reveal nothing, and an
     * affordance promising a picture that cannot exist is worse than silence.
     */
    private static function renderToolPicture(ToolResult $result, Theme $theme, int $width, ImageLayer $images, ?Mosaic $mosaic, int $imageRows, bool $isExpanded): string
    {
        if ($mosaic === null) {
            return '';
        }

        return $isExpanded
            ? self::renderToolImage($result, $theme, $width, $images, $mosaic, $imageRows)
            : self::collapsedImageNotice($result, $theme, $width);
    }

    /**
     * The collapsed stand-in for a tool result's picture: `🖼 20×10 sixel
     * image hidden (ctrl+o)`.
     *
     * Names the source's pixel dimensions and the protocol it will be painted
     * with, because the two questions a hidden picture raises are "how big is
     * it" and "will my terminal even show it" - the second being the entire
     * point of the `/doctor` swatch this was reported against.
     *
     * Dimensions come from `getimagesizefromstring()` (header only, no
     * bitmap decode - a collapsed row must stay cheap) and are simply omitted
     * when the header is unreadable; the expand path reports the real failure.
     * The protocol string is truncated with the rest of the line to preserve
     * the one-logical-line-per-row invariant.
     */
    private static function collapsedImageNotice(ToolResult $result, Theme $theme, int $width): string
    {
        $size = @getimagesizefromstring((string) $result->imageBytes);
        $dimensions = \is_array($size) && $size[0] > 0 && $size[1] > 0 ? "{$size[0]}×{$size[1]} " : '';
        $protocol = $result->imageProtocol === null || $result->imageProtocol === ''
            ? ''
            : self::untrusted($result->imageProtocol) . ' ';

        $text = Width::truncate('🖼 ' . $dimensions . $protocol . 'image hidden (ctrl+o)', max(1, $width));

        return Style::new()->foreground($theme->systemLabel)->faint()->render($text);
    }

    /**
     * Paint one image-bearing {@see ToolResult}'s bytes at the terminal's best
     * available protocol (crush_feat.md §9 E3), following the exact pattern
     * `sugar-gallery/src/PosterCard.php` proves out.
     *
     * Two shapes come back out of candy-mosaic and they are composed
     * differently: an inline renderer (half-block / quarter-block / ASCII)
     * emits ordinary styled cells that go straight into the frame, while a
     * pixel-graphics renderer (Sixel / Kitty / iTerm2) emits an out-of-band
     * escape blob that would corrupt the line-diff if it were concatenated -
     * that one is handed to {@see ImageLayer::place()}, which parks the bytes
     * on the layer and returns a same-sized marker block to occupy the frame
     * instead. {@see Mosaic::isInline()} is the switch; the fallback ladder
     * behind it (Kitty > iTerm2 > Sixel > chafa > half-block) is
     * {@see Mosaic::auto()}'s job, already decided before this call.
     *
     * Returns '' when no {@see Mosaic} is wired - a {@see Chat} built without
     * one (any direct `new Chat(...)`, as opposed to `Cli\Bootstrap::chat()`)
     * has no probed capability, and guessing a protocol for an unprobed
     * terminal would spray raw escape bytes at a terminal that cannot decode
     * them. The result's own text still renders; only the picture is skipped.
     *
     * Decoding is wrapped because `view()` runs on every frame and must never
     * throw: bytes reach here straight from a tool (possibly truncated, a
     * non-image, or a format this build of ext-gd cannot decode), and a
     * corrupt screenshot must cost one line of the transcript, not the
     * session.
     *
     * Both the decode and the encode are memoized in {@see $imageCache} because
     * this runs on every frame; only the (cheap) {@see ImageLayer::place()}
     * registration is redone per frame, since placement ids are positional to
     * the frame being built.
     *
     * @param int $imageRows tallest box to encode at. The height derived from
     *                       the aspect ratio is clamped to it so one tall source
     *                       (a full-page screenshot is easily 100+ cells high)
     *                       cannot blow up the encode cost for rows that
     *                       {@see renderView()}'s tail-clipping then discards.
     */
    private static function renderToolImage(ToolResult $result, Theme $theme, int $width, ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
    {
        if ($mosaic === null) {
            return '';
        }

        $bytes = (string) $result->imageBytes;
        $cols = max(8, min(self::IMAGE_COLS, $width));
        $rows = self::imageRows($bytes, $cols, $imageRows);
        $key = hash('xxh3', $bytes) . ':' . $cols . 'x' . $rows . ':' . $mosaic->protocol();

        if (isset(self::$imageCache[$key])) {
            $hit = self::$imageCache[$key];
            // Re-insert so eviction drops the picture that scrolled away, not
            // the one being repainted every frame.
            unset(self::$imageCache[$key]);
            self::$imageCache[$key] = $hit;
        } else {
            try {
                $hit = ['ok' => true, 'body' => $mosaic->render(ImageSource::fromString($bytes), $cols, $rows)];
            } catch (\Throwable $e) {
                $hit = ['ok' => false, 'body' => Style::new()->foreground($theme->systemLabel)->faint()
                    ->render('🖼 image unavailable: ' . Sanitize::untrusted($e->getMessage()))];
            }

            self::$imageCache[$key] = $hit;
            if (\count(self::$imageCache) > self::IMAGE_CACHE_MAX) {
                array_shift(self::$imageCache);
            }
        }

        if (!$hit['ok']) {
            return $hit['body'];
        }

        return $mosaic->isInline() ? $hit['body'] : $images->place($hit['body'], $cols, $rows);
    }

    /**
     * Cell height for an image of $bytes drawn $cols wide, clamped to $budget.
     *
     * Split out of {@see renderToolImage()} so the cheap header-only dimension
     * probe stays outside that method's cache lookup - the height is part of
     * the cache key, so it has to be known before the key is built, and
     * `getimagesizefromstring()` reads the header only rather than decoding the
     * whole bitmap the way {@see ImageSource::fromString()} does.
     *
     * Falls back to a square box when the header is unreadable: the real
     * decode is about to fail anyway, and this only has to produce a stable
     * cache key for that failure.
     */
    private static function imageRows(string $bytes, int $cols, int $budget): int
    {
        $size = @getimagesizefromstring($bytes);
        $aspect = \is_array($size) && $size[0] > 0 && $size[1] > 0 ? $size[0] / $size[1] : 1.0;

        // Cells are about twice as tall as they are wide, so the /2 is what
        // keeps a square image square rather than doubled in height.
        return max(1, min((int) round($cols / $aspect / 2), $budget));
    }

    /**
     * One tool result's body under the collapse/expand policy documented on
     * {@see renderToolResults()}: verbatim when expanded, a faint one-line
     * "N lines hidden" affordance when a collapsed call's body is worth
     * hiding, and a {@see collapseToolOutput()}-clipped excerpt (plus
     * trailer) when it is not.
     *
     * @param bool $keepWhenCollapsed the body survives collapsing (clipped
     *                                rather than hidden) because it is the
     *                                thing the user is looking for: an error's
     *                                reason, or the caption of a picture whose
     *                                own rendering is collapsed alongside it
     */
    private static function renderToolBody(string $body, bool $keepWhenCollapsed, bool $isExpanded, Theme $theme): string
    {
        if ($isExpanded) {
            return $body;
        }

        if (!$keepWhenCollapsed) {
            $count = substr_count($body, "\n") + 1;
            $hint = "… {$count} line" . ($count === 1 ? '' : 's') . ' hidden (ctrl+o)';

            return Style::new()->foreground($theme->systemLabel)->faint()->render($hint);
        }

        $collapsed = self::collapseToolOutput($body, self::TOOL_OUTPUT_MAX_LINES, self::TOOL_OUTPUT_MAX_CHARS);
        if (!$collapsed['overflow']) {
            return $collapsed['output'];
        }

        return $collapsed['output'] . "\n"
            . Style::new()->foreground($theme->systemLabel)->faint()->render('… output truncated (ctrl+o to expand)');
    }

    /**
     * Clip a tool's raw output to at most $maxLines lines AND $maxChars
     * characters, reporting whether anything was dropped (crush_feat.md
     * §1 E5).
     *
     * Deliberately a pure function over plain strings - no Theme, no Style,
     * no Chat - so the clipping policy is unit-testable on its own and can be
     * reused by any other surface that has to show tool output.
     *
     * The line budget is applied before the character budget so a result that
     * is short in lines but enormous in one of them still gets clipped; both
     * limits set `overflow`. Character counting is mb_*-based because tool
     * output is arbitrary UTF-8 and a byte-wise cut could split a codepoint
     * and put a lone continuation byte on the terminal wire.
     *
     * @param int $maxLines Maximum lines to keep; values below 1 are treated as 1
     * @param int $maxChars Maximum characters to keep; values below 1 are treated as 1
     *
     * @return array{output: string, overflow: bool}
     */
    public static function collapseToolOutput(string $output, int $maxLines, int $maxChars): array
    {
        if ($output === '') {
            return ['output' => '', 'overflow' => false];
        }

        $maxLines = max(1, $maxLines);
        $maxChars = max(1, $maxChars);

        $overflow = false;
        $rows = preg_split('/\r\n|\r|\n/', $output) ?: [];
        if (count($rows) > $maxLines) {
            $rows = array_slice($rows, 0, $maxLines);
            $overflow = true;
        }

        $clipped = implode("\n", $rows);
        if (mb_strlen($clipped) > $maxChars) {
            $clipped = mb_substr($clipped, 0, $maxChars);
            $overflow = true;
        }

        return ['output' => $clipped, 'overflow' => $overflow];
    }

    /**
     * Paint a raw unified diff (`--- a/…` / `+++ b/…` / `@@ … @@` / ` `+`/`-`
     * lines, exactly what `diff -u` emits) as a bordered, colour-coded block.
     *
     * Additions/removals are coloured with bare ANSI green/red rather than a
     * {@see Theme} field: every theme in the palette agrees on what "added"
     * and "removed" look like, and the diff has to stay readable even under
     * the `ansi` theme, which has no room for two more accent colours.
     *
     * Each row is prefixed with a faint old-file/new-file line-number gutter
     * ({@see DiffGutter}) so a reviewer can tell which line of the file a
     * change lands on - a raw `diff -u` body only says *what* changed.
     *
     * Every line is {@see Sanitize::untrusted()}-stripped before display -
     * diff bodies are verbatim file contents, so an edited file containing a
     * raw ESC would otherwise forge SGR straight onto the terminal wire - then
     * hard-truncated to $width so the frame keeps its one-logical-line-per-row
     * invariant (candy-core's Renderer repaints by absolute row; a wrapped
     * line silently shifts every row below it). Sanitising happens BEFORE the
     * gutter is computed, so the marker column the numbering reads is the same
     * one that ends up on screen. Tabs are expanded in the same pass, for the
     * width reason spelled out at the call site. The row count is capped at
     * {@see self::DIFF_MAX_ROWS} with a trailer for the same reason
     * {@see render()} tail-clips: a 400-line diff must not evict the
     * conversation it belongs to.
     */
    private static function renderDiff(string $diff, Theme $theme, int $width): string
    {
        // Border (2 cols) + padding(0, 1) (2 cols) sit outside the text.
        $inner = max(8, $width - 4);

        $rows = preg_split('/\r\n|\r|\n/', rtrim($diff, "\r\n")) ?: [];
        $overflow = count($rows) - self::DIFF_MAX_ROWS;
        if ($overflow > 0) {
            $rows = array_slice($rows, 0, self::DIFF_MAX_ROWS);
        }

        // TAB has to be expanded HERE, before anything measures a row.
        // candy-core's Sanitize::untrusted() deliberately preserves TAB, and
        // Width::string("\t") is 0 - but candy-sprinkles' Style::render()
        // expands every tab to tabWidth spaces before it does its own width
        // work (Style.php:969). So a tab-indented row (Go, Makefiles, C) is
        // budgeted 0 cells and painted 4, and the frame emits a row wider than
        // the viewport - the PR #1403 failure class, where candy-core's
        // absolute cursorTo() repaint turns an over-wide row into a status-bar
        // collision. The expansion width is read off a default Style rather
        // than written as a literal so it cannot drift from the styles below,
        // which are default-constructed the same way.
        $tab = str_repeat(' ', Style::new()->getTabWidth());
        $rows = array_map(
            static fn (string $row): string => str_replace("\t", $tab, self::untrusted($row)),
            $rows,
        );

        // On a viewport too narrow to spare the columns, the diff text itself
        // is worth more than knowing its line numbers - drop the gutter rather
        // than truncate every row down to its marker.
        $gutter = DiffGutter::forDiff($rows);
        if ($inner - $gutter->width < self::DIFF_MIN_BODY_COLS) {
            $gutter = DiffGutter::none(count($rows));
        }

        // Computed from the un-narrowed rows, and separately from $gutter, so
        // that dropping the gutter for a narrow viewport does not also change
        // how the body is coloured.
        $headers = DiffGutter::fileHeaders($rows);

        $body = $inner - $gutter->width;
        $gutterStyle = Style::new()->foreground($theme->systemLabel)->faint();

        $painted = [];
        foreach ($rows as $i => $row) {
            $text = Width::truncate($row, $body);
            $prefix = $gutter->prefixes[$i];
            $painted[] = ($prefix === '' ? '' : $gutterStyle->render($prefix))
                . self::styleDiffLine($text, $theme, $headers[$i])->render($text);
        }

        if ($overflow > 0) {
            $trailer = Width::truncate("… {$overflow} more diff line" . ($overflow === 1 ? '' : 's'), $body);
            $painted[] = $gutterStyle->render($gutter->blank . $trailer);
        }

        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render(implode("\n", $painted));
    }

    /**
     * Pick the {@see Style} for one unified-diff line from its marker column.
     * The `---`/`+++` file headers are matched before the bare `-`/`+` markers
     * they start with, otherwise a diff's own header would render as a giant
     * removal followed by a giant addition.
     *
     * Whether a `--- `/`+++ ` row IS a header is not decidable from the row
     * alone — `--` opens a comment in SQL, Lua, Haskell and Ada, so a deleted
     * `-- users table` arrives here looking exactly like a file header — so the
     * verdict comes from {@see DiffGutter::fileHeaders()}, which walks the block
     * and already knows whether a hunk is open. It is a required argument, not a
     * defaulted one, so a future caller has to decide rather than silently get
     * the ambiguous reading back.
     */
    private static function styleDiffLine(string $line, Theme $theme, bool $isHeader): Style
    {
        if ($isHeader) {
            return Style::new()->foreground($theme->systemLabel)->bold();
        }
        if (str_starts_with($line, '@@')) {
            return Style::new()->foreground(Color::ansi(6));
        }
        if (str_starts_with($line, '+')) {
            return Style::new()->foreground(Color::ansi(2));
        }
        if (str_starts_with($line, '-')) {
            return Style::new()->foreground(Color::ansi(1));
        }

        return Style::new()->foreground($theme->systemLabel)->faint();
    }

    /**
     * A "tool X is running" placeholder (see {@see Message::toolRunning()}) -
     * shown the moment a tool call is dispatched, before it finishes, so a
     * slow command doesn't look like nothing is happening. Replaced in
     * history with {@see renderToolResults()}'s finished marker once the
     * real result arrives (see Chat's ToolResultsMsg handling).
     */
    private static function renderPendingToolCall(Message $msg, Theme $theme): string
    {
        $spinner = Style::new()->foreground($theme->assistantLabel)->render('⠴');

        return $spinner . ' ' . Style::new()->foreground($theme->systemLabel)->faint()->render('running: ' . self::untrusted($msg->content));
    }

    /**
     * The "/" popup: {@see Chat::slashMenuMatches()}'s filtered command list,
     * with the highlighted row ({@see Chat::slashMenuIndex()}) marked with
     * "▸" and rendered brighter than the rest. Returns '' (nothing rendered)
     * once matches is empty - inputBuf isn't slash-prefixed, already
     * contains a space, or the typed prefix matches no command.
     */
    private static function renderSlashMenu(Chat $chat, Theme $theme): string
    {
        $matches = $chat->slashMenuMatches();
        if ($matches === []) {
            return '';
        }

        $selected = $chat->slashMenuIndex();
        $lines = [];
        foreach ($matches as $index => $spec) {
            $label = '/' . $spec->name . ' — ' . $spec->description;
            $lines[] = $index === $selected
                ? Style::new()->foreground($theme->userLabel)->bold()->render('▸ ' . $label)
                : Style::new()->foreground($theme->systemLabel)->faint()->render('  ' . $label);
        }

        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render(implode("\n", $lines));
    }

    /**
     * The live session picker's content, composited over the whole frame by
     * {@see render()} via {@see Veil} (crush_feat.md section 5 E8). Returns
     * '' (nothing composited) when the picker is closed - see
     * {@see Chat::sessionPicker()}.
     *
     * Sized against the same budget every other renderer here uses
     * ({@see SHELL_CHROME_COLS}), because under the live App/ChatPane shell
     * the picker is drawn inside a border + padding(1, 2) that the raw
     * terminal width knows nothing about. A further 4 columns come off for
     * {@see \SugarCraft\Crush\Tui\SessionPicker::render()}'s own
     * `border()->padding(0, 1)`, which wraps AROUND the width it is handed -
     * without that subtraction the composited frame is wider than the
     * terminal, and the diff renderer paints one logical line per physical
     * row, so a wrapped row collides with the next one exactly like the
     * overflow {@see render()}'s tail clip exists to prevent. The lower
     * bounds keep the picker's separator `str_repeat()` calls non-negative on
     * a very small terminal.
     *
     * No zone marking here (unlike {@see markPaletteItems()}): the picker is
     * keyboard-driven only, and its rows carry no click ids.
     */
    private static function renderSessionPicker(Chat $chat): string
    {
        $picker = $chat->sessionPicker();
        if ($picker === null) {
            return '';
        }

        $inner = max(20, $chat->cols() - self::SHELL_CHROME_COLS);
        $width = max(20, min($inner - 4, 76));
        $height = max(8, $chat->rows() - 4);

        return $picker->render($width, $height);
    }

    /**
     * The Ctrl+P command palette's content, composited over the whole frame
     * by {@see render()} via {@see Veil}. Returns '' (nothing composited)
     * when the palette is closed - see {@see Chat::palette()}.
     *
     * Rows come from {@see Chat::paletteMatchResults()} rather than the bare
     * label list so the matched characters can be highlighted through
     * {@see Highlighter} (crush_feat.md §4 E3). With no query typed, the root
     * list arrives category-grouped and MRU-biased and gets a faint header
     * per category (§4 E6/E7); a typed query stays a flat relevance-ranked
     * list, headers omitted, so the best match is always the first row.
     */
    private static function renderPalette(Chat $chat, Theme $theme): string
    {
        $palette = $chat->palette();
        if ($palette === null) {
            return '';
        }

        $results = $chat->paletteMatchResults();
        $selected = $palette->selectedIndex;
        $grouped = $palette->query === '' && $palette->mode !== 'providers' && $palette->mode !== 'themes';

        $lines = ['🔍 ' . self::untrusted($palette->query) . '█', ''];
        /** @var array<int, string> $rows row index => the content line it produced */
        $rows = [];
        if ($results === []) {
            $lines[] = Style::new()->foreground($theme->systemLabel)->faint()->render('No matches');
        } else {
            $highlighter = new Highlighter();
            // Underlined as well as recoloured: the selected row is already
            // bold userLabel, so colour alone would make its matched run
            // indistinguishable from the rest of the row.
            $matchStyle = Style::new()->foreground($theme->userLabel)->bold()->underline();
            $lastCategory = null;

            foreach ($results as $index => $result) {
                if ($grouped) {
                    $category = $chat->paletteCategory($result->haystack);
                    if ($category !== null && $category !== $lastCategory) {
                        $lines[] = Style::new()->foreground($theme->systemLabel)->faint()->render($category);
                        $lastCategory = $category;
                    }
                }

                $rowStyle = $index === $selected
                    ? Style::new()->foreground($theme->userLabel)->bold()
                    : Style::new()->foreground($theme->systemLabel);
                $reopen = self::sgrOpen($rowStyle);
                $body = $highlighter->highlight(
                    $result,
                    static fn(string $run): string => $matchStyle->render($run) . $reopen,
                );

                $row = $rowStyle->render(($index === $selected ? '▸ ' : '  ') . $body);
                $lines[] = $row;
                $rows[$index] = $row;
            }
        }

        $title = match ($palette->mode) {
            'providers' => ' switch model ',
            'themes' => ' switch theme ',
            default => ' command palette ',
        };

        $box = Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->width(50)
            ->render(implode("\n", $lines));

        self::recordPaletteItemZones($box, $rows);

        return $box;
    }

    /**
     * Remember which lines of the rendered palette box are clickable rows
     * (crush_feat.md §8 E6), for {@see markPaletteItems()} to wrap once the
     * box has been composited.
     *
     * A row is located by the content line it produced rather than by index,
     * for the reason {@see markToolCalls()} gives: the box adds border and
     * padding rows of its own, and the grouped root list interleaves faint
     * category headers between the rows. Each box line is claimed at most
     * once and the search resumes past the last claim, so two rows that
     * render identical text still map to the two lines they were emitted on.
     *
     * The WHOLE box line is recorded, borders included: it is the exact
     * substring `Veil::composite()` copies into the frame verbatim, which is
     * what lets {@see markPaletteItems()} wrap only the palette's own cells
     * instead of the full frame row (a click on the dimmed backdrop beside
     * the palette must not select a row).
     *
     * @param array<int, string> $rows row index => the content line it produced
     */
    private static function recordPaletteItemZones(string $box, array $rows): void
    {
        if ($rows === [] || !Chat::mouseClicksEnabled()) {
            return;
        }

        $lines = explode("\n", $box);
        $from  = 0;
        foreach ($rows as $id => $row) {
            for ($i = $from, $n = count($lines); $i < $n; $i++) {
                if (str_contains($lines[$i], $row)) {
                    self::$paletteItemZones[] = ['id' => (string) $id, 'line' => $lines[$i]];
                    $from                     = $i + 1;

                    break;
                }
            }
        }
    }

    /**
     * Turn each palette box line recorded by {@see recordPaletteItemZones()}
     * into a `picker-item:<index>` click zone on the ALREADY-composited frame
     * (crush_feat.md §8 E6) — see {@see $paletteItemZones} for why the mark
     * cannot happen before the composite.
     *
     * Only the box line's own cells are wrapped, not the frame row it landed
     * on, so the dimmed backdrop on either side of the palette stays inert.
     * A row whose box line is not found verbatim (the palette was clipped
     * because the terminal is narrower than the box) is simply left
     * unclickable; the arrow keys still reach it.
     */
    private static function markPaletteItems(string $frame): string
    {
        if (self::$paletteItemZones === []) {
            return $frame;
        }

        $lines = explode("\n", $frame);
        $from  = 0;
        foreach (self::$paletteItemZones as $zone) {
            for ($i = $from, $n = count($lines); $i < $n; $i++) {
                $at = strpos($lines[$i], $zone['line']);
                if ($at === false) {
                    continue;
                }

                $lines[$i] = substr_replace(
                    $lines[$i],
                    Mark::zone(self::PALETTE_ITEM_ZONE_PREFIX . $zone['id'], $zone['line']),
                    $at,
                    strlen($zone['line']),
                );
                $from = $i + 1;

                break;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * The opening SGR sequence of a style, with no text and no trailing
     * reset. Needed because {@see Style::render()} terminates every run with
     * a full reset: a highlighted run nested inside a coloured palette row
     * would otherwise strip the row's own colour off everything after it.
     * Re-emitting this after each highlighted run restores the row style.
     */
    private static function sgrOpen(Style $style): string
    {
        $rendered = $style->render('');
        $reset = "\x1b[0m";

        return str_ends_with($rendered, $reset)
            ? substr($rendered, 0, -strlen($reset))
            : $rendered;
    }

    /**
     * The in-app keybinding reference, composited over the whole frame by
     * {@see renderView()} via {@see Veil} (crush_code.md Phase 8 item 2).
     * Returns '' (nothing composited) when it is closed — see
     * {@see Chat::keyHelp()}.
     *
     * Rows come from {@see KeyBindingRegistry}, the same list
     * {@see \SugarCraft\Crush\Tui\KeyboardHandler} derives its claimed-chord
     * sets from, so this screen cannot describe a keyboard the app does not
     * have — the drift that made a hand-written cheat sheet not worth
     * shipping. Only {@see KeyBindingRegistry::live()} rows are painted;
     * a row marked dormant is a chord some handler claims but nothing acts
     * on, and promising it here would be worse than omitting it.
     *
     * Sizing follows {@see renderPermissionPrompt()}'s rule — the box's OWN
     * chrome comes off the width first — and lives in
     * {@see keyHelpGeometry()}, which is also what says when the box does not
     * fit at all and why neither of its two bounds has a floor.
     */
    private static function renderKeyHelp(Chat $chat, Theme $theme): string
    {
        $offset = $chat->keyHelp();
        if ($offset === null) {
            self::$keyHelpMaxOffset = 0;

            return '';
        }

        $geometry = self::keyHelpGeometry($chat);
        if ($geometry === null) {
            // No room for a border plus one cell of content. Nothing is drawn,
            // but Chat keeps the reference OPEN, so it appears the moment the
            // terminal (or the hosting pane) grows — the state is the user's,
            // only the space to paint it in is missing. That the reference is
            // still eating keystrokes is said on the status bar instead, by
            // renderStatusBar(), which asks this same method whether the box
            // fits: an open modal that is invisible AND silent is a stuck
            // terminal as far as the user can tell.
            self::$keyHelpMaxOffset = 0;

            return '';
        }

        [$width, $boxRows] = $geometry;

        // Content rows inside the border. The footer hint is the first thing to
        // go when there is only one row to spend, since a binding is what the
        // screen is for and the hint merely restates how to leave it.
        $viewport = $boxRows - 2;
        $showHint = $viewport > 1;

        $keyCol = 0;
        foreach (KeyBindingRegistry::live() as $binding) {
            $keyCol = max($keyCol, Width::of($binding->keys));
        }
        // A pathologically narrow box truncates the KEY so that a column is
        // always left for the description and the space before it: `$room`
        // below is `$width - $keyCol - 1`, and this cap is what keeps it
        // positive. Measured, the Enter row renders `E` at cols=5, `E S` at 7
        // and `En S` at 8 — the key losing characters, not the description
        // losing its column. (Under width 3 there is nothing to split and the
        // description does go.)
        //
        // Deliberately the reverse of what happens without it: Style::width()
        // TRUNCATES the assembled line rather than wrapping it (measured: a
        // 16-column row at width 4 renders `Ente`), so dropping the cap would
        // spend the whole box on the key and lose the description entirely.
        // Both fields staying present and aligned at every width is worth more
        // than four characters of key, since neither is legible down here.
        $keyCol = min($keyCol, max(1, $width - 2));

        $keyStyle = Style::new()->foreground($theme->userLabel)->bold();
        $headerStyle = Style::new()->foreground($theme->assistantLabel)->bold();
        $descStyle = Style::new()->foreground($theme->systemLabel);
        $hintStyle = Style::new()->foreground($theme->systemLabel)->faint();

        /** @var list<string> $lines already-styled content rows */
        $lines = [];
        foreach (KeyBindingRegistry::grouped() as $context => $bindings) {
            if ($lines !== []) {
                $lines[] = '';
            }
            $lines[] = $headerStyle->render(Width::truncate($context, $width));
            foreach ($bindings as $binding) {
                $key = Width::truncate($binding->keys, $keyCol);
                $pad = str_repeat(' ', max(0, $keyCol - Width::of($key)));
                $room = $width - $keyCol - 1;
                $desc = $room > 0 ? Width::truncate($binding->description, $room) : '';
                $lines[] = $keyStyle->render($key) . $pad . ' ' . $descStyle->render($desc);
            }
        }

        // Clamped here rather than trusted from Chat for the reason
        // renderView() re-clamps the transcript's offset: the stored value was
        // clamped against whatever frame was on screen when the key was
        // pressed, and this frame's geometry can differ (a resize, a narrower
        // hosted pane).
        $body = $viewport - ($showHint ? 1 : 0);
        self::$keyHelpMaxOffset = max(0, count($lines) - $body);
        $start = max(0, min($offset, self::$keyHelpMaxOffset));
        $visible = array_slice($lines, $start, $body);

        if ($showHint) {
            $visible[] = $hintStyle->render(Width::truncate(
                // The footer is where the MODAL's own keys are stated — the
                // rows above describe the app's ordinary keyboard, which is
                // not what those keys do while this screen is up. The wheel
                // belongs here for the same reason: with the reference open it
                // scrolls the reference (see Chat::scrollTranscript()).
                //
                // "? closes and types" is the one behaviour a reader would not
                // guess and would be annoyed to discover by accident, so it is
                // stated rather than left to the code: pressing "?" a second
                // time dismisses this screen AND puts the literal character in
                // the input box, which is what makes a message beginning with
                // "?" typeable (Chat::handleKeyHelpKey() carries the reasoning).
                // Anyone who wants a clean dismissal has the Esc named first.
                //
                // Widths, measured with Width::of against KEY_HELP_COLS = 64,
                // the box's widest content: 63 columns for the scrolling form
                // and 35 for the other, so one column of headroom. Thin, and
                // asserted rather than trusted —
                // KeyHelpTest::testTheFooterFitsTheBoxWithARealMarginAndLosesItsTailFirst().
                //
                // Truncating this line is the cheapest truncation in the box
                // because it loses no ROW of the reference, but it is not free
                // and an earlier version of this comment implied it was: the
                // footer loses its own clauses, and measured, by cols 14 it
                // reads just `Esc closes` with the `?` clause gone. Clause
                // ORDER is what makes that acceptable — Esc first so it is the
                // last thing standing, the scroll clause last so a narrow box
                // spends it first.
                self::$keyHelpMaxOffset > 0
                    ? 'Esc closes · ? closes and types "?" · ↑↓ PgUp/PgDn wheel scroll'
                    : 'Esc closes · ? closes and types "?"',
                $width,
            ));
        }

        return Style::new()
            ->border(Border::rounded()->withTitle(' keyboard shortcuts '))
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->width($width)
            ->render(implode("\n", $visible));
    }

    /**
     * The blocking permission prompt's modal (crush_feat.md §1 E2, the
     * rendering half), composited over the whole frame by {@see render()}
     * through the same {@see Veil} mechanism the Ctrl+P palette already uses
     * rather than a second overlay path. Returns '' (nothing composited)
     * when no prompt is blocking the turn - see
     * {@see Chat::pendingPermission()}.
     *
     * Shows three things, in the order a user needs them: what is being
     * asked for (the tool call, through the same
     * {@see Message::describeToolCall()} label the running placeholder and
     * the finished marker use, so the same call reads identically in all
     * three places), why it was stopped (the hook's own question), and how
     * to answer it. The answer keys are spelled out because this modal is
     * the ONLY place they appear - {@see renderStatusBar()}'s help text is
     * about the normal input line, and while a prompt is up none of those
     * keys do what it says.
     *
     * And WHICH keys is a function of the prompt's stage
     * ({@see \SugarCraft\Crush\Chat::permissionStage()}), because the same
     * letters do different things in each. That is not decoration: a prompt
     * disarmed by a stray keystroke looks identical to a live one, so without
     * the {@see PERMISSION_DISARMED_NOTICE} half a user would press `y`, watch
     * nothing happen, and have no way to find out that Enter is the way back.
     * The confirm half is the same argument in the other direction - `a` no
     * longer grants, so a modal that kept saying it did would read as a bug.
     *
     * Everything shown here is untrusted: a hook's message and a tool call's
     * arguments are both model-authored text, so both go through
     * {@see Sanitize::untrusted()} before reaching the terminal - a prompt
     * that could smuggle ESC sequences would let the very call being gated
     * repaint the dialog asking about it.
     */
    private static function renderPermissionPrompt(Chat $chat, Theme $theme): string
    {
        $request = $chat->pendingPermission();
        if ($request === null) {
            return '';
        }

        $call = $request->toolCall;
        // Never wider than the terminal: a modal that overflows $cols would be
        // wrapped by the terminal itself, which breaks the one-line-per-row
        // assumption render()'s viewport clipping is built on.
        $inner = max(20, min(self::PERMISSION_MODAL_COLS, $chat->cols() - self::SHELL_CHROME_COLS));

        $lines = [
            Style::new()->foreground($theme->userLabel)->bold()
                ->render('🔒 ' . Sanitize::untrusted($call->name)),
            Style::new()->foreground($theme->assistantLabel)
                ->render(self::wrapPermissionText(Message::describeToolCall($call), $inner)),
        ];

        $prompt = self::wrapPermissionText($request->prompt, $inner);
        if ($prompt !== '') {
            $lines[] = '';
            $lines[] = Style::new()->foreground($theme->systemLabel)->render($prompt);
        }

        $stage = $chat->permissionStage();

        // The confirm REPLACES the question's own keys rather than being added
        // under them: while it is up those keys do not work, and a modal
        // showing two live meanings for `y` at once is the misreading that
        // would turn a session grant into a slip again.
        if ($stage === PermissionPromptStage::ConfirmingAlways) {
            $lines[] = '';
            $lines[] = Style::new()->foreground($theme->userLabel)->bold()->render(
                self::wrapPermissionText(
                    'Allow every later ' . $call->name . ' call this session?',
                    $inner,
                ),
            );
        }

        if ($stage === PermissionPromptStage::Disarmed) {
            $lines[] = '';
            $lines[] = Style::new()->foreground($theme->systemLabel)->bold()->render(
                self::wrapPermissionText(self::PERMISSION_DISARMED_NOTICE, $inner),
            );
        }

        $options = match ($stage) {
            PermissionPromptStage::ConfirmingAlways => self::PERMISSION_CONFIRM_OPTIONS,
            PermissionPromptStage::Disarmed => self::PERMISSION_DISARMED_OPTIONS,
            PermissionPromptStage::Armed => self::PERMISSION_OPTIONS,
        };

        $lines[] = '';
        foreach ($options as [$keys, $label]) {
            $lines[] = Style::new()->foreground($theme->userLabel)->bold()->render($keys)
                . ' ' . Style::new()->foreground($theme->systemLabel)->faint()
                    ->render(self::wrapPermissionText($label, max(1, $inner - Width::string($keys) - 1)));
        }

        return Style::new()
            ->border(Border::rounded()->withTitle(' permission required '))
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->width($inner)
            ->render(implode("\n", $lines));
    }

    /**
     * Right-pad `$frame`'s lines so an overlay of `$overlay`'s width composites
     * onto it without being clipped, never past `$cols`.
     *
     * {@see Veil::composite()} derives its canvas from the background's widest
     * line, so a frame whose content happens to be narrow silently truncates
     * any wider overlay. Padding is applied only when an overlay is actually
     * being composited, so a plain frame keeps its existing ragged-right shape
     * (and the trailing-space-free output every other renderer test asserts on).
     */
    private static function padForOverlay(string $frame, string $overlay, int $cols): string
    {
        $lines = explode("\n", $frame);

        $frameWidth = 0;
        foreach ($lines as $line) {
            $frameWidth = max($frameWidth, Width::string($line));
        }

        $overlayWidth = 0;
        foreach (explode("\n", $overlay) as $line) {
            $overlayWidth = max($overlayWidth, Width::string($line));
        }

        $target = min(max(1, $cols), max($frameWidth, $overlayWidth));
        if ($target <= $frameWidth) {
            return $frame;
        }

        foreach ($lines as $index => $line) {
            $pad = $target - Width::string($line);
            if ($pad > 0) {
                $lines[$index] = $line . str_repeat(' ', $pad);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Columns to shift a centred overlay LEFT so its right edge cannot land
     * past column `$cols`. Never positive: an overlay that already fits stays
     * exactly where {@see Veil::composite()} centred it.
     *
     * Veil centres against `Width::string()` of the backdrop, and at this
     * point the frame still carries the zone sentinels {@see scanRoot()} only
     * strips later. Those sentinel cells count as columns, so a frame whose
     * visible width is 62 measures 84 whenever the status bar advertises its
     * `pane:menu` zone, and Veil centres the overlay as though the terminal
     * were that wide - pushing the right edge off-screen. An over-wide row is
     * exactly the absolute-cursorTo row collision {@see render()}'s tail clip
     * exists to prevent (the diff renderer paints one logical line per
     * physical row), so the centre is clamped rather than trusted.
     */
    private static function overlayLeftShift(string $backdrop, string $overlay, int $cols): int
    {
        $backdropWidth = 0;
        foreach (explode("\n", $backdrop) as $line) {
            $backdropWidth = max($backdropWidth, Width::string($line));
        }

        $overlayWidth = 0;
        foreach (explode("\n", $overlay) as $line) {
            $overlayWidth = max($overlayWidth, Width::string($line));
        }

        $centred = Position::CENTER->xOffset($overlayWidth, $backdropWidth);

        return min(0, max(0, $cols - $overlayWidth) - $centred);
    }

    /**
     * Sanitize, hard-wrap and clip free text for the permission modal.
     *
     * Wrapping happens here rather than being left to `Style::width()`
     * because that pads short lines to the modal width but does not break
     * long ones, and a single over-wide row inside a bordered box breaks the
     * border and (per the fixed-viewport clipping in {@see render()}) the
     * row accounting around it. `wordwrap()`'s cut flag is on so an unbroken
     * token - a long path or a base64 blob in a tool argument - wraps
     * instead of running off the edge.
     */
    private static function wrapPermissionText(string $text, int $cols): string
    {
        $clean = trim(Sanitize::untrusted($text));
        if ($clean === '') {
            return '';
        }

        $rows = [];
        foreach (explode("\n", $clean) as $line) {
            foreach (explode("\n", wordwrap($line, $cols, "\n", true)) as $wrapped) {
                $rows[] = $wrapped;
            }
        }

        if (count($rows) > self::PERMISSION_PROMPT_MAX_ROWS) {
            $hidden = count($rows) - self::PERMISSION_PROMPT_MAX_ROWS;
            $rows = array_slice($rows, 0, self::PERMISSION_PROMPT_MAX_ROWS);
            $rows[] = "… {$hidden} more lines";
        }

        return implode("\n", $rows);
    }

    private static function renderInput(Chat $chat, Theme $theme): string
    {
        $cursor = $chat->inFlight ? '' : '█';
        // The in-progress input buffer is untrusted keystroke data (e.g. a
        // bracketed-paste dump can smuggle ESC/C0/DEL). Strip it before it hits
        // the terminal so a paste can't inject control sequences at draw time.
        $body = "> " . self::untrusted($chat->inputBuf) . $cursor;
        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render($body);
    }
}
