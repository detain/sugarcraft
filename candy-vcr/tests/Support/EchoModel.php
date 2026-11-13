<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Support;

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Subscriptions;

/**
 * Minimal TEA model for replay tests (finding 047).
 *
 * Counts every message it receives and renders "tick: N". The old
 * pattern duplicated an identical anonymous-class model inside each
 * Player test that needed one; this is that same model, shared.
 *
 * `quitAfter` controls when the model self-terminates: the default 1
 * reproduces the historical behaviour (quit on the first message, used
 * by recording-style probes), while timing tests pass PHP_INT_MAX so the
 * replay ends on the cassette's own Quit event instead — the distinction
 * that makes an idle-trim clamp oracle meaningful.
 */
final class EchoModel implements Model
{
    public int $count = 0;

    public function __construct(
        private readonly int $quitAfter = 1,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{Model, ?\Closure}
     */
    public function update(Msg $msg): array
    {
        $this->count++;

        return [$this, $this->count >= $this->quitAfter ? Cmd::quit() : null];
    }

    public function view(): string
    {
        return "tick: {$this->count}";
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}
