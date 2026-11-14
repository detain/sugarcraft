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
 *
 * `undecodedInputCount` is a subset of `inputCount`: Input events
 * whose payload reached no message at the program (the serializer
 * registry refused the envelope, or the payload carried neither a
 * msg envelope nor a raw-byte string). Silent intake is what this
 * counts, so it surfaces in the summary whenever it is non-zero.
 *
 * `undecodedInputAt` names those events — one note per refusal in
 * tape order, tagged 'event on line N' (finding 035) — so a failing
 * replay can point at the offending cassette lines instead of only
 * counting them.
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
        public int $undecodedInputCount = 0,
        public array $undecodedInputAt = [],
    ) {
    }

    /**
     * Human-readable summary suitable for failure messages.
     */
    public function diffSummary(): string
    {
        if ($this->ok) {
            $summary = sprintf(
                'replay OK — %d event(s) (%d input, %d resize, %d output, %d quit)',
                $this->eventCount,
                $this->inputCount,
                $this->resizeCount,
                $this->outputCount,
                $this->quitCount,
            );
            if ($this->undecodedInputCount > 0) {
                $summary .= sprintf(
                    ' — %d input event(s) produced no decodable message and were skipped',
                    $this->undecodedInputCount,
                );
                $summary .= $this->undecodedAtPhrase();
            }
            return $summary;
        }
        $clean = $this->programQuitCleanly ? 'clean' : 'unclean (timed out / crashed)';
        return sprintf(
            "replay FAILED — %d event(s) processed, program exit: %s\n%s",
            $this->eventCount,
            $clean,
            $this->diff,
        ) . $this->undecodedAtPhrase();
    }

    /**
     * Names up to five refused events (tape order) so stderr points at
     * the cassette lines, not just the count; long runs collapse to a
     * '+N more' tail. Empty when no notes were carried (finding 035).
     */
    private function undecodedAtPhrase(): string
    {
        if ($this->undecodedInputAt === []) {
            return '';
        }
        $shown = array_slice($this->undecodedInputAt, 0, 5);
        $phrase = ' (' . implode('; ', $shown);
        $rest = count($this->undecodedInputAt) - count($shown);
        if ($rest > 0) {
            $phrase .= sprintf('; +%d more', $rest);
        }
        return $phrase . ')';
    }
}
