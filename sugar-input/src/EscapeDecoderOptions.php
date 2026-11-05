<?php

declare(strict_types=1);

namespace SugarCraft\Input;

/**
 * Configuration options for EscapeDecoder protocol filtering.
 *
 * Allows applications to disable unused protocol parsing to reduce overhead.
 * All four protocol families are enabled by default; trailing-escape
 * deferral is a behaviour, not a protocol, and it defaults OFF. The options
 * are immutable — the decoder reads them at construction; a different mix
 * means a new instance (named arguments make that a one-liner).
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
     * @param bool $enableMouse   Mouse reporting: SGR 1006/1016 (CSI < b ; x ; y M|m),
     *                            urxvt 1015 (CSI b ; x ; y M) and X10 (CSI M b x y).
     *                            1016 is byte-identical to 1006 on the wire, so the
     *                            stateless decoder keeps each field's first integer
     *                            verbatim — under 1016 that IS a pixel coordinate.
     *                            Units belong to the reporting mode the host enabled,
     *                            not to the decoder.
     * @param bool $enableKitty   Kitty keyboard protocol events (CSI code ; mods u — no private prefix;
     *                            the private `CSI ? … u` form is the flags reply family and is always drained)
     * @param bool $enableFocus   Focus change events (CSI I / CSI O)
     * @param bool $enablePaste   Bracketed paste mode (CSI 200 ~ ... CSI 201 ~)
     * @param bool $deferTrailingEscape  When true, a chunk ending on a lone ESC is buffered instead of
     *                            emitted as the Escape key, so an escape sequence split across reads is
     *                            never decoded as literal keys. The application resolves the held ESC by
     *                            calling EscapeDecoder::flushDeferredEscape() after its input-idle timer
     *                            fires. When false (default) a trailing ESC resolves eagerly to Escape —
     *                            the historical contract. Caveat with true: a deferred ESC followed later
     *                            by a plain byte merges into Alt+<char> unless flushed first.
     */
    public function __construct(
        public readonly bool $enableMouse = true,
        public readonly bool $enableKitty = true,
        public readonly bool $enableFocus = true,
        public readonly bool $enablePaste = true,
        public readonly bool $deferTrailingEscape = false,
    ) {}
}
