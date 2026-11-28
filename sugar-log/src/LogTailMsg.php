<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Msg;

/**
 * The result of one {@see LogViewer} read: the file's last records, as the
 * viewer's read Cmd found them when it ran.
 *
 * $viewerId and $generation route the message: a viewer only takes a read it
 * issued itself, from its current follow chain, so two viewers on one loop
 * never take each other's reads and a tick left over from before follow was
 * toggled is dropped instead of starting a second chain.
 *
 * Built with `new` like the sugar-core Msg family (see the factory exemption
 * on {@see Msg}).
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogTailMsg implements Msg
{
    /**
     * @param list<LogRecord> $records The newest records, oldest first.
     * @param ?string         $error   Why the read failed; $records is then empty.
     */
    public function __construct(
        public int $viewerId,
        public int $generation,
        public array $records,
        public ?string $error = null,
    ) {
    }
}
