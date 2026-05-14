<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An activity feed component for displaying a stream of events.
 *
 * Features:
 * - Chronological event list
 * - Event type icons
 * - Timestamp display
 * - Actor and action descriptions
 * - Color-coded by event type
 *
 * Mirrors activity feed patterns adapted to PHP with wither-style immutable setters.
 */
final class ActivityFeed implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param list<ActivityEvent> $events
     */
    public function __construct(
        private readonly array $events = [],
        private readonly bool $showIcons = true,
        private readonly bool $showTimestamps = true,
        private readonly bool $showAvatars = false,
        private readonly ?Color $iconColor = null,
        private readonly ?Color $timestampColor = null,
        private readonly ?Color $textColor = null,
        private readonly int $maxItems = 10,
    ) {}

    /**
     * Create a new activity feed.
     *
     * @param list<ActivityEvent> $events
     */
    public static function new(array $events = []): self
    {
        return new self(
            events: $events,
            showIcons: true,
            showTimestamps: true,
            showAvatars: false,
            iconColor: Color::hex('#89B4FA'),
            timestampColor: Color::hex('#6C7086'),
            textColor: Color::hex('#CDD6F4'),
            maxItems: 10,
        );
    }

    /**
     * Create a sample activity feed for demonstration.
     */
    public static function sample(): self
    {
        return self::new([
            new ActivityEvent(
                actor: 'Alice',
                action: 'pushed to',
                target: 'main branch',
                type: 'push',
                timestamp: '2 min ago'
            ),
            new ActivityEvent(
                actor: 'Bob',
                action: 'opened pull request',
                target: 'feat: new feature',
                type: 'pr',
                timestamp: '15 min ago'
            ),
            new ActivityEvent(
                actor: 'Charlie',
                action: 'merged pull request',
                target: 'fix: bug #123',
                type: 'merge',
                timestamp: '1 hour ago'
            ),
            new ActivityEvent(
                actor: 'Diana',
                action: 'commented on',
                target: 'Issue #456',
                type: 'comment',
                timestamp: '2 hours ago'
            ),
        ]);
    }

    /**
     * Set the allocated dimensions for this feed.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the icon for an event type.
     */
    private function getEventIcon(string $type): string
    {
        return match ($type) {
            'push' => '→',
            'pr' => '⇄',
            'merge' => '⤴',
            'comment' => '💬',
            'star' => '★',
            'fork' => '⎇',
            'issue' => '●',
            'release' => '⬆',
            'deploy' => '🚀',
            default => '•',
        };
    }

    /**
     * Get the color for an event type.
     */
    private function getEventColor(string $type): Color
    {
        return match ($type) {
            'push' => Color::hex('#89B4FA'),
            'pr' => Color::hex('#A6E3A1'),
            'merge' => Color::hex('#CBA6F7'),
            'comment' => Color::hex('#F9E2AF'),
            'star' => Color::hex('#F38BA8'),
            'fork' => Color::hex('#94E2D5'),
            'issue' => Color::hex('#FAB387'),
            'release' => Color::hex('#74C7EC'),
            'deploy' => Color::hex('#A6E3A1'),
            default => Color::hex('#6C7086'),
        };
    }

    /**
     * Render the activity feed.
     */
    public function render(): string
    {
        if (empty($this->events)) {
            return '';
        }

        $result = '';
        $useWidth = $this->width ?? 50;
        $maxItems = min($this->maxItems, count($this->events));

        for ($i = 0; $i < $maxItems; $i++) {
            $event = $this->events[$i];
            $line = '';

            // Icon
            if ($this->showIcons) {
                $iconColor = $this->iconColor ?? $this->getEventColor($event->type);
                $line .= $iconColor->toFg(ColorProfile::TrueColor);
                $line .= $this->getEventIcon($event->type) . ' ';
                $line .= Ansi::reset();
            }

            // Actor and action
            $text = $event->actor . ' ' . $event->action;
            if ($this->textColor !== null) {
                $line .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $line .= $text;
            if ($this->textColor !== null) {
                $line .= Ansi::reset();
            }

            // Target (truncated if needed)
            $target = ' ' . $event->target;
            $remainingWidth = $useWidth - mb_strlen($line, 'UTF-8') - 1;

            if ($remainingWidth > 3) {
                if (mb_strlen($target, 'UTF-8') > $remainingWidth) {
                    $target = mb_substr($target, 0, $remainingWidth - 1, 'UTF-8') . '…';
                }
                $line .= $target;
            }

            // Timestamp
            if ($this->showTimestamps && $event->timestamp !== null) {
                $ts = ' ' . $event->timestamp;
                $lineLen = mb_strlen($line, 'UTF-8');
                $tsLen = mb_strlen($ts, 'UTF-8');

                if ($lineLen + $tsLen < $useWidth) {
                    $line .= str_repeat(' ', $useWidth - $lineLen - $tsLen);
                    if ($this->timestampColor !== null) {
                        $line .= $this->timestampColor->toFg(ColorProfile::TrueColor);
                    }
                    $line .= $ts;
                    if ($this->timestampColor !== null) {
                        $line .= Ansi::reset();
                    }
                }
            }

            $result .= mb_substr($line, 0, $useWidth, 'UTF-8');
            if ($i < $maxItems - 1) {
                $result .= "\n";
            }
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this feed.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $width = $this->width ?? 50;
        $maxItems = min($this->maxItems, count($this->events));
        $height = $maxItems;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the events.
     *
     * @param list<ActivityEvent> $events
     */
    public function withEvents(array $events): self
    {
        return new self(
            events: $events,
            showIcons: $this->showIcons,
            showTimestamps: $this->showTimestamps,
            showAvatars: $this->showAvatars,
            iconColor: $this->iconColor,
            timestampColor: $this->timestampColor,
            textColor: $this->textColor,
            maxItems: $this->maxItems,
        );
    }

    /**
     * Show or hide icons.
     */
    public function withShowIcons(bool $show): self
    {
        return new self(
            events: $this->events,
            showIcons: $show,
            showTimestamps: $this->showTimestamps,
            showAvatars: $this->showAvatars,
            iconColor: $this->iconColor,
            timestampColor: $this->timestampColor,
            textColor: $this->textColor,
            maxItems: $this->maxItems,
        );
    }

    /**
     * Show or hide timestamps.
     */
    public function withShowTimestamps(bool $show): self
    {
        return new self(
            events: $this->events,
            showIcons: $this->showIcons,
            showTimestamps: $show,
            showAvatars: $this->showAvatars,
            iconColor: $this->iconColor,
            timestampColor: $this->timestampColor,
            textColor: $this->textColor,
            maxItems: $this->maxItems,
        );
    }

    /**
     * Set the maximum number of items to display.
     */
    public function withMaxItems(int $max): self
    {
        return new self(
            events: $this->events,
            showIcons: $this->showIcons,
            showTimestamps: $this->showTimestamps,
            showAvatars: $this->showAvatars,
            iconColor: $this->iconColor,
            timestampColor: $this->timestampColor,
            textColor: $this->textColor,
            maxItems: $max,
        );
    }
}

/**
 * An event in an activity feed.
 */
final readonly class ActivityEvent
{
    public function __construct(
        public string $actor,
        public string $action,
        public string $target,
        public string $type = 'default',
        public ?string $timestamp = null,
    ) {}
}
