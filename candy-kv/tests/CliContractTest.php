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
        foreach (['--binary', '--text', '--sanitize', '--dry-run', '--no-atomic', '--if-exists', 'prune'] as $needle) {
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
        $this->assertStringContainsString('integer number of seconds', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'big-ttl-k'])['exit']);
    }

    public function testContradictoryForceFlagsExitTwoAndStoreNothing(): void
    {
        // candy-kv-086 gate G9 end-to-end: the --binary --text pair is a
        // usage error refusing BEFORE any write — last-wins was the silent
        // clobber shape the flag pair must never fall back to.
        $run = $this->runKv(['set', 'clob-k', 'v', '--binary', '--text']);
        $this->assertSame(2, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('mutually exclusive', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'clob-k'])['exit'], 'the refused set must have stored nothing');
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

    public function testListDashSeparatedSingleDashDelimitsRows(): void
    {
        // kv-036 user-visible contract: the separated bare-dash spelling
        // answers rc 0 with the dash as the column delimiter — the exact
        // shape the attached `-d-` form already produced.
        $this->runKv(['set', 'alpha', 'one']);
        $this->runKv(['set', 'beta', 'two']);

        $run = $this->runKv(['list', '-d', '-']);
        $this->assertSame(0, $run['exit'], $run['stderr']);
        $this->assertMatchesRegularExpression('/^alpha-one$/m', $run['stdout']);
        $this->assertMatchesRegularExpression('/^beta-two$/m', $run['stdout']);

        $attached = $this->runKv(['list', '-d-']);
        $this->assertSame($attached['stdout'], $run['stdout'], 'both spellings answer one byte-identical table');
    }

    public function testTrailingDashDIsAUsageError(): void
    {
        // kv-100 user-visible contract: the lost-delimiter trip folds into
        // the exit-2 usage class and names the missing value.
        $run = $this->runKv(['list', '-d']);
        $this->assertSame(2, $run['exit'], $run['stdout']);
        $this->assertStringContainsString('Option -d requires a delimiter value', $run['stderr']);
    }

    public function testAcceptanceDatabaseParityVerbs(): void
    {
        // kv-046/047/048 acceptance shape, end to end through the binary:
        // write qualified, list the database bare, typo the database name
        // and be answered about the DATABASE, retire the database.
        $this->assertSame(0, $this->runKv(['set', 'k@notes', 'v'])['exit']);
        $this->assertSame(0, $this->runKv(['set', 'token@passwords', 'sekret'])['exit']);

        $scoped = $this->runKv(['list', '@notes']);
        $this->assertSame(0, $scoped['exit'], $scoped['stderr']);
        $this->assertMatchesRegularExpression('/^k\tv$/m', $scoped['stdout']);

        $typo = $this->runKv(['get', 'token@passwrods']);
        $this->assertSame(1, $typo['exit']);
        $this->assertStringContainsString('database not found: passwrods', $typo['stderr']);
        $this->assertStringContainsString("did you mean database 'passwords'", $typo['stderr'],
            'the typo answer must name the vault that exists, before any key hint');

        $typoScopedList = $this->runKv(['list', '@passwrods']);
        $this->assertSame(1, $typoScopedList['exit'], 'a scoped list of a missing db is the db-miss class');
        $this->assertStringContainsString("'passwords'", $typoScopedList['stderr']);

        $deleted = $this->runKv(['delete-db', 'notes']);
        $this->assertSame(0, $deleted['exit'], $deleted['stderr']);
        $this->assertStringContainsString('Deleted database "notes".', $deleted['stdout']);
        $this->assertFileDoesNotExist($this->configHome . '/skate/notes.db');
        $this->assertFileDoesNotExist($this->configHome . '/skate/notes.db-wal');
        $this->assertFileDoesNotExist($this->configHome . '/skate/notes.db-shm');

        $gone = $this->runKv(['delete-db', 'notes']);
        $this->assertSame(1, $gone['exit'], 're-deleting a retired database is a miss, not a second success');
        $this->assertStringContainsString('database not found', $gone['stderr']);
    }

    public function testDeleteDatabaseRefusesTheReservedNameThroughTheWriteDoor(): void
    {
        // S19's reservation binds this NEW verb too: settings answers the
        // reserved sentence at exit 2, never a delete.
        $run = $this->runKv(['delete-db', 'settings']);
        $this->assertSame(2, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('reserved', $run['stderr']);
    }

    public function testQualifiedMissOnADatabaseStillGetsKeyHelpWhenTheDbExists(): void
    {
        // The db-miss branch must not swallow the key class: wrong key,
        // right database → the did-you-mean KEY hint (old behaviour intact).
        $this->runKv(['set', 'token@notes', 'v']);
        $run = $this->runKv(['get', 'tken@notes']);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('did you mean', $run['stderr']);
        $this->assertStringNotContainsString('database not found', $run['stderr']);
    }

    public function testTextForcedNulValueIsRefusedAndBinaryDoorRoundTrips(): void
    {
        // kv-008: `--text` sold as a force switch silently minted
        // unreadable rows — SQLite keeps the cell but every C-string reader
        // truncates at the first NUL. Admission now refuses; --binary stays
        // the byte-exact door.
        $refused = $this->runKvFeeding(['set', 'nul-k', '--text'], "a\0b");
        $this->assertNotSame(0, $refused['exit'], 'the forced-text NUL payload must be refused');
        $this->assertStringContainsString('--binary', $refused['stderr'], 'the refusal must point at the right door');
        $this->assertSame(1, $this->runKv(['get', 'nul-k'])['exit'], 'the refusal stores nothing');

        $stored = $this->runKvFeeding(['set', 'nul-k', '--binary'], "a\0b");
        $this->assertSame(0, $stored['exit'], $stored['stderr']);
        $back = $this->runKv(['get', 'nul-k']);
        $this->assertSame(0, $back['exit'], $back['stderr']);
        $this->assertSame("a\0b", $back['stdout'], 'binary door round-trips byte-exact');
    }

    public function testErroringStdinIsRefusedAndStoresNothing(): void
    {
        // kv-016: a STDIN READ FAILURE used to be laundered into '' and
        // written over the previous secret with a success line. An EISDIR
        // fd surfaces on this CLI as a stream diagnostic + truncated ''
        // — the exact laundering shape — so it must exit 1 with nothing
        // stored. (The literal false return is unreachable through any
        // spawnable fd; the refusal arms both shapes in one code path.)
        $dir = \fopen('/tmp', 'r');
        if ($dir === false) {
            $this->markTestSkipped('this platform will not open a directory as a read resource.');
        }

        $run = $this->spawnKvOnFd0(['set', 'kv016-secret'], $this->configHome, $dir);
        \fclose($dir);

        $this->assertSame(1, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('Failed to read from STDIN', $run['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'kv016-secret'])['exit'], 'a failed read stores nothing');
    }

    public function testCleanEofStdinStillStoresAnEmptyValue(): void
    {
        // The other half of kv-016: only FAILURES refuse. Feeding zero bytes
        // through a closed pipe is a clean EOF — an intentional empty value.
        $run = $this->runKvFeeding(['set', 'kv016-empty'], '');
        $this->assertSame(0, $run['exit'], $run['stderr']);
        $get = $this->runKv(['get', 'kv016-empty']);
        $this->assertSame(0, $get['exit'], $get['stderr']);
        $this->assertSame('', $get['stdout']);
    }

    public function testBackslashTerminatedKeyIsRefusedAtTheCliDoor(): void
    {
        // G3 minimum (kv-007): usage-class refusal, exit 2, informative line.
        $run = $this->runKv(['set', 'bs\\', 'v']);
        $this->assertSame(2, $run['exit'], $run['stderr']);
        $this->assertStringContainsString('round-trip', $run['stderr']);
    }

    public function testDiagnosticsNeverCarryRawEscapeBytes(): void    {
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

    public function testGetReachesGlobCharKeysThroughTheEscapedSpelling(): void
    {
        // kv-043: keys whose name carries * or ? were creatable and
        // listable yet unfetchable — the gate judged raw bytes, so even
        // the `\*` escape (exact-key intent per the pattern law) died at
        // the door. QA dead-key roster replayed here: ra*wt, q?mark.
        $this->assertSame(0, $this->runKv(['set', 'ra*wt', 'v1'])['exit']);
        $this->assertSame(0, $this->runKv(['set', 'q?mark', 'v2'])['exit']);

        $hit = $this->runKv(['get', 'ra\\*wt']);
        $this->assertSame(0, $hit['exit'], $hit['stderr']);
        $this->assertSame('v1', $hit['stdout']);

        $hit = $this->runKv(['get', 'q\\?mark']);
        $this->assertSame(0, $hit['exit'], $hit['stderr']);
        $this->assertSame('v2', $hit['stdout']);

        // Raw unescaped glob stays the pattern refusal — wildcards are
        // list/delete territory, never a fetch.
        $refused = $this->runKv(['get', 'ra*wt']);
        $this->assertSame(2, $refused['exit']);
        $this->assertStringContainsString('list', $refused['stderr']);
    }

    public function testGetAnswersBackslashStoredLegacyKeysByTheirOwnSpelling(): void
    {
        // The pre-kv-043 habit `set 'weird\*key'` stored the backslash and
        // star verbatim (the roster's fourth dead key). When the decoded
        // form misses, the literal argv bytes are tried — legacy rows keep
        // answering to their own name.
        $this->assertSame(0, $this->runKv(['set', 'weird\\*key', 'v3'])['exit']);
        $hit = $this->runKv(['get', 'weird\\*key']);
        $this->assertSame(0, $hit['exit'], $hit['stderr']);
        $this->assertSame('v3', $hit['stdout']);
    }

    public function testExportSurvivesLegacyInvalidUtf8Value(): void
    {
        // A pre-auto-detect entry: raw non-UTF-8 bytes stored with binary=false.
        // One such value used to fail json_encode, then (after the first fix)
        // was silently rewritten to U+FFFD. The kv-009 law: the walk promotes
        // it into the base64 binary envelope — export succeeds AND the exact
        // bytes ride the document.
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
        $this->assertStringContainsString('"//4="', $export['stdout'], 'the exact bytes ride the base64 envelope');
        $this->assertStringContainsString('_binary', $export['stdout']);
        $this->assertStringNotContainsString("\xef\xbf\xbd", $export['stdout'], 'the substitution character must never appear');
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
        // Exactly one expired row must print the SINGULAR (kv-011): the
        // prune path used to reuse cli.pruned_n blindly, so a one-row
        // removal read "Removed 1 expired entries." — the delete path
        // already routes n===1 through cli.deleted_one; prune now does
        // the same. Row is stamped past-epoch in-process (the
        // DatabaseTest trick) instead of sleeping, so the pin is fast.
        $this->seedExpiredRow('gone-soon', 'v');
        $prune = $this->runKv(['prune']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('Removed 1 expired entry.', $prune['stdout']);
        $this->assertSame(1, $this->runKv(['get', 'gone-soon'])['exit']);
    }

    public function testPruneUsesPluralWordingForManyEntries(): void
    {
        $this->seedExpiredRow('doomed-a', '1');
        $this->seedExpiredRow('doomed-b', '2');
        $prune = $this->runKv(['prune']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('Removed 2 expired entries.', $prune['stdout']);
    }

    public function testPruneSingularWordingHonorsGermanLocale(): void
    {
        // LANG-wave sibling of the English singular pin: the key must exist
        // and route in every locale, not just en.
        $this->seedExpiredRow('gone-soon', 'v');
        $prune = self::spawnKv(['prune'], $this->configHome, null, ['LC_ALL' => 'de', 'LANG' => 'de']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('1 abgelaufener Eintrag entfernt.', $prune['stdout']);
    }

    /**
     * Write a live-then-expired row directly into the store the CLI will
     * open, then stamp its expiry into the past through the SQLite handle.
     *
     * Mirrors DatabaseTest::testCountExcludesExpiredRows; avoids two-second
     * real sleeps for every TTL-succession pin.
     */
    private function seedExpiredRow(string $key, string $value, string $db = 'default'): void
    {
        $dir = $this->configHome . '/skate';
        if (!\is_dir($dir)) {
            \mkdir($dir, 0o700, true);
        }
        $handle = new \SugarCraft\Kv\Database($dir . '/' . $db . '.db', $db);
        $handle->set($key, $value, false, 3600);

        $prop = new \ReflectionProperty(\SugarCraft\Kv\Database::class, 'db');
        $prop->setAccessible(true);
        /** @var \SQLite3 $sqlite */
        $sqlite = $prop->getValue($handle);
        $stmt = $sqlite->prepare('UPDATE entries SET expires_at = :epoch WHERE key = :key');
        $stmt->bindValue(':epoch', \time() - 10, \SQLITE3_INTEGER);
        $stmt->bindValue(':key', $key, \SQLITE3_TEXT);
        $stmt->execute();
        $stmt->close();
        unset($handle);
    }

    public function testPruneReportsNothingWhenAllAlive(): void
    {
        $this->runKv(['set', 'fresh', 'v', '--ttl=3600']);
        $prune = $this->runKv(['prune']);
        $this->assertSame(0, $prune['exit']);
        $this->assertStringContainsString('No expired entries to remove.', $prune['stdout']);
    }

    public function testScopedPruneRemovesOnlyItsOwnDatabase(): void
    {
        // candy-kv-025 bidirectional scope pin: expired rows live in BOTH
        // databases; `prune --db other` must retire other.db's row and hold
        // the default.db one as survivor — and the mirror call proves the
        // scope is a door, not a default that happens to match.
        $this->seedExpiredRow('dupe', 'default-side');
        $this->seedExpiredRow('dupe', 'other-side', 'other');
        $this->seedExpiredRow('alone', 'kept', 'default');

        $scoped = $this->runKv(['prune', '--db', 'other']);
        $this->assertSame(0, $scoped['exit'], $scoped['stderr']);
        $this->assertStringContainsString('Removed 1 expired entry', $scoped['stdout']);
        $this->assertStringContainsString('(other)', $scoped['stdout'], 'the answer must say which db it swept');
        // default.db's two expired rows are survivors of that scoped sweep:
        // the bare prune afterwards still finds them.
        $bare = $this->runKv(['prune']);
        $this->assertStringContainsString('Removed 2 expired entries', $bare['stdout']);
    }

    public function testQualifiedKeyPositionalPrunesThatDatabase(): void
    {
        $this->seedExpiredRow('ghost', 'gone', 'notes');
        $run = $this->runKv(['prune', 'cache@notes']);
        $this->assertSame(0, $run['exit'], $run['stderr']);
        $this->assertStringContainsString('Removed 1 expired entry', $run['stdout']);
        $this->assertStringContainsString('(notes)', $run['stdout']);
    }

    public function testPruneAllSweepsEveryDatabaseWithPerDbAccounting(): void
    {
        $this->seedExpiredRow('d1', 'x');
        $this->seedExpiredRow('n1', 'y', 'notes');
        $this->seedExpiredRow('n2', 'z', 'notes');
        $this->seedExpiredRow('a1', 'w', 'archive');

        $all = $this->runKv(['prune', '--all']);
        $this->assertSame(0, $all['exit'], $all['stderr']);
        $this->assertStringContainsString('Removed 1 expired entry. (archive)', $all['stdout']);
        $this->assertStringContainsString('Removed 1 expired entry. (default)', $all['stdout']);
        $this->assertStringContainsString('Removed 2 expired entries. (notes)', $all['stdout']);
        // Everything expired is gone everywhere: a second sweep is silent.
        $again = $this->runKv(['prune', '--all']);
        $this->assertStringContainsString('No expired entries to remove.', $again['stdout']);
    }

    public function testPruneUnknownDatabaseRefusesWithoutLaundering(): void
    {
        // "Nothing to prune" would be a LIE about a database that does not
        // exist — the typo class is exactly what this verb used to hide.
        $this->seedExpiredRow('live', 'in-default');
        $run = $this->runKv(['prune', '--db', 'othes']);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString("does not exist", $run['stderr']);
        $this->assertStringContainsString('default', $run['stderr'], 'the refusal must name what IS present');
    }

    public function testPruneReservedSettingsDatabaseStillRefusedThroughNewDoors(): void
    {
        // S19's guard binds every write door; the two NEW prune doors are
        // write doors too.
        $run = $this->runKv(['prune', '--db', 'settings']);
        $this->assertSame(2, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('reserved', $run['stderr']);
    }

    public function testPruneScopeContradictionsAreUsageErrors(): void
    {
        foreach ([['prune', '--all', '--db', 'notes'], ['prune', '--db', 'a', 'k@b'], ['prune', 'bareword']] as $args) {
            $run = $this->runKv($args);
            $this->assertSame(2, $run['exit'], implode(' ', $args) . ' => ' . $run['stderr']);
        }
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

    public function testReimportOverTheSameKeysAnnouncesTheOverwrites(): void
    {
        // candy-kv-051 user-visible contract: a silent clobber is how a
        // stale dump quietly rewrites a live store; the second answer must
        // carry the overwrite sentence.
        $dump = '{"a":"1","b":"2"}';
        $this->runKvFeeding(['import', 'json', '-'], $dump);
        $again = $this->runKvFeeding(['import', 'json', '-'], $dump);
        $this->assertSame(0, $again['exit'], $again['stderr']);
        $this->assertStringContainsString('Imported 2 entries.', $again['stdout']);
        $this->assertStringContainsString('Overwrote 2 entries.', $again['stdout']);
    }

    public function testIfExistsFlagIsHonouredThroughTheBinary(): void
    {
        $first = '{"live":"original"}';
        $this->runKvFeeding(['import', 'json', '-'], $first);

        $skip = $this->runKvFeeding(['import', 'json', '-', '--if-exists=skip'], '{"live":"poisoned"}');
        $this->assertSame(0, $skip['exit'], $skip['stderr']);
        $get = $this->runKv(['get', 'live']);
        $this->assertSame('original', \trim($get['stdout']));

        $fail = $this->runKvFeeding(['import', 'json', '-', '--if-exists=fail'], '{"live":"poisoned","fresh":"v"}');
        $this->assertSame(1, $fail['exit'], $fail['stdout']);
        $this->assertStringContainsString("'live'", $fail['stderr']);
        $this->assertSame(1, $this->runKv(['get', 'fresh'])['exit'], 'the rolled-back prefix must not exist');

        $bogus = $this->runKvFeeding(['import', 'json', '-', '--if-exists=clobber'], '{}');
        $this->assertSame(2, $bogus['exit'], 'unknown mode is a usage-class refusal');
        $this->assertStringContainsString('Unknown --if-exists mode', $bogus['stderr']);
    }

    public function testCliRefusesStreamWrapperImportPathNamingScheme(): void
    {
        // kv-019 user-visible contract: wrapper URLs refuse at the import
        // door, the refusal names the scheme, and nothing is imported.
        $run = $this->runKvFeeding(['import', 'json', 'phar://nope.phar/data.json'], '{}');
        $this->assertSame(1, $run['exit'], $run['stdout'] . $run['stderr']);
        $this->assertStringContainsString('stream wrapper "phar://"', $run['stderr']);
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

    public function testDeclaredExtensionsAndBootGateNameTheSameSet(): void
    {
        // candy-kv-057 pair law: an ext-* in composer require with no boot
        // gate is a laundered fatal waiting for the one machine that lacks
        // it; a gate entry with no require lets the silent degrade back in.
        // One list, two surfaces — pinned equal.
        $manifest = \json_decode((string) \file_get_contents(\dirname(__DIR__) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $declared = \array_values(\array_filter(
            \array_keys($manifest['require']),
            static fn (string $dep): bool => \str_starts_with($dep, 'ext-'),
        ));
        $declared = \array_map(static fn (string $dep): string => \substr($dep, 4), $declared);
        \sort($declared);

        $binSource = (string) \file_get_contents(\dirname(__DIR__) . '/bin/candy-kv');
        $this->assertSame(1, \preg_match('/foreach \(\[(.*?)\] as \$requiredExtension\)/s', $binSource, $m), 'boot gate loop must stay findable');
        \preg_match_all('/[\x27"]([a-z0-9_]+)[\x27"]/', $m[1], $named);
        $gated = $named[1];
        \sort($gated);

        $this->assertSame($declared, $gated);
    }

    public function testBootGateRefusesMissingExtensionsByName(): void
    {
        // candy-kv-057: `php -n` drops the ini-loaded shared extensions, and
        // this runtime's sqlite3/mbstring are exactly those (probed live —
        // on a build that statics them the probe answers YES and the gate
        // cannot be armed, so the test says so instead of passing silently).
        $probe = self::spawnBounded([\PHP_BINARY, '-n', '-r', 'echo extension_loaded("sqlite3") || extension_loaded("mbstring") ? "YES" : "NO";']);
        if ($probe['stdout'] !== 'NO') {
            $this->markTestSkipped('this php keeps sqlite3/mbstring even without an ini; the -n boot gate cannot be armed');
        }

        $env = \getenv();
        $env['XDG_CONFIG_HOME'] = $this->configHome;
        $bin = dirname(__DIR__) . '/bin/candy-kv';

        $get = self::spawnBounded([\PHP_BINARY, '-n', $bin, 'get', 'whatever'], $env);
        $this->assertSame(1, $get['exit'], $get['stdout'] . $get['stderr']);
        // sqlite3 is checked first, so it is the extension named — and only
        // the named ONE, not a lecture: the message is the diagnosis.
        $this->assertStringContainsString('requires the PHP extension "sqlite3"', $get['stderr']);
        $this->assertStringNotContainsString('Class not found', $get['stderr']);
        $this->assertFileDoesNotExist($this->configHome . '/skate/default.db', 'the gate must run before any store boot');

        // The purity law rides along: the help verb answers even when the
        // runtime is too bare to store anything — it IS the repair manual.
        $help = self::spawnBounded([\PHP_BINARY, '-n', $bin, '--help'], $env);
        $this->assertSame(0, $help['exit'], $help['stderr']);
        $this->assertStringContainsString('candy-kv', $help['stdout']);
        $this->assertDirectoryDoesNotExist($this->configHome . '/skate');
    }
}
