#!/usr/bin/env php
<?php

/**
 * The wrapper half of `spawn-program.php` — the program the PTY child runs.
 *
 * The supervisor (`spawn-program.php`) registers `Spawn` middleware whose
 * factory returns this file's path, so sshd's ForceCommand child stays the
 * place where Logger/Auth/RateLimit decide, and the interactive Program runs
 * one process later, inside a PTY that sugar-pty allocated. That split is why
 * a `Ctrl+C` here behaves: this process has a controlling terminal, and a
 * crash here cannot take the supervisor's audit trail with it.
 *
 * It takes the pieces a supervisor knows and a Program needs -- the user, the
 * geometry -- as argv. Read the size from the arguments rather than the
 * environment: `COLUMNS`/`LINES` in the child's env describe whatever was
 * exported, not what this PTY was resized to.
 *
 * The Model below is deliberately tiny and self-contained: this file is a
 * worked example of the boundary, not a demo of any particular app. Swap
 * `Counter` for your own Model and nothing else changes.
 *
 * Run through the supervisor:  php examples/spawn-program.php
 * Run on a plain terminal:     php examples/run-program.php alice 80 24
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\SubscriptionCapable;

/**
 * Counts keys; `q` quits. Nothing else -- the point is the seam.
 */
final class Counter implements Model
{
    use SubscriptionCapable;

    public function __construct(
        private readonly string $user,
        private readonly int $cols,
        private readonly int $rows,
        private int $presses = 0,
    ) {
    }

    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg) {
            if ($msg->rune === 'q') {
                return [$this, Cmd::quit()];
            }

            return [new self($this->user, $this->cols, $this->rows, $this->presses + 1), null];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return \sprintf(
            "%s@%s  %dx%d  keys: %d\n(q to quit)\n",
            $this->user,
            \gethostname(),
            $this->cols,
            $this->rows,
            $this->presses,
        );
    }
}

$user = (string) ($argv[1] ?? 'guest');
$cols = \max(1, (int) ($argv[2] ?? 80));
$rows = \max(1, (int) ($argv[3] ?? 24));

(new Program(new Counter($user, $cols, $rows)))->run();
