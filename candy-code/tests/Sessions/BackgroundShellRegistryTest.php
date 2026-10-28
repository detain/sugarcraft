<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Background\BackgroundShellRegistry;
use SugarCraft\Code\Support\ForkedChild;
use SugarCraft\Code\Support\ProcessContainment;

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

    public function testTheCursorThreadsStdoutThenStderrInsideTheirOwnFiles(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf AAAA; printf BBBB >&2', 'two streams');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $first = $registry->read($id, 0, 4);
        self::assertSame('AAAA', $first['stdout']);
        self::assertSame('', $first['stderr']);
        self::assertSame('4.0', $first['cursor'], 'the token carries each stream\'s own offset');

        $second = $registry->read($id, $first['cursor'], 4);
        self::assertSame('', $second['stdout']);
        self::assertSame('BBBB', $second['stderr']);
        self::assertSame('4.4', $second['cursor']);

        $third = $registry->read($id, $second['cursor'], 4);
        self::assertSame('', $third['stdout']);
        self::assertSame('', $third['stderr']);
        self::assertSame('4.4', $third['cursor'], 'reading past the end answers empty and keeps the cursor at the end');

        $again = $registry->read($id, $third['cursor'], 4);
        self::assertSame('', $again['stdout']);
        self::assertSame('', $again['stderr']);
        self::assertSame('4.4', $again['cursor'], 're-reading past the end is idempotent — the cursor never wanders');
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
        self::assertSame('71680.0', $answer['cursor'], 'the cursor lands at the end, never rewound');

        // An EXPLICIT window at cursor 0 is pagination, not a tail request:
        // it reads from the very beginning and skips nothing.
        $paged = $registry->read($id, 0, 20);
        self::assertSame('OLDERXXXXXXXXXNEWEST', $paged['stdout'], 'pagination starts at the head');
        self::assertSame(0, $paged['skippedBytes'], 'an explicit window never destroys unread output');
        self::assertSame('20.0', $paged['cursor']);
    }

    public function testACursorPastAShrunkLogRestartsAtTheTrueEnd(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf LONGER-THAN-NEXT', 'shrink');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));

        $answer = $registry->read($id, 10_000);
        self::assertSame('', $answer['stdout']);
        self::assertSame('16.0', $answer['cursor'], 'a legacy combined offset past the end clamps to the true end of LONGER-THAN-NEXT, 16 bytes');
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
        // MAJOR-1 fixture, shaped exactly like the leak it cures: the leader
        // takes TERM gracefully into a trapping handler (so `got-term` proves
        // rung one fired) AND spawns a member that ignores TERM outright.
        // The setsid/sh wrapper dies, the leader and the member do not — a
        // ladder that confirms on LEADER death reports success while both
        // survivors keep running inside a group whose id now points at a
        // corpse. Decisive assertions after stop(): the transcript carries
        // the TERM note, `confirmed` is true, and the GROUP ITSELF is dead —
        // `kill(-pid, 0)` no longer finds a single member. Neutering only
        // the KILL rung reddens exactly this test: the trapped pair survives
        // TERM, so group death can only have come from the second rung. The
        // exit file stays empty: a SIGKILLed wrapper never reaches the
        // trailer, and the honest answer for that code is null.
        $started = $registry->start(
            'trap \'printf got-term >&2; while :; do sleep 0.05; done\' TERM; printf ready >&2; bash -c \'trap "" TERM; while :; do sleep 0.05; done\' & wait',
            'term-proof',
        );
        $id = (string) $started['shell']['id'];
        $ready = $this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => str_contains(($r->read($id)['stderr'] ?? ''), 'ready'),
            8.0,
        );
        self::assertTrue($ready, 'the fixture never announced itself');
        $pid = (int) $registry->status($id)['pid'];

        $stopped = $registry->stop($id);
        self::assertTrue($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed'], 'a TERM-proof group must still die to the KILL rung');
        $transcript = (string) ($registry->read($id)['stderr'] ?? '');
        self::assertStringContainsString('got-term', $transcript, 'the TERM rung never reached the shell');
        self::assertNotSame('running', $registry->status($id)['state']);
        self::assertFalse(
            \function_exists('posix_kill') ? @posix_kill(-$pid, 0) : false,
            'the process group survived the ladder — a settled leader is not a dead shell',
        );
        self::assertNull($registry->status($id)['exitCode'], 'a killed wrapper leaves its exit file empty — the code is honestly unknown');
    }

    public function testACorpseLeaderCannotShelterATermProofMemberFromTheLadder(): void
    {
        // MAJOR-2 fixture, shaped like the escape it cures: the leader is
        // SIGKILLed and FULLY REAPED (proc_get_status peels it off the
        // process table) while a TERM-proof member keeps the group alive.
        // terminatePid() routes the group signal only while
        // posix_getpgid() answers on the leader, so pre-cure TERM and KILL
        // both died on ESRCH and the member outlived stop(). Post-cure the
        // rungs fall back to the direct group signal the member census
        // justifies: the row must stay running over the corpse, stop() must
        // CONFIRM, and the member must die to the KILL rung. Neutering only
        // the KILL rung's fallback reddens exactly this test.
        $registry = $this->registry();
        $memberFile = $this->root . '/member-' . bin2hex(random_bytes(3));
        $started = $registry->start(
            '( trap "" TERM; printf %s $BASHPID > ' . escapeshellarg($memberFile) . '; while :; do sleep 0.05; done ) & wait',
            'corpse-leader',
        );
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];
        $ready = $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => (int) @file_get_contents($memberFile) > 0);
        self::assertTrue($ready, 'the TERM-proof member never announced itself');
        $member = (int) @file_get_contents($memberFile);
        $pid = (int) $registry->status($id)['pid'];

        @posix_kill($pid, 9);
        // status() is also the reaper here: route 3 calls proc_get_status,
        // which waitpid()s the exited leader away. Its pgid lookup going
        // dead is the corpse state the ladder used to strand on.
        $reaped = $this->waitFor($registry, static function (BackgroundShellRegistry $r) use ($id, $pid): bool {
            $r->status($id);

            return @posix_getpgid($pid) !== $pid;
        });
        self::assertTrue($reaped, 'the leader never came off the process table');
        self::assertSame('running', $registry->status($id)['state'], 'a corpse leader must not settle a shell whose group still lives');

        $stopped = $registry->stop($id);
        self::assertTrue($stopped['wasRunning']);
        self::assertTrue($stopped['confirmed'], 'the ladder stranded on the dead leader and never reached the group');
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => !@posix_kill($member, 0), 5.0), 'the TERM-proof member survived the group KILL');
        self::assertFalse(@posix_kill(-$pid, 0), 'the process group survived the ladder — a reaped leader is not a dead shell');
        self::assertNotSame('running', $registry->status($id)['state'], 'the row reads settled once the group is truly gone');
        @unlink($memberFile);
    }

    public function testTheLaunchSweepCleansAnOrphanedGroupBehindASettledExitFile(): void
    {
        // MAJOR-2 through the teardown door, mirroring the ladder pin:
        // `X & exit 0` writes the exit file while X keeps running in the
        // group, so observe() settles the row WITHOUT a member check (the
        // boundary documented there), stop() is a no-op, and the only force
        // left is the unconditional sweep. With the launch's reaper having
        // peeled the exited leader — mirrored below through the handle PHP
        // holds — pre-cure the sweep routed through terminatePid, ESRCHed on
        // the corpse, and X escaped the launch entirely. Post-cure the
        // census-justified direct KILL reclaims it.
        $registry = $this->registry();
        $memberFile = $this->root . '/member-' . bin2hex(random_bytes(3));
        $started = $registry->start(
            '( trap "" TERM; printf %s $BASHPID > ' . escapeshellarg($memberFile) . '; while :; do sleep 0.05; done ) & exit 0',
            'orphan-behind-a-settled-row',
        );
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];
        $ready = $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => (int) @file_get_contents($memberFile) > 0);
        self::assertTrue($ready, 'the member never announced itself');
        $member = (int) @file_get_contents($memberFile);
        $pid = (int) $registry->status($id)['pid'];

        $settled = $this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished');
        self::assertTrue($settled, 'the exit file never settled the row');
        self::assertSame(0, $registry->status($id)['exitCode'], 'the wrapper exited clean and its code is the answer');
        self::assertTrue(@posix_kill($member, 0), 'pinned boundary: a settled exit file is wrapper truth — the live orphan behind it is not the row\'s verdict');
        self::assertFalse($registry->stop($id)['wasRunning'], 'stop() on a settled row is the documented no-op; the sweep is the backstop');

        // Drive the launch reaper through the handle this process holds:
        // once proc_get_status reports not-running, PHP has waitpid()ed the
        // leader and posix_getpgid() is blind — the corpse state.
        $handles = new \ReflectionProperty(BackgroundShellRegistry::class, 'handles');
        $handles->setAccessible(true);
        $map = $handles->getValue($registry);
        self::assertIsResource($map[$id] ?? null, 'this process spawned the shell, so it holds the handle');
        $peeled = $this->waitFor($registry, static function (BackgroundShellRegistry $r) use ($map, $id): bool {
            $status = @proc_get_status($map[$id]);

            return \is_array($status) && $status['running'] === false;
        });
        self::assertTrue($peeled, 'the leader never exited under the handle');
        self::assertFalse(@posix_getpgid($pid) === $pid, 'the reaped leader must no longer answer as the group — the state the sweep must survive');

        $registry->teardown();

        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => !@posix_kill($member, 0), 5.0), 'the sweep stranded on the corpse leader and let the orphan escape the launch');
        self::assertFalse(@posix_kill(-$pid, 0), 'no live group may survive teardown()');
        @unlink($memberFile);
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
        $pids = [];
        foreach ($ids as $id) {
            $state = $registry->status($id);
            self::assertSame('running', $state['state']);
            $pids[$id] = (int) $state['pid'];
        }

        $registry->teardown();

        foreach ($ids as $id) {
            self::assertNotSame('running', $registry->status($id)['state'], $id . ' survived the teardown');
            self::assertFalse(@posix_kill(-$pids[$id], 0), $id . ' left a live process group behind the teardown');
        }
        self::assertSame([], $registry->localIds(), 'teardown releases every handle this process held');
    }

    public function testTheLifetimeWallIsALaneDecisionAndItIsThirtyMinutes(): void
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
        $started = $registry->start('printf past-the-wall; sleep 120', 'over the wall');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => \str_contains((string) ($r->read($id, 0, 100)['stdout'] ?? ''), 'past-the-wall')));

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

        $late = $registry->read($id, 0, 100);
        self::assertTrue($late['ok']);
        self::assertTrue($late['expired']);
        self::assertStringContainsString('past-the-wall', $late['stdout'], 'the wall kills the process but the output file survives — ShellOutput still reads it back');
    }

    public function testMergingIsIdempotentAndInformedRowsBeatUninformedOnes(): void
    {
        $registry = $this->registry();
        $started = $registry->start('true', 'export', [], 'session-A');
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));
        $export = $registry->toArray();

        $fresh = $this->registry();
        $fresh->merge($export);
        $fresh->merge($export);
        self::assertSame([$id], $fresh->ids(), 'the same export twice is a no-op');
        self::assertSame(0, $fresh->status($id)['exitCode']);
        self::assertSame('session-A', $fresh->status($id)['owner'], 'the claim rides the export-merge channel');
        self::assertNull($fresh->status($id, 'session-B'), 'and the merged row keeps scoping by it');

        // An uninformed (still-running) row must not clobber the informed one.
        $stale = $export;
        $stale[$id]['exitCode'] = null;
        $stale[$id]['spawnedAt'] = $stale[$id]['spawnedAt'] + 100.0;
        $fresh->merge($stale);
        self::assertSame(0, $fresh->status($id)['exitCode'], 'informed beats uninformed regardless of order');
    }

    public function testStartRowsCarryTheOwnerTagAndRowsWithoutTheKeyStayVisible(): void
    {
        $registry = $this->registry();
        $id = (string) $registry->start('true', 'untagged')['shell']['id'];
        self::assertArrayHasKey('owner', $registry->toArray()[$id], 'C-TOOL-3.c: the row shape carries the claim');
        self::assertNull($registry->toArray()[$id]['owner']);

        // The literal pre-tag export shape: no key at all. It must merge to
        // an untagged row — visible to everyone — never a poison pill.
        $row = $registry->toArray()[$id];
        unset($row['owner']);
        $latecomer = $this->registry();
        $latecomer->merge([$id => $row]);
        self::assertNotNull($latecomer->status($id, 'whoever'), 'a row without the key is untagged, not unreadable');
        self::assertSame([$id], $latecomer->ownedBy('session-A'));

        $claimed = $this->registry();
        $claimedId = (string) $claimed->start('true', 'claimed', [], 'session-A')['shell']['id'];
        self::assertSame('session-A', $claimed->toArray()[$claimedId]['owner']);
        self::assertSame([$claimedId], $claimed->ownedBy('session-A'));
        self::assertSame([], $claimed->ownedBy('session-B'));

        $registry->teardown();
        $claimed->teardown();
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

    public function testTheShellCapRefusesTheSixtyFifthStartAndSaysWhatToDo(): void
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

    public function testTheLaunchBucketHandsOneRegistryPerRootAndStopKillsAndClearsIt(): void
    {
        // MINOR-1 (SHL-1 review): Bootstrap::backgroundShells() is the ONLY
        // door through which a registry exists, and stopBackgroundShells()
        // is the shutdown hook behind it — both were unpinned. A temp HOME
        // keeps the launch-scoped sinks out of the real ~/.candy-code.
        $home = sys_get_temp_dir() . '/w2d-home-' . getmypid() . '-' . bin2hex(random_bytes(3));
        mkdir($home, 0o700);
        $projectRoot = $home . '/project';
        mkdir($projectRoot, 0o700);
        $previousHome = getenv('HOME');
        $previousServerHome = $_SERVER['HOME'] ?? null;
        putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home; // twin spelling (OneSidedHomeSandbox doctrine)

        $buckets = new \ReflectionProperty(LaunchContext::class, 'shellRegistries');
        $armed = new \ReflectionProperty(LaunchContext::class, 'shellsShutdownRegistered');
        $buckets->setAccessible(true);
        $armed->setAccessible(true);
        $priorBuckets = $buckets->getValue(null);
        $priorArmed = $armed->getValue(null);
        $buckets->setValue(null, []);
        $armed->setValue(null, false);

        try {
            $first = Bootstrap::backgroundShells($projectRoot);
            self::assertSame($first, Bootstrap::backgroundShells($projectRoot), 'a second call for the same root reuses the bucket — never a second table of live shells');
            self::assertNotSame($first, Bootstrap::backgroundShells($home . '/other'), 'a different project root gets its own registry');

            $started = $first->start('printf bucket-proof; sleep 120', 'bucket pin');
            self::assertTrue($started['ok']);
            $id = (string) $started['shell']['id'];
            $pid = (int) $first->status($id)['pid'];
            self::assertStringStartsWith($home . '/.candy-code/shells', (string) $first->status($id)['stdoutPath'], 'the launch bucket places sinks under the .candy-code session home, per doctrine');
            self::assertTrue($this->waitFor($first, static fn (BackgroundShellRegistry $r): bool => str_contains((string) ($r->read($id, 0, 200)['stdout'] ?? ''), 'bucket-proof')));

            Bootstrap::stopBackgroundShells();
            self::assertNotSame('running', $first->status($id)['state'], 'the launch shutdown killed the shell it bucketed');
            self::assertFalse(@posix_kill(-$pid, 0), 'the shutdown sweep left no live group');
            self::assertNotSame($first, Bootstrap::backgroundShells($projectRoot), 'the bucket is released — the next call mints a fresh registry');
        } finally {
            Bootstrap::stopBackgroundShells();
            $buckets->setValue(null, $priorBuckets);
            $armed->setValue(null, $priorArmed);
            if ($previousHome === false) {
                putenv('HOME');
            } else {
                putenv('HOME=' . $previousHome);
            }
            if ($previousServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServerHome;
            }
        }
    }

    public function testTwoLaunchesKeepSeparateShellDirs(): void
    {
        // SHL-1-P3/P4: Bootstrap::backgroundShells mints the directory as
        // 'launch-<pid>' through the ONE constructor every caller shares,
        // and the corpse sweep reaches sibling directories from the outside:
        // a crashed launch's tree is dead weight once its pid can no longer
        // answer and the retention passed — but only then. Every gate gets
        // a plant: directory shape, dead, fresh, live, name.
        $home = \sys_get_temp_dir() . '/w2d-two-' . \getmypid() . '-' . \bin2hex(\random_bytes(3));
        $shells = $home . '/.candy-code/shells';
        $projectRoot = $home . '/project';
        \mkdir($projectRoot, 0o700, true);
        $previousHome = \getenv('HOME');
        $previousServerHome = $_SERVER['HOME'] ?? null;
        \putenv('HOME=' . $home);
        $_SERVER['HOME'] = $home; // twin spelling (OneSidedHomeSandbox doctrine)

        $buckets = new \ReflectionProperty(LaunchContext::class, 'shellRegistries');
        $armed = new \ReflectionProperty(LaunchContext::class, 'shellsShutdownRegistered');
        $buckets->setAccessible(true);
        $armed->setAccessible(true);
        $priorBuckets = $buckets->getValue(null);
        $priorArmed = $armed->getValue(null);
        $buckets->setValue(null, []);
        $armed->setValue(null, false);

        $registry = null;

        try {
            $registry = Bootstrap::backgroundShells($projectRoot);
            $first = $registry->start('printf two-launches', 'dir shape');
            self::assertTrue($first['ok'], (string) ($first['error'] ?? ''));
            $idA = (string) $first['shell']['id'];
            self::assertStringContainsString(
                '/launch-' . \getmypid() . '/',
                (string) $registry->status($idA)['stdoutPath'],
                'the one constructor scopes the directory to THIS launch pid — two launches sharing a home never share a sweep or cross ids',
            );

            $pidMax = (int) @\file_get_contents('/proc/sys/kernel/pid_max');
            $dead = $pidMax > 4096 ? $pidMax - 4 : 2147483000;
            self::assertFalse(@\posix_kill($dead, 0), 'the test needs a pid no process can hold on this host');
            $live = \posix_getppid();
            self::assertTrue(@\posix_kill($live, 0), 'the sibling stand-in for a live launch must answer');
            $ancient = \time() - BackgroundShellRegistry::CORPSE_RETENTION_SECONDS - 600;

            $dirFor = static function (string $name) use ($shells): string {
                $dir = $shells . '/' . $name;
                \mkdir($dir, 0o700);

                return $dir;
            };
            $deadDir = $dirFor('launch-' . $dead);
            \file_put_contents($deadDir . '/sh-deadbeef.out', 'planted');
            \touch($deadDir . '/sh-deadbeef.out', $ancient);
            \file_put_contents($deadDir . '/notes.txt', 'not even shell-shaped');
            \touch($deadDir . '/notes.txt', $ancient);
            \touch($deadDir, $ancient);

            $liveDir = $dirFor('launch-' . $live);
            \file_put_contents($liveDir . '/sh-cafebabe.out', 'planted');
            \touch($liveDir . '/sh-cafebabe.out', $ancient);
            \touch($liveDir, $ancient);

            $strayDir = $dirFor('launch-notapid');
            \file_put_contents($strayDir . '/sh-feedface.out', 'planted');
            \touch($strayDir . '/sh-feedface.out', $ancient);
            \touch($strayDir, $ancient);

            $freshDir = $dirFor('launch-' . ($dead - 1));
            \file_put_contents($freshDir . '/sh-80085abc.out', 'still recent');
            \touch($freshDir, $ancient); // old directory, young bytes

            $sessionDir = $dirFor('session');
            \file_put_contents($sessionDir . '/sh-de110a5.out', 'planted');
            \touch($sessionDir . '/sh-de110a5.out', $ancient);
            \touch($sessionDir, $ancient);

            \file_put_contents($shells . '/loose.log', 'never a directory');
            \touch($shells . '/loose.log', $ancient);

            $second = $registry->start('printf sweep-two', 'sweep trigger');
            self::assertTrue($second['ok'], (string) ($second['error'] ?? ''));
            $idB = (string) $second['shell']['id'];

            self::assertFalse(\is_dir($deadDir), 'a dead launch directory past the retention must not outlive the next sweep — files and the directory itself');
            self::assertTrue(\is_dir($liveDir), 'a live launch directory is never swept, whatever its age');
            self::assertTrue(\is_dir($strayDir), 'directories the sweep cannot name as a launch pid are not its business');
            self::assertTrue(\is_dir($freshDir) && \file_exists($freshDir . '/sh-80085abc.out'), 'a dead launch with fresh bytes keeps every one of them');
            self::assertTrue(\is_dir($sessionDir), 'a non-launch folder name is not a launch directory');
            self::assertTrue(\file_exists($shells . '/loose.log'), 'the sibling pass never removes plain files');

            self::assertTrue(
                $this->waitFor(
                    $registry,
                    static fn (BackgroundShellRegistry $r): bool => ($r->status($idA)['state'] ?? '') === 'finished'
                        && ($r->status($idB)['state'] ?? '') === 'finished',
                    15.0,
                ),
                'the probe shells never settled',
            );
            self::assertStringContainsString('two-launches', (string) $registry->read($idA)['stdout']);
        } finally {
            Bootstrap::stopBackgroundShells();
            $buckets->setValue(null, $priorBuckets);
            $armed->setValue(null, $priorArmed);
            if ($previousHome === false) {
                \putenv('HOME');
            } else {
                \putenv('HOME=' . $previousHome);
            }
            if ($previousServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServerHome;
            }
        }
    }

    public function testTheSinkCapStillFellsARunawayLog(): void
    {
        $registry = $this->registry();
        $started = $registry->start('cat /dev/zero 2>/dev/null', 'flood');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        $settled = $this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished',
            15.0,
        );
        // try/finally: if the cap ever regresses, this test must not leave a
        // shell writing into /tmp for the rest of the wall.
        try {
            self::assertTrue($settled, 'a runaway writer must be felled by its sink recorder, not run free to the wall');

            $state = $registry->status($id);
            // The recorder dies of SIGXFSZ at the cap; the writer's own code
            // depends on whether the broken pipe reaches it before it stops
            // writing (measured through this plumbing: 1; 141 and the legacy
            // 153 are equally honest shapes — the cap verdict rides the
            // settled SINK SIZE, not the number).
            self::assertIsInt($state['exitCode']);
            self::assertIsString($state['exitNote']);
            self::assertStringContainsString('output limit exceeded', (string) $state['exitNote']);
            $written = (int) filesize((string) $state['stdoutPath']);
            // At/over the cap is the signature; the kernel lets the crossing
            // burst land (measured 82767872), so no upper bound may be pinned.
            self::assertGreaterThanOrEqual(BackgroundShellRegistry::OUTPUT_LIMIT_BYTES, $written, 'the recorder cap must actually cap the sink');
        } finally {
            $registry->stop($id);
            // Hygiene: this flood's files are the test's own residue — the
            // five names (<id>.out/.err/.exit/.outd/.errd) go now, not into
            // the launch's sweep window.
            $row = $registry->status($id);
            $dir = \dirname((string) $row['stdoutPath']);
            $base = \basename((string) $row['stdoutPath'], '.out');
            foreach (['.out', '.err', '.exit', '.outd', '.errd'] as $ext) {
                @unlink($dir . '/' . $base . $ext);
            }
        }

        // The small-write arm: a shell far under the cap is byte-for-byte the
        // shell it was before the recorders existed.
        $small = $registry->start('printf small-ok', 'small');
        self::assertTrue($small['ok']);
        $smallId = (string) $small['shell']['id'];
        self::assertTrue($this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($smallId)['state'] ?? '') === 'finished',
        ));
        self::assertSame(0, $registry->status($smallId)['exitCode']);
        self::assertNull($registry->status($smallId)['exitNote']);
        self::assertSame('small-ok', (string) $registry->read($smallId, 0)['stdout']);
    }

    public function testANaturalExitOfTheCapCodeCarriesTheSameHonestNarration(): void
    {
        // The note derives from the exit code OR a settled capped sink; an
        // empty-sink command that CHOOSES 153 still reads through the code
        // branch, identically to the legacy SIGXFSZ shape, and the note's own
        // sentence says so. The ambiguity is disclosed, not hidden.
        $registry = $this->registry();
        $started = $registry->start('exit 153', 'chose 153');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];
        self::assertTrue($this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished',
        ));

        self::assertSame(153, $registry->status($id)['exitCode']);
        $note = (string) $registry->status($id)['exitNote'];
        self::assertStringContainsString('output limit exceeded', $note);
        self::assertStringContainsString('reads the same', $note);
        self::assertStringContainsString('output limit exceeded', (string) $registry->read($id)['exitNote']);

        $plain = $registry->start('exit 3', 'ordinary failure');
        $plainId = (string) $plain['shell']['id'];
        self::assertTrue($this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($plainId)['state'] ?? '') === 'finished',
        ));
        self::assertSame(3, $registry->status($plainId)['exitCode']);
        self::assertNull($registry->status($plainId)['exitNote']);
    }

    public function testTheWrapperCapsBothSinkRecordersExecsTheWallAndKeepsOneRecorder(): void
    {
        $wrapper = (new \ReflectionMethod(BackgroundShellRegistry::class, 'buildWrapper'))->invoke(null, 'true');
        self::assertStringStartsWith('SHELL_INNER=', $wrapper, 'the per-job cap is gone — the spawn string opens on the bound inner; only the recorders carry ulimit now');
        self::assertSame(2, substr_count($wrapper, 'trap "" TERM; ulimit -f ' . BackgroundShellRegistry::OUTPUT_LIMIT_BLOCKS . '; cat > '), 'both sinks ride capped recorders — and nothing else is capped');
        self::assertStringContainsString('${' . BackgroundShellRegistry::EXIT_CODE_FILE_ENV . '%.exit}.outd', $wrapper, 'the stdout recorder touches its done marker only after its cat falls');
        self::assertStringContainsString('${' . BackgroundShellRegistry::EXIT_CODE_FILE_ENV . '%.exit}.errd', $wrapper, 'the stderr recorder owes the trailer its own marker');
        self::assertStringContainsString('exec >&- 2>&-', $wrapper, 'the wrapper closes its own pipe copies so the recorders see EOF at the command\'s death');
        self::assertStringContainsString('SHELL_DEADLINE', $wrapper, 'the drain is bounded — a daemon that inherits the pipes cannot strand the exit file forever');
        self::assertSame(
            BackgroundShellRegistry::OUTPUT_LIMIT_BYTES,
            BackgroundShellRegistry::OUTPUT_LIMIT_BLOCKS * 512,
            'RLIMIT_FSIZE counts 512-byte blocks; the pinned figures must agree',
        );
        self::assertStringContainsString('exec timeout -s KILL ' . BackgroundShellRegistry::MAX_LIFETIME_SECONDS . ' bash -c "$SHELL_INNER"', $wrapper, 'the wall must ride an exec so the recorded pid stays the group leader');
        self::assertStringContainsString('; else exec bash -c "$SHELL_INNER"; fi', $wrapper, 'hosts without coreutils timeout lose only the parent-independent wall — the sink recorders still cap the two files');
        self::assertSame(1, substr_count($wrapper, 'SHELL_STATUS=$?'), 'the recorder rides the ONE bound inner literal both arms exec — never two copies');
        $big = 'printf ' . str_repeat('x', 70_000) . '-TAILMARKER-';
        $bigWrapper = (new \ReflectionMethod(BackgroundShellRegistry::class, 'buildWrapper'))->invoke(null, $big);
        self::assertSame(1, substr_count($bigWrapper, '-TAILMARKER-'), 'the command text is embedded exactly once');
        self::assertLessThan(131_072, \strlen($bigWrapper), 'the spawn string must stay under the per-argument exec limit');
    }

    public function testABigFileTheCommandWritesIsNotCapped(): void
    {
        // SHL-1-P4: the cap rides the two recorded SINKS, not the job. A
        // legitimate 65 MiB artifact — bigger than the sink cap, exactly the
        // file the old global `ulimit -f` fell with SIGXFSZ — must run free.
        $registry = $this->registry();
        $artifact = $this->root . '/big/artifact.bin';
        $started = $registry->start(
            'mkdir -p ' . $this->root . '/big && dd if=/dev/zero of=' . $artifact . ' bs=1M count=65 status=none',
            'big-file',
        );
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];
        try {
            self::assertTrue($this->waitFor(
                $registry,
                static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished',
                20.0,
            ), 'the recorders cap only the sinks — the dd must run to its own end');
            $state = $registry->status($id);
            self::assertSame(0, $state['exitCode'], 'a 65 MiB non-output write must not be felled by any cap');
            self::assertNull($state['exitNote'], 'the sink cap narrative is about SINKS, and these sinks are empty');
            self::assertSame(65 * 1048576, (int) filesize($artifact), 'every byte the command wrote survives');
        } finally {
            $registry->stop($id);
            @unlink($artifact);
            @rmdir($this->root . '/big');
        }
    }

    public function testAForkedChildDropsTheParentsHandles(): void
    {
        // SHL-1-P2: the class docblock's fork promise is now wired — a copy
        // of the registry entered by a process that did not perform the
        // spawns drops the inherited proc_open duplicates on ENTRY, one-way:
        // the owner's own map is untouched. The child reports through a file
        // and leaves via the house ForkedChild vehicle, never running the
        // suite's teardown a second time.
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            self::markTestSkipped('the fork door needs ext-pcntl and ext-posix to duplicate and enter the registry');
        }

        $registry = $this->registry();
        $started = $registry->start('sleep 120', 'fork pin');
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        $dir = $this->root . '/fork-' . bin2hex(random_bytes(3));
        mkdir($dir, 0o700);
        $report = $dir . '/verdict.json';

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid, 'the fork itself must succeed for this pin to mean anything');
        if ($pid === 0) {
            try {
                $before = $registry->localIds();
                $state = $registry->status($id);
                $after = $registry->localIds();
                file_put_contents($report, json_encode([
                    'before' => $before,
                    'after' => $after,
                    'state' => $state['state'] ?? '?',
                ]));
            } catch (\Throwable $thrown) {
                file_put_contents($report, json_encode(['thrown' => $thrown::class . ': ' . $thrown->getMessage()]));
            }
            ForkedChild::exitNow();
        }

        try {
            $deadline = microtime(true) + 8.0;
            while (!file_exists($report) && microtime(true) < $deadline) {
                usleep(20_000);
            }
            self::assertGreaterThanOrEqual(0, pcntl_waitpid($pid, $status), 'the child must be reaped by this test');

            $verdict = json_decode((string) file_get_contents($report), true);
            self::assertIsArray($verdict, 'the child never reported its verdict');
            self::assertArrayNotHasKey('thrown', $verdict, 'the child died inside the door: ' . (string) ($verdict['thrown'] ?? ''));
            self::assertSame([$id], $verdict['before'], 'the child process starts life holding the parent\'s inherited copies');
            self::assertSame([], $verdict['after'], 'entry to status() dropped every inherited copy');
            self::assertSame('running', $verdict['state'], 'handle-free, the row still reads honestly through the group check');
            self::assertSame([$id], $registry->localIds(), 'the door is one-way: the parent keeps what it spawned');
        } finally {
            $registry->stop($id);
            @unlink($report);
            @rmdir($dir);
        }
    }

    public function testTheGroupedFlagIsRecordedAtSpawnAndSurvivesTheForkBoundary(): void
    {
        $registry = $this->registry();
        $started = $registry->start('printf grouped; sleep 120', 'grouped pin');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];
        $grouped = ProcessContainment::detachedSpawnBinary() !== '';

        self::assertSame($grouped, $registry->status($id)['grouped'], 'grouped mirrors what the spawn actually had in front of it');

        $export = $registry->toArray();
        $receiver = $this->registry();
        $receiver->merge($export);
        self::assertSame($grouped, $receiver->status($id)['grouped'], 'a merged row keeps its spawn-time honesty');

        // A pre-SHL-1 export has no key to carry: parseRow's default answers
        // the CONSERVATIVE thing — not grouped.
        $legacy = $export;
        unset($legacy[$id]['grouped']);
        $older = $this->registry();
        $older->merge($legacy);
        self::assertFalse($older->status($id)['grouped']);

        $answer = $registry->stop($id);
        self::assertTrue($answer['found']);
        self::assertSame($grouped, $answer['grouped'], 'stop answers with the flag the narrator needs');
    }

    public function testAStdoutThatShrinksAloneDoesNotStrandTheUnreadStderr(): void
    {
        $registry = $this->registry();
        $started = $registry->start('sleep 30', 'shrink pin');
        self::assertTrue($started['ok']);
        $id = (string) $started['shell']['id'];

        try {
            $state = $registry->status($id);
            $stdoutPath = (string) $state['stdoutPath'];
            $stderrPath = (string) $state['stderrPath'];
            self::assertSame(800, (int) file_put_contents($stdoutPath, str_repeat('A', 800)));
            self::assertSame(500, (int) file_put_contents($stderrPath, str_repeat('B', 500)));

            $first = $registry->read($id, 0, 512);
            self::assertTrue($first['ok']);
            self::assertSame(str_repeat('A', 512), $first['stdout']);
            self::assertSame('', $first['stderr']);
            self::assertSame('512.0', $first['cursor']);

            $second = $registry->read($id, '512.0', 512);
            self::assertSame(str_repeat('A', 288), $second['stdout']);
            self::assertSame(str_repeat('B', 224), $second['stderr']);
            self::assertSame('800.224', $second['cursor']);

            // The shell truncates its own stdout beneath the threaded cursor.
            self::assertSame(100, (int) file_put_contents($stdoutPath, str_repeat('A', 100)));

            $third = $registry->read($id, '800.224', 4096);
            self::assertSame('', $third['stdout'], 'the truncated remainder is gone — the honest read is EOF on that stream');
            self::assertSame(str_repeat('B', 276), $third['stderr'], 'the 276 stderr bytes the caller never saw must arrive, not be skipped by arithmetic against a stdout that no longer exists — and the 224 already delivered must not replay');
            self::assertSame('100.500', $third['cursor'], 'each stream lands on its own true end: the shrunk space never lied forward');

            $fourth = $registry->read($id, '100.500', 4096);
            self::assertSame('', $fourth['stdout']);
            self::assertSame('', $fourth['stderr']);
            self::assertSame('100.500', $fourth['cursor']);
        } finally {
            $registry->stop($id);
        }
    }

    public function testInterleavedGrowthDeliversEveryByteExactlyOnce(): void
    {
        // C-TOOL-1 regression. The cursor used to be ONE global offset
        // split as min(cursor, stdoutSize) / cursor - stdoutSize, which is
        // valid only while stdout stays frozen. A shell that writes both
        // streams, pauses, then writes BOTH AGAIN makes the old split skip
        // stdout's second burst (the cursor already sat at the old stdout
        // size) and replay stderr's first burst (the remainder arithmetic
        // restarts it from the beginning). A two-part cursor advances each
        // stream in its own file, so threading it delivers every byte of
        // every stream exactly once, in order, across interleaved growth.
        $registry = $this->registry();
        // The gate lives in its own subdirectory: tearDown's sweep treats
        // every root entry as a directory it may clear (a shape this file
        // predates), so a bare gate file at root level would make its
        // scandir emit a PHP warning on cleanup.
        $gateDir = $this->root . '/gate-' . bin2hex(random_bytes(4));
        mkdir($gateDir, 0o700, true);
        $gate = $gateDir . '/go';
        $started = $registry->start(
            'printf O1; printf E1 >&2; while [ ! -f ' . escapeshellarg($gate) . ' ]; do sleep 0.05; done; printf O2; printf E2 >&2',
            'interleaved',
        );
        self::assertTrue($started['ok'], (string) ($started['error'] ?? ''));
        $id = (string) $started['shell']['id'];

        try {
            // Burst one has landed in BOTH files before the first read —
            // this is the frozen-stdout-in-the-wrong-sense shape the old
            // arithmetic could not thread.
            self::assertTrue($this->waitFor($registry, static function (BackgroundShellRegistry $r) use ($id): bool {
                \clearstatcache();
                $a = $r->read($id, 0, 1024);
                return \strlen((string) $a['stdout']) >= 2 && \strlen((string) $a['stderr']) >= 2;
            }));
            $first = $registry->read($id, 0, 1024);
            self::assertSame('O1', $first['stdout']);
            self::assertSame('E1', $first['stderr']);
            self::assertMatchesRegularExpression('/^\d+\.\d+$/', (string) $first['cursor'], 'the cursor is the two-part token, one offset per stream (C-TOOL-1)');
            self::assertSame('2.2', (string) $first['cursor'], 'two bytes consumed in each file');

            file_put_contents($gate, 'go');
            // Wait for burst two through the registry's own read, with the
            // stat cache cleared: a bare filesize() poll loop can cache the
            // pre-growth size and never refresh (only stream opens do),
            // and the shell's `[ -f ]` gate loop reads the real filesystem.
            self::assertTrue($this->waitFor($registry, static function (BackgroundShellRegistry $r) use ($id): bool {
                \clearstatcache();
                $late = $r->read($id, 0, 1024);
                return \strlen((string) $late['stdout']) >= 4 && \strlen((string) $late['stderr']) >= 4;
            }), 'the second burst lands after stderr was already read — the exact interleaving the old split mis-threaded');

            $second = $registry->read($id, $first['cursor'], 1024);
            self::assertSame('O2', $second['stdout'], 'stdout bytes written AFTER the cursor was taken must not be skipped');
            self::assertSame('E2', $second['stderr'], 'stderr bytes already delivered must not be replayed');

            self::assertTrue($this->waitFor($registry, static fn (BackgroundShellRegistry $r): bool => ($r->status($id)['state'] ?? '') === 'finished'));
            $row = $registry->status($id);
            self::assertSame(
                file_get_contents((string) $row['stdoutPath']),
                $first['stdout'] . $second['stdout'],
                'threading the cursor concatenates to the stdout file, byte for byte',
            );
            self::assertSame(
                file_get_contents((string) $row['stderrPath']),
                $first['stderr'] . $second['stderr'],
                'threading the cursor concatenates to the stderr file, byte for byte',
            );
        } finally {
            $registry->stop($id);
        }
    }

    public function testTheNextLaunchSweepsCorpseFilesAndSparesEverythingAddressedOrFresh(): void
    {
        $registry = $this->registry();
        $live = $registry->start('sleep 120', 'sweep live');
        self::assertTrue($live['ok']);
        $liveId = (string) $live['shell']['id'];
        $settled = $registry->start('printf first', 'sweep seed');
        $settledId = (string) $settled['shell']['id'];
        self::assertTrue($this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($settledId)['state'] ?? '') === 'finished',
        ));

        $directory = \dirname((string) $registry->status($liveId)['stdoutPath']);
        $ancient = time() - BackgroundShellRegistry::CORPSE_RETENTION_SECONDS - 600;
        foreach (['sh-deadbee1.out', 'sh-deadbee1.err', 'sh-deadbee1.exit'] as $corpse) {
            file_put_contents($directory . '/' . $corpse, 'x');
            touch($directory . '/' . $corpse, $ancient);
        }
        foreach (['sh-c0ffee01.out', 'sh-c0ffee01.err', 'sh-c0ffee01.exit'] as $fresh) {
            file_put_contents($directory . '/' . $fresh, 'x');
        }
        file_put_contents($directory . '/unrelated.log', 'x');
        touch($directory . '/unrelated.log', $ancient);
        // Addressed-but-old files ride too: the live sink and the settled
        // exit file are still owned by rows, and deleting under a reader is
        // not cleaning.
        touch((string) $registry->status($liveId)['stdoutPath'], $ancient);
        touch((string) $registry->status($settledId)['exitPath'], $ancient);

        $swept = $registry->start('printf sweeper', 'sweep run');
        self::assertTrue($swept['ok']);
        $sweptId = (string) $swept['shell']['id'];
        self::assertTrue($this->waitFor(
            $registry,
            static fn (BackgroundShellRegistry $r): bool => ($r->status($sweptId)['state'] ?? '') === 'finished',
        ));

        foreach (['sh-deadbee1.out', 'sh-deadbee1.err', 'sh-deadbee1.exit'] as $corpse) {
            self::assertFileDoesNotExist($directory . '/' . $corpse, 'a name-shaped corpse past retention is swept by the next launch');
        }
        foreach (['sh-c0ffee01.out', 'sh-c0ffee01.err', 'sh-c0ffee01.exit', 'unrelated.log'] as $kept) {
            self::assertFileExists($directory . '/' . $kept, 'fresh files and anything not shaped like a sink survive the sweep');
        }
        self::assertFileExists((string) $registry->status($liveId)['stdoutPath'], 'a row still addresses this shell — old mtime or not, its files are not corpses');
        self::assertFileExists((string) $registry->status($settledId)['exitPath']);
        self::assertSame('first', (string) $registry->read($settledId, 0)['stdout']);

        $registry->stop($liveId);
    }
}
