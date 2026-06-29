<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;

/**
 * TEA Model wrapper for the DatePicker component.
 *
 * Wraps a DatePicker and an optional EventStore, exposing the full
 * DatePicker keyboard navigation via the TEA update() loop.
 *
 * @implements Model
 */
final class Model implements \SugarCraft\Core\Model
{
    public function __construct(
        private readonly DatePicker $picker,
        private readonly ?EventStoreInterface $store = null,
    ) {
    }

    /**
     * Factory: build a new calendar model.
     *
     * Mirrors charmbracelet/bubble-datepicker's construction pattern.
     */
    public static function new(?\DateTimeImmutable $time = null, ?EventStoreInterface $store = null): self
    {
        return new self(DatePicker::new($time), $store);
    }

    /**
     * Init returns null — no startup command needed.
     */
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * Handle a KeyMsg, returning [nextModel, optionalCmd].
     *
     * Maps arrow keys to cursor movement, Enter to SelectDate (non-range
     * mode) or range-start/end (range mode), Escape to ClearDate, and
     * Home/End to cursor bounds. Records events to the EventStore when
     * one is injected.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $key = $this->keyToString($msg);

        // Map KeyType to DatePicker methods; leave Char (text) alone.
        $next = match ($msg->type) {
            KeyType::Left   => $this->picker->MoveCursorLeft(),
            KeyType::Right  => $this->picker->MoveCursorRight(),
            KeyType::Up     => $this->picker->MoveCursorUp(),
            KeyType::Down   => $this->picker->MoveCursorDown(),
            KeyType::Home   => $this->picker->handleKey(DatePicker::KEY_HOME),
            KeyType::End    => $this->picker->handleKey(DatePicker::KEY_END),
            KeyType::Enter  => $this->picker->handleKey(DatePicker::KEY_ENTER),
            KeyType::Escape => $this->picker->handleKey(DatePicker::KEY_ESCAPE),
            default         => $this->picker,
        };

        // Record date_selected when a range selection completes (rangeEnd is set)
        // or when a single date is picked via SelectDate() (IsSelecting becomes true).
        if ($msg->type === KeyType::Enter) {
            if ($this->picker->isRangeMode()
                && $this->picker->rangeEnd() === null
                && $next->rangeEnd() !== null
            ) {
                // Second Enter in range mode: range completed
                $this->store?->record('date_selected', [
                    'date' => $next->rangeEnd()?->format('Y-m-d'),
                ]);
            } elseif (!$this->picker->isRangeMode()
                && !$this->picker->IsSelecting()
                && $next->IsSelecting()
            ) {
                // Enter in non-range mode entered selecting state
                $this->store?->record('date_selected', [
                    'date' => $next->SelectedDate()?->format('Y-m-d'),
                ]);
            }
        }

        // Record navigation events (cursor moved to a different index).
        if ($key !== null && $next->CursorIndex() !== $this->picker->CursorIndex()) {
            $this->store?->record('cursor_moved', [
                'index' => $next->CursorIndex(),
            ]);
        }

        // Return [newModel, null]. The selection Cmd is intentionally omitted —
        // callers inspect SelectedDate() after update() if they need the value.
        return [new self($next, $this->store), null];
    }

    /**
     * Render the calendar view as an ANSI string.
     */
    public function view(): string
    {
        return $this->picker->View();
    }

    /**
     * No subscriptions needed.
     */
    public function subscriptions(): ?Subscriptions
    {
        return null;
    }

    /**
     * Expose the wrapped picker for direct inspection by callers that
     * prefer the imperative API.
     */
    public function picker(): DatePicker
    {
        return $this->picker;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Convert a KeyMsg to the string key name that DatePicker::handleKey
     * accepts, or null for unhandled Char keys.
     */
    private function keyToString(KeyMsg $msg): ?string
    {
        return match ($msg->type) {
            KeyType::Left   => DatePicker::KEY_LEFT,
            KeyType::Right  => DatePicker::KEY_RIGHT,
            KeyType::Up     => DatePicker::KEY_UP,
            KeyType::Down   => DatePicker::KEY_DOWN,
            KeyType::Home   => DatePicker::KEY_HOME,
            KeyType::End    => DatePicker::KEY_END,
            KeyType::Enter  => DatePicker::KEY_ENTER,
            KeyType::Escape => DatePicker::KEY_ESCAPE,
            default         => null,
        };
    }

}
