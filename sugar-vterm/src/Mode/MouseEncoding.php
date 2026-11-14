<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Mode;

/**
 * Mouse report coordinate encoding selected by the DEC 100x/101x family.
 *
 * These modes are ENCODING selectors, not tracking toggles: xterm ctlseqs
 * documents 1005/1006/1015 (and 1016) as alternatives that only change how a
 * report's coordinates are spelled once some tracking mode (1000/1001/1002/
 * 1003) is on. vterm-12 corrected an earlier over-modelling here: xterm
 * switches each mode INDEPENDENTLY (1005 and 1015 may both be on), so Mode
 * stores three flags and this enum describes only the EFFECTIVE encoder,
 * resolved by {@see Mode::effectiveMouseEncoding()} with xterm's SGR >
 * urxvt > UTF-8 > X10 priority; turning the top mode off falls to the next
 * flag still on. "Is reporting on" ({@see Mode::isMouseReporting()}) stays
 * orthogonal to "how to encode".
 *
 *  - {@see MouseEncoding::Default} — X10 vector encoding: button + 32,
 *    row/col + 32, one byte per component (sugar-ansi-parser's historical path)
 *  - {@see MouseEncoding::Utf8}    — DEC 1005: UTF-8 codepoints above 160
 *  - {@see MouseEncoding::Sgr}     — DEC 1006: printable `CSI <b;x;yM/m`
 *  - {@see MouseEncoding::Urxvt}   — DEC 1015: numeric `CSI b;x;y M`
 *
 * Mirrors charmbracelet/x/vt mouse-encoding mode family
 * (xterm ctlseqs "Extended mouse coordinates" / DEC 1005, 1006, 1015).
 *
 * @see https://invisible-island.net/xterm/ctlseqs/ctlseqs.html#h3-Extended-coordinates
 */
enum MouseEncoding: int
{
    /** X10 default: one byte per component, each biased by +32. */
    case Default = 0;

    /** DEC 1005 — coordinates as UTF-8-encoded codepoints (row/col + 32). */
    case Utf8 = 1;

    /** DEC 1006 — printable SGR form `CSI < button ; col ; row M` (press) / `m` (release). */
    case Sgr = 2;

    /** DEC 1015 — urxvt numeric form `CSI button+32 ; col ; row M`. */
    case Urxvt = 3;
}
