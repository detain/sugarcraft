<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Todo;

/**
 * Where one {@see TodoItem} stands (roadmap 3.C).
 *
 * The four states opencode's `todowrite` uses: Claude Code's three plus
 * `cancelled`, so an item the plan dropped can stay on the list as a record
 * instead of being silently deleted or falsely ticked.
 *
 * Each state has a one-character MARKER — the box the rendered checklist
 * prints (`- [x] …`) — which is also how {@see TodoList::parse()} reads a
 * rendered list back, so the two must stay a bijection.
 */
enum TodoStatus: string
{
    case Pending = 'pending';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    /** The character inside the rendered checklist's box. */
    public function marker(): string
    {
        return match ($this) {
            self::Pending => ' ',
            self::InProgress => '>',
            self::Completed => 'x',
            self::Cancelled => '-',
        };
    }

    /** The state a rendered box marker stands for, or null for none. */
    public static function fromMarker(string $marker): ?self
    {
        foreach (self::cases() as $status) {
            if ($status->marker() === $marker) {
                return $status;
            }
        }

        return null;
    }

    /**
     * The width-1 glyph the dock pane draws for this state. Every one is East
     * Asian Width N in the Unicode 16 table (U+2610..2613 BALLOT BOX..SALTIRE,
     * U+25B8 BLACK RIGHT-POINTING SMALL TRIANGLE), so a row never measures
     * wider than the pane budgets for it.
     */
    public function glyph(): string
    {
        return match ($this) {
            self::Pending => "\u{2610}",
            self::InProgress => "\u{25B8}",
            self::Completed => "\u{2611}",
            self::Cancelled => "\u{2612}",
        };
    }

    /** Whether the item still asks for work: pending or in progress. */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::InProgress;
    }

    /**
     * Every wire value, in the order the tool schema's `enum` lists them.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
