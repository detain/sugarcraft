<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Support;

/**
 * Shared validation + canonicalization door for SGR parameter strings.
 *
 * A style string here is intended to be interpolated raw into a
 * `CSI <style> m` sequence, so a caller-supplied value containing an ESC,
 * an OSC/DCS introducer, or arbitrary letters could terminate the SGR early
 * and inject attacker-controlled terminal control sequences. Constrain the
 * input to 1-3 digit codes separated by ';' at the setter (empty string and
 * null = "no style" are still permitted).
 *
 * The door also CANONICALIZES grammar: the T.416/xterm colon sub-parameter
 * form (38:2:0:R:G:B / 38:5:N) is accepted but rewritten to the plain
 * semicolon spelling before anything is stored. That is the whole coupling
 * story (finding 035): the emitter interpolates the stored bytes verbatim
 * into full frames while the renderer's SGR parser only speaks semicolon
 * grammar — two readers of one string. Normalizing at this single ingress
 * means a colon can never exist downstream, so emitter and parser cannot
 * drift even one byte apart. A colon smuggled past the door (hand-built
 * line bytes) stays invisible to the parser, exactly like the pre-door
 * walk consumed it; only door-fed values are canonical.
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
    /** One plain SGR coordinate: integer 0..255, no leading zeros. */
    private const CODE = '/^(25[0-5]|2[0-4]\d|1\d{2}|[1-9]?\d)$/';

    /**
     * Validate an SGR parameter string and return its canonical (semicolon
     * grammar) form.
     *
     * @param string|null $value   candidate style ('' or null = no style)
     * @param string      $property human-readable knob name for the message
     *
     * @return string|null the same value when it was already canonical; the
     *         colon grammar rewritten to ';' — callers MUST store THIS
     *         return, not their argument
     *
     * @throws \InvalidArgumentException when $value carries anything but
     *         SGR parameter codes 0..255 separated by single ';' (plus the
     *         colon sub-param forms of 38/48, which canonicalize)
     */
    public static function normalize(?string $value, string $property): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        $out = [];
        foreach (\explode(';', $value) as $segment) {
            if (\preg_match(self::CODE, $segment) === 1) {
                // Grammar AND value bound in one pass: every ';' separated
                // parameter must be a plain integer in 0..255 (the SGR
                // coordinate space). A 1-3 digit grammar alone let 256-999
                // through the door only for the renderer to silently ignore
                // them — a knob that looks accepted but does nothing is what
                // the door exists to refuse. Leading zeros are refused too
                // ('007' is not what any emitter writes).
                $out[] = $segment;
                continue;
            }
            $expanded = self::expandColonForm($segment);
            if ($expanded !== null) {
                $out[] = $expanded;
                continue;
            }
            // An empty segment here means a doubled or trailing separator,
            // which is malformed (the empty STRING was handled above).
            throw new \InvalidArgumentException(
                $property . ' must be a bare SGR parameter string: codes 0-255 '
                    . 'separated by ";" (e.g. "1", "1;31", "38;2;255;0;0"); '
                    . 'the colon form 38:5:N / 38:2:[CS:]R:G:B canonicalizes.'
            );
        }

        return \implode(';', $out);
    }

    /**
     * Rewrite the xterm/T.416 colon sub-parameter spelling of the extended
     * color introducers 38/48 into plain ';' parameters, or refuse.
     *
     * Accepted shapes (every numeric part a plain 0..255 code):
     *   38:5:N            → 38;5;N            (256-color)
     *   38:2:R:G:B        → 38;2;R;G;B        (truecolor, no colorspace)
     *   38:2:CS:R:G:B     → 38;2;R;G;B        (colorspace id dropped — the
     *   38:2::R:G:B        same form with empty CS; the semicolon grammar
     *                     the renderer speaks carries no CS slot)
     *
     * Colons on any other introducer (31:1, 22:0, …) are refused: there is
     * no consumer that could ever read them, which is the definition of a
     * smuggling channel.
     */
    private static function expandColonForm(string $segment): ?string
    {
        if (!\str_contains($segment, ':')) {
            return null;
        }

        $parts = \explode(':', $segment);
        $intro = $parts[0];
        if (($intro !== '38' && $intro !== '48') || \count($parts) < 2) {
            return null;
        }

        if ($parts[1] === '5') {
            if (\count($parts) === 3 && \preg_match(self::CODE, $parts[2]) === 1) {
                return $intro . ';5;' . $parts[2];
            }
            return null;
        }

        if ($parts[1] === '2') {
            if (\count($parts) === 5) {
                [$r, $g, $b] = [$parts[2], $parts[3], $parts[4]];
            } elseif (\count($parts) === 6) {
                // Optional colorspace id (possibly empty) rides between the
                // kind and the components; the canonical target grammar has
                // no colorspace slot, so it is dropped, not relocated.
                if ($parts[2] !== '' && \preg_match(self::CODE, $parts[2]) !== 1) {
                    return null;
                }
                [$r, $g, $b] = [$parts[3], $parts[4], $parts[5]];
            } else {
                return null;
            }
            if (
                \preg_match(self::CODE, $r) === 1
                && \preg_match(self::CODE, $g) === 1
                && \preg_match(self::CODE, $b) === 1
            ) {
                return $intro . ';2;' . $r . ';' . $g . ';' . $b;
            }
        }

        return null;
    }
}
