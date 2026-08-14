<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tui\Components;

use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Agents\Agent;
use SugarCraft\Crush\App\App;
use SugarCraft\Crush\Sessions\BackgroundSession;
use SugarCraft\Crush\Sessions\BackgroundSessionStatus;
use SugarCraft\Crush\Tui\AgentDisplayState;
use SugarCraft\Crush\Tui\AgentOutputPane;
use SugarCraft\Crush\Tui\AgentOutputState;
use SugarCraft\Crush\Tui\AgentStatusBar;
use SugarCraft\Crush\Tui\AgentViewMode;
use SugarCraft\Crush\Tui\AgentViewPane;
use SugarCraft\Crush\Tui\Mode;
use SugarCraft\Crush\Tui\Pane;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Sprinkles\Style;
use SugarCraft\Veil\Position;
use SugarCraft\Veil\Veil;

/**
 * The full-pane agent dashboard — sugar-crush's answer to `claude agents`
 * (crush_feat.md §5 E5).
 *
 * Renders every live worker in ONE ordered list: the {@see Agent}s registered
 * on {@see \SugarCraft\Crush\Agents\AgentManager} followed by the
 * {@see BackgroundSession}s owned by
 * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor}, grouped for display
 * into Working / Needs input / Ready / Completed.
 *
 * Three design constraints this class exists to satisfy:
 *
 * 1. **Reuse, don't reinvent.** Rows are drawn by
 *    {@see AgentStatusBar::renderAgentLine()}, the empty state by
 *    {@see AgentViewPane::render()}, and the Space peek overlay by
 *    {@see AgentOutputPane::render()} composited through {@see Veil} — the
 *    same overlay mechanism the Ctrl+P palette and the permission modal use.
 *    The older `Tui\Components\AgentsPane` stub (a hardcoded
 *    "(no active agents)" box) is left alone rather than extended: it is the
 *    sidebar-sized widget, this is the full-pane one.
 *
 * 2. **Stable indices.** {@see entries()} is the single ordering authority.
 *    Position N in that list is slot N+1 on screen and the target of
 *    `Alt+<N+1>` ({@see indexForSlot()}), and the slot label is printed on the
 *    row so the mapping is visible rather than guessed. Display grouping
 *    reorders the ROWS, never the slots.
 *
 * 3. **Bounded output.** Like {@see FilesPane}, this never emits more rows
 *    than the caller's height budget — an unbounded pane pushes the input
 *    line and status bar off-screen, and candy-core's absolute-cursor
 *    repaint then collides distinct logical rows onto one physical line
 *    (see {@see \SugarCraft\Crush\Tui\Renderer::renderView()} invariant 1).
 *    Peek output is tailed by BYTE budget before it is split into lines, so
 *    the per-frame cost is constant no matter how long a session has been
 *    streaming.
 *
 * 4. **Untrusted in, one clean row out.** Every string this pane draws is
 *    attacker-influenced, and the shell reaches it without passing through
 *    {@see \SugarCraft\Crush\Renderer}'s sanitiser — so {@see safe()} is the
 *    boundary, applied to each value as its display state is built.
 */
final class AgentDashboardPane
{
    /** Jump slots `Alt+1`..`Alt+9` address; entries past this have no chord. */
    public const MAX_JUMP_SLOTS = 9;

    /**
     * Bytes of a session's output the peek overlay reads, counted from the
     * END. Splitting the whole buffer every frame would make repaint cost
     * grow with session length — the exact per-frame-work-proportional-to-
     * history trap W3.M4 fixed in {@see FilesPane}.
     */
    private const PEEK_TAIL_BYTES = 4096;

    /** Output lines carried on a peek entry (the pane itself shows fewer). */
    private const PEEK_MAX_LINES = 8;

    /**
     * Display groups, in render order, keyed by the group id
     * {@see group()} returns.
     */
    private const GROUP_LABELS = [
        'working'     => 'Working',
        'needs-input' => 'Needs input',
        'ready'       => 'Ready',
        'completed'   => 'Completed',
    ];

