<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Position as BufferPosition;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * Floating alert notification renderer.
 *
 * Renders one or more toast alerts (error/warning/info/success) at a fixed
 * screen position, composited over a background view.
 *
 * Port of DaltonSW/bubbleup.
 *
 * @see https://github.com/daltonsw/bubbleup
 *
 * Findings verified:
 * - Finding 9: Stacked/queued toasts via maxConcurrent + Overflow (already implemented)
 * - Finding 10: examples/ directory exists (basic.php, types.php)
 */
final class Toast
{
    // Configuration
    private int $maxWidth   = 50;
    private int $minWidth   = 0;
    private Position $position = Position::TopLeft;
    private SymbolSet $symbols = SymbolSet::Unicode;
    private ?float $duration = null;  // seconds, null = no auto-dismiss

    /** Internal queue of active alerts. */
    private array $queue = [];

    /**
     * Dismissed flag — if true, Toast won't render any alerts ("stop
     * rendering until cleared"): {@see clear()} is the documented revival and
     * also resets this flag, while {@see alert()} / {@see progressToast()} /
     * {@see push()} refuse to queue into a dead instance.
     */
    private bool $dismissed = false;

    /** Host-consumed flag: whether the host should dismiss on Escape key press. The renderer stores this preference; it does not handle input itself. */
    private bool $allowEscToClose = true;

    /**
     * Maximum number of concurrent alerts (null = unlimited).
     *
     * Growth caveat, stated honestly: with the default null cap the queue only
     * shrinks through `dismiss()`, `clear()`, or the opportunistic prune that
     * now runs on every write, so persistent (non-expiring) alerts accumulate
     * without bound until the host clears them.
     */
    private ?int $maxConcurrent = null;

    /** Overflow strategy when queue exceeds maxConcurrent. */
    private Overflow $overflow = Overflow::DropOldest;

    /** Maximum number of entries kept in the history log (null = unbounded). */
    private ?int $historyLimit = 100;

    /** History log of dismissed alerts. */
    private HistoryLog $historyLog;

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function __construct(int $maxWidth = 50)
    {
        $this->maxWidth = $maxWidth;
        $this->historyLog = new HistoryLog();
    }

    public static function new(int $maxWidth = 50): self
    {
        return new self($maxWidth);
    }

    // -------------------------------------------------------------------------
    // Configuration (fluent with*)
    // -------------------------------------------------------------------------

    public function withMaxWidth(int $w): self
    {
        return $this->mutate(['maxWidth' => $w]);
    }

    public function withMinWidth(int $w): self
    {
        return $this->mutate(['minWidth' => $w]);
    }

    public function withPosition(Position $pos): self
    {
        return $this->mutate(['position' => $pos]);
    }

    public function withSymbolSet(SymbolSet $set): self
    {
        return $this->mutate(['symbols' => $set]);
    }

    /**
     * Auto-dismiss alerts after $duration seconds.
     * Pass null to disable auto-dismiss.
     */
    public function withDuration(?float $seconds): self
    {
        return $this->mutate(['duration' => $seconds]);
    }

    /**
     * Set the allowEscToClose preference flag.
     *
     * This is a host-consumed flag — the renderer stores the preference and
     * exposes it via allowEscToClose() so a host's key handler can decide
     * whether Escape dismisses. The library itself does not handle input.
     */
    public function withAllowEscToClose(bool $allow): self
    {
        return $this->mutate(['allowEscToClose' => $allow]);
    }

    /**
     * Returns the allowEscToClose preference flag.
     *
     * Host code can read this to determine whether the user has requested
     * Escape-to-dismiss behavior. The renderer itself does not act on it.
     */
    public function allowEscToClose(): bool
    {
        return $this->allowEscToClose;
    }

    /**
     * Set the maximum number of concurrent alerts.
     * Pass null for unlimited.
     */
    public function withMaxConcurrent(?int $max): self
    {
        return $this->mutate(['maxConcurrent' => $max]);
    }

    /**
     * Set the overflow strategy when maxConcurrent is exceeded.
     */
    public function withOverflow(Overflow $overflow): self
    {
        return $this->mutate(['overflow' => $overflow]);
    }

    /**
     * Cap the history log at $limit entries, evicting the oldest first
     * (the Overflow::DropOldest policy applied to history). The default is
     * 100; pass null to keep every dismissed alert unbounded.
     *
     * @throws \InvalidArgumentException when $limit is negative
     */
    public function withHistoryLimit(?int $limit): self
    {
        if ($limit !== null && $limit < 0) {
            throw new \InvalidArgumentException(
                "History limit must be >= 0 or null (unbounded), got {$limit}"
            );
        }
        return $this->mutate(['historyLimit' => $limit]);
    }

