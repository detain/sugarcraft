<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\PerfSchema;

use PHPUnit\Framework\TestCase;
use SugarCraft\Query\Admin\PerfSchema\EasySetup;

/**
 * Tests for EasySetup.
 */
final class EasySetupTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $setup = EasySetup::new();
        $this->assertInstanceOf(EasySetup::class, $setup);
    }

    public function testEnableStatementsContainsInstrumentUpdate(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->enableStatements();

        $this->assertNotEmpty($statements);
        $this->assertContainsOnly('string', $statements);

        // Should have UPDATE for instruments
        $instrumentStatement = $this->findStatement($statements, 'setup_instruments');
        $this->assertNotNull($instrumentStatement);
        $this->assertStringContainsString('ENABLED', $instrumentStatement);
        $this->assertStringContainsString('YES', $instrumentStatement);
    }

    private function findStatement(array $statements, string $needle): ?string
    {
        foreach ($statements as $statement) {
            if (str_contains($statement, $needle)) {
                return $statement;
            }
        }
        return null;
    }

    public function testEnableStatementsContainsConsumerUpdate(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->enableStatements();

        // Should have UPDATE for consumers
        $consumerStatement = $this->findStatement($statements, 'setup_consumers');
        $this->assertNotNull($consumerStatement);
        $this->assertStringContainsString('ENABLED', $consumerStatement);
        $this->assertStringContainsString('YES', $consumerStatement);
    }

    public function testEnableStatementsExcludesMemoryInstruments(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->enableStatements();

        foreach ($statements as $statement) {
            if (str_contains($statement, 'setup_instruments')) {
                $this->assertStringContainsString("NOT LIKE 'memory/%'", $statement);
            }
        }
    }

    public function testDisableStatementsContainsInstrumentUpdate(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->disableStatements();

        $this->assertNotEmpty($statements);

        // Should have UPDATE for instruments
        $instrumentStatement = $this->findStatement($statements, 'setup_instruments');
        $this->assertNotNull($instrumentStatement);
        $this->assertStringContainsString('ENABLED', $instrumentStatement);
        $this->assertStringContainsString('NO', $instrumentStatement);
    }

    public function testDisableStatementsContainsConsumerUpdate(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->disableStatements();

        // Should have UPDATE for consumers
        $consumerStatement = $this->findStatement($statements, 'setup_consumers');
        $this->assertNotNull($consumerStatement);
        $this->assertStringContainsString('ENABLED', $consumerStatement);
        $this->assertStringContainsString('NO', $consumerStatement);
    }

    public function testResetToDefaultStatementsContainsDisableForNonDefaults(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->resetToDefaultStatements();

        $this->assertNotEmpty($statements);

        // Should disable non-default instruments first (instruments not in the default set)
        // Note: % is escaped as \% in LIKE patterns (backslash is MySQL LIKE escape char)
        $hasNonDefaultDisable = false;
        foreach ($statements as $statement) {
            if (str_contains($statement, 'setup_instruments')
                && str_contains($statement, 'NOT LIKE')
                && str_contains($statement, 'wait/io/file/\\%')
                && str_contains($statement, 'wait/io/table/\\%')
                && str_contains($statement, 'wait/lock/table/sql/handler')) {
                $hasNonDefaultDisable = true;
                break;
            }
        }
        $this->assertTrue($hasNonDefaultDisable);
    }

    public function testResetToDefaultStatementsEnablesDefaultInstruments(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->resetToDefaultStatements();

        // Should have UPDATE statements for each default pattern
        $enabledStatements = array_filter(
            $statements,
            fn(string $s) => str_contains($s, 'ENABLED') && str_contains($s, 'YES')
        );

        // Should have at least one statement enabling defaults
        $this->assertNotEmpty($enabledStatements);
    }

    public function testResetToDefaultStatementsEnablesDefaultConsumers(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->resetToDefaultStatements();

        // Should disable all consumers first
        $consumerDisable = $this->findStatement($statements, 'setup_consumers');
        $this->assertNotNull($consumerDisable);

        // Should enable specific default consumers with IN clause (MySQL 5.6 defaults)
        $hasConsumerInClause = false;
        foreach ($statements as $statement) {
            if (str_contains($statement, 'setup_consumers')
                && str_contains($statement, 'events_statements_current')
                && str_contains($statement, 'IN')) {
                $hasConsumerInClause = true;
                break;
            }
        }
        $this->assertTrue($hasConsumerInClause);
    }

    public function testDefaultInstrumentsReturnsExpectedPatterns(): void
    {
        $setup = EasySetup::new();
        $defaults = $setup->defaultInstruments();

        // MySQL 5.6 defaults per Appendix C
        $expected = [
            'wait/io/file/%',
            'wait/io/table/%',
            'wait/lock/table/sql/handler',
            'statement/%',
            'idle',
        ];
        $this->assertSame($expected, $defaults);
    }

    public function testDefaultConsumersReturnsExpectedConsumers(): void
    {
        $setup = EasySetup::new();
        $defaults = $setup->defaultConsumers();

        // MySQL 5.6 defaults per Appendix C
        $expected = [
            'events_statements_current',
            'events_transactions_current',
            'global_instrumentation',
            'thread_instrumentation',
        ];
        $this->assertSame($expected, $defaults);
    }

    public function testEnableStatementsUsesBacktickIdentifiers(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->enableStatements();

        foreach ($statements as $statement) {
            $this->assertStringContainsString('`performance_schema`', $statement);
            $this->assertStringContainsString('`ENABLED`', $statement);
        }
    }

    public function testDisableStatementsUsesBacktickIdentifiers(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->disableStatements();

        foreach ($statements as $statement) {
            $this->assertStringContainsString('`performance_schema`', $statement);
            $this->assertStringContainsString('`ENABLED`', $statement);
        }
    }

    public function testResetToDefaultStatementsUsesBacktickIdentifiers(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->resetToDefaultStatements();

        $hasConsumerStatement = false;
        foreach ($statements as $statement) {
            if (str_contains($statement, 'setup_consumers')) {
                $hasConsumerStatement = true;
                $this->assertStringContainsString('`performance_schema`', $statement);
            }
        }
        $this->assertTrue($hasConsumerStatement);
    }

    public function testAllStatementsAreNonEmpty(): void
    {
        $setup = EasySetup::new();

        $allStatements = array_merge(
            $setup->enableStatements(),
            $setup->disableStatements(),
            $setup->resetToDefaultStatements()
        );

        foreach ($allStatements as $statement) {
            $this->assertNotEmpty(trim($statement));
        }
    }

    public function testConsumerNamesAreProperlyQuoted(): void
    {
        $setup = EasySetup::new();
        $statements = $setup->resetToDefaultStatements();

        // Find the statement that enables default consumers
        $consumerEnable = $this->findStatement($statements, 'events_statements_current');

        $this->assertNotNull($consumerEnable);
        // Should have backtick-quoted consumer names
        $this->assertStringContainsString('`events_statements_current`', $consumerEnable);
    }
}
