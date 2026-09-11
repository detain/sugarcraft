<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Support\TimedFileLock;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\Concerns\TruncatesOutput;
use SugarCraft\Crush\Workflows\Workflow;

/**
 * E633-SLICE-1: a doc-block that quotes measured figures AND draws a
 * qualitative conclusion from them reds instead of rotting.
 *
 * The parent entry's acceptance test is "a mutation of each CONCLUSION, not of
 * the figure underneath it", so each arm below parses the SENTENCE out of the
 * live source and recomputes every number and multiplier it states from the
 * constants it cites — changing a constant, a figure, or the multiplier
 * without the other two reddens here. Scope is the src/ directories no other
 * round-63 lane owns (Support, Skills, Diagnostics; full verdicts and the
 * remainder ledger live in the round-63 E633 notes).
 *
 * VACUITY: every arm asserts its pattern matched before comparing (a deleted
 * claim escapes the pin only by also deleting the test's ability to silently
 * pass), and the two rewritten sentences are themselves pinned as prose —
 * E633's own lesson that an unpinned correction rots back into the claim it
 * corrected.
 *
 * E686 TRANCHE-2 (round-65, lane be) extends the family to Providers, LSP,
 * MCP, Agents, Workflows and the docs/ pages: same rule, no exceptions — a
 * figure with an in-repo referent earns a live re-derivation, a figure without
 * one loses its digit, and a measured/historical figure stays labeled-by-
 * method. New symbols cited by name only, never by line number (line-number
 * prose rots; that is E686's own finding on this page).
 *
 * @internal
 */
final class DocFigureProseDriftTest extends TestCase
{
    /**
     * ToolIpcFiles::STALE_AFTER_SECONDS justifies the hour against three named
     * budgets and a "~Nx the largest" multiplier. Every figure in that
     * sentence is re-derived live; at the time of writing 3600/120 is exactly
     * 30, so "~30x" holds — if EngineBackend's silence cap moves to 1800s the
     * sentence would claim 30x of a 2x margin and must red here instead.
     */
    public function testStalePayloadCutoffProseSurvivesItsConstants(): void
    {
        $doc = self::docBlockOf(ToolIpcFiles::class, 'STALE_AFTER_SECONDS');

        self::assertSame(
            1,
            preg_match(
                '/(\d+)s group deadline.*?(\d+)s tool timeout.*?\((\d+)s of silence\).*?An hour is.*?~(\d+)x the largest/s',
                $doc,
                $m,
            ),
            'the STALE_AFTER_SECONDS justification no longer names its three budgets and its '
            . 'multiplier in one sentence — rewrite the pin with the prose, do not delete it',
        );

        $deadline = (int) (new \ReflectionClass(Runtime::class))->getConstant('PARALLEL_TOOL_DEADLINE_SECONDS');
        $toolTimeout = (int) (new \ReflectionClass(Chat::class))->getConstant('PARALLEL_TOOL_TIMEOUT_SECONDS');
        $silence = (int) (new \ReflectionClass(EngineBackend::class))->getConstant('COMPLETE_TIMEOUT_SECONDS');
        $stale = (int) (new \ReflectionClass(ToolIpcFiles::class))->getConstant('STALE_AFTER_SECONDS');

        self::assertSame($deadline, (int) $m[1], 'prose group-deadline figure drifted from Runtime');
        self::assertSame($toolTimeout, (int) $m[2], 'prose tool-timeout figure drifted from Chat');
        self::assertSame($silence, (int) $m[3], 'prose silence-cap figure drifted from EngineBackend');

        $largest = max($deadline, $toolTimeout, $silence);
        self::assertSame(
            intdiv($stale, $largest),
            (int) $m[4],
            "the '~Nx the largest' multiplier no longer matches STALE_AFTER_SECONDS / max(budgets)",
        );
        self::assertSame(3600, $stale, 'the sentence still says "An hour" — keep the two in step');
    }

