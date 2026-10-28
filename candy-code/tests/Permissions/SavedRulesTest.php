<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Config\Settings\SettingsTier;
use SugarCraft\Code\Config\Settings\SettingsWriter;
use SugarCraft\Code\Permissions\SavedRules;

/**
 * The saved-rule list `p` grows: C-SEC-6.a moved the write out of
 * PermissionPrompt into this service and gave the same file its reader
 * (`list`) and its remover (`remove`). The pins cover the invariants the
 * modal's notices always asserted (append is reported, never fatal; an
 * allow-pattern is never written twice; a hand-written `deny` keeps its
 * first-match place) plus the new half: numbering that `forget <n>` can
 * trust, pattern removal that survives the spaces a house pattern carries,
 * and the polarity split — a failed SAVE reports, a failed FORGET refuses
 * loudly, because silence there would forge a removal that never landed.
 */
final class SavedRulesTest extends TestCase
{
    private string $sandbox = '';
    private string $config = '';

    protected function setUp(): void
    {
        $this->sandbox = sys_get_temp_dir() . '/crush-saved-rules-' . bin2hex(random_bytes(6));
        mkdir($this->sandbox, 0700, true);
        $this->config = $this->sandbox . '/config.json';
    }

    protected function tearDown(): void
    {
        if ($this->sandbox !== '' && is_dir($this->sandbox)) {
            exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1');
        }
    }

    public function testAppendWritesTheUserTierOnceAndRoundTripsThroughList(): void
    {
        $rules = new SavedRules($this->writer());
        $notice = $rules->append(['Bash(git status *)']);

        self::assertStringContainsString('Allowed Bash(git status *)', $notice);
        self::assertStringContainsString('saved to', $notice, 'the save names where it landed, as the modal has always said');
        self::assertSame(
            [['pattern' => 'Bash(git status *)', 'action' => 'allow']],
            $rules->list(),
        );
        $stored = (array) json_decode((string) file_get_contents($this->config), true);
        self::assertSame([['pattern' => 'Bash(git status *)', 'action' => 'allow']], $stored['permissionRules']);
    }

    public function testAppendNeverWritesTheSameAllowPatternTwice(): void
    {
        $rules = new SavedRules($this->writer());
        $rules->append(['Bash(git status *)']);
        $afterFirst = (string) file_get_contents($this->config);

        $notice = $rules->append(['Bash(git status *)']);
        self::assertStringContainsString('saved to', $notice, 'a deduped save still reports the file it checked — nothing was double-written');
        self::assertSame($afterFirst, (string) file_get_contents($this->config), 'the second append wrote zero bytes');
        self::assertCount(1, $rules->list());
    }

