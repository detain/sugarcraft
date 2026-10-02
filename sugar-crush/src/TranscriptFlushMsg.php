<?php

declare(strict_types=1);

namespace SugarCraft\Crush;

use SugarCraft\Core\Msg;

/**
 * The tick {@see Session\DebouncedTranscriptWriter::schedule()} arms: when it
 * lands, {@see Chat} writes the newest pending transcript snapshot (audit R2).
 *
 * Payload-free: the snapshot lives on the shared writer, so whichever Chat is
 * on screen when the tick arrives writes the LATEST history, not the one that
 * was current when the tick was armed.
 */
final class TranscriptFlushMsg implements Msg
{
}
