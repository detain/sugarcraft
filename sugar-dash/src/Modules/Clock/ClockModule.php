<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Clock;

use SugarCraft\Dash\Module\BaseModule;
use SugarCraft\Dash\Foundation\Item;

/**
 * Clock module that displays the current time.
 *
 * Mirrors the lattice clock module pattern.
 */
final class ClockModule extends BaseModule
{
    private \DateTimeImmutable $time;

    public function __construct(
        private readonly bool $showDate = false,
        private readonly ?string $timezone = null,
    ) {
        $this->time = $this->createTime();
    }

    public function name(): string
    {
        return 'clock';
    }

    public function init(): array
    {
        return [
            'name' => $this->name(),
            'minSize' => $this->minSize(),
            'interval' => 1,
        ];
    }

    public function update(array $state): array
    {
        $this->time = $this->createTime();
        $state['time'] = $this->time->format('H:i:s');
        if ($this->showDate) {
            $state['date'] = $this->time->format('l, M d');
        }
        return $state;
    }

    public function view(array $state, int $width, int $height): string
    {
        $time = $this->time;
        $timeStr = $time->format('H:i:s');

        if ($this->showDate) {
            $dateStr = $time->format('l, M d');
            return $timeStr . "\n" . $dateStr;
        }

        return $timeStr;
    }

    public function minSize(): array
    {
        return $this->showDate ? [20, 5] : [12, 3];
    }

    private function createTime(): \DateTimeImmutable
    {
        $timezone = $this->timezone !== null
            ? new \DateTimeZone($this->timezone)
            : new \DateTimeZone(date_default_timezone_get());

        return new \DateTimeImmutable('now', $timezone);
    }
}
