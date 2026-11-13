<?php

declare(strict_types=1);

namespace SugarCraft\SshLauncher;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\RawMode;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Tty;
use SugarCraft\Core\Util\Width;
use SugarCraft\Fuzzy\MatchResult;
use SugarCraft\Fuzzy\Matcher\SmithWatermanMatcher;

/**
 * Tiny terminal picker — renders marker-prefixed display lines
 * (selectedMarker() over displayLine()) of {@see Endpoint}s. The user
 * moves with ↑/↓, Home/End and PgUp/PgDn (j/k are query letters on
 * every row — the invert model, -060), narrows with type-to-search,
 * and picks with Enter. Returns the selected Endpoint,
 * or null on Esc or the cancel byte (0x03 — what a closed stdin maps to
 * as well). On a real terminal Ctrl-C raises SIGINT, which the picker
 * traps to restore the terminal before exiting 130.
 *
 * Why not the full SugarWidgets `List` widget? That's overkill for a
 * one-shot picker that we immediately replace with `pcntl_exec`.
 * This class only needs to draw a static list, read a few keys,
 * and exit fast — no event loop, no full Program lifecycle.
 *
 * Test seam: the streams are constructor arguments and tests subclass
 * this class overriding {@see setRawMode()} (and {@see detectSize()}
 * for the geometry pins) — there is no
 * `inputStream()` / `outputStream()` to override.
 */
class Picker
{
    /**
     * Palette bytes for the selected-row marker and the filter echo, as
     * exactly produced by Ansi::sgr(1,36)/Ansi::reset()/Ansi::sgr(36).
     * Byte-pinned by the picker tests (2026-10-23 — extracted from the
     * inline literals without changing a single emitted byte). -11 moved
     * them behind selectedMarker()/filterColor()/descriptionColor(): the
     * bytes are unchanged whenever ANSI is honored, and fall away to the
     * bare '▸ ' marker / plain text under NO_COLOR, CLICOLOR=0 or
     * TERM=dumb — the opt-outs the raw consts used to steamroll.
     */
    private const MARKER_COLORED = "\x1b[1;36m▸\x1b[0m ";
    private const MARKER_PLAIN = '▸ ';
    private const UNSELECTED_MARKER = "  ";
    private const FILTER_COLOR_COLORED = "\x1b[36m";
    /**
     * The description tier's dim grade (F-26): SGR 90 dark gray, not SGR 2
     * faint. Faint is near-invisible on many terminals (and renders as a
     * transparency trick on those that honor it), so the "dimmed text"
     * documented for descriptions was effectively hidden. Dark gray is the
     * usable dim grade the four-color palette always has.
     */
    private const DESCRIPTION_COLOR_COLORED = "\x1b[90m";
    /**
     * Rows the visible window shows at once (F-25) — a CAP, not the
     * window: on a short terminal the window shrinks to what fits above
     * the chrome (-002, and the old comment's "keeps the picker inside
     * any real window" claim was false for short-but-real ones). Ten
     * gives PgUp/PgDn a page to scroll on ordinary windows.
     */
    private const WINDOW_ROWS = 10;
    /**
     * Chrome rows a full frame can carry: title, filter, blank, help —
     * plus the (shown of total) count row and the (no matches) row when
     * each is visible. draw() budgets the exact count per frame; pageSpan
     * uses the worst case so a page never scrolls past a too-tight window.
     */
    private const MAX_CHROME_ROWS = 6;
    /**
     * Narrowest terminal the picker will run on: below this the chrome
     * cannot fit a single honest row and the frame would scramble
     * (-061) — pick() refuses (returns null, like a cancel) instead of
     * rendering a lie.
     */
    private const MIN_COLS = 12;

    /**
     * Microseconds the escape decoder waits for the follow-on bytes of a
     * burst after ESC (-017). The historical hard 50 ms mis-decoded an
     * `ESC [ A` split by tmux-under-load or WAN latency into a lone-ESC
     * cancel: exit 130 with the `[A` orphaned in the stream. 150 ms
     * absorbs fragmented bursts; the cost lands only on a deliberate
     * lone-Esc press (a still-visible-instant pause). `ESCDELAY`
     * (milliseconds — ncurses' name for the same knob) overrides, clamped
     * to [0, 5000] ms so a typo cannot wedge the picker.
     */
    private const ESC_FOLLOWUP_MICROSECONDS = 150_000;

    /**
     * Bracketed paste (-09). While the picker owns the screen it asks
     * the terminal to wrap pasted blocks in ESC [ 200 ~ … ESC [ 201 ~;
     * the payload then goes to the filter as text (CR/LF folded to
     * spaces) and NEVER dispatches an Enter. The belt catches the older
     * shape too: an Enter still riding a batch whose bytes are buffered
     * behind the last keystroke is paste tail, not intent — a
     * zero-timeout select, deterministic and immune to draw latency.
     */
    private const PASTE_ON  = "\x1b[?2004h";
    private const PASTE_OFF = "\x1b[?2004l";
    private const PASTE_START = "\x1b[200~";
    private const PASTE_END   = "\x1b[201~";

