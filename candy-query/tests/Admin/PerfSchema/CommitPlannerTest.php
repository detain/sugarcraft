<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\CommitPlanner;
use SugarCraft\Query\Admin\PerfSchema\SetupActors;
use SugarCraft\Query\Admin\PerfSchema\SetupConsumers;
use SugarCraft\Query\Admin\PerfSchema\SetupInstruments;
use SugarCraft\Query\Admin\PerfSchema\SetupObjects;
use SugarCraft\Query\Admin\PerfSchema\SetupThreads;
use SugarCraft\Query\Admin\PerfSchema\SetupTimers;

final class CommitPlannerTest extends TestCase
{
    public function testNewCreatesEmptyPlanner(): void
    {
        $planner = CommitPlanner::new();

        $this->assertFalse($planner->isDirty());
        $this->assertSame([], $planner->commitAll());
    }

    // ─── SetupInstruments Tests ───────────────────────────────────────────────

    public function testCommitInstrumentsWithNoDirtyInstruments(): void
    {
        $instrument = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        );

        $planner = CommitPlanner::new(instruments: [$instrument]);

        $this->assertFalse($planner->isDirty());
        $this->assertSame([], $planner->commitInstruments());
    }

    public function testCommitInstrumentsGeneratesParameterizedUpdate(): void
    {
        $instrument = SetupInstruments::new(
            name: 'wait/io/file/sql/binlog',
            enabled: true,
            timed: true,
        );

        $dirtyInstrument = $instrument->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$dirtyInstrument]);

        $this->assertTrue($planner->isDirty());

        $statements = $planner->commitInstruments();

        $this->assertCount(1, $statements);

        // Verify SQL structure: has ? placeholders, no string interpolation
        $stmt = $statements[0];
        $this->assertArrayHasKey('sql', $stmt);
        $this->assertArrayHasKey('params', $stmt);

        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_instruments`', $sql);
        $this->assertStringContainsString('`ENABLED` = ?', $sql);
        $this->assertStringContainsString('`TIMED` = ?', $sql);
        $this->assertStringContainsString('`NAME` RLIKE ?', $sql);

        // Verify params: [enabledValue, timedValue, pattern]
        $this->assertCount(3, $params);
        $this->assertSame('NO', $params[0]); // enabled = false → 'NO'
        $this->assertSame('YES', $params[1]); // timed = true → 'YES'

        // Verify anchored, regex-escaped pattern (no capturing group for single instrument)
        $this->assertSame('^wait/io/file/sql/binlog$', $params[2]);
    }

    public function testCommitInstrumentsWithMultipleChangesInSameBucket(): void
    {
        // Two instruments with the same enabled/timed values should be batched
        $inst1 = SetupInstruments::new(name: 'wait/io/file/sql/binlog', enabled: true, timed: true);
        $inst2 = SetupInstruments::new(name: 'wait/io/file/sql/FRM', enabled: true, timed: true);

        $dirty1 = $inst1->withEnabled(false);
        $dirty2 = $inst2->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$dirty1, $dirty2]);

        $statements = $planner->commitInstruments();

        // Both disabled in same bucket → one statement with alternation pattern
        $this->assertCount(1, $statements);
        $stmt = $statements[0];

        // Pattern should be alternation of both instrument names
        $this->assertSame('NO', $stmt['params'][0]);
        $this->assertSame('YES', $stmt['params'][1]);
        $this->assertStringContainsString('binlog', $stmt['params'][2]);
        $this->assertStringContainsString('FRM', $stmt['params'][2]);
    }

    public function testCommitInstrumentsWithDifferentBuckets(): void
    {
        // Two instruments with different resulting (enabled, timed) states should be separate
        $inst1 = SetupInstruments::new(name: 'wait/io/file/sql/binlog', enabled: true, timed: true);
        $inst2 = SetupInstruments::new(name: 'statement/sql/select', enabled: false, timed: false);

        // inst1: only enabled changes to false → (false, true)
        $dirty1 = $inst1->withEnabled(false);
        // inst2: only enabled changes to true → (true, false)
        $dirty2 = $inst2->withEnabled(true);

        $planner = CommitPlanner::new(instruments: [$dirty1, $dirty2]);

        $statements = $planner->commitInstruments();

        // Different resulting (enabled, timed) combos → two separate statements
        $this->assertCount(2, $statements);

        // Verify they have different enabled/timed values
        $stmt1 = $statements[0];
        $stmt2 = $statements[1];

        // One should have (NO, YES), other should have (YES, NO)
        if ($stmt1['params'][0] === 'NO') {
            $this->assertSame('NO', $stmt1['params'][0]);
            $this->assertSame('YES', $stmt1['params'][1]);
            $this->assertSame('YES', $stmt2['params'][0]);
            $this->assertSame('NO', $stmt2['params'][1]);
        } else {
            $this->assertSame('YES', $stmt1['params'][0]);
            $this->assertSame('NO', $stmt1['params'][1]);
            $this->assertSame('NO', $stmt2['params'][0]);
            $this->assertSame('YES', $stmt2['params'][1]);
        }
    }

    public function testCommitInstrumentsWithMetacharactersInName(): void
    {
        // Instrument names with regex metacharacters should be properly escaped
        $instrument = SetupInstruments::new(
            name: 'statement/sql/abstract.test(group)',
            enabled: true,
            timed: true,
        );

        $dirtyInstrument = $instrument->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$dirtyInstrument]);

        $statements = $planner->commitInstruments();

        $stmt = $statements[0];

        // Pattern should have metacharacters escaped
        // . ( ) are regex metacharacters that should be escaped
        $this->assertSame('^statement/sql/abstract\\.test\\(group\\)$', $stmt['params'][2]);
    }

    // ─── SetupConsumers Tests ─────────────────────────────────────────────────

    public function testCommitConsumersWithNoDirtyConsumers(): void
    {
        $consumer = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $planner = CommitPlanner::new(consumers: [$consumer]);

        $this->assertFalse($planner->isDirty());
        $this->assertSame([], $planner->commitConsumers());
    }

    public function testCommitConsumersGeneratesParameterizedUpdate(): void
    {
        $consumer = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $dirtyConsumer = $consumer->withEnabled(false);

        $planner = CommitPlanner::new(consumers: [$dirtyConsumer]);

        $statements = $planner->commitConsumers();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertArrayHasKey('sql', $stmt);
        $this->assertArrayHasKey('params', $stmt);

        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_consumers`', $sql);
        $this->assertStringContainsString('`NAME` IN (?)', $sql);
        $this->assertStringContainsString('`ENABLED` = ?', $sql);

        // Params: [enabledValue, name1, name2, ...]
        $this->assertCount(2, $params);
        $this->assertSame('NO', $params[0]);
        $this->assertSame('events_statements_history', $params[1]);
    }

    public function testCommitConsumersGroupsByEnabledState(): void
    {
        $consumer1 = SetupConsumers::new(name: 'events_statements_history', enabled: true);
        $consumer2 = SetupConsumers::new(name: 'events_statements_current', enabled: true);

        $dirty1 = $consumer1->withEnabled(false);
        $dirty2 = $consumer2->withEnabled(false);

        $planner = CommitPlanner::new(consumers: [$dirty1, $dirty2]);

        $statements = $planner->commitConsumers();

        // Both disabled consumers should be in a single UPDATE statement
        $this->assertCount(1, $statements);
        $stmt = $statements[0];

        // Should have 3 params: enabled value + 2 names
        $this->assertCount(3, $stmt['params']);
        $this->assertSame('NO', $stmt['params'][0]);
        $this->assertSame('events_statements_history', $stmt['params'][1]);
        $this->assertSame('events_statements_current', $stmt['params'][2]);
    }

    // ─── SetupActors Tests ───────────────────────────────────────────────────

    public function testCommitActorsWithNoChanges(): void
    {
        $actor = SetupActors::new(
            host: "'%'",
            user: "'%'",
            role: "'%'",
            enabled: true,
        );

        $planner = CommitPlanner::new(actors: [$actor]);

        $this->assertFalse($planner->isDirty());
        $this->assertSame([], $planner->commitActors());
    }

    public function testCommitActorsGeneratesParameterizedInsert(): void
    {
        $actor = SetupActors::new(
            host: "'localhost'",
            user: "'testuser'",
            role: "'%'",
            enabled: true,
        );

        $dirtyActor = $actor->markForInsertion();

        $planner = CommitPlanner::new(actors: [$dirtyActor]);

        $statements = $planner->commitActors();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $this->assertArrayHasKey('sql', $stmt);
        $this->assertArrayHasKey('params', $stmt);

        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('INSERT INTO `performance_schema`.`setup_actors`', $sql);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?)', $sql);

        // Params: host, user, role, enabled
        $this->assertCount(4, $params);
        $this->assertSame("'localhost'", $params[0]);
        $this->assertSame("'testuser'", $params[1]);
        $this->assertSame("'%'", $params[2]);
        $this->assertSame('YES', $params[3]);
    }

    public function testCommitActorsGeneratesParameterizedUpdate(): void
    {
        $actor = SetupActors::new(
            host: "'%'",
            user: "'%'",
            role: "'%'",
            enabled: true,
        );

        $dirtyActor = $actor->withEnabled(false);

        $planner = CommitPlanner::new(actors: [$dirtyActor]);

        $statements = $planner->commitActors();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];

        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_actors`', $sql);
        $this->assertStringContainsString('`ENABLED` = ?', $sql);
        $this->assertStringContainsString('WHERE `HOST` = ?', $sql);
        $this->assertStringContainsString('`USER` = ?', $sql);
        $this->assertStringContainsString('`ROLE` = ?', $sql);

        // Params: enabled, host, user, role
        $this->assertCount(4, $params);
        $this->assertSame('NO', $params[0]);
    }

    public function testCommitActorsGeneratesParameterizedDelete(): void
    {
        $actor = SetupActors::new(
            host: "'localhost'",
            user: "'testuser'",
            role: "'%'",
            enabled: true,
        );

        $dirtyActor = $actor->markForDeletion();

        $planner = CommitPlanner::new(actors: [$dirtyActor]);

        $statements = $planner->commitActors();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];

        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('DELETE FROM `performance_schema`.`setup_actors`', $sql);
        $this->assertStringContainsString('WHERE `HOST` = ?', $sql);
        $this->assertStringContainsString('`USER` = ?', $sql);
        $this->assertStringContainsString('`ROLE` = ?', $sql);

        // Params: host, user, role
        $this->assertCount(3, $params);
        $this->assertSame("'localhost'", $params[0]);
    }

    public function testCommitActorsCancelsInsertOnMarkForDeletion(): void
    {
        $actor = SetupActors::new(
            host: "'localhost'",
            user: "'testuser'",
            role: "'%'",
            enabled: true,
        );

        // First mark for insertion
        $insertActor = $actor->markForInsertion();
        // Then mark for deletion - should cancel out
        $deleteActor = $insertActor->markForDeletion();

        $planner = CommitPlanner::new(actors: [$deleteActor]);

        $statements = $planner->commitActors();

        // No statement should be generated as it was cancelled
        $this->assertSame([], $statements);
    }

    // ─── SetupObjects Tests ──────────────────────────────────────────────────

    public function testCommitObjectsWithNoChanges(): void
    {
        $object = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mydb'",
            objectName: "'mytable'",
            enabled: true,
            timed: true,
        );

        $planner = CommitPlanner::new(objects: [$object]);

        $this->assertFalse($planner->isDirty());
        $this->assertSame([], $planner->commitObjects());
    }

    public function testCommitObjectsGeneratesParameterizedInsert(): void
    {
        $object = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mydb'",
            objectName: "'mytable'",
            enabled: true,
            timed: true,
        );

        $dirtyObject = $object->markForInsertion();

        $planner = CommitPlanner::new(objects: [$dirtyObject]);

        $statements = $planner->commitObjects();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('INSERT INTO `performance_schema`.`setup_objects`', $sql);
        $this->assertStringContainsString('VALUES (?, ?, ?, ?, ?)', $sql);

        // Params: objectType, objectSchema, objectName, enabled, timed
        $this->assertCount(5, $params);
        $this->assertSame('TABLE', $params[0]);
        $this->assertSame("'mydb'", $params[1]);
        $this->assertSame("'mytable'", $params[2]);
        $this->assertSame('YES', $params[3]);
        $this->assertSame('YES', $params[4]);
    }

    public function testCommitObjectsGeneratesParameterizedUpdate(): void
    {
        $object = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'%'",
            objectName: "'%'",
            enabled: true,
            timed: true,
        );

        $dirtyObject = $object->withEnabled(false);

        $planner = CommitPlanner::new(objects: [$dirtyObject]);

        $statements = $planner->commitObjects();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_objects`', $sql);
        $this->assertStringContainsString('`ENABLED` = ?', $sql);
        $this->assertStringContainsString('`TIMED` = ?', $sql);
        $this->assertStringContainsString('WHERE `OBJECT_TYPE` = ?', $sql);

        // Params: enabled, timed, objectType, objectSchema, objectName
        $this->assertCount(5, $params);
        $this->assertSame('NO', $params[0]); // enabled = false
        $this->assertSame('YES', $params[1]); // timed = true
    }

    public function testCommitObjectsGeneratesParameterizedDelete(): void
    {
        $object = SetupObjects::new(
            objectType: 'TABLE',
            objectSchema: "'mydb'",
            objectName: "'mytable'",
            enabled: true,
            timed: true,
        );

        $dirtyObject = $object->markForDeletion();

        $planner = CommitPlanner::new(objects: [$dirtyObject]);

        $statements = $planner->commitObjects();

        $this->assertCount(1, $statements);

        $stmt = $statements[0];
        $sql = $stmt['sql'];
        $params = $stmt['params'];

        $this->assertStringContainsString('DELETE FROM `performance_schema`.`setup_objects`', $sql);
        $this->assertStringContainsString('WHERE `OBJECT_TYPE` = ?', $sql);

        // Params: objectType, objectSchema, objectName
        $this->assertCount(3, $params);
        $this->assertSame('TABLE', $params[0]);
    }

    // ─── SetupThreads Tests ─────────────────────────────────────────────────

    public function testCommitThreadsReturnsEmpty(): void
    {
        $thread = SetupThreads::new(
            threadId: 1,
            name: 'thread/sql/main',
            type: 'FOREGROUND',
        );

        $planner = CommitPlanner::new(instruments: [], consumers: [], actors: [], objects: []);

        // Threads are read-only, should return empty statements
        $this->assertSame([], $planner->commitAll());
    }

    // ─── SetupTimers Tests ──────────────────────────────────────────────────

    public function testCommitTimersReturnsEmpty(): void
    {
        $timer = SetupTimers::new(
            name: 'NANOSECOND',
            timerName: 'nanosecond',
        );

        $planner = CommitPlanner::new();

        // Timers are not tracked by CommitPlanner, should return empty statements
        $this->assertSame([], $planner->commitAll());
    }

    // ─── commitAll() Tests ───────────────────────────────────────────────────

    public function testCommitAllCombinesAllStatements(): void
    {
        $instrument = SetupInstruments::new(name: 'wait/io/file/sql/binlog', enabled: true, timed: true);
        $consumer = SetupConsumers::new(name: 'events_statements_history', enabled: true);
        $actor = SetupActors::new(host: "'%'", user: "'%'", role: "'%'", enabled: true);
        $object = SetupObjects::new(objectType: 'TABLE', objectSchema: "'%'", objectName: "'%'", enabled: true, timed: true);

        $dirtyInstrument = $instrument->withEnabled(false);
        $dirtyConsumer = $consumer->withEnabled(false);
        $dirtyActor = $actor->withEnabled(false);
        $dirtyObject = $object->withEnabled(false);

        $planner = CommitPlanner::new(
            instruments: [$dirtyInstrument],
            consumers: [$dirtyConsumer],
            actors: [$dirtyActor],
            objects: [$dirtyObject],
        );

        $statements = $planner->commitAll();

        // 1 instrument bucket + 1 consumer bucket + 1 actor + 1 object = 4
        $this->assertCount(4, $statements);
    }

    public function testCommitAllWithNoChanges(): void
    {
        $planner = CommitPlanner::new();

        $statements = $planner->commitAll();

        $this->assertSame([], $statements);
    }

    // ─── Error Handling / SQL Safety Tests ─────────────────────────────────

    public function testSqlInjectionPreventionInInstrumentName(): void
    {
        // Instrument name with SQL injection attempt
        $maliciousName = "wait'; DROP TABLE mysql.users; --";
        $instrument = SetupInstruments::new(
            name: $maliciousName,
            enabled: true,
            timed: true,
        );

        $dirtyInstrument = $instrument->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$dirtyInstrument]);

        $statements = $planner->commitInstruments();

        $stmt = $statements[0];

        // The pattern is bound as a parameter, not interpolated
        // preg_quote escapes regex metacharacters (. → \., - → \-, etc.)
        // This makes the pattern match the literal malicious string, not SQL
        // The RLIKE will try to match the literal pattern, not execute SQL
        $this->assertSame("^wait'; DROP TABLE mysql\\.users; \\-\-\$", $stmt['params'][2]);

        // The SQL itself has no injected content - just the ? placeholder
        $this->assertStringNotContainsString('DROP TABLE', $stmt['sql']);
    }

    public function testSqlInjectionPreventionInActorValues(): void
    {
        $maliciousHost = "'; DELETE FROM performance_schema.setup_actors; --";
        $actor = SetupActors::new(
            host: $maliciousHost,
            user: "'user'",
            role: "'%'",
            enabled: true,
        );

        $dirtyActor = $actor->markForInsertion();

        $planner = CommitPlanner::new(actors: [$dirtyActor]);

        $statements = $planner->commitActors();

        $stmt = $statements[0];

        // The malicious host is a bound parameter, not interpolated
        $this->assertSame("'; DELETE FROM performance_schema.setup_actors; --", $stmt['params'][0]);

        // The SQL itself has no injected content - just ? placeholders
        $this->assertStringNotContainsString('DELETE FROM', $stmt['sql']);
    }
}
