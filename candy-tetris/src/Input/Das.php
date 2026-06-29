<?php

declare(strict_types=1);

namespace SugarCraft\Tetris\Input;

/**
 * DAS (Delayed Auto Shift) and ARR (Auto Repeat Rate) input timing.
 *
 * Mirrors charmbracelet/bubbletea Tetris DAS/ARR handling.
 *
 * When a directional key is held:
 *   - DAS delay (default 167 ms) must elapse before auto-repeat begins.
 *   - ARR interval (default 50 ms) governs how often the action repeats
 *     once the DAS delay has passed.
 *
 * This gives the player precise single-tap control (press below DAS)
 * and rapid continuous movement (hold past DAS threshold).
 *
 * Immutable: every press/advance returns a fresh instance.
 */
final class Das
{
    /** Default DAS delay in microseconds (167 ms = 167 000 µs). */
    public const DEFAULT_DAS_US = 167_000;

    /** Default ARR interval in microseconds (50 ms = 50 000 µs). */
    public const DEFAULT_ARR_US = 50_000;

    private function __construct(
        public readonly int $dasUs     = self::DEFAULT_DAS_US,
        public readonly int $arrUs    = self::DEFAULT_ARR_US,
        public readonly int $leftAcc  = 0,
        public readonly int $rightAcc = 0,
        public readonly int $downAcc  = 0,
    ) {}

    public static function create(int $dasUs = self::DEFAULT_DAS_US, int $arrUs = self::DEFAULT_ARR_US): self
    {
        return new self($dasUs, $arrUs);
    }

    /**
     * Advance the timers by $us microseconds.
     * Returns a new Das with accumulated time updated.
     */
    public function advance(int $us): self
    {
        return new self(
            $this->dasUs,
            $this->arrUs,
            $this->leftAcc  + $us,
            $this->rightAcc + $us,
            $this->downAcc  + $us,
        );
    }

    /**
     * A directional key has been pressed. Resets the DAS accumulator
     * for that direction (DAS delay restarts from zero on every fresh press).
     */
    public function withKeyDown(string $direction): self
    {
        return match ($direction) {
            'left'  => new self($this->dasUs, $this->arrUs, 0,      $this->rightAcc, $this->downAcc),
            'right' => new self($this->dasUs, $this->arrUs, $this->leftAcc, 0,          $this->downAcc),
            'down'  => new self($this->dasUs, $this->arrUs, $this->leftAcc, $this->rightAcc, 0),
            default => $this,
        };
    }

    /**
     * A directional key has been released. Clears the accumulator for
     * that direction (stop any in-progress repeat).
     */
    public function withKeyUp(string $direction): self
    {
        return match ($direction) {
            'left'  => new self($this->dasUs, $this->arrUs, 0,      $this->rightAcc, $this->downAcc),
            'right' => new self($this->dasUs, $this->arrUs, $this->leftAcc, 0,          $this->downAcc),
            'down'  => new self($this->dasUs, $this->arrUs, $this->leftAcc, $this->rightAcc, 0),
            default => $this,
        };
    }

    /**
     * Whether the left direction should fire (auto-repeat is active).
     */
    public function leftFiring(): bool
    {
        return $this->leftAcc >= $this->dasUs
            && ($this->leftAcc - $this->dasUs) % $this->arrUs < $this->arrUs;
    }

    /**
     * Whether the right direction should fire.
     */
    public function rightFiring(): bool
    {
        return $this->rightAcc >= $this->dasUs
            && ($this->rightAcc - $this->dasUs) % $this->arrUs < $this->arrUs;
    }

    /**
     * Whether the down (soft drop) direction should fire.
     */
    public function downFiring(): bool
    {
        return $this->downAcc >= $this->dasUs
            && ($this->downAcc - $this->dasUs) % $this->arrUs < $this->arrUs;
    }

    /**
     * Number of actions that would fire for the left direction in one
     * advance tick (0 or 1; 1 when ARR interval has elapsed).
     */
    public function leftRepeats(int $us): int
    {
        $next = $this->leftAcc + $us;
        if ($next < $this->dasUs) {
            return 0;
        }
        $effective = $next - $this->dasUs;
        return ($effective / $this->arrUs) >= 1 ? 1 : 0;
    }

    /**
     * Number of actions for right in one tick.
     */
    public function rightRepeats(int $us): int
    {
        $next = $this->rightAcc + $us;
        if ($next < $this->dasUs) {
            return 0;
        }
        $effective = $next - $this->dasUs;
        return ($effective / $this->arrUs) >= 1 ? 1 : 0;
    }
}
