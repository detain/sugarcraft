<?php

declare(strict_types=1);

namespace SugarCraft\Sql\Tests\Admin\History;

use PHPUnit\Framework\TestCase;
use SugarCraft\Sql\Admin\History\HistoryRecorder;
use SugarCraft\Sql\Admin\History\HistoryStoreInterface;
use SugarCraft\Sql\Admin\QueryLogger;
use SugarCraft\Sql\Admin\StatusSnapshot;

/**
 * The recorder must never take the live dashboard down with a broken
 * audit trail (D-M11, 2026-11-03). save() sat unguarded inside the
 * admin poll promise's ->then; any persist failure became an unhandled
 * rejection and froze admin refresh permanently.
 */
final class HistoryRecorderFaultTest extends TestCase
{
    public function testRecordSurvivesAThrowingStoreAndKeepsInMemoryState(): void
    {
        $store = new class implements HistoryStoreInterface {
            public function save(StatusSnapshot $snapshot): void
            {
                throw new \RuntimeException('disk ate the audit row');
            }

            public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function prune(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function close(): void {}
        };

        $recorder = new HistoryRecorder($store);
        $snapshot = new StatusSnapshot(['threads_running' => '7'], 1234.5);

        $recorder->record($snapshot); // must NOT throw

        $this->assertSame(['threads_running' => '7'], $recorder->currentSnapshot());
        $this->assertSame(1234.5, $recorder->statusVariablesTs());
    }

    public function testRecordedPersistFailureIsLoggedOnceForDiagnosis(): void
    {
        $store = new class implements HistoryStoreInterface {
            public function save(StatusSnapshot $snapshot): void
            {
                throw new \RuntimeException('history.db is corrupt');
            }

            public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function prune(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function close(): void {}
        };

        $recorder = new HistoryRecorder($store);
        $recorder->record(new StatusSnapshot(['a' => 'b'], 99.0));

        $lines = QueryLogger::displayLines();
        $haystack = \implode("\n", $lines);
        $this->assertStringContainsString('history-record', $haystack);
        $this->assertStringContainsString('corrupt', $haystack);
    }

    public function testHealthyStoreStillPersists(): void
    {
        $store = new class implements HistoryStoreInterface {
            /** @var list<StatusSnapshot> */
            public array $saved = [];

            public function save(StatusSnapshot $snapshot): void
            {
                $this->saved[] = $snapshot;
            }

            public function query(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function queryBoundaries(\DateTimeImmutable $since, \DateTimeImmutable $until): array
            {
                return [];
            }

            public function count(): int
            {
                return 0;
            }

            public function prune(\DateTimeImmutable $before): int
            {
                return 0;
            }

            public function close(): void {}
        };

        $recorder = new HistoryRecorder($store);
        $recorder->record(new StatusSnapshot(['ok' => '1'], 5.0));

        $this->assertCount(1, $store->saved);
    }
}
