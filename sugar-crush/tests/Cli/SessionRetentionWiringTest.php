<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Session\EnhancedSessionStore;

/**
 * crush_code.md Phase 0 item 12 — `pruneSessions()` was written, unit-tested
 * and never called, so `~/.sugar-crush/session.db` grew for the life of the
 * install. These assert it is reachable from the one production path that
 * builds the real store, that it is OPT-IN (an unset variable must never
 * delete a conversation), and that the retention knob behaves.
 *
 * @see Bootstrap::sessionStore()
 */
final class SessionRetentionWiringTest extends TestCase
{
    private string $tmpHome;
    private string|false $previousHome;
    private string|false $previousRetention;

    /**
     * $HOME is redirected HERE, not in a lazy helper.
     *
     * `Bootstrap::configDir()` reads `$HOME`, and `Bootstrap::sessionStore()`
     * now prunes, so any test method that reaches Bootstrap before the
     * redirect happens would run retention against the developer's REAL
     * `~/.sugar-crush/session.db`. Doing it in setUp() means no future method
     * can get that ordering wrong.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // getenv() returns false for unset; `?: null` would fold a legitimate
        // "0" into "unset" and silently RE-ENABLE retention in tearDown() for
        // a developer who had deliberately disabled it.
        $this->previousHome = getenv('HOME');
        $this->previousRetention = getenv('SUGARCRUSH_SESSION_RETENTION_DAYS');
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS');

        $this->tmpHome = sys_get_temp_dir() . '/crush-retention-' . bin2hex(random_bytes(6));
        mkdir($this->tmpHome . '/.sugar-crush', 0700, true);
        putenv("HOME={$this->tmpHome}");
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->previousRetention === false
            ? putenv('SUGARCRUSH_SESSION_RETENTION_DAYS')
            : putenv("SUGARCRUSH_SESSION_RETENTION_DAYS={$this->previousRetention}");

        $this->previousHome === false
            ? putenv('HOME')
            : putenv("HOME={$this->previousHome}");

        if (is_dir($this->tmpHome)) {
            $this->removeTree($this->tmpHome);
        }
    }

    /**
     * The headline of MINOR-2: with the variable unset, a launch must not
     * destroy anything. The session a 30-day default would have deleted is
     * exactly the one the user came back for.
     */
    public function testRetentionIsOffUnlessTheUserAsksForIt(): void
    {
        $this->seedStore(['ancient' => null]);
        $this->ageSession('ancient', '2001-01-01 00:00:00');

        $store = Bootstrap::sessionStore();

        $this->assertNotNull($store->getSession('ancient'));
        $this->assertSame([], $store->pruneReport());
    }

    public function testRetentionDefaultsToDisabled(): void
    {
        $this->assertSame(0, Bootstrap::sessionRetentionDays());
    }

    public function testSessionStoreRetiresAnAbandonedSessionWhenRetentionIsEnabled(): void
    {
        $this->seedStore(['stale' => null, 'fresh' => null, 'resumable' => null]);
        $this->ageSession('stale', '2020-01-01 00:00:00');

        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=30');
        $store = Bootstrap::sessionStore();

        $this->assertNull($store->getSession('stale'));
        $this->assertNotNull($store->getSession('fresh'));
    }

    /**
     * The exemption that keeps retention from deleting something a user meant
     * to keep, verified through the production entry point rather than only
     * on SessionStore directly.
     */
    public function testSessionStoreNeverRetiresANamedSession(): void
    {
        $this->seedStore(['stale' => null, 'named' => 'release checklist', 'resumable' => null]);
        $this->ageSession('stale', '2020-01-01 00:00:00');
        $this->ageSession('named', '2020-01-01 00:00:00');

        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=30');
        $store = Bootstrap::sessionStore();

        $this->assertNull($store->getSession('stale'));
        $this->assertNotNull($store->getSession('named'));
    }

    /**
     * "Unnamed" is a weak proxy for "abandoned": `Chat::scheduleTitleGeneration()`
     * fires at most once per session, needs a working title backend and fails
     * silently, so an offline or key-less install leaves every session unnamed
     * however much work it holds. The row `seedSession()` is about to resume
     * therefore survives on top of the name check — otherwise retention
     * deletes the conversation and hands the user an empty one in the same
     * launch.
     */
    public function testTheSessionAboutToBeResumedIsNeverPruned(): void
    {
        $this->seedStore(['resumable' => null, 'older' => null]);
        // Everything is old, including the row seedSession() would pick.
        $this->ageSession('older', '2019-01-01 00:00:00');
        $this->ageSession('resumable', '2020-01-01 00:00:00');

        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=30');
        $store = Bootstrap::sessionStore();

        $this->assertNotNull($store->getSession('resumable'), 'Retention deleted the session the launch was about to resume.');
        $this->assertNull($store->getSession('older'));

        [$sessionId] = Bootstrap::seedSession($store);
        $this->assertSame('resumable', $sessionId);
    }

