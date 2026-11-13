<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * Header + ordered event list. Format-agnostic value object — JsonlFormat /
 * YamlFormat read and write this same shape.
 *
 * Mirrors charmbracelet/x/vcr Cassette.
 */
final class Cassette
{
    public readonly CassetteHeader $header;

    /** @var list<Event> */
    public readonly array $events;

    /**
     * Virtual end of the tape in seconds, when it is known. A compiled tape's
     * trailing `Sleep`/`Wait` directives advance the clock without emitting an
     * event, so the last event's timestamp under-reports the tape's true
     * length; the Compiler threads the final clock through here (finding 003).
     * Cassettes loaded from the wire carry no end time — their duration stays
     * the last event's timestamp.
     */
    public readonly ?float $endTime;

    /**
     * @param iterable<Event> $events
     */
    public function __construct(CassetteHeader $header, iterable $events, ?float $endTime = null)
    {
        $list = [];
        foreach ($events as $event) {
            if (!$event instanceof Event) {
                throw new \InvalidArgumentException(
                    'Cassette events must be SugarCraft\\Vcr\\Event instances, got ' . get_debug_type($event),
                );
            }
            $list[] = $event;
        }
        $this->header = $header;
        $this->events = $list;
        $this->endTime = $endTime;
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    /**
     * @deprecated Use $header property directly (readonly).
     */
    public function header(): CassetteHeader
    {
        return $this->header;
    }

    public function duration(): float
    {
        $count = count($this->events);
        $lastEvent = $count === 0 ? 0.0 : $this->events[$count - 1]->t;

        return max($lastEvent, $this->endTime ?? 0.0);
    }
}