    /** @var resource */
    protected $in;
    /** @var resource */
    protected $out;
    private string $filter = '';
    private int $cursor = 0;
    private SmithWatermanMatcher $matcher;
    /**
     * Denominator for the "N of M" count row while filtering: total
     * endpoints handed to pick(), so `draw()` still takes its test-pinned
     * single argument (2026-10-23).
     */
    private int $totalEndpoints = 0;
    /**
     * Saved pcntl_async_signals() state so the SIGINT arm in
     * armCancelSignal() is strictly picker-scoped.
     */
    private ?bool $asyncSignalsBeforeArm = null;
    /**
     * -09 belt state: whether the last keystroke was filter text / an
     * Enter, and whether the real input stream still held bytes behind
     * it (batch-write shape). Reset per pick() session.
     */
    private bool $lastKeyWasText = false;
    private bool $lastKeyWasEnter = false;
    private bool $burstPending = false;
    /**
     * Terminal capability flags (-11), resolved from the environment at
     * the top of every pick(): colorOn gates the SGR palette, gate
     * addressable gates cursor addressing AND the alternate-buffer /
     * bracketed-paste switches (a truly dumb terminal honors none of
     * them). Defaults keep the historical always-ANSI shape for callers
     * that drive draw()/pick() machinery without the environment step.
     */
    private bool $colorOn = true;
    private bool $addressable = true;
    /** Terminal geometry in cells; re-probed at the top of every draw()
     * (-013: the old one-shot latch held the first size forever, so a
     * mid-pick resize re-stranded the frame). null = not probed yet.
     *
     * @var ?array{cols:?int, rows:?int}
     */
    private ?array $geometry = null;
    /** Set by the SIGWINCH handler; makes readKey() deliver a repaint wake. */
    private bool $resizePending = false;

    /**
     * @param resource|null $in
     * @param resource|null $out
     */
    public function __construct($in = null, $out = null)
    {
        $this->in  = $in  ?? STDIN;
        $this->out = $out ?? STDOUT;
        $this->matcher = new SmithWatermanMatcher();
    }

    /**
     * @param list<Endpoint> $endpoints
     */
    public function pick(array $endpoints): ?Endpoint
    {
        if ($endpoints === []) {
            return null;
        }
        // -061: refuse a terminal too narrow to hold the chrome. Nothing
        // is drawn and no raw mode is entered — the refusal behaves like
        // a cancel (pick() returns null, bin prints its cancel line),
        // which is the honest signal for "render impossible here".
        $probed = $this->detectSize() ?? ['cols' => null, 'rows' => null];
        if ($probed['cols'] !== null && $probed['cols'] < self::MIN_COLS) {
            return null;
        }
        $this->totalEndpoints = count($endpoints);
        // -062: pick() is re-entrant-clean. The historical contract was
        // one-shot (filter and cursor leaked across calls), and bin only
        // ever calls once — but the class is non-final with subclass
        // seams, so a reused picker must start from the same visible
        // state it had fresh, not mid-query from the previous session.
        $this->filter = '';
        $this->cursor = 0;
        $this->lastKeyWasText = false;
        $this->lastKeyWasEnter = false;
        $this->burstPending = false;
        $this->resolveTerminalCapability();
        // Arm BEFORE raw mode: setRawMode shells out to stty (fork+exec+
        // reap), and a Ctrl+C landing in that window would hit SIG_DFL —
        // process dies, finally never runs, echo stays off. A SIGINT
        // before raw entry is harmless (the handler's setRawMode(false)
        // is a no-op pair on a canonical tty).
        $this->armCancelSignal();
        $this->armResizeSignal();
        $this->setRawMode(true);
        try {
            // -08: own the alternate buffer for the session (CSI ?1049h
            // saves the main screen + cursor, and the matching ?1049l on
            // every exit path restores them byte-exactly). Entering
            // inside the try means the finally's leave covers every
            // return, throw and cancel shape.
            // -08/-11: the buffer switch and paste bracketing are
            // addressing features — a dumb terminal would only log them.
            if ($this->addressable) {
                fwrite($this->out, Ansi::altScreenEnter());
                // -09: ask the terminal to bracket pasted blocks while we
                // own the screen; PASTE_OFF rides every exit path below.
                fwrite($this->out, self::PASTE_ON);
            }
            while (true) {
                $matches = $this->filterMatches($endpoints);
                if ($this->cursor >= count($matches)) {
                    $this->cursor = max(0, count($matches) - 1);
                }
                if ($this->cursor < 0) {
                    $this->cursor = 0;
                }
                $this->draw($matches);

                $key = $this->readKey();
                // -09 belt input: is this keystroke the tail of a burst?
                $burstEnter = ($key === "\r" || $key === "\n")
                    && $this->enterLooksLikePasteTail();
                $this->noteKeystroke($key);
                switch ($key) {
                    case "\x03": /* cancel byte: stdin EOF, or ^C where SIGINT is blocked */
                    case "\x1b": /* ESC */
                        return null;
                    case "\r":
                    case "\n":
                        if ($burstEnter) {
                            // -09 belt: this Enter rode in behind the last
                            // keystroke's batch — it is the newline of a
                            // pasted line, not a connect intent. Fold it
                            // into the filter like the bracketed path does.
                            $this->filter .= ' ';
                            $this->cursor = 0;
                            continue 2;
                        }
                        if ($matches === []) {
                            continue 2;
                        }
                        return $matches[$this->cursor]['endpoint'];
                    case "\x1b[B":
                    case "\x1bOB": /* SS3 Down — DECCKM application-cursor mode (-020) */
                        if ($this->cursor < count($matches) - 1) {
                            $this->cursor++;
                        }
                        break;
                    case "\x1b[A":
                    case "\x1bOA": /* SS3 Up — DECCKM application-cursor mode (-020) */
                        if ($this->cursor > 0) {
                            $this->cursor--;
                        }
                        break;
                    case "\x1b[H":
                    case "\x1b[1~":
                    case "\x1b[7~":
                    case "\x1bOH": /* SS3 Home */
                        // F-25: readEscapeSequence decoded these keys all
                        // along; the switch silently dropped them. Home/End
                        // jump to the ends of the match list, PgUp/PgDn
                        // scroll a visible-window page.
                        $this->cursor = 0;
                        break;
                    case "\x1b[F":
                    case "\x1b[4~":
                    case "\x1b[8~":
                    case "\x1bOF": /* SS3 End */
                        $this->cursor = max(0, count($matches) - 1);
                        break;
                    case "\x1b[5~":
                    case "\x1bOP": /* SS3 PgUp */
                        $this->cursor = max(0, $this->cursor - $this->pageSpan($matches));
                        break;
                    case "\x1b[6~":
                    case "\x1bOQ": /* SS3 PgDn */
                        $this->cursor = $matches === []
                            ? 0
                            : min(count($matches) - 1, $this->cursor + $this->pageSpan($matches));
                        break;
                    case "\x7f": /* backspace */
                    case "\x08":
                        $before = $this->filter;
                        // Codepoint-granular trim. readKey() delivers whole
                        // UTF-8 codepoints, and the matcher/highlighter index
                        // the filter by codepoint too (sugar-fuzzy CharFold,
                        // see highlightLine()), so the delete unit must be the
                        // codepoint. A byte-wise substr here stranded dangling
                        // continuation bytes mid-character and echoed invalid
                        // UTF-8 to the terminal (mirrors sugar-quickpick
                        // 530c750f1). Trade-off stated honestly, same as
                        // there: a multi-codepoint grapheme such as '👍🏽'
                        // takes two backspaces to clear, because the skin-tone
                        // modifier is its own codepoint.
                        $this->filter = mb_substr($this->filter, 0, -1, 'UTF-8');
                        // Only reset cursor if the filter actually changed —
                        // on an empty filter, backspace is a true no-op and
                        // the current selection should be preserved.
                        if ($this->filter !== $before) {
                            $this->cursor = 0;
                        }
                        break;
                    case "\x15": /* Ctrl+U — clear the whole filter (-064) */
                        $this->filter = '';
                        $this->cursor = 0;
                        break;
                    case "\x17": /* Ctrl+W — delete one trailing filter word (-064) */
                        $before = $this->filter;
                        // House word-boundary class (shared with sugar-quickpick
                        // lineage): whitespace + the last run of non-space.
                        $this->filter = preg_replace('/\s*\S+\s*$/u', '', $this->filter) ?? '';
                        if ($this->filter !== $before) {
                            $this->cursor = 0;
                        }
                        break;
                    case self::PASTE_START:
                        // -09: bracketed payload. Consume everything up to
                        // the terminator as text (CR/LF folded to spaces,
                        // navigation fragments swallowed); never dispatch.
                        if ($this->readPasteBurst()) {
                            return null; // EOF mid-paste: cancel, don't hang
                        }
                        $this->cursor = 0;
                        continue 2;
                    default:
                        // Text intake. readKey() delivers whole UTF-8
                        // codepoints for typed characters and complete
                        // escape sequences (CSI / SS3 / Meta chords) for key
                        // events. Only control-free, well-formed text reaches
                        // the filter — ESC-bearing chords like "\x1bc" (RIS)
                        // or "\x1bp" (Alt+p) are consumed as opaque key
                        // events here and never accumulate as text.
                        if ($this->isTextKey($key)) {
                            $this->filter .= $key;
                            $this->cursor = 0;
                        }
                }
            }
        } finally {
            $this->setRawMode(false);
            $this->disarmCancelSignal();
            $this->disarmResizeSignal();
            // -08: leaving the alternate buffer restores the shell's screen
            // exactly — the historical hand-rolled cancel path (cursorTo +
            // eraseScreen) is gone, and both exits now share one truth:
            // the frame was never on the main screen at all. After a pick
            // ssh takes the screen over; after a cancel the shell prompt
            // returns untouched.
            // -09: paste bracketing unwinds before the buffer switch, on
            // the same every-exit contract as the alt screen (-11: both
            // are addressing features, armed together or not at all).
            if ($this->addressable) {
                fwrite($this->out, self::PASTE_OFF);
                fwrite($this->out, Ansi::altScreenLeave());
            }
            fwrite($this->out, "\n");
        }
    }

