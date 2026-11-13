<?php

declare(strict_types=1);

namespace SugarCraft\Animation;

/**
 * The whole canonical decode as one value (SA-G1 -006).
 *
 * `Decoder::decode()` hands back a bare `list<Frame>`, and every consumer
 * that needs the rest of the story — loop count, whether the 256-frame cap
 * bit — rebuilt the ritual by hand (candy-gifview reads the file, collects
 * `decodeBytes()`, guesses truncation with `count >= MAX_FRAMES`, and calls
 * `loopCount()` on the same bytes again). This object is that ritual,
 * captured once at the ingress where the truth actually lives:
 *
 * - `frames()` — the decoded list, same bytes `decode()` yields.
 * - `loopPlays()` — `Decoder::loopCount()` over the SAME audited bytes,
 *   normalized to total playthroughs: `null` = loop forever (no NETSCAPE
 *   extension or raw 0), `N` = N playthroughs. Feed to
 *   {@see Player::withLoops()} directly.
 * - `truncated()` — whether the frame list may be hiding frames.
 * - `stopReason()` — `null` when the walk ran to the file's own end;
 *   otherwise one of {@see self::STOP_FRAME_CAP} (the frame list hit the
 *   256-frame ceiling) or {@see self::STOP_UNDECODABLE_FRAME} (a frame the
 *   container advertised but GD refused never entered the list).
 *
 * Two disclosures ride with the design:
 *
 * 1. The frame cap is a HEURISTIC at the boundary: a file with exactly 256
 *    frames is byte-indistinguishable from one whose 257th was sliced, so
 *    both report `truncated() === true` with `STOP_FRAME_CAP`. Hosts that
 *    cached a `count >= Decoder::MAX_FRAMES` guess keep the same answer —
 *    now stated rather than inferred.
 * 2. Budget refusals (screen cap, joint cell budget, input size) are NOT
 *    stop reasons — they throw before any animation exists. A
 *    `DecodedAnimation` only ever describes a walk that was allowed to run.
 *
 * @see Decoder::decodeFile() the only production mint.
 */
final class DecodedAnimation
{
    /** The walk stopped at the MAX_FRAMES ceiling; later frames were never composited. */
    public const STOP_FRAME_CAP = 'frame-cap';

    /** A frame the container advertised failed to decode and was skipped. */
    public const STOP_UNDECODABLE_FRAME = 'undecodable-frame';

    /**
     * @param list<Frame> $frames
     * @param int<1, max>|null $loopPlays
     */
    private function __construct(
        private readonly array $frames,
        private readonly ?int $loopPlays,
        private readonly ?string $stopReason,
    ) {
    }

    /**
     * Seal a completed walk. @internal — {@see Decoder::decodeFile()} owns
     * the only production call; the seam is public so tests can pin the
     * reporting rules in isolation.
     *
     * @param list<Frame> $frames
     * @param int<1, max>|null $loopPlays
     */
    public static function fromWalk(array $frames, ?int $loopPlays, ?string $stopReason): self
    {
        return new self($frames, $loopPlays, $stopReason);
    }

    /**
     * @return list<Frame>
     */
    public function frames(): array
    {
        return $this->frames;
    }

    /**
     * Total playthroughs, `null` = loop forever (see class docblock).
     *
     * @return int<1, max>|null
     */
    public function loopPlays(): ?int
    {
        return $this->loopPlays;
    }

    /**
     * May the frame list be hiding frames? True exactly when the walk
     * carries a stop reason — either the frame cap withheld later frames
     * (including the documented exactly-256 false positive) or an
     * advertised frame was skipped undecodable.
     */
    public function truncated(): bool
    {
        return $this->stopReason !== null;
    }

    /**
     * @return self::STOP_*|null
     */
    public function stopReason(): ?string
    {
        return $this->stopReason;
    }
}
