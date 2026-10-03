<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Msg;

/**
 * Bracketed-paste payload. Emitted by {@see \SugarCraft\Core\InputReader}
 * when it sees the `CSI 200~ … CSI 201~` envelope a terminal wraps
 * around pasted text after `CSI ?2004h`.
 *
 * No key parsing is performed inside the paste, so newlines stay
 * newlines. By default the bytes are passed through
 * {@see \SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames()}
 * (embedded escapes / control sequences and zone sentinels neutralized);
 * with {@see \SugarCraft\Core\ProgramOptions::$sanitizePaste} off they are
 * exposed verbatim, control characters included. Models that want to
 * insert the paste should treat it as a single atomic edit.
 *
 * A paste larger than {@see \SugarCraft\Core\InputReader::MAX_PASTE_BYTES}
 * arrives as several PasteMsgs in order — the leading chunks while the
 * envelope is still open (before {@see PasteEndMsg}), the remainder after
 * it — so concatenating every PasteMsg between PasteStartMsg and the one
 * following PasteEndMsg reproduces the paste exactly. That bound is what
 * keeps an envelope that never closes from growing memory without limit.
 *
 * An envelope whose end marker is lost is closed by
 * {@see \SugarCraft\Core\Program} once input has been silent for
 * {@see \SugarCraft\Core\InputReader::PASTE_IDLE_TIMEOUT} seconds: the
 * same PasteEndMsg + PasteMsg pair is emitted and later bytes are keys again.
 */
final class PasteMsg implements Msg
{
    public function __construct(public readonly string $content)
    {
    }
}
