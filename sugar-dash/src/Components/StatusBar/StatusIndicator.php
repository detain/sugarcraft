<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\StatusBar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

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
final class StatusIndicator implements \SugarCraft\Dash\Foundation\Sizer
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
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the status indicator.
     */
    public function render(): string
    {
        $symbol = $this->getSymbol();
        $color = $this->color;

        if ($color === null) {
            return $symbol;
        }

        $output = $color->toFg(ColorProfile::TrueColor);

        if ($this->pulse) {
            $tickMs = (int) (microtime(true) * 1000);
            // Use fmod for float modulo to avoid int/float deprecation in PHP 8.4
            $phase = fmod($tickMs / 500.0, 2.0);
            if ($phase < 1.0) {
                $output .= $symbol;
            } else {
                $dimColor = $color->toFg(ColorProfile::TrueColor);
                $output = $dimColor . $symbol;
            }
        } else {
            $output .= $symbol;
        }

        return $output . Ansi::reset();
    }

    /**
     * Get the symbol character for the indicator.
     */
    public function getSymbol(): string
    {
        $symbols = [
            self::Online => '●',
            self::Offline => '○',
            self::Warning => '⚠',
            self::Error => '✕',
            self::Info => '●',
        ];

        $symbol = $symbols[$this->status] ?? '●';

        if ($this->size > 1) {
            return str_repeat($symbol, $this->size);
        }

        return $symbol;
    }

    /**
     * Get the current status type.
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * Calculate the natural dimensions of this indicator.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
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
