<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Support;

use React\EventLoop\Loop;

/**
 * Loop hygiene for the async-touching suites (sugar-forms-036).
 *
 * Two jobs, one seam:
 *
 *  1. CAPTURE + RESTORE. Tests legitimately swap the process-global loop
 *     (`Loop::set(new StreamSelectLoop())`) for isolation; before this trait
 *     existed, the two swapping files never restored, so every later test in
 *     the shard ran on a mutated foreign loop instance. setUp captures the
 *     instance, tearDown puts it back.
 *
 *  2. RESIDUE TRIPWIRE. Against the RESTORED loop instance, tearDown runs
 *     {@see SharedLoopResidue::census()} and fails loudly on any timer,
 *     stream, signal or future tick this test left armed above the baseline
 *     captured at setUp — the orphan that makes an unrelated later
 *     `Loop::run()` return early (or never) is caught at its source.
 *
 * Files that use their own setUp/tearDown compose via
 * `parent::setUp(); parent::tearDown();` exactly like the pty house pattern.
 */
trait LoopHygiene
{
    private ?\React\EventLoop\LoopInterface $hygieneSavedLoop = null;

    /** @var array{timers:int, readStreams:int, writeStreams:int, signals:int, futureTicks:int}|null */
    private ?array $hygieneBaseline = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->hygieneSavedLoop = Loop::get();
        $this->hygieneBaseline  = SharedLoopResidue::census();
    }

    protected function tearDown(): void
    {
        if ($this->hygieneSavedLoop !== null) {
            Loop::set($this->hygieneSavedLoop);
            $this->hygieneSavedLoop = null;
        }

        $after = SharedLoopResidue::census();
        if ($this->hygieneBaseline !== null && $after !== $this->hygieneBaseline) {
            $message = 'this test left residue on the shared loop: ' . SharedLoopResidue::describe()
                . ' (baseline: ' . $this->describeBaseline() . ')';
            $this->hygieneBaseline = null;
            $this->fail($message);
        }
        $this->hygieneBaseline = null;

        parent::tearDown();
    }

    private function describeBaseline(): string
    {
        $b = $this->hygieneBaseline ?? [];

        return \sprintf(
            '%d timer(s), %d read stream(s), %d write stream(s), %d signal(s), %d future tick(s)',
            $b['timers'] ?? 0,
            $b['readStreams'] ?? 0,
            $b['writeStreams'] ?? 0,
            $b['signals'] ?? 0,
            $b['futureTicks'] ?? 0,
        );
    }
}
