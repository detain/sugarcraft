<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Msg;

/**
 * The result of one {@see LogViewer} read, as the viewer's read Cmd found it
 * when it ran: the records the filter keeps that were written since $from
 * ($fresh false: the viewer appends them), or the file's last matching
 * records ($fresh true: they replace what the viewer holds).
 *
 * $viewerId and $generation route the message: a viewer only takes a read it
 * issued itself, since its reader and follow setting last changed, so two
 * viewers on one loop never take each other's reads and a read of an old
 * file, or one that lands after a pause, is dropped.
 *
 * $chained marks a read the viewer's follow chain issued: taking it, even
 * when its records are dropped, schedules the chain's next
 * {@see LogViewer::tick()}. A {@see LogViewer::refresh()} read is not
 * chained, so it never starts a second chain.
 *
 * $from is the cursor the read resumed from and $cursor where it stopped. A
 * viewer appends a resumed read only while it still holds $from, so two
 * reads from one cursor (a refresh beside a tick) never append a line twice.
 *
 * Built with `new` like the sugar-core Msg family (see the factory exemption
 * on {@see Msg}).
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogTailMsg implements Msg
{
    /**
     * @param list<LogRecord> $records The records read, oldest first.
     * @param ?string         $error   Why the read failed; $records is then empty.
     * @param bool            $chained Whether the follow chain issued this read.
     * @param ?LogCursor      $from    The cursor the read resumed from; null for a read from the tail.
     * @param ?LogCursor      $cursor  Where the read stopped; null when the file does not exist or the read failed.
     * @param bool            $fresh   Whether $records replace what the viewer holds rather than extend it.
     */
    public function __construct(
        public int $viewerId,
        public int $generation,
        public array $records,
        public ?string $error = null,
        public bool $chained = false,
        public ?LogCursor $from = null,
        public ?LogCursor $cursor = null,
        public bool $fresh = true,
    ) {
    }
}
