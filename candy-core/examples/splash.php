<?php

declare(strict_types=1);

/**
 * Splash-screen pattern — show an animated welcome for a fixed
 * duration, then transition to the main view.
 *
 *   php examples/splash.php
 *
 * Watch for ~3 seconds. Press 'q' to skip / quit.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;

final class FrameMsg implements Msg
{
    public function __construct(public readonly int $frame)
    {
    }
}

final class FinishedMsg implements Msg
{
}

final class Splash implements Model
{
    public function __construct(
        public readonly int $frame    = 0,
        public readonly bool $finished = false,
    ) {
    }

    public function init(): ?\Closure
    {
        return Cmd::batch(
            Cmd::tick(0.15, static fn (): Msg => new FrameMsg(1)),
            Cmd::tick(3.0, static fn (): Msg => new FinishedMsg()),
        );
    }

    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }
        if ($msg instanceof FinishedMsg) {
            return [new self($this->frame, true), null];
        }
        if ($msg instanceof FrameMsg) {
            $next = $this->frame + 1;
            return [
                new self($next, $this->finished),
                Cmd::tick(0.15, static fn () => new FrameMsg($next)),
            ];
        }
        return [$this, null];
    }

    public function view(): string
    {
        if ($this->finished) {
            return "\n  Welcome to SugarCraft.\n\n  (q to quit)\n";
        }
        $glyphs = ['🍬', '🌟', '✨', '🎨', '🍭', '🎀'];
        $g = $glyphs[$this->frame % count($glyphs)];
        $dots = str_repeat('.', $this->frame % 4);
        return "\n  $g  Loading$dots\n";
    }
}

(new Program(new Splash()))->run();
