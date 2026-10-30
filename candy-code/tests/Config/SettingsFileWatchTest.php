<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\SessionSettings;
use SugarCraft\Code\Config\SettingsFileWatch;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-TUI-5.c (sources: GAP-CFG-05): the settings-file watch answers one
 * question — "did the merged settings change on disk since the last look?" —
 * and the answer must be right without ever being louder than the files.
 *
 * The ladder is the contract: a first observation seeds and reports nothing,
 * and the launch takes that observation itself so an idle-window edit stays
 * visible; quiet files cost the hash of a small JSON file; churn that rewrites
 * identical bytes (or identical values under different spacing) reports
 * nothing; only a changed HASH feeds
 * the merge diff, and a key is reported exactly once — the baseline moves
 * with every answer. The watch-set rule is the cross-process guard: a
 * different set of watched paths (a moved --config, another test's home)
 * re-seeds silently instead of reporting a tier-tree swap as an edit.
 *
 * This file exercises the class directly; the turn-settle wiring and the
 * `/settings reload` command live in {@see \SugarCraft\Code\Tests\Chat\SettingsReloadTest}.
 */
final class SettingsFileWatchTest extends TestCase
{
    use HomeSandboxTrait;

    private string $home = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->home = sys_get_temp_dir() . '/crush-settings-watch-' . bin2hex(random_bytes(6));
        mkdir($this->home . '/.candy-code', 0o700, true);
        $this->useHomeSandbox($this->home);
        SessionSettings::reset();
        SettingsFileWatch::resetForTesting();
    }

    protected function tearDown(): void
    {
        SettingsFileWatch::resetForTesting();
        SessionSettings::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        exec('rm -rf ' . escapeshellarg($this->home));

        parent::tearDown();
    }

    public function testTheFirstObservationSeedsTheFingerprintAndReportsNothing(): void
    {
        // The launch read and the seed come from the same files, so a process
        // that has not settled once yet has no baseline to be stale against.
        $this->writeWatched(['scrollWheelLines' => 4]);

        self::assertSame([], SettingsFileWatch::changedKeys(), 'the seed must never report the launch values as a change');
    }

    public function testTheBootSeedMakesAnEditFromTheIdleWindowTheFirstReport(): void
    {
        // r1 MAJOR-2: seedAtLaunch() IS that first observation, taken at boot
        // instead of at the first look — so an edit that landed while the
        // session sat idle is a move against the launch's own baseline and the
        // next look reports it, instead of being folded into a snapshot no one
        // read at launch.
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::seedAtLaunch();

        $this->writeWatched(['scrollWheelLines' => 5]);

        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys(), 'the launch baseline makes the idle edit visible');
        self::assertSame([], SettingsFileWatch::changedKeys(), 'and it is reported exactly once');
    }

    public function testASecondBootSeedBaselinesOnTheBytesItFound(): void
    {
        // A build is a launch: its own read is the baseline it is measured
        // against. A second seed therefore swallows an edit nobody adopted yet
        // rather than reporting it to a session that merged it at its start.
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::seedAtLaunch();
        $this->writeWatched(['scrollWheelLines' => 5]);
        SettingsFileWatch::seedAtLaunch();

        self::assertSame([], SettingsFileWatch::changedKeys(), 'the newer launch read is not a change for the session that read it');
    }

    public function testTheLaunchPathItselfTakesTheFirstObservation(): void
    {
        // The three seeds above prove what seedAtLaunch() does; none of them
        // proves the production launch calls it — the whole defect was that
        // nobody took the first observation before the first look. Wiring pin
        // in the house source-scan shape: the call must live inside
        // Bootstrap::chat(), the function that builds the launched Chat.
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $head = \strpos($source, 'public static function chat(');
        self::assertNotFalse($head, 'Bootstrap::chat() is no longer where the launch is assembled');

        $open = \strpos($source, '{', (int) $head);
        self::assertNotFalse($open, 'chat() has no body to pin');
        $depth = 0;
        $close = -1;
        $length = \strlen($source);
        for ($i = (int) $open; $i < $length; $i++) {
            $byte = $source[$i];
            if ($byte === '{') {
                $depth++;
            } elseif ($byte === '}') {
                $depth--;
                if ($depth === 0) {
                    $close = $i;
                    break;
                }
            }
        }
        self::assertGreaterThan($open, $close, 'chat() body did not balance');

        $body = \substr($source, (int) $open, $close - $open);
        self::assertStringContainsString(
            'SettingsFileWatch::seedAtLaunch();',
            $body,
            'the launch must take the watch\'s first observation itself, not leave it to the first settle'
        );
    }

    public function testQuietFilesAnswerNothingOnEveryLaterLook(): void
    {
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();

        self::assertSame([], SettingsFileWatch::changedKeys());
        self::assertSame([], SettingsFileWatch::changedKeys(force: true), 'force buys "look now", never "report noise"');
    }

    public function testAHandEditIsReportedExactlyOnceAndThenBaselined(): void
    {
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();

        $this->writeWatched(['scrollWheelLines' => 5]);
        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys());
        self::assertSame([], SettingsFileWatch::changedKeys(), 'the answer moves the baseline; a key is never reported twice');
    }

    public function testRewritingIdenticalBytesAnswersNothing(): void
    {
        // The `git checkout` shape: a fresh mtime, the exact same content. An
        // mtime gate would accuse this file; the content hash is what answers.
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();

        $path = Bootstrap::userConfigPath();
        $bytes = (string) file_get_contents($path);
        file_put_contents($path, $bytes);
        touch($path, time() + 2);
        clearstatcache();

        self::assertSame([], SettingsFileWatch::changedKeys(), 'identical bytes are not an edit');
    }

    public function testChurnThatDoesNotMoveTheMergeAnswersNothing(): void
    {
        // Different spacing, same decoded value: the hash moves, the merged
        // view does not — and only the merged view is what a reload would
        // hand to applySettings().
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();

        file_put_contents(Bootstrap::userConfigPath(), '{"scrollWheelLines": 4}');
        self::assertSame([], SettingsFileWatch::changedKeys(), 'whitespace churn must not wake the session');
    }

    public function testTheAppearanceOfAWatchedFileIsReported(): void
    {
        // Nothing in config.json; the user settings.json layer introduces
        // scrollWheelLines when the file appears — an appearance is an edit
        // of the merge.
        $this->writeWatched([]);
        SettingsFileWatch::changedKeys();

        $dir = Bootstrap::userSettingsDirOrNull();
        self::assertNotNull($dir, 'fixture: a trusted user settings dir in the sandbox');
        file_put_contents(rtrim((string) $dir, '/') . '/settings.json', json_encode(['scrollWheelLines' => 9]));

        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys());
    }

    public function testTheDisappearanceOfAWatchedFileIsReportedToo(): void
    {
        $dir = Bootstrap::userSettingsDirOrNull();
        self::assertNotNull($dir);
        $layer = rtrim((string) $dir, '/') . '/settings.json';
        file_put_contents($layer, json_encode(['scrollWheelLines' => 9]));
        SettingsFileWatch::changedKeys();

        unlink($layer);
        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys(), 'a deleted edit reverts the merge and must be noticed');
    }

    public function testAChangeOfTheWatchedSetReseedsSilently(): void
    {
        // The --config move changes WHICH tree the session was launched from;
        // values from the other tree are not "changes" this session should
        // adopt, so the first look under a new set reports nothing — and the
        // new location is then watched like the old one was.
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();

        $alt = $this->home . '/elsewhere.json';
        Bootstrap::useConfigPath($alt);
        self::assertSame([], SettingsFileWatch::changedKeys(), 'a tier-tree move re-seeds, never reports');

        file_put_contents($alt, json_encode(['scrollWheelLines' => 6]));
        self::assertSame(['scrollWheelLines'], SettingsFileWatch::changedKeys(), 'and the moved location IS the watched file now');
    }

    public function testAResetForTestingMakesTheNextObservationTheSeedAgain(): void
    {
        $this->writeWatched(['scrollWheelLines' => 4]);
        SettingsFileWatch::changedKeys();
        $this->writeWatched(['scrollWheelLines' => 5]);

        SettingsFileWatch::resetForTesting();
        self::assertSame([], SettingsFileWatch::changedKeys(), 'after a reset the next look is a fresh seed');
    }

    private function writeWatched(array $data): void
    {
        file_put_contents(Bootstrap::userConfigPath(), json_encode($data === [] ? new \stdClass() : $data));
    }
}
