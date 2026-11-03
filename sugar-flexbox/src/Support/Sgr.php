<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Support;

/**
 * Shared validation door for bare SGR parameter strings.
 *
 * A style string here is intended to be interpolated raw into a
 * `CSI <style> m` sequence, so a caller-supplied value containing an ESC,
 * an OSC/DCS introducer, or arbitrary letters could terminate the SGR early
 * and inject attacker-controlled terminal control sequences. Constrain the
 * input to 1-3 digit codes separated by ';' at the setter (empty string and
 * null = "no style" are still permitted).
 *
 * This is the single home of the door that FlexItem, Column and Table each
 * carried a private copy of. The rejection message names WHICH property was
 * refused, so a failing setter call is diagnosable without reading the
 * class; the offending value is deliberately NOT echoed back — it is the
 * exact bytes this door exists to keep out of any output channel, and an
 * exception message routinely reaches a terminal.
 */
final class Sgr
{
    /**
     * Assert that $value is a bare SGR parameter string, naming $property in
     * the refusal.
     *
     * @param string|null $value   candidate style ('' or null = no style)
     * @param string      $property human-readable knob name for the message
     *
     * @throws \InvalidArgumentException when $value carries anything but
     *         1-3 digit codes separated by single ';'
     */
    public static function assertParameterString(?string $value, string $property): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (\preg_match('/^([0-9]{1,3}(;[0-9]{1,3})*)$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                $property . ' must be a bare SGR parameter string: 1-3 digit codes '
                    . 'separated by ";" (e.g. "1", "1;31", "38;2;255;0;0").'
            );
        }
    }
}
