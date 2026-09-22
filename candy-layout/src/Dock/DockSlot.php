<?php

declare(strict_types=1);

namespace SugarCraft\Layout\Dock;

/**
 * One pane's entry in a dock side's vertical stack, carrying its height share
 * as a rational (weightNum / weightDenom).
 *
 * Original SugarCraft design (no upstream mirror). The rational numerator/
 * denominator storage echoes the drift-free proportion approach of sugar-crush's
 * `Tui\SplitLayout` — referenced here in prose only, candy-layout stays a leaf
 * package and does not depend on it.
 */
final readonly class DockSlot
{
    public function __construct(
        public string $paneId,
        public int $weightNum,
        public int $weightDenom,
    ) {
        self::guard($paneId, $weightNum, $weightDenom);
    }

    /**
     * @param int $weightNum    stack weight numerator, >= 1
     * @param int $weightDenom  stack weight denominator, >= 1
     */
    public static function new(string $paneId, int $weightNum = 1, int $weightDenom = 1): self
    {
        return new self($paneId, $weightNum, $weightDenom);
    }

    /**
     * New slot carrying this pane id with a replacement rational weight.
     */
    public function withWeight(int $num, int $denom): self
    {
        return $this->mutate(['weightNum' => $num, 'weightDenom' => $denom]);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }

    private static function guard(string $paneId, int $weightNum, int $weightDenom): void
    {
        if ($paneId === '') {
            throw new \InvalidArgumentException('DockSlot paneId must be a non-empty string');
        }
        if ($weightNum < 1) {
            throw new \InvalidArgumentException(
                "DockSlot weight numerator must be >= 1; got {$weightNum}"
            );
        }
        if ($weightDenom < 1) {
            throw new \InvalidArgumentException(
                "DockSlot weight denominator must be >= 1; got {$weightDenom}"
            );
        }
    }
}
