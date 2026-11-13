<?php

declare(strict_types=1);

namespace SugarCraft\Mail\Tui;

use SugarCraft\Core\Msg;

/**
 * Outcome of the background send the compose form kicked off.
 *
 * `$error` is raw exception text (it can carry server bytes) - the model
 * sanitises it at paint time, not here.
 */
final class SendResultMsg implements Msg
{
    public function __construct(
        public readonly ?string $error,
        public readonly string $transport = '',
    ) {
    }
}