    /**
     * The sink clip must fit one datagram WITH margin — that is the pinned
     * form of the sentence, replacing the "order of magnitude" claim which
     * was FALSE as written (the worst case the very same paragraph states is
     * under 1,700 bytes; 8,192 is ~4.9x, not 10x).
     */
    public function testNoticeWorstCaseFitsOneDatagramWithPinnedMargin(): void
    {
        $maxChars = RuntimeNoticeSink::MAX_CHARS;
        $suffixLen = \strlen(\sprintf(
            RuntimeNoticeSink::OVERFLOW_FORMAT,
            \PHP_INT_MAX,
            's',
        ));
        // MAX_CHARS counts UTF-8 characters; the widest encoding is 4 bytes.
        $worstCaseBytes = $maxChars * 4 + $suffixLen;

        $datagram = (int) (new \ReflectionClass(RuntimeNoticeSink::class))->getConstant('DATAGRAM_BYTES');

        self::assertIsInt($datagram, 'DATAGRAM_BYTES vanished — the clip-size claim has no referent');
        self::assertLessThan(
            $datagram,
            $worstCaseBytes,
            'the worst-case notice no longer fits one datagram; the MAX_CHARS doc-block still '
            . 'promises it does',
        );
        self::assertGreaterThanOrEqual(
            4,
            intdiv($datagram, $worstCaseBytes),
            'the margin collapsed under 4x — the sentence says "with margin to spare", so either '
            . 'restore the margin or reword the sentence here and in RuntimeNoticeSink together',
        );

        $doc = self::docBlockOf(RuntimeNoticeSink::class, 'DATAGRAM_BYTES');
        self::assertStringNotContainsString(
            'order of magnitude',
            $doc,
            'the retracted 10x claim is back; the arithmetic above is ~5x and the prose must not '
            . 'oversell it (E633: the correction carried the same defect as the claim)',
        );
        self::assertStringContainsString('with margin to spare', $doc, 'the pinned sentence moved');
    }

    /**
     * MAX_DIRECTORIES is a runaway guard, not a sizing target — pinned as
     * prose, and the retired multiplier is asserted ABSENT so the "two orders
     * of magnitude" headroom claim (built on an unpinned "tens of directories"
     * premise) cannot creep back.
     */
    public function testSkillDirectoryCapIsFramedAsARunawayGuard(): void
    {
        $doc = self::docBlockOf(SkillLoader::class, 'MAX_DIRECTORIES');

        self::assertStringContainsString('runaway guard, not a sizing target', $doc);
        self::assertStringNotContainsString('orders of magnitude', $doc);
        self::assertStringNotContainsString('tens of directories', $doc);
    }


