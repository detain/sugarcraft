<?php

declare(strict_types=1);

/**
 * ProgramSimulator — drive a TEA model headlessly and inspect the run.
 *
 *   php examples/program-simulator.php
 *
 * The inline counter implements the sugar-core Model contract
 * (init/update/view/subscriptions). The simulator wraps it, ScriptedInput
 * builds the keystrokes, and captureCmds() keeps the quit Cmd collected
 * instead of executed — the deterministic mode for unit tests. No real
 * stdin/stdout, no timers, no signal handlers are touched.
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\QuitMsg;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Testing\Input\ScriptedInput;
use SugarCraft\Testing\ProgramSimulator;

final class DemoCounter implements Model
{
    public function __construct(private readonly int $count = 0)
    {
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
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char) {
            if ($msg->rune === '+') {
                return [new self($this->count + 1), null];
            }

            if ($msg->rune === 'q') {
                // A real program quits through a Cmd; the simulator's
                // capture mode collects it without executing anything.
                return [$this, static fn (): ?Msg => new QuitMsg()];
            }
        }

        return [$this, null];
    }

    public function view(): string
    {
        return 'count: ' . $this->count;
    }

    public function subscriptions(): ?Subscriptions
    {
        return null;
    }
}

$input = ScriptedInput::new()->key('+')->key('+')->key('q');

$sim    = ProgramSimulator::new(new DemoCounter())->captureCmds();
$result = $sim->addMsgs(...$input->build())->run();

echo "final view:   {$result->view}\n";
echo "frames seen:  " . count($result->frames) . "\n";

$result->assertCmdCount(1);          // the quit Cmd was collected, not run

$final = $result->model;
if (!$final instanceof DemoCounter || $result->view !== 'count: 2') {
    fwrite(STDERR, "unexpected run outcome — the scripted keys must land at count: 2\n");
    exit(1);
}

echo "assertions:   cmd count pinned at 1, model at count: 2, quit never executed\n";
