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

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/w5c_reg_' . bin2hex(random_bytes(6));
        mkdir($this->dir . '/.candy-code', 0o700, true);
        $this->path = $this->dir . '/.candy-code/schedules.json';
    }

    protected function tearDown(): void
    {
        self::sweep($this->dir);
    }

    public function testAPersistedFileIsPrivateAndCarriesThePlannedEnvelope(): void
    {
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 3_600, prompt: 'run tests', nextDue: 100));
        $registry->persist();

        $this->assertSame(0o600, fileperms($this->path) & 0o777);
        $document = json_decode((string) file_get_contents($this->path), true);
        $this->assertSame(ScheduleRegistry::VERSION, $document['version']);
        $this->assertSame('nightly', $document['schedules'][0]['name']);
    }

    public function testAHandWrittenRowLoadsAndAnAbsentFileIsSimplyEmpty(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'morning', 'every' => '900', 'prompt' => 'summarise overnight logs'],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $row = $registry->get('morning');
        $this->assertNotNull($row);
        $this->assertSame(900, $row->everySeconds, 'a digit-string "every" is a real interval');
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
                ['name' => 'good', 'every' => 60, 'prompt' => 'p'],
                ['name' => 'Bad Name', 'every' => 60, 'prompt' => 'p'],
                ['name' => 'also-good', 'every' => 60, 'prompt' => 'p'],
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
     * AUT-1 follow-through (plan-verify-1026b f4): a row whose spelled
     * permission mode this build does not know still LOADS on the floor (the
     * permission-floor contract), but the coercion must never be silent —
     * {@see ScheduleRegistry::load()} records a named note beside the row.
     * The two negative legs matter just as hard: a real spelling rides
     * unchanged (ownership posture) and an absent mode is the documented
     * default, not a coercion, so neither may add a note.
     */
    public function testACoercedModeSpellingLoadsOnTheFloorYetIsAnnouncedByName(): void
    {
        file_put_contents($this->path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'typo', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'acceptEdits'],
                ['name' => 'spelled-floor', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'dont-ask'],
                ['name' => 'named-real', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'plan'],
                ['name' => 'minimal', 'every' => 60, 'prompt' => 'p'],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->load();

        $this->assertSame(
            ['typo', 'spelled-floor', 'named-real', 'minimal'],
            array_map(static fn (Schedule $r): string => $r->name, $registry->all()),
            'a coercible row is never dropped for its bad spelling — only its mode is floored',
        );
        $this->assertSame('dont-ask', $registry->get('typo')?->permissionMode, 'the floor decides behavior, as ever');
        $this->assertSame('plan', $registry->get('named-real')?->permissionMode);

        $notes = $registry->loadErrors();
        $this->assertCount(1, $notes, 'exactly the coerced spelling is announced; a real spelling and a documented default stay silent');
        $this->assertStringContainsString("'typo'", $notes[0], 'the note names the row');
        $this->assertStringContainsString('"acceptEdits"', $notes[0], 'and the spelling that was refused');
        $this->assertStringContainsString('is not one of', $notes[0], 'naming what could be spelled instead');
        $this->assertStringContainsString('loaded', $notes[0], 'the note must say the row loaded — the neutral header relies on it');

        $registry->load();
        $this->assertCount(1, $registry->loadErrors(), 'a reload re-derives the notes, never accumulates a pile');
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
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'beat', everySeconds: 60, prompt: 'p', nextDue: time() - 1));
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
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'slow', everySeconds: 60, prompt: 'p', nextDue: time() - 1));
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
        $holder = ScheduleRegistry::atPath($this->path);
        $holder->add(new Schedule(name: 'duel', everySeconds: 60, prompt: 'p', nextDue: time() - 1));
        $holder->persist(); // the challenger reads the roster FROM DISK, like a second host does
        $challenger = ScheduleRegistry::atPath($this->path);
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
        $registry = ScheduleRegistry::atPath($this->path);
        $registry->add(new Schedule(name: 'broken', everySeconds: 60, prompt: 'p', nextDue: time() - 1));

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
        $registry = ScheduleRegistry::atPath($this->path);
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
