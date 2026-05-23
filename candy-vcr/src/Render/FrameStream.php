<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Render;

use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Player;
use SugarCraft\Vt\Snapshot;
use SugarCraft\Vt\Terminal;

/**
 * Iterator of Snapshot frames at fps cadence.
 *
 * Lazily walks the Player's cassette events, feeds bytes to the Terminal
 * on each event, advances a virtual clock by event timestamps, and yields
 * a terminal snapshot every 1/fps seconds. Only one frame is held in
 * memory at a time (the current one) — the previous reference is only
 * kept during iteration for potential dedup use by FrameDedup.
 *
 * @implements \IteratorAggregate<int, Snapshot>
 */
final class FrameStream implements \IteratorAggregate
{
    public function __construct(
        private Player $player,
        private Terminal $terminal,
        private float $fps = 30.0,
    ) {
    }

    /** @return \Traversable<int, Snapshot> */
    public function getIterator(): \Traversable
    {
        $cassette = $this->player->cassette;
        $terminal = $this->terminal;
        $fps = $this->fps;

        $frameInterval = 1.0 / $fps;

        $events = $cassette->events;
        $eventCount = count($events);

        if ($eventCount === 0) {
            return;
        }

        $virtualTime = 0.0;
        $nextSnapshotTime = 0.0;
        $frameIndex = 0;
        $lastSnapshotTime = -1.0;

        for ($i = 0; $i < $eventCount; $i++) {
            $event = $events[$i];

            $virtualTime = $event->t;

            $terminal = $this->processEvent($event, $terminal);

            while ($virtualTime >= $nextSnapshotTime) {
                $lastSnapshotTime = $nextSnapshotTime;
                yield $frameIndex => $terminal->snapshot($nextSnapshotTime);
                $frameIndex++;
                $nextSnapshotTime += $frameInterval;
            }
        }

        if ($frameIndex === 0 || $virtualTime > $lastSnapshotTime) {
            yield $frameIndex => $terminal->snapshot($virtualTime);
        }
    }

    /**
     * Process a single event, feeding appropriate bytes to the terminal.
     * Returns the (potentially new) terminal instance to use for subsequent events.
     */
    private function processEvent(Event $event, Terminal $terminal): Terminal
    {
        return match ($event->kind) {
            EventKind::Input => $this->processInput($event, $terminal),
            EventKind::Output => $this->processOutput($event, $terminal),
            EventKind::Resize => $this->processResize($event, $terminal),
            EventKind::Quit => $terminal,
        };
    }

    private function processInput(Event $event, Terminal $terminal): Terminal
    {
        if (isset($event->payload['b']) && is_string($event->payload['b'])) {
            $terminal->feed($event->payload['b']);
        }
        return $terminal;
    }

    private function processOutput(Event $event, Terminal $terminal): Terminal
    {
        if (isset($event->payload['b']) && is_string($event->payload['b'])) {
            $terminal->feed($event->payload['b']);
        }
        return $terminal;
    }

    private function processResize(Event $event, Terminal $terminal): Terminal
    {
        $cols = $event->payload['cols'] ?? null;
        $rows = $event->payload['rows'] ?? null;
        if (is_numeric($cols) && is_numeric($rows)) {
            $colsInt = (int) $cols;
            $rowsInt = (int) $rows;
            if ($colsInt > 0 && $rowsInt > 0) {
                return Terminal::new($colsInt, $rowsInt, $terminal->theme());
            }
        }
        return $terminal;
    }
}
