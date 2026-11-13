<?php

declare(strict_types=1);

/**
 * Child-process rig for SuspendResumeTest (sc-10, finding sugar-core-014).
 *
 * Runs a REAL Program on piped streams and prints one `MSG:<class>` line to
 * STDOUT for every Msg the MODEL sees, while all render bytes go to STDERR.
 * The suspend family ends in a genuine SIGTSTP stop of the whole process, so
 * no in-process test could observe it without stopping PHPUnit itself: the
 * parent drives the Ctrl-Z dance from outside and counts markers.
 *
 * argv[1]: 'pass' = no WithFilter installed; 'filter' = a pass-through
 * WithFilter is installed (the stash must reach the model either way).
 *
 * argv[2] (optional): 'no-pcntl' = the parent already disabled every pcntl_*
 * function with -d disable_functions, so the build shape the suspend guard
 * sees is the no-pcntl one.
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Cmd;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\ProgramOptions;
use SugarCraft\Core\SubscriptionCapable;

final class SuspendChildModel implements Model
{
    use SubscriptionCapable;

    public function init(): ?\Closure
    {
        return null;
    }

    public function update(Msg $msg): array
    {
        \fwrite(STDOUT, 'MSG:' . $msg::class . "\n");
        if ($msg instanceof KeyMsg && $msg->rune === 'z') {
            return [$this, Cmd::suspend()];
        }
        if ($msg instanceof KeyMsg && $msg->rune === 'q') {
            // The parent closes the run explicitly — the model deliberately
            // survives every ResumeMsg so a duplicate is OBSERVABLE rather
            // than raced away by an immediate quit.
            return [$this, Cmd::quit()];
        }

        return [$this, null];
    }

    public function view(): string
    {
        return 'suspend-child';
    }
}

$loop = new StreamSelectLoop();
$program = new Program(new SuspendChildModel(), new ProgramOptions(
    useAltScreen: false,
    catchInterrupts: true,   // installs the Program's SIGTSTP/SIGCONT handlers
    input: STDIN,
    output: STDERR,
    loop: $loop,
    filter: ($argv[1] ?? 'pass') === 'filter'
        ? static fn (Model $model, Msg $msg): Msg => $msg
        : null,
));
// Rig ceiling so a lost parent can never strand the child past the suite.
// Sized ABOVE the parent waits (30s stop-wait + 30s drain + settle) so host
// load cannot make the child stop ITSELF before the stop cycle is observed.
$loop->addTimer(120.0, static function () use ($loop): void {
    $loop->stop();
});
$program->run();
exit(0);
