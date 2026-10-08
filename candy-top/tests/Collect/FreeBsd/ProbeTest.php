<?php

declare(strict_types=1);

namespace SugarCraft\Top\Tests\Collect\FreeBsd;

use PHPUnit\Framework\TestCase;
use SugarCraft\Top\Collect\FreeBsd\LiveProbe;
use SugarCraft\Top\Collect\FreeBsd\Sysctl;
use SugarCraft\Top\Tests\Collect\FreeBsd\Support\FixtureProbe;

final class ProbeTest extends TestCase
{
    public function testParsesBothSysctlOutputForms(): void
    {
        $values = Sysctl::parse("hw.ncpu: 4\nhw.model=Intel(R) Atom(TM) CPU D525   @ 1.80GHz\nkern.boottime: { sec = 1, usec = 500000 } Thu\ndev.cpu.0.%desc=ACPI CPU\nnoise line\n");

        $this->assertSame('4', $values['hw.ncpu']);
        $this->assertSame('Intel(R) Atom(TM) CPU D525   @ 1.80GHz', $values['hw.model']);
        $this->assertSame('ACPI CPU', $values['dev.cpu.0.%desc']);
        $this->assertSame(1.5, Sysctl::boottime($values));
        $this->assertCount(4, $values);
    }

    public function testValueHelpers(): void
    {
        $v = ['a' => '40.1C', 'b' => '-1', 'c' => '313.15K', 'd' => '7', 'e' => '1 2 x', 'vm.loadavg' => '{ 0.32 0.33 0.25 }'];

        $this->assertSame(40.1, Sysctl::celsius($v, 'a'));
        $this->assertNull(Sysctl::celsius($v, 'b'), '-1 = trip point not set');
        $this->assertEqualsWithDelta(40.0, Sysctl::celsius($v, 'c'), 1e-9);
        $this->assertNull(Sysctl::celsius($v, 'missing'));
        $this->assertSame(7, Sysctl::int($v, 'd'));
        $this->assertNull(Sysctl::int($v, 'a'));
        $this->assertSame([], Sysctl::ints($v, 'e'), 'one bad token voids the vector');
        $this->assertSame([0.32, 0.33, 0.25], Sysctl::loadavg($v));
        $this->assertNull(Sysctl::loadavg([]));
        $this->assertNull(Sysctl::boottime([]));
    }

    public function testLiveProbeIsTotalOnAnyHost(): void
    {
        $probe = LiveProbe::new();

        $this->assertNull($probe->run(['candy-top-no-such-tool-' . getmypid()]));
        $this->assertNull($probe->run([]));
        $this->assertSame([], $probe->sysctl([]));
        $this->assertSame('hi', trim((string) $probe->run(['echo', 'hi'])), 'base dirs then PATH');
        $this->assertNull($probe->run(['false']), 'non-zero exit is no answer');
        $this->assertNull($probe->file('/nonexistent/' . getmypid()));
        $this->assertNotNull($probe->space('/'));
        $this->assertNull($probe->space('/nonexistent/' . getmypid()));
        $this->assertGreaterThan(0.0, $probe->epoch());
        $a = $probe->monotonic();
        $this->assertGreaterThanOrEqual($a, $probe->monotonic());
    }

    public function testLiveProbeRunsChildrenUnderTheCLocale(): void
    {
        $env = LiveProbe::new()->run(['env']);

        $this->assertNotNull($env);
        $this->assertMatchesRegularExpression('/^LC_ALL=C$/m', $env);
    }

    public function testLiveProbeKillsAndReapsAnOverrunningChild(): void
    {
        $started = microtime(true);
        $this->assertNull(LiveProbe::new()->run(['sleep', '5']));
        $this->assertLessThan(2.9, microtime(true) - $started, 'TIMEOUT (2 s) + a bounded reap, never the full 5 s');
        if (!is_dir('/proc/self')) {
            return;
        }
        $left = [];
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            $raw = @file_get_contents($stat);
            if ($raw === false || ($close = strrpos($raw, ')')) === false) {
                continue;
            }
            $fields = explode(' ', substr($raw, $close + 2));
            if ((int) ($fields[1] ?? 0) === getmypid() && str_contains($raw, '(sleep)')) {
                $left[] = $stat;
            }
        }
        $this->assertSame([], $left, 'the killed child was reaped: no sleep (zombie or alive) is left under this process');
    }

    public function testLiveProbeExitStatusIsKeptAcrossTheReapPoll(): void
    {
        // proc_close() after proc_get_status() saw the exit returns -1; the
        // probe must use the polled code, or every success would read null.
        $this->assertSame("ok\n", LiveProbe::new()->run(['echo', 'ok']));
        $this->assertNull(LiveProbe::new()->run(['sh', '-c', 'echo partial; exit 3']));
    }

    public function testFixtureProbeAnswersSubtreesLikeSysctlI(): void
    {
        $probe = new FixtureProbe(FixtureProbe::fixture('sysctl-reference.txt'));

        $this->assertSame(['hw.ncpu' => '4'], $probe->sysctl(['hw.ncpu', 'no.such.oid']));
        $this->assertCount(15, $probe->sysctl(['hw.acpi.thermal']));
    }
}
