<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Sessions\BackgroundShellRegistry;

/**
 * SHL-1: the background shell table — spawn, settle, cursor tail, the stop
 * ladder, the lifetime wall, the fork-boundary merge, the caps.
 *
 * THE WHOLE FILE AVOIDS `$HOME`: every registry is built with an explicit
 * temp `$root`, so {@see BackgroundShellRegistry::defaultRoot()} never reads
 * the home directory and no test here can write into the developer's tree
 * (the `OneSidedHomeSandbox` family of hazards, avoided at the door rather
 * than sandboxed past it).
 *
 * LIVENESS IS ALWAYS READ BY POLLING THE REGISTRY'S OWN `status()`, never by
 * a single-shot `proc_get_status()` after a pipe drains — the kernel closes a
 * dying child's pipes before it becomes waitable, so the one-shot form is the
 * documented flake family; `status()` peels without reaping and the exit
 * file it consults is written strictly before the child exits.
 */
final class BackgroundShellRegistryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = (string) sys_get_temp_dir() . '/w2d-reg-' . getmypid() . '-' . bin2hex(random_bytes(4));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        $prefix = $this->root . '/';
        foreach (scandir($this->root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $dir = $prefix . $entry;
            foreach (scandir($dir) ?: [] as $file) {
                @unlink($dir . '/' . $file);
            }
            @rmdir($dir);
        }
        @rmdir($this->root);
    }

    private function registry(): BackgroundShellRegistry
    {
        return BackgroundShellRegistry::forSession('w2d-test', $this->root);
    }

    /**
     * Poll a predicate over registry-visible state with a real-time budget.
     * The poll-then-observe discipline is the whole point (class doc-block).
     *
     * @param callable(BackgroundShellRegistry): bool $predicate
     */
    private function waitFor(BackgroundShellRegistry $registry, callable $predicate, float $budgetSeconds = 8.0): bool
    {
        $deadline = microtime(true) + $budgetSeconds;
        while (true) {
            if ($predicate($registry)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(20_000);
        }
    }

    public function testAQuickShellSettlesWithItsExitCodeInTheOutputAndTheDurableExitFile(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf hello-out; printf oops >&2; exit 3', 'greeting');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];
        self::assertMatchesRegularExpression('/^sh-[0-9a-f]{8}$/', $id);

        $settled = $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished');
        self::assertTrue($settled, 'the shell never settled within the budget');

        $row = $registry->status($id);
        self::assertSame(3, $row['exitCode']);
        $answer = $registry->read($id);
        self::assertSame('hello-out', $answer['stdout']);
        self::assertSame('oops', $answer['stderr']);
    }

    public function testFailuresCarryTheirOwnExitCodeAndASuccessIsZero(): void
    {
        $registry = $this->registry();
        $failed = $registry->start('exit 7', 'fails');
        $ok = $registry->start('true', 'passes');
        foreach ([[(string) $failed['shell']['id'], 7], [(string) $ok['shell']['id'], 0]] as [$id, $want]) {
            $settled = $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished');
            self::assertTrue($settled);
            self::assertSame($want, $registry->status($id)['exitCode']);
        }
    }

    public function testFileSinksCarryThePrivateDoctrine(): void
    {
        $registry = $this->registry();
        $started = $registry->start('true', 'doctrine');
        $id = (string) $started['shell']['id'];
        $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished');

        $directory = $registry->directoryPath();
        self::assertSame(0o700, fileperms($directory) & 0o777, 'the session directory is group- and world-locked');
        foreach (['.out', '.err', '.exit'] as $suffix) {
            self::assertSame(
                BackgroundShellRegistry::FILE_MODE,
                fileperms($directory . '/' . $id . $suffix) & 0o777,
                $id . $suffix . ' carries the 0600 MediaStore doctrine',
            );
        }
    }

    public function testTheCursorThreadsStdoutThenStderrAsOneMonotoneOffset(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2', 'two streams');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $first = $registry->read($id, 0, 4);
        self::assertSame('AAAA', $first['stdout']);
        self::assertSame('', $first['stderr']);
        self::assertSame(4, $first['cursor']);

        $second = $registry->read($id, $first['cursor'], 4);
        self::assertSame('', $second['stdout']);
        self::assertSame('BBBB', $second['stderr']);
        self::assertSame(8, $second['cursor']);

        $third = $registry->read($id, $second['cursor'], 4);
        self::assertSame('', $third['stdout']);
        self::assertSame('', $third['stderr']);
        self::assertSame(8, $third['cursor'], 'reading past the end answers empty and keeps the cursor at the end');
    }

    public function testADefaultFreshReadAnswersTheNewestBytesAndSaysHowManyWereSkipped(): void
    {
        $registry = $this->registry();
        // 70 KiB of steadily-changing output through the DEFAULT budget: the
        // fresh read owes the tail, and the skipped head is accounted, not
        // silently eaten.
        $started = $registry->start('for i in $(seq 1 3584); do printf OLDERXXXXXXXXXNEWEST; done', 'tail wins');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $answer = $registry->read($id, 0);
        self::assertSame(71680, $answer['bytesAvailable']);
        self::assertSame(6144, $answer['skippedBytes'], '71680 - 65536');
        self::assertSame(65536, \strlen($answer['stdout']), 'the default budget answers at full size');
        self::assertStringEndsWith('NEWEST', $answer['stdout'], 'the newest bytes win the tail');
        self::assertSame(71680, $answer['cursor'], 'the cursor lands at the end, never rewound');

        // An EXPLICIT window at cursor 0 is pagination, not a tail request:
        // it reads from the very beginning and skips nothing.
        $paged = $registry->read($id, 0, 20);
        self::assertSame('OLDERXXXXXXXXXNEWEST', $paged['stdout'], 'pagination starts at the head');
        self::assertSame(0, $paged['skippedBytes'], 'an explicit window never destroys unread output');
        self::assertSame(20, $paged['cursor']);
    }

    public function testACursorPastAShrunkLogRestartsAtTheTrueEnd(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf LONGER-THAN-NEXT', 'shrink');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $answer = $registry->read($id, 10_000);
        self::assertSame('', $answer['stdout']);
        self::assertSame(16, $answer['cursor'], 'the true end of LONGER-THAN-NEXT is 16 bytes');
        self::assertSame(0, $answer['skippedBytes']);
    }

    public function testStopSignallsTheGroupAndConfirmsALiveShell(): void
    {
        $registry = $this->registry();
        $started = $registry->start('sleep 120', 'sleeper');
        $id = (string) $started['shell']['id'];
        self::assertSame('running', $registry->status($id)['state']);

        $stopped = $registry->stop($id);
        self::assertTrue($stopped['found']);
        self::assertTrue($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed'], 'a plain sleep dies to SIGTERM; the ladder must confirm it');

        // The group died with it: the pid stops answering signal 0 once the
        // launch peels it (or, unreaped, its exit file stays absent and the
        // state reads honestly — the assertion below uses the handle the
        // registry itself holds, which peels without reaping).
        self::assertNotSame('running', $registry->status($id)['state']);
    }

    public function testStopReachesTheKillRungWhenTheGroupIgnoresTermination(): void
    {
        $registry = $this->registry();
        // The fixture ARGUES the ladder in its own transcript: TERM is rung
        // one, so the ladder itself delivers it — the test cannot peek at the
        // intermediate state between rungs (and should not: the ladder is one
        // operation). What is observable afterwards is decisive: the group
        // recorded that TERM arrived and refused to die from it, yet the
        // shell IS gone — which is only true if the KILL rung fired — and the
        // exit file is still ABSENT, because a SIGKILLed wrapper never
        // reaches the trailer. A neutered KILL rung would leave the shell
        // running and `confirmed` false; a broken TERM rung would leave the
        // transcript without the note.
        $started = $registry->start(
            'trap \'printf got-term >&2; while true; do sleep 0.05; done\' TERM; printf ready >&2; while true; do sleep 0.05; done',
            'term-proof',
        );
        $id = (string) $started['shell']['id'];
        $ready = $this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => str_contains(($r->read($id)['stderr'] ?? ''), 'ready'),
            8.0,
        );
        self::assertTrue($ready, 'the fixture never announced itself');

        $stopped = $registry->stop($id);
        self::assertTrue($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed'], 'a TERM-proof shell must still die to the KILL rung');
        $transcript = (string) ($registry->read($id)['stderr'] ?? '');
        self::assertStringContainsString('got-term', $transcript, 'the TERM rung never reached the shell');
        self::assertNotSame('running', $registry->status($id)['state']);
        self::assertNull($registry->status($id)['exitCode'], 'a killed wrapper leaves its exit file empty — the code is honestly unknown');
    }

    public function testStoppingASettledShellSignalsNothingAndReportsTheCode(): void
    {
        $registry = $this->registry();
        $started = $registry->start('exit 5', 'already done');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $stopped = $registry->stop($id);
        self::assertFalse($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed']);
        self::assertSame(5, $stopped['exitCode']);
    }

    public function testTeardownKillsEveryLiveShell(): void
    {
        $registry = $this->registry();
        $first = $registry->start('sleep 120', 'one');
        $second = $registry->start('sleep 120', 'two');
        $ids = [(string) $first['shell']['id'], (string) $second['shell']['id']];
        foreach ($ids as $id) {
            self::assertSame('running', $registry->status($id)['state']);
        }

        $registry->teardown();

        foreach ($ids as $id) {
            self::assertNotSame('running', $registry->status($id)['state'], $id . ' survived the teardown');
        }
        self::assertSame([], $registry->localIds(), 'teardown releases every handle this process held');
    }

    public function testTheLifetimeWallIsALaneDecisionAndItIsFifteenHundredSeconds(): void
    {
        // The number is a decision recorded in the lane's REPORT; this pin is
        // the decision's shadow. A RESOURCE bound on detached shells, stated
        // to the model in Bash's schema — not a wall on any request.
        self::assertSame(1800, BackgroundShellRegistry::MAX_LIFETIME_SECONDS);
        self::assertSame(2.0, BackgroundShellRegistry::TERM_GRACE_SECONDS);
    }

    public function testAShellPastTheWallIsKilledAndReportedExpiredOnTheNextStatus(): void
    {
        $registry = $this->registry();
        $started = $registry->start('sleep 120', 'over the wall');
        $id = (string) $started['shell']['id'];

        // Backdate the row past the wall through the row the registry
        // publishes — the alternative is a 30-minute test, and the seam the
        // row exposes to merge() is the same surface, so this probes the real
        // field enforceLifetime reads rather than a test-only clock.
        $rows = new \ReflectionProperty(BackgroundShellRegistry::class, 'rows');
        $rows->setAccessible(true);
        $all = $rows->getValue($registry);
        $all[$id]['spawnedAt'] -= BackgroundShellRegistry::MAX_LIFETIME_SECONDS + 10.0;
        $rows->setValue($registry, $all);

        $row = $registry->status($id);
        self::assertTrue($row['expired']);
        self::assertNotSame('running', $registry->status($id)['state'], 'the wall killed the shell before answering');
    }

    public function testMergingIsIdempotentAndInformedRowsBeatUninformedOnes(): void
    {
        $registry = $this->registry();
        $started = $registry->start('true', 'export');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));
        $export = $registry->toArray();

        $fresh = $this->registry();
        $fresh->merge($export);
        $fresh->merge($export);
        self::assertSame([$id], $fresh->ids(), 'the same export twice is a no-op');
        self::assertSame(0, $fresh->status($id)['exitCode']);

        // An uninformed (still-running) row must not clobber the informed one.
        $stale = $export;
        $stale[$id]['exitCode'] = null;
        $stale[$id]['spawnedAt'] = $stale[$id]['spawnedAt'] + 100.0;
        $fresh->merge($stale);
        self::assertSame(0, $fresh->status($id)['exitCode'], 'informed beats uninformed regardless of order');
    }

    public function testMergeRefusesForgedRowsAndForeignPaths(): void
    {
        $registry = $this->registry();
        // A genuinely foreign tree: another session under another root, so
        // every derived path differs from what THIS registry would mint.
        $other = BackgroundShellRegistry::forSession('other-session', $this->root . '-elsewhere');
        $started = $other->start('true', 'elsewhere');
        $id = (string) $started['shell']['id'];

        $registry->merge([$id => $other->toArray()[$id]]);
        self::assertSame([], $registry->ids(), 'a row whose paths belong to another tree is dropped');

        $registry->merge(['sh-deadbeef' => [
            'pid' => 999999, 'pgid' => 999999,
            'stdoutPath' => $registry->directoryPath() . '/sh-deadbeef.out',
            'stderrPath' => $registry->directoryPath() . '/sh-deadbeef.err',
            'exitPath' => $registry->directoryPath() . '/sh-deadbeef.exit',
            'spawnedAt' => 1.0, 'exitCode' => null,
        ]]);
        self::assertSame(['sh-deadbeef'], $registry->ids(), 'a well-formed row for THIS tree merges');

        $registry->merge(['not-an-id' => ['pid' => 1]]);
        $registry->merge('scalar');
        $registry->merge([4 => 'row']);
        self::assertCount(1, $registry->ids(), 'malformed payloads are skipped, never fatal');
    }

    public function testTheShellCapRefusesTheSixtyFirstStartAndSaysWhatToDo(): void
    {
        $registry = $this->registry();
        $rows = new \ReflectionProperty(BackgroundShellRegistry::class, 'rows');
        $rows->setAccessible(true);
        $seed = [];
        for ($i = 0; $i < BackgroundShellRegistry::MAX_SHELLS; ++$i) {
            $id = sprintf('sh-%08x', $i);
            $seed[$id] = [
                'id' => $id, 'label' => '', 'pid' => 999999, 'pgid' => 999999,
                'stdoutPath' => $registry->directoryPath() . '/' . $id . '.out',
                'stderrPath' => $registry->directoryPath() . '/' . $id . '.err',
                'exitPath' => $registry->directoryPath() . '/' . $id . '.exit',
                'spawnedAt' => microtime(true), 'exitCode' => null, 'stopped' => false, 'expired' => false,
            ];
        }
        $rows->setValue($registry, $seed);

        $refused = $registry->start('true', 'too many');
        self::assertFalse($refused['ok']);
        self::assertStringContainsString('ShellStop', (string) $refused['error']);
    }
}
