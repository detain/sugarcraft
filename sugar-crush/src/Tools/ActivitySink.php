<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * Where a delegated run's {@see SubAgentActivity} beats go when the process
 * running it is not the one allowed to write the turn's socket.
 *
 * {@see StreamsActivity::withActivitySink()} rebinds a tool onto one of these
 * inside a forked member of a concurrent group; the production sink is
 * {@see DatagramActivitySink}, one datagram per beat back to the forking
 * process, which replays each beat through the emitter the tool was
 * originally bound with.
 *
 * ONE-WAY OBSERVATION, NEVER CONTROL — the {@see SubAgentActivity} channel
 * contract. An implementation never throws and never blocks the run it
 * reports on: a beat it cannot deliver is dropped, and the run carries on.
 */
interface ActivitySink
{
    public function emit(SubAgentActivity $activity): void;
}
