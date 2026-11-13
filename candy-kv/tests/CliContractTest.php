<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Store;
use SugarCraft\Kv\Tests\Support\SpawnBounded;

/**
 * End-to-end pins for the strict CLI contract (lib-fixes-1103 wave A).
 *
 * Where CliSmokeTest guards "the binary never fatals", this file pins the
 * positive half of the contract: every flag is answered (unknown ones
 * refused by name), the `--` sentinel reaches values verbatim, binary
 * payloads survive set→get→export, `get` is byte-exact by default with an
 * opt-in `--sanitize`, and the success/refusal exit-code map is stable
 * (2 = usage, 1 = miss/failure).
 *
 * Each test isolates the data directory via XDG_CONFIG_HOME; the binary
 * must not create any storage for commands that never touch a store
 * (help/version/unknown command).
 */
final class CliContractTest extends TestCase
{
    use SpawnBounded;

    private string $configHome;

    protected function setUp(): void
    {
        if (!\extension_loaded('sqlite3')) {
            $this->markTestSkipped('ext-sqlite3 is required to run the candy-kv CLI.');
        }
        $this->configHome = \sys_get_temp_dir() . '/candy-kv-cli-contract-' . \uniqid('', true);
        \mkdir($this->configHome, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->configHome);
    }

    public function testUnknownCommandExitsTwoAndCreatesNoStorage(): void
    {
        $run = $this->runKv(['frobnicate']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('Unknown command', $run['stderr']);
        $this->assertStringNotContainsString('Fatal', $run['stderr']);
        $this->assertStringNotContainsString('Uncaught', $run['stderr']);
        $this->assertFalse(\is_dir($this->configHome . '/.config'), 'unknown command must not build the data dir');
    }

    public function testHelpExitsZeroAndCreatesNoStorage(): void
    {
        $run = $this->runKv(['--help']);
        $this->assertSame(0, $run['exit']);
        $this->assertStringContainsString('candy-kv', $run['stdout']);
        // The rewritten help lists every flag the parser actually accepts.
        foreach (['--binary', '--text', '--sanitize', '--dry-run', '--no-atomic', 'prune'] as $needle) {
            $this->assertStringContainsString($needle, $run['stdout'], "help must document {$needle}");
        }
        $this->assertFalse(\is_dir($this->configHome . '/.config'));
    }

    /**
     * candy-kv-084: the help verb has three spellings (--help, -h, bare
     * `help`) answering through the same bin/candy-kv fallthrough — the old
     * suite executed only one, so two could rot silently.
     */
    public function testShortHelpFlagExitsZeroAndCreatesNoStorage(): void
    {
        $run = $this->runKv(['-h']);
        $this->assertSame(0, $run['exit'], '-h must answer like --help, not face the unknown-command refusal');
        $this->assertStringContainsString('candy-kv', $run['stdout']);
        $this->assertFalse(\is_dir($this->configHome . '/.config'));
    }

    public function testBareHelpVerbExitsZeroAndCreatesNoStorage(): void
    {
        $run = $this->runKv(['help']);
        $this->assertSame(0, $run['exit'], 'bare `help` must answer like --help, not face the unknown-command refusal');
        $this->assertStringContainsString('candy-kv', $run['stdout']);
        $this->assertFalse(\is_dir($this->configHome . '/.config'));
    }

    public function testVersionExitsZeroAndCreatesNoStorage(): void    {
        $run = $this->runKv(['--version']);
        $this->assertSame(0, $run['exit']);
        $this->assertNotSame('', \trim($run['stdout']));
        $this->assertFalse(\is_dir($this->configHome . '/.config'));
    }

    public function testVersionRefusesSurplusTokensAsUsageError(): void
    {
        $run = $this->runKv(['--version', '--frobnicate']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('--version takes no arguments, got "--frobnicate"', $run['stderr']);
        $this->assertSame('', $run['stdout'], 'a refused --version must print no version line');
        $this->assertFalse(\is_dir($this->configHome . '/.config'), 'the refusal stays store-free like the success path');
    }

    public function testListDbsRefusesSurplusTokensAsUsageError(): void
    {
        $run = $this->runKv(['list-dbs', 'junk', '--frobnicate']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('list-dbs takes no arguments, got "junk"', $run['stderr']);
        $this->assertSame('', $run['stdout'], 'a refused list-dbs must not print a database list around the junk');
    }

    public function testListDbsStillAnswersCleanly(): void
    {
        $run = $this->runKv(['list-dbs']);
        $this->assertSame(0, $run['exit']);
    }

    public function testZeroTtlRefusedAndNothingStored(): void
    {
        $run = $this->runKv(['set', 'zero-ttl-k', 'v', '--ttl=0']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('at least 1 second', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'zero-ttl-k'])['exit'], 'refused set must store nothing');
    }

    public function testTtlOverflowRefused(): void
    {
        $run = $this->runKv(['set', 'big-ttl-k', 'v', '--ttl=10000000000']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('integer of seconds', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'big-ttl-k'])['exit']);
    }

    public function testUnknownFlagRefusedAndNothingStored(): void
    {
        $run = $this->runKv(['set', 'typo-k', 'v', '--frobnicate']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('--frobnicate', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'typo-k'])['exit']);
    }