    /**
     * Every live worker as a display state, in stable slot order.
     *
     * Registered agents come first (registration order), then background
     * sessions (spawn order). The index into this list IS the stable index:
     * a session keeps its slot for as long as it stays in the supervisor's
     * active set, so `Alt+3` means the same session between two frames.
     *
     * Returns `[]` when the App hosts no Chat, or the Chat has neither an
     * AgentManager nor a BackgroundSupervisor — an honest empty dashboard,
     * not a fabricated one.
     *
     * Disclosure about the Completed group: both sources are ACTIVE-only —
     * `AgentManager::active()` filters to `isActive`, and
     * `BackgroundSupervisor::getActiveSessions()` drops Completed/Failed/
     * Stopped. So the only worker that reaches the Completed group today is a
     * timed-out session, which the supervisor still counts as active. A
     * finished-session history would need a retention list on the supervisor,
     * which is not this step's file scope.
     *
     * @return list<AgentOutputState>
     */
    public static function entries(App $a): array
    {
        $chat = $a->chat;
        if ($chat === null) {
            return [];
        }

        $entries = [];

        $manager = $chat->agentManager();
        if ($manager !== null) {
            foreach ($manager->active() as $agent) {
                $entries[] = self::agentEntry($agent, $manager);
            }
        }

        $supervisor = $chat->backgroundSupervisor();
        if ($supervisor !== null) {
            foreach ($supervisor->getActiveSessions() as $session) {
                $entries[] = self::sessionEntry($session);
            }
        }

        return $entries;
    }

    /**
     * The entries index a `Alt+<slot>` chord addresses, or -1 when the slot
     * is out of range (no such agent, or beyond {@see MAX_JUMP_SLOTS}).
     *
     * Slots are 1-based because that is how they are labelled on screen.
     */
    public static function indexForSlot(App $a, int $slot): int
    {
        if ($slot < 1 || $slot > self::MAX_JUMP_SLOTS) {
            return -1;
        }

        return $slot <= count(self::entries($a)) ? $slot - 1 : -1;
    }

    /**
     * The display group a status keyword belongs to.
     *
     * The keyword vocabulary is {@see AgentStatusBar}'s, so an unrecognised
     * status lands in Completed for the same reason it renders gray there:
     * "not something the user is waiting on".
     *
     * @return 'working'|'needs-input'|'ready'|'completed'
     */
    public static function group(string $status): string
    {
        return match (strtolower(trim($status))) {
            'working', 'streaming' => 'working',
            'waiting'              => 'needs-input',
            'pending'              => 'ready',
            default                => 'completed',
        };
    }

    /**
     * Render the dashboard, peek overlay included.
     *
     * @param int $width Total columns the pane may occupy, borders included.
     * @param int $rows  Total rows the pane may occupy, borders included.
     */
    public static function render(App $a, int $width, int $rows): string
    {
        $inner = max(20, $width - 2);
        // Two rows go to the box's own top and bottom border.
        $budget = max(1, $rows - 2);

        $entries = self::entries($a);
        if ($entries === []) {
            // AgentViewPane already owns the styled "(no active agents)" box.
            return AgentViewPane::render([], -1, $inner, $budget);
        }

        $frame = self::box(self::body($a, $entries, $inner, $budget), $a, $inner);

        $overlay = self::peekOverlay($a, $width, $rows);
        if ($overlay === '') {
            return $frame;
        }

        return Veil::new()->withBackdrop(50)->composite(
            $overlay,
            $frame,
            Position::CENTER,
            Position::CENTER,
        );
    }

    /**
     * The Space-triggered peek: a non-committing look at the selected
     * worker's latest output, without leaving the dashboard.
     *
     * Returns '' unless the shell is in {@see AgentViewMode::Peek} with a
     * selection that still resolves — a stale selection (the session ended
     * between frames) draws nothing rather than an empty box for an agent
     * that is gone.
     */
    public static function peekOverlay(App $a, int $width, int $rows): string
    {
        if ($a->agentViewMode !== AgentViewMode::Peek) {
            return '';
        }

        $entries = self::entries($a);
        $selected = $a->selectedAgentIndex;
        if ($selected < 0 || $selected >= count($entries)) {
            return '';
        }

        // Held well inside the backdrop: Veil clips the overlay to the
        // backdrop's widest line, and a peek as wide as the pane would lose
        // its right border.
        $overlayWidth = max(20, min($width - 8, 72));
        $overlayRows = max(3, min($rows - 4, 12));

        return AgentOutputPane::render($entries[$selected], $overlayWidth, $overlayRows, Mode::Peek);
    }

