<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use SugarCraft\Kv\Cli\ArgParser;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CLI argument parser.
 *
 * Verifies parsing of all candy-kv subcommands: set, list, import, export.
 */
final class CliArgParserTest extends TestCase
{
    // ─── set ─────────────────────────────────────────────────────────────────

    public function testSetOptsKeyOnly(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertNull($result['value']);
        $this->assertNull($result['ttl']);
    }

    public function testSetOptsKeyAndValue(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', 'myvalue'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('myvalue', $result['value']);
        $this->assertNull($result['ttl']);
    }

    public function testSetOptsWithTtl(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl=3600'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testSetOptsWithTtlAndValue(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', 'myvalue', '--ttl=7200'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('myvalue', $result['value']);
        $this->assertSame(7200, $result['ttl']);
    }

    public function testSetOptsKeyWithDbSuffix(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'token@passwords', 'hunter2'], 2);
        $this->assertSame('token@passwords', $result['key']);
        $this->assertSame('hunter2', $result['value']);
    }

    public function testSetUnknownFlagIsRefused(): void
    {
        // Strict contract: a flag the verb does not know is a usage error, never a silent no-op.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');
        ArgParser::set(['candy-kv', 'set', 'key', 'val', '--no-atomic'], 2);
    }

    public function testSetOptsEmptyReturnsNulls(): void
    {
        $result = ArgParser::set(['candy-kv', 'set'], 2);
        $this->assertNull($result['key']);
        $this->assertNull($result['value']);
        $this->assertNull($result['ttl']);
    }

    // ─── list ─────────────────────────────────────────────────────────────────

    public function testListOptsAllMode(): void
    {
        $result = ArgParser::list(['candy-kv', 'list'], 2);
        $this->assertSame('all', $result['mode']);
        $this->assertFalse($result['reverse']);
        $this->assertSame("\t", $result['delimiter']);
        $this->assertNull($result['pattern']);
    }

    public function testListOptsKeysOnly(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-k'], 2);
        $this->assertSame('keys', $result['mode']);
    }

    public function testListOptsValuesOnly(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-v'], 2);
        $this->assertSame('values', $result['mode']);
    }

    public function testListOptsReverse(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-r'], 2);
        $this->assertTrue($result['reverse']);
    }

    public function testListOptsCustomDelimiter(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-d|'], 2);
        $this->assertSame('|', $result['delimiter']);
    }

    public function testListOptsCustomDelimiterEmpty(): void
    {
        // kv-100 FLIP: this pin used to codify the silent tab default on a
        // trailing -d. The delimiter must be visible; a half-typed command
        // is a usage error, and omission of -d is the route to tab.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Option -d requires a delimiter value.');
        ArgParser::list(['candy-kv', 'list', '-d'], 2);
    }

    public function testListOptsPattern(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', 'temp-*'], 2);
        $this->assertSame('temp-*', $result['pattern']);
    }

    public function testListOptsAllFlagsCombined(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-k', '-r', '-d:', 'user-*'], 2);
        $this->assertSame('keys', $result['mode']);
        $this->assertTrue($result['reverse']);
        $this->assertSame(':', $result['delimiter']);
        $this->assertSame('user-*', $result['pattern']);
    }

    // ─── import ───────────────────────────────────────────────────────────────

    public function testImportOptsFormatAndPath(): void
    {
        $result = ArgParser::import(['candy-kv', 'import', 'json', 'data.json'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertSame('data.json', $result['path']);
        $this->assertTrue($result['atomic']);
    }

    public function testImportOptsNoAtomic(): void
    {
        $result = ArgParser::import(['candy-kv', 'import', 'json', 'data.json', '--no-atomic'], 2);
        $this->assertFalse($result['atomic']);
    }

    public function testImportOptsIfExistsDefaultsToReplace(): void
    {
        // candy-kv-051: the documented default must be the parse default,
        // not a lucky coincidence downstream.
        $result = ArgParser::import(['candy-kv', 'import', 'json', 'data.json'], 2);
        $this->assertSame('replace', $result['ifExists']);
    }

    public function testImportOptsAcceptsEveryIfExistMode(): void
    {
        foreach (['skip', 'fail', 'replace'] as $mode) {
            $result = ArgParser::import(['candy-kv', 'import', 'json', 'd.json', '--if-exists=' . $mode], 2);
            $this->assertSame($mode, $result['ifExists']);
        }
    }

    public function testImportOptsUnknownIfExistModeRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown --if-exists mode "delete". Use skip, fail or replace.');
        ArgParser::import(['candy-kv', 'import', 'json', 'd.json', '--if-exists=delete'], 2);
    }

    public function testImportOptsIfExistModeIsScrubbedInRefusal(): void
    {
        // kv-004 law: the echoed bytes are argv-chosen; the refusal must not
        // carry a raw ESC through to the terminal.
        try {
            ArgParser::import(['candy-kv', 'import', 'json', 'd.json', "--if-exists=\x1b[31mX"], 2);
            $this->fail('unknown mode must refuse');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringNotContainsString("\x1b", $e->getMessage());
        }
    }

    public function testImportOptsYamlFormat(): void
    {
        $result = ArgParser::import(['candy-kv', 'import', 'yaml', 'data.yaml'], 2);
        $this->assertSame('yaml', $result['format']);
    }

    public function testImportOptsStdIn(): void
    {
        $result = ArgParser::import(['candy-kv', 'import', 'json', '-'], 2);
        $this->assertSame('-', $result['path']);
    }

    public function testImportOptsMissingParts(): void
    {
        $result = ArgParser::import(['candy-kv', 'import'], 2);
        $this->assertNull($result['format']);
        $this->assertNull($result['path']);
        $this->assertTrue($result['atomic']);
    }

    // ─── export ───────────────────────────────────────────────────────────────

    public function testExportOptsFormatOnly(): void
    {
        $result = ArgParser::export(['candy-kv', 'export', 'json'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertNull($result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportOptsFormatAndDb(): void
    {
        $result = ArgParser::export(['candy-kv', 'export', 'json', 'mydb'], 2);
        $this->assertSame('json', $result['format']);
        $this->assertSame('mydb', $result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportOptsAllPositionalArgs(): void
    {
        $result = ArgParser::export(['candy-kv', 'export', 'yaml', 'mydb', 'key-*'], 2);
        $this->assertSame('yaml', $result['format']);
        $this->assertSame('mydb', $result['db']);
        $this->assertSame('key-*', $result['pattern']);
    }

    public function testExportOptsYamlFormat(): void
    {
        $result = ArgParser::export(['candy-kv', 'export', 'yml'], 2);
        $this->assertSame('yml', $result['format']);
    }

    public function testExportOptsEmptyReturnsNulls(): void
    {
        $result = ArgParser::export(['candy-kv', 'export'], 2);
        $this->assertNull($result['format']);
        $this->assertNull($result['db']);
        $this->assertNull($result['pattern']);
    }

    public function testExportUnknownFlagsRefused(): void
    {
        // Strict contract: export defines no flags; unknown ones are refused by name.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');
        ArgParser::export(['candy-kv', 'export', 'json', '--unknown-flag'], 2);
    }

    // ─── both-form option parsing ───────────────────────────────────────────

    public function testSetAcceptsTtlSpaceForm(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl', '3600'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testSetAcceptsTtlSpaceFormBeforePositionals(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', '--ttl', '60', 'mykey', 'myvalue'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('myvalue', $result['value']);
        $this->assertSame(60, $result['ttl']);
    }

    public function testSetAcceptsTtlAttachedFormStill(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl=3600'], 2);
        $this->assertSame(3600, $result['ttl']);
    }

    public function testSetRefusesNonNumericTtlAttached(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('integer of seconds');
        ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl=soon'], 2);
    }

    public function testSetRefusesNonNumericTtlSpaceForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl', 'soon'], 2);
    }

    public function testSetRefusesEmptyTtl(): void
    {
        // '--ttl=' used to silently store 0; the shared choke refuses it now.
        $this->expectException(\InvalidArgumentException::class);
        ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl='], 2);
    }

    public function testSetRefusesBareTtlWithoutValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a value');
        ArgParser::set(['candy-kv', 'set', 'mykey', '--ttl'], 2);
    }

    public function testListAcceptsDelimiterSpaceForm(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-d', '|', 'user-*'], 2);
        $this->assertSame('|', $result['delimiter']);
        $this->assertSame('user-*', $result['pattern']);
    }

    public function testListAcceptsDelimiterSpaceFormWithColon(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-d', ':'], 2);
        $this->assertSame(':', $result['delimiter']);
        $this->assertNull($result['pattern']);
    }

    public function testListBareTrailingDelimiterRefuses(): void
    {
        // kv-100 (see the sibling flip above): trailing -d is the lost-value
        // class, not the tab-request class.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requires a delimiter value');
        ArgParser::list(['candy-kv', 'list', '-d'], 2);
    }

    public function testListBareDashIsAVisibleSeparatedDelimiter(): void
    {
        // kv-036: '-' fails isOptionLike (the single-dash exemption is
        // parser-wide), so the separated form must answer exactly what the
        // attached `-d-` spelling already did.
        $result = ArgParser::list(['candy-kv', 'list', '-d', '-'], 2);
        $this->assertSame('-', $result['delimiter']);

        $withPattern = ArgParser::list(['candy-kv', 'list', '-d', '-', 'user-*'], 2);
        $this->assertSame('-', $withPattern['delimiter']);
        $this->assertSame('user-*', $withPattern['pattern']);
    }

    public function testListFlagLikeDelimiterValueStillRefuses(): void
    {
        // The isOptionLike swap must not open the hole it closes: -d facing
        // a real option-shaped token still refuses, and the token is echoed
        // scrubbed (kv-004).
        try {
            ArgParser::list(['candy-kv', 'list', '-d', "-r\x1b[31m"], 2);
            $this->fail('option-shaped token is not a delimiter');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('flag-like', $e->getMessage());
            $this->assertStringNotContainsString("\x1b", $e->getMessage(), 'the echoed token is argv bytes — scrubbed on construction');
        }
    }

    // ─── strict contract (lib-fixes-1103 wave A) ────────────────────────────

    public function testSetRefusesZeroTtl(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 1 second');
        ArgParser::set(['candy-kv', 'set', 'mykey', 'myvalue', '--ttl=0'], 2);
    }

    public function testSetRefusesTtlOverflow(): void
    {
        // 10 digits exceeds the 999999999-second ceiling: refuse, never clamp to a zombie.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('integer of seconds');
        ArgParser::set(['candy-kv', 'set', 'mykey', 'myvalue', '--ttl=10000000000'], 2);
    }

    public function testSetRefusesSurplusPositional(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('takes at most');
        ArgParser::set(['candy-kv', 'set', 'mykey', 'myvalue', 'extra'], 2);
    }

    public function testUnknownOptionNamesTheFlagNotItsPayload(): void
    {
        try {
            ArgParser::set(['candy-kv', 'set', 'k', 'v', '--frobnicate=secret'], 2);
            $this->fail('expected refusal');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('--frobnicate', $e->getMessage());
            $this->assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testListRefusesFlagShapedDelimiterValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('delimiter');
        ArgParser::list(['candy-kv', 'list', '-d', '-r'], 2);
    }

    public function testListRefusesSurplusPositional(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgParser::list(['candy-kv', 'list', 'a', 'b'], 2);
    }

    public function testPruneRefusesUnqualifiedPositional(): void
    {
        // candy-kv-025 kept the old fail-shape for junk: a bare word is not
        // a scope, only the qualified key@db spelling names a database.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must name a database');
        ArgParser::prune(['candy-kv', 'prune', 'oops'], 2);
    }

    public function testPruneDefaultsToNoScope(): void
    {
        $this->assertSame(['db' => null, 'all' => false], ArgParser::prune(['candy-kv', 'prune'], 2));
    }

    public function testPruneDbBothSpellings(): void
    {
        foreach ([['--db', 'notes'], ['--db=notes']] as $form) {
            $this->assertSame(
                ['db' => 'notes', 'all' => false],
                ArgParser::prune(array_merge(['candy-kv', 'prune'], $form), 2),
                implode(' ', $form),
            );
        }
    }

    public function testPruneQualifiedKeyPositionalRoutesToItsDatabase(): void
    {
        $this->assertSame(
            ['db' => 'notes', 'all' => false],
            ArgParser::prune(['candy-kv', 'prune', 'cache@notes'], 2),
        );
    }

    public function testPruneRefusesContradictoryScopes(): void
    {
        $cases = [
            [['--db', 'notes', 'k@other'], 'twice'],
            [['--db=notes', '--all'], 'either --all'],
            [['--all', 'k@other'], 'either --all'],
        ];
        foreach ($cases as [$args, $needle]) {
            try {
                ArgParser::prune(array_merge(['candy-kv', 'prune'], $args), 2);
                $this->fail('must refuse: ' . implode(' ', $args));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($needle, $e->getMessage(), implode(' ', $args));
            }
        }
    }

    public function testPruneTrailingDbWithoutValueIsAUsageRefusal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--db requires a value');
        ArgParser::prune(['candy-kv', 'prune', '--db'], 2);
    }

    public function testNoArgumentsAnswersACleanCall(): void
    {
        ArgParser::noArguments(['candy-kv', 'list-dbs'], 2, 'list-dbs');
        $this->expectNotToPerformAssertions();
    }

    public function testNoArgumentsRefusesSurplusPositionalNamingTheVerb(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('list-dbs takes no arguments, got "junk".');
        ArgParser::noArguments(['candy-kv', 'list-dbs', 'junk'], 2, 'list-dbs');
    }

    public function testNoArgumentsRefusesFlagShapedTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('--version takes no arguments, got "--frobnicate".');
        ArgParser::noArguments(['candy-kv', '--version', '--frobnicate'], 2, '--version');
    }

    public function testNoArgumentsRefusesBeyondTheSentinel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('takes no arguments, got "tail".');
        ArgParser::noArguments(['candy-kv', 'list-dbs', '--', 'tail'], 2, 'list-dbs');
    }

    public function testSentinelMakesFlagShapedTailPositional(): void
    {
        $result = ArgParser::set(['candy-kv', 'set', 'mykey', '--', '--ttl=60'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertSame('--ttl=60', $result['value']);
        $this->assertNull($result['ttl']);
    }

    public function testGetDefaultsToRawBytes(): void
    {
        $result = ArgParser::get(['candy-kv', 'get', 'mykey'], 2);
        $this->assertSame('mykey', $result['key']);
        $this->assertFalse($result['sanitize']);
    }

    public function testGetSanitizeOptIn(): void
    {
        $result = ArgParser::get(['candy-kv', 'get', 'mykey', '--sanitize'], 2);
        $this->assertTrue($result['sanitize']);
    }

    public function testDeleteDryRunFlag(): void
    {
        $result = ArgParser::delete(['candy-kv', 'delete', 'mykey', '--dry-run'], 2);
        $this->assertTrue($result['dryRun']);
    }

    public function testSetBinaryAndTextForceFlags(): void
    {
        $binary = ArgParser::set(['candy-kv', 'set', 'k', 'v', '--binary'], 2);
        $this->assertTrue($binary['binary']);
        $text = ArgParser::set(['candy-kv', 'set', 'k', 'v', '--text'], 2);
        $this->assertFalse($text['binary']);
        $auto = ArgParser::set(['candy-kv', 'set', 'k', 'v'], 2);
        $this->assertNull($auto['binary']);
    }

    // ─── KV-S03 (kv-003): attached -d keeps the refuse-rather-than-guess law ──

    public function testListRefusesAMultiLetterAttachedDashDErrorLikeToken(): void
    {
        // The old walk matched str_starts_with($arg, '-d') before the unknown-flag
        // refusal, so a mistyped `list -debug pat` ran as a tabular listing with
        // delimiter "ebug" at exit 0.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('-debug');
        ArgParser::list(['candy-kv', 'list', '-debug', 'pat'], 2);
    }

    public function testListAttachedDelimiterStaysOneCharacter(): void
    {
        // Single-character attached delimiters — including the bare dash and
        // single multi-byte characters — are unambiguous and stay green.
        foreach (['|', ':', '-', 'é', '✓'] as $delimiter) {
            $result = ArgParser::list(['candy-kv', 'list', '-d' . $delimiter], 2);
            $this->assertSame($delimiter, $result['delimiter']);
        }
    }

    public function testListLongerDelimitersRideTheSeparatedFormOnly(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '-d', ', ', 'pat'], 2);
        $this->assertSame(', ', $result['delimiter']);

        $this->expectException(\InvalidArgumentException::class);
        ArgParser::list(['candy-kv', 'list', '-d, '], 2);
    }
    // ─── candy-kv-046/047: delete-db grammar + @db list scope ───────────────

    public function testDeleteDbParsesExactlyOnePositional(): void
    {
        $this->assertSame('notes', ArgParser::deleteDb(['candy-kv', 'delete-db', 'notes'], 2));
    }

    public function testDeleteDbMissingNameRefuses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('delete-db requires a database name.');
        ArgParser::deleteDb(['candy-kv', 'delete-db'], 2);
    }

    public function testDeleteDbRefusesSurplusAndOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgParser::deleteDb(['candy-kv', 'delete-db', 'a', 'b'], 2);
    }

    public function testDeleteDbRefusesFlagTokens(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ArgParser::deleteDb(['candy-kv', 'delete-db', '--force'], 2);
    }

    public function testListAtNameIsADatabaseScopeNotAPattern(): void
    {
        $result = ArgParser::list(['candy-kv', 'list', '@notes'], 2);
        $this->assertSame('notes', $result['db']);
        $this->assertNull($result['pattern']);

        $combined = ArgParser::list(['candy-kv', 'list', '-k', '@notes'], 2);
        $this->assertSame('notes', $combined['db']);
        $this->assertSame('keys', $combined['mode']);
    }

    public function testListNearMissesStayOrdinaryPatterns(): void
    {
        // '@' alone, an interior @, or a name beyond the db grammar keep the
        // pre-S24 pattern reading — the scope form must not steal them.
        foreach (['@', 'a@b', '@no@tes', '@dots.in.name'] as $pattern) {
            $result = ArgParser::list(['candy-kv', 'list', $pattern], 2);
            $this->assertNull($result['db'], $pattern);
            $this->assertSame($pattern, $result['pattern'], $pattern);
        }
    }
}
