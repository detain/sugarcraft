<?php

declare(strict_types=1);

/**
 * Child fixture for {@see \SugarCraft\Code\Tests\Cli\BinInteractiveTtyGuardTest}.
 *
 * Constructs the chat `Program` exactly as `bin/candy-code` does — over
 * {@see \SugarCraft\Code\Chat::programOptions()} with no explicit streams —
 * and reports, on stdout, whether the handle the keyboard will read from is
 * a terminal. Run it as `printf hi | php <this file>` under a pty (the
 * parent test drives `script -qec`): STDIN is then a doomed pipe, exactly the
 * `echo hi | candy-code` shape of v2:MAJOR-3, while `/dev/tty` is real.
 * Before the fix the Program took the pipe and lost every keystroke at its
 * first EOF; `openTty: true` re-anchors input and output on the tty, which
 * is what the parent asserts.
 *
 * Nothing runs here — no `Program::run()`, no terminal writes — so the file
 * is inert outside the pty harness and prints a single line.
 */
require __DIR__ . '/../../vendor/autoload.php';

use SugarCraft\Code\Chat;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Model;
use SugarCraft\Core\Program;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Core\View;

$model = new class () implements Model {
    public function init(): ?\Closure
    {
        return null;
    }

    /**
     * @return array{0: Model, 1: ?\Closure}
     */
    public function update(Msg $msg): array
    {
        return [$this, null];
    }

    public function view(): string|View
    {
        return '';
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
};

$program = new Program($model, Chat::programOptions());

$input = (new ReflectionProperty(Program::class, 'input'))->getValue($program);
$output = (new ReflectionProperty(Program::class, 'output'))->getValue($program);

fwrite(\STDOUT, 'KEYBOARD-TTY=' . (stream_isatty($input) ? 'yes' : 'no')
    . ' PAINTER-TTY=' . (stream_isatty($output) ? 'yes' : 'no')
    . ' STDIN-TTY=' . (stream_isatty(\STDIN) ? 'yes' : 'no')
    . ' TTY-DESCRIPTOR-IS-STDIN=' . ($input === \STDIN ? 'yes' : 'no')
    . "\n");
