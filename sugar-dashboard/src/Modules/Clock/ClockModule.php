<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Clock;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Msg;
use SugarCraft\Dashboard\Msg\TickMsg;
use SugarCraft\Dashboard\Module\BaseModule;

/**
 * Clock module that displays the current time.
 *
 * Mirrors the lattice clock module pattern.
 * Uses Cmd::tick(1.0) for 1Hz refresh.
 */
final class ClockModule extends BaseModule
{
    private \DateTimeImmutable $time;

    private readonly \DateTimeZone $zone;

    /** Visible bad-config reason when the configured timezone is invalid (D-164). */
    private readonly ?string $zoneError;

    private static int $timeBuildCount = 0;

    public function __construct(
        private readonly bool $showDate = false,
        private readonly ?string $timezone = null,
        private readonly ?string $name = null,
    ) {
        // Config trust boundary (D-164): one timezone typo must cost the
        // clock panel, never the whole dashboard at startup. Bad zones fall
        // back to UTC and record the reason; the honest-disable doctrine
        // (D-08, ported to first-party modules) renders that reason in the
        // frame instead of crashing or silently lying with the wrong clock.
        try {
            $this->zone = $timezone !== null
                ? new \DateTimeZone($timezone)
                : new \DateTimeZone(date_default_timezone_get());
            $this->zoneError = null;
        } catch (\Exception $e) {
            $this->zone = new \DateTimeZone('UTC');
            $this->zoneError = sprintf(
                'unknown timezone "%s" (%s)',
                (string) $timezone,
                $e->getMessage(),
            );
        }

        $this->time = $this->createTime();
    }

    public function name(): string
    {
        return $this->name ?? 'clock';
    }

    public function init(): ?\Closure
    {
        return $this->selfTickCmd();
    }

    public function update(Msg $msg): array
    {
        // Per-event law (SystemModule docblock): rebuilding DateTimeImmutable
        // + zone on every message is the same sin as per-event /proc I/O —
        // the wall read belongs behind the tick gate. Non-tick returns an
        // equal-valued fresh instance, zero time constructions.
        if ($msg instanceof TickMsg && $msg->isFor($this->name())) {
            return [$this->withTime($this->createTime()), $this->selfTickCmd()];
        }
        return [$this->withTime($this->time), null];
    }

    protected function tickIntervalSeconds(): ?float
    {
        return 1.0;
    }

    public function view(): string
    {
        if ($this->zoneError !== null) {
            return "—\nClock disabled: {$this->zoneError}; showing UTC time was suppressed to avoid a misleading clock.";
        }

        $timeStr = $this->time->format('H:i:s');

        if ($this->showDate) {
            $dateStr = $this->time->format('l, M d');
            return $timeStr . "\n" . $dateStr;
        }

        return $timeStr;
    }

    /**
     * Test seam (ProcAvailability style): counts createTime() bodies, so the
     * tick-gating law is pinned directly.
     */
    public static function timeBuildCountForTesting(): int
    {
        return self::$timeBuildCount;
    }

    public static function resetTimeBuildCountForTesting(): void
    {
        self::$timeBuildCount = 0;
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
        ++self::$timeBuildCount;

        return new \DateTimeImmutable('now', $this->zone);
    }
}
