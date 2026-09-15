<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Configuration options for EscapeDecoder protocol filtering.
 *
 * Allows applications to disable unused protocol parsing to reduce overhead.
 * By default all protocols are enabled.
 *
 * @example
 * ```php
 * $options = new EscapeDecoderOptions(enableMouse: false);
 * $decoder = new EscapeDecoder($options);
 * ```
 */
final class EscapeDecoderOptions
{
    /**
     * @param bool $enableMouse   Mouse reporting: SGR 1006 (CSI < b ; x ; y M|m) and X10 (CSI M b x y)
     * @param bool $enableKitty   Kitty keyboard protocol events (CSI code ; mods u — no private prefix;
     *                            the private `CSI ? … u` form is the flags reply family and is always drained)
     * @param bool $enableFocus   Focus change events (CSI I / CSI O)
     * @param bool $enablePaste   Bracketed paste mode (CSI 200 ~ ... CSI 201 ~)
     */
    public function __construct(
        public bool $enableMouse = true,
        public bool $enableKitty = true,
        public bool $enableFocus = true,
        public bool $enablePaste = true,
    ) {}
}
