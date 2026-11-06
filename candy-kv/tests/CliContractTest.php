<?php

declare(strict_types=1);

namespace SugarCraft\Kv\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Kv\Store;

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

    public function testVersionExitsZeroAndCreatesNoStorage(): void
    {
        $run = $this->runKv(['--version']);
        $this->assertSame(0, $run['exit']);
        $this->assertNotSame('', \trim($run['stdout']));
        $this->assertFalse(\is_dir($this->configHome . '/.config'));
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
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runKv(array $args): array
    {
        return $this->spawn($args, null);
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function runKvFeeding(array $args, string $stdin): array
    {
        return $this->spawn($args, $stdin);
    }

    /**
     * @param list<string> $args
     * @return array{stdout: string, stderr: string, exit: int}
     */
    private function spawn(array $args, ?string $stdin): array
    {
        $cmd = \array_merge([\PHP_BINARY, \dirname(__DIR__) . '/bin/candy-kv'], $args);
        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;

        $proc = \proc_open($cmd, [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, $env);
        $this->assertIsResource($proc, 'failed to launch the candy-kv CLI');

        if ($stdin !== null) {
            \fwrite($pipes[0], $stdin);
        }
        \fclose($pipes[0]);
        $stdout = \stream_get_contents($pipes[1]);
        $stderr = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        $exit = \proc_close($proc);

        return ['stdout' => (string) $stdout, 'stderr' => (string) $stderr, 'exit' => $exit];
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
