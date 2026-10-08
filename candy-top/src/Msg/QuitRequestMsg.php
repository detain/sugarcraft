<?php

declare(strict_types=1);

namespace SugarCraft\Top\Msg;

use SugarCraft\Core\Msg;

/**
 * Ask the App to quit from somewhere that cannot reach it — the main
 * menu's Quit entry. The App answers with its quit Cmd, which first saves
 * the config when save_config_on_exit asks for it (btop clean_quit:
 * `if (save_config_on_exit) Config::write()`), so every exit path writes.
 */
final class QuitRequestMsg implements Msg
{
}
