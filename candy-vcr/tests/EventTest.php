<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Cli\Application;
use SugarCraft\Vcr\Event;
use SugarCraft\Vcr\EventKind;
use SugarCraft\Vcr\Tests\Support\Stream;

/**
 * Timestamp trust door on the event value object (finding 001, door half).
 *
 * Every cassette format funnels through this constructor, so one named
 * guard here — finite, non-negative, at most 24 h — closes the corrupted-
 * clock family for the whole library: the 1e17-second `t` that used to
 * hang the render until the harness killed it with rc=124 now dies with a
 * message that names the value.
 */
final class EventTest extends TestCase
{
    public function testAcceptsTimestampsInsideTheCeiling(): void
    {
        foreach ([0.0, 0.5, 3600.0, Event::MAX_TIMESTAMP_SECONDS] as $t) {
            $event = new Event(t: $t, kind: EventKind::Output, payload: ['b' => 'x']);
            $this->assertSame($t, $event->t);
        }
    }

    /**
     * -0.0 is numerically zero: it passes the non-negative guard (which a
     * bare `$t <= 0.0` reject would not) and normalizes to 0.0 everywhere.
     */
    public function testNegativeZeroIsZeroNotRejection(): void
    {
        $event = new Event(t: -0.0, kind: EventKind::Output, payload: ['b' => 'x']);
        $this->assertSame(0.0, $event->t);
    }

    public function testRejectsNegativeTimestamp(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative');
        new Event(t: -1.0, kind: EventKind::Output, payload: []);
    }

    public function testRejectsInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');
        new Event(t: \INF, kind: EventKind::Output, payload: []);
    }

    public function testRejectsNegativeInfinity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');
        new Event(t: -\INF, kind: EventKind::Output, payload: []);
    }

    /**
     * NAN is the comparison-evasive shape: the finite check must sit FIRST
     * or it would slip past every range guard.
     */
    public function testRejectsNan(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');
        new Event(t: \NAN, kind: EventKind::Output, payload: []);
    }

    public function testRejectsTimestampPastTheCeiling(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('24h cassette ceiling');
        new Event(t: 1.0e17, kind: EventKind::Output, payload: []);
    }

    /**
     * The finding's acceptance shape end to end: `candy-vcr inspect` on a
     * cassette carrying each corrupted clock shape must exit 1 with the
     * named error — never rc=124, never laundering the value into t=0.
     */
    public function testInspectRefusesCorruptedTimestampsWithNamedErrors(): void
    {
        $corrupt = [
            '{"t":1e999,"k":"output","b":"x"}',
            '{"t":1e17,"k":"output","b":"x"}',
            '{"t":"abc","k":"output","b":"x"}',
        ];

        foreach ($corrupt as $line) {
            $path = tempnam(sys_get_temp_dir(), 'vcr-t') . '.jsonl';
            file_put_contents($path, json_encode([
                'v' => 2,
                'created' => '2026-11-12T00:00:00Z',
                'cols' => 80,
                'rows' => 24,
                'runtime' => 'x',
            ]) . "\n" . $line . "\n");

            [$exit, $out] = $this->runCli(['candy-vcr', 'inspect', $path]);
            @unlink($path);

            self::assertSame(1, $exit, "corrupted clock {$line} must exit 1, got {$exit}:\n{$out}");
            self::assertMatchesRegularExpression(
                '/finite|24h cassette ceiling|must be a finite non-negative number of seconds/',
                $out,
                "inspect output must name the timestamp door:\n{$out}"
            );
        }
    }

    /** @return array{0:int,1:string} */
    private function runCli(array $argv): array
    {
        $stdout = Stream::memory('w+');
        $stderr = Stream::memory('w+');
        $this->assertNotFalse($stdout);
        $this->assertNotFalse($stderr);

        $exit = (new Application())->run($argv, $stdout, $stderr);

        rewind($stdout);
        rewind($stderr);
        $combined = (string) stream_get_contents($stdout) . (string) stream_get_contents($stderr);

        return [$exit, $combined];
    }
}
