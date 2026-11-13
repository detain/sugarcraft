<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Cli\ExportCommand;
use SugarCraft\Kv\Import\YamlImporter;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\YamlDialect;
use PHPUnit\Framework\TestCase;

/**
 * Importer-level YAML round-trip locks.
 *
 * These tests drive ExportCommand → YamlImporter through whichever engine
 * YamlDialect selects (symfony/yaml when installed, the flat fallback
 * otherwise). The dialect contract itself — including the fallback emitter
 * and parser — is pinned engine-by-engine in YamlDialectTest.
 */
final class YamlFallbackTest extends TestCase
{
    private string $tmpDir;
    private Store $store;

    protected function setUp(): void
    {
        $this->tmpDir = \sys_get_temp_dir() . '/candy-kv-yamlfb-test-' . \uniqid();
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

    public function testImportEmptyValueRefuses(): void
    {
        // Step 48: an empty value parses to null on both routes and used to
        // coerce silently to "" — explicit "nothing" is refused now.
        $yaml = "empty-key: \n";
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "empty-key" has a null value; only strings and numbers round-trip.');
        $importer->importFromString($yaml, false);
    }

    public function testImportTildeNullValueRefuses(): void
    {
        // ~ is YAML's null
        $yaml = "nil-key: ~\n";
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "nil-key" has a null value; only strings and numbers round-trip.');
        $importer->importFromString($yaml, false);
    }

    public function testImportExplicitNullStringRefuses(): void
    {
        $yaml = "null-key: null\n";
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "null-key" has a null value; only strings and numbers round-trip.');
        $importer->importFromString($yaml, false);
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

        // The emitter must quote a ': '-bearing value on whichever engine is
        // active (symfony single-quotes, our fallback double-quotes) — pin
        // the engine-neutral property: no unquoted form may appear.
        $this->assertStringNotContainsString("header: Content-Type: text/plain\n", $output);

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

        // Leading space must be quoted on whichever engine is active
        // (symfony single-quotes, our fallback double-quotes). The unquoted
        // shape would be "indent:" + space + the value's two own spaces.
        $this->assertStringNotContainsString("indent:   indented\n", $output);

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

    // ─── KV-S12 dialect laws (forced fallback — no symfony dependency) ───────

    public function testFallbackDecodesNullSentinelsLikeSymfony(): void
    {
        // kv-017: bare empty/~/null/Null/NULL decode to NULL so the
        // importer's refuse-null law fires identically under both engines;
        // quoted forms stay deliberate strings.
        $parsed = YamlDialect::parseFallback("e:\nt: ~\nn: null\nN: Null\nq: \"~\"\nz: \"\"\n");

        $this->assertSame(['e' => null, 't' => null, 'n' => null, 'N' => null, 'q' => '~', 'z' => ''], $parsed);
    }

    public function testFallbackImportRefusesNullSentinelNamingTheEntry(): void
    {
        // The engine parity the law promises: on either engine the same
        // document refuses with the same message.
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Entry "tilde" has a null value; only strings and numbers round-trip.');
        $importer->importFromString("tilde: ~\n", false);
    }

    public function testFallbackStripsValueCommentsKeepsLiteralHashes(): void
    {
        // kv-018: a '#' introduced by whitespace starts a comment (plain or
        // after a closing quote); 'plain#keep' has no space before the hash
        // and keeps it.
        $parsed = YamlDialect::parseFallback("k: foo # bar\nj: plain#keep\nh: # c\nqt: \"v\" # tail\n");

        $this->assertSame(['k' => 'foo', 'j' => 'plain#keep', 'h' => null, 'qt' => 'v'], $parsed);
    }

    public function testFallbackAndParseRefuseMidLineCarriageReturn(): void
    {
        // kv-012: a lone CR is a LINE BREAK to symfony and invisible
        // garbage to the fallback — the same file meant two different
        // imports. Refused on both routes now, naming the line; whole-file
        // CRLF stays legal (trim eats the trailing CR).
        $doc = "a: 1\rb: 2\r";
        foreach ([YamlDialect::parseFallback(...), YamlDialect::parse(...)] as $route) {
            try {
                $route($doc);
                $this->fail('expected RuntimeException for mid-line CR');
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('carriage return', $e->getMessage());
                $this->assertStringContainsString('line 1', $e->getMessage());
            }
        }

        $this->assertSame(
            ['a' => '1', 'b' => '2'],
            YamlDialect::parseFallback("a: 1\r\nb: 2\r\n"),
            'CRLF documents must keep importing',
        );
    }

    public function testFallbackImportOfCrDocumentRefusesNamingTheLine(): void
    {
        // The acceptance shape: importFromString("a: 1\rb: 2\r") errors.
        $importer = new YamlImporter($this->store);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('line 1');
        $importer->importFromString("a: 1\rb: 2\r", false);
    }

    public function testFallbackRefusesDuplicateKeyNamingBothLines(): void
    {
        // kv-078: repeated keys silently last-won on the fallback while
        // symfony throws — the file imported different values depending on
        // an optional package.
        try {
            YamlDialect::parseFallback("k: 1\nj: 2\nk: 3");
            $this->fail('expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('duplicate key "k"', $e->getMessage());
            $this->assertStringContainsString('line 3', $e->getMessage());
            $this->assertStringContainsString('line 1', $e->getMessage());
        }
    }

    public function testParseRouteAlsoRefusesDuplicateKeys(): void
    {
        // Engine-independent: symfony throws its own duplicate-key error and
        // the wrapper keeps it a 'Syntax error'; the fallback refuses above.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error');
        YamlDialect::parse("k: 1\nj: 2\nk: 3");
    }

    public static function indicatorCorpus(): array
    {
        return [
            'sequence' => ['- x'],
            'bare-dash' => ['-'],
            'flow-map' => ['{a: 1}'],
            'flow-seq' => ['[a]'],
            'alias' => ['*anc'],
            'anchor' => ['&anc v'],
            'folded' => ['>'],
            'literal' => ['|'],
            'percent' => ['%directive'],
            'at' => ['@reserved'],
            'backtick' => ['`reserved'],
            'tag' => ['!!str x'],
        ];
    }

    /**
     * @param string $value
     * @dataProvider indicatorCorpus
     */
    public function testFallbackRefusesIndicatorStructureValues(string $value): void
    {
        // kv-071: structure to one engine, literal text to the other —
        // refused before the engine is chosen. Quoting is the escape hatch.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('is YAML structure');
        YamlDialect::parseFallback("k: $value");
    }

    public function testIndicatorLawCoversKeyPositionToo(): void
    {
        // '- a: b' is a block sequence wrapping a mapping to symfony and a
        // literal key '- a' to the old fallback.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('a key starting with "-"');
        YamlDialect::parseFallback('- a: b');
    }

    public function testNegativeNumbersAreNotSequences(): void
    {
        // The dash only indicts when followed by space/end, so '-5' keeps
        // its plain-scalar reading on both engines.
        $parsed = YamlDialect::parseFallback("k: -5\nj: -3.25");

        $this->assertSame(['k' => '-5', 'j' => '-3.25'], $parsed);
    }

    public function testFallbackDecodesFullDoubleQuoteEscapeSet(): void
    {
        // kv-072: the old machine re-emitted unknown escapes as literal
        // \q (symfony refuses) and only knew six escapes.
        $parsed = YamlDialect::parseFallback(
            'k: "\0\a\b\t\n\v\f\r\e\ \"\/\\\\\N\_\L\P\x41\u00e9\U0001F600"'
        );

        $expected = "\x00\x07\x08\t\n\x0B\x0C\r\x1B" . ' ' . '"' . '/' . "\\"
            . "\u{0085}\u{00A0}\u{2028}\u{2029}" . 'Aé😀';
        $this->assertSame($expected, $parsed['k']);
    }

    public function testFallbackPairsSurrogatesAndRefusesBadOnes(): void
    {
        // JSON surrogate rules: high+low pair to a real codepoint; an
        // unpaired high, a lone low, or >U+10FFFF all throw.
        $paired = YamlDialect::parseFallback('k: "\ud83d\ude00"');
        $this->assertSame('😀', $paired['k']);

        foreach (['k: "\ud83d"', 'k: "\udeee"', 'k: "\U00110000"', 'k: "\ud83dx"', 'k: "a\"'] as $doc) {
            try {
                YamlDialect::parseFallback($doc);
                $this->fail("expected refusal for $doc");
            } catch (\RuntimeException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testFallbackRefusesUnknownAndMalformedEscapes(): void
    {
        // The old machine RE-EMITTED '\\q' as literal text where symfony
        // refuses the document — two values for one file.
        foreach (['k: "a\\qb"', 'k: "x\\uZZZZ"', 'k: "\\u{1}"'] as $doc) {
            try {
                YamlDialect::parseFallback($doc);
                $this->fail("expected refusal for $doc");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString('escape', $e->getMessage());
            }
        }
    }

    public function testFallbackReadsSymfonyBinaryTagButRefusesOtherTags(): void
    {
        // !!binary is symfony's inline control-byte shape and must keep
        // working; every other tag means coercion the dialect never offers.
        $parsed = YamlDialect::parseFallback("ctl: !!binary YQFi\n");
        $this->assertSame(['ctl' => "a\x01b"], $parsed);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Syntax error on line 1');
        YamlDialect::parseFallback("k: !!str x\n");
    }

    // ─── KV-S27 fallback error-branch corpus (kv-083) ────────────────────────

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function fallbackErrorBranches(): array
    {
        return [
            'unterminated quoted value' => ['k: "abc', 'Syntax error on line 1: unterminated quoted string'],
            'unterminated quoted key' => ['"abc: v', 'Syntax error on line 1: unterminated quoted string'],
            'quoted key without colon' => ['"k" v', 'Syntax error on line 1: expected \':\' after the quoted key'],
            'quoted key trailing junk' => ['"k"x: v', 'Syntax error on line 1: expected \':\' after the quoted key'],
            'space-padded key' => ['k : v', 'Syntax error on line 1: missing key'],
            'colon-led line' => [': v', 'Syntax error on line 1: not a key: value pair'],
            'bare sequence line' => ['- a', 'Syntax error on line 1: sequence entries are not part of this dialect'],
            'junk after quoted value' => ['k: "v"x', 'Syntax error on line 1: unexpected text after the closing quote'],
            'binary tag broken base64' => ['k: !!binary ***', 'Syntax error on line 1: invalid base64 in a !!binary value'],
            'second line names itself' => ["k: 1\nbroken pair here", 'Syntax error on line 2: not a key: value pair'],
            'deep line numbering' => ["a: 1\nb: 2\n\n# c\nstill-broken-pair\n", 'Syntax error on line 5: not a key: value pair'],
        ];
    }

    /**
     * Every fallback error branch names its line and its reason (kv-083):
     * the old machine threw one bare 'Syntax error' for a 500-line file.
     * Exact-match on the full message, so the prefix law itself is pinned.
     *
     * @dataProvider fallbackErrorBranches
     */
    public function testFallbackErrorBranchNamesLineAndReason(string $doc, string $message): void
    {
        try {
            YamlDialect::parseFallback($doc);
            $this->fail("expected refusal for $doc");
        } catch (\RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }
}
