<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Background\Schedule;

/**
 * AUT-1 boundary rows: the parse that makes the rest of the schedule path
 * trust its data, the overrun-skip arithmetic that bounds the FIRE counts on
 * this clock (due-counts on the firing path — the E646 law; the daemon a fire
 * spawns carries the ordinary session run-bound, C-AGT-7.a P4), and the load
 * ceiling that refuses bypass-class spellings (C-AGT-7.a P3).
 */
final class ScheduleTest extends TestCase
{
    public function testAWellFormedRowSurvivesTheWireRoundTrip(): void
    {
        $root = sys_get_temp_dir();
        $row = new Schedule(name: 'nightly', everySeconds: 86_400, prompt: 'run the tests', provider: 'sglang', nextDue: 1_000, root: $root);
        $back = Schedule::fromArray($row->toArray(), 500);

        foreach (['name', 'everySeconds', 'prompt', 'provider', 'permissionMode', 'nextDue', 'enabled', 'root', 'timeoutSeconds', 'kind'] as $field) {
            $this->assertSame($row->$field, $back->$field, "field {$field}");
        }
        $this->assertTrue($back->persisted, 'an on-disk row is persisted by definition');
        $this->assertNull($row->timeoutSeconds, 'a row that names no bound round-trips as absent');
    }

    public function testTheFileRowCarriesExactlyThePlannedElevenKeys(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', nextDue: 0);

        $this->assertSame(['name', 'every', 'prompt', 'provider', 'permissionMode', 'nextDue', 'enabled', 'root', 'timeoutSeconds', 'createdBy', 'kind'], array_keys($row->toArray()));
    }

    /**
     * C-AGT-7.a P3, the self-round-trip leg: the floor serialized as null is
     * exactly what absence means, so a file this build writes re-loads on the
     * floor — no note, no refusal. The refusal would fire if toArray spelled
     * `dont-ask` back out, which is why the arm exists.
     */
    public function testTheFloorRoundTripsThroughTheFileAsAnAbsentMode(): void
    {
        $row = new Schedule(name: 'floored', everySeconds: 60, prompt: 'p');
        $wire = $row->toArray();
        $this->assertNull($wire['permissionMode'], 'the settled floor is never re-spelled into the file');

        $back = Schedule::fromArray($wire, 500);
        $this->assertSame(PermissionMode::DontAsk->value, $back->permissionMode);

        // And a NON-floor mode keeps riding spelled, both directions.
        $spelled = new Schedule(name: 'edits', everySeconds: 60, prompt: 'p', permissionMode: PermissionMode::AcceptEdits->value);
        $this->assertSame(PermissionMode::AcceptEdits->value, $spelled->toArray()['permissionMode']);
        $this->assertSame(PermissionMode::AcceptEdits->value, Schedule::fromArray($spelled->toArray(), 500)->permissionMode);
    }

    /** @return list<array{0: string}> */
    public static function badNameProvider(): array
    {
        return [
            [''],
            ['-lead'],
            ['../../etc/passwd'],
            ['has space'],
            ['UPPER'],
            ['colon:time'],
            ['a/' . 'x'],
            ['root/slash/path'],
            [str_repeat('n', 66)],
        ];
    }

    /** @dataProvider badNameProvider */
    public function testANameThatCouldEscapeTheLockPathIsRefused(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lock-file');

        new Schedule(name: $name, everySeconds: 60, prompt: 'p');
    }

    public function testCadenceOutsideTheBoundsIsRefusedNamingThem(): void
    {
        foreach ([9, Schedule::MAX_EVERY_SECONDS + 1] as $bad) {
            try {
                new Schedule(name: 'a', everySeconds: $bad, prompt: 'p');
                $this->fail("{$bad} was accepted");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('repeat every', $e->getMessage());
            }
        }
    }

