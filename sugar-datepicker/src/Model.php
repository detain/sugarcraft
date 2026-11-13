<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Subscriptions;

/**
 * TEA Model wrapper for the DatePicker component.
 *
 * Wraps a DatePicker and an optional EventStore, exposing the full
 * DatePicker keyboard navigation via the TEA update() loop.
 */
final class Model implements \SugarCraft\Core\Model
{
    public function __construct(
        private readonly DatePicker $picker,
        private readonly ?EventStoreInterface $store = null,
    ) {
    }

    /**
     * Factory: build a new date-picker model.
     *
     * Mirrors the EthanEFung/bubble-datepicker construction pattern.
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
     * Maps arrow keys to cursor movement, PageUp/PageDown to previous/next
     * month, Enter to selectDate (non-range mode) or range-start/end (range
     * mode), Escape to clearDate in single mode — in range mode Escape clears
     * the pending range and never the selection — and Home/End to the
     * first/last valid day.
     * Records events to the EventStore when one is injected.
     *
     * Chorded Enter/Escape/PageUp/PageDown (Ctrl or Alt held) are ignored so a
     * host's reserved chords are not hijacked; bare arrow keys always act.
     *
     * The command is permanently null: this widget never drives side effects of
     * its own. A host that wants "picked, now quit" must inspect
     * {@see picker()}'s selectedDate()/isSelecting() after update() and emit
     * its own Cmd — do not wait for a selection command that will not arrive.
     *
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }

        $prev = $this->picker;
        $next = $this->applyKey($prev, $msg);

        $this->recordTransitions($prev, $next);

        return [new self($next, $this->store), null];
    }

    /**
     * The single keyboard dispatch table. Both cursor movement and month
     * stepping resolve here, so there is exactly one source of truth for the
     * key → action mapping (no second hand-restated table).
     */
    private function applyKey(DatePicker $picker, KeyMsg $msg): DatePicker
    {
        $chorded = $msg->ctrl || $msg->alt;

        return match (true) {
            $msg->type === KeyType::Left   => $picker->moveCursorLeft(),
            $msg->type === KeyType::Right  => $picker->moveCursorRight(),
            $msg->type === KeyType::Up     => $picker->moveCursorUp(),
            $msg->type === KeyType::Down   => $picker->moveCursorDown(),
            $msg->type === KeyType::Home   => $picker->handleKey(DatePicker::KEY_HOME),
            $msg->type === KeyType::End    => $picker->handleKey(DatePicker::KEY_END),
            $msg->type === KeyType::PageUp   && !$chorded => $picker->goToPreviousMonth(),
            $msg->type === KeyType::PageDown && !$chorded => $picker->goToNextMonth(),
            $msg->type === KeyType::Enter  && !$chorded => $picker->handleKey(DatePicker::KEY_ENTER),
            $msg->type === KeyType::Escape && !$chorded => $picker->handleKey(DatePicker::KEY_ESCAPE),
            default                       => $picker,
        };
    }

    /**
     * Emit events for state TRANSITIONS (value change), never for flag edges:
     * re-selecting a different date must notify, re-selecting the same one
     * must not double-notify.
     */
    private function recordTransitions(DatePicker $prev, DatePicker $next): void
    {
        if ($this->store === null) {
            return;
        }

        if ($next->cursorIndex() !== $prev->cursorIndex()) {
            $this->store->record('cursor_moved', [
                'index' => $next->cursorIndex(),
            ]);
        }

        if ($next->isRangeMode()) {
            // range start armed (was unset, now set): the start transition.
            if ($prev->rangeStart() === null && $next->rangeStart() !== null) {
                $this->store->record('range_started', [
                    'date' => $next->rangeStart()?->format('Y-m-d'),
                ]);
            }
            // range completed (start set, end just became non-null).
            if ($next->rangeEnd() !== null && $prev->rangeEnd() === null) {
                $this->store->record('date_selected', [
                    'date'       => $next->rangeEnd()?->format('Y-m-d'),
                    'rangeStart' => $next->rangeStart()?->format('Y-m-d'),
                    'rangeEnd'   => $next->rangeEnd()?->format('Y-m-d'),
                ]);
            }
        } else {
            $prevDate = $prev->selectedDate();
            $nextDate = $next->selectedDate();

            if ($nextDate !== null && self::day($nextDate) !== self::day($prevDate)) {
                $this->store->record('date_selected', [
                    'date' => $nextDate->format('Y-m-d'),
                ]);
            } elseif ($nextDate === null && $prevDate !== null) {
                $this->store->record('date_cleared', []);
            }
        }
    }

    private static function day(?\DateTimeImmutable $d): string
    {
        return $d?->format('Y-m-d') ?? '';
    }

    /**
     * Render the date-picker view as an ANSI string, delegating the layout
     * options to DatePicker::View() (the total width is clamped there).
     *
     * NOTE: the Core\Model contract declares view() with no parameters, so a
     * host driving this model through the framework gets the 21×9 default.
     * To choose a width or the week-number column, call View() on the picker
     * (or this view() with arguments) directly rather than via the contract.
     */
    /**
     * Render the grid. Null arguments fall back to the picker's configured
     * state (withWidth()/withShowWeekNumbers()), so a framework-driven host
     * invoking the argless Core\Model contract gets the configured grid —
     * not a hard-coded 21×9.
     */
    public function view(?int $width = null, ?bool $showWeekNumbers = null): string
    {
        return $this->picker->view($width, $showWeekNumbers);
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
     * prefer the imperative API — including month/year navigation reached
     * through `goToNextMonth()` and friends, which have no dedicated key
     * beyond PageUp/PageDown.
     */
    public function picker(): DatePicker
    {
        return $this->picker;
    }

}
