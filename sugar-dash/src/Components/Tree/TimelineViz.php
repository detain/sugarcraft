<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Tree;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A timeline visualization component for displaying events chronologically.
 *
 * Features:
 * - Vertical timeline with time markers
 * - Event nodes on the timeline
 * - Color-coded event types
 * - Collapsible groups
 * - Duration显示
 *
 * Mirrors timeline/gantt patterns adapted to PHP with wither-style immutable setters.
 */
final class TimelineViz implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<TimelineEvent> $events
     */
    public function __construct(
        private array $events = [],
        private bool $showMarkers = true,
        private bool $showDescriptions = true,
        private ?Color $lineColor = null,
        private ?Color $markerColor = null,
        private ?Color $textColor = null,
        private string $orientation = 'vertical',
    ) {}

    /**
     * Create a new timeline visualization.
     *
     * @param list<TimelineEvent> $events
     */
    public static function new(array $events = []): self
    {
        return new self(
            events: $events,
            showMarkers: true,
            showDescriptions: true,
            lineColor: Color::hex('#45475A'),
            markerColor: Color::hex('#89B4FA'),
            textColor: Color::hex('#CDD6F4'),
            orientation: 'vertical',
        );
    }

    /**
     * Create a sample timeline for demonstration.
     */
    public static function sample(): self
    {
        return self::new([
            new TimelineEvent(
                time: '09:00',
                title: 'Project Kickoff',
                description: 'Initial team meeting',
                type: 'milestone'
            ),
            new TimelineEvent(
                time: '10:30',
                title: 'Design Review',
                description: 'UI/UX review session',
                type: 'meeting'
            ),
            new TimelineEvent(
                time: '12:00',
                title: 'Lunch Break',
                description: null,
                type: 'break'
            ),
            new TimelineEvent(
                time: '13:00',
                title: 'Development Sprint',
                description: 'Implement feature A',
                type: 'work'
            ),
            new TimelineEvent(
                time: '17:00',
                title: 'Daily Standup',
                description: 'End of day sync',
                type: 'meeting'
            ),
        ]);
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
     * Get the marker character for an event type.
     */
    private function getMarkerChar(string $type): string
    {
        return match ($type) {
            'milestone' => '◆',
            'meeting' => '○',
            'work' => '●',
            'break' => '□',
            'deadline' => '▲',
            'review' => '◇',
            default => '•',
        };
    }

    /**
     * Get the color for an event type.
     */
    private function getEventColor(string $type): Color
    {
        return match ($type) {
            'milestone' => Color::hex('#F9E2AF'),
            'meeting' => Color::hex('#89B4FA'),
            'work' => Color::hex('#A6E3A1'),
            'break' => Color::hex('#94E2D5'),
            'deadline' => Color::hex('#F38BA8'),
            'review' => Color::hex('#CBA6F7'),
            default => Color::hex('#6C7086'),
        };
    }

    /**
     * Render the timeline.
     */
    public function render(): string
    {
        if (empty($this->events)) {
            return '';
        }

        $result = '';
        $useWidth = $this->width ?? 50;
        $lineColor = $this->lineColor ?? Color::hex('#45475A');

        foreach ($this->events as $index => $event) {
            $isLast = $index === count($this->events) - 1;

            // Time
            $timeStr = $event->time ?? '';

            // Marker
            $markerChar = $this->getMarkerChar($event->type);
            $markerColor = $this->markerColor ?? $this->getEventColor($event->type);

            // Title
            $titleStr = $event->title ?? '';

            // Calculate padding
            $timeLen = mb_strlen($timeStr, 'UTF-8');
            $markerLen = 2;
            $titleLen = mb_strlen($titleStr, 'UTF-8');
            $remaining = $useWidth - $timeLen - $markerLen - $titleLen - 2;

            // Build line: time | marker title [description]
            $line = '';

            // Time
            if ($this->textColor !== null) {
                $line .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $timeStr;
            if ($this->textColor !== null) {
                $line .= Ansi::reset();
            }
            $line .= ' ';

            // Vertical line (timeline connector)
            if ($this->showMarkers) {
                $line .= $lineColor->toFg(ColorProfile::TrueColor);
                if (!$isLast) {
                    $line .= '│';
                } else {
                    $line .= '│';
                }
                $line .= ' ';
                $line .= Ansi::reset();
            }

            // Marker
            $line .= $markerColor->toFg(ColorProfile::TrueColor);
            $line .= $markerChar;
            $line .= Ansi::reset();
            $line .= ' ';

            // Title
            if ($this->textColor !== null) {
                $line .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $titleStr;
            if ($this->textColor !== null) {
                $line .= Ansi::reset();
            }

            // Description (if space allows and enabled)
            if ($this->showDescriptions && $event->description !== null) {
                $desc = ' - ' . $event->description;
                $currentLen = mb_strlen($line, 'UTF-8');
                $remainingSpace = $useWidth - $currentLen;

                if ($remainingSpace > mb_strlen($desc, 'UTF-8')) {
                    $line .= $desc;
                } elseif ($remainingSpace > 3) {
                    $line .= mb_substr($desc, 0, $remainingSpace - 1, 'UTF-8') . '…';
                }
            }

            $result .= mb_substr($line, 0, $useWidth, 'UTF-8');
            if (!$isLast) {
                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this timeline.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        if (empty($this->events)) {
            return [0, 0];
        }

        $width = 0;
        foreach ($this->events as $event) {
            $lineLen = 0;
            $lineLen += mb_strlen($event->time ?? '', 'UTF-8') + 1;
            $lineLen += 2; // marker + space
            $lineLen += mb_strlen($event->title ?? '', 'UTF-8');
            if ($event->description !== null) {
                $lineLen += 3 + mb_strlen($event->description, 'UTF-8');
            }
            $width = max($width, $lineLen);
        }

        return [$width, count($this->events)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the events.
     *
     * @param list<TimelineEvent> $events
     */
    public function withEvents(array $events): self
    {
        $clone = clone $this;
        $clone->events = $events;
        return $clone;
    }

    /**
     * Show or hide markers.
     */
    public function withShowMarkers(bool $show): self
    {
        $clone = clone $this;
        $clone->showMarkers = $show;
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
     * Set the line color.
     */
    public function withLineColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->lineColor = $color;
        return $clone;
    }

    /**
     * Set the marker color.
     */
    public function withMarkerColor(?Color $color): self
    {
        $clone = clone $this;
        $clone->markerColor = $color;
        return $clone;
    }
}

/**
 * An event in a timeline.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public string $time,
        public string $title,
        public ?string $description = null,
        public string $type = 'default',
    ) {}
}
