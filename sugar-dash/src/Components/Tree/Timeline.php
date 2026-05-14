<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Dash\Layout\HAlign;

/**
 * A timeline component for displaying chronological events.
 *
 * Features:
 * - Vertical timeline with events
 * - Configurable line style (solid, dashed, dotted)
 * - Optional icons for each event
 * - Different event types with colors (info, success, warning, error)
 * - Configurable connector style
 *
 * Mirrors timeline patterns adapted to PHP with wither-style immutable setters.
 */
final class Timeline implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const TypeInfo = 'info';
    public const TypeSuccess = 'success';
    public const TypeWarning = 'warning';
    public const TypeError = 'error';
    public const TypeDefault = 'default';

    /**
     * @param list<array{time: string, title: string, description?: string, type?: string, color?: Color|null}> $events
     */
    public function __construct(
        private array $events,
        private string $lineStyle = 'solid',
        private HAlign $align = HAlign::Left,
        private bool $showDescriptions = true,
        private bool $showIcons = true,
        private bool $reverse = false,
    ) {}

    /**
     * Create a new timeline with the given events.
     *
     * @param list<array{time: string, title: string, description?: string, type?: string, color?: string|Color|null}> $events
     */
    public static function new(array $events): self
    {
        return new self(events: $events);
    }

    /**
     * Create a timeline with default Catppuccin Mocha colors.
     *
     * @param list<array{time: string, title: string, description?: string, type?: string}> $events
     */
    public static function mocha(array $events): self
    {
        return new self(events: $events);
    }

    /**
     * Set the allocated dimensions for this timeline.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this timeline.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 60;
        $useHeight = $this->height ?? max(3, count($this->events) * 3);
        return [$useWidth, $useHeight];
    }

    /**
     * Render the timeline.
     */
    public function render(): string
    {
        if ($this->events === []) {
            return '';
        }

        $events = $this->reverse ? array_reverse($this->events) : $this->events;
        $useWidth = $this->width ?? 60;

        $result = [];
        $connectorChar = $this->getConnectorChar();
        $connectorColor = Color::hex('#6C7086');

        $timelineColor = Color::hex('#89B4FA');
        $defaultColors = [
            self::TypeInfo => Color::hex('#89B4FA'),
            self::TypeSuccess => Color::hex('#A6E3A1'),
            self::TypeWarning => Color::hex('#F9E2AF'),
            self::TypeError => Color::hex('#F38BA8'),
            self::TypeDefault => Color::hex('#CBA6F7'),
        ];

        for ($i = 0; $i < count($events); $i++) {
            $event = $events[$i];
            $type = $event['type'] ?? self::TypeDefault;
            $color = $event['color'] ?? ($defaultColors[$type] ?? $defaultColors[self::TypeDefault]);

            $time = $event['time'];
            $title = $event['title'];
            $description = $event['description'] ?? '';

            // Render time on the left
            $timeStr = '[' . $time . ']';

            // Render title
            $titleStr = $title;

            // Determine icons
            $icon = $this->showIcons ? $this->getIcon($type) : '';

            // Build the event line
            $lineContent = trim("$icon $timeStr $titleStr");
            if ($this->align === HAlign::Right) {
                $lineContent = str_pad($lineContent, $useWidth, ' ', STR_PAD_LEFT);
            }

            // Add color
            $line = $color->toFg(ColorProfile::TrueColor) . $lineContent . Ansi::reset();

            // Add connector line (except for last event)
            if ($i < count($events) - 1) {
                $result[] = $line;
                $connectorLine = str_repeat($connectorChar, $useWidth);
                $result[] = $connectorColor->toFg(ColorProfile::TrueColor) . $connectorLine . Ansi::reset();
            } else {
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    /**
     * Get the connector character based on line style.
     */
    private function getConnectorChar(): string
    {
        return match ($this->lineStyle) {
            'solid' => '│',
            'dashed' => '┆',
            'dotted' => '┊',
            'double' => '║',
            default => '│',
        };
    }

    /**
     * Get the icon for an event type.
     */
    private function getIcon(string $type): string
    {
        return match ($type) {
            self::TypeInfo => '●',
            self::TypeSuccess => '✓',
            self::TypeWarning => '⚠',
            self::TypeError => '✗',
            default => '○',
        };
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the line style.
     */
    public function withLineStyle(string $style): self
    {
        $clone = clone $this;
        $clone->lineStyle = $style;
        return $clone;
    }

    /**
     * Set the alignment.
     */
    public function withAlign(HAlign $align): self
    {
        $clone = clone $this;
        $clone->align = $align;
        return $clone;
    }

    /**
     * Show or hide descriptions.
     */
    public function withShowDescriptions(bool $show): self
    {
        $clone = clone $this;
        $clone->showDescriptions = $show;
        return $clone;
    }

    /**
     * Show or hide icons.
     */
    public function withShowIcons(bool $show): self
    {
        $clone = clone $this;
        $clone->showIcons = $show;
        return $clone;
    }

    /**
     * Reverse the timeline order.
     */
    public function withReverse(bool $reverse): self
    {
        $clone = clone $this;
        $clone->reverse = $reverse;
        return $clone;
    }
}
