<?php

declare(strict_types=1);

/**
 * Toggle alternative-screen mode at runtime. Press space to flip,
 * 'q' to quit.
 *
 *   php examples/altscreen-toggle.php
 */

require __DIR__ . '/../vendor/autoload.php';

use CandyCore\Core\Cmd;
use CandyCore\Core\KeyType;
use CandyCore\Core\Model;
use CandyCore\Core\Msg;
use CandyCore\Core\Msg\KeyMsg;
use CandyCore\Core\Program;
use CandyCore\Core\ProgramOptions;

final class AltscreenToggle implements Model
{
    public function __construct(public readonly bool $alt = false) {}

    public function init(): ?\Closure { return null; }

    public function update(Msg $msg): array
    {
        if (!$msg instanceof KeyMsg) {
            return [$this, null];
        }
        if ($msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg->type === KeyType::Space) {
            $next = new self(!$this->alt);
            $cmd  = $next->alt ? Cmd::enterAltScreen() : Cmd::exitAltScreen();
            return [$next, $cmd];
        }
        return [$this, null];
    }

    public function view(): string
    {
        $state = $this->alt ? 'ON  (alt screen)' : 'OFF (inline)';
        return "Alt-screen: $state\n\n(space to toggle, q to quit)\n";
    }
}

(new Program(new AltscreenToggle(), new ProgramOptions(useAltScreen: false)))->run();
