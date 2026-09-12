<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\ClaudeCodeMcpClient;
use SugarCraft\Crush\Cli\ArgvParser;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Commands\CommandRegistry;
use SugarCraft\Crush\Commands\CommandSpec;
use SugarCraft\Crush\Config\StatusLineCommand;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\EnvironmentBlock;
use SugarCraft\Crush\Context\MemoryBlock;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\LSP\LspConnection;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\Providers\ClaudeCodeProvider;
use SugarCraft\Crush\Providers\Concerns\HttpClientDefaults;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\TransientFailure;
use SugarCraft\Crush\Renderer;
use SugarCraft\Crush\Runtime;
use SugarCraft\Crush\Session\EnhancedSessionStore;
use SugarCraft\Crush\Skills\SkillLoader;
use SugarCraft\Crush\Skills\SkillPathNudge;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Support\TimedFileLock;
use SugarCraft\Crush\Support\ToolIpcFiles;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
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
 * E686 TRANCHE-3 (round-66, lane cb) adds the Context/Hooks/RuleLoader/MCP
 * arms below and grows the stderr-tail family with its fourth site — and
 * judged zero FALSE claims: every digit measured this tranche re-derived
 * exactly, which is what the campaign's pinning is FOR.
 *
 * E686 TRANCHE-4 (round-67, lane dl) moves the HOOKS.md `CRUSH_*` roster
 * claims onto live derivation from the `$fixed`/`stagePayloads()` arrays
 * (arms O-S below — the carried "7×/8×" counts were the last hand-typed
 * environment roster in the docs set) — and judged TWO FALSE sentences:
 * TROUBLESHOOTING.md still spelled the hook environment six `CRUSH_*` keys
 * (the pre-`_FILE` count, contradicting this page's eight), and HOOKS.md's
  * own parenthetical claimed the empty-`toolOutput` run "coincidentally shows
  * six lines" while the same page's table printed eight. Both fixed in prose
  * here, and the corrections pinned in both directions — E633's lesson.
  *
  * E686 TRANCHE-5 (round-68, lane ed) judges the carried HELD set. Nothing in
  * it turned out false, and nothing needed a prose edit; six carries instead
  * upgraded to pins (arms T-Y), all of them the conclusion half of a measured
  * figure: the "~128 KB" rounding and the oversize example sizes against the
  * live entry boundary, EnvironmentBlock's self-described "derivable part"
  * arithmetic, the splice-history pair both files state, EnhancedSessionStore's
  * internal sums, the 0.47s window as a cross-page family (arm D's idiom for a
  * digit no constant owns), and the ARCHITECTURE Chat paragraph's live count.
  * The digits of genuinely-host-timed sentences stay free — but every RELATION
  * the prose itself states about them now re-evaluates here, so a half-updated
  * sentence reds instead of quietly lying (ref: lane cb/be/dl ledgers).
  *
  * E686 TRANCHE-6 (round-69, lane fl) judges the 12-row carry and splits holds
  * the way arm W split ESS's: a measured absolute stays free while every
  * arithmetic RELATION the sentence itself hangs on it re-evaluates. The
  * live-pane tick family (Z) gets pinned to the probe's own `addPeriodicTimer`
  * literal — the carry's premise that the tick had no referent was wrong, it
  * just lives one file away; SkillRegistry's byte tables (AA) re-derive their
  * per-entry quotients, generator product and microsecond subtraction while
  * honoring the paragraph's explicit refusal to tie a total to an allocator's
  * answer; the nudge cost table (AB) pins its multiplier labels to the bytes
  * they divide and its margin sentence to three live constants. Nothing judged
  * FALSE; the two retracted-figure laws (stale-vs-live) follow arm Y.
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
     * E686 tranche-2 (D) + tranche-3 extension: four independent children all
     * justify a 65536-byte stderr tail as "one pipe buffer on this host" (the
     * fourth, ClaudeCodeMcpClient, joined at lane cb — the family grows with the
     * tree, it does not fork). The figure is only true as a family if all four
     * constants move together, and each 64 stays 64*1024 of its own constant;
     * the host labels (PHP/Linux versions, "this host") are held as
     * measured-domain sentences by the preserved substrings.
     */
    public function testStderrTailSixtyFourKibibyteFamilyAgrees(): void
    {
        $values = [];
        foreach ([LspConnection::class, ClaudeCodeProvider::class, ClaudeCodeMcpClient::class] as $class) {
            $value = (int) (new \ReflectionClass($class))->getConstant('MAX_STDERR_BYTES');
            self::assertSame(65536, $value, "{$class}::MAX_STDERR_BYTES moved — the four-site family sentence must move with it");
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

    /**
     * E686 tranche-3 (I): EnvironmentBlock's cap prose is pure cross-constant
     * arithmetic — 24,576 of fields, the 45,056/36,864 every-bound totals named
     * in exact KiB, the 25,600 whole-block promise, and four neighbor figures
     * cited as sizing anchors. Every digit here re-derives from the constants
     * it names; even the caption length (which rotted once, 100 → 91) is
     * re-counted live.
     */
    public function testContextBlockCapArithmeticSurvivesItsConstants(): void
    {
        $class = new \ReflectionClass(EnvironmentBlock::class);
        $diff = (int) $class->getConstant('DIFF_MAX_BYTES');
        $summary = (int) $class->getConstant('SUMMARY_MAX_BYTES');
        $branch = (int) $class->getConstant('BRANCH_MAX_BYTES');
        $memory = new \ReflectionClass(MemoryBlock::class);
        $memoryBytes = (int) $memory->getConstant('MAX_BYTES');
        $memoryEntries = (int) $memory->getConstant('MAX_ENTRIES');
        $repoMap = (int) (new \ReflectionClass(RepoMapBlock::class))->getConstant('MAX_SECTION_BYTES');
        $tool = (int) (new \ReflectionClass(TruncatesOutput::class))->getConstant('DEFAULT_MAX_OUTPUT_BYTES');
        $caveat = (string) $class->getConstant('GIT_STATE_CAVEAT');

        $fields = 2 * $diff + 2 * $summary;
        $diffDoc = self::docBlockOf(EnvironmentBlock::class, 'DIFF_MAX_BYTES');
        $summaryDoc = self::docBlockOf(EnvironmentBlock::class, 'SUMMARY_MAX_BYTES');
        $branchDoc = self::docBlockOf(EnvironmentBlock::class, 'BRANCH_MAX_BYTES');

        self::assertSame(
            1,
            preg_match("/this block's\s+\*\s+([\d,]+) B of capped fields \(below\), plus `MemoryBlock`'s ([\d,]+), plus/s", $diffDoc, $m),
            'the total sentence in the DIFF doc-block no longer names the fields ceiling and MemoryBlock\'s share together',
        );
        self::assertSame($fields, (int) str_replace(',', '', $m[1]), 'prose fields-total drifted from 2*DIFF + 2*SUMMARY');
        self::assertSame($memoryBytes, (int) str_replace(',', '', $m[2]), 'prose MemoryBlock figure drifted from MAX_BYTES');

        self::assertSame(
            1,
            preg_match("/`RepoMapBlock`'s 2 x ([\d,]+) — ([\d,]+) B, exactly (\d+) KiB/s", $diffDoc, $m),
            'the every-bound sentence no longer spells its section size, total and KiB unit together',
        );
        self::assertSame($repoMap, (int) str_replace(',', '', $m[1]), 'prose RepoMap section figure drifted from MAX_SECTION_BYTES');
        $everyBound = (int) str_replace(',', '', $m[2]);
        self::assertSame($fields + $memoryBytes + 2 * $repoMap, $everyBound, 'the every-bound total is no longer fields + memory + 2 repo-map sections');
        self::assertSame(0, $everyBound % 1024, 'the every-bound total is no longer an exact KiB count — the prose says "exactly"');
        self::assertSame(intdiv($everyBound, 1024), (int) $m[3], 'the "exactly N KiB" figure drifted from the byte total');

        self::assertSame(
            1,
            preg_match('/real ceiling is ([\d,]+) B, exactly (\d+) KiB/s', $diffDoc, $m),
            'the practical-ceiling sentence moved',
        );
        $realCeiling = (int) str_replace(',', '', $m[1]);
        self::assertSame($fields + $memoryBytes + $repoMap, $realCeiling, 'the practical ceiling is no longer fields + memory + ONE repo-map section');
        self::assertSame(0, $realCeiling % 1024, 'the practical ceiling is no longer an exact KiB count');
        self::assertSame(intdiv($realCeiling, 1024), (int) $m[2], 'the "exactly N KiB" figure drifted from the practical ceiling');

        self::assertSame(
            1,
            preg_match('/spells (\d+) and (\d+) as KiB/s', $diffDoc, $m),
            'the units-correction sentence no longer names both byte counts it spells correctly',
        );
        self::assertSame([$diff, $summary], [(int) $m[1], (int) $m[2]], 'the correction sentence\'s own figures drifted from DIFF/SUMMARY');

        self::assertSame(
            1,
            preg_match('/\{\@see MemoryBlock::MAX_BYTES\}\s+\*\s+is ([\d,]+) for ([a-z]+) curated notes/s', $diffDoc, $m),
            'the neighbour sentence no longer pairs the byte figure with its spelled entry count',
        );
        $wordNumbers = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        self::assertSame($memoryBytes, (int) str_replace(',', '', $m[1]), 'prose Memory::MAX_BYTES cite drifted from the constant');
        self::assertSame($memoryEntries, $wordNumbers[$m[2]] ?? -1, 'the spelled "twelve curated notes" no longer counts MemoryBlock::MAX_ENTRIES — flip both together (census-trio lesson)');

        self::assertSame(
            1,
            preg_match('/default is ([\d,]+); a diff needs/s', $diffDoc, $m),
            'the TruncatesOutput anchor sentence moved',
        );
        self::assertSame($tool, (int) str_replace(',', '', $m[1]), 'prose tool-default figure drifted from TruncatesOutput::DEFAULT_MAX_OUTPUT_BYTES');

        self::assertSame(
            1,
            preg_match('/MAX_SECTION_BYTES\}\s+\*\s+is ([\d,]+) — the same figure as this one/s', $diffDoc, $m),
            'the coincidence sentence no longer claims the two section sizes are equal',
        );
        self::assertSame($repoMap, (int) str_replace(',', '', $m[1]), 'prose RepoMap figure drifted from MAX_SECTION_BYTES');
        self::assertSame($diff, $repoMap, 'the prose still reads "the same figure as this one" — RepoMap and DIFF no longer coincide, so the sentence must change HERE and in the doc-block together');

        self::assertSame(
            1,
            preg_match('/\+ 2 \* this = ([\d,]+) B/s', $summaryDoc, $m),
            'the SUM of the derivation sentence no longer resolves',
        );
        self::assertSame($fields, (int) str_replace(',', '', $m[1]), 'the SUMMARY doc-block\'s own 2*DIFF + 2*this sum drifted');
        self::assertSame(
            1,
            preg_match('/so the\s+\*\s+([\d,]+) is a true ceiling on the fields/s', $summaryDoc, $m),
            'the ceiling re-cite moved',
        );
        self::assertSame($fields, (int) str_replace(',', '', $m[1]), 'the re-cited fields ceiling drifted from the same arithmetic');
        self::assertSame(
            1,
            preg_match('/the (\d+)-byte \{\@see GIT_STATE_CAVEAT\} caption/s', $summaryDoc, $m),
            'the caption no longer carries a byte figure — keep the sentence and the string in step',
        );
        self::assertSame(strlen($caveat), (int) $m[1], 'prose caption bytes drifted from the GIT_STATE_CAVEAT string (it already rotted once: 100 → 91)');
        self::assertSame(
            1,
            preg_match('/under (\d+) KiB \(([\d,]+) B\) however dirty the tree is/s', $summaryDoc, $m),
            'the whole-block bound moved — EnvironmentBlockTest derives the same total',
        );
        self::assertSame($fields + 1024, (int) str_replace(',', '', $m[2]), '24,576 + the 1 KiB fixed part is no longer the stated whole-block bound');
        self::assertSame(intdiv((int) str_replace(',', '', $m[2]), 1024), (int) $m[1], 'the KiB word and the byte count disagree');

        self::assertSame(
            1,
            preg_match('/value (\d+) B \+ newlines is nowhere near ([\d,]+) B, so\s+\*\s+([\d,]+) \+ ([\d,]+) = ([\d,]+) continues to hold/s', $branchDoc, $m),
            'the fixed-part arithmetic sentence no longer states value, slack, and the sum in one breath',
        );
        self::assertSame($branch, (int) $m[1], 'prose branch-cap figure drifted from BRANCH_MAX_BYTES');
        self::assertSame($fields, (int) str_replace(',', '', $m[3]), 'the branch doc-block\'s fields figure drifted from the same arithmetic as SUMMARY\'s');
        self::assertSame(
            (int) str_replace(',', '', $m[3]) + (int) str_replace(',', '', $m[4]),
            (int) str_replace(',', '', $m[5]),
            'the branch doc-block\'s sum no longer adds up',
        );
        self::assertSame((int) str_replace(',', '', $m[2]), (int) str_replace(',', '', $m[4]), 'the "nowhere near" slack and the reserved slack are no longer the same figure');
        self::assertSame(
            1,
            preg_match("/takes `str_repeat\('([^']+)', (\d+)\)` whole/s", $branchDoc, $m),
            'the escaping premise sentence moved — the +3 B a tag arithmetic below cites it',
        );
        self::assertSame(
            1,
            preg_match('/at \+3 B a tag, growing those (\d+) bytes to (\d+)/s', $branchDoc, $b),
            'the escape-growth sentence no longer names its before and after together',
        );
        self::assertSame((int) $m[2] * strlen($m[1]), (int) $b[1], 'the pre-escape byte figure is no longer repeats x tag-length from the premise sentence');
        self::assertSame((int) $b[1] + (int) $m[2] * 3, (int) $b[2], 'the +3 B a tag growth no longer carries 250 to the stated 400');
    }

    /**
     * E686 tranche-3 (J): the hook runtime budgets — the 60s default quoted on
     * three pages, the 200ms drain slice shared by three files under a "five
     * wakeups a second" justification, the 16,384 quartet whose 16,465 example
     * is re-derived from the ACTUAL clip marker re-extracted from clip(), the
     * 8 wrapped permission rows, the 10,000-byte note cap, and the
     * PAGE_SIZE * 32 env-entry cap — all live off their constants.
     */
    public function testHookRunBudgetsSurviveTheirConstants(): void
    {
        $hookClass = new \ReflectionClass(ScriptHook::class);
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $trouble = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/TROUBLESHOOTING.md');

        $timeout = (float) $hookClass->getConstant('DEFAULT_TIMEOUT_SECONDS');
        self::assertSame(
            1,
            preg_match('/(\d+) seconds, because a hook legitimately/', self::docBlockOf(ScriptHook::class, 'DEFAULT_TIMEOUT_SECONDS'), $m),
            'the timeout justification no longer states its seconds figure',
        );
        self::assertSame((int) $timeout, (int) $m[1], 'prose timeout drifted from DEFAULT_TIMEOUT_SECONDS');
        self::assertSame(
            1,
            preg_match('/reap together\.\*\* (\d+) seconds by default/', $hooks, $m),
            'docs/HOOKS.md no longer states the hook-run bound',
        );
        self::assertSame((int) $timeout, (int) $m[1], 'docs/HOOKS.md timeout figure drifted from DEFAULT_TIMEOUT_SECONDS');
        self::assertSame(
            1,
            preg_match('/at (\d+) seconds by default\./', $trouble, $m),
            'docs/TROUBLESHOOTING.md no longer states the hook-run bound',
        );
        self::assertSame((int) $timeout, (int) $m[1], 'docs/TROUBLESHOOTING.md timeout figure drifted from DEFAULT_TIMEOUT_SECONDS');

        $slice = (float) $hookClass->getConstant('DRAIN_SLICE_SECONDS');
        self::assertSame(
            (float) (new \ReflectionClass(StatusLineCommand::class))->getConstant('DRAIN_SLICE_SECONDS'),
            $slice,
            'the prose promises StatusLineCommand shares ScriptHook::DRAIN_SLICE_SECONDS — one side moved',
        );
        $sliceDoc = self::docBlockOf(ScriptHook::class, 'DRAIN_SLICE_SECONDS');
        self::assertSame(
            1,
            preg_match('/for (\w+) wakeups a second.*?makes at the same (\d+)ms/s', $sliceDoc, $m),
            'the drain-slice sentence no longer spells its wakeups-per-second and its cross-class 200ms together',
        );
        $wordNumbers = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];
        self::assertSame($wordNumbers[$m[1]] ?? -1, intdiv(1000, (int) round($slice * 1000)), 'the spelled wakeups-per-second no longer equals 1s / DRAIN_SLICE_SECONDS — flip both together');
        self::assertSame((int) round($slice * 1000), (int) $m[2], 'prose milliseconds drifted from DRAIN_SLICE_SECONDS');
        self::assertSame(
            1,
            preg_match("/DRAIN_SLICE_SECONDS\}'\s+\*\s+(\d+)ms, for its reason/s", self::docBlockOf(StatusLineCommand::class, 'DRAIN_SLICE_SECONDS'), $m),
            'the StatusLine drain doc no longer cites the shared slice with its millisecond figure',
        );
        self::assertSame((int) round($slice * 1000), (int) $m[1], 'StatusLine prose milliseconds drifted from the shared slice');
        $specBody = self::bodyExcerpt(self::sourceOf('Commands/CommandSpec.php'), 'runShellSubstitution', 5000);
        self::assertSame(
            1,
            preg_match('/Capped at (\d+)ms per wait/', $specBody, $m),
            'CommandSpec no longer comments its slice in milliseconds — the "same 200ms" prose cites it',
        );
        self::assertSame((int) round($slice * 1000), (int) $m[1], 'CommandSpec\'s commented slice drifted from ScriptHook\'s constant');
        self::assertSame(
            1,
            preg_match('/min\(\$remaining, ([\d.]+)\)/', $specBody, $m),
            'runShellSubstitution lost its inline slice literal — the cross-class "same 200ms" claim lost its referent',
        );
        self::assertSame($slice, (float) $m[1], 'CommandSpec no longer waits at ScriptHook\'s slice — the "same trade" prose is false');

        $deny = (int) $hookClass->getConstant('MAX_DENY_REASON_BYTES');
        $ask = (int) $hookClass->getConstant('MAX_ASK_PROMPT_BYTES');
        $rewrite = (int) $hookClass->getConstant('MIN_REWRITE_BYTES');
        self::assertSame($deny, $ask, 'the deny and ask clips were the same figure in docs — one constant moved');
        self::assertSame($deny, $rewrite, 'the rewrite floor and the deny clip were the same figure — one moved');
        self::assertSame(
            $deny,
            (int) (new \ReflectionClass(CommandSpec::class))->getConstant('MAX_SUBSTITUTION_BYTES'),
            'the MIN_REWRITE prose cites CommandSpec::MAX_SUBSTITUTION_BYTES as "the figure it already uses" — one side moved',
        );
        self::assertSame(
            1,
            preg_match('/(\d+) KiB, the figure/', self::docBlockOf(ScriptHook::class, 'MIN_REWRITE_BYTES'), $m),
            'the rewrite-floor sentence no longer states its KiB figure',
        );
        self::assertSame(intdiv($deny, 1024), (int) $m[1], 'prose KiB drifted from the 16384 quartet');
        self::assertSame(
            3,
            preg_match_all('/clipped at (\d+) KiB/', $hooks, $m),
            'docs/HOOKS.md no longer states the 16 KiB clip on all three of its rows — re-pin with the prose',
        );
        foreach ($m[1] as $stated) {
            self::assertSame(intdiv($deny, 1024), (int) $stated, 'a prose "clipped at N KiB" figure drifted from the 16384 quartet');
        }
        $noteCap = (int) (new \ReflectionClass(HookResult::class))->getConstant('MAX_ADDITIONAL_CONTEXT_BYTES');
        preg_match_all('/\| ([\d,]+) bytes \|/', $hooks, $tableBytes);
        self::assertCount(
            3,
            $tableBytes[1],
            'the clipping-table byte column no longer has its three cells (note, ask, deny) — re-pin with the prose',
        );
        self::assertSame(
            [$noteCap, $deny, $deny],
            array_map(static fn (string $v): int => (int) str_replace(',', '', $v), $tableBytes[1]),
            'a "| N bytes |" cell in docs/HOOKS.md drifted from the constant it clips against',
        );
        self::assertSame(
            1,
            preg_match('/\*\*note\*\*, capped at ([\d,]+) bytes/', $hooks, $m),
            'the exit-code table no longer states the allow-note cap',
        );
        self::assertSame($noteCap, (int) str_replace(',', '', $m[1]), 'prose note cap drifted from MAX_ADDITIONAL_CONTEXT_BYTES');
        self::assertSame(
            1,
            preg_match('/\*\*([\d,]+) bytes\*\*, counted as bytes/', $hooks, $m),
            'the note-cap rationale sentence moved',
        );
        self::assertSame($noteCap, (int) str_replace(',', '', $m[1]), 'the rationale bold figure drifted from MAX_ADDITIONAL_CONTEXT_BYTES');

        $askDoc = self::docBlockOf(ScriptHook::class, 'MAX_ASK_PROMPT_BYTES');
        self::assertSame(
            1,
            preg_match('/A ([\d,]+)-byte question reaches\s+\*\s+`settleAsk\(\)` as ([\d,]+) bytes — ([\d,]+) plus a marker that names both/s', $askDoc, $m),
            'the 16,465 example sentence no longer spells question, total, and clip together',
        );
        self::assertSame($ask, (int) str_replace(',', '', $m[3]), 'the example\'s clip figure drifted from MAX_ASK_PROMPT_BYTES');
        $clipBody = self::bodyExcerpt(self::sourceOf('Hooks/ScriptHook.php'), 'clip');
        self::assertSame(
            1,
            preg_match("/sprintf\(\s*'([^']+)'/s", $clipBody, $f),
            'clip() no longer builds a single-quoted sprintf marker — the marker-length arithmetic behind the example lost its referent',
        );
        $questionLen = (int) str_replace(',', '', $m[1]);
        self::assertSame(
            $ask + strlen(sprintf($f[1], $ask, $questionLen)),
            (int) str_replace(',', '', $m[2]),
            'the stated clipped total is no longer the cap plus the marker the code actually formats',
        );
        self::assertSame(
            1,
            preg_match('/keeps\s+\*\s+`PERMISSION_PROMPT_MAX_ROWS` = (\d+) wrapped rows/s', $askDoc, $m),
            'the modal-bound cite no longer names the renderer constant with its digit',
        );
        self::assertSame(
            (int) (new \ReflectionClass(Renderer::class))->getConstant('PERMISSION_PROMPT_MAX_ROWS'),
            (int) $m[1],
            'prose wrapped-rows figure drifted from PERMISSION_PROMPT_MAX_ROWS',
        );

        $envMax = (int) $hookClass->getConstant('MAX_ENV_ENTRY_BYTES');
        self::assertSame(
            1,
            preg_match('/is `PAGE_SIZE \* 32` = ([\d,]+), and/', self::docBlockOf(ScriptHook::class, 'MAX_ENV_ENTRY_BYTES'), $m),
            'the kernel-limit derivation no longer spells PAGE_SIZE * 32 with its byte figure',
        );
        self::assertSame($envMax, (int) str_replace(',', '', $m[1]), 'prose env-entry figure drifted from MAX_ENV_ENTRY_BYTES');
        self::assertSame($envMax, 4096 * 32, 'MAX_ENV_ENTRY_BYTES is documented as 4 KiB pages times 32 — the page premise or the constant moved');
        self::assertSame(
            1,
            preg_match('/usual 4 KiB pages that is \*\*([\d,]+) bytes\*\*/', $hooks, $m),
            'docs/HOOKS.md no longer states the E2BIG ceiling',
        );
        self::assertSame($envMax, (int) str_replace(',', '', $m[1]), 'docs/HOOKS.md env-entry figure drifted from MAX_ENV_ENTRY_BYTES');
    }

    /**
     * E686 tranche-3 (K): SglangProvider's contextWindow() docblock quotes the
     * CompactorConfig tiers and derives twelve token figures from them and the
     * three context windows — every "~N" here re-evaluates as
     * intdiv(pct x window, 100) off the constants, including the historical
     * correction triple (the sentence exists precisely to prove derived figures
     * move when their input does).
     */
    public function testSglangContextTierFiguresSurviveTheirConstants(): void
    {
        $sglang = new \ReflectionClass(SglangProvider::class);
        $windows = [
            (int) $sglang->getConstant('DEEPSEEK_V4_CONTEXT_WINDOW'),
            (int) $sglang->getConstant('QWEN3_NEXT_CONTEXT_WINDOW'),
            (int) $sglang->getConstant('LEGACY_DEFAULT_CONTEXT_WINDOW'),
        ];
        $tiers = [
            self::promotedParamDefault(CompactorConfig::class, 'reminderThreshold'),
            self::promotedParamDefault(CompactorConfig::class, 'backgroundCompactionThreshold'),
            self::promotedParamDefault(CompactorConfig::class, 'foregroundBlockingThreshold'),
        ];
        $doc = self::methodDocOf(SglangProvider::class, 'contextWindow');

        self::assertSame(
            1,
            preg_match('/the (\d+)% reminder, (\d+)% automatic compaction,\s+\*\s+(\d+)% blocking refusal and the idle-compaction prompt/s', $doc, $m),
            'the four-tier sentence no longer spells all three percentages with the idle prompt',
        );
        self::assertSame($tiers, [(int) $m[1], (int) $m[2], (int) $m[3]], 'prose tier percentages drifted from the CompactorConfig defaults');

        self::assertSame(
            1,
            preg_match('/arm those fire at ~([\d,]+) \/ ~([\d,]+) \/ ~([\d,]+) estimated tokens; on\s+\*\s+the Qwen3\.8 arm at ~([\d,]+) \/ ~([\d,]+) \/ ~([\d,]+); on the legacy arm\s+\*\s+at ~([\d,]+) \/ ~([\d,]+) \/ ~([\d,]+), unchanged/s', $doc, $m),
            'the three-arm token table no longer reads as one sentence — re-pin it with the prose',
        );
        $stated = [];
        for ($i = 1; $i <= 9; ++$i) {
            $stated[] = (int) str_replace(',', '', $m[$i]);
        }
        $derived = [];
        foreach ($windows as $window) {
            foreach ($tiers as $pct) {
                $derived[] = intdiv($pct * $window, 100);
            }
        }
        self::assertSame(
            $derived,
            $stated,
            'a window or tier constant moved and one of the nine "~tokens" figures did not follow — the docblock\'s own words make recomputing them the duty',
        );

        self::assertSame(
            1,
            preg_match('/are (\d+)\/(\d+)\/(\d+)% of ([\d,]+), of ([\d,]+) and\s+\*\s+of ([\d,]+) respectively and of nothing else/s', $doc, $m),
            'the "of nothing else" sentence no longer ties the percentages to all three windows',
        );
        self::assertSame($tiers, [(int) $m[1], (int) $m[2], (int) $m[3]], 'the second percentages cite drifted from CompactorConfig');
        self::assertSame(
            $windows,
            array_map(static fn (string $v): int => (int) str_replace(',', '', $v), [$m[4], $m[5], $m[6]]),
            'the three windows named in prose drifted from the provider constants',
        );

        self::assertSame(
            1,
            preg_match('/written as\s+\*\s+~([\d,]+) \/ ~([\d,]+) \/ ~([\d,]+) - the same percentages of the superseded\s+\*\s+([\d,]+)/s', $doc, $m),
            'the correction sentence moved — it is the cautionary tale this pin exists for',
        );
        $superseded = (int) str_replace(',', '', $m[4]);
        foreach ($tiers as $index => $pct) {
            self::assertSame(
                intdiv($pct * $superseded, 100),
                (int) str_replace(',', '', $m[1 + $index]),
                'the historical triple is no longer the stated percentages of the stated superseded window — arithmetic rot in a correction is how these sentences die',
            );
        }

        $qwenDoc = self::docBlockOf(SglangProvider::class, 'QWEN3_NEXT_CONTEXT_WINDOW');
        self::assertSame(
            1,
            preg_match('/min\(1_000_000, ([\d_]+) − ([\d_]+)\)` = \*\*([\d_]+)\*\*/s', $qwenDoc, $m),
            'the Qwen window derivation no longer reads as the pinned min/minus sentence',
        );
        $inputLen = (int) $sglang->getConstant('QWEN3_NEXT_MAX_REQUEST_INPUT_LEN');
        self::assertSame($inputLen, (int) str_replace('_', '', $m[1]), 'prose input-length drifted from QWEN3_NEXT_MAX_REQUEST_INPUT_LEN');
        self::assertSame($windows[1], (int) str_replace('_', '', $m[3]), 'the bold result drifted from QWEN3_NEXT_CONTEXT_WINDOW');
        self::assertSame($windows[1], min(1_000_000, $inputLen - (int) str_replace('_', '', $m[2])), 'the derivation sentence no longer evaluates to the window it names');
    }

    /**
     * E686 tranche-3 (L): RuleLoader's aggregate-arithmetic header states the
     * walk as (directories x MAX_FILES) with a live-call-site count, the +1
     * root file, and the splice ceiling it hands to Runtime. The pre-FU5
     * derived absolutes ("12,724,235 raw") stay held as history-of-the-bug.
     */
    public function testRuleLoaderAggregateArithmeticSurvivesItsConstants(): void
    {
        $loader = self::sourceOf('Context/RuleLoader.php');
        $loaderClass = new \ReflectionClass(RuleLoader::class);

        self::assertSame(
            3,
            substr_count($loader, '$this->loadFromDirectory('),
            'RuleLoader no longer walks exactly three directories — flip the header sentence\'s first factor and this probe in-step',
        );
        self::assertSame(
            1,
            preg_match('/\(directories walked x MAX_FILES\) = (\d+) x (\d+)\s+\*\s+= (\d+) reads/s', $loader, $m),
            'the aggregate-arithmetic sentence no longer spells directories, cap and product in one breath',
        );
        self::assertSame(3, (int) $m[1], 'prose directory count drifted from the live call-site count');
        self::assertSame((int) $loaderClass->getConstant('MAX_FILES'), (int) $m[2], 'prose per-directory cap drifted from MAX_FILES');
        self::assertSame((int) $m[1] * (int) $m[2], (int) $m[3], 'the stated product is no longer directories x cap');
        self::assertSame(
            1,
            preg_match('/all ([\d,]+) files this sum can name/s', $loader, $m2),
            'the +1 root sentence moved',
        );
        self::assertSame((int) $m[3] + 1, (int) str_replace(',', '', $m2[1]), 'the walk total plus the root RULES.md no longer equals the stated sum');
        self::assertSame(
            1,
            preg_match('/prices the standing loops at ([\d,]+)\s+\*\s+framed post-escape bytes/s', $loader, $m3),
            'the splice-ceiling sentence no longer names Runtime\'s figure',
        );
        self::assertSame(
            (int) (new \ReflectionClass(Runtime::class))->getConstant('MAX_STANDING_RULE_BYTES'),
            (int) str_replace(',', '', $m3[1]),
            'prose splice ceiling drifted from Runtime::MAX_STANDING_RULE_BYTES',
        );
    }

    /**
     * E686 tranche-3 (M): the operator-facing sizing notes — the /memory
     * troubleshooting bounds, the MEMORY.md truncation demo's cap, the
     * status-line byte cap in prose and settings docs, and the agent pool's
     * default width (whose Claude Code clause stays labeled external).
     */
    public function testOperatorVisibleSizingDefaultsSurviveTheirSymbols(): void
    {
        $memory = new \ReflectionClass(MemoryBlock::class);
        $trouble = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/TROUBLESHOOTING.md');
        self::assertSame(
            1,
            preg_match('/Two more bounds: (\d+) entries, newest first, and ([\d,]+) bytes of rendered note/s', $trouble, $m),
            'the /memory bounds sentence no longer spells entries and bytes together',
        );
        self::assertSame((int) $memory->getConstant('MAX_ENTRIES'), (int) $m[1], 'prose entry bound drifted from MemoryBlock::MAX_ENTRIES');
        self::assertSame((int) $memory->getConstant('MAX_BYTES'), (int) str_replace(',', '', $m[2]), 'prose byte bound drifted from MemoryBlock::MAX_BYTES');

        $memoryDoc = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MEMORY.md');
        self::assertSame(
            1,
            preg_match('/comes back at \*\*exactly ([\d,]+) bytes\*\*/', $memoryDoc, $m),
            'the truncation demo no longer states its byte figure — the MEASURED label stays, the cap it names is pinned here',
        );
        self::assertSame((int) $memory->getConstant('MAX_ENTRY_BYTES'), (int) str_replace(',', '', $m[1]), 'prose per-note cap drifted from MemoryBlock::MAX_ENTRY_BYTES');

        $statusCap = (int) (new \ReflectionClass(StatusLineCommand::class))->getConstant('MAX_OUTPUT_BYTES');
        $settings = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SETTINGS.md');
        self::assertSame(
            1,
            preg_match('/MAX_OUTPUT_BYTES` \((\d+) KiB\)/', $settings, $m),
            'docs/SETTINGS.md no longer states the status-line cap in KiB beside the symbol',
        );
        self::assertSame(intdiv($statusCap, 1024), (int) $m[1], 'docs/SETTINGS.md KiB figure drifted from MAX_OUTPUT_BYTES');
        self::assertSame(
            1,
            preg_match('/(\d+) KiB, which is/s', self::docBlockOf(StatusLineCommand::class, 'MAX_OUTPUT_BYTES'), $m),
            'the status-line justification sentence moved',
        );
        self::assertSame(intdiv($statusCap, 1024), (int) $m[1], 'prose KiB drifted from MAX_OUTPUT_BYTES');

        $maxConcurrent = new \ReflectionProperty(AgentPoolConfig::class, 'maxConcurrent');
        $poolDoc = (string) $maxConcurrent->getDocComment();
        self::assertSame(
            1,
            preg_match('/Defaults to (\d+), matching/', $poolDoc, $m),
            'the pool-width docblock no longer states its default with the external provenance clause',
        );
        self::assertSame(self::promotedParamDefault(AgentPoolConfig::class, 'maxConcurrent'), (int) $m[1], 'prose default drifted from the promoted parameter default');
        self::assertStringContainsString(
            'matching Claude Code',
            $poolDoc,
            'the external-provenance label must stay labeled — an external premise may not be silently internalized into a pinned in-repo fact (E686)',
        );
    }

    /**
     * E686 tranche-3 (N): the Claude-Code MCP client's retry shape is one
     * sentence over two literal loops, and the write-idle cite names a constant
     * with its decimal. The "poll for 1.8s" narrative (attempts plus read time,
     * measured) stays held; only the derivable digits are pinned.
     */
    public function testMcpWritePumpRetryProseSurvivesItsLiterals(): void
    {
        $mcp = self::sourceOf('ClaudeCodeMcpClient.php');

        self::assertSame(
            1,
            preg_match('/drive \((\d+) attempts, (\d+) ms apart\)/', $mcp, $m),
            'the retry-shape sentence no longer spells attempts and interval together',
        );
        $attempts = (int) $m[1];
        $intervalMs = (int) $m[2];
        foreach (['callTool', 'listTools'] as $pump) {
            $body = self::bodyExcerpt($mcp, $pump);
            self::assertSame(1, preg_match('/while \(\$attempts < (\d+)\)/', $body, $loop), "{$pump}() lost its counted retry loop — the prose attempts count lost its referent");
            self::assertSame(1, preg_match('/usleep\((\d+)\)/', $body, $poll), "{$pump}() no longer polls with a fixed usleep — the prose interval lost its referent");
            self::assertSame($attempts, (int) $loop[1], "prose attempt count drifted from {$pump}()'s loop bound");
            self::assertSame($intervalMs, intdiv((int) $poll[1], 1000), "prose poll milliseconds drifted from {$pump}()'s usleep");
        }
        self::assertSame(
            1,
            preg_match('/so ~([\d.]+)s of waiting plus read time/', $mcp, $m),
            'the waiting-time sentence moved — its digit is derived, so it must re-pin with the loop bounds',
        );
        self::assertSame(
            (float) ($attempts * $intervalMs) / 1000.0,
            (float) $m[1],
            'the "~Ns of waiting" figure is no longer attempts x interval',
        );

        self::assertSame(
            1,
            preg_match('/\{\@see WRITE_IDLE_SECONDS\} = ([\d.]+);/', $mcp, $m),
            'the write-idle cite no longer names the constant with its decimal — update both sides',
        );
        self::assertSame(
            (float) (new \ReflectionClass(ClaudeCodeMcpClient::class))->getConstant('WRITE_IDLE_SECONDS'),
            (float) $m[1],
            'the cited decimal drifted from WRITE_IDLE_SECONDS',
        );
    }

    /**
     * E686 tranche-4 (O): HOOKS.md's roster sentence — "**eight** `CRUSH_*`
     * keys — six, on the one run where the temp directory will not take a
     * file" — re-derives live off the arrays that build the child environment
     * (`$fixed` in `executeStaged()`, the payloads handed to `stagePayloads()`,
     * and the `_FILE` pointer each payload gains), because a hand-typed env
     * roster is exactly the disease E583 names for symbol citations. The grid
     * fence that names the keys must equal the same set, and the paragraph's
     * closer "Those eight are what the hook sets" must still be counting it.
     */
    public function testHookEnvRosterProseSurvivesTheLiveEnvArrays(): void
    {
        $roster = self::hookEnvRoster();
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        self::assertSame(
            1,
            preg_match('/\*\*(\w+)\*\* `CRUSH_\*` keys — (\w+), on the one run where the temp directory/', $hooks, $m),
            'the roster sentence no longer spells both word-counts with its degraded-run premise — rewrite the pin with the prose, do not delete it',
        );
        $full = count($roster['all']);
        $degraded = count($roster['fixed']) + count($roster['payloads']);
        self::assertSame($full, $words[$m[1]] ?? -1, 'the spelled total no longer counts the live $fixed + payload + _FILE arrays — flip both together (census-trio lesson)');
        self::assertSame($degraded, $words[$m[2]] ?? -1, 'the spelled degraded count no longer equals the live arrays minus their _FILE pointers');
        self::assertSame(count($roster['pointers']), $full - $degraded, 'the degraded case must be exactly the _FILE pointers dropping out');

        self::assertSame(
            1,
            preg_match_all('/```\n(CRUSH_[^\n]*(?:\nCRUSH_[^\n]*)+)\n```/', $hooks, $grid),
            'exactly one fence may open with the CRUSH_* key grid — a second grid is drift, its absence is deletion',
        );
        preg_match_all('/CRUSH_[A-Z_]+/', $grid[1][0], $listed);
        self::assertSame($full, count($listed[0]), 'the grid fence no longer lists one entry per live roster key');
        self::assertEqualsCanonicalizing($roster['all'], $listed[0], 'the grid fence and the live environment arrays name different keys');

        self::assertSame(
            1,
            preg_match('/Those (\w+) are what the hook \*sets\*/', $hooks, $m),
            'the paragraph closer moved — it is the sentence that retires the grid in prose',
        );
        self::assertSame($full, $words[$m[1]] ?? -1, 'the spelled closer no longer counts the live roster');
    }

    /**
     * E686 tranche-4 (P): the `env | sort` table is roster arithmetic, not a
     * remembered measurement — the full run shows every key set plus `PWD`,
     * the empty-`toolOutput` run hides exactly the payload variables the
     * listing marks `← only in the second run`, and the pointer-before-empty-
     * skip order inside `stagePayloads()` is what makes the output pointer
     * "appear in both runs". The stale parenthetical this tranche fixed —
     * claiming the empty run "coincidentally shows six lines" while the same
     * page's table printed eight — is pinned absent, its correction in both
     * directions.
     */
    public function testHookSeenEnvTableSurvivesTheRosterArithmetic(): void
    {
        $roster = self::hookEnvRoster();
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        self::assertSame(
            1,
            preg_match("/\\| `''` \\(empty\\) \\| \\*\\*(\\d+)\\*\\* \\| (\\d+) × `CRUSH_\\*` \\+ `PWD` \\|/", $hooks, $empty),
            'the empty-toolOutput table row no longer states lines and CRUSH-count together',
        );
        self::assertSame(
            1,
            preg_match("/\\| `'RESULT-TEXT'` \\| \\*\\*(\\d+)\\*\\* \\| (\\d+) × `CRUSH_\\*` \\+ `PWD` \\|/", $hooks, $full),
            'the full-payload table row no longer states lines and CRUSH-count together',
        );
        self::assertSame(
            1,
            preg_match('/```\n((?:CRUSH_[A-Z_]+=.*\n)+)(PWD=[^\n]*)\n```/', $hooks, $run),
            'the sorted env listing no longer reads as CRUSH_* lines closed by PWD — re-pin with the table',
        );
        preg_match_all('/^(CRUSH_[A-Z_]+)=.*$/m', $run[1], $shown);
        self::assertEqualsCanonicalizing($roster['all'], $shown[1], 'the listing and the live environment arrays diverged');
        self::assertSame(
            1,
            preg_match('/^(CRUSH_[A-Z_]+)=.*← only in the second run/m', $run[1], $hidden),
            'the listing marks exactly one variable as second-run-only — that single hiding is what the empty row subtracts',
        );
        self::assertContains($hidden[1], $roster['payloads'], 'the hidden variable is not a payload — stagePayloads() passes it empty, not absent');

        $total = count($roster['all']);
        self::assertSame($total - 1, (int) $empty[2], 'the empty-run CRUSH-count drifted from the roster less the hidden payload');
        self::assertSame($total, (int) $empty[1], 'the empty-run line count drifted from its CRUSH-count plus PWD');
        self::assertSame($total, (int) $full[2], 'the full-run CRUSH-count drifted from the live roster');
        self::assertSame($total + 1, (int) $full[1], 'the full-run line count drifted from the roster plus PWD');

        self::assertSame(1, preg_match('/appears in \*\*both\*\* runs/', $hooks), 'the both-runs pointer claim moved — it is why the empty row keeps its pointer line');
        $stager = self::bodyExcerpt(self::sourceOf('Hooks/ScriptHook.php'), 'stagePayloads', 2000);
        $pointerWrite = strpos($stager, "\$env[\$pathVariable] = \$path;");
        $emptySkip = strpos($stager, "if (\$value === '') {");
        self::assertNotFalse($pointerWrite, 'stagePayloads() no longer commits the _FILE pointer the way the docs name it — the both-runs claim lost its referent');
        self::assertNotFalse($emptySkip, 'stagePayloads() no longer keeps the empty-payload file the way the docs cite it — the both-runs claim lost its referent');
        self::assertLessThan($emptySkip, $pointerWrite, 'the pointer is no longer committed before the empty-value skip — an empty payload would lose its _FILE and the table row is false');

        self::assertStringNotContainsString('coincidentally shows six lines', $hooks, 'the pre-pointer stale parenthetical is back — the table above this page says the empty run shows eight lines, not six');
        self::assertSame(
            1,
            preg_match('/prints (\w+) fewer `CRUSH_\*` line than it\s+has keys/', $hooks, $m),
            'the corrected parenthetical moved — this pin and the sentence retire together',
        );
        self::assertSame($total - (int) $empty[2], $words[$m[1]] ?? -1, 'the spelled hide-count no longer equals the CRUSH-count gap the table states');
    }

    /**
     * E686 tranche-4 (Q): the boundary pair tranche-3 HELD as measured is in
     * fact derivable — the doc-block over MAX_ENV_ENTRY_BYTES spells the
     * kernel's own formula (`NAME=VALUE\0` costs two bytes on top of the name
     * and the value) — so 131,054 and 131,053 recompute from the live key
     * names, and the claim that `CRUSH_TOOL_OUTPUT` sits "one byte lower"
     * "because its name is one byte longer" checks as a strlen difference over
     * those same names. The "~128 KB" rounding stays held: it paraphrases a
     * figure that is now pinned exactly.
     */
    public function testPayloadBoundaryPairDerivesFromTheLiveKeyNames(): void
    {
        $hook = self::sourceOf('Hooks/ScriptHook.php');
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $roster = self::hookEnvRoster();
        $words = ['one' => 1, 'two' => 2, 'three' => 3];

        self::assertSame(
            1,
            preg_match_all("/strlen\\('(CRUSH_[A-Z_]+)'\\) \\+ (\\d+) \\+ value \\+ (\\d+) <= (\\d+)/", $hook, $f),
            'exactly one doc-block may state the env-entry formula — a second spelling is drift',
        );
        $overhead = (int) $f[2][0] + (int) $f[3][0];
        $cap = (int) $f[4][0];
        self::assertSame($cap, (int) (new \ReflectionClass(ScriptHook::class))->getConstant('MAX_ENV_ENTRY_BYTES'), 'the formula and the constant it bounds no longer agree');
        self::assertContains($f[1][0], $roster['payloads'], 'the formula cites a key that is no longer a staged payload');

        self::assertSame(
            1,
            preg_match('/([\d,]+) bytes\s+of value allowed, ([\d,]+) denied/', $hooks, $in),
            'the measured payload boundary pair no longer reads as one sentence — re-pin with the prose',
        );
        $allowed = $cap - strlen($f[1][0]) - $overhead;
        self::assertSame($allowed, (int) str_replace(',', '', $in[1]), 'the allowed figure no longer equals cap minus name minus overhead for the cited key');
        self::assertSame((int) str_replace(',', '', $in[1]) + 1, (int) str_replace(',', '', $in[2]), 'the denied boundary is no longer one past the allowed figure');

        $other = null;
        foreach ($roster['payloads'] as $name) {
            if ($name !== $f[1][0]) {
                $other = $name;
            }
        }
        self::assertNotNull($other, 'the payload roster needs a second entry before a one-byte-lower sentence can mean anything');
        self::assertSame(
            1,
            preg_match('/behaves the same way (\w+) byte lower,\s+at ([\d,]+)\/([\d,]+)/', $hooks, $out),
            'the second payload boundary sentence no longer ties its word-gap to its pair — re-pin with the prose',
        );
        self::assertSame(strlen($other) - strlen($f[1][0]), $words[$out[1]] ?? -1, 'the spelled byte-gap no longer matches the live key names — flip prose and arrays together');
        $lower = $cap - strlen($other) - $overhead;
        self::assertSame($lower, (int) str_replace(',', '', $out[2]), 'the lower pair no longer derives from the second payload name');
        self::assertSame((int) str_replace(',', '', $out[2]) + 1, (int) str_replace(',', '', $out[3]), 'the lower denied boundary is no longer one past its allowed figure');
        self::assertSame((int) str_replace(',', '', $out[3]), $allowed, 'one byte lower must put the denied figure exactly on the first pair\'s allowed figure — the two sentences drifted apart');
    }

    /**
     * E686 tranche-4 (R): the retry marker is a wire contract in three places
     * — `OVERSIZE_ENV_MARKER` in src, the fenced example, and the `case` arm
     * of the guard snippet — and they must spell one literal. The example's
     * shape re-evaluates through the sprintf `stagePayloads()` itself formats
     * (extracted from the live body, never retyped), its "read $" target must
     * be a live pointer key, and the snippet may only name keys the live
     * arrays set. The example's size digit stays held: it is that call's
     * payload length, not a constant. "Not a prefix" and "an absent `CRUSH_*`
     * already means empty" are the contract's semantics and stay pinned.
     */
    public function testOversizeMarkerContractSurvivesItsConstant(): void
    {
        $marker = (string) (new \ReflectionClass(ScriptHook::class))->getConstant('OVERSIZE_ENV_MARKER');
        $roster = self::hookEnvRoster();
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');

        self::assertSame(
            2,
            substr_count($hooks, $marker),
            'the marker must be spelled identically in exactly its two places (example + guard snippet) — a third spelling is drift, a fourth is a fork',
        );

        $stager = self::bodyExcerpt(self::sourceOf('Hooks/ScriptHook.php'), 'stagePayloads', 2600);
        self::assertSame(
            1,
            preg_match("/sprintf\\(\\s*'([^']*bytes; read [^']*)',/s", $stager, $fmt),
            'stagePayloads() no longer builds the marker with a "bytes; read $..." sprintf — the docs example lost its generator',
        );
        self::assertSame(
            1,
            preg_match('/' . preg_quote($marker, '/') . ' (\d+) bytes; read \$(CRUSH_[A-Z_]+)/', $hooks, $m),
            'the fenced marker example moved — the retry contract the page teaches needs it',
        );
        self::assertSame(
            sprintf($fmt[1], $marker, (int) $m[1], $m[2]),
            $m[0],
            'the docs example is no longer what the live sprintf produces — one side moved',
        );
        self::assertContains($m[2], $roster['pointers'], 'the marker tells the hook to read a variable that is not in the live _FILE roster');

        self::assertSame(
            1,
            preg_match('/if \[ -n "\$\{(CRUSH_[A-Z_]+):-\}" \] && \[ -r "\$\1" \]; then\n\s+input="\$\(cat "\$\1"\)"\nelse\n\s+input="\$(CRUSH_[A-Z_]+)"/', $hooks, $snip),
            'the guard snippet no longer reads as probe-file-else-variable — the fallback contract this page exists to teach moved',
        );
        self::assertContains($snip[1], $roster['pointers'], 'the guard snippet probes a variable that is not a live _FILE pointer');
        self::assertContains($snip[2], $roster['payloads'], 'the guard snippet falls back to a variable the live payload roster no longer sets');
        self::assertSame(
            1,
            preg_match("/''\\|'" . preg_quote($marker, '/') . "'\\*\\)/", $hooks),
            'the guard snippet no longer fails closed on the marker — the contract is the marker, not an empty string',
        );
        self::assertStringContainsString('Not a prefix of the JSON', $hooks, 'the marker-rationale prose moved (E686: corrections are pinned in both directions)');
        self::assertStringContainsString('an absent `CRUSH_*` already means "empty" here', $hooks, 'the absent-means-empty premise moved — the whole guard teaches on it');
    }

    /**
     * E686 tranche-4 (S): cross-page roster integrity. TROUBLESHOOTING.md
     * spelled the hook environment six `CRUSH_*` variables — the pre-pointer
     * count — contradicting HOOKS.md's eight off the same arrays; it now
     * spells the live total and this arm keeps the two pages counting ONE
     * roster with ONE number. The launch configuration stays outside the hook
     * environment: the page names the parallel-calls disable variable by the
     * very string EngineBackend reads, no quoted `SUGARCRUSH_*` key appears
     * in the environment assembly, and the "nothing from your shell survives"
     * sentence stands.
     */
    public function testCrossPageRosterAndLaunchVarBoundarySurviveTheRoster(): void
    {
        $roster = self::hookEnvRoster();
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $trouble = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/TROUBLESHOOTING.md');
        $words = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];

        self::assertSame(
            1,
            preg_match('/\*\*replaces\*\* the environment with (\w+) `CRUSH_\*`\s+variables/', $trouble, $m),
            'the TROUBLESHOOTING roster sentence moved — re-pin with the prose, do not delete it',
        );
        self::assertSame(count($roster['all']), $words[$m[1]] ?? -1, 'TROUBLESHOOTING.md and HOOKS.md must spell one roster with one number — the spelled count drifted from the live arrays');
        self::assertStringNotContainsString('with six `CRUSH_*`', $trouble, 'the stale pre-pointer count is back — it contradicts the live $fixed + payload + _FILE arrays (E686 tranche-4 fixed it HERE)');

        self::assertSame(
            1,
            preg_match('/`(\w*DISABLE_PARALLEL\w*)=1`/', $hooks, $m),
            'HOOKS.md no longer names the parallel-calls disable variable in a code span',
        );
        self::assertSame(
            (string) (new \ReflectionClass(EngineBackend::class))->getConstant('PARALLEL_TOOL_CALLS_DISABLE_ENV'),
            $m[1],
            'the documented disable variable drifted from the name EngineBackend reads',
        );

        self::assertSame(
            1,
            preg_match('/none of the `SUGARCRUSH_\*` variables that configured the launch/', $hooks),
            'the launch-vars-do-not-survive sentence moved — hook authors program against it',
        );
        self::assertSame(
            0,
            preg_match_all("/['\"]SUGARCRUSH_[A-Z_]+['\"]/", self::bodyExcerpt(self::sourceOf('Hooks/ScriptHook.php'), 'executeStaged', 7000)),
            'a quoted SUGARCRUSH_* key joined the hook environment assembly — the page promises launch configuration never reaches a hook',
        );
    }

    /**
     * E686 tranche-5 (T): the oversize-entry narrative in HOOKS.md. "~128 KB"
     * is the KiB rounding of the entry cap; "200,000 and 1,000,000 denied
     * identically" and the marker example's "200011 bytes" are size claims
     * whose contract is that they exceed the live allowed boundary — the same
     * ScriptHook formula arm Q re-derives the 131,054/131,055 pair from. The
     * digits stay free (they are example payloads, not constants); what is
     * pinned is the boundary relation, so the doc cannot quietly start
     * illustrating the marker with a size that would never have earned it
     * (dl's two carried HELDs, judged live here).
     */
    public function testOversizePayloadNarrativeStaysPastTheLiveEntryBoundary(): void
    {
        $hooks = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $hook = self::sourceOf('Hooks/ScriptHook.php');

        self::assertSame(
            1,
            preg_match('/exceeded ~(\d+) KB could not run at all/', $hooks, $kb),
            'the ~128 KB framing sentence moved — it is why the payload now travels both ways',
        );
        self::assertSame(
            1,
            preg_match("/strlen\\('(CRUSH_[A-Z_]+)'\\) \\+ (\\d+) \\+ value \\+ (\\d+) <= (\\d+)/", $hook, $f),
            'the MAX_ENV_ENTRY_BYTES doc-block no longer spells the NAME=VALUE formula — arm Q and this arm lost their referent together',
        );
        $cap = (int) $f[4];
        self::assertSame($cap, (int) (new \ReflectionClass(ScriptHook::class))->getConstant('MAX_ENV_ENTRY_BYTES'), 'the formula and the constant it bounds no longer agree');
        self::assertSame(intdiv($cap, 1024), (int) $kb[1], 'the "~N KB" rounding is no longer the KiB rounding of MAX_ENV_ENTRY_BYTES — move the paraphrase with the cap');

        $allowed = $cap - strlen($f[1]) - ((int) $f[2] + (int) $f[3]);

        self::assertSame(
            1,
            preg_match('/([\d,]+) and ([\d,]+) denied identically/', $hooks, $denied),
            'the denied-identically sentence moved — it is the anecdote the file-backed route exists to close',
        );
        foreach ([1, 2] as $i) {
            self::assertGreaterThan(
                $allowed,
                (int) str_replace(',', '', $denied[$i]),
                'a payload cited as denied is no longer oversize — "denied identically" reads false on its face',
            );
        }

        $marker = (string) (new \ReflectionClass(ScriptHook::class))->getConstant('OVERSIZE_ENV_MARKER');
        self::assertSame(
            1,
            preg_match('/' . preg_quote($marker, '/') . ' (\d+) bytes; read \$(CRUSH_[A-Z_]+)_FILE/', $hooks, $example),
            'the fenced marker example moved — arm R pins its shape, this arm its size claim',
        );
        self::assertSame($f[1], $example[2], 'the marker example names a payload other than the formula key — the boundary computed here is not the one that example crossed');
        self::assertGreaterThan(
            $allowed,
            (int) $example[1],
            'the example is no longer oversize for its own key — the marker it displays could not have fired for a payload that small',
        );
    }

    /**
     * E686 tranche-5 (U): the EnvironmentBlock fixture paragraph declares its
     * own falsifiability — "NO TEST BUILDS THIS FIXTURE, so nothing downstream
     * can falsify the absolutes; only the delta below is checkable, and the
     * derivable part of it checks out" — so this arm checks exactly that
     * derivable part: the spelled 5 x (7+1+1500+1) = 7,545 formula and its
     * 4,528 omission, the headroom that must land the absolute on the live
     * derived 25,600 ceiling, the +93/+102 two-era caption law against the
     * live GIT_STATE_CAVEAT, the 74 B unexplained gap as stated, and the
     * ceil(4096 x 120 / 1779) = 277 hedge the prose itself computes. The
     * fixture absolutes stay free — this is arm K's self-consistency idiom on
     * a measured narrative (cb's held row, promoted).
     */
    public function testEnvironmentBlockFixtureArithmeticChecksItsOwnDerivablePart(): void
    {
        $doc = self::docBlockOf(EnvironmentBlock::class, 'SUMMARY_MAX_BYTES');
        $class = new \ReflectionClass(EnvironmentBlock::class);
        $ceiling = 2 * (int) $class->getConstant('DIFF_MAX_BYTES') + 2 * (int) $class->getConstant('SUMMARY_MAX_BYTES') + 1024;
        $caveat = strlen((string) $class->getConstant('GIT_STATE_CAVEAT'));

        self::assertSame(
            1,
            preg_match('/five ([\d,]+)-byte\s+\*\s+subjects are (\d+) \* \((\d+) \+ (\d+) \+ (\d+) \+ (\d+)\) = ([\d,]+) B, of which two whole lines\s+\*\s+minus the trailing newline are kept, giving the ([\d,]+) B omitted/s', $doc, $m),
            'the self-described derivable sentence no longer spells formula and omission together — rewrite the pin with the prose, do not delete it',
        );
        self::assertSame((int) $m[5], (int) str_replace(',', '', $m[1]), 'the spelled subject size and the formula\'s 1500 term are no longer the same premise');
        $perLine = (int) $m[3] + (int) $m[4] + (int) $m[5] + (int) $m[6];
        $total = (int) str_replace(',', '', $m[7]);
        self::assertSame((int) $m[2] * $perLine, $total, 'the stated total is no longer count x the formula sum');
        $omitted = (int) str_replace(',', '', $m[8]);
        self::assertSame($total - (2 * $perLine - 1), $omitted, 'the omitted figure is no longer total minus two whole lines without their trailing newline');

        self::assertSame(
            1,
            preg_match('/`log` \(([\d,]+) of ([\d,]+) B omitted\)/', $doc, $pair),
            'the first mention of the log omission moved — the prose cites the same pair twice and they must not fork',
        );
        self::assertSame([$omitted, $total], [(int) str_replace(',', '', $pair[1]), (int) str_replace(',', '', $pair[2])], 'the two statements of the omitted/total pair diverged');

        self::assertSame(
            1,
            preg_match('/the block came to \*\*([\d,]+) B\*\*, i\.e\. ([\d,]+) B\s+\*\s+of headroom under the derived ceiling/s', $doc, $head),
            'the absolute-plus-headroom sentence moved',
        );
        self::assertSame(
            $ceiling,
            (int) str_replace(',', '', $head[1]) + (int) str_replace(',', '', $head[2]),
            'absolute plus stated headroom is no longer the derived 24,576+1,024 ceiling the same doc-block pins',
        );

        self::assertSame(
            1,
            preg_match('/(\d+) porcelain lines at ~(\d+) B is a ([\d,]+) B body against\s+\*\s+SUMMARY_MAX_BYTES = (\d+), and clipping it would take about (\d+) such\s+\*\s+lines, more than twice this fixture.s (\d+)/s', $doc, $porcelain),
            'the Status-cannot-clip hedge no longer spells its lines, body, cap and needed count together',
        );
        self::assertSame((int) $class->getConstant('SUMMARY_MAX_BYTES'), (int) $porcelain[4], 'the prose cap drifted from SUMMARY_MAX_BYTES');
        self::assertSame((int) $porcelain[1], (int) $porcelain[6], 'the fixture size is stated as two different porcelain counts');
        self::assertSame((int) $porcelain[2], (int) round((int) str_replace(',', '', $porcelain[3]) / (int) $porcelain[1]), 'the "~15 B" per-line premise no longer rounds from the stated body');
        self::assertSame((int) $porcelain[5], (int) ceil(((int) $porcelain[4] * (int) $porcelain[1]) / (int) str_replace(',', '', $porcelain[3])), 'the needed-lines figure is no longer the ceiling divided through the stated body');
        self::assertGreaterThan(2 * (int) $porcelain[1], (int) $porcelain[5], 'the prose still says "more than twice this fixture\'s 120" — the figures stopped supporting it');

        self::assertSame(
            1,
            preg_match('/rendered ([\d,]+) B\s+\*\s+against master/s', $doc, $master),
            'the rebuild-against-master figure moved',
        );
        self::assertSame(
            1,
            preg_match('/The delta is \+(\d+) B — the (\d+)-byte caption plus its blank line/s', $doc, $delta),
            'the +93 delta sentence moved — the prose calls it the part that reproduces on any fixture',
        );
        self::assertSame(
            (int) str_replace(',', '', $head[1]) - (int) str_replace(',', '', $master[1]),
            (int) $delta[1],
            'the stated delta is no longer current absolute minus the master rebuild',
        );
        self::assertSame($caveat + 2, (int) $delta[1], 'the caption-plus-blank-line arithmetic (strlen(GIT_STATE_CAVEAT) + 2) no longer produces the stated delta');
        self::assertSame($caveat, (int) $delta[2], 'the prose\'s caption byte count drifted from the live GIT_STATE_CAVEAT');

        self::assertSame(
            1,
            preg_match('/recorded ([\d,]+) B for the fixture it\s+\*\s+described in these same words — (\d+) B above what the rebuild/s', $doc, $gap),
            'the unexplained 74 B gap sentence moved — the prose deliberately leaves it standing',
        );
        self::assertSame(
            (int) str_replace(',', '', $gap[1]) - (int) str_replace(',', '', $master[1]),
            (int) $gap[2],
            'the historical record minus the rebuild is no longer the stated gap',
        );

        self::assertSame(
            1,
            preg_match('/recorded ([\d,]+) \/ ([\d,]+) \/ \+(\d+) B here; those are of the (\d+)-byte caption/s', $doc, $older),
            'the 100-byte-caption-era sentence moved',
        );
        self::assertSame(
            (int) str_replace(',', '', $older[1]) - (int) str_replace(',', '', $older[2]),
            (int) $older[3],
            'the earlier revision\'s three figures no longer add up',
        );
        self::assertSame((int) $older[4] + 2, (int) $older[3], 'the +2 caption-plus-blank-line law broke across eras — the prose pins "the same +93 B on any fixture" to the same arithmetic');
        self::assertStringContainsString('"ALL FOUR capped fields', $doc, 'the retracted claim must stay quoted where it is retracted (E633: corrections are pinned in both directions)');
        self::assertStringContainsString('corrected here rather than dropped', $doc, 'the correction premise moved');
    }

    /**
     * E686 tranche-5 (V): the FU5 splice-history pair — 12,724,235 raw,
     * 20,313,188 after escape — is stated twice, in RuleLoader's class doc and
     * in Runtime's MAX_STANDING_RULE_BYTES justification, and Runtime adds a
     * multiplier claim ("the 1.6x lesson") about the pair itself. Neither
     * digit has an in-repo generator (the escape ratio is data-dependent), but
     * the two sites quoting ONE history must not fork, the one-decimal ratio
     * must survive its own pair, and RuleLoader's "can no longer emit" is
     * backed by live caps: 193 x MAX_FILE_BYTES < 12,724,235 (cb's held row,
     * promoted).
     */
    public function testSpliceHistoryPairAgreesAcrossItsTwoSites(): void
    {
        $loader = self::sourceOf('Context/RuleLoader.php');
        $runtimeDoc = self::docBlockOf(Runtime::class, 'MAX_STANDING_RULE_BYTES');

        self::assertSame(
            1,
            preg_match('/the (\d+)-file worst case can no longer emit ([\d,]+) raw bytes\s+\*\s+\(([\d,]+) after escape\)/s', $loader, $m),
            'the RuleLoader sentence no longer ties the file count to the historical byte pair — re-pin with the prose, do not delete it',
        );
        $raw = (int) str_replace(',', '', $m[2]);
        $escaped = (int) str_replace(',', '', $m[3]);

        self::assertSame(
            1,
            preg_match('/unbounded splice at ([\d,]+) emitted bytes becoming ([\d,]+) after escape,\s+\*\s+and a budget priced pre-escape would be the ([\d.]+)x lesson/s', $runtimeDoc, $r),
            'the Runtime side of the pair or its multiplier sentence moved — both sites quote ONE measured history',
        );
        self::assertSame($raw, (int) str_replace(',', '', $r[1]), 'the splice history forked: RuleLoader and Runtime quote different raw figures');
        self::assertSame($escaped, (int) str_replace(',', '', $r[2]), 'the splice history forked: RuleLoader and Runtime quote different after-escape figures');
        self::assertSame((float) $r[3], round($escaped / $raw, 1), 'the stated multiplier is no longer the one-decimal ratio of the pair it names');

        $loaderClass = new \ReflectionClass(RuleLoader::class);
        $files = 3 * (int) $loaderClass->getConstant('MAX_FILES') + 1;
        self::assertSame($files, (int) $m[1], 'the historical sentence\'s file count drifted from the live walk (3 dirs x MAX_FILES + root) that arm L pins — do not touch one side alone');
        self::assertLessThan(
            $raw,
            $files * (int) $loaderClass->getConstant('MAX_FILE_BYTES'),
            'the walk caps now admit what the prose says they "can no longer emit" — the FU5 claim needs re-checking, HERE and in the sentence together',
        );
    }

    /**
     * E686 tranche-5 (W): EnhancedSessionStore's "What this buys, measured"
     * block is honest about being measured — so no absolute here is pinned to
     * a constant — but the block also states ARITHMETIC: 14 plus 38 is 52,
     * 49.72 against 37.89 leaves 11.8, and retain minus discard is the ~7 ms
     * the paragraph calls "real work". Those relations are what a partial
     * re-measurement breaks, so they are what gets pinned; the multipliers
     * 18x/46x/77% stay held because the prose itself says "the factor moves
     * with both turn count and message size", and "~10 s over the session"
     * stays held because the halving it rests on is left implicit.
     */
    public function testSessionStoreMeasuredBlockKeepsItsOwnSums(): void
    {
        $doc = (string) (new \ReflectionProperty(EnhancedSessionStore::class, 'messageHashes'))->getDocComment();
        self::assertNotSame('', $doc, '$messageHashes lost its doc-block — the measured block this pins was deleted, not fixed');

        self::assertSame(
            1,
            preg_match('/that measured (\d+) ms of `json_encode` plus\s+\*\s*(\d+) ms of `sha256` — (\d+) ms of dead time/s', $doc, $sum),
            'the headline sum sentence no longer spells encode, sha and total together — re-pin with the prose',
        );
        self::assertSame((int) $sum[1] + (int) $sum[2], (int) $sum[3], 'the two component measurements no longer add to the stated dead time');

        self::assertSame(
            1,
            preg_match('/encode-and-discard[\s*]+([\d.]+)[\s*]+ms,[\s*]+encode-and-retain[\s*]+([\d.]+)[\s*]+ms,[\s*]+and[\s*]+the[\s*]+faithful[\s*]+encode\+`sha256`\+retain[\s*]+loop[\s*]+([\d.]+)[\s*]+ms[\s*]+against[\s*]+`sha256`[\s*]+alone[\s*]+at[\s*]+([\d.]+)[\s*]+ms[\s*]+—[\s*]+([\d.]+)[\s*]+ms[\s*]+of[\s*]+encode[\s*]+attributable/s', $doc, $ways),
            'the three-ways re-measurement no longer spells all five figures in one breath — the prose restructured, rewrite the pin with it',
        );
        self::assertSame((int) $sum[1], (int) round((float) $ways[2]), 'the detailed encode-and-retain figure no longer rounds to the headline json_encode figure — "the figures above stand" is now false');
        self::assertSame((int) $sum[2], (int) round((float) $ways[4]), 'the detailed sha256-alone figure no longer rounds to the headline sha256 figure');
        self::assertSame((float) $ways[5], round((float) $ways[3] - (float) $ways[4], 1), 'the stated one-decimal attribution is no longer loop minus sha-alone (rounded)');

        self::assertSame(
            1,
            preg_match('/is the other ~(\d+) ms/', $doc, $retain),
            'the retain-costs-more sentence moved',
        );
        self::assertSame((int) $retain[1], (int) round((float) $ways[2] - (float) $ways[1]), 'the "~N ms" retention gap is no longer retain minus discard');
    }

    /**
     * E686 tranche-5 (X): the 0.47s alt-screen window is pure host timing — no
     * constant owns it — so the digit stays free, exactly as the 64-KiB
     * family's host labels do, while every SITE that quotes it must quote the
     * SAME one: two docs pages state the delay to the user, and the two
     * src sites that carry the MEASURED label are its provenance. Half an
     * update (PERMISSIONS moved, ARCHITECTURE didn't) is the failure this
     * catches (be's held row, judged: TRUE-as-family).
     */
    public function testAltScreenPaintDelayFamilyQuotesOneDigit(): void
    {
        $arch = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md');
        $perm = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/PERMISSIONS.md');

        self::assertSame(
            1,
            preg_match('/paints over stderr ([\d.]+)s later/', $arch, $a),
            'ARCHITECTURE.md no longer states the alt-screen paint delay beside the transcript-row rationale',
        );
        self::assertSame(
            1,
            preg_match('/paints over stderr ([\d.]+)s later/', $perm, $p),
            'PERMISSIONS.md no longer states the alt-screen paint delay beside the never-silent-refusal bullet',
        );
        self::assertSame($p[1], $a[1], 'the two docs pages quote different paint delays — the family must move together or split its premise explicitly');

        $prompt = self::sourceOf('Cli/HeadlessPermissionPrompt.php');
        $boot = self::sourceOf('Cli/Bootstrap.php');
        self::assertSame(1, preg_match('/\(MEASURED at ([\d.]+)s on a/', $prompt, $m1), 'HeadlessPermissionPrompt lost the MEASURED label the docs cite through it — the digit may move, the labeling may not');
        self::assertSame(1, preg_match('/\(MEASURED: ([\d.]+)s on a real pty run\)/', $boot, $m2), 'Bootstrap::warnPermissionConfigInTranscript lost its MEASURED provenance sentence');
        self::assertSame($a[1], $m1[1], 'the docs paint-delay digit no longer matches the src MEASURED site');
        self::assertSame($a[1], $m2[1], 'the docs paint-delay digit no longer matches Bootstrap\'s MEASURED site');
    }

    /**
     * E686 tranche-5 (Y): the ARCHITECTURE Chat paragraph is this campaign's
     * own correction idiom in the wild — it de-digitalised a rotted figure and
     * points at `wc -l` as the instrument. So the surviving claims are the
     * ones that ARE derivable: "well past ten thousand lines" checks the
     * spelled word against the live count, "the largest file in the package"
     * checks a live scan of src/, and the retraction must stay quoted — its
     * "was stale by the time anyone read it" additionally asserts the quoted
     * figure is STILL not the live one (be row 22's other half, anchors
     * re-derived at this base).
     */
    public function testArchitectureChatParagraphStaysLive(): void
    {
        $arch = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md');
        $chat = \dirname(__DIR__, 2) . '/src/Chat.php';

        self::assertSame(
            1,
            preg_match('/well past ([a-z]+) thousand lines; run\s+`wc -l src\/Chat\.php` rather than trusting a figure here/s', $arch, $m),
            'the Chat-size sentence no longer pairs its spelled floor with the wc -l instrument it names — rewrite the pin with the prose, do not delete it',
        );
        $wordNumbers = ['one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10];
        self::assertArrayHasKey($m[1], $wordNumbers, "the spelled floor '{$m[1]}' is outside the pinned word map — extend the map deliberately, never let a reworded sentence pass this arm by accident");
        $statedFloor = $wordNumbers[$m[1]] * 1000;
        $lines = count((array) file($chat));
        self::assertGreaterThan($statedFloor, $lines, 'Chat.php no longer sits well past the spelled thousand-line floor — flip the spelled word together with the code');

        self::assertSame(
            1,
            preg_match('/used to carry \("([\d,]+) lines, measured on this checkout"\) was stale by/', $arch, $quote),
            'the retraction quote must stay standing — an unpinned correction rots back into the claim it corrected (E633)',
        );
        self::assertNotSame((int) str_replace(',', '', $quote[1]), $lines, 'the "was stale" retraction quotes a figure that is EXACTLY the current count — either the count stopped moving or this sentence needs its own retraction');

        $largest = '';
        $largestSize = -1;
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__, 2) . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php' && $file->getSize() > $largestSize) {
                $largestSize = $file->getSize();
                $largest = $file->getPathname();
            }
        }
        self::assertSame($chat, $largest, 'the page still calls Chat the largest file in the package — either the claim or the code moved (E686)');
    }

    /**
     * E686 tranche-6 (Z): the live-pane timing family. THREE sites — the
     * ProcessExecutor simulation paragraph, the AgentWorkerPool forkedExecutor
     * rationale, and the WorkflowLivePaneTest comment above the probe — quote
     * the same two numbers: "7 runs in 20" and "20ms". The tick has a live
     * referent the carry said it lacked: the test file's single
     * `addPeriodicTimer(0.02, ...` painter, which the three prose digits must
     * keep matching, and "over about a second — roughly fifty ticks" closes
     * the loop at fifty × 20 ms = 1,000 ms. A failure count is measured, so
     * its digits are free to be RE-TAKEN — but only everywhere at once; half
     * the family moving is the corruption this catches (ed#12 hold promoted).
     */
    public function testLivePaneTimingFamilyQuotesOneTickAndOneFailureRate(): void
    {
        $worker = self::proseOf(self::sourceOf('Agents/ProcessExecutor.php'));
        $pool = self::proseOf(self::sourceOf('Agents/AgentWorkerPool.php'));
        $pane = self::proseOf((string) file_get_contents(\dirname(__DIR__, 2) . '/tests/Workflows/WorkflowLivePaneTest.php'));

        self::assertSame(1, preg_match('/made it fail (\d+) runs in (\d+), because/', $worker, $w), 'ProcessExecutor no longer states the measured failure rate beside the tick that explains it');
        self::assertSame(1, preg_match('/inside one (\d+)ms sampling tick/', $worker, $wt), 'ProcessExecutor no longer names the sampling tick beside the failure rate');
        self::assertSame(1, preg_match('/two `streaming` frames spaced by `usleep\(\)`, about a second end to end/', $worker, $wf), 'the fixed-known-shape sentence moved — "about a second" is half of the fifty-ticks arithmetic the pane test states');

        self::assertSame(1, preg_match('/suite failed (\d+) runs in (\d+);/', $pool, $p), 'AgentWorkerPool::forkedExecutor no longer carries the same 7-in-20 history');
        self::assertSame(1, preg_match('/on a (\d+)ms timer and that accessor goes empty/', $pool, $pt), 'the pool rationale no longer names the tick its window collapses under');

        self::assertSame(1, preg_match('/on a (\d+)ms timer and that accessor empties/', $pane, $l), 'WorkflowLivePaneTest no longer names the tick its probe runs on');
        self::assertSame(1, preg_match('/this test failed (\d+) runs in (\d+)/', $pane, $lt), 'the pane test comment no longer quotes its own measured flake rate');
        self::assertSame((int) $w[1], (int) $p[1], 'the simulated-worker history forked: ProcessExecutor and AgentWorkerPool quote different failure counts');
        self::assertSame((int) $p[1], (int) $lt[1], 'the simulated-worker history forked again: the suite quoting the failure moved away from both src rationales');
        self::assertSame((int) $w[2], (int) $p[2], 'the three sites quote different denominators for the same measured run-count');
        self::assertSame((int) $wt[1], (int) $pt[1], 'the tick forked between the two src doc-blocks');
        self::assertSame((int) $pt[1], (int) $l[1], 'the tick in the src rationales no longer matches the one the probe\'s own file states');

        self::assertSame(1, preg_match('/addPeriodicTimer\(\s*([0-9.]+),/', $pane, $lit), 'WorkflowLivePaneTest no longer passes its painter interval as a plain decimal — the prose tick lost its live referent');
        self::assertSame((int) $wt[1], (int) round((float) $lit[1] * 1000), 'the prose tick and the live addPeriodicTimer interval diverged — either the painter moved (rewrite all three sentences together) or the prose drifted (fix the prose)');

        self::assertSame(1, preg_match('/over about a second — roughly (\w+) ticks/', $pane, $ft), 'the fifty-ticks sentence moved — its product with the tick is the arithmetic this pins');
        $wordNumbers = ['forty' => 40, 'fifty' => 50, 'sixty' => 60, 'eighty' => 80, 'hundred' => 100];
        self::assertArrayHasKey($ft[1], $wordNumbers, "the spelled tick count '{$ft[1]}' is outside the pinned word map — extend the map deliberately, never let a reworded sentence pass this arm by accident (the ?? -1 vacuity trap, ref lane ed)");
        self::assertSame(1000, $wordNumbers[$ft[1]] * (int) $wt[1], '"roughly N ticks over about a second" no longer multiplies out to a second — move the spelled word and the tick together or not at all');
    }

    /**
     * E686 tranche-6 (AA): SkillRegistry's byte tables carry the campaign's
     * rarest beast — a paragraph that EXPLICITLY refuses to let its totals be
     * pinned ("a byte count is an allocator's answer") — while stating
     * arithmetic that pins cleanly regardless of allocator: every per-entry
     * figure is the total it names over the entry count in the same breath,
     * the generator is a product, the retraction's subtraction lands on its
     * own microsecond quotient, and the honest "roughly 7x and 10x" band must
     * still contain every ratio printed above it. The retracted totals follow
     * arm Y's stale-vs-live law: neither may ever equal its replacement, or
     * "Neither byte figure reproduces" rots into a lie. ed#15's whole-table
     * hold splits here the way arm W split ESS's measured block. The
     * 20,000-mod-cap entry counts are deliberately NOT repeated —
     * CompiledPatternCacheBoundTest already derives them from the live cap.
     */
    public function testSkillRegistryMeasuredBlockKeepsItsOwnQuotients(): void
    {
        $cap = (int) (new \ReflectionClass(SkillRegistry::class))->getConstant('MAX_COMPILED_PATTERNS');
        $tables = self::proseOf(self::docBlockOf(SkillRegistry::class, 'MAX_COMPILED_PATTERNS'));

        self::assertSame(1, preg_match('/20,000 entries cost ([\d,]+) B \(([\d.]+) B\/entry\) and ([\d,]+) cost ([\d,]+) B \(([\d.]+) B\/entry\)/', $tables, $cur), 'the current byte pair no longer spells totals and per-entry figures in one breath');
        self::assertSame($cap, (int) str_replace(',', '', $cur[3]), 'the prose\'s capped entry count is no longer the live MAX_COMPILED_PATTERNS');
        self::assertSame((float) $cur[2], round((int) str_replace(',', '', $cur[1]) / 20000, 1), 'the 20,000-entry per-entry figure is no longer the one-decimal quotient of its own total');
        self::assertSame((float) $cur[5], round((int) str_replace(',', '', $cur[4]) / $cap, 1), 'the capped per-entry figure is no longer the quotient of its own total');
        self::assertLessThan((float) $cur[2], (float) $cur[5], 'the prose claims per-entry cost "goes UP with n" — the current pair no longer obeys its own conclusion');

        self::assertSame(1, preg_match('/hashtable alone \(same keys and values, pre-built outside the measured window\) is ([\d.]+) B\/entry at 1,024 and ([\d.]+) B\/entry at 20,000/', $tables, $ht), 'the hashtable-only sentence moved — its two figures are the same direction claim in smaller magnitudes');
        self::assertLessThan((float) $ht[2], (float) $ht[1], 'the hashtable per-entry figures no longer rise with n either — the stated generator explanation needs re-reading');

        self::assertSame(1, preg_match('/"Uncapped … ([\d,]+) bytes of PHP heap \((\d+) B\/entry\); capped … ([\d,]+) bytes \((\d+) B\/entry/s', $tables, $old), 'the retracted quotation no longer stands intact — corrections are pinned in both directions (E633)');
        self::assertSame((int) $old[2], (int) round((int) str_replace(',', '', $old[1]) / 20000), 'the retracted uncapped figure is no longer the integer quotient of the total it quotes');
        self::assertSame((int) $old[4], (int) round((int) str_replace(',', '', $old[3]) / $cap), 'the retracted capped figure is no longer the integer quotient of the total it quotes');
        self::assertGreaterThan((int) $old[2], (int) $old[4], 'the retraction calls the old explanation "inverted" — the quoted pair no longer contradicts the current direction');
        self::assertNotSame((int) str_replace(',', '', $old[1]), (int) str_replace(',', '', $cur[1]), '"Neither byte figure reproduces" now quotes the CURRENT total — the retraction went stale and needs its own retraction');
        self::assertNotSame((int) str_replace(',', '', $old[3]), (int) str_replace(',', '', $cur[4]), 'the retracted capped total equals the current one — same rot on the capped side');

        $perf = self::proseOf((string) (new \ReflectionClass(SkillRegistry::class))->getProperty('compiledPathPatterns')->getDocComment());
        self::assertNotSame('', $perf, '$compiledPathPatterns lost its doc-block — the perf paragraph this pins was deleted, not fixed');

        self::assertSame(1, preg_match('/(\d+) patterns x (\d+) paths x (\d+) trials = ([\d,]+) pairs/', $perf, $gen), 'the generator sentence no longer spells its factors and product together');
        $pairs = (int) str_replace(',', '', $gen[4]);
        self::assertSame($pairs, (int) $gen[1] * (int) $gen[2] * (int) $gen[3], 'the stated pair count is no longer the product of the stated generator dimensions');

        self::assertSame(1, preg_match('/([\d.]+)s minus ([\d.]+)s over ([\d,]+) pairs is ([\d.]+) us per translation/', $perf, $sub), 'the retraction\'s subtraction sentence moved — it is the arithmetic proof that the OLD microsecond pair could not share a run');
        self::assertSame($pairs, (int) str_replace(',', '', $sub[3]), 'the subtraction now divides by a pair count the generator sentence does not state — the two figures cite different runs');
        self::assertSame((float) $sub[4], round(((float) $sub[1] - (float) $sub[2]) * 1000000 / $pairs, 2), 'the per-translation microseconds are no longer the difference of the two wall figures over the stated pairs');

        preg_match_all('/(\d+\.\d+)x \/ (\d+\.\d+)x \/ (\d+\.\d+)x/', $perf, $rows);
        $ratios = [];
        foreach ([1, 2, 3] as $group) {
            foreach ($rows[$group] as $r) {
                $ratios[] = (float) $r;
            }
        }
        self::assertSame(6, count($ratios), 'the ratio table no longer states two runs-triples — re-read the block before changing this pin');
        self::assertSame(1, preg_match('/give ([\d.]+)x-([\d.]+)x and ([\d.]+)x-([\d.]+)x/', $perf, $alt), 'the distinct-patterns re-take sentence moved — its four endpoints ride in the same band the conclusion claims');
        foreach ([1, 2, 3, 4] as $group) {
            $ratios[] = (float) $alt[$group];
        }
        self::assertSame(1, preg_match('/between roughly (\d+)x and (\d+)x/', $perf, $band), 'the honest-band conclusion moved — containing the stated ratios IS its claim');
        self::assertGreaterThanOrEqual((float) $band[1], min($ratios), 'a stated ratio now sits BELOW the "between roughly" floor the same paragraph concludes');
        self::assertLessThanOrEqual((float) $band[2], max($ratios), 'a stated ratio now sits ABOVE the "between roughly" ceiling the same paragraph concludes');
    }

    /**
     * E686 tranche-6 (AB): SkillPathNudge's cost table and margin sentences
     * were carried as fixture-domain — and the byte ABSOLUTES stay exactly
     * that, free — but the multiplier LABELS the prose hangs on them are
     * quotients (returned bytes over the cap named in the same sentence, one
     * decimal; the Read case integer-rounds), the "Linear in" conclusion is a
     * slope claim over the four table rows, and the shipped-margin sentence is
     * fully live: at MAX_ENTRIES the ceiling is maxBytes() against
     * Grep::DEFAULT_MAX_OUTPUT_BYTES divided by CALLER_BUDGET_DIVISOR — 8,192
     * — with 3.1x the one-decimal quotient of the last two. The two prose
     * ceilings bracket that budget (the 20-era price under it, the 27-era
     * ceiling over it), which is exactly what "20 does not red / the first
     * value that reds is 27" claims. The tipping count itself is derived live
     * by SkillPathScopingWiringTest and deliberately not repeated here.
     */
    public function testNudgeCostTableLabelsDivideTheirOwnFigures(): void
    {
        $entry = self::proseOf(self::docBlockOf(SkillPathNudge::class, 'MAX_ENTRY_BYTES'));

        preg_match_all('/(\d+) skills? x ([\d,]+)-byte descriptions? -> ([\d,]+) bytes/', $entry, $rows);
        self::assertCount(4, $rows[0], 'the four-row cost table changed shape — re-read the block (fixture absolutes stay free, but this arm\'s slopes and labels index its rows)');
        $points = [];
        for ($i = 0; $i < 4; ++$i) {
            $points[] = [(int) $rows[1][$i] * (int) str_replace(',', '', $rows[2][$i]), (int) str_replace(',', '', $rows[3][$i])];
        }
        for ($i = 1; $i < 4; ++$i) {
            $slope = ($points[$i][1] - $points[$i - 1][1]) / ($points[$i][0] - $points[$i - 1][0]);
            self::assertGreaterThan(1.0, $slope, "table rows {$i}/".($i + 1)." went backwards — the nudge no longer grows with (matching skills x description length) while the prose still claims it does");
            self::assertLessThan(1.02, $slope, "the marginal cost per description byte exceeded 1.02 between rows {$i}/".($i + 1)." — \"linear in (matching skills x description length)\" needs re-deriving with the framing it prices");
        }

        self::assertSame(1, preg_match('/cap ([\d,]+) with 1 skill x 200 returned ([\d,]+) bytes \(([\d.]+)x\)/', $entry, $q1), 'the Grep end-to-end first case no longer states cap, returned bytes, and margin together');
        self::assertSame((float) $q1[3], round((int) str_replace(',', '', $q1[2]) / (int) str_replace(',', '', $q1[1]), 1), 'the 1.3x label is no longer the one-decimal quotient of its own returned bytes over its own cap');
        self::assertSame(1, preg_match('/5 x 5,000 returned ([\d,]+) \(([\d.]+)x\)/', $entry, $q2), 'the second Grep case moved');
        self::assertSame((float) $q2[2], round((int) str_replace(',', '', $q2[1]) / (int) str_replace(',', '', $q1[1]), 1), 'the 26.2x label no longer divides by the cap the paragraph named one sentence earlier');
        self::assertSame(1, preg_match('/20 x 20,000 returned ([\d,]+) \(([\d.]+)x\)/', $entry, $q3), 'the third Grep case moved');
        self::assertSame((float) $q3[2], round((int) str_replace(',', '', $q3[1]) / (int) str_replace(',', '', $q1[1]), 1), 'the 401.4x label no longer divides by the stated cap');
        self::assertSame(1, preg_match('/Read\} at maxBytes (\d+) returned ([\d,]+) bytes on the last of those — ([\d,]+)x/', $entry, $q4), 'the Read over-read sentence moved');
        self::assertSame((int) str_replace(',', '', $q4[3]), (int) round((int) str_replace(',', '', $q4[2]) / (int) $q4[1]), 'the 2,002x label is no longer the integer-rounded quotient of returned bytes over maxBytes');

        $cap = self::proseOf(self::docBlockOf(SkillPathNudge::class, 'MAX_ENTRIES'));
        self::assertSame(1, preg_match('/at (\d+) the ceiling is ([\d,]+) bytes against a Grep\/Glob budget of ([\d,]+), a ([\d.]+)x margin/', $cap, $ship), 'the shipped-margin sentence no longer spells count, ceiling, budget and margin in one breath');
        self::assertSame((int) (new \ReflectionClass(SkillPathNudge::class))->getConstant('MAX_ENTRIES'), (int) $ship[1], 'the prose entry count drifted from live MAX_ENTRIES');
        self::assertSame(SkillPathNudge::maxBytes(), (int) str_replace(',', '', $ship[2]), 'the prose ceiling drifted from live maxBytes()');
        $budget = (int) ((new \ReflectionClassConstant(Grep::class, 'DEFAULT_MAX_OUTPUT_BYTES'))->getValue() / SkillPathNudge::CALLER_BUDGET_DIVISOR);
        self::assertSame($budget, (int) str_replace(',', '', $ship[3]), 'the prose budget is no longer the live Grep cap over the live CALLER_BUDGET_DIVISOR');
        self::assertSame((float) $ship[4], round($budget / (int) str_replace(',', '', $ship[2]), 1), 'the stated margin is no longer the one-decimal quotient of the two figures it names');

        self::assertSame(1, preg_match('/it is (\d+) — the first value that reds the guard is (\d+), where the ceiling reaches ([\d,]+)/', $cap, $tip), 'the tipping-pair sentence moved — the red/not-red bracketing IS its claim');
        self::assertSame((int) $tip[1] + 1, (int) $tip[2], '"the first value that reds" is no longer one past the stated tipping point');
        self::assertSame(1, preg_match('/It does not: (\d+) prices the ceiling at ([\d,]+), comfortably/', $cap, $era), 'the round-43 retraction sentence moved — its figure is the other half of the bracket');
        self::assertLessThan((int) $tip[1], (int) $era[1], 'the retracted 20-era count is no longer below the tipping point the prose just established');
        self::assertLessThan($budget, (int) str_replace(',', '', $era[2]), 'the prose still says the 20-era ceiling does NOT red the guard, but its figure now reaches the live budget');
        self::assertGreaterThan($budget, (int) str_replace(',', '', $tip[3]), 'the prose still says 27 reds the guard, but its ceiling figure no longer clears the live budget');
    }

    /**
     * The live roster of CRUSH_* keys a hook child receives, derived from the
     * source the runtime actually runs: the $fixed array, the payloads handed
     * to stagePayloads() at its call site, and the _FILE pointer each staged
     * payload gains. Never a hand-typed list — that is the disease E583 names
     * for symbol citations, and the 7x/8x counts on this page were the last
     * hand-typed env roster in the docs set (E686 tranche-4).
     *
     * @return array{fixed: list<string>, payloads: list<string>, pointers: list<string>, all: list<string>}
     */
    private static function hookEnvRoster(): array
    {
        $hook = self::sourceOf('Hooks/ScriptHook.php');

        self::assertSame(
            1,
            preg_match('/\$fixed = \[(.*?)\];/s', $hook, $block),
            'executeStaged() no longer builds a $fixed environment array — the roster sentence has no referent',
        );
        preg_match_all("/'(CRUSH_[A-Z_]+)'\s*=>/", $block[1], $fixedKeys);
        self::assertNotEmpty($fixedKeys[1], '$fixed names not one CRUSH_* key — the grid fence lost its anchor');

        $call = strpos($hook, 'self::stagePayloads([');
        self::assertNotFalse($call, 'the stagePayloads([...]) call site vanished — the payload roster lost its source');
        preg_match_all("/'(CRUSH_[A-Z_]+)'\s*=>/", substr($hook, $call, 600), $payloadKeys);
        self::assertNotEmpty($payloadKeys[1], 'the staged payloads name not one CRUSH_* key — the two-payload split lost its referent');

        self::assertSame(
            1,
            preg_match("/\\\$name \\. '_FILE'/", self::bodyExcerpt($hook, 'stagePayloads', 1200)),
            'the _FILE pointer convention moved — the grid fence, every count, and the marker sentence ride on it',
        );
        $pointers = array_map(static fn (string $name): string => $name . '_FILE', $payloadKeys[1]);

        $all = array_merge($fixedKeys[1], $payloadKeys[1], $pointers);
        self::assertSame(count($all), count(array_unique($all)), 'the hook environment gained a duplicated key');

        preg_match_all("/'(CRUSH_[A-Z_]+)'\s*=>/", $hook, $everywhere);
        self::assertEqualsCanonicalizing(
            array_values(array_unique($everywhere[1])),
            array_merge($fixedKeys[1], $payloadKeys[1]),
            'ScriptHook.php builds a CRUSH_* key outside the $fixed/stagePayloads pair — the grid fence and every count this tranche pinned must join it, or the _FILE pointers stopped being derived',
        );

        return ['fixed' => $fixedKeys[1], 'payloads' => $payloadKeys[1], 'pointers' => $pointers, 'all' => $all];
    }

    /**
     * ReflectionProperty::getDefaultValue() reads NULL for promoted parameters
     * at the suite's PHP level, so defaults are taken from the constructor
     * signature, which is the truth the prose quotes anyway.
     */
    private static function promotedParamDefault(string $class, string $parameter): int
    {
        foreach ((new \ReflectionClass($class))->getConstructor()->getParameters() as $param) {
            if ($param->getName() === $parameter && $param->isDefaultValueAvailable()) {
                return (int) $param->getDefaultValue();
            }
        }

        self::fail("{$class}::__construct() no longer promotes \${$parameter} with a default — the prose default lost its referent");
    }

    private static function sourceOf(string $relative): string
    {
        $text = file_get_contents(\dirname(__DIR__, 2) . '/src/' . $relative);

        self::assertIsString($text, "src/{$relative} vanished — a prose pin lost its file");

        return $text;
    }

    /**
     * Strip doc-block/comment continuation markers and collapse whitespace, so
     * a sentence wrapped at column eighty matches in one breath. A pin blind
     * to the wrapping would pass on any rewrap; this normalizer is what makes
     * the single-breath patterns honest (ref: the margin doc test's
     * docBlock() preamble — the family already ships this idiom).
     */
    private static function proseOf(string $text): string
    {
        $stripped = (string) preg_replace('/^\s*(?:\*|\/\/) ?/m', '', $text);

        return (string) preg_replace('/\s+/', ' ', $stripped);
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