    public function testMultiLetterAttachedDashDIsRefusedNotGuessedAsDelimiter(): void
    {
        // G2 / kv-003: `list -debug pat` once ran as a tabular listing with
        // delimiter "ebug" at exit 0. The parser now names the token and refuses.
        $run = $this->runKv(['list', '-debug', 'pat']);
        $this->assertSame(2, $run['exit'], $run['stderr']);
        $this->assertStringContainsString('-debug', $run['stderr']);
    }

    public function testDiagnosticsNeverCarryRawEscapeBytes(): void
    {
        // kv-004 egress law: every diagnostic that interpolates argv or store
        // bytes passes through Store::sanitizeForTty before reaching stderr —
        // parser refusals at their message source, everything else at the bin
        // catches. The probes must fail (exit != 0) and stay 0x1b-free.
        $probes = [
            ['list', "-\x1b[31mfo"],            // unknownOption names the token
            ['set', 'k', 'v', "--ttl=1\x1b2"],  // ttlSeconds echoes the value
            ['set', 'k', 'v', "e\x1bx"],         // refuseSurplus names the surplus
            ['get', "a@\x1bb@c"],                // splitKey's ambiguous-address refusal
        ];
        foreach ($probes as $args) {
            $run = $this->runKv($args);
            $this->assertNotSame(0, $run['exit'], 'probe must fail: ' . json_encode($args));
            $this->assertStringNotContainsString("\x1b", $run['stderr'], 'raw ESC must never ride a diagnostic: ' . json_encode($args));
        }
    }

    public function testSurplusPositionalRefused(): void
    {
        $run = $this->runKv(['set', 'k1', 'v1', 'oops']);
        $this->assertSame(2, $run['exit']);
        $this->assertStringContainsString('takes at most', $run['stderr']);
    }

    public function testSentinelKeepsFlagShapedValueVerbatim(): void
    {
        $set = $this->runKv(['set', 'sentinel-k', '--', '--ttl=60']);
        $this->assertSame(0, $set['exit']);
        $get = $this->runKv(['get', 'sentinel-k']);
        $this->assertSame('--ttl=60', $get['stdout']);
    }

    public function testBinaryValueRoundTripsByteExactThroughStdin(): void
    {
        $set = $this->runKvFeeding(['set', 'nul-k'], "x\0y");
        $this->assertSame(0, $set['exit'], $set['stderr']);
        $get = $this->runKv(['get', 'nul-k']);
        $this->assertSame(0, $get['exit']);
        $this->assertSame("x\0y", $get['stdout'], 'binary auto-detect must round-trip the NUL byte for byte');
    }

    public function testListMarksBinaryEntriesWithoutLeakingBytes(): void
    {
        $this->runKvFeeding(['set', 'nul-k'], "x\0y");
        $list = $this->runKv(['list']);
        $this->assertSame(0, $list['exit']);
        $this->assertStringContainsString("nul-k\t<binary 3 bytes>", $list['stdout']);
    }

    public function testListValuesHonoursTheBinaryPlaceholderLikeDefaultMode(): void
    {
        // --binary forces the flag on a UTF-8-decodable payload, the shape
        // the sanitizeForTty placeholder gate cannot see. 'list -v' used to
        // dump it in full while plain 'list' placeholdered the same entry.
        $set = $this->runKv(['set', 'utfbin-k', '--binary', 'café']);
        $this->assertSame(0, $set['exit'], $set['stderr']);
        $bytes = \strlen('café');

        $list = $this->runKv(['list']);
        $this->assertStringContainsString("utfbin-k\t<binary {$bytes} bytes>", $list['stdout']);

        $values = $this->runKv(['list', '-v']);
        $this->assertSame(0, $values['exit']);
        $this->assertStringContainsString("<binary {$bytes} bytes>", $values['stdout']);
        $this->assertStringNotContainsString('café', $values['stdout'], '-v must placeholder binary entries, not dump them');
    }

