<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Background;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Permissions\PermissionMode;

/**
 * C-AGT-5.a — the creation-policy factory: mode ceiling, model-row cap, root
 * binding, and the row the tool persists (and later `/schedules add` will
 * reuse). The registry is always {@see ScheduleRegistry::atPath()} over a
 * temp file bound to the test project — no HOME needed at this layer; the
 * HOME-backed seam is pinned beside the tool itself.
 */
final class ScheduleFactoryTest extends TestCase
{
    private string $dir;

    private string $root;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cc-sched-factory-' . bin2hex(random_bytes(5));
        mkdir($this->dir . '/project', 0700, true);
        $this->root = (string) realpath($this->dir . '/project');
    }

    protected function tearDown(): void
    {
        // Deterministic, fixed-path cleanup: no walker call sites in a test
        // file (TreeWideGuardRoster) and no glob-shaped string literals in
        // this corpus (GlobDialectDifferential).
        @unlink($this->dir . '/schedules.json');
        @unlink($this->dir . '/schedules/.file.lock');
        @rmdir($this->dir . '/schedules');
        @rmdir($this->dir . '/project');
        @rmdir($this->dir);
    }

    private function registry(): ScheduleRegistry
    {
        return ScheduleRegistry::atPath($this->dir . '/schedules.json', $this->root);
    }

    /**
     * @param array<array-key, mixed> $args
     */
    private function create(array $args, PermissionMode $session = PermissionMode::Default, int $now = 1_700_000_000): Schedule
    {
        return ScheduleFactory::create(
            $args + ['name' => 'nightly-sweep', 'every' => 86_400, 'prompt' => 'Run the suite and summarise.'],
            $this->root,
            $session,
            $this->registry(),
            $now,
        );
    }

    public function testACreatedRowIsRootBoundModelTaggedPersistedAndArmedOneIntervalOut(): void
    {
        $row = $this->create(['name' => 'nightly-sweep', 'every' => 3600, 'prompt' => 'Summarise the open issues.'], now: 1000);

        self::assertTrue($row->persisted);
        self::assertSame($this->root, $row->root, 'the create binds the host root — no row arrives unbound');
        self::assertSame(Schedule::CREATED_BY_MODEL, $row->createdBy);
        self::assertSame(1000 + 3600, $row->nextDue, 'first fire one interval out, never immediate');
        self::assertSame(PermissionMode::DontAsk->value, $row->permissionMode, 'omitted mode lands on the dont-ask floor');
    }

    public function testTheCreatedRowRoundTripsTheFileThroughTheReadPathAndPoisonsNothing(): void
    {
        $this->create(['name' => 'issue-triage', 'every' => 7200, 'prompt' => 'Triage the issue list.'], now: 500);

        $fresh = $this->registry();
        $fresh->load();
        $row = $fresh->get('issue-triage');

        self::assertNotNull($row, 'the persisted file re-reads — hosts reload by digest, this is what they reload');
        self::assertSame(Schedule::CREATED_BY_MODEL, $row->createdBy);
        self::assertSame($this->root, $row->root);
        self::assertSame(500 + 7200, $row->nextDue);
        // The poison pin: the floor serialises as null, so the next load never
        // sees a bypass-class SPELLING this build wrote (C-AGT-7.a's own
        // promise, now load-bearing for a machine-authored row too).
        $bytes = (string) file_get_contents($this->dir . '/schedules.json');
        self::assertStringNotContainsString(PermissionMode::DontAsk->value, $bytes);
        self::assertStringNotContainsString(PermissionMode::BypassPermissions->value, $bytes);
    }

    public function testTheBypassClassSpellingsAreRefusedByNameAndNothingIsWritten(): void
    {
        foreach ([PermissionMode::BypassPermissions->value, PermissionMode::DontAsk->value] as $spelling) {
            try {
                $this->create(['name' => 'ceiling-' . substr(md5($spelling), 0, 8), 'permission_mode' => $spelling]);
                self::fail('a bypass-class spelling slipped the ceiling: ' . $spelling);
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString($spelling, $e->getMessage());
                self::assertStringContainsString('unattended', $e->getMessage());
            }
        }
        self::assertSame([], $this->registry()->all(), 'a refused create wrote no row');
    }

    public function testAnUnknownModeSpellingIsRefusedAtCreateNamingTheAllowedSpellings(): void
    {
        // The deliberate split from the file-read path: a hand-edited row with
        // a typo lands on the floor LOUDLY (a load note); a model asked to
        // name a mode and naming nonsense is answered at the call, not
        // silently coerced into a grant it never spelled.
        try {
            $this->create(['name' => 'nonsense', 'permission_mode' => 'yes-please']);
            self::fail('the unknown spelling fell through the ceiling');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('yes-please', $e->getMessage());
            self::assertStringContainsString(PermissionMode::AcceptEdits->value, $e->getMessage());
        }
    }

    public function testTheSessionModeIsTheCeilingLooserRequestsLandOnIt(): void
    {
        // Session runs `plan` (rank 4); `accept-edits` (2) and `default` (3)
        // are both looser asks and neither may widen past the session.
        $asked = $this->create(['name' => 'capped-a', 'permission_mode' => PermissionMode::AcceptEdits->value], session: PermissionMode::Plan);
        self::assertSame(PermissionMode::Plan->value, $asked->permissionMode);

        $under = $this->create(['name' => 'capped-b', 'permission_mode' => PermissionMode::Default->value], session: PermissionMode::Plan);
        self::assertSame(PermissionMode::Plan->value, $under->permissionMode);
    }

    public function testAStricterRequestRidesUnchanged(): void
    {
        // Session `default`; the model asks `plan` — stricter is the model's
        // own good will and it rides.
        $row = $this->create(['name' => 'narrower', 'permission_mode' => PermissionMode::Plan->value]);
        self::assertSame(PermissionMode::Plan->value, $row->permissionMode);
    }

    public function testTheCapCountsOnlyModelRowsOfThisRootAndTheNinthCreateRefuses(): void
    {
        $registry = $this->registry();
        // An operator hand-row and a model row bound elsewhere: neither may
        // eat this root's model quota — one is not the model's to count, the
        // other is not this project's.
        $registry->add(new Schedule(name: 'operator-hand', everySeconds: 600, prompt: 'theirs', persisted: true, root: $this->root));
        $registry->add(new Schedule(name: 'elsewhere', everySeconds: 600, prompt: 'theirs', persisted: true, root: \dirname($this->root), createdBy: Schedule::CREATED_BY_MODEL));
        $registry->persist();

        for ($i = 0; $i < ScheduleFactory::MODEL_ROW_CAP; $i++) {
            $this->create(['name' => 'quota-' . $i]);
        }

        $after = $this->registry();
        $after->load();
        self::assertSame(ScheduleFactory::MODEL_ROW_CAP, ScheduleFactory::modelRowCount($after, $this->root));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already carries ' . ScheduleFactory::MODEL_ROW_CAP . ' model-created schedules/');
        $this->create(['name' => 'quota-final']);
    }

    public function testTheCapReloadsTheFileBeforeCounting(): void
    {
        // Two hosts, one file: a peer wrote the project's eight model rows
        // after this registry loaded. The cap counts the FILE, so the create
        // that walks past a stale roster is refused — the reloadIfChanged
        // inside create() is what this pin exists for.
        $peer = $this->registry();
        for ($i = 0; $i < ScheduleFactory::MODEL_ROW_CAP; $i++) {
            $peer->add(new Schedule(name: 'peer-' . $i, everySeconds: 600, prompt: 'x', persisted: true, root: $this->root, createdBy: Schedule::CREATED_BY_MODEL));
        }
        $peer->persist();

        $stale = $this->registry();
        $stale->load(); // saw an empty file... the rows landed after

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already carries/');
        ScheduleFactory::create(
            ['name' => 'late-one', 'every' => 600, 'prompt' => 'x'],
            $this->root,
            PermissionMode::Default,
            $stale,
            1000,
        );
    }

    public function testARecreatedNameRefusesThroughTheRegistry(): void
    {
        $this->create(['name' => 'once']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already exists');
        $this->create(['name' => 'once']);
    }

    public function testMissingRootIsRefusedRatherThanWritingADeadRow(): void
    {
        // C-AGT-1 binding: an unbound persisted row fires NOWHERE, forever. A
        // model told "created" about one would have been lied to, so the
        // factory refuses rather than persisting a false success.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/no project directory/');
        ScheduleFactory::create(
            ['name' => 'homeless', 'every' => 600, 'prompt' => 'x'],
            null,
            PermissionMode::Default,
            $this->registry(),
            1000,
        );
    }

    public function testFieldShapeRefusalsAreNamed(): void
    {
        foreach ([
            'every non-numeric' => ['every' => '30 minutes'],
            'empty prompt' => ['prompt' => '   '],
            'non-string provider' => ['provider' => 7],
            'non-string name' => ['name' => 42],
            'non-integer timeout' => ['timeout_seconds' => 4.5],
        ] as $label => $args) {
            try {
                $this->create($args);
                self::fail('the factory accepted a malformed create: ' . $label);
            } catch (\InvalidArgumentException $e) {
                self::assertNotSame('', $e->getMessage(), 'a refusal that names nothing (' . $label . ') is not an error the caller can repair');
            }
        }
    }

    public function testTheTagCarriesThroughTheWitherRoundTrip(): void
    {
        // Schedule::mutate() rebuilds via array_merge of get_object_vars —
        // the new createdBy property must ride a wither the same way root
        // does, or `/schedules disable` would silently launder a model row
        // into an untagged one (and a laundered row the tool can no longer
        // delete without the opt-in — noise, but noise on the wrong side of
        // the boundary).
        $row = new Schedule(name: 'rides', everySeconds: 600, prompt: 'x', root: $this->root, createdBy: Schedule::CREATED_BY_MODEL);
        self::assertSame(Schedule::CREATED_BY_MODEL, $row->withEnabled(false)->createdBy);
        self::assertSame(Schedule::CREATED_BY_MODEL, $row->withNextDue(1)->createdBy);
    }

    public function testTheFileRefusesAForgedCreationTag(): void
    {
        // The tag gates the tool's delete privilege, so fromArray reads it
        // strictly: `model` or absence. A forged spelling is named and
        // refused at the boundary — precisely the row this field exists to
        // police, arriving from a file this build does not vouch for.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('createdBy');
        Schedule::fromArray([
            'name' => 'forged',
            'every' => 600,
            'prompt' => 'x',
            'root' => $this->root,
            'createdBy' => 'operator',
        ], 1000);
    }

    public function testSettleModeAnswersWithoutARegistry(): void
    {
        // The pure ceiling beside the create path, so a future surface
        // reusing it cannot lose create() and escape the bound with it.
        self::assertNull(ScheduleFactory::settleMode(null, PermissionMode::Auto));
        self::assertNull(ScheduleFactory::settleMode('   ', PermissionMode::Auto));
        self::assertSame(
            PermissionMode::AcceptEdits->value,
            ScheduleFactory::settleMode(PermissionMode::AcceptEdits->value, PermissionMode::Auto),
            'a stricter-than-session request rides',
        );
        self::assertSame(
            PermissionMode::Plan->value,
            ScheduleFactory::settleMode(PermissionMode::Auto->value, PermissionMode::Plan),
            'a looser request lands on the session',
        );
    }
}
