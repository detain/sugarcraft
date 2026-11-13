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
     * Pass either a seed `$time` or a fully configured `$picker` (bounds,
     * range mode, render state — all honoured as-is). Supplying both is a
     * programming error and throws: the picker would silently discard the
     * time, and silent precedence is how integration bugs ship.
     *
     * Mirrors the EthanEFung/bubble-datepicker construction pattern.
     */
    public static function new(
        ?\DateTimeImmutable $time = null,
        ?EventStoreInterface $store = null,
        ?DatePicker $picker = null,
    ): self {
        if ($time !== null && $picker !== null) {
            throw new \InvalidArgumentException(
                'Model::new() takes either a seed time or a configured picker, never both',
            );
        }

        return new self($picker ?? DatePicker::new($time), $store);
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
     * first/last selectable day (the jump scans past dimmed bound-violating
     * days — see handleKey() dp-11 contract).
     * Records events to the EventStore when one is injected — and this is the
     * ONLY recording channel: {@see DatePicker::handleKey()} is the non-TEA
     * imperative surface and records nothing by design (see {@see PickerEvent}).
     *
     * Chord policy (single source of truth, pinned in full by
     * {@see ModelTest::testChordMatrix()}): the four ACTION keys
     * Enter/Escape/PageUp/PageDown are reserved to the host under ANY chord —
     * Ctrl, Alt or Shift held means the key is ignored, so a chat host's
     * Shift+Enter newline is never hijacked. The six NAVIGATION keys
     * Left/Right/Up/Down/Home/End keep the deliberate terminal-compatibility
     * exception and act bare AND chorded.
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

        $this->recordTransitions($prev, $next, $msg);

        return [new self($next, $this->store), null];
    }

    /**
     * The single keyboard dispatch table. Both cursor movement and month
     * stepping resolve here, so there is exactly one source of truth for the
     * key → action mapping (no second hand-restated table). The one chord
     * policy: `$chorded` names all three modifier legs (Ctrl|Alt|Shift), and
     * it gates exactly the four ACTION arms; the navigation arms stay
     * reachable under every chord by design.
     */
    private function applyKey(DatePicker $picker, KeyMsg $msg): DatePicker
    {
        $chorded = $msg->ctrl || $msg->alt || $msg->shift;

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
     *
     * dp-09 completes the census: range restarts (both-set → fresh start),
     * pending- and completed-range clears, month steps, and refused Enters
     * are all transitions over the prev/next diff plus the pure refusal seam
     * {@see DatePicker::selectionRefusal()} — no picker state is consulted
     * beyond public queries, so mutability law holds. A refusal is an
     * ANNOUNCEMENT only: polling the picker stays the authoritative channel.
     */
    private function recordTransitions(DatePicker $prev, DatePicker $next, KeyMsg $msg): void
    {
        if ($this->store === null) {
            return;
        }

        if ($next->cursorIndex() !== $prev->cursorIndex()) {
            $this->store->record(PickerEvent::CURSOR_MOVED, [
                'index' => $next->cursorIndex(),
                'date'  => $next->dateAtCursor()?->format('Y-m-d'),
            ]);
        }

        // Refused Enter (dp-09): the acted key would have applied nothing on
        // the pre-key state. Chorded Enter never acted (host-reserved) and so
        // cannot be refused. Computed off $prev because a refusal is exactly
        // "next == prev on the selection channels".
        $chorded = $msg->ctrl || $msg->alt || $msg->shift;
        if ($msg->type === KeyType::Enter && !$chorded) {
            $reason = $prev->selectionRefusal();
            if ($reason !== null) {
                $this->store->record(PickerEvent::SELECTION_REFUSED, [
                    'date'   => $prev->selectionTarget()?->format('Y-m-d'),
                    'reason' => $reason,
                ]);
            }
        }

        // Month step (PageUp/PageDown — any public channel that moved the
        // view window): a transition in its own right, recorded for the host.
        if ($prev->viewMonth() !== $next->viewMonth() || $prev->viewYear() !== $next->viewYear()) {
            $this->store->record(PickerEvent::MONTH_CHANGED, [
                'year'  => $next->viewYear(),
                'month' => $next->viewMonth(),
            ]);
        }

        if ($next->isRangeMode()) {
            $ps = $prev->rangeStart();
            $pe = $prev->rangeEnd();
            $ns = $next->rangeStart();
            $ne = $next->rangeEnd();

            // range start armed (unset → set): the start transition.
            if ($ps === null && $ns !== null) {
                $this->store->record(PickerEvent::RANGE_STARTED, [
                    'date' => $ns->format('Y-m-d'),
                ]);
            }
            // Both had been set and the end is now gone while a start stands:
            // the third Enter restarted the range (dp-09). to may equal from —
            // dropping a completed end IS the transition.
            if ($ps !== null && $pe !== null && $ne === null && $ns !== null) {
                $this->store->record(PickerEvent::RANGE_RESTARTED, [
                    'from' => $ps->format('Y-m-d'),
                    'to'   => $ns->format('Y-m-d'),
                ]);
            }
            // Endpoints went armed → fully cleared (Escape, pending or
            // completed alike; the completed case also drops the end, but the
            // surviving null start distinguishes it from a restart).
            if ($ps !== null && $ns === null) {
                $this->store->record(PickerEvent::RANGE_CLEARED, [
                    'from' => $ps->format('Y-m-d'),
                ]);
            }
            // Range completed (end just became non-null).
            if ($ne !== null && $pe === null) {
                $this->store->record(PickerEvent::DATE_SELECTED, [
                    'date'       => $ne->format('Y-m-d'),
                    'rangeStart' => $ns?->format('Y-m-d'),
                    'rangeEnd'   => $ne->format('Y-m-d'),
                ]);
            }
        } else {
            $prevDate = $prev->selectedDate();
            $nextDate = $next->selectedDate();

            if ($nextDate !== null && self::day($nextDate) !== self::day($prevDate)) {
                $this->store->record(PickerEvent::DATE_SELECTED, [
                    'date' => $nextDate->format('Y-m-d'),
                ]);
            } elseif ($nextDate === null && $prevDate !== null) {
                $this->store->record(PickerEvent::DATE_CLEARED, []);
            }
        }
    }

    private static function day(?\DateTimeImmutable $d): string
    {
        return $d?->format('Y-m-d') ?? '';
    }

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

    /**
     * Swap the wrapped picker, keeping the SAME EventStore instance wired in.
     *
     * Accepts a ready DatePicker, or a callable that receives the current
     * picker and returns its replacement — the TEA-idiomatic way for a host
     * to reconfigure a running model (flip range mode, tighten bounds)
     * mid-flight without hand-threading the store through a constructor.
     * The readonly DatePicker-typed constructor is the validation seam: a
     * callable returning anything else is a TypeError, not a silent skew.
     */
    public function withPicker(callable|DatePicker $picker): self
    {
        $next = $picker instanceof DatePicker
            ? $picker
            : $picker($this->picker);

        return new self($next, $this->store);
    }

}