    public function testListPrintsDelimiterRawAndTabIsDefault(): void
    {
        // The delimiter is an awk field separator for scripts: it must not be
        // routed through the value sanitizer (a tab used to arrive as a space).
        $this->runKv(['set', 'delim-k', 'dv']);
        $list = $this->runKv(['list']);
        $this->assertStringContainsString("delim-k\tdv", $list['stdout']);
    }

    public function testDryRunCountsEscapedAtGlobsLikeDeleteDoes(): void
    {
        // 'a\@b*' is one glob in the default database (escaped @ is literal).
        // The old raw-strrpos split saw '@b*' as a suffix and answered
        // "Nothing deleted" (exit 1) for a pattern delete really removes.
        $this->assertSame(0, $this->runKv(['set', 'a\\@b-one', 'v1'])['exit']);
        $this->assertSame(0, $this->runKv(['set', 'a\\@b-two', 'v2'])['exit']);

        $dry = $this->runKv(['delete', 'a\\@b*', '--dry-run']);
        $this->assertSame(0, $dry['exit'], $dry['stderr']);
        $this->assertStringContainsString('2 entries.', $dry['stdout'], 'dry-run counts both rows');

        $real = $this->runKv(['delete', 'a\\@b*']);
        $this->assertSame(0, $real['exit'], $real['stderr']);
        $this->assertStringNotContainsString('a@b-one', $this->runKv(['list'])['stdout']);
    }

    public function testDryRunCountsLeadingAtGlobsLikeDeleteDoes(): void
    {
        // '@roo*' is a literal-leading-@ glob in the default database; the
        // naive split turned it into an empty key against database 'roo*'.
        $this->assertSame(0, $this->runKv(['set', '@roo-x', 'v'])['exit']);

        $dry = $this->runKv(['delete', '@roo*', '--dry-run']);
        $this->assertSame(0, $dry['exit'], $dry['stderr']);
        $this->assertStringContainsString('1 entry.', $dry['stdout'], 'dry-run counts the row');

        $real = $this->runKv(['delete', '@roo*']);
        $this->assertSame(0, $real['exit'], $real['stderr']);
    }

    public function testDryRunAgreesWithDeleteOnEscapedAtUnderASuffix(): void
    {
        // 'x\@y*@logs': pattern 'x@y*' in database logs (README law).
        // Before the escape-aware split, dry-run matched the still-encoded
        // spelling (zero rows, exit 1) while the real delete matched.
        $this->assertSame(0, $this->runKv(['set', 'x\\@y-1@logs', 'v'])['exit']);

        $dry = $this->runKv(['delete', 'x\\@y*@logs', '--dry-run']);
        $this->assertSame(0, $dry['exit'], $dry['stderr']);

        $real = $this->runKv(['delete', 'x\\@y*@logs']);
        $this->assertSame(0, $real['exit'], $real['stderr']);
    }

    public function testGetIsByteExactByDefaultAndSanitizesOnRequest(): void
    {
        $escaped = "a\x1b[31mred\x1b[0m";
        $this->assertSame(0, $this->runKvFeeding(['set', 'esc-k'], $escaped)['exit']);

        $raw = $this->runKv(['get', 'esc-k']);
        $this->assertSame($escaped, $raw['stdout'], 'default get is the exact read');

        $safe = $this->runKv(['get', '--sanitize', 'esc-k']);
        $this->assertSame(0, $safe['exit']);
        $this->assertStringNotContainsString("\x1b", $safe['stdout'], '--sanitize must strip ESC bytes');
        $this->assertStringContainsString('red', $safe['stdout']);
    }

    public function testExportSurvivesLegacyInvalidUtf8Value(): void
    {
        // A pre-auto-detect entry: raw non-UTF-8 bytes stored with binary=false.
        // One such value used to fail json_encode and poison the whole export.
        $previous = \getenv('XDG_CONFIG_HOME');
        \putenv('XDG_CONFIG_HOME=' . $this->configHome);
        try {
            $store = new Store();
            $store->set('legacy-raw', "\xff\xfe", false);
        } finally {
            \putenv(false === $previous ? 'XDG_CONFIG_HOME' : 'XDG_CONFIG_HOME=' . $previous);
        }

        $export = $this->runKv(['export', 'json']);
        $this->assertSame(0, $export['exit'], $export['stderr']);
        $this->assertStringContainsString('legacy-raw', $export['stdout']);
        $this->assertStringContainsString("\xef\xbf\xbd", $export['stdout'], 'invalid bytes must be substituted, not fatal');
    }

