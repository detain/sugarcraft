<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\CommandContext;
use SugarCraft\Code\Host\Commands\CommandResult;
use SugarCraft\Code\Host\Commands\LoopCommand;
use SugarCraft\Code\Host\Commands\SchedulesCommand;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Message;
use SugarCraft\Code\Background\Schedule;
use SugarCraft\Code\Background\ScheduleFactory;
use SugarCraft\Code\Background\ScheduleRegistry;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionPolicy;

/**
 * AUT-1 command surface: `/loop` registers an in-process ephemeral row (never
 * touches the file), `/schedules` lists and mutates persisted rows (list is
 * the read-only-safe verb, mutating verbs are refused in read-only windows —
 * pinned in Chat's dispatch, judged here via {@see SchedulesCommand::isReadOnlyVerb()}).
 */
final class LoopSchedulesCommandTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/sc_loop_cmd_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0o700, true);
    }

    protected function tearDown(): void
    {
        self::remove($this->root);
    }

    // =====================================================================
    // /loop
    // =====================================================================

    public function testLoopWithoutArgumentOrWithABadIntervalRefuses(): void
    {
        $registry = ScheduleRegistry::inMemory();
        foreach (['/loop' => 'Usage', '/loop onlywords' => 'Usage', '/loop 15x go' => 'Not an interval'] as $text => $needle) {
            $result = (new LoopCommand())->run($this->context($registry), $text);
            self::assertStringContainsString($needle, self::lastRow($result), $text);
        }
        self::assertSame([], $registry->all(), 'refusals registered nothing');
    }

    public function testLoopRegistersAnEphemeralRowOneIntervalOut(): void
    {
        $registry = ScheduleRegistry::inMemory();
        $before = time();
        $result = (new LoopCommand())->run($this->context($registry), '/loop 15m write tests');

        self::assertStringContainsString('loop-1', self::lastRow($result));
        $row = $registry->get('loop-1');
        self::assertNotNull($row);
        self::assertFalse($row->persisted, 'a /loop row is in-process only');
        self::assertSame(900, $row->everySeconds);
        self::assertSame('write tests', $row->prompt);
        self::assertGreaterThanOrEqual($before + 900, $row->nextDue);
        self::assertLessThanOrEqual(time() + 900, $row->nextDue, 'first fire is ONE interval out, not immediate');
    }

    public function testLoopNamespacesIncrementAndTheCadenceFloorRefuses(): void
    {
        $registry = ScheduleRegistry::inMemory();
        (new LoopCommand())->run($this->context($registry), '/loop 60 p1');
        (new LoopCommand())->run($this->context($registry), '/loop 1d p2');

        self::assertNotNull($registry->get('loop-2'), 'the second loop takes the next name');
        self::assertSame(86400, $registry->get('loop-2')?->everySeconds);

        $result = (new LoopCommand())->run($this->context($registry), '/loop 5s too fast');
        self::assertStringContainsString('every', self::lastRow($result), 'the floor refusal names the bound');
        self::assertNull($registry->get('loop-3'), 'a refused loop registers nothing');
    }

    public function testLoopWithoutARegistrySaysSo(): void
    {
        $result = (new LoopCommand())->run($this->context(null), '/loop 15m go');
        self::assertStringContainsString('not configured', self::lastRow($result));
    }

    // =====================================================================
    // /schedules
    // =====================================================================

    public function testListingOfNothingAndOfBothKinds(): void
    {
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);
        self::assertStringContainsString('No schedules', self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules')));

        $registry->add(new Schedule(name: 'nightly', everySeconds: 3600, prompt: 'run notes', nextDue: time() + 3600, root: $this->root));
        $registry->persist();
        (new LoopCommand())->run($this->context($registry), '/loop 15m watch the tests');

        $listing = self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules list'));
        self::assertStringContainsString('nightly', $listing);
        self::assertStringContainsString('(file)', $listing);
        self::assertStringContainsString('loop-1', $listing);
        self::assertStringContainsString('(loop)', $listing);
        self::assertStringContainsString('every 15m', $listing, 'the label ladder reads in human units');
        // C-AGT-1: each row spells WHERE it fires — a bound row names its
        // project, a /loop row (which dies with this session) says so.
        self::assertStringContainsString(' @ ' . realpath($this->root) . ':', $listing, 'the bound row lists its root');
        self::assertStringContainsString('unbound', $listing, 'a row with no binding says unbound, not nothing');
    }

    public function testUnknownVerbIsUsageAndUnknownNameIsLoud(): void
    {
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json');
        self::assertStringContainsString('Usage', self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules frobnicate x')));
        self::assertStringContainsString('no schedule', strtolower(self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules delete ghost'))));
        self::assertStringContainsString('no schedule', strtolower(self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules enable ghost'))));
    }

    public function testEnableDisableDeleteRoundTripThroughTheFile(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path);
        $registry->add(new Schedule(name: 'nightly', everySeconds: 3600, prompt: 'run notes', nextDue: time() + 3600));
        $registry->persist();

        self::assertStringContainsString('disabled', self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules disable nightly')));
        self::assertFalse(self::document($path)['schedules'][0]['enabled']);

        // A second handle sees it (the file is the shared truth).
        $other = ScheduleRegistry::atPath($path);
        $other->reloadIfChanged();
        self::assertNotNull($other->get('nightly'));
        self::assertFalse($other->get('nightly')?->enabled, 'the file is the shared truth');

        self::assertStringContainsString('enabled', self::lastRow((new SchedulesCommand())->run($this->context($other), '/schedules enable nightly')));
        self::assertTrue(self::document($path)['schedules'][0]['enabled']);

        self::assertStringContainsString('deleted', strtolower(self::lastRow((new SchedulesCommand())->run($this->context($other), '/schedules delete nightly'))));
        self::assertSame([], self::document($path)['schedules']);
    }

    public function testDeletingALoopRowNeedsNoFileAndTouchesNone(): void
    {
        $registry = ScheduleRegistry::inMemory();
        (new LoopCommand())->run($this->context($registry), '/loop 60 tick');
        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules delete loop-1');

        self::assertStringContainsString('loop-1', self::lastRow($result));
        self::assertNull($registry->get('loop-1'));
    }

    public function testListingSurfacesBadRowsByName(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path);
        $registry->add(new Schedule(name: 'good', everySeconds: 60, prompt: 'p', nextDue: time() + 60));
        $registry->persist();
        // Wreck it behind the registry's back, the way a hand edit would.
        $broken = self::document($path);
        $broken['schedules'][] = ['name' => 'no-such-field'];
        $broken['version'] = 99;
        file_put_contents($path, json_encode($broken, JSON_PRETTY_PRINT));

        // Version refusal drops the WHOLE document; the load error is what the
        // listing must show.
        $fresh = ScheduleRegistry::atPath($path);
        self::assertTrue($fresh->reloadIfChanged());
        $listing = self::lastRow((new SchedulesCommand())->run($this->context($fresh), '/schedules'));
        self::assertStringContainsString('Load notes from', $listing, 'the refused document is announced');
        self::assertStringContainsString('version 99', $listing, 'by name, not silently');
    }

    public function testListingSurfacesACoercedModeBesideTheRowThatLoaded(): void
    {
        $path = $this->root . '/schedules.json';
        file_put_contents($path, json_encode([
            'version' => 1,
            'schedules' => [
                ['name' => 'typo', 'every' => 60, 'prompt' => 'check build', 'permissionMode' => 'approve'],
            ],
        ]));
        $registry = ScheduleRegistry::atPath($path);
        $registry->load();

        $listing = self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules'));
        self::assertStringContainsString('typo — every', $listing, 'the row LISTS (it loaded on the floor) — announce, never drop');
        self::assertStringContainsString('"approve"', $listing, 'the refused spelling is named on the human surface');
        self::assertStringContainsString('is not one of', $listing);
        self::assertStringContainsString('Load notes from', $listing, 'notes cover refusals and coercions alike');
    }

    public function testReadOnlyJudgementIsVerbShaped(): void
    {
        self::assertTrue(SchedulesCommand::isReadOnlyVerb('list'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('enable'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('disable'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('delete'));
        // C-AGT-5.b: adding is MUTATING — the read-only door refuses it, and
        // READ_VERBS deliberately stays ['list'] (the brief's law).
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('add'));
        // Bare `/schedules` IS the list verb, so the empty verb is safe.
        self::assertTrue(SchedulesCommand::isReadOnlyVerb('list'));
    }

    // =====================================================================
    // Fixtures
    // =====================================================================

    private function context(?ScheduleRegistry $registry): CommandContext
    {
        return CommandContext::new(root: $this->root, scheduleRegistry: $registry);
    }

    private static function lastRow(CommandResult $result): string
    {
        $last = $result->rows[\count($result->rows) - 1];

        return $last instanceof Message ? $last->content : (string) $last;
    }

    /** @return array{version:int,schedules:list<array<string,mixed>>} */
    private static function document(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array{version:int,schedules:list<array<string,mixed>>} $decoded */
        return $decoded;
    }

    // =====================================================================
    // C-AGT-5.b — /schedules add, the factory's second mouth
    // =====================================================================

    public function testAddGoesThroughTheFactoryAndStampsTheHostRoot(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);
        $before = time();

        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules add nightly 15m Do the thing');

        self::assertSame([], $result->effects, 'adding is a reply, not a client-side pump arm');
        $reply = self::lastRow($result);
        self::assertStringContainsString('Schedule nightly: every 15m', $reply);
        self::assertStringContainsString('Do the thing', $reply);
        self::assertStringContainsString('bound to this root', $reply);

        $document = self::document($path);
        self::assertCount(1, $document['schedules']);
        $row = $document['schedules'][0];
        self::assertSame('nightly', $row['name']);
        self::assertSame(900, $row['every']);
        self::assertSame($this->root, $row['root'], 'the add stamps the host root — the tool delete guard scopes on it');
        self::assertSame(Schedule::CREATED_BY_MODEL, $row['createdBy'] ?? null, 'a command-created row carries the model tag');
        self::assertGreaterThanOrEqual($before + 900, $row['nextDue']);

        // The factory path is single: the row lists as a persisted file row.
        $listing = SchedulesCommand::listing($registry);
        self::assertStringContainsString('(file) nightly', $listing);
        self::remove($this->root);
    }

    public function testAddRefusesAnUnparseableIntervalNamingTheUnits(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);

        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules add nightly banana Do the thing');

        self::assertStringContainsString('Not an interval: banana', self::lastRow($result));
        self::assertStringContainsString('90s, 15m, 2h, 1d', self::lastRow($result));
        self::assertFileDoesNotExist($path, 'a refused add never lands the roster file');
        self::remove($this->root);
    }

    public function testAddRefusesAnIntervalBelowTheScheduleFloor(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);

        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules add nightly 5s Do the thing');

        self::assertStringContainsString('must repeat every', self::lastRow($result));
        self::assertFileDoesNotExist($path);
        self::remove($this->root);
    }

    public function testAddRefusesABypassClassModeSpelledByName(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);

        $result = (new SchedulesCommand())->run(
                $this->context($registry),
                '/schedules add nightly 15m Do the thing --mode bypass-permissions',
            );

        self::assertStringContainsString('bypass-permissions', self::lastRow($result));
        self::assertStringContainsString('refused', self::lastRow($result));
        self::assertFileDoesNotExist($path);
        self::remove($this->root);
    }

    public function testAddCarriesTheProviderFlagAndKeepsThePromptVerbatim(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);

        (new SchedulesCommand())->run(
                $this->context($registry),
                '/schedules add nightly 15m Do the thing --provider myprov',
            );

        $row = self::document($path)['schedules'][0];
        self::assertSame('Do the thing', $row['prompt'], 'the flags are carried out of the prompt tail');
        self::assertSame('myprov', $row['provider'] ?? null);
        self::remove($this->root);
    }

    public function testAddHonoursTheInlineFlagForm(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);
        $context = CommandContext::new(
            root: $this->root,
            permissionGate: new PermissionGate(PermissionMode::Plan),
            scheduleRegistry: $registry,
        );

        // Session runs `plan` (rank 4); `accept-edits` (2) is offered, and
        // the settlement keeps the stricter — the same arithmetic
        // ScheduleFactoryTest pins for the tool face. The inline `=` form
        // must parse identically to the space form.
        $result = (new SchedulesCommand())->run(
                $context,
                '/schedules add nightly 15m Do the thing --mode=accept-edits',
            );

        self::assertStringContainsString('Schedule nightly', self::lastRow($result));
        self::assertSame('plan', self::document($path)['schedules'][0]['permissionMode'] ?? null);
        self::remove($this->root);
    }

    public function testAddRefusesAFlagSpelledWithoutAValue(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);

        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules add nightly 15m Do the thing --mode');

        self::assertStringContainsString('--mode', self::lastRow($result));
        self::assertFileDoesNotExist($path, 'a valueless flag is refused, never swallowed into the prompt');
        self::remove($this->root);
    }

    public function testAddRefusesWhenThePromptPartIsMissing(): void
    {
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json', $this->root);

        $result = (new SchedulesCommand())->run($this->context($registry), '/schedules add nightly 15m');

        self::assertStringContainsString('Usage: /schedules', self::lastRow($result));
        self::remove($this->root);
    }

    public function testAddSettlesAgainstTheSessionModeTheSameWayTheToolDoes(): void
    {
        $path = $this->root . '/schedules.json';
        $registry = ScheduleRegistry::atPath($path, $this->root);
        $context = CommandContext::new(
            root: $this->root,
            permissionGate: new PermissionGate(PermissionMode::Plan),
            scheduleRegistry: $registry,
        );

        $spelled = (new SchedulesCommand())->run(
                $context,
                '/schedules add nightly 15m Do the thing --mode auto',
            );
        self::assertStringContainsString('Schedule nightly', self::lastRow($spelled));
        self::assertSame('plan', self::document($path)['schedules'][0]['permissionMode'] ?? null,
            'a looser spelled mode never lands looser than the session runs');

        $omitted = (new SchedulesCommand())->run($context, '/schedules add later 30m Do the other thing');
        self::assertStringContainsString('Schedule later', self::lastRow($omitted));
        $rows = self::document($path)['schedules'];
        self::assertNull($rows[1]['permissionMode'] ?? null,
            'an omitted mode serialises as the floor null, never a frozen session mode');
        self::remove($this->root);
    }

    // =====================================================================
    // fixtures
    // =====================================================================

    private static function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::remove($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
