<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Buffer\Region;
use SugarCraft\Buffer\Position as BufferPosition;
use SugarCraft\Core\Util\Ansi;
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

    /** Dismissed flag — if true, Toast won't render any alerts. */
    private bool $dismissed = false;

    /** Host-consumed flag: whether the host should dismiss on Escape key press. The renderer stores this preference; it does not handle input itself. */
    private bool $allowEscToClose = true;

    /** Maximum number of concurrent alerts (null = unlimited). */
    private ?int $maxConcurrent = null;

    /** Overflow strategy when queue exceeds maxConcurrent. */
    private Overflow $overflow = Overflow::DropOldest;

    /** History log of dismissed alerts. */
    private HistoryLog $historyLog;

    /** Fade animation duration in seconds (0 = disabled). */
    private float $animationDuration = 0.0;

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
     * Set fade animation duration in seconds.
     *
     * When > 0, a simple character-reveal animation hint is rendered.
     * True CubicBezier easing requires the honey-bounce library (step 09.17).
     */
    public function withAnimationDuration(float $seconds): self
    {
        return $this->mutate(['animationDuration' => \max(0.0, $seconds)]);
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
     */
    public function alert(ToastType|string $type, string $message, ?float $expiresAt = null): self
    {
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = new Alert($resolvedType, $message, $expiresAt);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        // Apply overflow strategy when maxConcurrent is set
        if ($clone->maxConcurrent !== null && \count($clone->queue) >= $clone->maxConcurrent) {
            if ($clone->overflow === Overflow::DropNewest) {
                return $clone;  // discard the new alert
            }
            if ($clone->overflow === Overflow::DropOldest) {
                \array_shift($clone->queue);
            }
            // Enqueue: do nothing, allow exceeding max
        }

        $clone->queue[] = $alert;
        return $clone;
    }

    /**
     * Add a progress toast — renders a progress bar beneath the message.
     *
     * @param float $progress  Value between 0.0 and 1.0 (clamped)
     */
    public function progressToast(ToastType|string $type, string $message, float $progress, ?float $expiresAt = null): self
    {
        $resolvedType = $type instanceof ToastType
            ? $type
            : ToastType::tryFrom(\strtolower($type))
                ?? throw new \InvalidArgumentException("Unknown toast type: {$type}");

        $clone = clone $this;
        $alert = (new Alert($resolvedType, $message, $expiresAt))->withProgress($progress);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
        }

        if ($clone->maxConcurrent !== null && \count($clone->queue) >= $clone->maxConcurrent) {
            if ($clone->overflow === Overflow::DropNewest) {
                return $clone;
            }
            if ($clone->overflow === Overflow::DropOldest) {
                \array_shift($clone->queue);
            }
        }

        $clone->queue[] = $alert;
        return $clone;
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
     * Dismiss all alerts and record them in the history log.
     */
    public function dismiss(): self
    {
        $clone = clone $this;

        // Record active (non-expired) alerts to history before dismissing
        foreach ($clone->queue as $alert) {
            if (!$alert->isExpired()) {
                $clone->historyLog = $clone->historyLog->push($alert);
            }
        }

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
     * Clear the entire queue.
     */
    public function clear(): self
    {
        $clone = clone $this;
        $clone->queue = [];
        return $clone;
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
     * @return list<Alert>
     */
    public function getHistory(): array
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
     * @param string $background  The underlying viewport content
     * @param int $viewportWidth  Viewport width in cells
     * @param int $viewportHeight Viewport height in lines
     * @return string  The composited output
     */
    public function View(string $background, int $viewportWidth = 80, int $viewportHeight = 24): string
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

        // WHY: Bottom/Middle yOffset depends on the total height. We must
        // decide the canonical height BEFORE computing any yOffset so both
        // the sizing pass and placement pass agree on the same coordinate
        // system. The viewport must be at least as tall as the background
        // and the caller's viewportHeight.
        $h = max($bgRowCount, $viewportHeight);

        $cumulativeHeight = 0;
        $lastAlertY = 0;
        $lastAlertHeight = 0;
        foreach ($active as $alert) {
            $alertStr = $this->renderAlert($alert);
            $alertLines = $this->splitLines($alertStr);
            $alertHeight = \count($alertLines);
            $lastAlertY = $this->position->yOffset($alertHeight, $h, $cumulativeHeight - $alertHeight);
            $lastAlertHeight = $alertHeight;
            $cumulativeHeight += $alertHeight;
        }

        // Use the canonical height as the base, growing only if alerts
        // extend beyond it. Bottom/Middle anchored to this larger height
        // will only move further down/center — no need to re-derive.
        $contentHeight = max($h, $lastAlertY + $lastAlertHeight);

        $contentWidth = min($this->maxWidth, $viewportWidth);

        $viewport = Buffer::new($contentWidth, $contentHeight);
        $viewport = $this->fillViewportFromString($viewport, $bgLines);

        $cumulativeHeight = 0;
        foreach ($active as $alert) {
            $alertBuf = $this->renderAlertToBuffer($alert);
            $alertHeight = $alertBuf->height();
            $alertWidth = $alertBuf->width();

            $x = $this->position->xOffset($alertWidth, $contentWidth);
            $y = $this->position->yOffset($alertHeight, $contentHeight, $cumulativeHeight);

            $region = new Region(BufferPosition::new($x, $y), $alertWidth, $alertHeight);
            $viewport = $viewport->withRegion($region, $alertBuf);
            $cumulativeHeight += $alertHeight;
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

    private function renderAlert(Alert $alert): string
    {
        $width = $this->resolveWidth(Width::string($alert->message));
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
        $iconCells = Width::string($prefix);
        $headerWrap = $this->wordWrap($alert->message, \max(1, $inner - $iconCells));
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
     * Render an alert into a Buffer.
     *
     * Parses the ANSI-encoded string from renderAlert() to extract SGR
     * sequences and builds cells with proper Buffer Style objects, so
     * toAnsi() produces correct styled output. Mirrors charmbracelet/bubbleup's
     * alert rendering pipeline.
     */
    private function renderAlertToBuffer(Alert $alert): Buffer
    {
        $alertStr = $this->renderAlert($alert);
        $lines = $this->splitLines($alertStr);

        $height = \count($lines);
        $width = $this->maxWidth;

        $buf = Buffer::new($width, $height);
        for ($row = 0; $row < $height; $row++) {
            $buf = $this->placeAnsiStringAt($buf, 0, $row, $lines[$row]);
        }

        return $buf;
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
                $currentStyle = $this->sgrToBufferStyle($seq);
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
     */
    private function nextCluster(string $s, int $i): string
    {
        if (\function_exists('grapheme_extract')) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (\is_string($cluster) && $cluster !== '') {
                return $cluster;
            }
        }
        $b = \ord($s[$i]);
        $bytes = match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
        return \substr($s, $i, $bytes);
    }

    /**
     * Convert ANSI SGR color code (e.g. "31" or "1;32") to a Buffer Style.
     * SGR "0" means reset all attributes, returning null.
     */
    private function sgrToBufferStyle(string $sgr): ?Style
    {
        $codes = \explode(';', $sgr);
        foreach ($codes as $code) {
            if ((int) $code === 0) {
                return null;
            }
        }
        $fg = null;
        $attrs = 0;
        foreach ($codes as $code) {
            $code = (int) $code;
            if ($code >= 30 && $code <= 37) {
                $fg = $this->ansiColorToRgb($code - 30, false);
            } elseif ($code >= 90 && $code <= 97) {
                $fg = $this->ansiColorToRgb($code - 90, true);
            } elseif ($code === 1) {
                $attrs |= Style::ATTR_BOLD;
            }
        }
        return new Style($fg, null, $attrs);
    }

    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $colors = [
            [0, 0, 0],       // black
            [128, 0, 0],     // red
            [0, 128, 0],     // green
            [128, 128, 0],  // yellow
            [0, 0, 128],     // blue
            [128, 0, 128],  // magenta
            [0, 128, 128],  // cyan
            [192, 192, 192], // white
        ];
        $c = $colors[$idx] ?? [192, 192, 192];
        if ($bright) {
            $c = [\min(255, $c[0] + 96), \min(255, $c[1] + 96), \min(255, $c[2] + 96)];
        }
        return ($c[0] << 16) | ($c[1] << 8) | $c[2];
    }

    private function graphemeWidth(string $g): int
    {
        if ($g === '') return 0;
        $cp = \function_exists('mb_ord') ? \mb_ord($g, 'UTF-8') : \ord($g[0]);
        if ($cp === false || $cp === 0) return 0;
        // ASCII control chars (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F, 0x7F) → 0
        if (($cp <= 0x08) || ($cp >= 0x0E && $cp <= 0x1F) || ($cp === 0x7F)) {
            return 0;
        }
        // Zero-width combining marks
        if (($cp >= 0x0300 && $cp <= 0x036F)
            || ($cp >= 0x0483 && $cp <= 0x0489)
            || ($cp >= 0x200b && $cp <= 0x200f)
            || ($cp >= 0x2028 && $cp <= 0x2029)
            || ($cp >= 0x2060 && $cp <= 0x2064)
            || ($cp === 0xfeff)) {
            return 0;
        }
        // Wide East-Asian chars → 2
        if (($cp >= 0x1100 && $cp <= 0x115f)
            || ($cp >= 0x3040 && $cp <= 0xfe6f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)) {
            return 2;
        }
        return 1;
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

    private function resolveWidth(int $messageLen): int
    {
        if ($this->minWidth <= 0) {
            return $this->maxWidth;
        }
        // WHY: NerdFont/Unicode icons are 1 display cell; ASCII "[E]" is 3 cells.
        // The +1 accounts for the trailing space after the icon in renderAlert().
        $iconSpace = match ($this->symbols) {
            SymbolSet::Ascii => 3,
            default => 1,
        } + 1;
        $needed = $messageLen + $iconSpace + 4;  // + borders + padding
        return \max($this->minWidth, \min($needed, $this->maxWidth));
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
