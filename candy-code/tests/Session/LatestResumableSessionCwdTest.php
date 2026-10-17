<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Session;

use PDO;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Message;
use SugarCraft\Code\Session\EnhancedSessionStore;

/**
 * `--continue` across checkouts (sugar_issues.md ISS-026): the store's ladder
 * prefers the newest resumable session recorded under the launching cwd and
 * only then falls back to the global newest — reporting whether the answer
 * crossed projects so the launch can say so out loud.
 *
 * The announcement itself lands on stderr (which PHPUnit does not capture, by
 * the SessionRetentionWiringTest doctrine); these pins hold up the structure
 * behind it — the pick and the crossed flag.
 */
final class LatestResumableSessionCwdTest extends TestCase
{
    private string $dbPath;

    protected function setUp(): void
    {
        $dir = sys_get_temp_dir() . '/crush-resume-cwd-' . bin2hex(random_bytes(6));
        mkdir($dir, 0700, true);
        $this->dbPath = $dir . '/session.db';
    }

    protected function tearDown(): void
    {
        foreach (glob(dirname($this->dbPath) . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir(dirname($this->dbPath));
    }

    private function store(): EnhancedSessionStore
    {
        return new EnhancedSessionStore($this->dbPath);
    }

    private function age(string $id, string $stamp): void
    {
        (new PDO('sqlite:' . $this->dbPath))
            ->prepare('UPDATE sessions SET updated_at = ? WHERE id = ?')
            ->execute([$stamp, $id]);
    }

    private function talked(EnhancedSessionStore $store, string $id): void
    {
        $store->saveTranscript($id, [Message::user('work in ' . $id)]);
    }

    public function testTheSameCheckoutWinsOverANewerSessionElsewhere(): void
    {
        $store = $this->store();
        $store->createSession('here', 'p', 'm', null, null, '/project/here');
        $store->createSession('there', 'p', 'm', null, null, '/project/there');
        $this->talked($store, 'here');
        $this->talked($store, 'there');
        $this->age('here', '2029-01-01 00:00:00');
        $this->age('there', '2030-01-01 00:00:00');

        $pick = $store->latestResumableSessionFromCwd('/project/here');

        $this->assertNotNull($pick);
        [$row, $crossed] = $pick;
        $this->assertSame('here', $row['id'], 'the global newest answered while this checkout had its own work.');
        $this->assertFalse($crossed);
        // The global order really does prefer 'there', so the pin above is not
        // vacuous on SQL accident: same data, unscoped answer, opposite row.
        $this->assertSame('there', $store->latestResumableSession()['id']);
    }

    public function testTheGlobalFallbackCrossesWhenTheRowDemonstrablyLivesElsewhere(): void
    {
        $store = $this->store();
        $store->createSession('there', 'p', 'm', null, null, '/project/there');
        $this->talked($store, 'there');

        $pick = $store->latestResumableSessionFromCwd('/project/here');

        $this->assertNotNull($pick);
        [$row, $crossed] = $pick;
        $this->assertSame('there', $row['id']);
        $this->assertTrue($crossed);
    }

    public function testARowWithNoRecordedProjectCrossesSilently(): void
    {
        $store = $this->store();
        $store->createSession('legacy', 'p', 'm');
        $this->talked($store, 'legacy');

        $pick = $store->latestResumableSessionFromCwd('/project/here');

        $this->assertNotNull($pick);
        [$row, $crossed] = $pick;
        $this->assertSame('legacy', $row['id']);
        $this->assertFalse($crossed, 'an unknown cwd is not evidence of another project.');
    }

    public function testWithoutALaunchingCwdTheGlobalPickIsJustThePick(): void
    {
        $store = $this->store();
        $store->createSession('there', 'p', 'm', null, null, '/project/there');
        $this->talked($store, 'there');

        $pick = $store->latestResumableSessionFromCwd(null);

        $this->assertNotNull($pick);
        [$row, $crossed] = $pick;
        $this->assertSame('there', $row['id']);
        $this->assertFalse($crossed, 'there was no project to cross out of.');
    }

    public function testNothingResumableAnswersNull(): void
    {
        $store = $this->store();
        $store->createSession('silent', 'p', 'm', null, null, '/project/here');

        $this->assertNull($store->latestResumableSessionFromCwd('/project/here'));
        $this->assertNull($this->store()->latestResumableSessionFromCwd('/project/here'));
    }

    /**
     * Both rungs must mean "resumable" identically, or the scoped rung could
     * veto a row the global rung would happily resume (or the reverse). The
     * predicates here are exercised on the scoped rung with the global rung
     * as the control: same-cwd rows the global ladder still sees must be the
     * scoped pick; archived and subagent rows must strand the scoped rung
     * into the crossed fallback, never into silence.
     */
    public function testTheScopedRungKeepsEveryResumablePredicate(): void
    {
        $store = $this->store();
        $store->createSession('archived-here', 'p', 'm', null, null, '/project/here');
        $store->createSession('subagent-here', 'p', 'm', null, null, '/project/here');
        $store->createSession('quiet-here', 'p', 'm', null, null, '/project/here');
        $store->createSession('elsewhere', 'p', 'm', null, null, '/project/there');
        foreach (['archived-here', 'subagent-here', 'quiet-here', 'elsewhere'] as $id) {
            $this->talked($store, $id);
        }
        $this->age('archived-here', '2032-01-01 00:00:00');
        $this->age('subagent-here', '2031-01-01 00:00:00');
        $this->age('quiet-here', '2030-01-01 00:00:00');
        // Newest of all: the scoped answer can only come from the predicates.
        $this->age('elsewhere', '2033-01-01 00:00:00');
        $pdo = new PDO('sqlite:' . $this->dbPath);
        $pdo->prepare('UPDATE sessions SET archived_at = ? WHERE id = ?')->execute(['2020-01-01 00:00:00', 'archived-here']);
        $pdo->prepare("UPDATE sessions SET kind = 'subagent' WHERE id = ?")->execute(['subagent-here']);

        $pick = $store->latestResumableSessionFromCwd('/project/here');

        $this->assertNotNull($pick);
        [$row, $crossed] = $pick;
        $this->assertSame('quiet-here', $row['id'], 'the scoped rung dropped a row the global ladder would have resumed.');
        $this->assertFalse($crossed);
    }
}
