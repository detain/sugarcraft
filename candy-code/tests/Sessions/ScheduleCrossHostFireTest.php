<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Tests\Support\RunsWallClockBoundedChildTrait;

/**
 * AUT-1: the cross-host duel, past the one case the per-row lock already handled.
 *
 * THE INVARIANT THE LOCK EXISTS TO KEEP. Two hosts sharing one home — a TUI
 * and `serve`, or two TUIs — must run a due row ONCE between its slots. The
 * audit probe (`/tmp/opencode/cc-compare-audit/aut/probe_clobber.php`) showed
 * both halves of that broken in the worst legal interleaving, and showed the
 * second half is worse than a duplicate: the skipped-busy path persisted the
 * WHOLE in-memory roster every due tick with no lock of any kind, so a host
 * holding a busy row silently reverted whatever another host had added,
 * deleted or edited in between — a deleted unattended schedule came back and
 * kept firing.
 *
 * A flock excludes OVERLAPPING claims. It says nothing about a claim that
 * follows a release, which is the case that mattered, so due-ness is decided
 * twice (snapshot to find candidates, fresh read inside the claim to grant
 * them) and every whole-file write happens under a lock, re-based on the
 * freshest copy, skipped when the digest says the file already says this.
 *
 * {@see ScheduleRegistryTest::testASecondHostOnTheSamePathSkipsALockedRowWithoutTouchingTheClock()}
 * covers the overlapping case (holder inside its spawn). Nothing here re-runs
 * it: every test below lands the claim AFTER the peer's persist, which is the
 * interleaving that used to double-fire, and the last one does it with TWO
 * REAL PROCESSES and the kernel's own flock, bounded by
 * {@see RunsWallClockBoundedChildTrait}.
 */
final class ScheduleCrossHostFireTest extends TestCase
{
    use RunsWallClockBoundedChildTrait;

    /** Daily cadence: one advance moves a row a full day, so a second fire is unmistakable. */
    private const DAY = 86_400;

    /** The synthetic tick every host here agrees on — no wall clock, so no flake. */
    private const TICK = 1_700_000_000;

    private string $dir = '';

