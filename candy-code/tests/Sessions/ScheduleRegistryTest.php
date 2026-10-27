<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleRegistry;

/**
 * AUT-1's roster: the file half (0600, load refusals, reload-on-stat), the
 * clock half (fire/advance/skip), and the two-host arbitration that lets a
 * TUI and `serve` share one home. No daemon is spawned anywhere near this
 * file: {@see ScheduleRegistry::fireDue()} takes the spawn as a closure, which
 * is exactly why the clock is testable at all.
 */
final class ScheduleRegistryTest extends TestCase
{
    private string $dir;

    private string $path;

    private string $other;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/w5c_reg_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.candy-code', 0o700, true);
        $this->path = $this->dir . '/.candy-code/schedules.json';
        // A second project directory, for rows that belong to another host.
        $this->other = sys_get_temp_dir() . '/w5c_regB_' . bin2hex(random_bytes(6));
        mkdir($this->other, 0o700);
    }

    protected function tearDown(): void
    {
        self::sweep($this->dir);
        self::sweep($this->other);
    }

    public function testAPersistedFileIsPrivateAndCarriesThePlannedEnvelope(): void
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 3_600, prompt: 'run tests', nextDue: 100, root: $this->dir));
        $registry->persist();

        $this->assertSame(0o600, fileperms($this->path) & 0o777);
        $document = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame(ScheduleRegistry::VERSION, $document['version']);
        $this->assertSame('nightly', $document['schedules'][0]['name']);
        $this->assertSame(realpath($this->dir), $document['schedules'][0]['root'], 'the binding lands on disk, not just in memory');
    }

    public function testAHandWrittenRowLoadsAndAnAbsentFileIsSimplyEmpty(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'morning', 'every' => '900', 'prompt' => 'summarise overnight logs', 'root' => $this->dir],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->load();

        $row = $registry->get('morning');
        $this->assertNotNull($row);
        $this->assertSame(900, $row->everySeconds, 'a digit-string "every" is a real interval');
        $this->assertSame(realpath($this->dir), $row->root);
        $this->assertSame([], $registry->loadErrors());

        $missing = ScheduleRegistry::atPath($this->dir . '/nothing-here.json');
        $missing->load();
        $this->assertSame([], $missing->all());
        $this->assertSame([], $missing->loadErrors());
    }

    public function testABadRowIsSkippedByNameWhileItsSiblingsKeepRunning(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'good', 'every' => 60, 'prompt' => 'p', 'root' => $this->dir],
                ['name' => 'Bad Name', 'every' => 60, 'prompt' => 'p'],
                ['name' => 'also-good', 'every' => 60, 'prompt' => 'p', 'root' => $this->dir],
                'not even an object',
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $this->assertSame(['good', 'also-good'], array_map(static fn (Schedule $r): string => $r->name, $registry->all()));
        $this->assertCount(2, $registry->loadErrors());
        $this->assertStringContainsString('Bad Name', $registry->loadErrors()[0]);
    }

    /**
     * AUT-1 follow-through (plan-verify-1026b f4) as widened by C-AGT-7.a P3:
     * a row whose spelled permission mode this build does not know still LOADS
     * on the floor (the permission-floor contract), but the coercion must
     * never be silent — {@see ScheduleRegistry::load()} records a named note
     * beside the row. And a row that spells a BYPASS-CLASS mode is no longer
     * coerced at all: it is refused at the file boundary, skipped BY NAME with
     * the reason in the notes, while its siblings load and run untouched. The
     * negative legs matter just as hard: a real spelling rides unchanged
     * (ownership posture) and an absent mode is the documented default, so
     * neither may add a note.
     */
    public function testACoercedModeSpellingLoadsOnTheFloorYetIsAnnouncedByName(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'typo', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'acceptEdits', 'root' => $this->dir],
                ['name' => 'spelled-floor', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'dont-ask', 'root' => $this->dir],
                ['name' => 'spelled-bypass', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'bypass-permissions', 'root' => $this->dir],
                ['name' => 'named-real', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'plan', 'root' => $this->dir],
                ['name' => 'minimal', 'every' => 60, 'prompt' => 'p', 'root' => $this->dir],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $this->assertSame(
            ['typo', 'named-real', 'minimal'],
            array_map(static fn (Schedule $r): string => $r->name, $registry->all()),
            'the coercible row is never dropped for its bad spelling — only its mode is floored; the bypass-class rows are the ones that must not enter',
        );
        $this->assertNull($registry->get('spelled-floor'), 'a spelled floor is REFUSED, not quietly granted — the floor comes from the code, not the file');
        $this->assertNull($registry->get('spelled-bypass'));
        $this->assertSame('dont-ask', $registry->get('typo')?->permissionMode, 'the floor decides behavior, as ever');
        $this->assertSame('plan', $registry->get('named-real')?->permissionMode);

        $notes = $registry->loadErrors();
        $this->assertCount(3, $notes, 'the coercion plus the two named refusals; a real spelling and a documented default stay silent');
        $this->assertStringContainsString("'typo'", $notes[0], 'the note names the row');
        $this->assertStringContainsString('"acceptEdits"', $notes[0], 'and the spelling that was refused');
        $this->assertStringContainsString('is not one of', $notes[0], 'naming what could be spelled instead');
        $this->assertStringContainsString('loaded', $notes[0], 'the note must say the row loaded — the neutral header relies on it');

        $refusal = $notes[1] . "\n" . $notes[2];
        $this->assertStringContainsString("'spelled-floor'", $refusal, 'the refusal names the skipped row');
        $this->assertStringContainsString('is refused for schedules', $refusal, 'and says why, mirroring the webhook loader wording');
        $this->assertStringContainsString("'spelled-bypass'", $refusal, 'both halves of the bypass class refuse');

        $registry->load();
        $this->assertCount(3, $registry->loadErrors(), 'a reload re-derives the notes, never accumulates a pile');
    }

    public function testAnUnknownFileVersionRefusesToLoadRatherThanHalfTrusting(): void
    {
        file_put_contents($this->path, json_encode(['version' => 2, 'schedules' => [['name' => 'x', 'every' => 60, 'prompt' => 'p']]]));
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $this->assertSame([], $registry->all());
        $this->assertStringContainsString('version', $registry->loadErrors()[0]);
    }

    public function testReloadSeesAnotherHostsWriteWithinATickAndIgnoresUnmovedFiles(): void
    {
        $a = ScheduleRegistry::atPath($this->path);
        $a->add(new Schedule(name: 'shared', everySeconds: 60, prompt: 'first', nextDue: 10));
        $a->persist();

        $b = ScheduleRegistry::atPath($this->path);
        $b->load();
        $this->assertFalse($b->reloadIfChanged(), 'nothing moved');

        sleep(1); // the latch is mtime-grained like every stat cache
        $a->add(new Schedule(name: 'late', everySeconds: 120, prompt: 'second', nextDue: 10));
        $a->persist();

        $this->assertTrue($b->reloadIfChanged());
        $this->assertNotNull($b->get('late'));
    }

    public function testFireDueAdvancesBeforeSpawningAndRemembersTheSession(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'beat', everySeconds: 60, prompt: 'p', nextDue: time() - 1, root: $this->dir));
        $seen = null;
        $results = $registry->fireDue(time(), function (Schedule $row) use (&$seen, $registry): ?string {
            $seen = $row->name;
            // The clock had ALREADY moved and persisted when the spawn ran.
            $this->assertGreaterThan(time(), $registry->get('beat')->nextDue);

            return 'sess_fire1';
        });

        $this->assertSame('beat', $seen);
        $this->assertSame('fired', $results[0]['action']);
        $this->assertSame('sess_fire1', $registry->busySession('beat'));
        $document = json_decode((string) file_get_contents($this->path), true);
        $this->assertGreaterThan(time(), $document['schedules'][0]['nextDue'], 'the advance survived to disk');
    }

    public function testABusyRowIsSkippedButAdvancedSoItCannotStack(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'slow', everySeconds: 60, prompt: 'p', nextDue: time() - 1, root: $this->dir));
        $registry->noteFired('slow', 'sess_running');

        $spawned = 0;
        $results = $registry->fireDue(time(), function () use (&$spawned): ?string {
            ++$spawned;

            return null;
        });

        $this->assertSame(0, $spawned, 'the still-running row is never doubled');
        $this->assertSame('skipped-busy', $results[0]['action']);
        $this->assertGreaterThan(time(), $registry->get('slow')->nextDue);
        $this->assertSame('sess_running', $registry->busySession('slow'));

        $registry->clearFired('slow');
        $this->assertNull($registry->busySession('slow'));
    }

    public function testASecondHostOnTheSamePathSkipsALockedRowWithoutTouchingTheClock(): void
    {
        $holder = ScheduleRegistry::atPath($this->path, $this->dir);
        $holder->add(new Schedule(name: 'duel', everySeconds: 60, prompt: 'p', nextDue: time() - 1, root: $this->dir));
        $holder->persist(); // the challenger reads the roster FROM DISK, like a second pump does
        $challenger = ScheduleRegistry::atPath($this->path, $this->dir);
        $challenger->load();

        $outcomes = [];
        // While the holder is INSIDE its spawn it holds the row lock; fire the
        // challenger from in there — the exact race two pumps hit at :00.
        $holderResults = $holder->fireDue(time(), function () use ($challenger, $holder, &$outcomes): ?string {
            $theirs = $challenger->fireDue(time(), static fn (): ?string => 'must-not-run');
            $outcomes = [$theirs[0]['action'], count($theirs)];

            return 'sess_holder';
        });

        $this->assertSame('fired', $holderResults[0]['action']);
        $this->assertSame(['skipped-locked', 1], $outcomes, 'the loser says so once, and does not spawn');
    }

    public function testASpawnRefusalKeepsTheAdvanceSoItAnnouncesOncePerPeriod(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'broken', everySeconds: 60, prompt: 'p', nextDue: time() - 1, root: $this->dir));

        $results = $registry->fireDue(time(), static function (): ?string {
            throw new \RuntimeException('handshake went nowhere');
        });

        $this->assertSame('error', $results[0]['action']);
        $this->assertStringContainsString('handshake', $results[0]['error']);
        $this->assertGreaterThan(time(), $registry->get('broken')->nextDue);
        $this->assertSame([], $registry->fireDue(time(), static fn (): ?string => 'again'), 'no retry before the next slot');
    }

    public function testDisabledRowsAreNeverDue(): void
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add((new Schedule(name: 'off', everySeconds: 60, prompt: 'p', nextDue: time() - 1))->withEnabled(false));
        $registry->setEnabled('off', true);

        $this->assertCount(1, $registry->due(time()));
        $this->assertTrue($registry->hasEnabled());
    }

    public function testEphemeralRowsNeverTouchTheFileAndNeverLock(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'loop-1', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false));
        $registry->persist();

        $document = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame([], $document['schedules'], 'a /loop row is nobody else\'s business: the file says none');
        $spawned = 0;
        $registry->fireDue(time(), static function () use (&$spawned): ?string {
            ++$spawned;

            return 'x';
        });
        $this->assertSame(0, $spawned, 'persisted fires only — the pump owns the ephemeral half');
        $this->assertCount(1, $registry->due(time()));
    }

    public function testDueEphemeralForAnswersOneSessionsDueRowsOnly(): void
    {
        // C-AGT-1.b keying: one serve process, many tabs, one registry.
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'mine', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, sessionId: 's'));
        $registry->add(new Schedule(name: 'theirs', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, sessionId: 't'));
        $registry->add(new Schedule(name: 'unstamped', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false));
        $registry->add(new Schedule(name: 'persisted', everySeconds: 60, prompt: 'p', nextDue: 1, root: $this->dir));
        $registry->add(new Schedule(name: 'mine-later', everySeconds: 60, prompt: 'p', nextDue: 9_999_999_999, persisted: false, sessionId: 's'));
        $registry->add(new Schedule(name: 'mine-paused', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, enabled: false, sessionId: 's'));

        $due = $registry->dueEphemeralFor('s', time());
        $this->assertSame(['mine'], array_map(static fn (Schedule $row): string => $row->name, $due), 'due, enabled, MY stamp — nothing else; the file rows and the other tabs are not this call\'s answer');

        $this->assertSame([], $registry->dueEphemeralFor('nobody', time()), 'a session with no rows gets none');
        // Cadence alone still decides membership in the ungated view: the
        // key is a filter over due(), never a second clock.
        $this->assertContains('theirs', array_map(static fn (Schedule $row): string => $row->name, $registry->due(time())));
    }

    public function testRemoveEphemeralForDropsOneSessionsRowsAndAnswersCount(): void
    {
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->add(new Schedule(name: 'loop-1', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, sessionId: 's'));
        $registry->add(new Schedule(name: 'loop-2', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, sessionId: 's'));
        $registry->add(new Schedule(name: 'tab-t', everySeconds: 60, prompt: 'p', nextDue: 1, persisted: false, sessionId: 't'));
        $registry->add(new Schedule(name: 'persisted', everySeconds: 60, prompt: 'p', nextDue: 1, root: $this->dir));

        $this->assertSame(2, $registry->removeEphemeralFor('s'), 'both of the session\'s rows went');
        $this->assertNull($registry->get('loop-1'));
        $this->assertNull($registry->get('loop-2'));
        $this->assertNotNull($registry->get('tab-t'), 'another tab keeps its loop');
        $this->assertNotNull($registry->get('persisted'), 'file rows outlive sessions by design');

        $this->assertSame(0, $registry->removeEphemeralFor('s'), 'the second sweep is empty, not an error');
    }

    public function testADuplicateNameRefusesInEitherDirection(): void
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'twice', everySeconds: 60, prompt: 'p'));
        foreach ([true, false] as $persisted) {
            try {
                $registry->add(new Schedule(name: 'twice', everySeconds: 120, prompt: 'q', persisted: $persisted));
                $this->fail('a second row named twice was accepted');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('already exists', $e->getMessage());
            }
        }
    }

    public function testRemoveAnswersWhetherARowExistedAndClearsItsBusyMarker(): void
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'gone', everySeconds: 60, prompt: 'p', persisted: false));
        $registry->noteFired('gone', 'sess_x');

        $this->assertTrue($registry->remove('gone'));
        $this->assertFalse($registry->remove('gone'));
        $this->assertNull($registry->busySession('gone'));
    }

    public function testAHomelessRegistryKeepsLoopsAndRefusesPersistenceLoudly(): void
    {
        $registry = ScheduleRegistry::inMemory();
        $registry->add(new Schedule(name: 'loop-1', everySeconds: 60, prompt: 'p', persisted: false));
        $this->assertCount(1, $registry->all());

        try {
            $registry->add(new Schedule(name: 'x', everySeconds: 60, prompt: 'p'));
            $this->fail('a persisted row was accepted with nowhere to persist it');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('vouchsafe home', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $registry->persist();
    }

    public function testARowBoundToNoProjectIsAnnouncedAndFiredByNobody(): void
    {
        $tick = time();
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'orphan', 'every' => 60, 'prompt' => 'p', 'nextDue' => $tick - 60],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->load();

        $notes = $registry->loadErrors();
        $this->assertCount(1, $notes, 'the unbound row speaks up once at load');
        $this->assertStringContainsString("'orphan'", $notes[0]);
        $this->assertStringContainsString('not bound to a project', $notes[0]);
        $this->assertStringContainsString('"root"', $notes[0], 'the note names the field that fixes it');
        $this->assertStringContainsString('will not fire', $notes[0]);

        $spawned = 0;
        $results = $registry->fireDue($tick, static function () use (&$spawned): ?string {
            ++$spawned;

            return 'x';
        });

        $this->assertSame([['name' => 'orphan', 'action' => 'skipped-foreign']], $results);
        $this->assertSame(0, $spawned, 'no host on the machine claims an unbound row');
        $this->assertSame($tick - 60, $registry->get('orphan')->nextDue, 'its clock is left exactly as its author wrote it');
    }

    public function testARowBoundToAnotherProjectIsSkippedWithNeitherLockNorClockMove(): void
    {
        $tick = time();
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'elsewhere', 'every' => 60, 'prompt' => 'p', 'nextDue' => $tick - 60, 'root' => $this->other],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path, $this->dir);
        $registry->load();
        $this->assertSame([], $registry->loadErrors(), 'a row bound elsewhere is not a load problem HERE');

        $spawned = 0;
        $results = $registry->fireDue($tick, static function () use (&$spawned): ?string {
            ++$spawned;

            return 'x';
        });

        $this->assertSame([['name' => 'elsewhere', 'action' => 'skipped-foreign']], $results);
        $this->assertSame(0, $spawned);
        $this->assertFalse(is_dir($this->dir . '/.candy-code/schedules'), 'the row lock was never claimed — the foreign host still wins its own slot');
        $document = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame($tick - 60, $document['schedules'][0]['nextDue'], 'the shared file is byte-still');
    }

    public function testAHostThatCannotNameItsRootFiresNoPersistedRow(): void
    {
        $tick = time();
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'home-bound', 'every' => 60, 'prompt' => 'p', 'nextDue' => $tick - 60, 'root' => $this->dir],
            ],
        ]));
        // No host root at all: the fail-closed half of the binding gate.
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $spawned = 0;
        $results = $registry->fireDue($tick, static function () use (&$spawned): ?string {
            ++$spawned;

            return 'x';
        });

        $this->assertSame([['name' => 'home-bound', 'action' => 'skipped-foreign']], $results);
        $this->assertSame(0, $spawned, 'an unrooted pump runs nobody\'s daemon, not even its own project\'s');
    }

    private static function sweep(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::sweep($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
