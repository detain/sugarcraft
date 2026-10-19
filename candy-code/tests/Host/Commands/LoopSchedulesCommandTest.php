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
use SugarCraft\Code\Sessions\Schedule;
use SugarCraft\Code\Sessions\ScheduleRegistry;

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
        $registry = ScheduleRegistry::atPath($this->root . '/schedules.json');
        self::assertStringContainsString('No schedules', self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules')));

        $registry->add(new Schedule(name: 'nightly', everySeconds: 3600, prompt: 'run notes', nextDue: time() + 3600));
        $registry->persist();
        (new LoopCommand())->run($this->context($registry), '/loop 15m watch the tests');

        $listing = self::lastRow((new SchedulesCommand())->run($this->context($registry), '/schedules list'));
        self::assertStringContainsString('nightly', $listing);
        self::assertStringContainsString('(file)', $listing);
        self::assertStringContainsString('loop-1', $listing);
        self::assertStringContainsString('(loop)', $listing);
        self::assertStringContainsString('every 15m', $listing, 'the label ladder reads in human units');
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
        self::assertStringContainsString('Not loaded from', $listing, 'the refused document is announced');
        self::assertStringContainsString('version 99', $listing, 'by name, not silently');
    }

    public function testReadOnlyJudgementIsVerbShaped(): void
    {
        self::assertTrue(SchedulesCommand::isReadOnlyVerb('list'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('enable'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('disable'));
        self::assertFalse(SchedulesCommand::isReadOnlyVerb('delete'));
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
