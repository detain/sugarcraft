<?php

declare(strict_types=1);

namespace SugarCraft\Skate\Tests;

use SugarCraft\Skate\Cli\ExportCommand;
use SugarCraft\Skate\Import\YamlImporter;
use SugarCraft\Skate\Store;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the YAML fallback parser/serializer round-trip.
 *
 * Symfony\Component\Yaml\Yaml is not installed in this lib, so the fallback
 * path is always exercised. These tests lock the fallback behaviour.
 */
final class YamlFallbackTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/skate-yamlfb-test-' . \uniqid();
        \mkdir($this->tmpDir, 0o700, true);
        $this->store = new Store($this->tmpDir, 'testdb');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        $this->removeDir($this->tmpDir);
    }

    private function removeDir(string $dir): void
    {
        $files = \glob("{$dir}/*") ?: [];
        foreach ($files as $f) {
            \is_dir($f) ? $this->removeDir($f) : \unlink($f);
        }
        \rmdir($dir);
    }

    // ─── Import from string ─────────────────────────────────────────────────────

    public function testImportQuotedValues(): void
    {
        // The fallback parser strips surrounding quotes, so "value" → value
        $yaml = "key1: \"quoted value\"\nkey2: 'single quoted'\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
        $this->assertSame('quoted value', $this->store->get('key1'));
        $this->assertSame('single quoted', $this->store->get('key2'));
    }

    public function testImportEmptyValue(): void
    {
        $yaml = "empty-key: \n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(1, $count);
        $this->assertSame('', $this->store->get('empty-key'));
    }

    public function testImportTildeNullValue(): void
    {
        // ~ is YAML's null
        $yaml = "nil-key: ~\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(1, $count);
        $this->assertSame('', $this->store->get('nil-key'));
    }

    public function testImportExplicitNullString(): void
    {
        $yaml = "null-key: null\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(1, $count);
        $this->assertSame('', $this->store->get('null-key'));
    }

    public function testImportWithTtlPrefix(): void
    {
        // TTL via skate_ttl_ prefix (YAML-side), not _ttl map (JSON-side)
        $yaml = "skate_ttl_ttl-key: 7200\nttl-key: ttl-value\n";
        $importer = new YamlImporter($this->store);
        $importer->importFromString($yaml, false);

        $entry = $this->store->entry('ttl-key');
        $this->assertNotNull($entry);
        $this->assertNotNull($entry->expiresAt);
    }

    public function testImportWithDbSuffix(): void
    {
        $yaml = "token@pw: secret\nuser@meta: alice\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
        $this->assertSame('secret', $this->store->get('token@pw'));
        $this->assertSame('alice', $this->store->get('user@meta'));
    }

    // ─── Round-trip via export ─────────────────────────────────────────────────

    /**
     * Verify that values with special characters round-trip through the
     * fallback YAML emitter → fallback parser without data loss.
     */
    public function testYamlRoundTripWithColonValue(): void
    {
        // Values containing ":" need quoting in YAML
        $this->store->set('header', 'Content-Type: text/plain');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        // The fallback emitter should have quoted the value
        $this->assertStringContainsString('"', $output);

        // Re-import the exported YAML
        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('Content-Type: text/plain', $this->store->get('header'));
    }

    public function testYamlRoundTripWithHashValue(): void
    {
        // Values containing # need quoting
        $this->store->set('comment', 'note: #this is a comment');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('note: #this is a comment', $this->store->get('comment'));
    }

    public function testYamlRoundTripWithLeadingSpace(): void
    {
        // Values with leading space need quoting
        $this->store->set('indent', '  indented');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        // Leading space should be quoted in output
        $this->assertStringContainsString('"  indented"', $output);

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('  indented', $this->store->get('indent'));
    }

    public function testYamlRoundTripWithTrailingSpace(): void
    {
        // Values with trailing space need quoting
        $this->store->set('trailing', 'value   ');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('value   ', $this->store->get('trailing'));
    }

    public function testYamlRoundTripWithPercentValue(): void
    {
        $this->store->set('pct', '100% complete');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('100% complete', $this->store->get('pct'));
    }

    public function testYamlRoundTripWithQuestionMarkValue(): void
    {
        $this->store->set('question', 'why? because');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('why? because', $this->store->get('question'));
    }

    public function testYamlRoundTripWithCommaValue(): void
    {
        $this->store->set('list', 'a, b, c');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('a, b, c', $this->store->get('list'));
    }

    public function testYamlRoundTripWithBacktickValue(): void
    {
        $this->store->set('code', '`inline code`');

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml');

        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $this->assertSame('`inline code`', $this->store->get('code'));
    }

    public function testYamlRoundTripWithTtlOnNamedDb(): void
    {
        // TTL exported with @-suffixed key should re-import correctly (Step 6)
        $this->store->set('token@mydb', 'secret', false, 3600);

        $exportCmd = new ExportCommand($this->store);
        $output = $exportCmd->exportToString('yaml', 'mydb');

        // Output should contain the TTL line with the suffixed key
        $this->assertStringContainsString('skate_ttl_token@mydb', $output);

        // Clear and re-import
        $this->store->deleteDatabase('mydb');
        $importer = new YamlImporter($this->store);
        $importer->importFromString($output, false);

        $entry = $this->store->entry('token@mydb');
        $this->assertNotNull($entry, 'TTL entry should be re-imported');
        $this->assertNotNull($entry->expiresAt, 'TTL should be preserved after round-trip');
    }

    public function testYamlFallbackParserPreservesDotInKey(): void
    {
        // Keys can legally contain dots (regex allows A-Za-z0-9_\-.@)
        $yaml = "user.name: alice\napi.v2: stable\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
        $this->assertSame('alice', $this->store->get('user.name'));
        $this->assertSame('stable', $this->store->get('api.v2'));
    }
}
