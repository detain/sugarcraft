<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Cli\ExportCommand;
use SugarCraft\Kv\Import\JsonImporter;
use SugarCraft\Kv\Import\YamlImporter;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\YamlDialect;

/**
 * Dialect contract pins for the build/parse round-trip.
 *
 * The corruption class: values with backslashes, newlines, colons, or
 * binary bytes went through the fallback emitter unescaped or leaked as
 * raw bytes into text formats, so an export→import cycle silently rewrote
 * them. Export pins run against whichever engine is live (symfony when
 * installed); the fallback emitter/parser pair — the published behaviour
 * for installs without the component — is pinned directly.
 */
final class YamlDialectTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-dialect-' . \uniqid('', true);
        \mkdir($this->tmpDir, 0o700, true);
        $this->store = new Store($this->tmpDir, 'dialect');
    }

    protected function tearDown(): void
    {
        unset($this->store);
        foreach (\glob($this->tmpDir . '/*') ?: [] as $f) {
            @\unlink($f);
        }
        @\rmdir($this->tmpDir);
    }

    /**
     * Round-trip corpus both engines must survive untouched.
     *
     * @return array<string, array{0:string,1:string}> name => [key, value]
     */
    public static function trickyPairs(): array
    {
        return [
            'backslash-path' => ['winpath', 'C:\\temp\\file.txt'],
            'multi-line' => ['note', "line one\nline two"],
            'colon-key' => ['session:abc123', '{"user":"bob"}'],
            'colon-space-value' => ['header', 'Content-Type: text/plain'],
            'hash-value' => ['frag', 'value # not a comment'],
            'empty-value' => ['blank', ''],
            'tilde-value' => ['tl', '~'],
            'null-word-value' => ['nw', 'null'],
            'bool-word-value' => ['bw', 'true'],
            'numeric-string' => ['ns', '3600'],
            'leading-space' => ['ls', '  padded'],
            'trailing-space' => ['ts', 'padded  '],
            'tab-value' => ['tb', "a\tb"],
            'quote-value' => ['qt', 'He said "hi" & \'bye\''],
            'at-key' => ['token@pw', 'secret'],
            'percent-lead' => ['pct', '%docs%'],
            'control-byte' => ['ctl', "a\x01b"],
            'utf8' => ['uni', 'héllo — 世界 🎉'],
        ];
    }

    /**
     * @param array{0:string,1:string} $pair
     * @dataProvider trickyPairs
     */
    public function testFallbackDialectRoundTripsTrickyValues(string $key, string $value): void
    {
        $yaml = YamlDialect::buildFallback([$key => $value]);
        $parsed = YamlDialect::parseFallback($yaml);

        $this->assertArrayHasKey($key, $parsed, "fallback lost the key on round-trip of {$yaml}");
        $this->assertSame($value, $parsed[$key], 'fallback corrupted the value on round-trip');
    }

    /**
     * @param array{0:string,1:string} $pair
     * @dataProvider trickyPairs
     */
    public function testSymfonyDialectRoundTripsTrickyValues(string $key, string $value): void
    {
        if (!\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->markTestSkipped('symfony/yaml not installed.');
        }

        $yaml = YamlDialect::build([$key => $value]);
        $parsed = YamlDialect::parse($yaml);

        $this->assertArrayHasKey($key, $parsed);
        $this->assertSame($value, $parsed[$key], 'symfony route corrupted the value on round-trip');
    }

    public function testFallbackReadsSymfonyOutputAndViceVersa(): void
    {
        if (!\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            $this->markTestSkipped('symfony/yaml not installed.');
        }

        foreach (self::trickyPairs() as [$key, $value]) {
            $symfonyOut = YamlDialect::build([$key => $value]);
            $fallbackRead = YamlDialect::parseFallback($symfonyOut);
            $this->assertSame($value, $fallbackRead[$key] ?? null, "fallback cannot read symfony's shape for {$key}");

            $fallbackOut = YamlDialect::buildFallback([$key => $value]);
            $symfonyRead = YamlDialect::parse($fallbackOut);
            $this->assertSame($value, $symfonyRead[$key] ?? null, "symfony cannot read fallback's shape for {$key}");
        }
    }

    public function testBuildOfEmptyMapIsEmptyString(): void
    {
        $this->assertSame('', YamlDialect::build([]));
        $this->assertSame('', YamlDialect::buildFallback([]));
    }



    public function testFallbackStillRefusesGarbageWithSyntaxError(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        YamlDialect::parseFallback('- just a list item');
    }

    public function testFallbackRefusalNamesTheLineAndReason(): void
    {
        // A bare 'Syntax error' on a large file gave nothing to fix; the
        // fallback now reports the offending line number and its defect.
        try {
            YamlDialect::parseFallback("good: 1\nalso-good: 2\nthis line has no colon at all");
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Syntax error on line 3', $e->getMessage());
            $this->assertStringContainsString('key: value', $e->getMessage());
        }
    }

    public function testFallbackSkipsWhitespaceOnlyLines(): void
    {
        // Whitespace-only lines used to reach the pair splitter as empty
        // strings and read offset 0 of nothing (a PHP warning — fatal under
        // failOnWarning, garbage in every other consumer).
        $parsed = YamlDialect::parseFallback("a: 1\n   \n\t\nb: 2\n");
        $this->assertSame(['a' => '1', 'b' => '2'], $parsed);
    }

    public function testReservedKeyReasonIsFormatScoped(): void
    {
        // The JSON envelope reserves _ttl/_binary; the YAML dialect reserves
        // the skate_* prefixes AND the legacy nested _ttl/_binary envelope
        // forms (kv-021 — a yaml export writing one reimports it as
        // metadata, so both formats reserve the exact names). Each format
        // must still refuse ONLY its own prefix family.
        $this->assertNull(YamlDialect::reservedKeyReason('skate_ttl_backup', 'json'));
        $this->assertStringContainsString('collides', (string) YamlDialect::reservedKeyReason('skate_ttl_backup', 'yaml'));
        $this->assertStringContainsString('reserved', (string) YamlDialect::reservedKeyReason('_ttl', 'json'));
        $this->assertStringContainsString('reserved', (string) YamlDialect::reservedKeyReason('_ttl', 'yaml'));
        $this->assertStringContainsString('reserved', (string) YamlDialect::reservedKeyReason('_binary', 'yaml'));
        // Legacy unscoped call still refuses both families.
        $this->assertNotNull(YamlDialect::reservedKeyReason('skate_ttl_backup'));
        $this->assertNotNull(YamlDialect::reservedKeyReason('_ttl'));
    }

    public function testJsonExportOfKeyWithSkatePrefixSucceeds(): void
    {
        // Regression: a store key starting skate_ttl_ used to block JSON
        // export entirely, although the JSON envelope never reads it.
        $this->store->set('skate_ttl_evil', 'x');
        $exportCmd = new ExportCommand($this->store);
        $decoded = \json_decode($exportCmd->exportToString('json'), true);
        $this->assertSame('x', $decoded['skate_ttl_evil']);
    }

    public function testParseRefusesNonMappingTopLevel(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        YamlDialect::parse('42');
    }

    public function testYamlExportRefusesLegacyEnvelopeKeyNames(): void
    {
        // kv-021 reproduction: store a key named `_binary`, export YAML —
        // the restore used to fail because YamlImporter consumes a top-level
        // `_binary:` line as its legacy metadata envelope. Refusing on the
        // WRITE side is what keeps every emitted document importable.
        foreach (['_ttl', '_binary'] as $reserved) {
            $this->store->set($reserved, 'x');
            $exportCmd = new ExportCommand($this->store);

            try {
                $exportCmd->exportToString('yaml');
                $this->fail($reserved . ' must be refused at the YAML write side');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('reserved by the YAML legacy metadata envelope', $e->getMessage());
                $this->assertStringContainsString($reserved, $e->getMessage(), 'refusal names the key');
            }

            // JSON refused the same names before this step; that law holds.
            try {
                $exportCmd->exportToString('json');
                $this->fail($reserved . ' must stay refused for JSON too');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('reserved by the JSON export envelope', $e->getMessage());
            }

            $this->store->delete($reserved);
        }
    }

    // ─── KV-S27 engine-divergence matrix (kv-034) ────────────────────────────
    //
    // The YamlDialect class docblock lists exactly which asymmetries the
    // shared file-level laws leave standing. This matrix ENFORCES that list:
    // every fallback leg runs always, every symfony leg is skipped only
    // when symfony is genuinely absent (kv-083's CI parity requirement).
    // If a leg below starts failing while the docblock still claims the
    // asymmetry, the docblock is a lie and must be rewritten in the same
    // commit that changes the behaviour.

    private static function symfonyAvailable(): bool
    {
        return \class_exists(\Symfony\Component\Yaml\Yaml::class);
    }

    public function testMatrixBoolTokenStaysLiteralTextOnTheFallbackRoute(): void
    {
        // Documented asymmetry (b): the fallback keeps `true` as the word,
        // so a fallback-only machine imports `k: true` as the string 'true'.
        $this->assertSame(['k' => 'true'], YamlDialect::parseFallback('k: true'));
    }

    public function testMatrixBoolTokenDecodesUnderSymfonyAndTheImporterRefuses(): void
    {
        if (!self::symfonyAvailable()) {
            $this->markTestSkipped('this leg pins the SYMFONY half of the documented bool asymmetry.');
        }

        $this->assertSame(['k' => true], \Symfony\Component\Yaml\Yaml::parse('k: true'));

        // The refusal lands at the importer door (YamlDialect docblock
        // pointer): a decoded bool is not a round-trippable scalar.
        $importer = new YamlImporter($this->store);
        try {
            $importer->importFromString('k: true');
            $this->fail('the importer must refuse a bool-valued entry');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Entry "k" has a bool value', $e->getMessage());
        }
    }

    public function testMatrixIntLandsIdenticallyOnBothRoutes(): void
    {
        // Documented symmetry (b′): a decoded int stringifies at import, so
        // the stored bytes match whichever engine read the file.
        $this->assertSame(['k' => '42'], YamlDialect::parseFallback('k: 42'));

        if (self::symfonyAvailable()) {
            $this->assertSame(['k' => 42], \Symfony\Component\Yaml\Yaml::parse('k: 42'));
        }

        $importer = new YamlImporter($this->store);
        $importer->importFromString('k: 42'); // auto-engine: symfony under CI
        $this->assertSame('42', $this->store->get('k'), 'int lands as the same stored bytes either way');
    }

    public function testMatrixColonBearingKeyRefusesOnFallbackUnderBothConfigs(): void
    {
        // Documented asymmetry (a): the fallback refuses a bare colon-led
        // key outright; symfony reads `e:f: hey` as the key `e:f`.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error on line 1: not a key: value pair');
        YamlDialect::parseFallback('e:f: hey');
    }

    public function testMatrixColonBearingKeyDecodesUnderSymfony(): void
    {
        if (!self::symfonyAvailable()) {
            $this->markTestSkipped('this leg pins the SYMFONY half of the documented colon-key asymmetry.');
        }

        $this->assertSame(['e:f' => 'hey'], \Symfony\Component\Yaml\Yaml::parse('e:f: hey'));
    }

    public function testMatrixFoldedScalarRefusesOnFallbackUnderBothConfigs(): void
    {
        // Documented asymmetry (c): symfony may fold a long plain scalar
        // across lines; the fallback refuses the continuation loudly at its
        // line instead of half-parsing a value the writer never meant.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error on line 2: not a key: value pair');
        YamlDialect::parseFallback('k: some
  folded text');
    }

    public function testMatrixFoldedScalarFoldsUnderSymfony(): void
    {
        if (!self::symfonyAvailable()) {
            $this->markTestSkipped('this leg pins the SYMFONY half of the documented fold asymmetry.');
        }

        $this->assertSame(
            ['k' => 'some folded text'],
            \Symfony\Component\Yaml\Yaml::parse('k: some
  folded text'),
        );
    }

    public function testTtlAndBinaryMetadataRideTheFlatDialectOnBothEngines(): void
    {
        $this->store->set('token@pw', 'hunter2', false, 3600);
        $this->store->set('blob@pw', "\x00\x01\xff bytes", true);

        $exportCmd = new ExportCommand($this->store);
        $yaml = $exportCmd->exportToString('yaml', 'pw');

        // Flat metadata lines — no nested maps on either route.
        // Keys may arrive quoted by symfony, hence the flexible opener.
        $this->assertMatchesRegularExpression('/skate_ttl_token@pw[\'\"]?: [0-9]+$/m', $yaml);
        $this->assertMatchesRegularExpression('/skate_binary_blob@pw[\'\"]?: 1$/m', $yaml);

        // Re-import through a fresh store and compare true content.
        $fresh = new Store($this->tmpDir . '/fresh', 'pw');
        (new YamlImporter($fresh))->importFromString($yaml, false);

        $entry = $fresh->entry('token@pw');
        $this->assertNotNull($entry);
        $this->assertGreaterThan(new \DateTimeImmutable('+3590 seconds'), $entry->expiresAt);

        $blob = $fresh->entry('blob@pw');
        $this->assertNotNull($blob);
        $this->assertTrue($blob->binary);
        $this->assertSame("\x00\x01\xff bytes", $blob->rawValue());
    }

    public function testExportRefusesKeysCollidingWithTheEnvelope(): void
    {
        $this->store->set('skate_ttl_evil', 'x');

        $exportCmd = new ExportCommand($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('collides with the YAML metadata prefixes');
        $exportCmd->exportToString('yaml');
    }

    public function testReservedKeyReasonShapes(): void
    {
        $this->assertStringContainsString('reserved', (string) YamlDialect::reservedKeyReason('_ttl'));
        $this->assertStringContainsString('reserved', (string) YamlDialect::reservedKeyReason('_binary'));
        $this->assertStringContainsString('collides', (string) YamlDialect::reservedKeyReason('skate_ttl_a'));
        $this->assertStringContainsString('collides', (string) YamlDialect::reservedKeyReason('skate_binary_a'));
        $this->assertNull(YamlDialect::reservedKeyReason('session:abc'));
    }

    public function testDecodeBinaryRefusesInvalidBase64NamingTheKey(): void
    {
        $this->assertSame('abc', YamlDialect::decodeBinary('YWJj', 'k'));

        try {
            YamlDialect::decodeBinary('!!!not b64!!!', 'broken-key');
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('broken-key', $e->getMessage());
        }
    }

    public function testAdversarialBinaryRoundTripsThroughFilesBothFormats(): void
    {
        // The M5 proof: 256 random bytes (guaranteed invalid UTF-8, control
        // bytes, quote-ish bytes) survive export→file→import intact.
        $bytes = \random_bytes(256);
        $this->store->set('chaos', $bytes, true);
        $this->store->set('note', 'plain', false, 7200);

        foreach (['json', 'yaml'] as $format) {
            $exportCmd = new ExportCommand($this->store);
            $payload = $exportCmd->exportToString($format);

            $path = $this->tmpDir . "/round.{$format}";
            \file_put_contents($path, $payload);

            $fresh = new Store($this->tmpDir . "/fresh-{$format}", 'dialect');
            $importer = $format === 'json'
                ? new JsonImporter($fresh)
                : new YamlImporter($fresh);
            $count = $importer->importFromFile($path, false)->imported;

            $this->assertSame(2, $count, "import lost entries via {$format}");
            $chaos = $fresh->entry('chaos');
            $this->assertNotNull($chaos);
            $this->assertTrue($chaos->binary, "binary flag lost via {$format}");
            $this->assertSame($bytes, $chaos->rawValue(), "{$format} corrupted raw bytes");
            $this->assertSame('plain', $fresh->get('note'));
        }
    }
}
