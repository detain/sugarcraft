<?php

declare(strict_types=1);

namespace SugarCraft\Code\App;

use SugarCraft\Core\Msg;
use SugarCraft\Code\Chat;

/**
 * The prompt typed on the command line after the flags (`candy-code fix the
 * login bug`), delivered by {@see Chat::init()} so it is submitted as the
 * session's first prompt once the TUI is up (audit CLI-2(b)).
 *
 * A Msg rather than a submit inside the constructor: the turn has to start
 * from `update()`, where its Cmd reaches the Program, exactly like a prompt
 * typed into the box and sent with Enter.
 */
final class InitialPromptMsg implements Msg
{
    public function __construct(public readonly string $prompt)
    {
    }
}
