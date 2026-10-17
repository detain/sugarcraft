<?php

declare(strict_types=1);

/**
 * CandyCode's real shell, offline.
 *
 * The one demo that runs the app end-to-end the way `bin/candy-code` does —
 * the pane shell ({@see App}) hosting the live {@see Chat} content model —
 * but on {@see \SugarCraft\Code\Backend\EchoBackend}, the offline backend
 * `Chat` already falls back to when nothing else is wired. No API key, no
 * network, no `~/.candy-code/config.json`: every run of this file produces
 * the same frames, which is what a recorded tape needs and what pointing a
 * tape at `bin/candy-code` itself cannot promise (that path reads the user's
 * config and may reach a real provider).
 *
 * Drives: markdown rendering of an assistant reply, the input box, the status
 * bar, and — since this goes through `App` — the OSC 11 background query
 * `App::init()` sends, so the `adaptive` theme resolves against the terminal
 * the recording is made in rather than against `COLORFGBG`.
 *
 * @see .vhs/chat.tape
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Program;
use SugarCraft\Code\App\App;
use SugarCraft\Code\Chat;
use SugarCraft\Code\Providers\EchoProvider;

$provider = new EchoProvider();

$app = App::new($provider, 'echo')
    ->withChat(new Chat(themeName: 'adaptive'));

(new Program($app, Chat::programOptions()))->run();
