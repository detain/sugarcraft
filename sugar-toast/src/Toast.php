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

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function __construct(int $maxWidth = 50)
    {
        $this->maxWidth = $maxWidth;
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

    // -------------------------------------------------------------------------
    // Alert operations
    // -------------------------------------------------------------------------

    /**
     * Add an alert to the queue. Returns a new Toast instance.
     *
     * If $expiresAt is provided, it overrides the configured duration.
     */
    public function alert(ToastType $type, string $message, ?float $expiresAt = null): self
    {
        $clone = clone $this;
        $alert = new Alert($type, $message, $expiresAt);
        if ($expiresAt === null && $clone->duration !== null) {
            $alert = $alert->withExpiry($clone->duration);
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
     * Dismiss all alerts (hide the toast layer).
     */
    public function dismiss(): self
    {
        $clone = clone $this;
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
        $middle = '│' . \str_pad($header, $width - 2, ' ', \STR_PAD_BOTH) . '│';
        $bottom = '╰' . \str_repeat('─', $width - 2) . '╯';

        // Word-wrap middle if needed
        $wrapped = $this->wordWrap($alert->message, $width - \strlen($icon) - 4);
        $middleLines = [\substr($prefix, 0, $width - 2)];
        foreach ($wrapped as $wl) {
            $middleLines[] = '│' . \str_pad(' ' . $wl, $width - 2) . '│';
        }

        $lines = [$top, ...$middleLines, $bottom];
        return \implode("\n", $lines);
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
