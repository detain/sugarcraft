<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Cli\ArgParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CLI argument parser.
 *
 * Verifies parsing of all skate subcommands: set, list, import, export.
 */
final class CliArgParserTest extends TestCase
{
    // ─── set ─────────────────────────────────────────────────────────────────

    public function testSetOptsKeyOnly(): void
    {
        $result = ArgParser::set(['skate', 'set', 'mykey'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertNull($result['value']);
        $this->assertNull($result['ttl']);
    }

    public function testSetOptsKeyAndValue(): void
    {
        $result = ArgParser::set(['skate', 'set', 'mykey', 'myvalue'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('myvalue', $result['value']);
        $this->assertNull($result['ttl']);
    }

    public function testSetOptsWithTtl(): void
    {
        $result = ArgParser::set(['skate', 'set', 'mykey', '--ttl=3600'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testSetOptsWithTtlAndValue(): void
    {
        $result = ArgParser::set(['skate', 'set', 'mykey', 'myvalue', '--ttl=7200'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('myvalue', $result['value']);
        $this->assertSame(7200, $result['ttl']);
    }

    public function testSetOptsKeyWithDbSuffix(): void
    {
        $result = ArgParser::set(['skate', 'set', 'token@passwords', 'hunter2'], 2);
        $this->assertSame('token@passwords', $result['key']);
        $this->assertSame('hunter2', $result['value']);
    }

    public function testSetOptsUnknownFlagIsIgnored(): void
    {
        // --no-atomic is not applicable to set and must be silently ignored
        $result = ArgParser::set(['skate', 'set', 'key', 'val', '--no-atomic'], 2);
        $this->assertSame('key', $result['key']);
        $this->assertSame('val', $result['value']);
    }

    public function testSetOptsEmptyReturnsNulls(): void
    {
        $result = ArgParser::set(['skate', 'set'], 2);
        $this->assertNull($result['key']);
        $this->assertNull($result['value']);
        $this->assertNull($result['ttl']);
    }

    // ─── list ─────────────────────────────────────────────────────────────────

    public function testListOptsAllMode(): void
    {
        $result = ArgParser::list(['skate', 'list'], 2);
        $this->assertSame('all', $result['mode']);
        $this->assertFalse($result['reverse']);
        $this->assertSame("\t", $result['delimiter']);
        $this->assertNull($result['pattern']);
    }

    public function testListOptsKeysOnly(): void
    {
        $result = ArgParser::list(['skate', 'list', '-k'], 2);
        $this->assertSame('keys', $result['mode']);
    }

    public function testListOptsValuesOnly(): void
    {
        $result = ArgParser::list(['skate', 'list', '-v'], 2);
        $this->assertSame('values', $result['mode']);
    }

    public function testListOptsReverse(): void
    {
        $result = ArgParser::list(['skate', 'list', '-r'], 2);
        $this->assertTrue($result['reverse']);
    }

    public function testListOptsCustomDelimiter(): void
    {
        $result = ArgParser::list(['skate', 'list', '-d|'], 2);
        $this->assertSame('|', $result['delimiter']);
    }

    public function testListOptsCustomDelimiterEmpty(): void
    {
        $result = ArgParser::list(['skate', 'list', '-d'], 2);
        // Empty -d defaults to tab
        $this->assertSame("\t", $result['delimiter']);
    }

    public function testListOptsPattern(): void
    {
        $result = ArgParser::list(['skate', 'list', 'temp-*'], 2);
        $this->assertSame('temp-*', $result['pattern']);
    }

    public function testListOptsAllFlagsCombined(): void
    {
        $result = ArgParser::list(['skate', 'list', '-k', '-r', '-d:', 'user-*'], 2);
        $this->assertSame('keys', $result['mode']);
        $this->assertTrue($result['reverse']);
        $this->assertSame(':', $result['delimiter']);
        $this->assertSame('user-*', $result['pattern']);
    }

    // ─── import ───────────────────────────────────────────────────────────────

    public function testImportOptsFormatAndPath(): void
    {
        $result = ArgParser::import(['skate', 'import', 'json', 'data.json'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertSame('data.json', $result['path']);
        $this->assertTrue($result['atomic']);
    }

    public function testImportOptsNoAtomic(): void
    {
        $result = ArgParser::import(['skate', 'import', 'json', 'data.json', '--no-atomic'], 2);
        $this->assertFalse($result['atomic']);
    }

    public function testImportOptsYamlFormat(): void
    {
        $result = ArgParser::import(['skate', 'import', 'yaml', 'data.yaml'], 2);
        $this->assertSame('yaml', $result['format']);
    }

    public function testImportOptsStdIn(): void
    {
        $result = ArgParser::import(['skate', 'import', 'json', '-'], 2);
        $this->assertSame('-', $result['path']);
    }

    public function testImportOptsMissingParts(): void
    {
        $result = ArgParser::import(['skate', 'import'], 2);
        $this->assertNull($result['format']);
        $this->assertNull($result['path']);
        $this->assertTrue($result['atomic']);
    }

    // ─── export ───────────────────────────────────────────────────────────────

    public function testExportOptsFormatOnly(): void
    {
        $result = ArgParser::export(['skate', 'export', 'json'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertNull($result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportOptsFormatAndDb(): void
    {
        $result = ArgParser::export(['skate', 'export', 'json', 'mydb'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertSame('mydb', $result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportOptsAllPositionalArgs(): void
    {
        $result = ArgParser::export(['skate', 'export', 'yaml', 'mydb', 'key-*'], 2);
        $this->assertSame('yaml', $result['format']);
        $this->assertSame('mydb', $result['db']);
        $this->assertSame('key-*', $result['pattern']);
    }

    public function testExportOptsYamlFormat(): void
    {
        $result = ArgParser::export(['skate', 'export', 'yml'], 2);
        $this->assertSame('yml', $result['format']);
    }

    public function testExportOptsEmptyReturnsNulls(): void
    {
        $result = ArgParser::export(['skate', 'export'], 2);
        $this->assertNull($result['format']);
        $this->assertNull($result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportOptsUnknownFlagsIgnored(): void
    {
        // No flags are defined for export; unknown ones must be ignored.
        $result = ArgParser::export(['skate', 'export', 'json', '--unknown-flag'], 2);
        $this->assertSame('json', $result['format']);
    }
}
