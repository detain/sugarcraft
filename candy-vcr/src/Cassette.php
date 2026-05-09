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
     * @param iterable<Event> $events
     */
    public function __construct(CassetteHeader $header, iterable $events)
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
    }

    public function eventCount(): int
    {
        return count($this->events);
    }

    public function duration(): float
    {
        $count = count($this->events);
        return $count === 0 ? 0.0 : $this->events[$count - 1]->t;
    }
}
