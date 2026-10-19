<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Sessions\Schedule;

/**
 * AUT-1 boundary rows: the parse that makes the rest of the schedule path
 * trust its data, and the overrun-skip arithmetic that is the ONLY bound on
 * this clock (due-counts, never timeouts — the E646 law).
 */
final class ScheduleTest extends TestCase
{
    public function testAWellFormedRowSurvivesTheWireRoundTrip(): void
    {
        $row = new Schedule(name: 'nightly', everySeconds: 86_400, prompt: 'run the tests', provider: 'sglang', nextDue: 1_000);
        $back = Schedule::fromArray($row->toArray(), 500);

        foreach (['name', 'everySeconds', 'prompt', 'provider', 'permissionMode', 'nextDue', 'enabled'] as $field) {
            $this->assertSame($row->$field, $back->$field, "field {$field}");
        }
        $this->assertTrue($back->persisted, 'an on-disk row is persisted by definition');
    }

    public function testTheFileRowCarriesExactlyThePlannedSevenKeys(): void
    {
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', nextDue: 0);

        $this->assertSame(['name', 'every', 'prompt', 'provider', 'permissionMode', 'nextDue', 'enabled'], array_keys($row->toArray()));
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

    public function testAnUnknownPermissionSpellingFallsToTheDontAskFloor(): void
    {
        $this->assertSame(
            PermissionMode::DontAsk->value,
            (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', permissionMode: 'god-mode'))->permissionMode,
        );
        $this->assertSame(
            PermissionMode::BypassPermissions->value,
            (new Schedule(name: 'a', everySeconds: 60, prompt: 'p', permissionMode: PermissionMode::BypassPermissions->value))->permissionMode,
            'a mode spelled by the operator rides unchanged',
        );
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
        // NEXT slot after now, not seven days of catch-up (E646: the bound is
        // the due-count arithmetic, there is no queue and no timeout).
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
        $row = new Schedule(name: 'a', everySeconds: 60, prompt: 'p', provider: 'v', permissionMode: PermissionMode::Auto->value, nextDue: 10, enabled: false, persisted: false);
        $moved = $row->withNextDue(70)->withEnabled(true);

        foreach (['name', 'everySeconds', 'prompt', 'provider', 'permissionMode', 'persisted'] as $field) {
            $this->assertSame($row->$field, $moved->$field, "field {$field}");
        }
        $this->assertSame(70, $moved->nextDue);
        $this->assertTrue($moved->enabled);
    }
}