    /**
     * Group headings + slot-labelled rows, clipped to the height budget.
     *
     * @param list<AgentOutputState> $entries
     */
    private static function body(App $a, array $entries, int $inner, int $budget): string
    {
        $grouped = [];
        foreach ($entries as $index => $entry) {
            $grouped[self::group($entry->status)][] = [$index, $entry];
        }

        $lines = [];
        foreach (self::GROUP_LABELS as $groupId => $label) {
            if (!isset($grouped[$groupId])) {
                continue;
            }

            $members = $grouped[$groupId];
            $lines[] = Style::new()
                ->bold()
                ->foreground(AgentViewPane::statusColor($members[0][1]->status))
                ->render($label . ' (' . count($members) . ')');

            foreach ($members as [$index, $entry]) {
                $lines[] = self::row($index, $entry, $a->selectedAgentIndex === $index, $inner);
            }
        }

        return implode("\n", self::clip($lines, $budget, $inner));
    }

    /**
     * One dashboard row: `[N] ● name [status] operation  elapsed  usage`.
     *
     * The slot label is a plain ASCII digit on purpose. Private-Use
     * codepoints (U+E000–U+F8FF) are where candy-core's image markers and
     * candy-mouse's zone sentinels both live, so a decorative glyph from that
     * block here would be mistaken for one of them by
     * {@see \SugarCraft\Crush\Renderer::maskImageMarkers()}.
     */
    private static function row(int $index, AgentDisplayState $entry, bool $selected, int $inner): string
    {
        $slot = $index < self::MAX_JUMP_SLOTS ? '[' . ($index + 1) . ']' : '   ';
        $label = Style::new()
            ->foreground(Color::hex($selected ? '#e0af68' : '#565676'))
            ->render($slot);

        $line = $label . ' ' . AgentStatusBar::renderAgentLine($entry);

        return Width::string($line) > $inner ? Width::truncateAnsi($line, $inner) : $line;
    }

    /**
     * Hold $lines to $budget rows, trading the last row for an "N more"
     * trailer when there is anything left over.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private static function clip(array $lines, int $budget, int $inner): array
    {
        if (count($lines) <= $budget) {
            return $lines;
        }

        $kept = array_slice($lines, 0, max(0, $budget - 1));
        $hidden = count($lines) - count($kept);
        $trailer = Style::new()
            ->foreground(Color::hex('#565676'))
            ->render('… ' . $hidden . ' more');

        $kept[] = Width::string($trailer) > $inner ? Width::truncateAnsi($trailer, $inner) : $trailer;

        return $kept;
    }

    /**
     * Wrap the body in the pane box, focus-coloured the same way
     * {@see AgentsPane} colours the sidebar version so the two read as one
     * widget family.
     */
    private static function box(string $body, App $a, int $inner): string
    {
        $style = Style::new()
            ->border(Border::rounded()->withTitle(' agents '))
            ->padding(0, 1)
            ->width($inner);

        $style = $a->pane === Pane::Agents
            ? $style->borderForeground(Color::hex('#00ffaa'))
            : $style->borderForeground(Color::hex('#ff66aa'));

        return $style->render($body);
    }

    /**
     * A registered agent's display state, sourced from the manager's real
     * telemetry (W3.F2) rather than the placeholder zeros this pane's
     * predecessor reported.
     */
    private static function agentEntry(Agent $agent, \SugarCraft\Crush\Agents\AgentManager $manager): AgentOutputState
    {
        return AgentOutputState::fromDisplayState(
            AgentDisplayState::new(
                name: self::safe($agent->name),
                status: $agent->isActive ? 'working' : 'stopped',
                operation: self::safe($agent->description),
                // Telemetry lookups key off the RAW name — sanitising is a
                // display concern, and a sanitised key would miss the map.
                elapsedSeconds: $manager->elapsedSeconds($agent->name),
                tokensUsed: $manager->tokensUsed($agent->name),
                costUsd: $manager->costUsd($agent->name),
            ),
            model: self::safe($agent->model),
            // A registered agent has no output buffer of its own — its
            // sub-agents do, and {@see
            // \SugarCraft\Crush\Agents\AgentManager::liveOutput()} is the
            // accessor that rolls theirs up as it is produced (crush_code.md
            // Phase 1 item 1). Before it existed this was necessarily `[]`,
            // so a delegating agent's row was a header with no body while a
            // background session's row showed a live tail.
            outputBuffer: self::outputTail($manager->liveOutput($agent->name)),
        );
    }

