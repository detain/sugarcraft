<?php

declare(strict_types=1);

namespace SugarCraft\Kit\Internal;

/**
 * The kit presenters share one rule for their `$width` argument: a width
 * below one cell is an authoring error and says so, while `null` stays a
 * deliberate opt-out whose meaning differs per presenter.
 *
 * Before this existed, HelpText threw while Section quietly clamped a bad
 * width to an empty line — so the same mistake produced either a stack trace
 * or invisible wrong output depending on which presenter happened to be
 * called. One guard keeps the verdict identical across the library, and keeps
 * a third presenter from inventing a third behavior.
 *
 * @internal Not part of the public API; may change without notice.
 */
final class WidthGuard
{
    /**
     * @param string $presenter   name the thrown message should blame
     * @param int|null $width     the caller's width, already null-checked here
     * @param string $nullMeaning how to read `null` for this presenter, phrased
     *                            to complete "or null to …"
     *
     * @throws \InvalidArgumentException when `$width` is non-null and less than 1
     */
    public static function assert(string $presenter, ?int $width, string $nullMeaning): void
    {
        if ($width !== null && $width < 1) {
            throw new \InvalidArgumentException(
                $presenter . ' width must be at least 1 cell (or null to ' . $nullMeaning . '); got ' . $width . '.'
            );
        }
    }
}
