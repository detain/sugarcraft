<?php

declare(strict_types=1);

namespace SugarCraft\Vcr;

/**
 * Outcome of a {@see Player::play()} call.
 *
 * `ok` reflects the assertion verdict over the replayed output.
 * `eventCount` is the total number of cassette events processed.
 * `inputCount` / `resizeCount` / `outputCount` / `quitCount` break
 * that down by kind so failure messages can pinpoint where things
 * diverged.
 */
final readonly class ReplayResult
{
    public function __construct(
        public bool $ok,
        public string $diff,
        public int $eventCount,
        public int $inputCount,
        public int $resizeCount,
        public int $outputCount,
        public int $quitCount,
        public bool $programQuitCleanly,
    ) {}

    /**
     * Human-readable summary suitable for failure messages.
     */
    public function diffSummary(): string
    {
        if ($this->ok) {
            return sprintf(
                'replay OK — %d events (%d input, %d resize, %d output, %d quit)',
                $this->eventCount,
                $this->inputCount,
                $this->resizeCount,
                $this->outputCount,
                $this->quitCount,
            );
        }
        $clean = $this->programQuitCleanly ? 'clean' : 'unclean (timed out / crashed)';
        return sprintf(
            "replay FAILED — %d events processed, program exit: %s\n%s",
            $this->eventCount,
            $clean,
            $this->diff,
        );
    }
}
