<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
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

    public static function render(Chat $chat): string
    {
        $theme = $chat->theme();
        $body = self::renderHistory($chat->history, $theme, max(20, $chat->cols() - self::SHELL_CHROME_COLS));
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

        $palette = self::renderPalette($chat, $theme);
        if ($palette !== '') {
            // A fresh Veil per render call (rather than one persisted on
            // Chat) means its own frame-diffing never kicks in - fine here,
            // since Chat already does its own diffing at a higher level in
            // view() and double-diffing isn't needed for correctness.
            $frame = Veil::new()->withBackdrop(50)->composite($palette, $frame, Position::CENTER, Position::CENTER);
        }

        return $frame;
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
     * @param int           $width usable columns inside the shell's border +
     *                             padding, so nested boxes (tool diffs) can
     *                             truncate rather than wrap into a second row
     */
    private static function renderHistory(array $history, Theme $theme, int $width): string
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
                $blocks[] = self::renderToolResults($msg, $theme, $width);

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
     */
    private static function renderToolResults(Message $msg, Theme $theme, int $width): string
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

            $lines[] = $block;
        }

        return implode("\n\n", $lines);
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
