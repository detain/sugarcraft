<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\View;
use SugarCraft\Mosaic\ImageLayer;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Shine\Renderer as Markdown;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentStatusBar;
use SugarCraft\Crush\Tui\AgentViewPane;

/**
 * Pure view function for {@see Chat} — the renderer actually reached by a
 * real user running `bin/sugarcrush` (`Chat::view()` calls
 * {@see self::render()}). `src/Tui/Renderer.php` + its `App`-keyed
 * Pane/Component system is a second, parallel renderer that nothing in the
 * live path ever constructs; this class is deliberately kept independent of
 * it (see the "R20 wiring decision" note below).
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
 * ### R20.fix: `agentManager` is not yet populated in production
 *
 * The rendering below is only reachable when `Chat::agentManager()` is
 * non-null. Today, `SugarCraft\Crush\Cli\Bootstrap::chat()` — the
 * construction path `bin/sugarcrush` actually runs — never passes an
 * `agentManager:` argument (constructing a real one needs a
 * `ProviderInterface` + `SkillRegistry`, which `Bootstrap::backend()`
 * builds internally but does not currently expose for this purpose), so
 * `renderAgentView()` always returns `''` for a real `bin/sugarcrush` user
 * regardless of config. This is honestly a currently-unreachable code path
 * pending that follow-up wiring in `Bootstrap.php` (not in this item's file
 * scope) — it is exercised today only by tests that construct
 * `new Chat(agentManager: ...)` directly. `Chat::handleAgentsCommand()`
 * (and the Ctrl+A shortcut that dispatches through it) degrades to a
 * "not configured" message rather than throwing when `agentManager` is
 * null, so this gap is inert rather than crashing — see that method's
 * docblock.
 *
 * Only `Agent::isActive`/`name`/`description` are real, live data from that
 * path — `AgentWorkerPool`/`AgentManager`'s public API (deliberately not
 * touched by this item; both are out of its file scope) exposes only
 * aggregate counts (`getActiveCount()`/`getQueueSize()`), not a per-agent
 * live output buffer, elapsed time, or token/cost accounting. So
 * `elapsedSeconds`/`tokensUsed`/`costUsd` are honestly reported as `0`
 * rather than fabricated, and {@see \SugarCraft\Crush\Tui\AgentOutputPane}
 * (which needs a real streaming output buffer) and the P5.S7/S8 split-pane
 * renderer (`self::renderWithSplit()`/`renderForCurrentEnvironment()` on
 * `Tui\Renderer`, meant for laying out *multiple* agents' live output side
 * by side) are explicitly NOT wired into `render()` here — with no real
 * per-agent output text to show, a split view would only ever display empty
 * tiles, which is worse than the honest single-column status line this
 * renders instead. Wiring either one for real needs a public
 * "current live output buffer" accessor on `AgentManager`/`AgentWorkerPool`
 * first, which is out of scope for this pass (those files are not in R20's
 * file list). `src/Tui/Components/AgentsPane.php` — also in R20's file list
 * — was left unmodified for the same reason `Tui\Renderer.php` itself is
 * untouched: it belongs entirely to the disconnected `App`-keyed system, so
 * fixing its stub body would not make anything reachable from this, the
 * live, path.
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
 * ### R20.fix: no production code path ever calls `createSession()`
 *
 * `renderSessionTabStrip()` reads real rows from
 * {@see \SugarCraft\Crush\Session\SessionStore::listSessions()}, but nothing
 * in `src/` or `bin/sugarcrush` ever calls
 * `SessionStore::createSession()`/`EnhancedSessionStore::createSession()` —
 * `Chat::init()` returns no startup `Cmd` that would create one either. So
 * `listSessions()` returns `[]` for the entire lifetime of a real
 * `bin/sugarcrush` process today, independent of the `currentSessionId`
 * gap documented above: even a hypothetical fix that seeded a
 * `currentSessionId` into `Bootstrap::chat()` would still show a tab strip
 * with zero rows, because no session row would exist on disk for it to
 * point at. `count($rows) < 2` already degrades this to `''` rather than
 * rendering an empty/malformed strip, so this is inert, not broken — but it
 * is a real gap, and the tests exercising this method
 * (`RendererTest::testRendersSessionTabStripWithMultipleSessionsAndBracketsCurrent`)
 * only do so by constructing a `SessionStore` and calling `createSession()`
 * directly, a path no production code takes. Wiring an actual session-create
 * call into `Bootstrap::chat()`/`Chat::init()` is out of this item's file
 * scope (`Bootstrap.php` is not in R20's file list) and is left as follow-up
 * work alongside the `currentSessionId` seeding noted above.
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
     * Columns the shell's border + padding(1, 2) consume, subtracted before
     * anything inside it is truncated to width.
     */
    private const SHELL_CHROME_COLS = 6;

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
     * The answer keys {@see renderPermissionPrompt()} advertises, as
     * `[keys, label]` pairs.
     *
     * Deliberately a table rather than a formatted string literal: it has to
     * stay in lockstep with `Chat::handlePermissionKey()`'s match arms, and a
     * list of the exact accepted keys is far easier to check against that
     * match than prose is. Only the keys that map to a
     * {@see \SugarCraft\Crush\Permissions\PermissionReply} are listed -
     * every other key is ignored there, so advertising anything else would
     * promise an answer that never arrives.
     */
    private const PERMISSION_OPTIONS = [
        ['y', 'allow once'],
        ['a', 'allow always (this session)'],
        ['n / Esc', 'reject'],
    ];

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
        $body = self::renderHistory(
            $chat->history,
            $theme,
            max(20, $chat->cols() - self::SHELL_CHROME_COLS),
            $images,
            $chat->mosaic(),
            // Row budget for a single picture: anything taller is clipped off
            // the frame's tail anyway, so encoding it would be pure waste.
            max(1, $chat->rows() - 2),
        );
        if ($chat->inFlight) {
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
        $rows = $chat->rows();
        $available = max(1, $rows - 1);
        $contentLines = explode("\n", $content);
        if (count($contentLines) > $available) {
            $contentLines = array_slice($contentLines, -$available);
        } else {
            while (count($contentLines) < $available) {
                $contentLines[] = '';
            }
        }

        $frame = implode("\n", $contentLines) . "\n" . self::renderStatusBar($chat);

        // A blocking permission prompt takes the overlay slot away from the
        // palette while it is up, because Chat::update() routes every
        // keystroke to the prompt first: showing a palette the keyboard no
        // longer drives would misrepresent what the next key does.
        $overlay = self::renderPermissionPrompt($chat, $theme);
        if ($overlay === '') {
            $overlay = self::renderPalette($chat, $theme);
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
            $frame = Veil::new()->withBackdrop(50)->composite(
                $overlay,
                self::padForOverlay($frame, $overlay, $chat->cols()),
                Position::CENTER,
                Position::CENTER,
            );
        }

        return new View($frame, images: $images->placements());
    }

    /**
     * The bottom status bar: the existing processing indicator/help text,
     * plus a context-usage percentage from {@see Chat::contextUsagePercent()}
     * so a user can see how full the context window is without running
     * /compact speculatively.
     */
    private static function renderStatusBar(Chat $chat): string
    {
        $processing = $chat->inFlight
            ? '⠴ thinking… · Esc Esc to cancel'
            : 'Enter to send · Ctrl+P menu · /exit or ^C to quit';
        $percent = (int) round($chat->contextUsagePercent() * 100);

        return "{$percent}% context · {$processing}";
    }

    /**
     * Render the agent status line + agent list pane, or '' when Chat has
     * no AgentManager or the manager has no active agents. See the "R20
     * wiring decision" note on this class's docblock for why the fields
     * beyond name/status/operation are 0 rather than fabricated, and why
     * AgentOutputPane / the split-pane renderer are not called here.
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

        $states = array_map(self::agentDisplayState(...), $agents);

        $cols = $chat->cols();
        $width = max(40, $cols - 4);

        return AgentStatusBar::render($states)
            . "\n" . AgentViewPane::render($states, -1, $width, self::AGENT_VIEW_MAX_ROWS);
    }

    /**
     * Map a real registered {@see Agent} to the display-state shape
     * AgentStatusBar/AgentViewPane render. elapsedSeconds/tokensUsed/costUsd
     * are 0 — Chat's AgentManager/AgentWorkerPool accessors expose no
     * per-agent live telemetry to source real values from (see class
     * docblock); reporting 0 is honest, not fabricated.
     */
    private static function agentDisplayState(Agent $agent): AgentDisplayState
    {
        return AgentDisplayState::new(
            name: $agent->name,
            status: $agent->isActive ? 'working' : 'stopped',
            operation: $agent->description,
            elapsedSeconds: 0,
            tokensUsed: 0,
            costUsd: 0.0,
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
            $labels[] = ($id !== '' && $id === $current) ? "[{$name}]" : " {$name} ";
        }

        return implode('|', $labels);
    }

    /**
     * @param list<Message> $history
     * @param int           $width  usable columns inside the shell's border +
     *                              padding, so nested boxes (tool diffs) can
     *                              truncate rather than wrap into a second row
     * @param ImageLayer    $images this frame's pixel-graphics layer, threaded
     *                              down to {@see renderToolImage()}
     * @param Mosaic|null   $mosaic the probe-once terminal image capability off
     *                              {@see Chat::mosaic()}; null disables images
     * @param int           $imageRows tallest cell box a single tool image may
     *                              be encoded at, see {@see renderToolImage()}
     */
    private static function renderHistory(array $history, Theme $theme, int $width, ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
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
                $blocks[] = self::renderToolResults($msg, $theme, $width, $images, $mosaic, $imageRows);

                continue;
            }
            if ($msg->pendingToolCallId !== null) {
                $blocks[] = self::renderPendingToolCall($msg, $theme);

                continue;
            }
            $blocks[] = match ($msg->role) {
                Role::User      => Style::new()->foreground($theme->userLabel)->bold()->render('user>') . " " . Sanitize::untrusted($msg->content),
                Role::Assistant => self::renderAssistantTurn($msg, $theme, $md),
                Role::System    => Style::new()->foreground($theme->systemLabel)->faint()->render("system: " . Sanitize::untrusted($msg->content)),
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
        $body = trim($md->render($msg->content));

        if ($msg->reasoning === null || trim($msg->reasoning) === '') {
            return $label . "\n" . $body;
        }

        return $label . "\n" . self::renderReasoning($msg->reasoning, $theme) . "\n" . $body;
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
        $flat = trim(preg_replace('/\s+/', ' ', Sanitize::untrusted($reasoning)) ?? '');
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
     * itself painted below the marker, via {@see renderToolImage()}
     * (crush_feat.md §9 E3).
     */
    private static function renderToolResults(Message $msg, Theme $theme, int $width, ImageLayer $images, ?Mosaic $mosaic, int $imageRows): string
    {
        $lines = [];
        foreach ($msg->toolResults as $result) {
            $status = $result->isError()
                ? Style::new()->foreground($theme->systemLabel)->bold()->render('✗ error')
                : Style::new()->foreground($theme->assistantLabel)->bold()->render('✓ ok');
            $label = Style::new()->foreground($theme->systemLabel)->faint()->render('🔧 tool: ' . $result->name) . ' ' . $status;
            $body = Sanitize::untrusted($result->isError() ? ($result->error ?? '') : $result->result);
            $block = $body === '' ? $label : $label . "\n" . $body;

            if ($result->hasDiff()) {
                $block .= "\n" . self::renderDiff((string) $result->diff, $theme, $width);
            }

            if ($result->hasImage()) {
                $picture = self::renderToolImage($result, $theme, $width, $images, $mosaic, $imageRows);
                if ($picture !== '') {
                    $block .= "\n" . $picture;
                }
            }

            $lines[] = $block;
        }

        return implode("\n\n", $lines);
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
     * Paint a raw unified diff (`--- a/…` / `+++ b/…` / `@@ … @@` / ` `+`/`-`
     * lines, exactly what `diff -u` emits) as a bordered, colour-coded block.
     *
     * Additions/removals are coloured with bare ANSI green/red rather than a
     * {@see Theme} field: every theme in the palette agrees on what "added"
     * and "removed" look like, and the diff has to stay readable even under
     * the `ansi` theme, which has no room for two more accent colours.
     *
     * Every line is {@see Sanitize::untrusted()}-stripped before display -
     * diff bodies are verbatim file contents, so an edited file containing a
     * raw ESC would otherwise forge SGR straight onto the terminal wire - then
     * hard-truncated to $width so the frame keeps its one-logical-line-per-row
     * invariant (candy-core's Renderer repaints by absolute row; a wrapped
     * line silently shifts every row below it). The row count is capped at
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

        $painted = [];
        foreach ($rows as $row) {
            $text = Width::truncate(Sanitize::untrusted($row), $inner);
            $painted[] = self::styleDiffLine($text, $theme)->render($text);
        }

        if ($overflow > 0) {
            $trailer = Width::truncate("… {$overflow} more diff line" . ($overflow === 1 ? '' : 's'), $inner);
            $painted[] = Style::new()->foreground($theme->systemLabel)->faint()->render($trailer);
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
     */
    private static function styleDiffLine(string $line, Theme $theme): Style
    {
        if (str_starts_with($line, '--- ') || str_starts_with($line, '+++ ')) {
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

        return $spinner . ' ' . Style::new()->foreground($theme->systemLabel)->faint()->render('running: ' . $msg->content);
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
     * The Ctrl+P command palette's content, composited over the whole frame
     * by {@see render()} via {@see Veil}. Returns '' (nothing composited)
     * when the palette is closed - see {@see Chat::palette()}.
     */
    private static function renderPalette(Chat $chat, Theme $theme): string
    {
        $palette = $chat->palette();
        if ($palette === null) {
            return '';
        }

        $matches = $chat->paletteMatches();
        $selected = $palette->selectedIndex;

        $lines = ['🔍 ' . Sanitize::untrusted($palette->query) . '█', ''];
        if ($matches === []) {
            $lines[] = Style::new()->foreground($theme->systemLabel)->faint()->render('No matches');
        } else {
            foreach ($matches as $index => $label) {
                $lines[] = $index === $selected
                    ? Style::new()->foreground($theme->userLabel)->bold()->render('▸ ' . $label)
                    : Style::new()->foreground($theme->systemLabel)->render('  ' . $label);
            }
        }

        $title = match ($palette->mode) {
            'providers' => ' switch model ',
            'themes' => ' switch theme ',
            default => ' command palette ',
        };

        return Style::new()
            ->border(Border::rounded()->withTitle($title))
            ->borderForeground($theme->border)
            ->padding(1, 2)
            ->width(50)
            ->render(implode("\n", $lines));
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

        $lines[] = '';
        foreach (self::PERMISSION_OPTIONS as [$keys, $label]) {
            $lines[] = Style::new()->foreground($theme->userLabel)->bold()->render($keys)
                . ' ' . Style::new()->foreground($theme->systemLabel)->faint()->render($label);
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
        $body = "> " . Sanitize::untrusted($chat->inputBuf) . $cursor;
        return Style::new()
            ->border(Border::normal())
            ->borderForeground($theme->border)
            ->padding(0, 1)
            ->render($body);
    }
}
