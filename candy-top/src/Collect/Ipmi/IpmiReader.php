<?php

declare(strict_types=1);

namespace SugarCraft\Top\Collect\Ipmi;

use React\Promise\PromiseInterface;
use SugarCraft\Top\Source\Source;

/**
 * Where the ipmi box's data comes from: the live {@see Ipmi} collector
 * (ipmitool on the loop) or {@see \SugarCraft\Top\Source\Fake\FakeIpmi}.
 * It is a {@see Source} so a sample can ride a SampledMsg, but the panel
 * drives it through {@see poll()}: rounds are asynchronous and at most
 * one is in flight.
 */
interface IpmiReader extends Source
{
    /**
     * Start a round, or null while one is still in flight (at most one
     * ipmitool child at a time — the in-flight round answers its own Cmd).
     * `$slowMs` is the cadence of the expensive reads (the SDR walk,
     * chassis, SEL). The promise resolves with an {@see IpmiSnapshot} and
     * never rejects.
     *
     * @return ?PromiseInterface<IpmiSnapshot>
     */
    public function poll(int $slowMs): ?PromiseInterface;
}
