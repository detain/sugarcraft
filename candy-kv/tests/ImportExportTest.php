<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Import\JsonImporter;
use SugarCraft\Kv\Import\YamlImporter;
use SugarCraft\Kv\Store;
use PHPUnit\Framework\TestCase;

final class ImportExportTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-ie-test-' . \uniqid();
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

    // ─── JsonImporter ─────────────────────────────────────────────────────────

    public function testJsonImporterFromFile(): void
    {
        $path = $this->tmpDir . '/import.json';
        \file_put_contents($path, '{"color":"blue","size":"large"}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('blue', $this->store->get('color'));
        $this->assertSame('large', $this->store->get('size'));
    }

    public function testJsonImporterFromString(): void
    {
        $json = '{"a":"1","b":"2"}';
        $importer = new JsonImporter($this->store);
        $count = $importer->importFromString($json, false);

        $this->assertSame(2, $count);
    }

    public function testJsonImporterWithTtl(): void
    {
        $path = $this->tmpDir . '/ttl.json';
        \file_put_contents($path, \json_encode([
            '_ttl' => ['timed-key' => 3600],
            'timed-key' => 'timed-value',
        ]));

        $importer = new JsonImporter($this->store);
        $importer->importFromFile($path, false);

        $entry = $this->store->entry('timed-key');
        $this->assertNotNull($entry->expiresAt);
        $this->assertSame('timed-value', $this->store->get('timed-key'));
    }

    public function testJsonImporterAtomic(): void
    {
        $path = $this->tmpDir . '/atomic.json';
        \file_put_contents($path, '{"x":"1","y":"2","z":"3"}');

        $importer = new JsonImporter($this->store);
        $importer->importFromFile($path, true);

        $this->assertSame('1', $this->store->get('x'));
        $this->assertSame('2', $this->store->get('y'));
        $this->assertSame('3', $this->store->get('z'));
    }

    public function testJsonImporterWithDbSuffix(): void
    {
        $path = $this->tmpDir . '/dbsuffix.json';
        \file_put_contents($path, '{"token@pw":"secret","user@meta":"alice"}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('secret', $this->store->get('token@pw'));
        $this->assertSame('alice', $this->store->get('user@meta'));
    }

    public function testJsonImporterAtomicMultiDatabaseThrows(): void
    {
        $path = $this->tmpDir . '/multi-db.json';
        \file_put_contents($path, '{"token@pw":"secret","user@meta":"alice"}');

        $importer = new JsonImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic import is not supported across multiple databases');
        $importer->importFromFile($path, true);
    }

    public function testJsonImporterCoercesScalarsRefusesComplexValues(): void
    {
        // Numeric JSON scalars import as text; null, arrays and nested
        // objects refuse loudly — silently skipping them let a typo'd
        // document "import" a fraction of itself with a clean exit.
        $path = $this->tmpDir . '/mixed.json';
        \file_put_contents($path, '{"str":"value","num":123,"arr":[1,2,3],"obj":{"a":1}}');

        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "arr" has a array value; only strings and numbers round-trip.');
        $importer->importFromFile($path, false);
    }

    public function testJsonImporterRefusesNullValue(): void
    {
        // A null once coerced silently to an empty string; explicit "nothing"
        // is not "" and must not masquerade as a stored empty value.
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "k" has a null value; only strings and numbers round-trip.');
        $importer->importFromString('{"k":null}', false);
    }

    public function testJsonImporterRefusesComplexValuesBeforeWritingAnything(): void
    {
        // The refusal happens in the validation pre-pass: a document with a
        // bad entry imports NOTHING, not a prefix of itself.
        $path = $this->tmpDir . '/mixed2.json';
        \file_put_contents($path, '{"good":"kept","bad":{"a":1}}');

        $importer = new JsonImporter($this->store);
        try {
            $importer->importFromFile($path, false);
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('has a array value', $e->getMessage());
        }
        $this->assertSame('', $this->store->get('good'));
    }

    public function testJsonImporterRefusesTopLevelList(): void
    {
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('got a list');
        $importer->importFromString('["a","b"]', false);
    }

    public function testYamlImporterRefusesTopLevelSequence(): void
    {
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        // symfony route: the parse hands us a list and the importer refuses
        // it; fallback route: the dialect parser refuses sequence lines.
        $this->expectExceptionMessageMatches('/got a sequence|sequence entries are not part of/');
        $importer->importFromString("- a\n- b\n", false);
    }

    public function testJsonImporterRefusesNonIntegerTtl(): void
    {
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_ttl entry "k" must be an integer');
        $importer->importFromString('{"_ttl":{"k":"soon"},"k":"v"}', false);
    }

    public function testJsonImporterRefusesZeroTtl(): void
    {
        // A zero TTL silently meant "forever" — the opposite of what the
        // document asked for. The CLI refuses the same spelling.
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must be at least 1 second');
        $importer->importFromString('{"_ttl":{"k":0},"k":"v"}', false);
    }

    public function testJsonImporterRefusesFractionalTtl(): void
    {
        // is_numeric used to admit 1.5 and (int) truncated it to one second
        // — the document's stated lifetime shaved silently.
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_ttl entry "k" must be an integer number of seconds, got 1.5.');
        $importer->importFromString('{"_ttl":{"k":1.5},"k":"v"}', false);
    }

    public function testJsonImporterRefusesFractionalStringTtl(): void
    {
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_ttl entry "k" must be an integer number of seconds, got 60.5.');
        $importer->importFromString('{"_ttl":{"k":"60.5"},"k":"v"}', false);
    }

    public function testJsonImporterRefusesScalarTtlEnvelope(): void
    {
        // A scalar "_ttl" used to fall through the envelope branch and
        // import as a stray literal data key that export can never write
        // back (reserved-name refusal makes it a dead key).
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_ttl must be a map of key → seconds, got int.');
        $importer->importFromString('{"_ttl":60,"k":"v"}', false);
    }

    public function testJsonImporterRefusesScalarBinaryEnvelope(): void
    {
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_binary must be a list of key names, got string.');
        $importer->importFromString('{"_binary":"k","k":"QUJD"}', false);
    }

    public function testJsonImporterRefusesNonStringBinaryMember(): void
    {
        // A skipped non-string member silently demoted one intended binary
        // entry to literal base64 text.
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_binary must list key names as strings, got int.');
        $importer->importFromString('{"_binary":["k",5],"k":"QUJD"}', false);
    }

    public function testAtomicRosterSeesNumericValuesOnSecondDatabase(): void
    {
        // The old roster skipped non-string values while the write loop
        // coerced them: this document reached a SECOND database outside any
        // transaction without ever tripping the multi-database refusal.
        $importer = new JsonImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic import is not supported across multiple databases');
        $importer->importFromString('{"a@roster1":"x","b@roster2":5}', true);
    }

    /**
     * A non-array top-level JSON must be rejected loudly via sugar-core's
     * guarded Json::decodeArray() rather than silently iterating a scalar as
     * an empty foreach (which previously masqueraded as a 0-entry import).
     *
     * @dataProvider nonArrayTopLevelProvider
     */
    public function testJsonImporterRejectsNonArrayTopLevel(string $json): void
    {
        $importer = new JsonImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected JSON top level to be an array');
        $importer->importFromString($json, false);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonArrayTopLevelProvider(): iterable
    {
        yield 'bare int' => ['42'];
        yield 'bare string' => ['"str"'];
        yield 'bare true' => ['true'];
        yield 'bare null' => ['null'];
        yield 'bare float' => ['3.14'];
    }

    public function testJsonImporterRejectsNonArrayTopLevelFromFile(): void
    {
        $path = $this->tmpDir . '/scalar.json';
        \file_put_contents($path, '42');

        $importer = new JsonImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected JSON top level to be an array');
        $importer->importFromFile($path, false);
    }

    public function testJsonImporterThrowsOnMalformedJson(): void
    {
        $importer = new JsonImporter($this->store);

        $this->expectException(\JsonException::class);
        $importer->importFromString('{not valid json', false);
    }

    // ─── YamlImporter ─────────────────────────────────────────────────────────

    public function testYamlImporterFromFile(): void
    {
        $path = $this->tmpDir . '/import.yaml';
        \file_put_contents($path, "color: blue\nsize: large\n");

        $importer = new YamlImporter($this->store);
        $count = $importer->importFromFile($path, false);

        $this->assertSame(2, $count);
        $this->assertSame('blue', $this->store->get('color'));
        $this->assertSame('large', $this->store->get('size'));
    }

    public function testYamlImporterFromString(): void
    {
        $yaml = "a: 1\nb: 2\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
    }

    public function testYamlImporterWithTtl(): void
    {
        $path = $this->tmpDir . '/ttl.yaml';
        \file_put_contents($path, "skate_ttl_timed-key: 3600\ntimed-key: timed-value\n");

        $importer = new YamlImporter($this->store);
        $importer->importFromFile($path, false);

        $entry = $this->store->entry('timed-key');
        $this->assertNotNull($entry->expiresAt);
    }

    public function testYamlImporterRefusesFractionalTtlLine(): void
    {
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('TTL for entry "k" must be an integer number of seconds, got 60.5.');
        $importer->importFromString("k: v\nskate_ttl_k: 60.5\n", false);
    }

    public function testYamlImporterRefusesScalarLegacyTtlEnvelope(): void
    {
        // A scalar `_ttl:` fell through the legacy-envelope branch and
        // imported as a stray literal data key the exporter can never
        // round-trip.
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_ttl must be a map of key → seconds, got int.');
        $importer->importFromString("_ttl: 60\nk: v\n", false);
    }

    public function testYamlImporterRefusesScalarLegacyBinaryEnvelope(): void
    {
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('_binary must be a list of key names, got string.');
        $importer->importFromString("_binary: k\nk: QUJD\n", false);
    }

    public function testYamlImporterRefusesTextBinaryFlag(): void
    {
        // (bool)(int)'text' evaluated to 0: the flag dropped silently and
        // the base64 payload imported as literal text — the exact corruption
        // the fail-fast headline promises to refuse.
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Binary flag for entry "k" must be 0 or 1');
        $importer->importFromString("k: QUJD\nskate_binary_k: text\n", false);
    }

    public function testYamlImporterHonoursZeroOneBinaryFlags(): void
    {
        // Positive control: the shape export writes (1) still imports, and
        // an explicit 0 means plain text rather than a refusal.
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString(
            "bin: QUJD\nskate_binary_bin: 1\ntxt: hello\nskate_binary_txt: 0\n",
            false,
        );
        $this->assertSame(2, $count);
        $this->assertTrue($this->store->entry('bin')->binary);
        $this->assertSame('ABC', $this->store->entry('bin')->rawValue());
        $this->assertFalse($this->store->entry('txt')->binary);
        $this->assertSame('hello', $this->store->get('txt'));
    }

    public function testYamlImporterRefusesEmptyValue(): void
    {
        // `k:` parses to null on both YAML routes; it once coerced to an
        // empty string entry. Explicit "nothing" must refuse, like JSON.
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "k" has a null value; only strings and numbers round-trip.');
        $importer->importFromString("k:\n", false);
    }

    public function testYamlImporterAtomicMultiDatabaseThrows(): void
    {
        $path = $this->tmpDir . '/multi-db.yaml';
        \file_put_contents($path, "token@pw: secret\nuser@meta: alice\n");

        $importer = new YamlImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Atomic import is not supported across multiple databases');
        $importer->importFromFile($path, true);
    }

    // ─── Export ────────────────────────────────────────────────────────────────

    public function testJsonExportRoundTrip(): void
    {
        // Formerly called run(), which writes with fwrite(STDOUT) — noise the
        // runner's output buffer cannot capture — then asserted nothing about
        // the export itself (audit #15). Now asserts real CONTENT and an
        // actual round trip; run()'s stdout path is child-process-covered in
        // CliSmokeTest::testExportRunWritesJsonEntriesToStdout.
        $this->store->set('x', '1');
        $this->store->set('y', '2');

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $json = $cli->exportToString('json');

        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded, 'export output must be valid JSON');
        $this->assertSame('1', $decoded['x'] ?? null);
        $this->assertSame('2', $decoded['y'] ?? null);

        // Round trip: importing the export into a fresh store reproduces it.
        $dirB = $this->tmpDir . '/roundtrip';
        \mkdir($dirB, 0o700, true);
        $storeB = new Store($dirB, 'testdb');
        $count = (new JsonImporter($storeB))->importFromString($json, false);
        $this->assertSame(2, $count);
        $this->assertSame('1', $storeB->get('x'));
        $this->assertSame('2', $storeB->get('y'));
    }

    public function testJsonExportWithTtl(): void
    {
        // Asserts the EXPORT PAYLOAD carries the TTL (the old body's
        // "verify export output" loop re-queried the store, not the output).
        $this->store->set('t', 'v', false, 3600);

        $exportCmd = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $decoded = \json_decode($exportCmd->exportToString('json'), true);

        $this->assertIsArray($decoded);
        $this->assertSame('v', $decoded['t'] ?? null);
        $this->assertArrayHasKey('_ttl', $decoded, 'remaining TTL must ride the export payload');
        $this->assertArrayHasKey('t', $decoded['_ttl']);
        $this->assertGreaterThan(3000, $decoded['_ttl']['t'], 'exported TTL should be ~1h of remaining seconds');
        $this->assertLessThanOrEqual(3600, $decoded['_ttl']['t']);
    }

    public function testJsonExportOfEmptyStoreIsAnObjectNotAList(): void
    {
        // The documented envelope is a mapping. `json_encode([])` would
        // dump an empty store as `[]` — list shape — and consumers that
        // trust the object contract (jq, python, a foreign importer) choke.
        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);

        $this->assertSame('{}' . \PHP_EOL, $cli->exportToString('json'));
    }

    // ─── ExportCommand error paths ─────────────────────────────────────────────

    public function testExportToStringThrowsOnUnknownFormat(): void
    {
        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Unknown export format: csv");
        $cli->exportToString('csv');
    }

    public function testExportToStringWithDbName(): void
    {
        $this->store->set('key@region', 'value');

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $output = $cli->exportToString('json', 'region');

        $this->assertStringContainsString('key@region', $output);
        $this->assertStringContainsString('value', $output);
    }

    public function testExportToStringWithPattern(): void
    {
        $this->store->set('temp-1', 'a');
        $this->store->set('temp-2', 'b');
        $this->store->set('keep', 'c');

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $output = $cli->exportToString('json', null, 'temp-*');

        $decoded = \json_decode($output, true);
        $this->assertArrayHasKey('temp-1', $decoded);
        $this->assertArrayHasKey('temp-2', $decoded);
        $this->assertArrayNotHasKey('keep', $decoded);
    }

    public function testExportToStringYamlWithDbName(): void
    {
        $this->store->set('token@mydb', 'secret', false, 3600);

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $output = $cli->exportToString('yaml', 'mydb');

        // YAML export should include the TTL prefix line
        $this->assertStringContainsString('skate_ttl_token@mydb', $output);
        $this->assertStringContainsString('token@mydb', $output);
    }

    public function testExportEscapesLiteralAtSoTheDocumentRoundTrips(): void
    {
        // A stored key carrying a literal @ used to export bare ("p@q"),
        // and a pristine import split it — key "p" landing in a phantom
        // database "q" with a clean exit. Export now writes the escaped
        // spelling the key law defines, so the dump reimports into the
        // same key and database it came from.
        $this->store->set('p\\@q', 'v');
        $this->assertSame('v', $this->store->get('p\\@q'));

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $json = $cli->exportToString('json');
        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame(['p\\@q' => 'v'], $decoded);

        $dirB = $this->tmpDir . '/escaped-roundtrip';
        \mkdir($dirB, 0o700, true);
        $storeB = new Store($dirB, 'testdb');
        $count = (new JsonImporter($storeB))->importFromString($json, false);
        $this->assertSame(1, $count);
        $this->assertSame('v', $storeB->get('p\\@q'));
        // The phantom database must never be minted by the reimport, and
        // the head of the split must not exist either.
        $this->assertFileDoesNotExist($dirB . '/q.db');
        $this->assertSame('', $storeB->get('p'));
    }

    public function testExportUnderNamedDatabaseEscapesEntryAndKeepsSuffixRaw(): void
    {
        // The suffix leg stays unescaped: "a\@b@logs" spells entry "a@b"
        // in database "logs". Quoting the separator too would strand the
        // key in the default database and mint "logs" nowhere.
        $this->store->set('a\\@b@logs', 'v');

        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);
        $json = $cli->exportToString('json', 'logs');
        $decoded = \json_decode($json, true);
        $this->assertSame(['a\\@b@logs' => 'v'], $decoded);

        $dirB = $this->tmpDir . '/suffix-roundtrip';
        \mkdir($dirB, 0o700, true);
        $storeB = new Store($dirB, 'testdb');
        (new JsonImporter($storeB))->importFromString($json, false);
        $this->assertSame('v', $storeB->get('a\\@b@logs'));
        $this->assertFileExists($dirB . '/logs.db');
    }

    public function testImportRefusesTwoUnescapedAtSignsLoudly(): void
    {
        // The law has teeth on the read side too: a hand-edited document
        // whose key spells "a@b@logs" (two unescaped @) is ambiguous and
        // refuses rather than guessing a split.
        $importer = new JsonImporter($this->store);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Entry key 'a@b' cannot contain @");
        $importer->importFromString('{"a@b@logs":"v"}', false);
    }

    public function testExportRunReturnsTwoForUnknownFormat(): void
    {
        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);

        // Unknown format is a usage error under the strict contract: exit 2.
        $exitCode = $cli->run('invalid-format');
        $this->assertSame(2, $exitCode);
    }

    public function testExportRunReturnsOneOnFailure(): void
    {
        $cli = new \SugarCraft\Kv\Cli\ExportCommand($this->store);

        // The runtime-failure leg (exit 1) stays reachable: a database name
        // the store refuses (invalid spelling) is a failure to report, not a
        // usage exit 2 — the format itself was fine.
        $exitCode = $cli->run('json', 'not a valid db name');
        $this->assertSame(1, $exitCode);
    }

    // ─── YAML import parser errors ──────────────────────────────────────────

    public function testYamlParserThrowsOnSyntaxError(): void
    {
        // Both engines surface "Syntax error" for malformed YAML
        $yaml = "[this is definitely not valid yaml: {nested";
        $importer = new YamlImporter($this->store);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        $importer->importFromString($yaml, false);
    }

    public function testYamlParserHandlesDocumentMarkers(): void
    {
        // Document markers --- and ... should be skipped
        $yaml = "---\nkey1: value1\n...\n---\nkey2: value2\n";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(2, $count);
        $this->assertSame('value1', $this->store->get('key1'));
        $this->assertSame('value2', $this->store->get('key2'));
    }

    public function testYamlParserHandlesComments(): void
    {
        $yaml = "# This is a comment\nkey: value\n# Another comment";
        $importer = new YamlImporter($this->store);
        $count = $importer->importFromString($yaml, false);

        $this->assertSame(1, $count);
        $this->assertSame('value', $this->store->get('key'));
    }

    // ─── JsonImporter with TTL constructor options ───────────────────────────

    public function testJsonImporterWithTtlConstructorOption(): void
    {
        $json = '{"opt-key": "opt-value"}';
        $importer = new JsonImporter($this->store, ['ttl' => ['opt-key' => 1800]]);
        $importer->importFromString($json, false);

        $entry = $this->store->entry('opt-key');
        $this->assertNotNull($entry->expiresAt);
    }

    public function testJsonImporterTtlOptionsMergeWithFileTtl(): void
    {
        // Constructor TTL + file TTL should merge; file takes precedence
        $path = $this->tmpDir . '/merge.json';
        \file_put_contents($path, \json_encode([
            '_ttl' => ['file-key' => 7200],
            'file-key' => 'file-value',
            'opt-key' => 'opt-value',
        ]));

        // opt-key has TTL from constructor, file-key has TTL from file
        $importer = new JsonImporter($this->store, ['ttl' => ['opt-key' => 1800]]);
        $importer->importFromFile($path, false);

        $fileEntry = $this->store->entry('file-key');
        $optEntry = $this->store->entry('opt-key');

        $this->assertNotNull($fileEntry->expiresAt);
        $this->assertNotNull($optEntry->expiresAt);
    }

    public function testJsonImporterConstructorTtlMatchesFullDocumentKeySpelling(): void
    {
        // The ttl option is keyed by the FULL document-key spelling (the
        // '@db' suffix included) — that is the string the row loop resolves.
        $importer = new JsonImporter($this->store, ['ttl' => ['k@other' => 1800]]);
        $importer->importFromString('{"k@other":"v"}', false);
        $this->assertNotNull($this->store->entry('k@other')->expiresAt);

        $bare = new JsonImporter($this->store, ['ttl' => ['k@x' => 1800]]);
        $bare->importFromString('{"k":"v"}', false);
        $this->assertNull($this->store->entry('k')->expiresAt, 'a suffix-keyed map must not leak onto the bare key');
    }

    // ─── JsonImporter single-database atomic path ─────────────────────────────

    public function testJsonImporterAtomicSingleDatabaseCommits(): void
    {
        $path = $this->tmpDir . '/single-atomic.json';
        \file_put_contents($path, '{"a":"1","b":"2","c":"3"}');

        $importer = new JsonImporter($this->store);
        $count = $importer->importFromFile($path, true);

        $this->assertSame(3, $count);
        $this->assertSame('1', $this->store->get('a'));
        $this->assertSame('2', $this->store->get('b'));
        $this->assertSame('3', $this->store->get('c'));
    }

    // ─── YamlImporter atomic single-database path ───────────────────────────

    public function testYamlImporterAtomicSingleDatabaseCommits(): void
    {
        $path = $this->tmpDir . '/single-atomic.yaml';
        \file_put_contents($path, "x: 1\ny: 2\n");

        $importer = new YamlImporter($this->store);
        $count = $importer->importFromFile($path, true);

        $this->assertSame(2, $count);
        $this->assertSame('1', $this->store->get('x'));
        $this->assertSame('2', $this->store->get('y'));
    }
}