    /**
     * Create a new instance with the given changes merged in.
     *
     * Mirrors the Mutable trait pattern from sugar-core but avoids requiring
     * readonly fields or constructor-param-only initialization.
     */
    private function mutate(array $changes): self
    {
        $clone = clone $this;
        foreach ($changes as $k => $v) {
            $clone->{$k} = $v;
        }
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Alert operations
    // -------------------------------------------------------------------------

    /**
     * Add an alert to the queue. Returns a new Toast instance.
     *
     * If $expiresAt is provided, it overrides the configured duration.
     * Accepts a ToastType or a string type name (case-insensitive).
     *
     * When maxConcurrent is set and the queue would exceed it, applies
     * the configured overflow strategy (DropOldest, DropNewest, or Enqueue).
     * Expired alerts sitting in the queue are pruned first, on this write.
     *
     * @param list<Action>|null $actions  Clickable action buttons to render beneath the message
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function alert(ToastType|string $type, string $message, ?float $expiresAt = null, ?array $actions = null): self
    {
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = new Alert($resolvedType, $message, $expiresAt, null, $actions ?? []);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        // Finding 9: Stacked/queued toasts — verified via maxConcurrent + Overflow enum
        $clone->queue = $this->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Add a progress toast — renders a progress bar beneath the message.
     *
     * @param float $progress  Value between 0.0 and 1.0 (clamped)
     * @param list<Action>|null $actions  Clickable action buttons to render beneath the progress bar
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function progressToast(ToastType|string $type, string $message, float $progress, ?float $expiresAt = null, ?array $actions = null): self
    {
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = (new Alert($resolvedType, $message, $expiresAt, null, $actions ?? []))->withProgress($progress);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        $clone->queue = $this->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Enqueue a pre-built (optionally styled) Alert.
     *
     * Companion to {@see alert()} for callers that construct an Alert
     * directly — e.g. to attach background/foreground/border colours via
     * {@see Alert::withBackgroundColor()} / {@see Alert::withForegroundColor()}
     * / {@see Alert::withBorderColor()}. Honours the configured duration
     * (when the alert carries no expiry of its own), the expired-on-write
     * prune, and the maxConcurrent/overflow policy, exactly like {@see alert()}.
     *
     * @throws \LogicException when the toast has been dismissed — call clear() to resume
     */
    public function push(Alert $alert): self
    {
        $clone = clone $this;

        if ($alert->expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        $clone->queue = $this->appendBounded($clone->queue, $alert);
        return $clone;
    }

    /**
     * Append an alert to a queue copy honouring the maxConcurrent/overflow
     * policy — the shared engine behind {@see alert()}, {@see progressToast()}
     * and {@see push()}.
     *
     * Expired alerts are pruned from the queue first, on this write, so a
     * long-lived instance cannot accumulate dead cruft between view() calls.
     * A dismissed instance refuses new writes outright rather than queueing
     * into a renderer that will not paint them — clear() revives first.
     *
     * DropNewest discards the incoming alert; DropOldest evicts the oldest
     * queued alert — at a zero cap there is nothing to evict, so the incoming
     * alert is discarded too rather than letting the queue exceed the cap.
     * Enqueue lets the queue grow past the cap (DropOldest is the default).
     *
     * @param list<Alert> $queue
     * @return list<Alert>
     * @throws \LogicException when the toast has been dismissed
     */
    private function appendBounded(array $queue, Alert $alert): array
    {
        if ($this->dismissed) {
            throw new \LogicException(
                'Toast has been dismissed; call clear() before queueing new alerts.'
            );
        }

        // Bounded-queue law: expiry leaves on the next write, not only on
        // view() — otherwise maxConcurrent=null instances grow forever.
        $queue = \array_values(
            \array_filter($queue, fn(Alert $a): bool => !$a->isExpired())
        );

        if ($this->maxConcurrent !== null && \count($queue) >= $this->maxConcurrent) {
            if ($this->overflow === Overflow::DropNewest) {
                return $queue;
            }
            if ($this->overflow === Overflow::DropOldest) {
                if ($queue === []) {
                    return $queue;
                }
                \array_shift($queue);
            }
            // Enqueue: fall through, allow exceeding max
        }
        $queue[] = $alert;
        return $queue;
    }

    /**
     * Convenience: show an error alert.
     */
    public function error(string $message): self
    {
        return $this->alert(ToastType::Error, $message);
    }

    /**
     * Convenience: show a warning alert.
     */
    public function warning(string $message): self
    {
        return $this->alert(ToastType::Warning, $message);
    }

    /**
     * Convenience: show an info alert.
     */
    public function info(string $message): self
    {
        return $this->alert(ToastType::Info, $message);
    }

    /**
     * Convenience: show a success alert.
     */
    public function success(string $message): self
    {
        return $this->alert(ToastType::Success, $message);
    }

    /**
     * Stop rendering and move the live alerts into the history log.
     *
     * Dismiss is a MOVE, not a copy: queued alerts leave the queue (expired
     * ones are dropped, never recorded), so a second dismiss() can neither
     * double-count history nor find leftovers. Rendering stays stopped until
     * the host calls {@see clear()} — alert() refuses meanwhile — and clear()
     * resets the flag.
     */
    public function dismiss(): self
    {
        if ($this->dismissed) {
            return $this;  // idempotent: already stopped, nothing left to move
        }

        $clone = clone $this;

        // Move active (non-expired) alerts to history, then empty the queue.
        foreach ($clone->queue as $alert) {
            if (!$alert->isExpired()) {
                $clone->historyLog = $clone->historyLog->push($alert, $clone->historyLimit);
            }
        }
        $clone->queue = [];

        $clone->dismissed = true;
        return $clone;
    }

    /**
     * Remove expired alerts and return a new Toast.
     */
    public function pruneExpired(): self
    {
        $clone = clone $this;
        $clone->queue = \array_values(
            \array_filter($clone->queue, fn(Alert $a): bool => !$a->isExpired())
        );
        return $clone;
    }

    /**
     * Clear the entire queue and reset the dismissed flag, resuming
     * rendering — the documented revival path after {@see dismiss()}.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->queue = [];
        $clone->dismissed = false;
        return $clone;
    }

    /**
     * Cancel the auto-dismiss timer on the alert at $index, making it persistent.
     * Has no effect if $index is out of bounds or the alert is already non-expiring.
     */
    public function cancelAlert(int $index): self
    {
        if ($index < 0 || $index >= \count($this->queue)) {
            return $this;
        }
        $queue = $this->queue;
        $queue[$index] = $queue[$index]->withoutExpiry();
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Extend the auto-dismiss timer on the alert at $index by $additionalSeconds from now.
     * Has no effect if $index is out of bounds or the alert never expires — a
     * persistent alert has no timer to extend (mirrors {@see extendAll()}).
     */
    public function extendAlert(int $index, float $additionalSeconds): self
    {
        if ($index < 0 || $index >= \count($this->queue)) {
            return $this;
        }
        $alert = $this->queue[$index];
        if ($alert->expiresAt === null) {
            return $this;
        }
        $queue = $this->queue;
        $queue[$index] = $alert->withExtendedExpiry($additionalSeconds);
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Extend the auto-dismiss timer on all alerts by $additionalSeconds from now.
     * Only affects alerts that have an expiry; non-expiring alerts are unchanged.
     */
    public function extendAll(float $additionalSeconds): self
    {
        $queue = \array_map(
            fn(Alert $a): Alert => $a->expiresAt !== null ? $a->withExtendedExpiry($additionalSeconds) : $a,
            $this->queue,
        );
        return $this->mutate(['queue' => $queue]);
    }

    /**
     * Returns true if there are active (non-expired) alerts in the queue.
     */
    public function hasActiveAlert(): bool
    {
        foreach ($this->queue as $alert) {
            if (!$alert->isExpired()) {
                return true;
            }
        }
        return false;
    }

    /**
     * The soonest expiry instant (seconds since epoch) among the queued alerts
     * that auto-dismiss, or null when no queued alert has an expiry.
     *
     * This is the loop-integration primitive: instead of polling
     * {@see pruneExpired()} on a fixed interval, a TEA / event-loop host can
     * schedule ONE timer to fire at this instant, prune, then reschedule. The
     * value may be in the past if an alert is already due for pruning (the host
     * should prune immediately in that case) — see {@see secondsUntilNextExpiry()}
     * for a clamped, ready-to-schedule delay.
     */
    public function nextExpiry(): ?float
    {
        $soonest = null;
        foreach ($this->queue as $alert) {
            if ($alert->expiresAt === null) {
                continue;
            }
            if ($soonest === null || $alert->expiresAt < $soonest) {
                $soonest = $alert->expiresAt;
            }
        }
        return $soonest;
    }

    /**
     * Seconds from now until the next alert expires, clamped to >= 0.0 (an
     * already-due alert yields 0.0 → prune now), or null when no queued alert
     * auto-dismisses. Convenience for scheduling a single prune tick, e.g.
     * `Cmd::tick($toast->secondsUntilNextExpiry() ?? $idle, …)`.
     */
    public function secondsUntilNextExpiry(): ?float
    {
        $at = $this->nextExpiry();
        if ($at === null) {
            return null;
        }
        return \max(0.0, $at - \microtime(true));
    }

    /**
     * Return the history of dismissed alerts.
     *
     * Bare accessor per project convention (no `get` prefix).
     *
     * @return list<Alert>
     */
    public function history(): array
    {
        return $this->historyLog->all();
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the toast layer composited over a background view using
     * Buffer-based composition.
     *
     * Lowercase `view()` per the project renderer convention; PHP method
     * dispatch is case-insensitive, so the legacy `View()` call sites in
     * sugar-dash/candy-query keep resolving here without an alias.
     *
     * @param string $background  The underlying viewport content
     * @param int $viewportWidth  Viewport width in cells
     * @param int $viewportHeight Viewport height in lines
     * @return string  The composited output
     */
    public function view(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
    {
        if ($this->dismissed || $this->queue === []) {
            return $background;
        }

        $active = \array_values(
            \array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired())
        );

        if ($active === []) {
            return $background;
        }

        $bgLines = $this->splitLines($background);
        $bgRowCount = \count($bgLines);

        // Layout ONCE (audit M3): each box is rendered straight to its Buffer
        // and its height feeds both the frame growth and the placement pass,
        // so the two passes can no longer disagree about the coordinate
        // system — the old sizing pass handed yOffset() the PREVIOUS
        // cumulative height minus the alert height, which pushed every
        // bottom-anchored toast below the emitted frame.
        $boxes = [];
        $stackHeight = 0;
        foreach ($active as $alert) {
            $box = $this->renderAlertToBuffer($alert, $viewportWidth);
            $boxes[] = $box;
            $stackHeight += $box->height();
        }

        // The frame is the VIEWPORT canvas, not the box width (audit C2):
        // every background column survives; only the alert box is capped at
        // maxWidth (via renderAlert's $capWidth), and top-stacked alerts may
        // grow the frame downward.
        $frameWidth = max(1, $viewportWidth);
        $contentHeight = max(max($bgRowCount, $viewportHeight), $stackHeight);

        $viewport = Buffer::new($frameWidth, $contentHeight);
        $viewport = $this->fillViewportFromString($viewport, $bgLines);

        // xOffset()/yOffset() contract: the extra argument is the cumulative
        // height of the boxes placed BEFORE this one (top families stack
        // downward from it, bottom/middle stack upward against it), and the
        // box's REAL width is passed to xOffset so centre/right offsets become
        // non-zero — the old code always measured the full-canvas buffer,
        // collapsing xOffset to 0 and making 6 of the 9 positions identical
        // (audit M4). The region spans exactly the box, so nothing right of a
        // narrow box is overwritten with blanks (audit M5).
        $stackedBefore = 0;
        foreach ($boxes as $box) {
            $x = max(0, $this->position->xOffset($box->width(), $frameWidth));
            $y = max(0, $this->position->yOffset($box->height(), $contentHeight, $stackedBefore));

            $region = new Region(BufferPosition::new($x, $y), $box->width(), $box->height());
            $viewport = $viewport->withRegion($region, $box);
            $stackedBefore += $box->height();
        }

        return $viewport->toAnsi();
    }

    /**
     * Fill a viewport Buffer with content from an array of strings.
     * Each line is placed with ANSI parsing for styles.
     */
    private function fillViewportFromString(Buffer $buf, array $lines): Buffer
    {
        for ($row = 0; $row < \count($lines) && $row < $buf->height(); $row++) {
            $buf = $this->placeAnsiStringAt($buf, 0, $row, $lines[$row]);
        }
        return $buf;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Lay out one alert as its bordered box string.
     *
     * @param int $capWidth Hard ceiling from the render canvas (the viewport
     *                      width) — the box can never outgrow what it is
     *                      drawn on, even when maxWidth is larger.
     */
    private function renderAlert(Alert $alert, int $capWidth): string
    {
        // Finding 10 / Item 3.1: null message renders as empty string — the
        // width probe must coalesce too, or Width::string(null) fatals on a
        // strict_types path before the `?? ''` guard further down ever runs.
        $width = $this->resolveWidth(Width::string($alert->message ?? ''), $capWidth);
        $icon  = $alert->type->icon($this->symbols);
        $color = $alert->type->color();

        // Inner content width (between the two vertical borders).
        $inner = \max(1, $width - 2);

        // SGR prefix routed through candy-core Ansi so the icon's colour
        // is emitted byte-identically to a hand-built CSI sequence.
        $prefix = Ansi::CSI . $color . 'm' . $icon . Ansi::reset() . ' ';

        // Top / bottom borders: '─' is a 3-byte UTF-8 grapheme but one
        // display cell, so repeat it $inner times for $inner cells.
        $top    = '╭' . \str_repeat('─', $inner) . '╮';
        $bottom = '╰' . \str_repeat('─', $inner) . '╯';

        $middleLines = [];

        // Header line: coloured icon + first slice of the message, padded
        // to the inner cell width (Width::* are ANSI- and multibyte-aware).
        // Finding 10 / Item 3.1: null message renders as empty string — never "null"
        $iconCells = Width::string($prefix);
        $headerWrap = $this->wordWrap($alert->message ?? '', \max(1, $inner - $iconCells));
        $firstWord  = \array_shift($headerWrap) ?? '';
        $headerBody = $prefix . $firstWord;
        $middleLines[] = '│' . Width::padRight(Width::truncateAnsi($headerBody, $inner), $inner) . '│';

        // Remaining wrapped message lines, indented one cell.
        foreach ($headerWrap as $wl) {
            $body = ' ' . $wl;
            $middleLines[] = '│' . Width::padRight(Width::truncateAnsi($body, $inner), $inner) . '│';
        }

        // Render progress bar if set
        if ($alert->progress !== null) {
            $middleLines[] = $this->renderProgressBar($alert->progress, $inner);
        }

        // Render action buttons if any
        foreach ($alert->actions as $action) {
            $label = '[' . $action->label . ']';
            $middleLines[] = '│' . Width::padRight(Width::truncate($label, $inner), $inner) . '│';
        }

        $lines = [$top, ...$middleLines, $bottom];
        return \implode("\n", $lines);
    }

    /**
     * Render an alert into a Buffer sized to exactly its box.
     *
     * Parses the ANSI-encoded string from renderAlert() to extract SGR
     * sequences and builds cells with proper Buffer Style objects, so
     * toAnsi() produces correct styled output. Mirrors charmbracelet/bubbleup's
     * alert rendering pipeline.
     *
     * @param int $capWidth Ceiling from the render canvas (min of maxWidth is
     *                      applied inside resolveWidth); the returned buffer's
     *                      width equals the box's own cell width — never the
     *                      canvas — so View() blits precisely the box (audit M5).
     */
    private function renderAlertToBuffer(Alert $alert, int $capWidth): Buffer
    {
        $alertStr = $this->renderAlert($alert, $capWidth);
        $lines = $this->splitLines($alertStr);

        $height = \count($lines);
        $boxWidth = max(1, Width::string($lines[0] ?? ''));

        $buf = Buffer::new($boxWidth, $height);
        for ($row = 0; $row < $height; $row++) {
            $buf = $this->placeAnsiStringAt($buf, 0, $row, $lines[$row]);
        }

        // Additive: overlay optional per-alert bg/fg/border colours. When
        // all three are null (the default) this pass is skipped and the
        // buffer stays byte-identical to the plain render path.
        if ($alert->backgroundColor !== null
            || $alert->foregroundColor !== null
            || $alert->borderColor !== null) {
            $buf = $this->applyAlertColors($buf, $alert, $lines);
        }

        return $buf;
    }

    /**
     * Overlay a queued Alert's optional bg/fg/border colours onto its
     * freshly laid-out buffer.
     *
     * Every box cell gains the background fill; box-drawing glyphs
     * (╭ ─ ╮ │ ╰ ╯) take the border colour; remaining interior text takes
     * the foreground colour. Cells that already carry a colour — the
     * severity-coloured icon and any inline-SGR message spans — keep it.
     * Mirrors the styled toast box rendered by sugar-dash's Toast::render().
     *
     * @param list<string> $lines The rendered alert rows, used to bound the
     *                            styled columns to each row's box width so a
     *                            narrower box never bleeds fill into the
     *                            trailing blank cells of a wider buffer.
     */
    private function applyAlertColors(Buffer $buf, Alert $alert, array $lines): Buffer
    {
        static $borderGlyphs = ['╭' => true, '─' => true, '╮' => true, '│' => true, '╰' => true, '╯' => true];

        $bg     = $alert->backgroundColor !== null ? $this->colorToRgb($alert->backgroundColor) : null;
        $fg     = $alert->foregroundColor !== null ? $this->colorToRgb($alert->foregroundColor) : null;
        $border = $alert->borderColor !== null ? $this->colorToRgb($alert->borderColor) : null;

        $height   = $buf->height();
        $bufWidth = $buf->width();
        $lineCount = \count($lines);

        for ($row = 0; $row < $height && $row < $lineCount; $row++) {
            $cols = \min($bufWidth, Width::string($lines[$row]));
            for ($col = 0; $col < $cols; $col++) {
                $cell = $buf->cellAt($col, $row);
                if ($cell->width === 0) {
                    continue;  // wide-char continuation — toAnsi() skips it
                }

                $existing = $cell->style();
                $newFg = $existing?->fg();
                $attrs = $existing?->attrs() ?? 0;

                if (isset($borderGlyphs[$cell->rune()])) {
                    if ($border !== null) {
                        $newFg = $border;
                    }
                } elseif ($newFg === null && $fg !== null) {
                    // Plain interior text/padding — icon and inline-styled
                    // cells (non-null fg) keep their own colour.
                    $newFg = $fg;
                }

                $newBg = $bg ?? $existing?->bg();

                $buf = $buf->withCellAt(
                    $col,
                    $row,
                    new Cell($cell->rune(), new Style($newFg, $newBg, $attrs), $cell->link(), $cell->width),
                );
            }
        }

        return $buf;
    }

    /**
     * Pack a candy-core {@see Color} into a 24-bit 0xRRGGBB int, the shape
     * candy-buffer's {@see Style} stores fg/bg colours in.
     */
    private function colorToRgb(Color $color): int
    {
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }

    /**
     * Place an ANSI-encoded string into the buffer at (col, row), parsing
     * SGR sequences and applying corresponding Buffer Style objects.
     */
    private function placeAnsiStringAt(Buffer $buf, int $col, int $row, string $s): Buffer
    {
        $len = \strlen($s);
        $i = 0;
        $currentStyle = null;

        while ($i < $len) {
            $b = $s[$i];

            if ($b === "\x1b" && ($s[$i + 1] ?? '') === '[') {
                $j = $i + 2;
                while ($j < $len) {
                    $c = \ord($s[$j]);
                    $j++;
                    if ($c >= 0x40 && $c <= 0x7e) {
                        break;
                    }
                }
                $seq = \substr($s, $i + 2, $j - $i - 3);
                // Cumulative fold (audit M7): a sequence mutates only the
                // facets it names — styles survive across sequences on the
                // same run of cells, and a candy-buffer-emitted `\e[0;…m`
                // preamble resets then rebuilds instead of erasing everything.
                $currentStyle = $this->applySgr($currentStyle, $seq);
                $i = $j;
                continue;
            }

            $cluster = $this->nextCluster($s, $i);
            $gw = $this->graphemeWidth($cluster);

            if ($gw === 0) {
                $i += \strlen($cluster);
                continue;
            }

            if ($col >= $buf->width()) {
                break;
            }

            $buf = $buf->withCellAt($col, $row, new Cell($cluster, $currentStyle, null, $gw));
            if ($gw === 2 && $col + 1 < $buf->width()) {
                $buf = $buf->withCellAt($col + 1, $row, Cell::continuation());
            }
            $col += $gw;
            $i += \strlen($cluster);
        }

        return $buf;
    }

    /**
     * Extract the next UTF-8 grapheme cluster from string $s at position $i.
     *
     * Delegates to candy-core's canonical {@see Width::nextCluster()}. This
     * used to be the sanctioned verbatim fork (@c5cce07d7, kept because the
     * core splitter was private); the duplicated body is deleted now that
     * core promotes it to a public entry point, so both invalid-UTF-8 guards
     * (ICU position rejection + continuation-byte validation) live in exactly
     * one place. The malformed-walk pins reach the guards through this seam:
     * the call-site contract is unchanged (same return shape, byte-for-byte
     * reproduction of malformed input, always advances by >= 1 byte).
     */
    private function nextCluster(string $s, int $i): string
    {
        return Width::nextCluster($s, $i);
    }

    /**
     * Convert an ANSI SGR parameter list (e.g. "31" or "1;32") into a Buffer
     * Style from a clean slate. SGR "0" resets everything, returning null.
     *
     * Thin single-sequence wrapper over {@see applySgr()} — kept as the
     * historical entry point the renderer tests cite.
     */
    private function sgrToBufferStyle(string $sgr): ?Style
    {
        return $this->applySgr(null, $sgr);
    }

    /**
     * Fold one SGR parameter list onto an existing style with terminal-facet
     * semantics (audit M7): every code mutates only the facet it names.
     *
     * The old decoder REPLACED the whole style per sequence and understood
     * only fg-16/bold/reset, so 256-colour (`38;5;n`), truecolour
     * (`38;2;r;g;b`), backgrounds and the underline/reverse/dim/overline
     * family were silently dropped — and `38;2;10;20;30` even mis-parsed its
     * payload as a palette index, painting text black. Supported now:
     * 1-9/53 on, 22-29/55 off, 30-37/90-97/39 foreground, 40-47/100-107/49
     * background, 38/48 extended colours (index 5 → candy-core's canonical
     * xterm-256 table, index 2 → packed truecolor). An empty parameter list
     * means SGR 0 (reset) per spec; a malformed extended-colour payload stops
     * the walk fail-soft like a real terminal, and unknown codes are skipped
     * without disturbing the state.
     */
    private function applySgr(?Style $base, string $sgr): ?Style
    {
        // explode(';', '') yields [''] → intval 0 → the spec's implicit reset.
        $params = \array_map('intval', \explode(';', $sgr));
        $count = \count($params);

        $fg = $base?->fg();
        $bg = $base?->bg();
        $attrs = $base?->attrs() ?? 0;

        for ($k = 0; $k < $count; $k++) {
            $code = $params[$k];

            if ($code === 38 || $code === 48) {
                $rgb = $this->readExtendedColor($params, $k);
                if ($rgb === null) {
                    break; // malformed payload — stop consuming, like a terminal
                }
                if ($code === 38) {
                    $fg = $rgb;
                } else {
                    $bg = $rgb;
                }
                continue;
            }

            if ($code === 0) {
                $fg = null;
                $bg = null;
                $attrs = 0;
            } elseif ($code === 1) {
                $attrs |= Style::ATTR_BOLD;
            } elseif ($code === 2) {
                $attrs |= Style::ATTR_FAINT;
            } elseif ($code === 3) {
                $attrs |= Style::ATTR_ITALIC;
            } elseif ($code === 4) {
                $attrs |= Style::ATTR_UNDERLINE;
            } elseif ($code === 5) {
                $attrs |= Style::ATTR_BLINK;
            } elseif ($code === 7) {
                $attrs |= Style::ATTR_REVERSE;
            } elseif ($code === 8) {
                $attrs |= Style::ATTR_INVISIBLE;
            } elseif ($code === 9) {
                $attrs |= Style::ATTR_STRIKE;
            } elseif ($code === 53) {
                $attrs |= Style::ATTR_OVERLINE;
            } elseif ($code === 22) {
                $attrs &= ~(Style::ATTR_BOLD | Style::ATTR_FAINT);
            } elseif ($code === 23) {
                $attrs &= ~Style::ATTR_ITALIC;
            } elseif ($code === 24) {
                $attrs &= ~Style::ATTR_UNDERLINE;
            } elseif ($code === 25) {
                $attrs &= ~Style::ATTR_BLINK;
            } elseif ($code === 27) {
                $attrs &= ~Style::ATTR_REVERSE;
            } elseif ($code === 28) {
                $attrs &= ~Style::ATTR_INVISIBLE;
            } elseif ($code === 29) {
                $attrs &= ~Style::ATTR_STRIKE;
            } elseif ($code === 55) {
                $attrs &= ~Style::ATTR_OVERLINE;
            } elseif ($code >= 30 && $code <= 37) {
                $fg = $this->ansiColorToRgb($code - 30, false);
            } elseif ($code === 39) {
                $fg = null;
            } elseif ($code >= 40 && $code <= 47) {
                $bg = $this->ansiColorToRgb($code - 40, false);
            } elseif ($code === 49) {
                $bg = null;
            } elseif ($code >= 90 && $code <= 97) {
                $fg = $this->ansiColorToRgb($code - 90, true);
            } elseif ($code >= 100 && $code <= 107) {
                $bg = $this->ansiColorToRgb($code - 100, true);
            }
            // unknown code: skipped without disturbing the state
        }

        if ($fg === null && $bg === null && $attrs === 0) {
            return null;
        }
        return new Style($fg, $bg, $attrs);
    }

    /**
     * Decode the `5;n` / `2;r;g;b` payload trailing a 38/48 code at
     * {@see $k}, advancing $k past every consumed parameter. Returns the
     * packed 0xRRGGBB int, or null when the payload is missing, has an
     * unsupported selector, or carries an out-of-range component.
     *
     * @param list<int> $params
     */
    private function readExtendedColor(array $params, int &$k): ?int
    {
        $selector = $params[$k + 1] ?? null;
        if ($selector === 5) {
            $idx = $params[$k + 2] ?? null;
            if ($idx === null || $idx < 0 || $idx > 255) {
                return null;
            }
            $k += 2;
            return $this->ansi256ToRgb($idx);
        }
        if ($selector === 2) {
            $r = $params[$k + 2] ?? null;
            $g = $params[$k + 3] ?? null;
            $b = $params[$k + 4] ?? null;
            foreach ([$r, $g, $b] as $component) {
                if ($component === null || $component < 0 || $component > 255) {
                    return null;
                }
            }
            $k += 4;
            return ($r << 16) | ($g << 8) | $b;
        }
        return null;
    }

    /**
     * xterm-256 index → packed RGB via candy-core's canonical table, so the
     * 16-colour slots can never fork away from {@see ansiColorToRgb()}.
     */
    private function ansi256ToRgb(int $idx): int
    {
        $color = Color::ansi256($idx);
        return ($color->r << 16) | ($color->g << 8) | $color->b;
    }

    /**
     * Map an SGR colour index (0-7, bright variant when {@see $bright}) to a
     * packed 24-bit RGB int. The triples come from candy-core's canonical
     * xterm table — slot 4 = `#0000EE` (`main.h DEF_COLOR4 "blue2"`), slot 12
     * = `#5C5CFF` (`DEF_COLOR12 "rgb:5c/5c/ff"`) — indexed from
     * {@see Color::ANSI16_RGB} so this decode can never fork its own blues
     * again. Indices that resolve outside the 16-slot table fall back to its
     * white slot (7, or 15 for the bright half), preserving the old
     * default-to-white.
     */
    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $offset = $bright ? 8 : 0;
        [$r, $g, $b] = Color::ANSI16_RGB[$offset + $idx] ?? Color::ANSI16_RGB[$offset + 7];
        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Display width of one grapheme cluster in cells: 0, 1, or 2.
     *
     * Delegates to candy-core's canonical {@see Width} oracle (audit M8) — the
     * old local fork measured emoji (U+1F300+) and other EAW-wide ranges as
     * 1 cell, desynchronising box padding from every other SugarCraft
     * renderer (a padded row then overflowed its own border by one cell).
     * Two grid-specific divergences stay deliberate and documented: a TAB
     * occupies ONE spacer cell (Width charges E69's TAB_WIDTH = 4, which a
     * fixed cell grid cannot represent), and the result is clamped into the
     * Cell contract's 0–2 so a multi-cell cluster can never blow the grid.
     */
    private function graphemeWidth(string $g): int
    {
        if ($g === '') return 0;
        if ($g === "\t") return 1;
        return (int) \min(2, \max(0, Width::string($g)));
    }

    /**
     * Render a progress bar using Unicode block characters.
     *
     * @param float $progress  0.0 to 1.0
     * @param int $width  Available width in cells
     */
    private function renderProgressBar(float $progress, int $width): string
    {
        // $width is the inner cell width (between the vertical borders).
        $width = \max(4, $width);
        $progress = \max(0.0, \min(1.0, $progress));
        $filled = (int) \round($progress * $width);
        $filled = \max(0, \min($width, $filled));
        $empty = $width - $filled;

        // Each block glyph (█ / ░) is one display cell; build exactly
        // $width cells so the bar aligns flush with the borders.
        $bar = \str_repeat('█', $filled) . \str_repeat('░', $empty);
        return '│' . $bar . '│';
    }

    /**
     * Box width for a message of $messageLen display cells, capped by BOTH
     * maxWidth and the render canvas ($capWidth) — a box may never exceed the
     * frame it is blitted onto (audit C2).
     */
    private function resolveWidth(int $messageLen, int $capWidth): int
    {
        $cap = max(1, min($this->maxWidth, $capWidth));
        if ($this->minWidth <= 0) {
            return $cap;
        }
        // WHY: NerdFont/Unicode icons are 1 display cell; ASCII "[E]" is 3 cells.
        // The +1 accounts for the trailing space after the icon in renderAlert().
        $iconSpace = match ($this->symbols) {
            SymbolSet::Ascii => 3,
            default => 1,
        } + 1;
        $needed = $messageLen + $iconSpace + 4;  // + borders + padding
        return max(1, min($cap, max($this->minWidth, min($needed, $this->maxWidth))));
    }

    private function wordWrap(string $text, int $width): array
    {
        if ($width <= 0) return [''];
        $result = [];
        foreach (\explode("\n", $text) as $para) {
            $words = \preg_split('/\s+/', $para) ?: [];
            $current = '';
            foreach ($words as $word) {
                $test = $current === '' ? $word : $current . ' ' . $word;
                // Measure by display cells, not bytes, so multibyte words
                // wrap at the visible column rather than a byte boundary.
                if (Width::string($test) <= $width) {
                    $current = $test;
                } else {
                    if ($current !== '') $result[] = $current;
                    if (Width::string($word) > $width) {
                        // Split oversized word at cell boundaries (never
                        // mid-grapheme).
                        $remaining = $word;
                        while (Width::string($remaining) > $width) {
                            $chunk = Width::truncate($remaining, $width);
                            if ($chunk === '') {
                                break;
                            }
                            $result[] = $chunk;
                            $remaining = \substr($remaining, \strlen($chunk));
                        }
                        $current = $remaining;
                    } else {
                        $current = $word;
                    }
                }
            }
            if ($current !== '') $result[] = $current;
        }
        return $result ?: [''];
    }

    private function splitLines(string $text): array
    {
        $lines = \explode("\n", $text);
        if (\end($lines) === '') \array_pop($lines);
        return $lines;
    }
}
