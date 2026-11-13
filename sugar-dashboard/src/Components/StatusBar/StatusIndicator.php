<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Components\StatusBar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dashboard\Foundation\ColorMode;

/**
 * A status indicator dot/light component.
 *
 * Displays a small colored indicator representing status:
 * - online/success (green), warning (yellow), error (red), info (blue)
 * - Optional pulsing animation indicator
 * - Custom colors and sizes
 *
 * Mirrors status indicator concepts adapted to PHP with wither-style immutable setters.
 */
final class StatusIndicator implements \SugarCraft\Dashboard\Foundation\SizedItem
{
    private ?int $width = null;
    private ?int $height = null;

    public const Online = 'online';
    public const Offline = 'offline';
    public const Warning = 'warning';
    public const Error = 'error';
    public const Info = 'info';

    public function __construct(
        private readonly string $status = self::Online,
        private readonly ?Color $color = null,
        private readonly bool $pulse = false,
        private readonly int $size = 1,
    ) {}

    /**
     * Create a new status indicator with default styling.
     */
    public static function new(string $status = self::Online): self
    {
        return new self(
            status: $status,
            color: self::getDefaultColor($status),
            pulse: false,
            size: 1,
        );
    }

    /**
     * Create an online/success status indicator.
     */
    public static function online(): self
    {
        return self::new(self::Online);
    }

    /**
     * Create an offline status indicator.
     */
    public static function offline(): self
    {
        return self::new(self::Offline);
    }

    /**
     * Create a warning status indicator.
     */
    public static function warning(): self
    {
        return self::new(self::Warning);
    }

    /**
     * Create an error status indicator.
     */
    public static function error(): self
    {
        return self::new(self::Error);
    }

    /**
     * Create an info status indicator.
     */
    public static function info(): self
    {
        return self::new(self::Info);
    }

    /**
     * Get the default color for a status.
     */
    private static function getDefaultColor(string $status): Color
    {
        return match ($status) {
            self::Online => Color::hex('#22C55E'),
            self::Offline => Color::hex('#6B7280'),
            self::Warning => Color::hex('#F59E0B'),
            self::Error => Color::hex('#EF4444'),
            self::Info => Color::hex('#3B82F6'),
            default => Color::hex('#22C55E'),
        };
    }

    /**
     * Set the allocated dimensions for this indicator.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dashboard\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Half-period of the pulse animation: 500 ms bright, 500 ms dim.
     */
    private const PULSE_HALF_PERIOD_MS = 500.0;

    /**
     * Blend ratio toward black used during the dark half-cycle.
     */
    private const PULSE_DIM_RATIO = 0.5;

    /**
     * Render the status indicator.
     *
     * The pulse phase is HOST-supplied, never read from a clock inside the
     * library: pass `render($now)` a timestamp in seconds (e.g. the host's
     * `microtime(true)`) and the dot alternates bright/dim on a 500 ms
     * half-period; a bare `render()` is the still, bright frame (a
     * reduced-motion presentation reachable from the outside, per the
     * frame-driven contract `Marquee` follows).
     */
    public function render(?float $now = null): string
    {
        $cells = $this->cellCount();
        if ($cells === 0) {
            // Allocation is zero-width: emit nothing rather than spill a
            // row past the panel that sized us (176: the size knob was
            // repeat-by-knob with no regard for allocation).
            return '';
        }

        $symbol = str_repeat($this->symbolChar(), $cells);
        $color = $this->color;

        if ($color === null) {
            return $symbol;
        }

        if ($this->pulse && $now !== null) {
            // fmod over ms/period in float space: intdiv would harden the
            // PHP 8.4 int/float boundary, and 0.0 keeps the bright phase.
            $phase = fmod($now * 1000.0 / self::PULSE_HALF_PERIOD_MS, 2.0);
            if ($phase >= 1.0) {
                // Real dim: the dark half-cycle blends 50% toward black
                // (the pre-fix branch re-emitted the SAME toFg expression,
                // so both phases were byte-identical and the "pulse"
                // never moved).
                $color = $color->blend(Color::rgb(0, 0, 0), self::PULSE_DIM_RATIO);
            }
        }

        return $color->toFg(ColorMode::profile()) . $symbol . Ansi::reset();
    }

    /**
     * Number of symbol cells to paint: the size knob clamped to the
     * allocated width when one was set via {@see self::setSize()}.
     */
    private function cellCount(): int
    {
        $cells = max(1, $this->size);

        return $this->width === null ? $cells : min($cells, max(0, $this->width));
    }

    /**
     * Get the symbol character for the indicator.
     */
    public function symbol(): string
    {
        $symbol = $this->symbolChar();

        return $this->size > 1 ? str_repeat($symbol, $this->size) : $symbol;
    }

    /**
     * Single glyph for the configured status.
     */
    private function symbolChar(): string
    {
        $symbols = [
            self::Online => '●',
            self::Offline => '○',
            self::Warning => '⚠',
            self::Error => '✕',
            self::Info => '●',
        ];

        return $symbols[$this->status] ?? '●';
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::symbol()} instead. REMOVED-IN: 2.0.0.
     */
    public function getSymbol(): string { return $this->symbol(); }

    /**
     * Get the current status type.
     */
    public function status(): string
    {
        return $this->status;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::status()} instead. REMOVED-IN: 2.0.0.
     */
    public function getStatus(): string { return $this->status(); }

    /**
     * Calculate the natural dimensions of this indicator.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function innerSize(): array
    {
        return [$this->size, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the status type.
     */
    public function withStatus(string $status): self
    {
        return new self(
            status: $status,
            color: $this->color ?? self::getDefaultColor($status),
            pulse: $this->pulse,
            size: $this->size,
        );
    }

    /**
     * Set the indicator color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            status: $this->status,
            color: $color,
            pulse: $this->pulse,
            size: $this->size,
        );
    }

    /**
     * Enable or disable pulse animation.
     */
    public function withPulse(bool $pulse): self
    {
        return new self(
            status: $this->status,
            color: $this->color,
            pulse: $pulse,
            size: $this->size,
        );
    }

    /**
     * Set the size (number of indicator characters).
     */
    public function withSize(int $size): self
    {
        return new self(
            status: $this->status,
            color: $this->color,
            pulse: $this->pulse,
            size: max(1, $size),
        );
    }
}
