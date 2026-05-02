<?php

declare(strict_types=1);

namespace CandyCore\Core\Msg;

use CandyCore\Core\Msg;

/**
 * Bracketed-paste payload. Emitted by {@see \CandyCore\Core\InputReader}
 * when it sees the `CSI 200~ … CSI 201~` envelope a terminal wraps
 * around pasted text after `CSI ?2004h`.
 *
 * The buffered bytes between the two markers are exposed verbatim — no
 * key parsing is performed inside the paste, so newlines stay newlines
 * and control characters are preserved as-is. Models that want to
 * insert the paste should treat it as a single atomic edit.
 */
final class PasteMsg implements Msg
{
    public function __construct(public readonly string $content) {}
}
