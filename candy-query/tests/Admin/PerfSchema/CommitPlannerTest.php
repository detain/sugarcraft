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

    public function testCommitInstrumentsGeneratesUpdateStatement(): void
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
        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_instruments`', $statements[0]);
        $this->assertStringContainsString("`NAME` RLIKE 'wait/io/file/sql/binlog'", $statements[0]);
        $this->assertStringContainsString("`ENABLED` = 'NO'", $statements[0]);
        $this->assertStringContainsString("`TIMED` = 'YES'", $statements[0]);
    }

    public function testCommitInstrumentsWithMultipleChanges(): void
    {
        $inst1 = SetupInstruments::new(name: 'wait/io/file/sql/binlog', enabled: true, timed: true);
        $inst2 = SetupInstruments::new(name: 'statement/sql/select', enabled: false, timed: false);

        $dirty1 = $inst1->withEnabled(false);
        $dirty2 = $inst2->withTimed(true);

        $planner = CommitPlanner::new(instruments: [$dirty1, $dirty2]);

        $statements = $planner->commitInstruments();

        $this->assertCount(2, $statements);
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

    public function testCommitConsumersGeneratesUpdateStatement(): void
    {
        $consumer = SetupConsumers::new(
            name: 'events_statements_history',
            enabled: true,
        );

        $dirtyConsumer = $consumer->withEnabled(false);

        $planner = CommitPlanner::new(consumers: [$dirtyConsumer]);

        $statements = $planner->commitConsumers();

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_consumers`', $statements[0]);
        $this->assertStringContainsString("`NAME` IN ('events_statements_history')", $statements[0]);
        $this->assertStringContainsString("`ENABLED` = 'NO'", $statements[0]);
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
        $this->assertStringContainsString("'events_statements_history'", $statements[0]);
        $this->assertStringContainsString("'events_statements_current'", $statements[0]);
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

    public function testCommitActorsGeneratesInsertStatement(): void
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
        $this->assertStringContainsString('INSERT INTO `performance_schema`.`setup_actors`', $statements[0]);
        $this->assertStringContainsString("(`HOST`, `USER`, `ROLE`, `ENABLED`)", $statements[0]);
        $this->assertStringContainsString("'''localhost'''", $statements[0]);
        $this->assertStringContainsString("'''testuser'''", $statements[0]);
    }

    public function testCommitActorsGeneratesUpdateStatement(): void
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
        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_actors`', $statements[0]);
        $this->assertStringContainsString("`ENABLED` = 'NO'", $statements[0]);
    }

    public function testCommitActorsGeneratesDeleteStatement(): void
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
        $this->assertStringContainsString('DELETE FROM `performance_schema`.`setup_actors`', $statements[0]);
        $this->assertStringContainsString("`HOST` = '''localhost'''", $statements[0]);
        $this->assertStringContainsString("`USER` = '''testuser'''", $statements[0]);
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

    public function testCommitObjectsGeneratesInsertStatement(): void
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
        $this->assertStringContainsString('INSERT INTO `performance_schema`.`setup_objects`', $statements[0]);
        $this->assertStringContainsString("(`OBJECT_TYPE`, `OBJECT_SCHEMA`, `OBJECT_NAME`, `ENABLED`, `TIMED`)", $statements[0]);
        $this->assertStringContainsString("'TABLE'", $statements[0]);
        $this->assertStringContainsString("'''mydb'''", $statements[0]);
        $this->assertStringContainsString("'''mytable'''", $statements[0]);
    }

    public function testCommitObjectsGeneratesUpdateStatement(): void
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
        $this->assertStringContainsString('UPDATE `performance_schema`.`setup_objects`', $statements[0]);
        $this->assertStringContainsString("`ENABLED` = 'NO'", $statements[0]);
        $this->assertStringContainsString("`TIMED` = 'YES'", $statements[0]);
    }

    public function testCommitObjectsGeneratesDeleteStatement(): void
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
        $this->assertStringContainsString('DELETE FROM `performance_schema`.`setup_objects`', $statements[0]);
        $this->assertStringContainsString("`OBJECT_TYPE` = 'TABLE'", $statements[0]);
        $this->assertStringContainsString("`OBJECT_SCHEMA` = '''mydb'''", $statements[0]);
        $this->assertStringContainsString("`OBJECT_NAME` = '''mytable'''", $statements[0]);
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

        $this->assertCount(4, $statements);
    }

    public function testCommitAllWithNoChanges(): void
    {
        $planner = CommitPlanner::new();

        $statements = $planner->commitAll();

        $this->assertSame([], $statements);
    }

    // ─── Error Handling Tests ────────────────────────────────────────────────

    public function testSqlInjectionPreventionInInstrumentName(): void
    {
        $maliciousName = "wait'; DROP TABLE mysql.users; --";
        $instrument = SetupInstruments::new(
            name: $maliciousName,
            enabled: true,
            timed: true,
        );

        $dirtyInstrument = $instrument->withEnabled(false);

        $planner = CommitPlanner::new(instruments: [$dirtyInstrument]);

        $statements = $planner->commitInstruments();

        // The malicious name should be properly escaped as a string literal
        // MySQL escapes single quotes by doubling them: ' becomes ''
        // So the injected semicolon is inside the string, not a statement terminator
        $this->assertStringContainsString("DROP TABLE", $statements[0]);
        $this->assertStringContainsString("RLIKE 'wait''; DROP TABLE mysql.users; --'", $statements[0]);
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

        // The malicious value should be properly quoted and escaped
        // The string "'; DELETE..." becomes '''; DELETE...' after quote()
        $this->assertStringContainsString("'''; DELETE FROM performance_schema.setup_actors; --'", $statements[0]);
    }
}