    public function testAppendLandsBehindAHandWrittenDenyAndKeepsFirstMatchWins(): void
    {
        $rules = new SavedRules($this->writer());
        // The dedup half keys on ALLOW entries only: a deny that names the
        // same pattern must survive in front, because the save must not
        // silently widen what a hand-written rule refuses.
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'deny'],
        ]);

        $rules->append(['Bash(git status *)']);
        self::assertSame([
            ['pattern' => 'Bash(git status *)', 'action' => 'deny'],
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
        ], $rules->list(), 'the allow rides AFTER the deny — first match wins, so the deny still decides');
    }

    public function testAppendWithoutADoorReportsTheNoDoorReasonAndThrowsNothing(): void
    {
        $notice = (new SavedRules(null))->append(['Bash(npm test *)']);

        self::assertStringContainsString('not saved', $notice);
        self::assertStringContainsString('this session has no settings write door', $notice, 'the sentence the prompt has always shown, kept byte-for-byte through the move');
    }

    public function testAppendReportsAFailingDoorAsNotSavedAndNeverFatal(): void
    {
        $writer = SettingsWriter::new($this->config, static function (array $set, array $unset): void {
            throw new \RuntimeException('disk on fire');
        });

        $notice = (new SavedRules($writer))->append(['Bash(npm test *)']);
        self::assertStringContainsString('not saved', $notice);
        self::assertStringContainsString('disk on fire', $notice, 'the failure names what threw');
        self::assertFileDoesNotExist($this->config, 'a refused save never creates the settings file');
    }

    public function testRemoveByIndexTakesExactlyTheEntrySavedNumbered(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'deny'],
        ]);
        $rules = new SavedRules($this->writer());

        $gone = $rules->remove(2);
        self::assertSame([['pattern' => 'Bash(npm test *)', 'action' => 'deny']], $gone);
        self::assertSame(
            [['pattern' => 'Bash(git status *)', 'action' => 'allow']],
            $rules->list(),
            'the other entry rides untouched, in order',
        );
    }

    public function testRemoveByIndexIsOneBasedAgainstTheDisplayedOrder(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'allow'],
        ]);
        $rules = new SavedRules($this->writer());

        self::assertCount(1, $rules->remove(1));
        self::assertSame([['pattern' => 'Bash(npm test *)', 'action' => 'allow']], $rules->list());
    }

    public function testRemoveByPatternTakesEveryExactMatchAndSurvivesTheSpaces(): void
    {
        $this->seedConfig([
            ['pattern' => 'Bash(git status *)', 'action' => 'deny'],
            ['pattern' => 'Bash(npm test *)', 'action' => 'allow'],
            ['pattern' => 'Bash(git status *)', 'action' => 'allow'],
        ]);
        $rules = new SavedRules($this->writer());

        $gone = $rules->remove('Bash(git status *)');
        self::assertCount(2, $gone, 'a duplicate a hand-edit made all goes — the pattern was the question, not the row');
        self::assertSame([['pattern' => 'Bash(npm test *)', 'action' => 'allow']], $rules->list());
    }

    public function testRemoveOfNoMatchAnswersEmptyAndWritesNothing(): void
    {
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);
        $before = (string) file_get_contents($this->config);
        $rules = new SavedRules($this->writer());

        self::assertSame([], $rules->remove('Bash(rm -rf *)'));
        self::assertSame($before, (string) file_get_contents($this->config), 'a miss rewrote zero bytes');
        self::assertSame([], $rules->remove(9), 'an out-of-range index is the same kind of miss');
        self::assertSame($before, (string) file_get_contents($this->config));
    }

    public function testAMissOnRemoveNeverKnocksTheWriteDoor(): void
    {
        // The stronger half of "writes nothing": the byte pin above cannot
        // see a no-op knock (the writer short-circuits an unchanged value),
        // so this door COUNTERS. Dropping the `gone !== []` guard makes the
        // miss call the door and the pin goes red by count, not by luck.
        $knocks = 0;
        $config = $this->config;
        $writer = SettingsWriter::new($config, static function (array $set, array $unset) use ($config, &$knocks): void {
            $knocks++;
            $current = \is_file($config) ? (array) json_decode((string) file_get_contents($config), true) : [];
            file_put_contents($config, (string) json_encode(SettingsWriter::patched($current, $set, $unset)));
        });
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);

        $rules = new SavedRules($writer);
        self::assertSame([], $rules->remove('Bash(rm -rf *)'));
        self::assertSame([], $rules->remove(42));
        self::assertSame(0, $knocks, 'a miss must not even attempt the write');

        self::assertCount(1, $rules->remove(1), 'a hit does knock — the counter is the guard, not a dead door');
        self::assertSame(1, $knocks);
    }

    public function testForgetOfTheLastRuleLeavesAnEmptyLoadableList(): void
    {
        $this->seedConfig([['pattern' => 'Bash(git status *)', 'action' => 'allow']]);
        $rules = new SavedRules($this->writer());

        self::assertCount(1, $rules->remove(1));
        self::assertSame([], $rules->list());
        $stored = (array) json_decode((string) file_get_contents($this->config), true);
        self::assertSame([], $stored['permissionRules'], 'the key stays present-and-empty, the shape the launch loads whole');
    }

    public function testRemoveWithoutADoorRefusesLoudlyUnlikeAFailingSave(): void
    {
        // The polarity split is the point: append() reports, remove() throws,
        // so a forget that could not land can never be heard as a success.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('this session has no settings write door');

        (new SavedRules(null))->remove(1);
    }

    public function testListPropagatesAnUnreadableConfigInsteadOfShowingEmpty(): void
    {
        // A top-level JSON list: `current()` reads strictly and throws on
        // exactly this shape, so the refusal here is the read half, not a
        // parse accident that might return [] quietly.
        file_put_contents($this->config, (string) json_encode([['pattern' => 'x', 'action' => 'allow']]));

        $this->expectException(\RuntimeException::class);

        (new SavedRules($this->writer()))->list();
    }

    public function testListShowsAndNumbersOnlyTheRowsTheLaunchWouldLoad(): void
    {
        // Review r1 MINOR: a row the launcher's permissionRules() skips —
        // no pattern, an action the enum does not know, not even an array —
        // used to ride list() as a fabricated `allow`, inflating the count
        // the header prints and shifting what `forget <n>` points at. One
        // shared filter now: hidden from the list and unnumberable here, and
        // any landed forget normalizes them out of the file.
        file_put_contents($this->config, (string) json_encode(['permissionRules' => [
            ['action' => 'allow'],
            ['pattern' => 'Bash(git status *)', 'action' => 'nope'],
            'not-even-an-array',
            ['pattern' => 'Bash(npm test *)', 'action' => 'allow'],
        ]]));
        $rules = new SavedRules($this->writer());

        self::assertSame(
            [['pattern' => 'Bash(npm test *)', 'action' => 'allow']],
            $rules->list(),
            'the count and the rows are exactly what the next launch will load, no fabricated allow',
        );

        self::assertSame(
            [['pattern' => 'Bash(npm test *)', 'action' => 'allow']],
            $rules->remove(1),
            'number 1 is the first row the list showed, not the first row the file happens to hold',
        );

        $stored = (array) json_decode((string) file_get_contents($this->config), true);
        self::assertSame(
            [],
            $stored['permissionRules'],
            'the forget took the entry it showed, and the rows it could never show left with it — the settings schema refuses to rewrite a list still holding them, so a landing forget normalizes',
        );
        self::assertSame([], $rules->list(), 'and the visible list agrees with all of it');
    }

    private function writer(): SettingsWriter
    {
        $config = $this->config;

        return SettingsWriter::new($config, static function (array $set, array $unset) use ($config): void {
            $current = \is_file($config) ? (array) json_decode((string) file_get_contents($config), true) : [];
            file_put_contents($config, (string) json_encode(SettingsWriter::patched($current, $set, $unset)));
        });
    }

    /**
     * Seed the file directly — what `list()` reads and what the launcher's
     * strict parser must accept, so the shapes here are the real stored
     * shapes, not a fixture dialect.
     *
     * @param list<array{pattern: string, action: string}> $rules
     */
    private function seedConfig(array $rules): void
    {
        file_put_contents($this->config, (string) json_encode(['permissionRules' => $rules]));
    }
}
