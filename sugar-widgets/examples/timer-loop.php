<?php

declare(strict_types=1);

/**
 * Timer on a real event loop — the Subscriptions::withTick → TimerTick →
 * update() → [model, Cmd] chain end to end.
 *
 *   q                 quit
 *
 *   php examples/timer-loop.php
 *
 * This is the counterpart to examples/timer.php (which only formats
 * durations): here the arming seam documented in the README's message
 * cheat-sheet is made concrete. The host arms one periodic tick
 * subscription that produces the exact Timer\TimerTick the component
 * matches — id and epoch taken from the started timer so the msg passes
 * the generation guard — and the countdown renders every second until
 * the TimeoutMsg fires. The timer's own one-shot reschedule Cmd is
 * swallowed in update() so the subscription stays the single cadence
 * source (arming both would double-count each interval).
 */

require __DIR__ . '/../vendor/autoload.php';

use SugarCraft\Core\Cmd;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Model;
use SugarCraft\Core\Msg;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Program;
use SugarCraft\Core\Subscriptions;
use SugarCraft\Widgets\Timer\TimerTick;
use SugarCraft\Widgets\Timer\Timer;

// Start the timer once up-front and keep its armed model state; the
// discarded Cmd is the component's self-rescheduling tick — this demo
// replaces it with the host-side subscription below.
[$started, ] = Timer::new(10.0, 1.0)->start();

$model = new class($started) implements Model {
    public function __construct(
        private readonly Timer $timer,
    ) {}

    public function init(): ?\Closure
    {
        return null;
    }

    public function subscriptions(): ?Subscriptions
    {
        if (!$this->timer->isRunning()) {
            return null;
        }
        // The host arms the loop: produce the exact TimerTick this Timer
        // instance matches (its id, its current generation epoch).
        $id = $this->timer->id;
        $epoch = $this->timer->epoch;
        return (new Subscriptions())
            ->withTick('timer-loop', $this->timer->interval(), static fn (): Msg => new TimerTick($id, $epoch));
    }

    /**
     * @return array{0:Model, 1:?\Closure}
     */
    public function update(Msg $msg): array
    {
        if ($msg instanceof KeyMsg && $msg->type === KeyType::Char && $msg->rune === 'q') {
            return [$this, Cmd::quit()];
        }

        [$next, $cmd] = $this->timer->update($msg);
        assert($next instanceof Timer);

        if ($next->timedOut()) {
            // Forward the component's own TimeoutMsg dispatch, then stop.
            return [new self($next), $cmd];
        }

        // Cadence lives in subscriptions() above — swallow the
        // one-shot self-reschedule so the two never double-arm.
        return [new self($next), null];
    }

    public function view(): string
    {
        $line = $this->timer->view();
        $status = $this->timer->timedOut() ? 'time is up!' : 'counting down…';
        return "\n  Countdown  {$line}   {$status}\n\n  q to quit\n";
    }
};

(new Program($model))->run();