    public function testAnEmptyPromptIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Schedule(name: 'a', everySeconds: 60, prompt: "  \n ");
    }

    public function testARootIsStoredResolvedSoSpellingsConverge(): void
    {
        $base = sys_get_temp_dir() . '/w5c_root_' . bin2hex(random_bytes(6));
        mkdir($base, 0o700);
        $link = $base . '-link';
        symlink($base, $link);

        try {
            $viaLink = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', root: $link);
            $viaDot = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', root: $base . '/./');

            $this->assertSame(realpath($base), $viaLink->root, 'a symlink spells the same binding');
            $this->assertSame($viaLink->root, $viaDot->root, 'a `dir/./` spelling spells the same binding');
        } finally {
            @unlink($link);
            @rmdir($base);
        }
    }

    public function testARootThatDoesNotExistIsRefusedByName(): void
    {
        $ghost = sys_get_temp_dir() . '/w5c_ghost_' . bin2hex(random_bytes(6));

        try {
            new Schedule(name: 'a', everySeconds: 60, prompt: 'p', root: $ghost);
            $this->fail('a missing root directory was accepted');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('"root"', $e->getMessage());
            $this->assertStringContainsString('does not exist', $e->getMessage());
            $this->assertStringContainsString($ghost, $e->getMessage());
        }
    }

    public function testAnAbsentRootLoadsAsNullBinding(): void
    {
        $row = Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p'], now: 0);

        $this->assertNull($row->root);
        $this->assertNull($row->toArray()['root'], 'the wire keeps the absence, it does not invent one');
    }

    public function testAnUnknownPermissionSpellingFallsToTheDontAskFloor(): void
    {
        $this->assertSame(
            PermissionMode::DontAsk->value,
            (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', permissionMode: 'god-mode'))->permissionMode,
        );
        $this->assertSame(
            PermissionMode::BypassPermissions->value,
            (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', permissionMode: PermissionMode::BypassPermissions->value))->permissionMode,
            'the CONSTRUCTOR stays permissive on purpose — its bypass rides are in-process grants; the file boundary refuses, see testASpelledBypassClassModeIsRefusedAtTheFileBoundary',
        );
    }

    /**
     * C-AGT-7.a P3: the ceiling lives at fromArray, the trust boundary. Both
     * bypass-class spellings (the ServerConfig pair the webhook loader uses)
     * refuse the row BY NAME; null and absence stay the floor's silent door,
     * and an unknown spelling is still the constructor's business (floor +
     * load-time announcement), never a refusal.
     */
    public function testASpelledBypassClassModeIsRefusedAtTheFileBoundary(): void
    {
        foreach ([PermissionMode::DontAsk->value, PermissionMode::BypassPermissions->value] as $spelled) {
            try {
                Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p', 'permissionMode' => $spelled], 0);
                $this->fail('a spelled bypass-class mode was accepted: ' . $spelled);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('is refused for schedules', $e->getMessage());
                $this->assertStringContainsString($spelled, $e->getMessage());
            }
        }

        $this->assertSame(
            PermissionMode::DontAsk->value,
            Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p', 'permissionMode' => null], 0)->permissionMode,
            'an explicit null names no mode — the floor, silently, as absence has always meant',
        );
        $this->assertSame(
            PermissionMode::DontAsk->value,
            Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p', 'permissionMode' => 'god-mode'], 0)->permissionMode,
            'the unknown spelling stays a coercion, not a refusal — load() announces it',
        );
    }

    /**
     * C-AGT-7.a P4: the optional per-row run bound. Absent means null the
     * whole way to the spawner (the 3600 default is spawnSession's to own);
     * a named bound round-trips, digit-strings parse like every other int on
     * this row, and the bounds are whole minutes up to a year.
     */
    public function testTimeoutSecondsRoundTripsWithAnAbsentKeyMeaningTheSessionDefault(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', timeoutSeconds: 7_200);
        $wire = $row->toArray();
        $this->assertSame(7_200, $wire['timeoutSeconds']);

        $back = Schedule::fromArray($wire, 0);
        $this->assertSame(7_200, $back->timeoutSeconds);

        $this->assertSame(
            7_200,
            Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p', 'timeoutSeconds' => '7200'], 0)->timeoutSeconds,
            'a digit-string parses exactly like nextDue does',
        );
        $this->assertNull(
            Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p'], 0)->timeoutSeconds,
            'absence is the session default, not zero and not a mirrored 3600',
        );
    }

    public function testATimeoutOutsideWholeMinutesUpToAYearIsRefusedByName(): void
    {
        foreach ([59, 0, -1, Schedule::MAX_TIMEOUT_SECONDS + 1] as $bad) {
            try {
                new Schedule(name: 'a', everySeconds: 60, prompt: 'p', timeoutSeconds: $bad);
                $this->fail('accepted timeout: ' . $bad);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('"timeoutSeconds"', $e->getMessage());
            }
        }

        // Both edges are legal; only the shape decides refusal.
        $this->assertSame(Schedule::MIN_TIMEOUT_SECONDS, (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', timeoutSeconds: Schedule::MIN_TIMEOUT_SECONDS))->timeoutSeconds);
        $this->assertSame(Schedule::MAX_TIMEOUT_SECONDS, (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', timeoutSeconds: Schedule::MAX_TIMEOUT_SECONDS))->timeoutSeconds);
    }

    public function testFromArrayArmsAnUnstampedRowOneIntervalOutNotImmediately(): void
    {
        $row = Schedule::fromArray(['name' => 'a', 'every' => 300, 'prompt' => 'p'], now: 1_000);

        $this->assertSame(1_300, $row->nextDue);
        $this->assertFalse($row->isDue(1_299));
        $this->assertTrue($row->isDue(1_300));
    }

    public function testFromArrayRefusesEachMalformedFieldByName(): void
    {
        $cases = [
            [['every' => 60, 'prompt' => 'p'], '"name"'],
            [['name' => 'a', 'prompt' => 'p'], '"every"'],
            [['name' => 'a', 'every' => 60], '"prompt"'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'provider' => 3], 'provider'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'permissionMode' => true], 'permissionMode'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'enabled' => 'yes'], 'enabled'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'nextDue' => 'soon'], 'nextDue'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'root' => 3], '"root"'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'timeoutSeconds' => 'fast'], 'timeoutSeconds'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'kind' => 3], '"kind"'],
            [['name' => 'a', 'every' => 60, 'prompt' => 'p', 'kind' => 'hearthbeat'], '"kind"'],
        ];
        foreach ($cases as [$row, $needle]) {
            try {
                Schedule::fromArray($row, 0);
                $this->fail('accepted: ' . json_encode($row));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage());
            }
        }
    }

    public function testOverrunSkipFiresOnceAndReArmsForTheNextLiveSlot(): void
    {
        // A 10-minute row whose last slot was a week ago: next fire is the
        // NEXT slot after now, not seven days of catch-up (the bound on the
        // FIRING path is the due-count arithmetic — no queue, no timer there;
        // the daemon each fire spawns carries its own run-bound, C-AGT-7.a P4).
        $every = 600;
        $last = 1_000_000;
        $now = $last + 7 * 86_400 + 1;
        $row = new Schedule(name: 'a', everySeconds: $every, prompt: 'p', nextDue: $last);

        $next = $row->nextDueAfter($now);

        $this->assertGreaterThan($now, $next);
        $this->assertLessThanOrEqual($now + $every, $next);
        $this->assertSame(($next - $last) % $every, 0, 'the slot lattice never shifts');
    }

    public function testNextDueAfterIsIdentityWhenTheRowIsNotYetDue(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', nextDue: 500);

        $this->assertSame(500, $row->nextDueAfter(499));
    }

    public function testTheExactBoundarySlotIsDueAndSkipsForwardOnce(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', nextDue: 500);

        $this->assertTrue($row->isDue(500));
        $this->assertSame(560, $row->nextDueAfter(500));
    }

    public function testADisabledRowIsNeverDue(): void
    {
        $row = (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', nextDue: 0))->withEnabled(false);

        $this->assertFalse($row->isDue(PHP_INT_MAX));
    }

    public function testWithersCarryEveryFieldForwardIncludingTheEphemeralFlag(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', provider: 'v', permissionMode: PermissionMode::Auto->value, nextDue: 10, enabled: false, persisted: false, root: sys_get_temp_dir());
        $moved = $row->withNextDue(70)->withEnabled(true);

        foreach (['name', 'everySeconds', 'prompt', 'provider', 'permissionMode', 'persisted', 'root', 'kind'] as $field) {
            $this->assertSame($row->$field, $moved->$field, "field {$field}");
        }
        $this->assertSame(70, $moved->nextDue);
        $this->assertTrue($moved->enabled);
    }

    /**
     * C-AGT-5.c, the kind at the file boundary: absence is the legacy shape
     * (every pre-kind row is a task row), heartbeat round-trips spelled, and
     * a misspelling is refused by NAME rather than coerced — a kind that
     * quietly changed would either delete a heartbeat's alerts or ring on
     * every unrelated task answer.
     */
    public function testTheKindIsTaskWhenAbsentAndRoundTripsWhenHeartbeat(): void
    {
        $legacy = Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p'], 0);
        $this->assertSame(Schedule::KIND_TASK, $legacy->kind);
        $this->assertSame(Schedule::KIND_TASK, $legacy->toArray()['kind'], 'a row this build writes spells its kind');

        $heart = (new Schedule(name: 'watch', everySeconds: 60, prompt: 'p'))->withKind(Schedule::KIND_HEARTBEAT);
        $back = Schedule::fromArray($heart->toArray(), 0);
        $this->assertSame(Schedule::KIND_HEARTBEAT, $back->kind);
        $this->assertSame(Schedule::KIND_HEARTBEAT, $back->withEnabled(false)->kind, 'the kind survives every other mutate too');
    }

    public function testAMisspelledKindIsRefusedByNameAtTheConstructorAndTheFile(): void
    {
        try {
            new Schedule(name: 'a', everySeconds: 60, prompt: 'p', kind: 'heart-brat');
            $this->fail('the constructor accepted an off-roster kind');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('heart-brat', $e->getMessage());
        }
        try {
            Schedule::fromArray(['name' => 'a', 'every' => 60, 'prompt' => 'p', 'kind' => 'HEARTBEAT'], 0);
            $this->fail('fromArray normalised a misspelled kind');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('HEARTBEAT', $e->getMessage());
        }
    }
}
