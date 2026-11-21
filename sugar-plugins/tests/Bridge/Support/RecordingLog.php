<?php

declare(strict_types=1);

namespace SugarCraft\Plugins\Tests\Bridge\Support;

use SugarCraft\Plugins\Bridge\BridgeLog;

/** A BridgeLog that keeps every row for assertion. */
final class RecordingLog implements BridgeLog
{
    /** @var list<array{level:string, event:string, fields:array<string,mixed>}> */
    public array $rows = [];

    public function debug(string $event, array $fields = []): void
    {
        $this->rows[] = ['level' => 'debug', 'event' => $event, 'fields' => $fields];
    }

    public function info(string $event, array $fields = []): void
    {
        $this->rows[] = ['level' => 'info', 'event' => $event, 'fields' => $fields];
    }

    public function warn(string $event, array $fields = []): void
    {
        $this->rows[] = ['level' => 'warn', 'event' => $event, 'fields' => $fields];
    }

    public function error(string $event, array $fields = []): void
    {
        $this->rows[] = ['level' => 'error', 'event' => $event, 'fields' => $fields];
    }

    /** @return list<string> the event names, in order */
    public function events(): array
    {
        return \array_column($this->rows, 'event');
    }

    /** @return array<string,mixed>|null the fields of the first row with this event */
    public function fieldsOf(string $event): ?array
    {
        foreach ($this->rows as $row) {
            if ($row['event'] === $event) {
                return $row['fields'];
            }
        }

        return null;
    }
}
