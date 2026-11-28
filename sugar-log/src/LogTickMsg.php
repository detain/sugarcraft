<?php

declare(strict_types=1);

namespace SugarCraft\Log;

use SugarCraft\Core\Msg;

/**
 * One beat of a {@see LogViewer}'s follow chain: the {@see LogViewer::tick()}
 * timer fired. It carries no records; the viewer decides on taking it
 * whether to read, so the read always uses the viewer's current reader and
 * follow setting instead of the ones the timer was armed with.
 *
 * $viewerId routes the message, so two viewers on one loop never take each
 * other's ticks.
 *
 * Built with `new` like the sugar-core Msg family (see the factory exemption
 * on {@see Msg}).
 *
 * Original SugarCraft read side: charmbracelet/log has no reader.
 */
final readonly class LogTickMsg implements Msg
{
    public function __construct(
        public int $viewerId,
    ) {
    }
}
