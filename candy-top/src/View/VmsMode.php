<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

/**
 * The VM dashboard's layout law (candy-top's own; btop has no VM view).
 *
 * `vms` is a shown_boxes / preset box name like any other, but it is a
 * takeover: while it is shown it takes the whole area below the cpu box
 * (and the gpu grid) and ECLIPSES the side and process boxes — mem, net,
 * proc and ctr stay in shown_boxes, untouched, but are neither laid out,
 * painted nor sampled. Removing `vms` therefore brings back exactly the
 * boxes that were showing, with no saved copy to keep in step; and every
 * place that sizes or samples boxes (calcSizes, the minimum size, the
 * size notice, the data tick) asks {@see effective()} instead of the raw
 * list. The cpu box and the gpu boxes stay, so host load stays in view.
 */
final class VmsMode
{
    public const BOX = 'vms';

    /** Boxes the dashboard hides while it is shown. */
    public const ECLIPSED = ['mem', 'net', 'proc', 'ctr'];

    private function __construct()
    {
    }

    /** @param list<string> $boxes */
    public static function active(array $boxes): bool
    {
        return \in_array(self::BOX, $boxes, true);
    }

    /**
     * The boxes actually laid out: `$boxes` minus the eclipsed ones while
     * the dashboard is shown, else `$boxes` unchanged.
     *
     * @param list<string> $boxes
     * @return list<string>
     */
    public static function effective(array $boxes): array
    {
        if (!self::active($boxes)) {
            return $boxes;
        }

        return array_values(array_filter($boxes, static fn (string $b): bool => !\in_array($b, self::ECLIPSED, true)));
    }

    /**
     * The shown_boxes value for leaving the dashboard: `vms` removed and
     * every box in `$ensure` appended unless already listed (Enter on a
     * card brings back proc and ctr; `2`-`4`/`x` the box they name).
     *
     * @param list<string> $boxes
     * @param list<string> $ensure
     * @return list<string>
     */
    public static function leaving(array $boxes, array $ensure = []): array
    {
        $out = array_values(array_filter($boxes, static fn (string $b): bool => $b !== self::BOX));
        foreach ($ensure as $box) {
            if (!\in_array($box, $out, true)) {
                $out[] = $box;
            }
        }

        return $out;
    }
}
