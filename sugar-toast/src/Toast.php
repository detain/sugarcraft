<?php

declare(strict_types=1);

namespace SugarCraft\Toast;

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

    /** Whether Escape key dismisses the active alert. */
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
        $clone = clone $this;
        $clone->maxWidth = $w;
        return $clone;
    }

    public function withMinWidth(int $w): self
    {
        $clone = clone $this;
        $clone->minWidth = $w;
        return $clone;
    }

    public function withPosition(Position $pos): self
    {
        $clone = clone $this;
        $clone->position = $pos;
        return $clone;
    }

    public function withSymbolSet(SymbolSet $set): self
    {
        $clone = clone $this;
        $clone->symbols = $set;
        return $clone;
    }

    /**
     * Auto-dismiss alerts after $duration seconds.
     * Pass null to disable auto-dismiss.
     */
    public function withDuration(?float $seconds): self
    {
        $clone = clone $this;
        $clone->duration = $seconds;
        return $clone;
    }

    /**
     * Control whether pressing Escape dismisses the active alert.
     */
    public function withAllowEscToClose(bool $allow): self
    {
        $clone = clone $this;
        $clone->allowEscToClose = $allow;
        return $clone;
    }

    /**
     * Set the maximum number of concurrent alerts.
     * Pass null for unlimited.
     */
    public function withMaxConcurrent(?int $max): self
    {
        $clone = clone $this;
        $clone->maxConcurrent = $max;
        return $clone;
    }

    /**
     * Set the overflow strategy when maxConcurrent is exceeded.
     */
    public function withOverflow(Overflow $overflow): self
    {
        $clone = clone $this;
        $clone->overflow = $overflow;
        return $clone;
    }

    /**
     * Set fade animation duration in seconds.
     *
     * When > 0, a simple character-reveal animation hint is rendered.
     * True CubicBezier easing requires the honey-bounce library (step 09.17).
     */
    public function withAnimationDuration(float $seconds): self
    {
        $clone = clone $this;
        $clone->animationDuration = \max(0.0, $seconds);
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
     * Render the toast layer composited over a background view.
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

        // Filter expired
        $active = \array_values(
            \array_filter($this->queue, fn(Alert $a): bool => !$a->isExpired())
        );

        if ($active === []) {
            return $background;
        }

        $bgLines = $this->splitLines($background);

        // First pass: compute total height of all active alerts for stacking
        $totalAlertLines = 0;
        foreach ($active as $alert) {
            $alertStr = $this->renderAlert($alert);
            $totalAlertLines += \count($this->splitLines($alertStr));
        }

        // Second pass: render each alert with proper cumulative stacking offset
        $cumulativeHeight = 0;
        foreach ($active as $alert) {
            $alertStr = $this->renderAlert($alert);
            $alertLines = $this->splitLines($alertStr);
            $alertWidth = $this->maxWidth;
            $alertHeight = \count($alertLines);

            $x = $this->position->xOffset($alertWidth, $viewportWidth);
            $y = $this->position->yOffset($alertHeight, $viewportHeight, $cumulativeHeight);

            $bgLines = $this->compositeLines($bgLines, $alertLines, $x, $y, $alertWidth);
            $cumulativeHeight += $alertHeight;
        }

        return \implode("\n", $bgLines);
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function renderAlert(Alert $alert): string
    {
        $width = $this->resolveWidth(\strlen($alert->message));
        $icon  = $alert->type->icon($this->symbols);
        $color = $alert->type->color();

        $prefix = "\x1b[{$color}m{$icon}\x1b[0m ";
        $header = $prefix . $alert->message;

        // Top border
        $top    = '╭' . \str_repeat('─', $width - 2) . '╮';
        $bottom = '╰' . \str_repeat('─', $width - 2) . '╯';

        // Word-wrap middle if needed
        $wrapped = $this->wordWrap($alert->message, $width - \strlen($icon) - 4);
        $middleLines = [\substr($prefix, 0, $width - 2)];
        foreach ($wrapped as $wl) {
            $middleLines[] = '│' . \str_pad(' ' . $wl, $width - 2) . '│';
        }

        // Render progress bar if set
        $progressBar = null;
        if ($alert->progress !== null) {
            $progressBar = $this->renderProgressBar($alert->progress, $width - 2);
            $middleLines[] = $progressBar;
        }

        // Render action buttons if any
        foreach ($alert->actions as $action) {
            $label = '[' . $action->label . ']';
            $middleLines[] = '│' . \str_pad($label, $width - 2, ' ', \STR_PAD_RIGHT) . '│';
        }

        $lines = [$top, ...$middleLines, $bottom];
        return \implode("\n", $lines);
    }

    /**
     * Render a progress bar using Unicode block characters.
     *
     * @param float $progress  0.0 to 1.0
     * @param int $width  Available width in cells
     */
    private function renderProgressBar(float $progress, int $width): string
    {
        $width = \max(4, $width);
        $filled = (int) \round($progress * $width);
        $empty = $width - $filled;

        $bar = '▰' . \str_repeat('█', $filled) . \str_repeat('░', $empty);
        return '│' . \str_pad($bar, $width, ' ', \STR_PAD_BOTH) . '│';
    }

    private function resolveWidth(int $messageLen): int
    {
        if ($this->minWidth <= 0) {
            return $this->maxWidth;
        }
        $iconSpace = \strlen($this->symbols->name) + 2;
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
                if (\strlen($test) <= $width) {
                    $current = $test;
                } else {
                    if ($current !== '') $result[] = $current;
                    if (\strlen($word) > $width) {
                        // Split oversized word
                        for ($i = 0; $i < \strlen($word); $i += $width) {
                            $result[] = \substr($word, $i, $width);
                        }
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

    private function compositeLines(array $bg, array $fg, int $x, int $y, int $w): array
    {
        for ($i = 0; $i < \count($fg); $i++) {
            $destY = $y + $i;
            if ($destY < 0 || $destY >= \count($bg)) continue;

            $fgLine = $fg[$i];
            $bgLine = $bg[$destY];

            // Ensure bg line is long enough
            $bgLine = \str_pad($bgLine, $x + $w, ' ');
            $pre  = \substr($bgLine, 0, $x);
            $post = \substr($bgLine, $x + $w);
            $bg[$destY] = $pre . \substr($fgLine, 0, $w) . $post;
        }
        return $bg;
    }
}