    /**
     * E685 (E633-SLICE-2): the trait headline reserves Read a quarter and
     * states the resulting multiples ("sixteen times" DEFAULT_MAX_INSTRUCTION_
     * BYTES, "four times" DEFAULT_MAX_OUTPUT_BYTES) - live arithmetic on three
     * constants, so every word is re-derived here rather than trusted. The two
     * de-digitalised corrections minted the same round (the size-agnostic
     * CLAUDE.md sentence; the digit-less Glob rationale) are pinned as prose:
     * E633's lesson that an unpinned correction rots back into the claim it
     * corrected.
     */
    public function testToolsReserveMultipliersSurviveTheirConstants(): void
    {
        $traitDoc = (string) file_get_contents(
            \dirname(__DIR__, 2) . '/src/Tools/Concerns/TruncatesOutput.php',
        );

        self::assertSame(
            1,
            preg_match(
                '/default `\$\w+` is (\d+) MiB.{0,30}?reserve is (\d+).{0,12}?KiB.{0,12}?sixteen times the flat'
                . ' \{\@see DEFAULT_MAX_INSTRUCTION_BYTES\}.*?and four times this/s',
                $traitDoc,
                $m,
            ),
            'the reserve sentence no longer spells its MiB, its KiB reserve, and both multipliers'
            . ' in one sentence — rewrite the pin with the prose, do not delete it',
        );

        $readDefault = null;
        foreach ((new \ReflectionClass(Read::class))->getMethods() as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->isDefaultValueAvailable() && $parameter->getDefaultValue() === 1_048_576) {
                    $readDefault = 1_048_576;
                    break 2;
                }
            }
        }
        self::assertNotNull(
            $readDefault,
            'nothing in Read defaults to 1 MiB any more — the headline still says "is 1 MiB",'
            . ' so the sentence and this probe must move together',
        );
        self::assertSame((int) $m[1], intdiv($readDefault, 1_048_576), 'prose MiB figure drifted from Read');
        self::assertSame((int) $m[2], intdiv(intdiv($readDefault, 4), 1024), 'prose reserve-KiB drifted from Read/4');

        $instruction = (int) (new \ReflectionClass(TruncatesOutput::class))->getConstant('DEFAULT_MAX_INSTRUCTION_BYTES');
        $output = (int) (new \ReflectionClass(TruncatesOutput::class))->getConstant('DEFAULT_MAX_OUTPUT_BYTES');
        $reserve = intdiv($readDefault, 4);
        self::assertSame(16, intdiv($reserve, $instruction), '"sixteen times" is no longer the true multiple of DEFAULT_MAX_INSTRUCTION_BYTES');
        self::assertSame(4, intdiv($reserve, $output), '"four times" is no longer the true multiple of DEFAULT_MAX_OUTPUT_BYTES');

        // pinned corrections (each false as a digit, de-digitalised this round)
        self::assertStringNotContainsString('9,611-byte `CLAUDE.md` verbatim', $traitDoc, 'the stale repo-CLAUDE.md digit is back — the file is 9,167 bytes at 07a53049b and any digit rots');
        self::assertStringContainsString('verbatim - comfortably true at any', $traitDoc, 'the corrective sentence moved');
        $globDoc = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/Glob.php');
        self::assertStringNotContainsString('112,000', $globDoc, 'the unlabeled whole-tree figure is back; the point needs no digit');
        self::assertStringContainsString('whole tree and discarding nearly all of it', $globDoc, 'the Glob corrective sentence moved');
    }

    /**
     * E686 tranche-2 (A): HttpClientDefaults states its connect timeout against
     * an audited window, the engine idle ceiling it must stay under, and the
     * MCP client's different domain. Every figure here is re-derived from the
     * constant or cross-referenced symbol it names; the loose sentences the
     * judgment kept ("roughly an order of magnitude" against its own named
     * 1-3s premise; libcurl's external 300s; PHP's default_socket_timeout 60s)
     * stay unpinned because no symbol in this repo owns them.
     */
    public function testProviderConnectTimeoutProseSurvivesItsConstants(): void
    {
        $file = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Providers/Concerns/HttpClientDefaults.php');
        $doc = self::docBlockOf(HttpClientDefaults::class, 'CONNECT_TIMEOUT_SECONDS');

        self::assertSame(
            1,
            preg_match('/(\d+)s sits mid-range of the audited (\d+)-(\d+)s window/', $doc, $m),
            'the connect-timeout justification no longer states its figure and its window together',
        );
        self::assertSame(15.0, (float) (new \ReflectionClass(HttpClientDefaults::class))->getConstant('CONNECT_TIMEOUT_SECONDS'));
        self::assertSame((int) $m[1], 15, 'prose seconds figure drifted from CONNECT_TIMEOUT_SECONDS');
        self::assertGreaterThanOrEqual((int) $m[2], 15.0, '15s is no longer at or above the audited floor the sentence admits');
        self::assertLessThanOrEqual((int) $m[3], 15.0, '15s is no longer at or below the audited ceiling the sentence admits');

        $idleCeiling = (int) (new \ReflectionClass(EngineBackend::class))->getConstant('COMPLETE_TIMEOUT_SECONDS');
        self::assertSame(
            1,
            preg_match('/(\d+)s idle timer/', $file, $m),
            'the trait headline no longer names the engine idle timer it must undercut',
        );
        self::assertSame($idleCeiling, (int) $m[1], 'prose idle-timer figure drifted from EngineBackend::COMPLETE_TIMEOUT_SECONDS');

        self::assertSame(
            2,
            preg_match_all('/`EngineBackend::COMPLETE_TIMEOUT_SECONDS` \((\d+)s\)/', $file, $m),
            'exactly two sentences may cite COMPLETE_TIMEOUT_SECONDS with a digit (trait headline + stream-read justification)',
        );
        foreach ($m[1] as $stated) {
            self::assertSame($idleCeiling, (int) $stated, 'a prose (Ns) cite of COMPLETE_TIMEOUT_SECONDS drifted from the constant');
        }

        self::assertSame(
            1,
            preg_match('/`src\/MCP\/McpClient\.php`\'s `timeout => (\d+)`/', $file, $m),
            'the contrast sentence with the MCP client\'s timeout moved',
        );
        $mcpClient = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/MCP/McpClient.php');
        self::assertSame(
            1,
            preg_match("/'timeout' => (\d+)/", $mcpClient, $c),
            'McpClient no longer constructs its client with a literal timeout => N — update both sides',
        );
        self::assertSame((int) $m[1], (int) $c[1], 'prose MCP timeout drifted from McpClient\'s constructor');

        $streamDoc = self::docBlockOf(HttpClientDefaults::class, 'STREAM_READ_IDLE_TIMEOUT_SECONDS');
        self::assertStringContainsString('completely silent for an hour', $streamDoc, 'the hour claim moved');
        self::assertSame(3600.0, (float) (new \ReflectionClass(HttpClientDefaults::class))->getConstant('STREAM_READ_IDLE_TIMEOUT_SECONDS'), 'the sentence still says "an hour" — keep the two in step');
    }

    /**
     * E686 tranche-2 (B): TransientFailure's base-backoff prose names the
     * constant in milliseconds.
     */
    public function testProvidersBaseBackoffProseSurvivesItsConstant(): void
    {
        $doc = self::docBlockOf(TransientFailure::class, 'BASE_BACKOFF_MICROSECONDS');

        self::assertSame(
            1,
            preg_match('/(\d+)ms is long enough/', $doc, $m),
            'the base-backoff sentence no longer states its millisecond figure',
        );
        self::assertSame(
            intdiv((int) (new \ReflectionClass(TransientFailure::class))->getConstant('BASE_BACKOFF_MICROSECONDS'), 1000),
            (int) $m[1],
            'prose backoff milliseconds drifted from BASE_BACKOFF_MICROSECONDS',
        );
    }

    /**
     * E686 tranche-2 (C): LspConnection states the stderr drain rate as a
     * product of its own loop bound and chunk size over its poll interval, and
     * the select-failure backstop against the property default its deadline
     * uses. The measured 7.1s/1408-per-second pair is held labeled-by-method;
     * only digits with in-repo referents are pinned.
     */
    public function testLspDrainRateAndSelectBackstopProseSurviveTheirLiterals(): void
    {
        $lsp = self::sourceOf('LSP/LspConnection.php');
        $doc = self::docBlockOf(LspConnection::class, 'WRITE_POLL_MICROS');

        self::assertSame(
            1,
            preg_match('/(\d+) × (\d+) = (\d+) KiB per pass, so (\d+) ms is a ceiling of roughly (\d+) MB\/s/s', $doc, $m),
            'the drain-rate sentence no longer spells its product, its poll ceiling and its rate in one breath',
        );
        $drainBody = self::bodyExcerpt($lsp, 'drainStderr');
        self::assertSame(1, preg_match('/for \(\$i = 0; \$i < (\d+); \$i\+\+\)/', $drainBody, $loop), 'drainStderr lost its counted loop — the prose 16 lost its referent');
        self::assertSame(1, preg_match('/fread\(\$this->pipes\[2\], (\d+)\)/', $drainBody, $chunk), 'drainStderr no longer reads a fixed chunk — the prose 8192 lost its referent');

        self::assertSame((int) $loop[1], (int) $m[1], 'prose pass count drifted from drainStderr\'s loop bound');
        self::assertSame((int) $chunk[1], (int) $m[2], 'prose chunk size drifted from drainStderr\'s fread');
        self::assertSame((int) $m[3] * 1024, (int) $loop[1] * (int) $chunk[1], 'the stated KiB product no longer equals passes x chunk');
        self::assertSame((int) (new \ReflectionClass(LspConnection::class))->getConstant('WRITE_POLL_MICROS'), (int) $m[4] * 1000, 'prose poll milliseconds drifted from WRITE_POLL_MICROS');
        $bytesPerSecond = intdiv((int) $loop[1] * (int) $chunk[1] * 1_000_000, (int) $m[4] * 1000);
        self::assertSame(intdiv($bytesPerSecond, 1_000_000), (int) $m[5], "the 'roughly Nx MB/s' ceiling drifted from passes*chunk/poll");

        $backstopDoc = self::docBlockOf(LspConnection::class, 'MAX_CONSECUTIVE_SELECT_FAILURES');
        self::assertSame(
            1,
            preg_match('/at the (\d+\.\d+)s default the\s*\*?\s*backstop/s', $backstopDoc, $m),
            'the backstop sentence no longer names the deadline default it races',
        );
        self::assertSame(
            (float) (new \ReflectionProperty(LspConnection::class, 'requestTimeout'))->getDefaultValue(),
            (float) $m[1],
            'prose deadline default drifted from $requestTimeout',
        );
        self::assertSame(
            1,
            preg_match('/(\d+) failures in/', $backstopDoc, $m),
            'the measured failure-rate sentence no longer states its count',
        );
        self::assertSame(
            (int) (new \ReflectionClass(LspConnection::class))->getConstant('MAX_CONSECUTIVE_SELECT_FAILURES'),
            (int) $m[1],
            'prose failure count drifted from MAX_CONSECUTIVE_SELECT_FAILURES',
        );
    }

    /**
     * E686 tranche-2 (D): three independent children all justify a 65536-byte
     * stderr tail as "one pipe buffer on this host". The figure is only true as
     * a family if all three constants move together, and each 64 stays 64*1024
     * of its own constant; the host labels (PHP/Linux versions, "this host")
     * are held as measured-domain sentences by the preserved substrings.
     */
    public function testStderrTailSixtyFourKibibyteFamilyAgrees(): void
    {
        $values = [];
        foreach ([LspConnection::class, ClaudeCodeProvider::class] as $class) {
            $value = (int) (new \ReflectionClass($class))->getConstant('MAX_STDERR_BYTES');
            self::assertSame(65536, $value, "{$class}::MAX_STDERR_BYTES moved — the three-site family sentence must move with it");
            $doc = self::docBlockOf($class, 'MAX_STDERR_BYTES');
            self::assertSame(
                1,
                preg_match('/(\d+) KiB is one pipe buffer on this host/', $doc, $m),
                "{$class} lost the one-pipe-buffer justification its constant's value rests on",
            );
            self::assertSame(intdiv($value, 1024), (int) $m[1], "{$class}'s prose KiB figure drifted from MAX_STDERR_BYTES");
            $values[$class] = $value;
        }

        $stdio = self::sourceOf('MCP/StdioMcpServer.php');
        self::assertSame(
            1,
            preg_match('/— (\d+) KiB on this host \(PHP 8\.3\.6, Linux 6\.8\)/', $stdio, $m),
            'the StdioMcpServer deadlock narrative no longer names its host-labeled buffer size',
        );
        $stdioValue = (int) (new \ReflectionClass(StdioMcpServer::class))->getConstant('MAX_STDERR_BYTES');
        self::assertSame(intdiv($stdioValue, 1024), (int) $m[1], 'StdioMcpServer prose KiB drifted from its MAX_STDERR_BYTES');
        self::assertSame($stdioValue, $values[LspConnection::class], 'the stderr-tail family forked — one constant moved without the others');
    }

    /**
     * E686 tranche-2 (E): the stdio pump bound is a product of two literals in
     * sibling methods, and the start timeout is spelled in words in src and in
     * decimals in docs/MCP.md — all four must agree with the constant.
     */
    public function testStdioPumpBoundAndStartTimeoutProseSurviveTheirLiterals(): void
    {
        $stdio = self::sourceOf('MCP/StdioMcpServer.php');

        self::assertSame(
            1,
            preg_match('/- up to (\d+) passes x (\d+) bytes per call/', $stdio, $m),
            'the pumpStderr bound sentence no longer spells its passes and chunk',
        );
        self::assertSame(1, preg_match('/\$pass < (\d+)/', self::bodyExcerpt($stdio, 'pumpStderr'), $loop), 'pumpStderr lost its loop bound');
        self::assertSame(1, preg_match('/fread\(\$this->pipes\[2\], (\d+)\)/', self::bodyExcerpt($stdio, 'absorbStderr'), $chunk), 'absorbStderr no longer reads a fixed chunk');
        self::assertSame((int) $loop[1], (int) $m[1], 'prose pass count drifted from pumpStderr\'s loop');
        self::assertSame((int) $chunk[1], (int) $m[2], 'prose chunk size drifted from absorbStderr\'s fread');

        $startValue = (float) (new \ReflectionClass(StdioMcpServer::class))->getConstant('DEFAULT_START_TIMEOUT_SECONDS');
        $startDoc = self::docBlockOf(StdioMcpServer::class, 'DEFAULT_START_TIMEOUT_SECONDS');
        self::assertStringContainsString('SIXTY SECONDS', $startDoc, 'the start-timeout sentence is spelled in words — keep both halves in step');
        self::assertSame(60.0, $startValue, 'the word says SIXTY; the constant must too');

        $mcpDoc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        self::assertSame(
            1,
            preg_match('/DEFAULT_START_TIMEOUT_SECONDS`, which\s+is `(\d+\.\d+)`\./s', $mcpDoc, $m),
            'docs/MCP.md no longer states the start-timeout fallback in decimals — re-pin with the prose',
        );
        self::assertSame($startValue, (float) $m[1], 'docs/MCP.md decimal drifted from DEFAULT_START_TIMEOUT_SECONDS');
    }

    /**
     * E686 tranche-2 (F): the three agent-side backoff windows — pool reap
     * (shared with EngineBackend by an explicit "same pair of numbers"
     * contract), mailbox poll (5ms → 100ms, literals in the method body), and
     * the flock poll/deadline pair that must keep matching the SQLite
     * busyTimeout it names.
     */
    public function testAgentBackoffWindowsProseSurviveTheirConstants(): void
    {
        $poolClass = new \ReflectionClass(AgentWorkerPool::class);
        $poolDoc = self::docBlockOf(AgentWorkerPool::class, 'REAP_ATTEMPTS');
        self::assertSame(
            1,
            preg_match('/(\d+) attempts x[^\d]*(\d+)ms is a (\d+)ms ceiling/s', $poolDoc, $m),
            'the reap-window sentence no longer spells attempts, poll and ceiling together',
        );
        self::assertSame((int) $poolClass->getConstant('REAP_ATTEMPTS'), (int) $m[1], 'prose attempts drifted from AgentWorkerPool::REAP_ATTEMPTS');
        self::assertSame(intdiv((int) $poolClass->getConstant('REAP_POLL_MICROSECONDS'), 1000), (int) $m[2], 'prose poll drifted from REAP_POLL_MICROSECONDS');
        self::assertSame((int) $m[1] * (int) $m[2], (int) $m[3], 'the stated ceiling is no longer attempts x poll');

        $engineClass = new \ReflectionClass(EngineBackend::class);
        self::assertSame(
            (int) $poolClass->getConstant('REAP_ATTEMPTS'),
            (int) $engineClass->getConstant('REAP_ATTEMPTS'),
            'the prose promises "the same pair of numbers" as EngineBackend — one side moved',
        );
        self::assertSame(
            (int) $poolClass->getConstant('REAP_POLL_MICROSECONDS'),
            (int) $engineClass->getConstant('REAP_POLL_MICROSECONDS'),
            'the prose promises "the same pair of numbers" as EngineBackend — one side moved',
        );

        $mailboxDoc = self::methodDocOf(Mailbox::class, 'waitForMessage');
        $mailbox = self::sourceOf('Agents/Mailbox.php');
        self::assertSame(
            1,
            preg_match('/\((\d+)ms → (\d+)ms cap\)/s', $mailboxDoc, $m),
            'the mailbox backoff sentence no longer names its floor and cap',
        );
        self::assertSame(1, preg_match('/\$pollIntervalSeconds = ([\d.]+);/', $mailbox, $init), 'mailbox lost its initial poll literal');
        self::assertSame(1, preg_match('/\$maxPollIntervalSeconds = ([\d.]+);/', $mailbox, $cap), 'mailbox lost its cap literal');
        self::assertSame((int) $m[1], (int) round((float) $init[1] * 1000), 'prose floor drifted from $pollIntervalSeconds');
        self::assertSame((int) $m[2], (int) round((float) $cap[1] * 1000), 'prose cap drifted from $maxPollIntervalSeconds');
        self::assertMatchesRegularExpression('/\$pollIntervalSeconds \* 2/', $mailbox, 'the backoff is no longer exponential — the prose says it is');

        $taskListDoc = self::methodDocOf(TaskList::class, 'flockTimed');
        self::assertSame(
            1,
            preg_match('/polled every (\d+)ms[^\d]*?([\d.]+)s to match the SQLite busyTimeout/s', $taskListDoc, $m),
            'the flock sentence no longer ties its poll, deadline and busyTimeout match together',
        );
        self::assertSame(
            intdiv((int) (new \ReflectionClass(TimedFileLock::class))->getConstant('POLL_MICROSECONDS'), 1000),
            (int) $m[1],
            'prose poll drifted from TimedFileLock::POLL_MICROSECONDS',
        );
        self::assertSame(
            (float) (new \ReflectionClass(TaskList::class))->getConstant('DEFAULT_LOCK_WAIT_SECONDS'),
            (float) $m[2],
            'prose deadline drifted from DEFAULT_LOCK_WAIT_SECONDS',
        );
        self::assertSame(1, preg_match('/busyTimeout\((\d+)\)/', self::sourceOf('Agents/TaskList.php'), $busy), 'the SQLite busyTimeout literal vanished — the "to match" claim has no referent');
        self::assertSame((int) $busy[1], (int) round((float) $m[2] * 1000), 'busyTimeout and the flock deadline are no longer the same duration');
    }

    /**
     * E686 tranche-2 (G): the Workflow defaults are quoted in the promoted
     * constructor doc, again in docs/WORKFLOWS.md twice (own defaults and the
     * 300-vs-3600 domain note), and the 300 fallback is repeated at every
     * engine call site — all derived from ReflectionParameter, never trusted.
     */
    public function testWorkflowDefaultFiguresSurviveTheirSignatures(): void
    {
        $constructor = (new \ReflectionClass(Workflow::class))->getConstructor();
        $defaults = [];
        foreach ($constructor->getParameters() as $parameter) {
            if ($parameter->isDefaultValueAvailable()) {
                $defaults[$parameter->getName()] = $parameter->getDefaultValue();
            }
        }
        self::assertSame(5, $defaults['maxConcurrent'] ?? null, 'Workflow::$maxConcurrent default moved');
        self::assertSame(3600, $defaults['timeout'] ?? null, 'Workflow::$timeout default moved');

        $workflowDoc = (string) $constructor->getDocComment();
        self::assertSame(
            1,
            preg_match('/\(default (\d+)\)\.\s*\n\s*\*\s*@param int\s+\$timeout\s+Per-stage timeout in seconds \(default (\d+) = 1 hour\)/s', $workflowDoc, $m),
            'the @param block no longer spells both defaults with the hour gloss',
        );
        self::assertSame((int) $m[1], $defaults['maxConcurrent'], '@param default drifted from the signature');
        self::assertSame((int) $m[2], $defaults['timeout'], '@param default drifted from the signature');

        $workflowsDoc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/WORKFLOWS.md');
        self::assertSame(
            1,
            preg_match('/Defaults are `Workflow`\'s own: `maxConcurrent: (\d+)`, `timeout: (\d+)`,/', $workflowsDoc, $m),
            'docs/WORKFLOWS.md no longer mirrors Workflow\'s own defaults',
        );
        self::assertSame((int) $m[1], $defaults['maxConcurrent'], 'docs maxConcurrent figure drifted from the signature');
        self::assertSame((int) $m[2], $defaults['timeout'], 'docs timeout figure drifted from the signature');

        self::assertSame(
            1,
            preg_match('/`\$task->timeout \?\? (\d+)` seconds, `\$(?:task|verifier)->retries \?\? (\d+)`/', $workflowsDoc, $m),
            'the per-task fallback sentence moved',
        );
        self::assertSame(300, (int) $m[1], 'the documented per-task fallback is no longer 300');
        self::assertNotSame(
            (int) $m[1],
            $defaults['timeout'],
            "the engine's per-task fallback and Workflow's per-stage default are documented as DIFFERENT numbers (300 vs 3600) — if they ever coincide, this note rots here first",
        );

        $engine = self::sourceOf('Workflows/WorkflowEngine.php');
        preg_match_all('/timeout: \$task->timeout \?\? (\d+)/', $engine, $engineTimeouts);
        self::assertNotEmpty($engineTimeouts[1], 'no engine call site carries the $task->timeout ?? fallback the docs describe');
        self::assertSame([300], array_values(array_unique(array_map('intval', $engineTimeouts[1]))), 'engine per-task fallbacks are no longer uniformly the documented figure');
        preg_match_all('/maxRetries: \$\w+->retries \?\? (\d+)/', $engine, $engineRetries);
        self::assertNotEmpty($engineRetries[1], 'no engine call site carries the retries fallback');
        self::assertSame([(int) $m[2]], array_values(array_unique(array_map('intval', $engineRetries[1]))), 'engine retry fallbacks are no longer uniformly the documented figure');
        self::assertStringContainsString(
            "default of {$defaults['timeout']}",
            $workflowsDoc,
            'the per-stage default in the domain note is no longer the signature value',
        );
    }

    /**
     * E686 tranche-2 (H): docs/ARCHITECTURE.md figures are either LIVE
     * re-counts (built-in tool files, slash commands, subcommand names) or
     * gone. The two size figures this page carried (bin/sugarcrush lines;
     * Bootstrap lines+methods) had both rotted and were de-digitalised this
     * tranche — the corrections are pinned as prose and the surviving
     * all-static property is checked by reflection, because that is the claim
     * the paragraph actually rests on.
     */
    public function testArchitecturePageCountersAreLiveOrDigitless(): void
    {
        $arch = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md');

        self::assertSame(
            1,
            preg_match('/Tools\\\\\*\s+(\d+) built-ins \+ MCP bridges/', $arch, $m),
            'the component tree no longer counts the built-in tools in its Tools row',
        );
        $builtIns = glob(\dirname(__DIR__, 2) . '/src/Tools/BuiltIn/*.php');
        self::assertIsArray($builtIns);
        self::assertCount((int) $m[1], $builtIns, 'the tree\'s built-in count is stale against src/Tools/BuiltIn/');

        self::assertStringNotContainsString('219 lines', $arch, 'the stale bin/sugarcrush line count is back — it moves every round (E686)');
        self::assertStringNotContainsString('4,253', $arch, 'the stale Bootstrap size figures are back — de-digitalised because they rotted (E686)');
        self::assertStringNotContainsString('70 methods', $arch, 'the stale Bootstrap method count is back — de-digitalised because it rotted (E686)');
        self::assertStringContainsString('quotes none on', $arch, 'the bin/sugarcrush corrective sentence moved');
        self::assertStringContainsString('thousands of lines, every one of its methods static', $arch, 'the Bootstrap corrective sentence moved');

        $methods = (new \ReflectionClass(Bootstrap::class))->getMethods();
        self::assertNotEmpty($methods);
        foreach ($methods as $method) {
            self::assertTrue($method->isStatic(), "Bootstrap::{$method->getName()}() is no longer static — the page still claims every method is");
        }

        self::assertSame(
            1,
            preg_match('/dispatch arms for (\d+)\s+built-in slash commands/s', $arch, $m),
            'the Chat paragraph no longer states its slash-command count',
        );
        self::assertCount((int) $m[1], CommandRegistry::slashCommands(), 'the documented slash-command count is stale against CommandRegistry::slashCommands()');

        self::assertSame(
            1,
            preg_match('/and the (five)\s+subcommands \(([^)]*)\)/s', $arch, $m),
            'the pre-flight paragraph no longer spells its subcommand word-count and list together',
        );
        preg_match_all('/`([^`]+)`/', $m[2], $listed);
        // ParsedArgs shares src/Cli/ArgvParser.php and is not PSR-4 loadable on its
        // own; touching ArgvParser first is what makes the sibling class real.
        self::assertTrue(class_exists(ArgvParser::class), 'ArgvParser vanished — ParsedArgs has no loader without it');
        $names = constant('SugarCraft\Crush\Cli\ParsedArgs::SUBCOMMANDS');
        self::assertCount(count($names), $listed[1], 'the prose subcommand list no longer has one entry per ParsedArgs::SUBCOMMANDS name');
        foreach ($names as $name) {
            $matched = false;
            foreach ($listed[1] as $entry) {
                if ($entry === $name || str_starts_with($entry, $name . ' ')) {
                    $matched = true;
                    break;
                }
            }
            self::assertTrue($matched, "ParsedArgs::SUBCOMMANDS gained '{$name}' but the pre-flight sentence's list did not");
        }
        $wordNumbers = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];
        self::assertSame(count($names), $wordNumbers[$m[1]] ?? -1, 'the spelled word no longer counts the live SUBCOMMANDS list — flip both together (census-trio lesson)');
    }

    private static function sourceOf(string $relative): string
    {
        $text = file_get_contents(\dirname(__DIR__, 2) . '/src/' . $relative);

        self::assertIsString($text, "src/{$relative} vanished — a prose pin lost its file");

        return $text;
    }

    private static function bodyExcerpt(string $fileText, string $method, int $window = 3000): string
    {
        $position = strpos($fileText, "function {$method}(");

        self::assertNotFalse($position, "no function {$method}() in this file — the code the prose cites moved or vanished");

        return substr($fileText, $position, $window);
    }

    private static function methodDocOf(string $class, string $method): string
    {
        $doc = (new \ReflectionClass($class))->getMethod($method)->getDocComment();

        self::assertIsString($doc, "{$class}::{$method}() lost its doc-block — the claim this pins was deleted, not fixed");

        return $doc;
    }

    private static function docBlockOf(string $class, string $constant): string
    {
        $reflection = new \ReflectionClass($class);
        $property = $reflection->getReflectionConstant($constant);

        self::assertNotFalse($property, "{$class}::{$constant} no longer exists");
        $doc = $property->getDocComment();

        self::assertIsString($doc, "{$class}::{$constant} lost its doc-block — the claim this pins was deleted, not fixed");

        return $doc;
    }
}
