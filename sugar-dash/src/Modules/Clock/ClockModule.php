<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Modules\Clock;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dash\Module\BaseModule;

/**
 * Clock module that displays the current time.
 *
 * Mirrors the lattice clock module pattern.
 * Uses Cmd::tick(1.0) for 1Hz refresh.
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

    public function init(): ?\Closure
    {
        return Cmd::tick(1.0, static fn(): Msg => new TickMsg());
    }

    public function update(Msg $msg): array
    {
        $newTime = $this->createTime();
        if ($msg instanceof TickMsg) {
            return [$this->withTime($newTime), Cmd::tick(1.0, static fn(): Msg => new TickMsg())];
        }
        return [$this->withTime($newTime), null];
    }

    public function view(): string
    {
        $timeStr = $this->time->format('H:i:s');

        if ($this->showDate) {
            $dateStr = $this->time->format('l, M d');
            return $timeStr . "\n" . $dateStr;
        }

        return $timeStr;
    }

    public function minSize(): array
    {
        return $this->showDate ? [20, 5] : [12, 3];
    }

    /**
     * Create a clone of this module with the given time.
     */
    private function withTime(\DateTimeImmutable $time): static
    {
        $clone = clone $this;
        $clone->time = $time;
        return $clone;
    }

    private function createTime(): \DateTimeImmutable
    {
        $timezone = $this->timezone !== null
            ? new \DateTimeZone($this->timezone)
            : new \DateTimeZone(date_default_timezone_get());

        return new \DateTimeImmutable('now', $timezone);
    }
}