    /**
     * Arm the ^C path on a real terminal. sugar-core RawMode leaves ISIG on
     * (stty -icanon -echo keeps the signal machinery alive), so Ctrl-C
     * arrives as SIGINT and never as a byte: without this arm the pick()
     * finally block never runs, the terminal is left non-canonical, and the
     * 130 exit is only the shell's coincidence. The handler restores the
     * terminal FIRST, mirrors the cancel frame, and exits 130 with the same
     * cli.cancelled line the byte-cancel path produces in bin. SIGTERM,
     * SIGQUIT and SIGHUP get the same restore before dying too (-010) —
     * re-raised with SIG_DFL so the shell-visible status stays 129+signo —
     * because the pick() finally never runs for a default-disposition kill
     * and a RAW-stranded tty is not something vim/less-class tools leave
     * behind. ext-pcntl is a hard composer requirement; the guard below
     * only covers stripped builds, where ^C keeps its historical
     * unrecovered behavior — a disclosed fallback, never a silently
     * assumed one (F-3). The re-raise arms additionally require ext-posix;
     * without it the SIGINT path keeps its historical coverage.
     */
    private function armCancelSignal(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            return;
        }
        $this->asyncSignalsBeforeArm = pcntl_async_signals(true);
        pcntl_signal(SIGINT, function (int $signal): void {
            pcntl_signal($signal, SIG_DFL);
            pcntl_async_signals($this->asyncSignalsBeforeArm ?? false);
            $this->restoreTerminalForSignal();
            fwrite(STDERR, Lang::t('cli.cancelled') . "\n");
            exit(130);
        });
        if (function_exists('posix_kill') && function_exists('posix_getpid')) {
            foreach ([\SIGTERM, \SIGQUIT, \SIGHUP] as $terminating) {
                pcntl_signal($terminating, function (int $signal): void {
                    pcntl_signal($signal, SIG_DFL);
                    pcntl_async_signals($this->asyncSignalsBeforeArm ?? false);
                    $this->restoreTerminalForSignal();
                    posix_kill(posix_getpid(), $signal);
                });
            }
        }
    }

    /**
     * Common restore sequence for every signal handler: leave raw mode and
     * leave the alternate buffer — the shell's screen comes back exactly
     * as it was, then the cursor line is released. The same bytes the
     * normal pick() finally writes (-08).
     */
    private function restoreTerminalForSignal(): void
    {
        $this->setRawMode(false);
        if ($this->addressable) {
            fwrite($this->out, self::PASTE_OFF);
            fwrite($this->out, Ansi::altScreenLeave());
        }
        fwrite($this->out, "\n");
    }

    /**
     * Undo armCancelSignal(): a pick() that ended normally (or will be
     * re-entered) must not leak a picker-scoped handler or async dispatch
     * into the rest of the process.
     */
    private function disarmCancelSignal(): void
    {
        if ($this->asyncSignalsBeforeArm !== null && function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_DFL);
            pcntl_signal(SIGTERM, SIG_DFL);
            pcntl_signal(SIGQUIT, SIG_DFL);
            pcntl_signal(SIGHUP, SIG_DFL);
            pcntl_async_signals($this->asyncSignalsBeforeArm);
            $this->asyncSignalsBeforeArm = null;
        }
    }

    /**
     * Arm the resize wake (-013): SIGWINCH invalidates the geometry cache
     * and flags readKey() to return a repaint sentinel, so the frame
     * redraws against the new size without waiting for the next keypress.
     * The per-draw re-probe in draw() is the belt — this handler is the
     * immediate wake. Builds without ext-pcntl simply repaint one key
     * late; the geometry itself can no longer strand (the old latch was
     * the bug, the signal is latency).
     */
    private function armResizeSignal(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        pcntl_signal(SIGWINCH, function (): void {
            $this->geometry = null;
            $this->resizePending = true;
        });
    }

    /** Undo armResizeSignal() — picker-scoped, like the cancel arms. */
    private function disarmResizeSignal(): void
    {
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGWINCH, SIG_DFL);
        }
        $this->resizePending = false;
    }

    /**
     * @param list<Endpoint> $endpoints
     * @return list<array{endpoint: Endpoint, result: ?MatchResult, displayLine: string}>
     */
    private function filterMatches(array $endpoints): array
    {
        if ($this->filter === '') {
            return array_map(
                fn(Endpoint $e) => [
                    'endpoint' => $e,
                    'result' => null,
                    'displayLine' => $this->stripControls($e->displayLine()),
                ],
                $endpoints
            );
        }

        // Build sanitized display strings for scoring and rendering.
        // Using the same string for both ensures matched indices align
        // with what highlightLine() renders (no re-match needed).
        $displayLines = array_map(
            fn(Endpoint $e) => $this->stripControls($e->displayLine()),
            $endpoints
        );

        // Score each candidate; SmithWatermanMatcher returns null for no match
        $results = $this->matcher->matchAll($this->filter, $displayLines);

        // Build a map from haystack string to MatchResult for quick lookup
        $resultMap = [];
        foreach ($results as $r) {
            $resultMap[$r->haystack] = $r;
        }

        // Walk endpoints in original order, attaching MatchResult and displayLine
        $out = [];
        foreach ($endpoints as $idx => $e) {
            $sanitizedDisplay = $displayLines[$idx];
            $out[] = [
                'endpoint' => $e,
                'result' => $resultMap[$sanitizedDisplay] ?? null,
                'displayLine' => $sanitizedDisplay,
            ];
        }

        // Filter to only matched (score > 0) and sort by score descending.
        // usort() has been stable since PHP 8.0 — equal scores keep config
        // order (pinned by testEqualScoresPreserveConfigOrder, 2026-10-23).
        $matched = array_filter($out, static fn(array $r) => $r['result'] !== null);
        usort($matched, static fn(array $a, array $b) =>
            $b['result']->score <=> $a['result']->score
        );

        return $matched;
    }

    /**
     * @param list<array{endpoint: Endpoint, result: ?MatchResult, displayLine: string}> $matches
     */
    private function draw(array $matches): void
    {
        $this->geometry = null; // re-probe per frame: resizes are real (-013)
        $size = $this->geometry();
        $showCount = $this->filter !== '' && $this->totalEndpoints > 0;
        $showNone = $matches === [];
        // title + filter + blank + help, plus the conditional count and
        // no-matches rows — the rows the budget below must leave for data.
        // -07: with an EMPTY filter the frame carries the secondary key
        // row in place of the count/no-matches pair, so 6 stays the
        // worst-case chrome total (count + no-matches with a live filter).
        $showKeys = $this->filter === '';
        $chromeRows = 4 + ($showCount ? 1 : 0) + ($showNone ? 1 : 0) + ($showKeys ? 1 : 0);

        if ($this->addressable) {
            fwrite($this->out, Ansi::cursorTo(1, 1) . Ansi::eraseToEnd());
        }
        // -014: every row the frame writes goes through clampRow — the
        // chrome used to bypass it, and a localized help string (62-78
        // cells) soft-wrapped on ≤78-col panes, stranding the overflow
        // under the next redraw.
        fwrite($this->out, $this->clampRow(Lang::t('picker.title')) . "\r\n");
        // Belt-and-braces: intake (isTextKey) already refuses control bytes,
        // but the echo passes through the same sanitizer the config-derived
        // strings use, so no raw ESC can reach the terminal from here either.
        // The 'en' filter prompt is byte-pinned as 'filter: ' by the
        // integrity suite — picker.filter must keep that shape verbatim.
        $filterLine = Lang::t('picker.filter') . $this->filterColor() . $this->stripControls($this->filter) . $this->sgrReset();
        fwrite($this->out, $this->clampRow($filterLine) . "\r\n");
        if ($showCount) {
            // Honest funnel: how much of the config the filter killed
            // (2026-10-23). The count row stays out of the filter line
            // itself (the integrity suite counts ESC bytes per 'filter: '
            // line) and out of reflection draws where totalEndpoints is
            // still 0.
            fwrite($this->out, $this->clampRow(Lang::t('picker.count', [
                'shown' => (string) count($matches),
                'total' => (string) $this->totalEndpoints,
            ])) . "\r\n");
        }
        if ($showNone) {
            fwrite($this->out, $this->clampRow(Lang::t('picker.no_matches')) . "\r\n");
        }
        // F-25: render-side visible window. windowTop is recomputed from
        // the cursor every frame, so no navigation branch has to maintain
        // it; array_slice keeps original keys, so $i still identifies the
        // absolute row for the marker test. -002: on a terminal with known
        // rows the window shrinks to what fits above the chrome instead of
        // scrolling the picker's own title out of the viewport.
        $total   = count($matches);
        $visible = min(self::WINDOW_ROWS, $total);
        if ($size['rows'] !== null && $total > 0) {
            $visible = min($visible, max(1, $size['rows'] - $chromeRows));
        }
        $top     = max(0, min($this->cursor, $total - $visible));
        foreach (array_slice($matches, $top, $visible, true) as $i => $record) {
            $e = $record['endpoint'];
            $marker = $i === $this->cursor ? $this->selectedMarker() : self::UNSELECTED_MARKER;
            // The displayLine is already sanitized from filterMatches()
            $line = $this->highlightLine($record['displayLine'], $record['result']);
            if ($e->description !== null && $e->description !== '') {
                $line .= '  ' . $this->descriptionColor() . $this->stripControls($e->description) . $this->sgrReset();
            }
            fwrite($this->out, $this->clampRow("{$marker}{$line}") . "\r\n");
        }
        fwrite($this->out, "\r\n" . $this->clampRow(Lang::t('picker.help')) . "\r\n");
        if ($showKeys) {
            // -07: the full chord map earns its row only while nothing is
            // typed — the primary line is the memory-safe half, this is
            // the discoverability half, and a live query yields the
            // vertical budget back to results.
            fwrite($this->out, $this->clampRow(Lang::t('picker.help_keys')) . "\r\n");
        }
    }

    /**
     * Page span for PgUp/PgDn (F-25): what the visible window shows at
     * once, never below one — on a rows-known terminal, never past what
     * fits above the worst-case chrome either (-002: a page of 10 onto a
     * 7-row window would scroll the frame blind again).
     *
     * @param array<int|string, array<string, mixed>> $matches
     */
    private function pageSpan(array $matches): int
    {
        $span = min(self::WINDOW_ROWS, max(1, count($matches)));
        $rows = $this->geometry()['rows'];
        if ($rows !== null) {
            $span = min($span, max(1, $rows - self::MAX_CHROME_ROWS));
        }
        return $span;
    }

    /**
     * Truncate a composed row to one less than the terminal width so a
     * long host or description cannot force a soft wrap. The byte model
     * (-08 correction of the stale comment here): each frame opens with
     * cursorTo(1,1) + eraseToEnd (CSI 0J — clears from the cursor to the
     * END OF SCREEN, not just the line), so horizontal leftovers never
     * survive. Wrapping is still fatal because a wrapped row consumes an
     * extra line: every later row lands one cell low, and at the bottom
     * margin the scroll pushes the picker's own title above the cursor —
     * out of reach of the next 0J. Non-terminals — every byte-pinned
     * picker test and any piped run — probe null and pass through unclamped.
     * The floor is cols-1 at the extremes too (-061): the old
     * max(8, cols-1) could EXCEED the terminal on 4-8-col ttys, defeating
     * the invariant in exactly the narrow case it existed for; the
     * sub-MIN_COLS refusal in pick() keeps that band degenerate-but-safe.
     */
    private function clampRow(string $row): string
    {
        $cols = $this->geometry()['cols'];
        if ($cols === null) {
            return $row;
        }
        $limit = max(1, $cols - 1);
        if (Width::of($row) <= $limit) {
            return $row;
        }
        // One cell is reserved for the ellipsis itself.
        return Width::truncateAnsi($row, $limit - 1) . '…';
    }

    /**
     * Terminal geometry, probed once per frame (draw() resets the cache
     * at its top; the SIGWINCH handler clears it for between-frame wakes)
     * and normalized so "no tty" is ['cols' => null, 'rows' => null].
     *
     * @return array{cols:?int, rows:?int}
     */
    private function geometry(): array
    {
        if ($this->geometry === null) {
            $this->geometry = $this->detectSize() ?? ['cols' => null, 'rows' => null];
        }
        return $this->geometry;
    }

    /**
     * Test seam: the real terminal size when $out is a tty, null
     * otherwise. {@see PickerChromeTest} overrides it to force geometry
     * without a PTY. Leg-coverage map (-097): the real-stream select branch
     * of this class never ran in-suite until PickerInputIntegrityTest's
     * socketpair pins — burst promotion, ESCDELAY override, and the
     * lone-ESC expiry each exercise a different leg; the memory-stream
     * bypass is exercised by every byte-pinned `drive()` test, and the
     * clamp geometry legs by PickerChromeTest.
     *
     * @return ?array{cols:?int, rows:?int}
     */
    protected function detectSize(): ?array
    {
        $tty = new Tty($this->out);
        if (!$tty->isTty()) {
            return null;
        }
        $size = $tty->size();
        return ['cols' => $size['cols'] ?? null, 'rows' => $size['rows'] ?? null];
    }

    /**
     * Canonical untrusted-bytes policy for everything the picker writes to
     * the terminal. {@see Sanitize::untrustedForDisplay()} strips escape
     * sequences, C0 controls and the encoded-C1/C1-shim bytes the old local
     * byte-sweep missed (\xC2[\x80-\x9F] reaches a terminal as a real CSI
     * when the C1 range is interpreted), and marks invisible formatting
     * (bidi overrides, zero-width joins) as visible <U+XXXX> sentinels
     * instead of letting them silently rewrite what a row says.
     *
     * CR, LF and TAB survive sanitizing (the boundary keeps line endings for
     * paste fidelity), so they are folded to spaces here: a config field's
     * newline must not forge an extra visual row through the display path.
     */
    private function stripControls(string $s): string
    {
        $s = Sanitize::untrustedForDisplay($s);

        return str_replace(["\t", "\r", "\n"], ' ', $s);
    }

    /**
     * Apply bold+cyan highlighting to matched character positions in a display line.
     *
     * The $line argument is the sanitized display string; $result contains
     * matchedIndices computed against that same string in filterMatches().
     * We walk CODEPOINTS — the same index domain sugar-fuzzy scores in
     * (CharFold::foldSplit is 1:1 with original codepoints, see
     * SmithWatermanMatcher), so matched indices land on the characters the
     * matcher actually aligned. A grapheme walk here (the old /\X/u) would
     * desynchronise every index after a combining mark or emoji.
     */
    private function highlightLine(string $line, ?MatchResult $result): string
    {
        if ($result === null || $result->matchedIndices === []) {
            return $line;
        }
        if (!$this->colorOn) {
            // -11: the highlight tier rides the palette — plain text under
            // NO_COLOR/dumb, positions unchanged.
            return $line;
        }

        $matchSet = array_flip($result->matchedIndices);

        // Wrap matched codepoints in ANSI bold+cyan
        $highlighted = '';
        $idx = 0;
        foreach (mb_str_split($line, 1, 'UTF-8') as $codepoint) {
            if (isset($matchSet[$idx])) {
                $highlighted .= Ansi::sgr(1, 36) . $codepoint . Ansi::reset();
            } else {
                $highlighted .= $codepoint;
            }
            $idx++;
        }

        return $highlighted;
    }

    /**
     * Classify a {@see readKey()} result as printable filter text.
     *
     * True only for well-formed UTF-8 that carries no control codepoint:
     * - any embedded C0/DEL byte (0x00-0x1F, 0x7F) disqualifies the
     *   sequence, at any position — this is what keeps ESC-bearing key
     *   events (RIS "\x1bc", Meta chords "\x1bp", CSI/SS3 bodies) out of
     *   the filter instead of echoing them raw mid-frame;
     * - a lone raw 0x80-0x9F byte is an 8-bit C1 control (or a stranded
     *   UTF-8 continuation) and fails the well-formedness probe;
     * - the encoded C1 range (U+0080-U+009F, e.g. "\xc2\x9b" = 8-bit CSI)
     *   is valid UTF-8 but still a control, so the first codepoint is
     *   checked after decoding.
     *
     * Byte-oriented patterns on purpose (no /u flag on the C0 class): C0
     * bytes can never occur inside a well-formed multi-byte sequence, and
     * a /u pattern returns false (not zero) on invalid UTF-8 — the same
     * fail-closed shape sugar-core's Sanitize documents.
     */
    private function isTextKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $key) === 1) {
            return false;
        }
        if (preg_match('//u', $key) !== 1) {
            return false; // invalid UTF-8: stranded continuation or raw C1
        }
        $first = mb_ord($key, 'UTF-8');
        if ($first === false) {
            return false;
        }
        // Reject encoded C1 controls (U+0080-U+009F); C0/DEL were already
        // refused byte-wise above.
        return !($first >= 0x80 && $first <= 0x9f);
    }

    /**
     * -09 belt inputs: did the last delivered keystroke come from a still
     * draining batch (bytes buffered behind it), and was it filter text or
     * an Enter we already folded? An Enter that lands with those flags is
     * the newline of a pasted line, not a connect intent. Memory streams
     * never set the flags — canned test drives deliver whole sessions at
     * once and stay on the dispatch path by construction.
     */
    private function enterLooksLikePasteTail(): bool
    {
        return $this->burstPending && ($this->lastKeyWasText || $this->lastKeyWasEnter);
    }

    /**
     * Record the keystroke for the -09 belt: what it was, and whether the
     * stream still holds bytes behind it (a zero-timeout select — the
     * shape of a batch write, immune to draw latency and CI load).
     */
    private function noteKeystroke(string $key): void
    {
        $this->lastKeyWasText = $this->isTextKey($key);
        $this->lastKeyWasEnter = $key === "\r" || $key === "\n";
        $this->refreshBurstTail();
    }

    private function refreshBurstTail(): void
    {
        if (!$this->isRealInputStream()) {
            $this->burstPending = false;
            return;
        }
        $r = [$this->in];
        $w = null;
        $e = null;
        $this->burstPending = @stream_select($r, $w, $e, 0, 0) > 0;
    }

    private function isRealInputStream(): bool
    {
        return (stream_get_meta_data($this->in)['stream_type'] ?? '') !== 'MEMORY';
    }

    /**
     * Route the palette and the addressing switches through the fleet
     * color authority (-11). ColorProfile::detect gets $stdout=null on
     * purpose: the interactive leg in bin has already proven the tty, and
     * the picker's historical contract (byte-pinned by dozens of drives,
     * honored by `| less -R` classic workflows) keeps escapes flowing on
     * pipes. Only explicit signals change behavior:
     *   NO_COLOR / CLICOLOR=0  → no SGR anywhere (layout glyphs stay)
     *   TERM=dumb (→ NoTty)    → no cursor addressing, no ?1049/?2004
     *                            switches: the frame prints once, plain
     * A capability-less TERM (unset, CI) keeps the legacy rich shape —
     * silence about capabilities must never COST the colors on terminals
     * that simply did not announce themselves.
     */
    private function resolveTerminalCapability(): void
    {
        $profile = ColorProfile::detect(null, null);
        $explicitColorOff = $this->truthyEnv('NO_COLOR')
            || (getenv('CLICOLOR') !== false && getenv('CLICOLOR') === '0');
        $this->colorOn = $profile->supportsAnsi() || !$explicitColorOff;
        // NoTty with $stdout=null is only reachable via TERM=dumb — the
        // one signal that means "this device cannot honor addressing".
        $this->addressable = $profile !== ColorProfile::NoTty;
        if (!$this->addressable) {
            $this->colorOn = false;
        }
    }

    private function truthyEnv(string $name): bool
    {
        $v = getenv($name);
        return $v !== false && $v !== '' && $v !== '0' && strtolower($v) !== 'false';
    }

    private function selectedMarker(): string
    {
        return $this->colorOn ? self::MARKER_COLORED : self::MARKER_PLAIN;
    }

    private function filterColor(): string
    {
        return $this->colorOn ? self::FILTER_COLOR_COLORED : '';
    }

    private function descriptionColor(): string
    {
        return $this->colorOn ? self::DESCRIPTION_COLOR_COLORED : '';
    }

    private function sgrReset(): string
    {
        return $this->colorOn ? Ansi::reset() : '';
    }

    /**
     * Drain a bracketed paste payload (-09). Text keys append to the
     * filter, CR/LF fold to spaces, and every other event (arrow
     * fragments, chords) is swallowed — inside a burst the picker is a
     * text pipe, not a navigator. Returns true when the stream ended
     * before the terminator (pick() maps that to cancel, never hang).
     */
    private function readPasteBurst(): bool
    {
        while (true) {
            $key = $this->readKey();
            if ($key === self::PASTE_END) {
                // A genuine Enter microseconds behind the terminator can
                // still be paste tail; re-probe the stream for it.
                $this->lastKeyWasText = true;
                $this->lastKeyWasEnter = false;
                $this->refreshBurstTail();
                return false;
            }
            if ($key === "\x03") {
                return true;
            }
            if ($key === "\r" || $key === "\n") {
                $this->filter .= ' ';
                continue;
            }
            if ($this->isTextKey($key)) {
                $this->filter .= $key;
            }
        }
    }

    /**
     * Read one complete key event.
     *
     * Key events are delivered ATOMICALLY: a single ASCII byte, a whole
     * UTF-8 codepoint (lead + continuation bytes assembled here, so the
     * filter never holds a dangling lead byte), or a full escape sequence
     * (bare ESC, CSI `ESC [ …final`, SS3 `ESC O x`, or a 2-byte Meta
     * chord). The switch in pick() then dispatches navigation keys and the
     * text intake only ever sees complete codepoints.
     */
    private function readKey(): string
    {
        $b = $this->readFirstByte();
        if ($b === false || $b === '') {
            return "\x03";
        }
        if ($b === "\x1b") {
            return $this->readEscapeSequence();
        }
        $code = ord($b);
        if ($code >= 0xc2 && $code <= 0xf4) {
            // Valid UTF-8 lead byte: assemble the rest of the codepoint.
            // (0xC0/0xC1 leads are overlong encodings and 0xF5+ are out of
            // range — both fall through as lone invalid bytes, which
            // isTextKey() refuses.)
            return $this->readCodepointTail($b, self::utf8SequenceLength($code));
        }
        // ASCII, or a lone 0x80-0xBF continuation byte / 0xF5-0xFF invalid
        // lead — isTextKey() drops the malformed shapes; only printable
        // ASCII survives.
        return $b;
    }

    /**
     * First byte of a key event, waited for in bounded select slices with
     * a signal dispatch between them (-001).
     *
     * A bare blocking fread is NOT signal-interruptible in userland: PHP
     * dispatches queued pcntl handlers only at opcode boundaries, and the
     * kernel silently restarts the interrupted read (SA_RESTART), so an
     * armed SIGINT handler would sit queued until some later byte released
     * the read — Ctrl-C looked dead on a real terminal. The select slice +
     * explicit pcntl_signal_dispatch() closes the window with or without
     * async-signal mode. stream_select() is a no-op guarantee on memory
     * streams (tests), so those keep the plain fread path — the same
     * stream-type bypass nextByteWithWait() documents.
     */
    private function readFirstByte(): string|false
    {
        if ((stream_get_meta_data($this->in)['stream_type'] ?? '') === 'MEMORY') {
            return fread($this->in, 1);
        }
        while (true) {
            $r = [$this->in];
            $w = null;
            $e = null;
            $ready = @stream_select($r, $w, $e, 0, 200_000);
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            if ($this->resizePending) {
                // -013 repaint wake: return an unbound byte (form feed —
                // never a keystroke here, and isTextKey() refuses control
                // bytes anyway) so the switch drops it and the loop
                // redraws against fresh geometry without waiting for a
                // keypress.
                $this->resizePending = false;
                return "\x0c";
            }
            if ($ready !== 0) {
                // > 0: a byte (or EOF) is ready. false: select errored —
                // hand the stream to fread anyway, so an unreachable fd
                // still maps to the EOF cancel byte exactly as the old
                // blocking call did.
                return fread($this->in, 1);
            }
        }
    }

    /**
     * Total byte length of a UTF-8 sequence from its lead byte (0xC2-0xF4).
     */
    private static function utf8SequenceLength(int $lead): int
    {
        return match (true) {
            $lead >= 0xf0 => 4,
            $lead >= 0xe0 => 3,
            default       => 2,
        };
    }

    /**
     * Read up to $length-1 continuation bytes (0x80-0xBF) to complete a
     * multi-byte codepoint. A missing or non-continuation byte aborts
     * assembly; the resulting partial sequence is invalid UTF-8 and gets
     * dropped by isTextKey(). (A real terminal sends a codepoint's bytes
     * together, so the abort path only ever sees corrupt input — at worst
     * one following keystroke is consumed with the junk it belongs to.)
     */
    private function readCodepointTail(string $lead, int $length): string
    {
        $seq = $lead;
        stream_set_blocking($this->in, false);
        try {
            for ($i = 1; $i < $length; $i++) {
                $c = $this->nextByteWithWait();
                if ($c === '' || (ord($c) & 0xc0) !== 0x80) {
                    break;
                }
                $seq .= $c;
            }
        } finally {
            stream_set_blocking($this->in, true);
        }
        return $seq;
    }

    /**
     * The follow-up window in microseconds: the documented constant, or
     * `ESCDELAY` (milliseconds) when it parses as plain digits (-017's
     * named escape hatch).
     */
    private static function escFollowupMicroseconds(): int
    {
        $delay = getenv('ESCDELAY');
        if ($delay !== false && preg_match('/^\d{1,4}$/', $delay) === 1) {
            return (int) $delay * 1000;
        }
        return self::ESC_FOLLOWUP_MICROSECONDS;
    }

    /**
     * ESC arrived — classify the rest of the sequence.
     *
     * `ESC [ …` is CSI (params through a 0x40-0x7E final byte), `ESC O x`
     * is SS3 (a single final byte), a lone ESC (nothing arrives within the
     * follow-up window, see {@see escFollowupMicroseconds()}) is the Esc
     * keypress itself, and anything else is a 2-byte Meta/Alt chord. All
     * but the lone ESC are opaque key events: pick()'s switch ignores the
     * unknown ones and isTextKey() refuses to type them.
     */
    private function readEscapeSequence(): string
    {
        // Non-blocking window: on a real TTY this stream_select timeout is
        // what distinguishes a genuine lone ESC (no follow-on bytes) from
        // an arrow-key burst. Memory streams (tests) do not support
        // stream_select — nextByteWithWait() reads them directly.
        stream_set_blocking($this->in, false);
        try {
            $next = $this->nextByteWithWait();
            if ($next === '') {
                return "\x1b"; // lone ESC keypress, or EOF mid-sequence
            }
            if ($next === '[') {
                return "\x1b[" . $this->readCsiBody();
            }
            if ($next === 'O') {
                $final = $this->nextByteWithWait();
                return $final === '' ? "\x1bO" : "\x1bO" . $final;
            }
            return "\x1b" . $next; // Meta/Alt chord — one opaque key event
        } finally {
            stream_set_blocking($this->in, true);
        }
    }

    /**
     * CSI parameter/intermediate bytes through the 0x40-0x7E final byte,
     * bounded at 16 bytes so a stream that never sends a final byte cannot
     * wedge the pump.
     */
    private function readCsiBody(): string
    {
        $seq = '';
        for ($i = 0; $i < 16; $i++) {
            $c = $this->nextByteWithWait();
            if ($c === '') {
                break;
            }
            $seq .= $c;
            $code = ord($c);
            if ($code >= 0x40 && $code <= 0x7e) {
                break;
            }
        }
        return $seq;
    }

    /**
     * One byte, giving up (returning '') when none is pending: memory
     * streams have everything buffered already, real TTY/pipe streams get
     * the follow-up window of {@see escFollowupMicroseconds()} — which is
     * what promotes a latency-fragmented `ESC [ A` burst to a real
     * keypress instead of a lone-ESC cancel (-017).
     */
    private function nextByteWithWait(): string
    {
        if ((stream_get_meta_data($this->in)['stream_type'] ?? '') === 'MEMORY') {
            $c = fread($this->in, 1);
            return $c === false ? '' : $c;
        }
        $r = [$this->in];
        $w = null;
        $e = null;
        $usec = self::escFollowupMicroseconds();
        if (@stream_select($r, $w, $e, intdiv($usec, 1_000_000), $usec % 1_000_000) < 1) {
            return '';
        }
        $c = fread($this->in, 1);
        return $c === false ? '' : $c;
    }

    /**
     * Toggle the controlling tty into raw mode so the picker sees
     * keys one byte at a time. Delegates to sugar-core's portable
     * {@see RawMode} helper, which is a safe no-op on non-tty streams
     * (e.g. piped input in tests).
     */
    protected function setRawMode(bool $on): void
    {
        if ($on) {
            RawMode::enable($this->in);
        } else {
            RawMode::disable($this->in);
        }
    }
}
