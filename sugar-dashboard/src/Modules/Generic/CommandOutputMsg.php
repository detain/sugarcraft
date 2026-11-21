<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Modules\Generic;

use SugarCraft\Core\Msg;

/**
 * The settled result of one run of a Generic module's command, delivered back
 * to the module that started it. The command runs on the loop, so a tick never
 * blocks on it; `$output` is the text view() shows (output, or the named failure).
 * `$run` names the run that produced it: a hung run can settle after a newer
 * one has started, and only the newest may clear the in-flight latch.
 */
final readonly class CommandOutputMsg implements Msg
{
    public function __construct(
        public string $source,
        public string $output,
        public int $run,
    ) {
    }
}
