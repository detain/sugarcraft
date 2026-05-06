<?php

declare(strict_types=1);

namespace CandyCore\Bits\Help;

use CandyCore\Sprinkles\Style;

/**
 * Styles for {@see Help}: one slot per visible help element. All
 * default to a no-op {@see Style} so existing snapshot tests keep
 * passing; pass non-default styles to {@see Help::withStyles()} to
 * customise. Mirrors upstream Bubbles' `help.Styles`.
 */
final class Styles
{
    public readonly Style $shortKey;
    public readonly Style $shortDesc;
    public readonly Style $shortSeparator;
    public readonly Style $fullKey;
    public readonly Style $fullDesc;
    public readonly Style $fullSeparator;
    public readonly Style $ellipsis;

    public function __construct(
        ?Style $shortKey       = null,
        ?Style $shortDesc      = null,
        ?Style $shortSeparator = null,
        ?Style $fullKey        = null,
        ?Style $fullDesc       = null,
        ?Style $fullSeparator  = null,
        ?Style $ellipsis       = null,
    ) {
        $noop = Style::new();
        $this->shortKey       = $shortKey       ?? $noop;
        $this->shortDesc      = $shortDesc      ?? $noop;
        $this->shortSeparator = $shortSeparator ?? $noop;
        $this->fullKey        = $fullKey        ?? $noop;
        $this->fullDesc       = $fullDesc       ?? $noop;
        $this->fullSeparator  = $fullSeparator  ?? $noop;
        $this->ellipsis       = $ellipsis       ?? $noop;
    }

    /** Convenience: every element styled identically. */
    public static function uniform(Style $s): self
    {
        return new self($s, $s, $s, $s, $s, $s, $s);
    }
}
