<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

use SugarCraft\Top\Collect\Process\CommandOutcome;
use SugarCraft\Top\Collect\Process\CommandResult;

/**
 * What one {@see Ipmi} round has read so far, filled step by step along
 * its promise chain; the first fatal outcome stops the remaining steps.
 * Mutable scratch state that never leaves the collector.
 *
 * @internal
 */
final class IpmiRound
{
    public ?IpmiState $fail = null;

    public ?IpmiPower $power = null;

    /** @var ?list<IpmiSensor> */
    public ?array $sensors = null;

    public ?IpmiChassis $chassis = null;

    public ?IpmiSel $sel = null;

    /**
     * Record a command's outcome: a timeout or a runaway output always
     * ends the round (a hung or broken BMC); a refusal ends it only for a command the round cannot do
     * without (`$fatal`: mc info, sensor list).
     */
    public function note(CommandResult $result, bool $fatal = false): void
    {
        if ($this->fail !== null) {
            return;
        }
        if ($result->outcome === CommandOutcome::Timeout) {
            $this->fail = IpmiState::TimedOut;
        } elseif ($result->outcome === CommandOutcome::TooLarge) {
            $this->fail = IpmiState::NoResponse; // a BMC spewing megabytes is broken, whatever the command
        } elseif ($fatal && !$result->ok()) {
            $this->fail = $result->outcome === CommandOutcome::Absent ? IpmiState::NoTool : IpmiState::NoResponse;
        }
    }

    public function failed(): bool
    {
        return $this->fail !== null;
    }
}