    private string $path = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/aut1_duel_' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir . '/.candy-code', 0o700, true);
        $this->path = $this->dir . '/.candy-code/schedules.json';
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            self::clearTree($this->dir);
        }
    }

    public function testAStaleHostSkipsEveryRowAPeerAlreadyFiredAndSpawnsNothing(): void
    {
        $this->seedRoster('row1', 'row2');
        $stale = $this->snapshottedHost();

        // The peer fires and releases — both rows advance and persist, and the
        // row locks are FREE again by the time the stale host reaches them.
        $peer = $this->snapshottedHost();
        $peerFires = [];
        $peer->fireDue(self::TICK, $this->collectingSpawn($peerFires));
        $this->assertSame(['row1', 'row2'], $peerFires, 'the peer legitimately owns the first fire');

        $staleFires = [];
        $results = $stale->fireDue(self::TICK, $this->collectingSpawn($staleFires));

        $this->assertSame([], $staleFires, 'a claim that follows a release must not re-run the peer');
        $this->assertSame(
            ['row1' => 'skipped-stale', 'row2' => 'skipped-stale'],
            \array_column($results, 'action', 'name'),
        );
        $this->assertSame(
            ['row1' => self::TICK + self::DAY, 'row2' => self::TICK + self::DAY],
            $this->diskClocks(),
            'the peer advanced each row exactly once',
        );
    }

    public function testABusyHostAdvancesItsOwnRowWithoutRevertingAForeignAdd(): void
    {
        $this->seedRoster('row1');
        $busy = $this->snapshottedHost();
        $busy->noteFired('row1', 'sess_live'); // the run is still in flight

        // A peer adds a row on disk while this host holds the busy marker.
        $editor = $this->snapshottedHost();
        $editor->add(new Schedule(name: 'added-later', everySeconds: 60, prompt: 'q', nextDue: self::TICK + 5));
        $editor->persist();

        $spawned = 0;
        $results = $busy->fireDue(self::TICK, static function () use (&$spawned): ?string {
            ++$spawned;

            return 'must-not-run';
        });

        $this->assertSame(0, $spawned);
        $this->assertSame('skipped-busy', $results[0]['action']);
        $roster = $this->diskRoster();
        $this->assertSame(['row1', 'added-later'], \array_column($roster, 'name'), 'the foreign add survived');
        $this->assertSame(
            self::TICK + self::DAY,
            (int) $roster[0]['nextDue'],
            "and the busy row's clock still moved",
        );
    }

    public function testABusyHostWritesNothingOnceAPeerAlreadyMovedTheClock(): void
    {
        $this->seedRoster('row1');
        $busy = $this->snapshottedHost();
        $busy->noteFired('row1', 'sess_live');

        // A peer fires row1 for real and adds a row of its own.
        $peer = $this->snapshottedHost();
        $peer->add(new Schedule(name: 'added-later', everySeconds: 60, prompt: 'q', nextDue: self::TICK + 5));
        $peer->fireDue(self::TICK, static fn (): ?string => 'sess_peer');

        // The busy host still believes row1 is due (its head snapshot predates
        // the peer's write). The advance must notice, and touch no file: the
        // old shape rewrote the whole roster on every such tick.
        $stampBefore = $this->fileStamp();
        $results = $busy->fireDue(self::TICK, static fn (): ?string => 'must-not-run');

        $this->assertSame('skipped-busy', $results[0]['action']);
        $this->assertSame($stampBefore, $this->fileStamp(), 'a clock that did not move rewrites nothing');
        $roster = $this->diskRoster();
        $this->assertSame(['row1', 'added-later'], \array_column($roster, 'name'));
        $this->assertSame(
            self::TICK + self::DAY,
            (int) $roster[0]['nextDue'],
            'the peer advance is what stays on disk',
        );
    }

    public function testARowDeletedByAPeerStaysDeletedUnderABusyHost(): void
    {
        $this->seedRoster('row1', 'row2');
        $busy = $this->snapshottedHost();
        $busy->noteFired('row1', 'sess_live');

        // The owner deletes row1 (and row2's cadence changes) on another host.
        $editor = $this->snapshottedHost();
        $editor->remove('row1');
        $editor->persist();

        $busy->fireDue(self::TICK, static fn (): ?string => 'must-not-run');

        $this->assertSame(['row2'], \array_column($this->diskRoster(), 'name'), 'a deleted schedule stays deleted');
    }

    public function testAClaimOnARowAPeerDeletedSkipsStaleAndNeverResurrectsIt(): void
    {
        $this->seedRoster('row1');
        $stale = $this->snapshottedHost();

        $editor = $this->snapshottedHost();
        $editor->remove('row1');
        $editor->persist();

        $spawned = 0;
        $results = $stale->fireDue(self::TICK, static function () use (&$spawned): ?string {
            ++$spawned;

            return 'must-not-run';
        });

        $this->assertSame(0, $spawned, 'the row the owner deleted never runs again');
        $this->assertSame('skipped-stale', $results[0]['action']);
        $this->assertSame([], $this->diskRoster(), 'and it is not written back on the way out');
    }

    /**
     * The write-amplification half of the fix, pinned on its own: `persist()`
     * is a public door a command can walk at any moment, and a write that
     * changes nothing is not free — it moves the mtime, which drags every
     * sibling host into a reload, and it re-issues the whole file for no
     * reason. The digest is what makes "no change" mean "no write".
     */
    public function testAPersistOfAnUnchangedRosterWritesNothingButARealChangeDoes(): void
    {
        $this->seedRoster('row1');
        $host = $this->snapshottedHost();
        $stamp = $this->fileStamp();

        $host->persist();
        $this->assertSame($stamp, $this->fileStamp(), 'the file already says exactly this');

        $host->setEnabled('row1', false);
        $this->assertSame($stamp, $this->fileStamp(), 'an in-memory edit is not a write to schedules.json');

        $host->persist();
        $this->assertNotSame($stamp, $this->fileStamp(), 'a real change still lands');
        $this->assertFalse((bool) $this->diskRoster()[0]['enabled']);
    }

    /**
     * The same interleaving, with two REAL processes and the kernel's flock:
     * the test host snapshots the roster, a child process (host B) fires every
     * due row and EXITS — releasing the locks it held — and only then does the
     * stale host reach `fireDue`. That is exactly the audit's failing order,
     * and the only way to answer it is the fresh read inside the claim.
     */
    public function testARealPeerProcessFiresTheRowsAStaleSnapshotHostAlsoSaw(): void
    {
        $this->seedRoster('row1', 'row2');
        $stale = $this->snapshottedHost();

        $script = $this->dir . '/peer-host.php';
        \file_put_contents($script, $this->peerHostSource());

        [$status, $lines] = $this->runWallClockBoundedChild('', \sprintf(
            '%s %s %s %d 2>&1',
            \escapeshellarg(PHP_BINARY),
            \escapeshellarg($script),
            \escapeshellarg($this->path),
            self::TICK,
        ));
        $output = \implode("\n", $lines);

        // The status FIRST: a child that never ran looks exactly like a child
        // with nothing to say (the trait's own law).
        $this->assertSame(0, $status, 'the peer host must run to completion: ' . $output);
        $this->assertStringContainsString('fired=row1,row2', $output, 'the peer took both rows');

        $staleFires = [];
        $results = $stale->fireDue(self::TICK, $this->collectingSpawn($staleFires));

        $this->assertSame([], $staleFires, 'no row runs twice across two hosts between slots');
        $this->assertSame(
            ['row1' => 'skipped-stale', 'row2' => 'skipped-stale'],
            \array_column($results, 'action', 'name'),
            'the stale host read the disk the peer wrote, inside the claim: ' . $output,
        );
        $this->assertSame(
            ['row1' => self::TICK + self::DAY, 'row2' => self::TICK + self::DAY],
            $this->diskClocks(),
            'one advance per row, from the peer',
        );
    }

    /**
     * Seed daily rows due at the shared tick, as a hand-edited
     * schedules.json would have them.
     */
    private function seedRoster(string ...$names): void
    {
        $seed = ScheduleRegistry::atPath($this->path);
        foreach ($names as $name) {
            $seed->add(new Schedule(name: $name, everySeconds: self::DAY, prompt: 'p', nextDue: self::TICK));
        }
        $seed->persist();
    }

    /** A host that has read the roster and will NOT re-read it before firing. */
    private function snapshottedHost(): ScheduleRegistry
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        return $registry;
    }

    /**
     * A spawn closure that records the names it was asked to run. Passed by
     * reference so a test can assert the spawn never happened, which is the
     * whole point — an `action` string alone could be painted by a code path
     * that spawned anyway.
     *
     * @param list<string> $fired
     */
    private function collectingSpawn(array &$fired): \Closure
    {
        return static function (Schedule $row) use (&$fired): ?string {
            $fired[] = $row->name;

            return 'sess_' . $row->name;
        };
    }

    /** @return array<string, int> row name => nextDue as the file holds it */
    private function diskClocks(): array
    {
        $clocks = [];
        foreach ($this->diskRoster() as $row) {
            $clocks[(string) $row['name']] = (int) $row['nextDue'];
        }

        return $clocks;
    }

    /** @return list<array<string, mixed>> the persisted rows, in file order */
    private function diskRoster(): array
    {
        \clearstatcache();
        $document = \json_decode((string) @\file_get_contents($this->path), true);

        return \is_array($document) ? \array_values($document['schedules'] ?? []) : [];
    }

    /**
     * The file's identity: `AtomicJsonFile` writes through a temp inode and
     * renames, so a changed inode is proof a write happened, independent of
     * the second-grained mtime the rest of the latch family has to live with.
     */
    private function fileStamp(): array
    {
        \clearstatcache();
        $stat = @\stat($this->path);

        return $stat === false ? [] : [$stat['ino'], $stat['size']];
    }

    /** The one-shot program host B runs as: load, fire every due row, report. */
    private function peerHostSource(): string
    {
        $autoload = \var_export(\dirname(__DIR__, 2) . '/vendor/autoload.php', true);

        return (string) \str_replace('__AUTOLOAD__', $autoload, <<<'PHP'
            <?php
            declare(strict_types=1);

            require __AUTOLOAD__;

            use SugarCraft\Code\Background\Schedule;
            use SugarCraft\Code\Background\ScheduleRegistry;

            $registry = ScheduleRegistry::atPath((string) $argv[1]);
            $registry->load();
            $now = (int) $argv[2];

            $fired = [];
            $results = $registry->fireDue($now, static function (Schedule $row) use (&$fired): ?string {
                $fired[] = $row->name;

                return 'peer_' . $row->name;
            });

            foreach ($results as $result) {
                echo $result['name'] . '=' . $result['action'] . "\n";
            }
            echo 'fired=' . implode(',', $fired) . "\n";
            echo "SURVIVED\n";
            PHP);
    }

    private static function clearTree(string $path): void
    {
        if (\is_dir($path) && !\is_link($path)) {
            foreach (\scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::clearTree($path . '/' . $entry);
                }
            }
            @\rmdir($path);

            return;
        }
        @\unlink($path);
    }
}