    /**
     * Deleting conversations silently is the part that is not defensible: the
     * caller used to throw `pruneSessions()`'s return value away, so a launch
     * destroyed a month-old transcript and printed nothing at all. The lines
     * this run writes to stderr (which PHPUnit does not capture, hence the
     * assertion on the structured report behind them) are deliberate.
     */
    public function testWhatRetentionDeletedIsReportedNotDiscarded(): void
    {
        $this->seedStore(['resumable' => null, 'gone' => null]);
        $this->ageSession('gone', '2020-01-01 00:00:00');
        $store = new EnhancedSessionStore($this->tmpHome . '/.sugar-crush/session.db');
        $store->addMessage('gone', ['role' => 'user', 'content' => 'a month of work']);
        $this->ageSession('gone', '2020-01-01 00:00:00');
        unset($store);

        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=30');
        $store = Bootstrap::sessionStore();

        $report = $store->pruneReport();
        $this->assertCount(1, $report);
        $this->assertSame('gone', $report[0]['id']);
        $this->assertSame(1, $report[0]['messages']);
        $this->assertSame('2020-01-01 00:00:00', $report[0]['updated_at']);
    }

    public function testRetentionDaysAreConfigurableFromTheEnvironment(): void
    {
        $this->seedStore(['resumable' => null, 'tenDaysOld' => null]);
        $this->ageSession('tenDaysOld', gmdate('Y-m-d H:i:s', strtotime('-10 days')));

        // The 30-day window would keep it.
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=5');
        $store = Bootstrap::sessionStore();

        $this->assertNull($store->getSession('tenDaysOld'));
    }

    public function testZeroRetentionDisablesPruningEntirely(): void
    {
        $this->seedStore(['ancient' => null]);
        $this->ageSession('ancient', '2001-01-01 00:00:00');

        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=0');
        $store = Bootstrap::sessionStore();

        $this->assertNotNull($store->getSession('ancient'));
    }

    /**
     * A typo must not be read as "delete more aggressively" — anything that
     * is not a plain positive integer turns retention off instead.
     *
     * @dataProvider unusableRetentionValues
     */
    public function testAnUnusableRetentionValueDisablesPruningRatherThanGuessing(string $raw): void
    {
        putenv("SUGARCRUSH_SESSION_RETENTION_DAYS={$raw}");

        $this->assertSame(0, Bootstrap::sessionRetentionDays());
    }

    /** @return array<string, array{0: string}> */
    public static function unusableRetentionValues(): array
    {
        return [
            'negative'  => ['-7'],
            'wordy'     => ['thirty'],
            'fractional' => ['1.5'],
            'suffixed'  => ['30d'],
            'signed'    => ['+7'],
            'blank'     => ['   '],
        ];
    }

    /**
     * `ctype_digit()` passes a 20-digit number, `(int)` saturates it to
     * `PHP_INT_MAX`, and `strtotime()` overflows that into a cutoff in the
     * year 2343668 — a FUTURE cutoff, which matches every session there is.
     * The value is clamped rather than trusted.
     */
    public function testAnOverflowingRetentionValueIsClampedNotObeyed(): void
    {
        putenv('SUGARCRUSH_SESSION_RETENTION_DAYS=99999999999999999999');

        $days = Bootstrap::sessionRetentionDays();
        $this->assertSame(\SugarCraft\Crush\Session\SessionStore::MAX_RETENTION_DAYS, $days);

        $this->seedStore(['ancient' => null, 'resumable' => null]);
        $this->ageSession('ancient', '2001-01-01 00:00:00');

        $store = Bootstrap::sessionStore();

        $this->assertNotNull($store->getSession('ancient'), 'An overflowing retention window deleted the whole table.');
    }

    /**
     * @param array<string, string|null> $sessions id => name
     */
    private function seedStore(array $sessions): void
    {
        $store = new EnhancedSessionStore($this->tmpHome . '/.sugar-crush/session.db');
        foreach ($sessions as $id => $name) {
            $store->createSession($id, 'p', 'm', null, $name);
        }
    }

    private function ageSession(string $id, string $timestamp): void
    {
        $pdo = new PDO('sqlite:' . $this->tmpHome . '/.sugar-crush/session.db');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')->execute([$timestamp, $id]);
    }

    private function removeTree(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