    public function testDeleteDryRunPreviewsWithoutWriting(): void
    {
        $this->runKv(['set', 'dry-1', 'a']);
        $this->runKv(['set', 'dry-2', 'b']);

        $preview = $this->runKv(['delete', 'dry-*', '--dry-run']);
        $this->assertSame(0, $preview['exit']);
        $this->assertStringContainsString('Would delete 2 entries.', $preview['stdout']);

        $this->assertSame(0, $this->runKv(['get', 'dry-1'])['exit'], 'dry-run must not delete');
    }

    public function testDeleteDryRunMissExitsOne(): void
    {
        $run = $this->runKv(['delete', 'nothing-matches-*', '--dry-run']);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('Nothing deleted', $run['stderr']);
    }

    public function testDeleteSingleUsesSingularWording(): void
    {
        $this->runKv(['set', 'solo', 'v']);
        $run = $this->runKv(['delete', 'solo']);
        $this->assertSame(0, $run['exit']);
        $this->assertStringContainsString('Deleted 1 entry.', $run['stdout']);
    }

    public function testPruneRemovesExpiredEntries(): void
    {
        $this->assertSame(0, $this->runKv(['set', 'gone-soon', 'v', '--ttl=1'])['exit']);
        \sleep(2);
        $prune = $this->runKv(['prune']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('Removed 1 expired entries.', $prune['stdout']);
        $this->assertSame(1, $this->runKv(['get', 'gone-soon'])['exit']);
    }

    public function testPruneReportsNothingWhenAllAlive(): void
    {
        $this->runKv(['set', 'fresh', 'v', '--ttl=3600']);
        $prune = $this->runKv(['prune']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('No expired entries to remove.', $prune['stdout']);
    }

    public function testImportSingularAndPluralWording(): void
    {
        $one = $this->runKvFeeding(['import', 'json', '-'], '{"only":"1"}');
        $this->assertSame(0, $one['exit']);
        $this->assertStringContainsString('Imported 1 entry.', $one['stdout']);

        $many = $this->runKvFeeding(['import', 'json', '-'], '{"a":"1","b":"2"}');
        $this->assertSame(0, $many['exit']);
        $this->assertStringContainsString('Imported 2 entries.', $many['stdout']);
    }

    public function testEmptyAtomicImportCreatesNoDatabaseFile(): void
    {
        // Data-directory law (README): neither the directory nor a database
        // file exists "until the first write". An atomic import of an empty
        // document used to open default.db just to BEGIN/COMMIT nothing,
        // while --no-atomic created nothing; both shapes must now be true
        // no-ops that still report success.
        $cases = [
            [['import', 'json', '-'], '{}'],
            [['import', 'json', '-', '--no-atomic'], '{}'],
            [['import', 'yaml', '-'], ''],
        ];
        foreach ($cases as [$args, $payload]) {
            $label = \implode(' ', $args);
            $run = $this->runKvFeeding($args, $payload);
            $this->assertSame(0, $run['exit'], $label);
            $this->assertStringContainsString('Imported 0 entries.', $run['stdout'], $label);
            $this->assertFileDoesNotExist($this->configHome . '/skate/default.db', $label . ' minted a database file');
        }
        $this->assertDirectoryDoesNotExist($this->configHome . '/skate', 'the data directory itself may not appear');
    }

    public function testUnknownImportAndExportFormatsExitTwo(): void
    {
        $import = $this->runKv(['import', 'xml', 'whatever.xml']);
        $this->assertSame(2, $import['exit']);
        $this->assertStringContainsString("'yml'", $import['stderr'], 'refusal must list every accepted spelling');

        $export = $this->runKv(['export', 'xml']);
        $this->assertSame(2, $export['exit']);
        $this->assertStringContainsString('Unknown export format', $export['stderr']);
    }

    public function testGetMissNamesTheKeyWhenNoSuggestionFits(): void
    {
        $run = $this->runKv(['get', 'zzz-no-such-thing-xyz']);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('key not found: zzz-no-such-thing-xyz', $run['stderr']);
    }

    public function testGetMissSuggestsCloseKey(): void
    {
        $this->runKv(['set', 'colur', 'green']);
        $run = $this->runKv(['get', 'colar']);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString("did you mean 'colur'", $run['stderr']);
    }

    /**
     * candy-kv-069 (G10 default, KV-S35): the piped contract is the payload
     * itself — a poisoned value on a non-tty stdout must print byte-exact
     * with NO hint noise, stderr fully silent.
     */
    public function testGetOfAPoisonedValueOnAPipeIsByteExactWithSilentStderr(): void
    {
        $poison = "a\x1b[31mRED";
        $set = $this->runKv(['set', 'kv-s35', $poison]);
        $this->assertSame(0, $set['exit'], $set['stderr']);

        $get = $this->runKv(['get', 'kv-s35']);
        $this->assertSame(0, $get['exit'], $get['stderr']);
        $this->assertSame($poison, $get['stdout'], 'piped get stays byte-exact, ESC and all');
        $this->assertSame('', $get['stderr'], 'the hint belongs to a TTY only; pipes must stay pure');
    }

    /**
     * The interactive half of candy-kv-069: under a real pty the same get
     * STILL prints raw bytes (byte-exactness law) and adds the --sanitize
     * hint; a clean value gets no hint even interactively (the predicate is
     * "a scrub would change something").
     */
    public function testTheInteractiveHintFiresOnATtyOnlyForScrubbableValues(): void
    {
        if (PHP_OS_FAMILY !== 'Linux') {
            $this->markTestSkipped('util-linux script(1) pty probe is modelled on Linux runners only.');
        }
        $probe = $this->spawnBounded(['script', '-q', '-e', '-c', 'true', '/dev/null']);
        if ($probe['exit'] !== 0) {
            $this->markTestSkipped('script(1) with -e/-c is unavailable on this box.');
        }

        $set = $this->runKv(['set', 'kv-s35', "a\x1b[31mRED"]);
        $this->assertSame(0, $set['exit'], $set['stderr']);
        $clean = $this->runKv(['set', 'kv-s35-clean', 'plain']);
        $this->assertSame(0, $clean['exit'], $clean['stderr']);

        $bin = \dirname(__DIR__) . '/bin/candy-kv';
        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;

        // script(1) hands -c to its own child shell, so the inner quotes the
        // shell must SEE belong to the string itself (a second escapeshellarg
        // would arrive literal and the word-splitting would break).
        $ttyCmd = \sprintf('%s %s get kv-s35', \escapeshellarg(\PHP_BINARY), \escapeshellarg($bin));
        $tty = $this->spawnBounded(['script', '-q', '-e', '-c', $ttyCmd, '/dev/null'], $env);
        $this->assertSame(0, $tty['exit'], $tty['stderr'] . $tty['stdout']);
        $this->assertStringContainsString("a\x1b[31mRED", $tty['stdout'], 'raw bytes stay on the terminal (byte-exactness law)');
        $this->assertStringContainsString('(value contains control bytes', $tty['stdout'], 'a TTY must be told a scrub exists');

        $ttyCleanCmd = \sprintf('%s %s get kv-s35-clean', \escapeshellarg(\PHP_BINARY), \escapeshellarg($bin));
        $ttyClean = $this->spawnBounded(['script', '-q', '-e', '-c', $ttyCleanCmd, '/dev/null'], $env);
        $this->assertSame(0, $ttyClean['exit'], $ttyClean['stderr'] . $ttyClean['stdout']);
        $this->assertStringContainsString('plain', $ttyClean['stdout']);
        $this->assertStringNotContainsString('(value contains control bytes', $ttyClean['stdout'], 'a clean value must never nag');
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    private function runKv(array $args): array
    {
        return $this->spawn($args, null);
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    private function runKvFeeding(array $args, string $stdin): array
    {
        return $this->spawn($args, $stdin);
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int, timedOut: bool}
     */
    private function spawn(array $args, ?string $stdin): array
    {
        return self::spawnKv($args, $this->configHome, $stdin);
    }

    private function removeTree(string $dir): void
    {
        if (!\is_dir($dir)) {
            return;
        }
        foreach (\glob($dir . '/*') ?: [] as $entry) {
            \is_dir($entry) ? $this->removeTree($entry) : @\unlink($entry);
        }
        @\rmdir($dir);
    }
}