    /** A background session's display state, with a byte-bounded output tail. */
    private static function sessionEntry(BackgroundSession $session): AgentOutputState
    {
        return AgentOutputState::fromDisplayState(
            AgentDisplayState::new(
                name: self::safe($session->name),
                status: self::sessionStatus($session->status),
                operation: self::safe($session->task),
                elapsedSeconds: $session->elapsedSeconds(),
                tokensUsed: $session->tokensUsed,
                costUsd: $session->costUsd,
            ),
            model: self::safe($session->agent->model),
            outputBuffer: self::outputTail($session->output),
        );
    }

    /**
     * Map a session's lifecycle status onto {@see AgentStatusBar}'s status
     * vocabulary, so both widgets colour the same state the same way.
     *
     * `Stalled` reads as `waiting` because a stalled session is precisely one
     * the user has to look at — the "Needs input" group.
     */
    private static function sessionStatus(BackgroundSessionStatus $status): string
    {
        return match ($status) {
            BackgroundSessionStatus::Running   => 'working',
            BackgroundSessionStatus::Streaming => 'streaming',
            BackgroundSessionStatus::Stalled   => 'waiting',
            BackgroundSessionStatus::Pending   => 'pending',
            BackgroundSessionStatus::Completed => 'completed',
            BackgroundSessionStatus::Stopped   => 'stopped',
            BackgroundSessionStatus::Failed,
            BackgroundSessionStatus::TimedOut  => 'failed',
        };
    }

    /**
     * The last few lines of a session's output.
     *
     * The byte tail is taken FIRST: `explode()` on a multi-megabyte buffer
     * allocates the whole thing every frame, and only the tail is ever shown.
     * A cut mid-line drops that partial first line rather than showing a
     * fragment that starts mid-word.
     *
     * Sanitising happens LAST, on the ≤{@see PEEK_MAX_LINES} lines that
     * actually reach the overlay, so the per-frame cost stays bounded no
     * matter how much a session has streamed.
     *
     * @return list<string>
     */
    private static function outputTail(string $output): array
    {
        if ($output === '') {
            return [];
        }

        $tail = strlen($output) > self::PEEK_TAIL_BYTES
            ? substr($output, -self::PEEK_TAIL_BYTES)
            : $output;

        $lines = explode("\n", rtrim($tail, "\n"));
        if (strlen($output) > self::PEEK_TAIL_BYTES && count($lines) > 1) {
            array_shift($lines);
        }

        return array_map(
            self::safe(...),
            array_values(array_slice($lines, -self::PEEK_MAX_LINES)),
        );
    }

    /**
     * This pane's untrusted-text boundary.
     *
     * Everything the dashboard draws — a session's `output` (byte-for-byte
     * what {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor} read off the
     * daemonised child's IPC socket, i.e. raw model and tool output), its
     * name/task, and an agent's name/description/model — is attacker-
     * influenced text going straight to a terminal. The chat path holds this
     * boundary in {@see \SugarCraft\Crush\Renderer}; the shell reaches this
     * pane WITHOUT passing through it ({@see \SugarCraft\Crush\Tui\Renderer}
     * only clips the finished frame), so the boundary has to live here.
     *
     * Two halves, both required:
     *
     * - {@see PaneLabel::of()} runs `Sanitize::untrusted()` (kills OSC title
     *   sets, `\x1b[2J` erases, SGR forgeries) and then collapses the
     *   control/whitespace `untrusted()` deliberately preserves, which is what
     *   keeps one entry to one row — an LF costs a row the height budget never
     *   reserved, and a lone CR lets session output overwrite the pane border.
     *   Losing an output line's indentation is the accepted price.
     * - {@see stripPrivateUse()} removes the Private-Use block, which
     *   `untrusted()` leaves alone because it is printable. Without it a
     *   session could echo candy-mouse's zone sentinels or candy-core's image
     *   markers back at us and forge a clickable region in our own frame.
     */
    private static function safe(string $raw): string
    {
        return PaneLabel::of(self::stripPrivateUse($raw));
    }

    /** Drop every Private-Use code point (U+E000–U+F8FF and the astral planes). */
    private static function stripPrivateUse(string $raw): string
    {
        $stripped = preg_replace('/\p{Co}/u', '', $raw);
        if ($stripped !== null) {
            return $stripped;
        }

        // Invalid UTF-8 bails the /u pattern; sweep the BMP Private-Use area's
        // literal UTF-8 byte ranges instead so malformed output — which a
        // byte-tail cut can produce on its own — cannot smuggle a sentinel in.
        return (string) preg_replace(
            '/\xEE[\x80-\xBF][\x80-\xBF]|\xEF[\x80-\xA3][\x80-\xBF]/',
            '',
            $raw,
        );
    }
}
