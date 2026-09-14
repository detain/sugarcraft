<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\AgentWorkerPool;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\MemoryScope;
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
use SugarCraft\Crush\Context\PromptFence;
use SugarCraft\Crush\Context\RepoMapBlock;
use SugarCraft\Crush\Context\RuleLoader;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Hooks\HookConfig;
use SugarCraft\Crush\Hooks\HookDispatcher;
use SugarCraft\Crush\Hooks\HookEvent;
use SugarCraft\Crush\Hooks\HookResult;
use SugarCraft\Crush\Hooks\ScriptHook;
use SugarCraft\Crush\MCP\McpRouter;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Support\HookContextFiles;
use SugarCraft\Crush\Tests\Support\SourceFileWalkTrait;
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
   * E686 TRANCHE-7 (round-70, lane gd) closes E353's decision by FOLDING: the
   * HOOKS.md tables that restate code — entry keys, the delimiter alphabet, the
   * exit-code contract and its match arms, the events enum and the dormant/wired
   * dispatch partition, the built-in registration roster and the refusal grid —
   * are pinned to live derivation here (arms AC-AF), the cheap inventory E353
   * itself proposed: each named symbol still exists and still has the property
   * the row claims, never a golden-file comparison. The SKILLS.md nudge sentence
   * (AG) joins figures already pinned inside src to their cross-page restatement,
   * and the ENVIRONMENT.md streaming table (AH) keeps its measured absolutes free
    * while pinning the relations its own preamble states.
    *
    * E686 TRANCHE-8 (round-71, lane gg) works the gd carry: every HELD row was
    * re-adjudicated and stays held (reasons in the gg ledger — external facts,
    * self-labeled measurements, fixture domains), while the named next slices
    * (ARCHITECTURE prose figures, ENVIRONMENT per-var rows) produced nine new
    * arms (AI-AR) and the campaign's rare event: TWO FALSE paragraphs. The
    * maxSteps-ownership paragraph had rotted its four line-number anchors (and
    * Runtime had grown a second doc-comment mention), and "ext-sqlite3 is
    * called by nothing in src/" was refuted by TaskList's own `new \SQLite3`
    * task database — both sentences rewritten truthfully IN-STEP here, per the
    * page's own E686 rule that symbols are cited by name, never by line, and
    * their surviving claims pinned live.
    *
    * E686 TRANCHE-9 (round-72, lane ha) works the two pages the tranche-8 seed
    * named that actually exist — SKILLS.md and MCP.md (the ledger records the
    * PROVIDERS/ANTHROPICS/TOOLS names as phantom files; their claim domains
    * live in the merge-owned README, guarded by ReadmeRosterDriftTest). Nine
    * arms (AS-BA): tier counts and merge orders the SKILLS page cites, the
    * dormant-skill-call census, the glob page's own MEASURED pairs replayed
    * through the live matcher, loader bounds against their constants, the MCP
    * status/verdict tables against the constants and the doctor closure, the
    * server-type factory and the env-interpolation pattern, the bridge naming
    * and permission matrix, the command surface, and Backend.php's $onEvent
    * trio against the wire encoder's own unions. Two FALSE sentences were
    * fixed IN-STEP: MCP.md's drifted `line 175` anchor (E686's own
    * symbols-not-lines rule) and Backend.php's stale "tool-lifecycle observer"
    * on completeAsync, one word the tranche-8-era rewrite left behind.
    *
    * E686 TRANCHE-10 (round-73, lane hc) finally works docs/MEMORY.md — the
    * one page the earlier tranches inventoried and left for last. Seven arms
    * (BB-BH): the private index constants and the scope vocabulary (invoking
    * normalizeScope() itself, and honoring the page's `local`-appears-nowhere
    * claim with a comment-stripped literal census), the entry-type roster and
    * the project-only fold read through reflection and the live call shapes,
    * the three-bounds table re-derived by the class's OWN public MAX_* names
    * with the 512/527 marker arithmetic recomputed, the fence section's three
    * behavioural promises replayed against PromptFence::escape() directly, the
    * importer's tag/scope/sentinel literals against their construction sites,
    * the spelled SIX containment sites pinned three ways (doc word, token
    * census, ContainedPathInventoryTest roster row), and the loader-threading
    * paragraph matched list-for-list against the constructor calls that
    * actually receive the named argument. TWO FALSE sentences fixed IN-STEP:
    * the containment paragraph still said five call sites and left
    * loadAncestorRoots()' ancestor entry unnamed (the census grew to six
    * before the page did),  and the threading sentence omitted Grep — the fifth tool that
    * receives the loader. Both corrections are pinned by the arms above them.
    *
    * @internal
    */
final class DocFigureProseDriftTest extends TestCase
{
    use SourceFileWalkTrait;

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
     * E686 tranche-7 (AC, closing E353's decision by FOLD): the HOOKS.md tables
     * that restate code — the name()/event() table, the built-ins table, the
     * refusal grid, the registration roster, and the six-mode clause — are
     * re-derived from the BuiltIn classes, registerBuiltIns(), and the
     * PermissionMode enum. Every row's property becomes a live lookup: names,
     * events and matchers come from each class's return literal, the
     * registered/NOT-registered split from the registrar body against the
     * directory roster, and the grid's refused/accepted verdicts recomputed
     * from the live (name, event) pair-set (ref: E353, docs/HOOKS.md).
     */
    public function testBuiltInHookTablesSurviveRegistrationAndNaming(): void
    {
        $hooksRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $hooks = self::markdownProse($hooksRaw);
        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];

        $live = [];
        // The wildcard rides as its own literal on purpose: a single-quoted
        // '/…/*Hook.php' string is glob-shaped and would leak into the
        // GlobDialectDifferentialTest corpus figure (lane-dd/-fj lesson, -70).
        foreach (glob(\dirname(__DIR__, 2) . '/src/Hooks/BuiltIn/' . '*' . 'Hook.php') ?: [] as $path) {
            $class = 'SugarCraft\\Crush\\Hooks\\BuiltIn\\' . basename($path, '.php');
            $text = (string) file_get_contents($path);
            $live[basename($path, '.php')] = [
                'name' => self::hookMethodLiteral($text, $class, 'name'),
                'event' => self::hookMethodLiteral($text, $class, 'event'),
                'matcher' => self::hookMethodLiteral($text, $class, 'matcher'),
            ];
        }
        ksort($live);
        self::assertCount(5, $live, 'the BuiltIn hook roster changed — the name table, the built-ins table, and BOTH bullet halves of the registration claim move together');

        self::assertSame(
            1,
            preg_match('/registers (\w+) unconditionally, ahead of anything from a file and ahead of the permission gate/', $hooks, $three),
            'the registration sentence no longer names its count and both ordering claims in one breath — re-pin with the prose, do not delete it',
        );
        $registrar = self::bodyExcerpt(self::sourceOf('Hooks/HookManager.php'), 'registerBuiltIns', 900);
        $registerNeedle = '$this->registry->register(new BuiltIn\\';
        self::assertSame($words[$three[1]] ?? -1, substr_count($registrar, $registerNeedle), 'the spelled register count no longer matches registerBuiltIns()');
        preg_match_all('/register\(new BuiltIn\\\\(\w+)\(\)\)/', $registrar, $registered);
        self::assertCount(3, $registered[1], 'the registrar body no longer builds its hooks with new BuiltIn\X() — re-derive this pin');

        // Built-ins table: the three rows, their event cells, and whichever
        // matchers they spell out.
        $tableStart = strpos($hooksRaw, '## The built-in hooks');
        $tableEnd = strpos($hooksRaw, 'Two more exist');
        self::assertIsInt($tableStart);
        self::assertIsInt($tableEnd);
        $table = substr($hooksRaw, $tableStart, $tableEnd - $tableStart);
        preg_match_all('/^\| `(\w+)` \| `(\w+)`[^\n]*/m', $table, $rows, PREG_SET_ORDER);
        self::assertEqualsCanonicalizing($registered[1], array_column($rows, 1), 'docs/HOOKS.md built-ins rows no longer name exactly the classes registerBuiltIns() registers');
        foreach ($rows as $row) {
            self::assertSame($live[$row[1]]['event'], $row[2], "the built-ins table still gives {$row[1]} event {$row[2]} — its live event() moved");
            if (str_contains($row[0], ' on `') || str_contains($row[0], 'matcher `')) {
                self::assertStringContainsString('`' . str_replace('|', '\|', $live[$row[1]]['matcher']) . '`', $row[0], "the row that spells {$row[1]}'s matcher no longer carries its live matcher() literal");
            }
        }
        self::assertSame(1, preg_match('/`AuditHook::(\w+)\(\)`/', $table, $audits), 'the audit row no longer cites a method instead of restating the path — that citation IS the E353 mitigation');
        self::assertTrue(method_exists('SugarCraft\\Crush\\Hooks\\BuiltIn\\AuditHook', $audits[1]), "AuditHook::{$audits[1]}() is gone but the table still cites it");

        // name()/event() table: every cell equals its class's return literal.
        $nameRowsStart = strpos($hooksRaw, '### A loaded hook may only add');
        $nameRowsEnd = strpos($hooksRaw, 'So `name: confirm-remove`');
        self::assertIsInt($nameRowsStart);
        self::assertIsInt($nameRowsEnd);
        $namesTable = substr($hooksRaw, $nameRowsStart, $nameRowsEnd - $nameRowsStart);
        preg_match_all('/^\| `BuiltIn\\\\(\w+)` \| \*{0,2}`([^`]+)`\*{0,2} \| \*{0,2}`(\w+)`\*{0,2} \|/m', $namesTable, $nameRows, PREG_SET_ORDER);
        self::assertEqualsCanonicalizing($registered[1], array_column($nameRows, 1), 'the name table no longer covers exactly the registered three');
        foreach ($nameRows as $row) {
            self::assertSame($live[$row[1]]['name'], $row[2], "the table still says BuiltIn\\{$row[1]} is named {$row[2]} — its name() moved (E353's exact failure mode)");
            self::assertSame($live[$row[1]]['event'], $row[3], "the table still says BuiltIn\\{$row[1]} fires on {$row[3]} — its event() moved");
        }

        // Refusal grid: verdicts RECOMPUTED from the live (name, event) pairs.
        $gridStart = strpos($hooksRaw, 'Measured on this tree, with `registerBuiltIns()`');
        $gridEnd = strpos($hooksRaw, 'is the row worth reading twice');
        self::assertIsInt($gridStart);
        self::assertIsInt($gridEnd);
        $grid = substr($hooksRaw, $gridStart, $gridEnd - $gridStart);
        self::assertSame(1, preg_match('/\| `name:` \| on `event: (\w+)` \| on `event: (\w+)` \|/', $grid, $head), 'the grid header no longer names its two event coordinates');
        preg_match_all('/^\| `([a-z-]+)` \| \*{0,2}(\w+)\*{0,2} \| \*{0,2}(\w+)\*{0,2} \|/m', $grid, $cells, PREG_SET_ORDER);
        self::assertNotEmpty($cells, 'the refusal grid lost its rows');
        $pairs = array_map(static fn (string $class): string => $live[$class]['name'] . '|' . $live[$class]['event'], $registered[1]);
        foreach ($cells as $cell) {
            self::assertSame(\in_array($cell[1] . '|' . $head[1], $pairs, true) ? 'refused' : 'accepted', $cell[2], "grid row {$cell[1]} vs {$head[1]}: the registered pairs say otherwise");
            self::assertSame(\in_array($cell[1] . '|' . $head[2], $pairs, true) ? 'refused' : 'accepted', $cell[3], "grid row {$cell[1]} vs {$head[2]}: the registered pairs say otherwise");
        }
        $liveNames = array_map(static fn (string $class): string => $live[$class]['name'], $registered[1]);
        $extras = array_values(array_diff(array_column($cells, 1), $liveNames));
        $confirmRemove = (string) current(array_filter($registered[1], static fn (string $c): bool => str_contains($c, 'Confirm')));
        self::assertSame(
            [strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', substr($confirmRemove, 0, -4)))],
            $extras,
            'the grid gained or lost its class-name-sounding example — `name()` vs class-name confusion IS the row\'s claim (census-trio lesson: flip the grid with the naming)',
        );

        // The two bullets = the roster minus the registered three, set-equal.
        self::assertSame(1, preg_match('/(\w+) more exist and are/', $hooks, $two), 'the not-registered sentence lost its spelled count');
        self::assertSame(count(array_diff(array_keys($live), $registered[1])), $words[strtolower($two[1])] ?? -1, 'the unregistered half of the roster no longer matches BuiltIn-minus-registered');
        $bulletStart = strpos($hooksRaw, 'Two more exist');
        self::assertIsInt($bulletStart);
        $bullets = substr($hooksRaw, $bulletStart);
        self::assertSame(1, preg_match('/`(\w+)` — registered by `Bootstrap::(\w+)\(\)` when a gate exists,\s*which is every CLI launch\. It is what makes the (\w+)-mode gate/', $bullets, $gateRow), 'the gate bullet no longer names its class, its Bootstrap seam, and the gate mode count together');
        self::assertSame(1, preg_match('/`(\w+)` — opt-in, constructed with a jail root/', $bullets, $jailRow), 'the opt-in bullet moved');
        self::assertEqualsCanonicalizing(array_keys(array_diff_key($live, array_flip($registered[1]))), [$gateRow[1], $jailRow[1]], 'the two bullets no longer name exactly the unregistered BuiltIn classes');
        self::assertTrue(method_exists(Bootstrap::class, $gateRow[2]), "Bootstrap::{$gateRow[2]}() no longer exists — the bullet names the wrong seam");
        $hooksBody = self::bodyExcerpt(self::sourceOf('Cli/Bootstrap.php'), $gateRow[2], 6000);
        foreach (['registerBuiltIns()', 'loadEntries(', 'new ' . $gateRow[1] . '('] as $needle) {
            self::assertStringContainsString($needle, $hooksBody, "Bootstrap::{$gateRow[2]}() no longer contains {$needle} — the ordering and gate claims lost their referent");
        }
        self::assertLessThan((int) strpos($hooksBody, 'loadEntries('), (int) strpos($hooksBody, 'registerBuiltIns()'), 'built-ins are no longer registered AHEAD of file entries — the page says the order is the point');
        self::assertLessThan((int) strpos($hooksBody, 'new ' . $gateRow[1] . '('), (int) strpos($hooksBody, 'loadEntries('), 'the gate hook no longer lands after file entries either — the precedence sentence inverted');
        self::assertSame(count(PermissionMode::cases()), $words[$gateRow[3]] ?? -1, 'the spelled gate-mode count no longer matches PermissionMode::cases()');
        self::assertStringNotContainsString('new ' . $jailRow[1] . '(', self::sourceOf('Hooks/HookManager.php'), 'the jail-root hook is now constructed inside the registrar — the page says an embedder must register it explicitly');
    }

    /**
     * E686 tranche-7 (AD, closing E353's decision by FOLD): the events table and
     * its dormancy paragraph. The spelled enum count, the one-dispatch-method-per
     * case claim, the wired attribution column (every cited `Class::method()` and
     * nothing else reaches each wired event in src/), the dash-row partition, the
     * no-call-site quartet versus the guarded trio, `src/ constructs that class
     * nowhere`, the lone `new TaskList(…)` leaving the dispatcher defaulted, and
     * `submit()` as the sole turn-hook caller — all re-derived from live code
     * (ref: E353, docs/HOOKS.md *Events*).
     */
    public function testHookEventsTableSurvivesTheLiveEnumAndDispatchSites(): void
    {
        $hooksRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $words = ['three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        $cases = HookEvent::cases();
        $caseNames = array_map(static fn (HookEvent $case): string => $case->name, $cases);

        $sectionStart = strpos($hooksRaw, '## Events');
        $sectionEnd = strpos($hooksRaw, 'The `—` rows are');
        self::assertIsInt($sectionStart);
        self::assertIsInt($sectionEnd);
        $section = substr($hooksRaw, $sectionStart, $sectionEnd - $sectionStart);

        self::assertSame(1, preg_match('/defines (\w+):/', $section, $eleven), 'the enum count sentence moved — the table lost its head number');
        self::assertSame(count($cases), $words[$eleven[1]] ?? -1, 'the spelled event count no longer counts HookEvent::cases() — flip the word and the enum together (census-trio lesson)');
        foreach ($cases as $case) {
            self::assertTrue(
                method_exists(HookDispatcher::class, 'dispatch' . $case->name),
                'HookDispatcher no longer carries dispatch' . $case->name . '() — the page says it carries one for each',
            );
        }

        preg_match_all('/^\|(.+)\|\s*$/m', $section, $tableLines);
        $citations = [];
        $dashes = [];
        foreach ($tableLines[1] as $line) {
            $cells = array_map('trim', explode('|', $line));
            if (!str_starts_with($cells[0], '`')) {
                continue; // header
            }
            preg_match_all('/`(\w+)`/', $cells[0], $rowEvents);
            if (($cells[2] ?? '') === '—') {
                array_push($dashes, ...$rowEvents[1]);
                continue;
            }
            preg_match_all('/`([A-Za-z]+)::([a-zA-Z]+)\(\)`/', $cells[2], $pairs, PREG_SET_ORDER);
            self::assertNotEmpty($pairs, 'a non-dash row cites no Class::method() symbol — the attribution column changed shape');
            foreach ($rowEvents[1] as $event) {
                foreach ($pairs as $pair) {
                    $citations[$event][] = $pair[1] . '::' . $pair[2];
                }
            }
        }
        self::assertEqualsCanonicalizing($caseNames, array_merge(array_keys($citations), $dashes), 'the table no longer covers every HookEvent case exactly once');

        // Wired rows: the cited methods are EXACTLY where each event is reached.
        foreach ($citations as $event => $cited) {
            $toolScoped = str_contains($event, 'ToolUse');
            $token = $toolScoped ? '->' . lcfirst($event) . '(' : 'HookEvent::' . $event;
            $found = [];
            foreach (self::srcTexts() as $relative => $text) {
                if (str_starts_with($relative, 'src/Hooks/')) {
                    continue; // Hooks/ internals are the wiring the table describes, not the origin it cites
                }
                $cursor = 0;
                while (false !== ($pos = strpos($text, $token, $cursor))) {
                    $cursor = $pos + 1;

                    $found[] = basename($relative, '.php') . '::' . self::enclosingMethodName($relative, $text, $pos);
                }
            }
            self::assertNotEmpty($found, "the table dispatches {$event} from cited code, but {$token} occurs nowhere in src/ any more");
            self::assertEqualsCanonicalizing($cited, $found, "docs/HOOKS.md's Dispatched-from column for {$event} no longer matches where {$token} is actually called in src/");
        }

        // Dormancy paragraph: the two halves, told apart exactly as the page does.
        $paraEnd = strpos($hooksRaw, 'What a **block**');
        self::assertIsInt($paraEnd);
        $para = self::markdownProse(substr($hooksRaw, $sectionEnd, $paraEnd - $sectionEnd));
        self::assertSame(1, preg_match('/`(\w+)`, `(\w+)`, `(\w+)` and `(\w+)` have no dispatch call site at all/', $para, $quartet), 'the no-call-site half of the dormancy split moved');
        self::assertSame(1, preg_match('/`(\w+)`, `(\w+)` and `(\w+)` do have call sites, all three in `(\w+)`, but each is guarded on an injected `HookDispatcher`, and `src\/` constructs that class nowhere/', $para, $trio), 'the guarded-trio half of the dormancy split moved');
        self::assertEqualsCanonicalizing($dashes, [$quartet[1], $quartet[2], $quartet[3], $quartet[4], $trio[1], $trio[2], $trio[3]], 'the two dormancy halves no longer split the dash rows');
        foreach ([$quartet[1], $quartet[2], $quartet[3], $quartet[4]] as $event) {
            self::assertSame([], self::srcOccurrences('->dispatch' . $event . '('), "{$event} gained a dispatch call site — the page still lists it under no-call-site-at-all");
        }
        $trioFile = $trio[4];
        foreach ([$trio[1], $trio[2], $trio[3]] as $event) {
            $hits = self::srcOccurrences('$this->hookDispatcher->dispatch' . $event . '(');
            self::assertCount(1, $hits, "{$event}: the page says all three trio call sites sit guarded in {$trioFile}, one dispatch each");
            [$relative, $pos] = $hits[0];
            self::assertSame(basename($relative), $trioFile . '.php', "{$event} is now dispatched from " . basename($relative) . " — the page names {$trioFile} as the only host");
            self::assertMatchesRegularExpression('/hookDispatcher [!=]== null/', self::enclosingFunctionSlice($relative, $pos), "the {$event} dispatch lost its injected-dispatcher guard — the page's whole dormant-not-removed argument rests on it");
        }
        self::assertSame([], self::srcOccurrences('new HookDispatcher('), 'src/ constructs a HookDispatcher somewhere — the dormancy explanation ("the dispatcher that is never built") is now false');
        self::assertSame(
            1,
            preg_match('/`(\w+)\.php`, the only production `new TaskList/', $para, $teamCite),
            'the sole-TaskList-host sentence moved',
        );
        $taskListHits = self::srcOccurrences('new TaskList(');
        self::assertCount(1, $taskListHits, 'a second production new TaskList appeared — the sentence calls Team.php the only one');
        self::assertSame('src/Agents/' . $teamCite[1] . '.php', $taskListHits[0][0], 'the lone new TaskList(…) moved off the class the page names');
        [$teamText] = [self::srcTexts()[$taskListHits[0][0]]];
        $argStart = $taskListHits[0][1] + \strlen('new TaskList(');
        $argText = self::balancedArguments($teamText, $argStart);
        self::assertStringNotContainsString(',', $argText, 'the only production new TaskList(…) no longer passes a single argument — the dispatcher is no longer left at its default');
        self::assertSame(1, preg_match('/\?HookDispatcher \$hookDispatcher = null/', self::sourceOf('Agents/TaskList.php')), 'TaskList lost the defaulted injected-dispatcher parameter the page explains dormancy with');

        // Turn events: one call site, reached from the method the page names.
        self::assertSame(1, preg_match('/`Chat::dispatchTurnHooks\(\)` is the only production call site for both, reached\s+from `(\w+)\(\)`/', $hooksRaw, $turn), 'the sole-call-site sentence no longer names its reaching method');
        $turnHits = self::srcOccurrences('->dispatchTurnHooks(');
        self::assertCount(1, $turnHits, 'dispatchTurnHooks() gained or lost a production call site — the page calls it the only one');
        self::assertSame($turn[1], self::enclosingMethodName($turnHits[0][0], self::srcTexts()[$turnHits[0][0]], $turnHits[0][1]), 'the turn hooks are no longer reached from the method the page names');

        // The documented divergence cites a real method; pin the symbol (E353 shape).
        self::assertSame(1, preg_match('/the strict\s*`HookEvent::(\w+)\(\)`\s*reading/', self::markdownProse($hooksRaw), $diverge), 'the divergence sentence no longer cites the HookEvent method it diverges from');
        self::assertTrue(method_exists(HookEvent::class, $diverge[1]), "HookEvent::{$diverge[1]}() vanished but the page still reads against it");
    }

    /**
     * E686 tranche-7 (AE, closing E353's decision by FOLD): the file-format and
     * exit-code sections of HOOKS.md. The six entry keys against
     * HookConfig::ENTRY_KEYS, the delimiter alphabet against DELIMITERS, and the
     * four worked matcher results against REAL HookConfig::pattern() calls; the
     * four-arm match, its digits, and the exit table against the ScriptHook
     * constants and the live match block; plus the line-number retraction's own
     * staleness law (arm Y idiom).
     */
    public function testHookEntryAndExitSectionsSurviveTheirSources(): void
    {
        $hooksRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $hooks = self::markdownProse($hooksRaw);
        $words = ['four' => 4, 'five' => 5, 'six' => 6];

        self::assertSame(1, preg_match('/Those (\w+) are the \*\*only\*\* keys an entry may carry/', $hooks, $six), 'the entry-key sentence lost its spelled count');
        $entryKeys = (new \ReflectionClassConstant(HookConfig::class, 'ENTRY_KEYS'))->getValue();
        self::assertSame(\count($entryKeys), $words[$six[1]] ?? -1, 'the spelled key count no longer counts HookConfig::ENTRY_KEYS — flip both together');
        $formatStart = strpos($hooksRaw, '## The file format');
        self::assertIsInt($formatStart);
        self::assertSame(1, preg_match('/```yaml\n(.*?)```/s', substr($hooksRaw, $formatStart), $fence), 'the format example lost its fenced block');
        preg_match_all('/^ {4,6}(?:- )?(\w+):/m', $fence[1], $shown);
        self::assertSame([], array_values(array_diff($shown[1], $entryKeys)), 'the YAML example demonstrates a key ENTRY_KEYS would refuse — "unrecognised key is refused" is a lie or the example moved');
        $firstEntryStart = strpos($fence[1], 'PreToolUse:');
        $secondEntryStart = strpos($fence[1], 'PostToolUse:');
        self::assertIsInt($firstEntryStart);
        self::assertIsInt($secondEntryStart);
        preg_match_all('/^ {4,6}(?:- )?(\w+):/m', substr($fence[1], $firstEntryStart, $secondEntryStart - $firstEntryStart), $full);
        self::assertEqualsCanonicalizing($entryKeys, $full[1], 'the example entry no longer demonstrates every key "Those six" counts');

        self::assertSame(1, preg_match('/picks the first of `([^`]+)` that your pattern does not contain/', $hooks, $alphabet), 'the delimiter sentence no longer spells the alphabet in one code span');
        $delimiters = (new \ReflectionClassConstant(HookConfig::class, 'DELIMITERS'))->getValue();
        self::assertSame($delimiters, explode(' ', $alphabet[1]), 'the delimiter alphabet drifted from HookConfig::DELIMITERS — content AND order are the claim');

        // The four worked results, each side live: doc needle vs pattern() call.
        self::assertSame(1, preg_match("/holds no delimiter either, so it compiles to `([^`]+)`/", $hooks, $matchAll), 'the empty-matcher sentence moved — its worked result is the claim');
        self::assertSame($matchAll[1], HookConfig::pattern(''), "the page still says '' compiles to {$matchAll[1]}, but pattern() answers otherwise");
        self::assertSame(1, preg_match('/`matcher: \x27([^\x27]+)\x27` works/', $hooks, $slashed), 'the Read|Write/Edit example moved');
        $firstAbsent = null;
        foreach ($delimiters as $delimiter) {
            if (!str_contains($slashed[1], $delimiter)) {
                $firstAbsent = $delimiter;
                break;
            }
        }
        self::assertIsString($firstAbsent, 'the example matcher now contains every delimiter in the alphabet — the walk needs a new worked case');
        self::assertSame($firstAbsent . $slashed[1] . $firstAbsent . 'i', HookConfig::pattern($slashed[1]), 'pattern() no longer wraps in the first delimiter the matcher lacks');
        self::assertSame(1, preg_match('/Under a fixed `\/` delimiter it compiled to `([^`]+)`/', $hooks, $broken), 'the historical broken form vanished from the sentence that explains WHY the alphabet exists');
        self::assertNotSame(HookConfig::pattern($slashed[1]), $broken[1], 'the page still narrates the fixed-delimiter form as the bug, yet pattern() now produces exactly it');
        self::assertSame(1, preg_match('/`matcher: \x27(\S)\x27` becomes `([^`]+)`, PCRE refuses it/', $hooks, $globStar), 'the glob-instinct sentence moved');
        self::assertSame($globStar[2], HookConfig::pattern($globStar[1]), 'the wrapped glob form no longer equals pattern() of the single character the example names');
        self::assertFalse(@preg_match($globStar[2], 'anytool'), 'the page still says PCRE refuses the wrapped glob — but the pattern compiles now');
        self::assertSame(1, preg_match('/it lands on `([^`]+)`, which matches everything/', $hooks, $omission), 'the omitted-key sentence moved');
        self::assertSame(1, preg_match("/\[(\x27matcher\x27)\] \?\? \x27([^\x27]*)\x27/", self::bodyExcerpt(self::sourceOf('Hooks/HookConfig.php'), 'parse', 12000), $fallback), 'parse() no longer defaults a missing matcher with a ?? literal — the omission route changed shape');
        self::assertSame($omission[1], $fallback[2], 'the page still says an omitted matcher lands on ' . $omission[1] . ' — the live default moved');

        // The exit-code contract: match block, constants, and table rows.
        $scriptText = self::sourceOf('Hooks/ScriptHook.php');
        self::assertSame(1, preg_match('/with a (\w+)-arm `match`: `(\d+)`, `(\d+)`, `(\d+)`, and `default`/', $hooks, $arms), 'the four-arm sentence no longer names the count and all three digits in one breath');
        self::assertSame(1, preg_match('/return match \(\$exitCode\) \{(.*?)default =>/s', $scriptText, $block), 'ScriptHook no longer resolves the exit code through a match that defaults to deny');
        preg_match_all('/self::(EXIT_\w+) =>/', $block[1], $armNames);
        self::assertSame($words[$arms[1]] ?? -1, \count($armNames[1]) + 1, 'the spelled arm count no longer matches the live match block (EXIT_ arms plus default)');
        $armValues = array_map(static fn (string $name) => (string) (new \ReflectionClassConstant(ScriptHook::class, $name))->getValue(), $armNames[1]);
        self::assertSame([$arms[2], $arms[3], $arms[4]], $armValues, 'the digits in the four-arm sentence no longer ride the EXIT_ constants, in the order the match lists them');
        preg_match_all('/^\| `(\d+)` \| \*\*(allow|ask|modify)\*\*/m', $hooksRaw, $exitRows, PREG_SET_ORDER);
        self::assertCount(3, $exitRows, 'the exit table lost a verdict row — its digits and the constants are pinned pairwise');
        foreach ($exitRows as $row) {
            self::assertSame((int) $row[1], (int) (new \ReflectionClassConstant(ScriptHook::class, 'EXIT_' . strtoupper($row[2])))->getValue(), "the exit table still gives {$row[2]} code {$row[1]} — ScriptHook::EXIT_{$row[2]} moved");
        }
        self::assertStringContainsString('$this->executeStaged(', self::bodyExcerpt($scriptText, 'execute', 1500), 'the page attributes the verdict to execute(), which no longer delegates to the staging run where the match lives');
        self::assertSame(1, preg_match('/be printed, `line (\d+)`, had drifted by more than a hundred/', $hooks, $staleLine), 'the no-line-numbers-here retraction moved — its own staleness claim is the pin');
        $matchStart = strpos($scriptText, 'match ($exitCode)');
        self::assertIsInt($matchStart);
        self::assertGreaterThan((int) $staleLine[1] + 100, substr_count(substr($scriptText, 0, $matchStart), "\n") + 1, 'the retraction still claims the printed line had drifted by MORE than a hundred — the match moved back near it and the sentence now rots');
    }

    /**
     * E686 tranche-7 (AF, closing E353's decision by FOLD): the three runtime
     * sentences in HOOKS.md that restate constants or whole-tree absence — the
     * stream_select retry budget against DRAIN_SELECT_RETRIES, the sweep's
     * bare-temp prefix list against ToolIpcFiles::sweep()'s own constant list
     * (why sc-hook-ctx/ can never be swept, re-derived from the same values),
     * and the [exit-1] non-blocking marker against every src/ line outside the
     * dispatcher that reads it.
     */
    public function testHookDrainSweepAndMarkerSentencesSurviveTheirSources(): void
    {
        $hooksRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/HOOKS.md');
        $hooks = self::markdownProse($hooksRaw);
        $words = ['three' => 3, 'four' => 4, 'six' => 6];

        self::assertSame(1, preg_match('/retried up to (\d+) consecutive times/', $hooks, $retries), 'the EINTR ride-out sentence no longer states its budget');
        self::assertSame((int) $retries[1], (int) (new \ReflectionClassConstant(ScriptHook::class, 'DRAIN_SELECT_RETRIES'))->getValue(), 'the retry figure drifted from ScriptHook::DRAIN_SELECT_RETRIES');

        self::assertSame(1, preg_match('/matches only its (\w+) bare-temp prefixes \(([^)]+)\)/', $hooks, $sweep), 'the never-swept argument no longer counts and names the prefixes in one breath');
        $sweepBody = self::bodyExcerpt(self::sourceOf('Support/ToolIpcFiles.php'), 'sweep', 900);
        self::assertSame(1, preg_match('/foreach \(\[([^\]]+)\] as \$prefix\)/', $sweepBody, $list), 'sweep() no longer walks a bracketed constant list — the prose cannot name what the code does not enumerate');
        preg_match_all('/self::(\w+)/', $list[1], $constNames);
        $prefixes = array_map(static fn (string $name) => (string) (new \ReflectionClassConstant(ToolIpcFiles::class, $name))->getValue(), $constNames[1]);
        $cells = array_map(static fn (string $cell): string => trim($cell, " `\t"), explode(',', $sweep[2]));
        self::assertSame($words[$sweep[1]] ?? -1, \count($cells), 'the spelled prefix count no longer matches the live sweep() list');
        self::assertSame(\count($prefixes), \count($cells), 'the doc list and the sweep constant list stopped having one entry each');
        foreach ($prefixes as $i => $prefix) {
            self::assertSame($prefix . '*', $cells[$i], "prefix cell {$cells[$i]} no longer equals a ToolIpcFiles constant plus the star the sweep globs with");
            self::assertStringNotContainsString('/', $cells[$i], 'a sweep prefix gained a directory separator — "bare-temp" and the never-crossed-separator argument both rot');
        }
        self::assertSame(1, preg_match('/live in a `([^`]+)` directory inside the system temp/', $hooks, $ctxDir), 'the retained-overflow directory sentence moved');
        $dirName = (string) (new \ReflectionClassConstant(HookContextFiles::class, 'DIR_NAME'))->getValue();
        self::assertSame($dirName . '/', $ctxDir[1], 'the page names a directory HookContextFiles no longer creates');
        foreach ($prefixes as $prefix) {
            self::assertFalse(str_starts_with($dirName, $prefix), 'the retained directory now shares a prefix with a swept temp family — nothing under it is safe from the sweep any more');
        }

        self::assertSame(1, preg_match('/no shipped `HookInterface` implementation emits that prefix/', $hooks, $marker), 'the [exit-1] absence claim moved — the whole non-blocking-path paragraph rests on it');
        $needle = '[exit-' . '1]';
        $commentMentions = 0;
        foreach (self::srcTexts() as $relative => $text) {
            if ($relative === 'src/Hooks/HookDispatcher.php') {
                continue; // the dispatcher RECOGNIZES the marker; that is its job
            }
            foreach (explode("\n", $text) as $line) {
                if (!str_contains($line, $needle)) {
                    continue;
                }
                $trimmed = ltrim($line);
                if (str_starts_with($trimmed, '*') || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    ++$commentMentions;
                    continue;
                }
                self::fail("{$relative} emits the {$needle} marker outside a comment — the dispatcher docblock and docs/HOOKS.md both claim no shipped implementation does");
            }
        }
        self::assertGreaterThan(0, $commentMentions, 'nothing in src/ even NAMES the marker in prose any more — the absence claim lost its witnesses and needs re-derivation, not silence');
    }

    /**
     * E686 tranche-7 (AG): SKILLS.md's paths-cell restates figures the src
     * already owns — the entry cap and byte cap against SkillPathNudge's private
     * constants, the class ceiling against live maxBytes() AND the docblock that
     * spells the same digit (cross-page family, dl arm S idiom), the eighth
     * against CALLER_BUDGET_DIVISOR, and the stated 1.375x against the exact
     * cap + cap/4 + cap/8 the Read and TruncatesOutput comments derive. The
     * inside-the-cap versus beside-the-cap split is pinned mechanically: Grep and
     * Glob subtract $nudgeCost, Read appends.
     */
    public function testSkillsPageNudgeSentenceDividesTheSameBudgetsAsTheTools(): void
    {
        $skillsRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SKILLS.md');
        self::assertSame(1, preg_match('/^\| `paths` \|.*$/m', $skillsRaw, $line), 'the paths row moved out of the frontmatter table — the nudge sentence lost its home');
        $cell = self::markdownProse($line[0]);
        $ordinals = ['quarter' => 4, 'third' => 3, 'half' => 2, 'eighth' => 8];

        self::assertSame(1, preg_match('/at most (\d+) entries, each at most (\d+) bytes/', $cell, $shape), 'the bounded-nudge sentence no longer states both caps in one breath');
        self::assertSame((int) (new \ReflectionClassConstant(SkillPathNudge::class, 'MAX_ENTRIES'))->getValue(), (int) $shape[1], 'the page still says at most this many entries — live MAX_ENTRIES moved');
        self::assertSame((int) (new \ReflectionClassConstant(SkillPathNudge::class, 'MAX_ENTRY_BYTES'))->getValue(), (int) $shape[2], 'the page still says this many bytes per entry — live MAX_ENTRY_BYTES moved');

        self::assertSame(1, preg_match('/the class ceiling of ([\d,]+) bytes is the whole bound/', $cell, $ceiling), 'the Edit/Write half of the depends-on-the-tool sentence lost its figure');
        $live = SkillPathNudge::maxBytes();
        self::assertSame($live, (int) str_replace(',', '', $ceiling[1]), 'SKILLS.md restates a class ceiling the live maxBytes() no longer computes');
        $nudgeSource = self::sourceOf('Skills/SkillPathNudge.php');
        self::assertSame(1, preg_match('/the ceiling is ([\d,]+) bytes against/', $nudgeSource, $sibling), 'the SkillPathNudge docblock no longer states the same ceiling — the two pages drift apart silently');
        self::assertSame((int) str_replace(',', '', $sibling[1]), $live, 'the src ceiling and the SKILLS.md ceiling no longer equal live maxBytes()');

        self::assertSame(1, preg_match('/`Grep` and `Glob` subtract it from their own `maxOutputBytes`, so it is spent INSIDE the cap/', $cell, $inside), 'the inside-the-cap half of the split moved');
        foreach (['Tools/BuiltIn/Grep.php', 'Tools/BuiltIn/Glob.php'] as $tool) {
            self::assertStringContainsString('maxOutputBytes - $nudgeCost', self::sourceOf($tool), basename($tool) . ' no longer subtracts the nudge cost from its own cap — INSIDE the cap is now a lie');
        }
        self::assertSame(1, preg_match('/`Read` takes an (\w+) BESIDE its cap \(hence its stated ([\d.]+)x/', $cell, $beside), 'the beside-the-cap half moved — word and digit are one claim');
        self::assertSame(SkillPathNudge::CALLER_BUDGET_DIVISOR, $ordinals[$beside[1]] ?? -1, 'the spelled share no longer equals the live CALLER_BUDGET_DIVISOR — flip word and constant together');
        $readSource = self::sourceOf('Tools/BuiltIn/Read.php');
        self::assertStringContainsString('$content .= "\n\n" . $nudge;', $readSource, 'Read no longer appends the nudge BESIDE the capped content — the beside-vs-inside split the page draws is gone');
        foreach (['Tools/BuiltIn/Edit.php', 'Tools/BuiltIn/Write.php'] as $tool) {
            self::assertStringContainsString('No budget passed, so', self::sourceOf($tool), basename($tool) . ' now passes a nudge budget — the page says the class ceiling is the whole bound there');
        }

        $truncateText = self::sourceOf('Tools/Concerns/TruncatesOutput.php');
        self::assertSame(1, preg_match('/intdiv\(\$maxOutputBytes, (\d+)\)/', self::bodyExcerpt($truncateText, 'instructionBudget', 400), $quarter), 'instructionBudget() no longer divides by a literal — the quarter the 1.375x sums over lost its referent');
        $cap = (int) (new \ReflectionClassConstant(TruncatesOutput::class, 'DEFAULT_MAX_OUTPUT_BYTES'))->getValue();
        $total = $cap + intdiv($cap, (int) $quarter[1]) + intdiv($cap, SkillPathNudge::CALLER_BUDGET_DIVISOR);
        self::assertSame(8 * $total, 11 * $cap, 'cap + quarter + eighth is no longer exactly 11/8 of the cap — every 1.375x on two pages rots at once');
        self::assertSame((float) $beside[2], $total / $cap, 'the SKILLS.md multiple is no longer the quotient of the three shares it names');
        self::assertSame(1, preg_match('/bounded at ([\d.]+)x \$maxBytes/', $readSource, $stated), 'the Read comment no longer states the total multiple');
        self::assertSame((float) $stated[1], (float) $beside[2], 'the src-stated multiple and the SKILLS.md restatement drifted apart');
        self::assertSame(1, preg_match('/so the stated total is \$maxBytes \+ (\d+)\/(\d+)/', $readSource, $fraction), 'the Read derivation no longer names its fraction');
        self::assertSame(1 / (int) $quarter[1] + 1 / SkillPathNudge::CALLER_BUDGET_DIVISOR, (int) $fraction[1] / (int) $fraction[2], 'the stated fraction is no longer the sum of the two shares the code divides by');
    }

    /**
     * E686 tranche-7 (AH): ENVIRONMENT.md's streaming table reports a measured
     * before/after, and the measured absolutes (36 ticks, 50 ms, the first
     * offset) stay free — but the preamble itself states the table's shape:
     * SIX tokens, 300 ms apart. Both columns are re-counted and their
     * successive deltas re-differenced against that spacing, so a half-updated
     * table reds instead of quietly lying (arm W's measured-sums idiom).
     */
    public function testEnvironmentPageStreamingTableKeepsItsOwnSpacing(): void
    {
        $env = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md'));
        self::assertSame(1, preg_match('/a wrapper emitting (\w+) tokens (\d+)ms apart/', $env, $claim), 'the probe preamble no longer states the token count and spacing the table rests on');
        $words = ['four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8];
        $tokens = $words[$claim[1]] ?? -1;
        self::assertGreaterThan(0, $tokens, "the spelled token count '{$claim[1]}' sits outside the pinned word map — extend it deliberately");
        $spacing = (float) $claim[2];

        self::assertSame(1, preg_match('/callback invocations \| (\d+, at [^|]+) \| (\d+, at [^|]+) \|/', $env, $row), 'the callback-invocations row changed shape — the before/after pair is what the preamble measures');
        self::assertStringContainsString('Measured on this tree', $env, 'the table lost its measurement label — free absolutes are licensed by it (arm AB law)');
        foreach ([$row[1], $row[2]] as $column => $cell) {
            self::assertSame(1, preg_match('/^(\d+), at /', $cell, $head), 'a column no longer leads with its invocation count');
            preg_match_all('/([0-9]+\.[0-9]+)s/', $cell, $stamps);
            $times = array_map('floatval', $stamps[1]);
            self::assertSame($tokens, (int) $head[1], 'the stated invocation count no longer equals the digit leading the list');
            self::assertCount($tokens, $times, 'the timestamp list no longer holds one entry per emitted token the preamble names');
            for ($i = 1; $i < \count($times); $i++) {
                self::assertLessThanOrEqual(10.0, abs(($times[$i] - $times[$i - 1]) * 1000 - $spacing), "consecutive stamps in column {$column} drifted more than 10 ms from the {$spacing}ms spacing the prose itself states");
            }
        }
    }

    /**
     * E686 tranche-8 (AI): the ARCHITECTURE maxSteps-ownership paragraph. This
     * tranche's first FALSE — every line-number anchor the paragraph carried
     * had rotted (and Runtime.php had grown a second doc-comment mention), so
     * the prose was corrected symbol-only per this page's own E686 rule. What
     * survives is checkable: every `maxSteps` mention in Runtime.php is a
     * comment, its constructor has no such parameter, the EngineBackend
     * default is 8, and the armed loop and the max(1, ...) clamp are literal
     * code the page quotes verbatim.
     */
    public function testArchitectureMaxStepsOwnershipParagraphSurvivesItsSymbols(): void
    {
        $arch = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md'));

        self::assertSame(
            1,
            preg_match('/It has no step counter — every\s+`maxSteps` mention inside `src\/Runtime\.php` sits in a doc-comment, and\s+`Runtime::__construct` takes no such parameter/', $arch),
            'the corrected no-counter sentence moved — rewrite the pin with it, and remember WHY the old line-numbered form was deleted (E686)',
        );
        foreach (['line 1433', 'lines 101-107', '(line 126)', 'EngineBackend.php:462'] as $rottenAnchor) {
            self::assertStringNotContainsString($rottenAnchor, $arch, "the rotted line-number anchor '{$rottenAnchor}' is back — this page cites symbols by name, never by line (E686)");
        }
        self::assertStringContainsString('every line-number anchor this paragraph carried had rotted', $arch, 'the corrective clause that licenses the de-anchored paragraph moved — an unpinned correction rots back (E633)');

        self::assertSame(
            1,
            preg_match('/`private readonly int \$maxSteps = (\d+)` is a constructor parameter of\s+`src\/Backend\/EngineBackend\.php`, and the bound it arms is the loop\s+`for \(\$step = 0; \$step < \$this->maxSteps; \$step\+\+\)`/', $arch, $owner),
            'the ownership sentence no longer quotes the promoted parameter and its loop verbatim in one breath',
        );
        self::assertSame(
            (int) $owner[1],
            self::promotedParamDefault(EngineBackend::class, 'maxSteps'),
            'the page still states this default for EngineBackend::$maxSteps — the promoted default moved',
        );

        $engine = self::sourceOf('Backend/EngineBackend.php');
        self::assertStringContainsString('for ($step = 0; $step < $this->maxSteps; $step++)', $engine, 'the quoted armed loop is no longer EngineBackend code — ownership moved back to Runtime?');
        self::assertStringContainsString('max(1, $maxSteps)', self::bodyExcerpt($engine, 'withMaxSteps'), 'withMaxSteps() no longer clamps with the literal the page quotes');
        self::assertStringContainsString('clamps its argument with `max(1, $maxSteps)`', $arch, 'the clamp sentence moved — its quoted literal and the code are pinned above');

        $runtimeText = self::sourceOf('Runtime.php');
        $all = substr_count($runtimeText, 'maxSteps');
        self::assertGreaterThan(0, $all, 'Runtime.php no longer mentions maxSteps at all — the doc-comment-only claim is vacuous, delete or rewrite the sentence');
        $inComments = 0;
        foreach (\PhpToken::tokenize($runtimeText) as $token) {
            if ($token->is(T_DOC_COMMENT) || $token->is(T_COMMENT)) {
                $inComments += substr_count($token->text, 'maxSteps');
            }
        }
        self::assertSame($all, $inComments, 'a maxSteps mention escaped into RUNTIME CODE in Runtime.php — the page says every mention is a comment and Runtime has no step counter');
        foreach ((new \ReflectionClass(Runtime::class))->getConstructor()->getParameters() as $param) {
            self::assertNotSame('maxSteps', $param->getName(), 'Runtime::__construct gained a maxSteps parameter — the step-less premise is gone');
        }
    }

    /**
     * E686 tranche-8 (AJ): the dependencies page counts "ten SugarCraft
     * siblings" and names each one. The count and the name set are re-derived
     * from composer.json's require block; candy-pty's exclusion is not an
     * oversight but the runtime/dev split, so both sides are pinned.
     */
    public function testArchitectureSiblingRosterDividesRuntimeFromDevRequires(): void
    {
        $arch = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md'));
        self::assertSame(
            1,
            preg_match('/(\w+) SugarCraft siblings: (.*?)\. `ext-sqlite3` is declared/s', $arch, $m),
            'the siblings sentence no longer runs from a spelled count through the named list into the ext-sqlite3 paragraph that follows',
        );
        $words = ['nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        self::assertArrayHasKey($m[1], $words, "the spelled count '{$m[1]}' is outside the pinned word map — extend it deliberately");

        preg_match_all('/`(candy-[a-z]+|sugar-veil|sugar-[a-z-]+)`/', $m[2], $named);
        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        $required = array_values(array_map(
            static fn (string $package): string => substr($package, \strlen('sugarcraft/')),
            array_keys(array_filter($composer['require'], static fn (string $key): bool => str_starts_with($key, 'sugarcraft/'), \ARRAY_FILTER_USE_KEY)),
        ));

        self::assertCount($words[$m[1]], $required, 'the spelled sibling count no longer matches composer.json require entries');
        self::assertSame(count($required), count($named[1]), 'the page names a different number of siblings than composer requires');
        self::assertEqualsCanonicalizing($required, $named[1], 'the page\'s named sibling list and composer.json require no longer describe the same set');

        $devPackages = array_map(
            static fn (string $package): string => substr($package, \strlen('sugarcraft/')),
            array_keys(array_filter($composer['require-dev'] ?? [], static fn (string $key): bool => str_starts_with($key, 'sugarcraft/'), \ARRAY_FILTER_USE_KEY)),
        );
        self::assertNotEmpty($devPackages, 'the page\'s runtime/dev split has no dev side — this pin presumes sugarcraft siblings live in require-dev too');
        foreach ($devPackages as $package) {
            self::assertNotContains($package, $named[1], "a require-dev sibling is now listed among the runtime siblings — either the page moved it or composer did");
        }
    }

    /**
     * E686 tranche-8 (AK): the Sessions-and-state table, the `/bg` `/fork`
     * sentence, and this tranche's SECOND FALSE: "ext-sqlite3 is called by
     * nothing in src/" was refuted by TaskList's own `new \SQLite3` task
     * database, so the sentence was corrected to name the one real user. The
     * corrected shape is what gets pinned: code-level SQLite3 use == exactly
     * TaskList, the store's PDO type declaration, the composer declaration,
     * doctor's pdo_sqlite probe, and every table cell against its literal.
     */
    public function testArchitectureSessionsTableAndSqliteSentenceSurviveTheirSources(): void
    {
        $archRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md');
        $arch = self::markdownProse($archRaw);

        self::assertStringNotContainsString('called by nothing in', $arch, 'the refuted claim is back — TaskList constructs \\SQLite3; see the corrected sentence this arm pins');
        self::assertSame(
            1,
            preg_match('/`ext-sqlite3` is declared, and `src\/` constructs it in exactly one place: `Agents\\\\TaskList`\'s task database\. The session store reaches SQLite through\s+\*\*PDO\*\*, which is why `doctor` probes `pdo_sqlite` rather than the extension/', $arch),
            'the corrected sqlite sentence moved — pin the replacement here rather than deleting the guard',
        );

        $users = [];
        foreach (self::srcTexts() as $relative => $text) {
            $tokens = \PhpToken::tokenize($text);
            foreach ($tokens as $index => $token) {
                if (!$token->is(T_NEW)) {
                    continue;
                }
                $next = $tokens[$index + 1] ?? null;
                if ($next !== null && $next->is(T_WHITESPACE)) {
                    $next = $tokens[$index + 2] ?? null;
                }
                if ($next !== null && ($next->text === '\\SQLite3' || $next->text === 'SQLite3')) {
                    $users[$relative] = true;
                }
            }
        }
        self::assertSame(['src/Agents/TaskList.php'], array_keys($users), 'code-level `new \\SQLite3` spread beyond (or out of) TaskList — the page names exactly one place');

        $composer = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/composer.json'), true, 512, \JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('ext-sqlite3', $composer['require'], 'the page still says ext-sqlite3 is declared — composer stopped declaring it');
        self::assertStringContainsString("'pdo_sqlite'", self::sourceOf('Cli/Subcommands.php'), 'doctor no longer probes pdo_sqlite — the page\'s rationale sentence is a lie');
        self::assertSame('PDO', (new \ReflectionProperty(EnhancedSessionStore::class, 'pdo'))->getType()->getName(), 'the session store no longer reaches SQLite through PDO — which is what makes the doctor probe the right one');

        $tableStart = strpos($arch, '## Sessions and state');
        // Wildcard split deliberately: a glued `*` literal here would be
        // harvested as glob-shaped and drift the PathGlob corpus figure
        // (E686 tranche-7's M7 lesson).
        $tableEnd = strpos($arch, '`Sessions\\Background' . '*' . '`');
        self::assertIsInt($tableStart);
        self::assertIsInt($tableEnd);
        $segment = substr($arch, $tableStart, $tableEnd - $tableStart);
        preg_match_all('/\| `([^`]+)` \| `([^`]+)`/', $segment, $rows, \PREG_SET_ORDER);
        self::assertCount(4, $rows, 'the sessions table no longer has its four directory/class rows');
        $orderedDirs = [
            '~/.sugar-crush/session.db',
            '~/.sugar-crush/memory/',
            '~/.sugar-crush/teams/',
            '<workflowsPath>/.running/',
        ];
        self::assertSame($orderedDirs, array_column($rows, 1), 'the table\'s directory column changed — re-derive each referent below before re-pinning');
        foreach ($rows as $row) {
            self::assertTrue(class_exists('SugarCraft\\Crush\\' . $row[2]), "the table cites SugarCraft\\Crush\\{$row[2]} which does not exist");
        }
        $bootstrap = self::sourceOf('Cli/Bootstrap.php');
        self::assertStringContainsString("configDir() . '/session.db'", $bootstrap, 'session.db is no longer the store file under the config dir — table cell drifted');
        self::assertStringContainsString("configDir() . '/memory'", $bootstrap, 'memory/ is no longer the store directory under the config dir — table cell drifted');
        self::assertStringContainsString("'~/.sugar-crush/teams'", self::sourceOf('Agents/TeamManager.php'), 'TeamManager no longer defaults to ~/.sugar-crush/teams — table cell drifted');
        self::assertSame('.running', (new \ReflectionClassConstant('SugarCraft\Crush\Workflows\WorkflowEngine', 'PAUSE_DIR'))->getValue(), 'the pause directory constant no longer spells .running — table cell drifted');

        self::assertSame(
            1,
            preg_match('/runs a task in a detached session \(`\/bg`, `\/fork`\)/', $arch),
            'the background-session sentence no longer names its two slash commands — check the registry below',
        );
        $slashNames = array_map(static fn (CommandSpec $spec): string => $spec->name, CommandRegistry::slashCommands());
        self::assertContains('bg', $slashNames, 'the page still cites /bg — the command left the registry');
        self::assertContains('fork', $slashNames, 'the page still cites /fork — the command left the registry');
    }

    /**
     * E686 tranche-8 (AL): the retention row quotes its own output sentence and
     * its own cap. The sentence is Bootstrap::SESSION_RETENTION_SUMMARY_FORMAT
     * filled with the row's example figures, the SAME sentence the constant's
     * doc-block carries, and 36500 is exactly 100×365. Three sites, one truth
     * (arm X's family idiom).
     */
    public function testEnvironmentRetentionRowQuotesTheLiveFormatAndCap(): void
    {
        $env = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md'));

        self::assertSame(
            1,
            preg_match('/The one-line summary \(`([^`]+)`\) is seeded into the transcript/', $env, $quote),
            'the retention row no longer quotes its transcript summary — the format it copies may have moved',
        );
        self::assertSame(
            1,
            preg_match('/^retention removed (\d+) unnamed (\w+) untouched for (\d+)\+ days \(ids on stderr\)$/', $quote[1], $parts),
            'the quoted summary no longer matches the shape the live format produces — update the example with the format',
        );
        $format = Bootstrap::SESSION_RETENTION_SUMMARY_FORMAT;
        self::assertSame(
            \sprintf($format, (int) $parts[1], $parts[2], (int) $parts[3]),
            $quote[1],
            'the row\'s example is no longer the live format filled with its own figures',
        );
        $doc = self::proseOf(self::docBlockOf(Bootstrap::class, 'SESSION_RETENTION_SUMMARY_FORMAT'));
        self::assertStringContainsString($quote[1], $doc, 'ENVIRONMENT.md and the format\'s own doc-block now quote different sentences — the family moved half');

        self::assertSame(
            1,
            preg_match('/values are capped at `(\d+)` \((\d+) years\)/', $env, $cap),
            'the cap sentence no longer states days and years together',
        );
        $maxDays = (int) (new \ReflectionClassConstant('SugarCraft\Crush\Session\SessionStore', 'MAX_RETENTION_DAYS'))->getValue();
        self::assertSame($maxDays, (int) $cap[1], 'the row still caps at this many days — live MAX_RETENTION_DAYS moved');
        self::assertSame((int) $cap[2] * 365, $maxDays, "the '(N years)' gloss is no longer the day cap divided by 365");

        self::assertSame(
            1,
            preg_match('/`SUGARCRUSH_SESSION_RETENTION_DAYS` \| `(\d+)` — retention is/', $env, $off),
            'the unset-default cell no longer states the off value',
        );
        self::assertSame(0, (int) $off[1], 'the row still says 0 means off — if the default moved, so did this claim\'s referent');
    }

    /**
     * E686 tranche-8 (AM): ENVIRONMENT's per-var rows for the two HTTP bounds
     * and ARCHITECTURE's parenthetical restatement of the parallel deadline.
     * 15.0/0.001 belong to HttpClientDefaults (arm A only ever pinned the SRC
     * sentences), and the 90 on both doc pages must equal Runtime's constant.
     */
    public function testEnvironmentTimeoutRowsQuoteTheirOwnConstants(): void
    {
        $env = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md'));
        $arch = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md'));

        self::assertSame(
            1,
            preg_match('/`SUGARCRUSH_CONNECT_TIMEOUT` \| `([\d.]+)` seconds/', $env, $connect),
            'the connect-timeout row no longer leads with its default seconds figure',
        );
        self::assertSame(
            (float) (new \ReflectionClassConstant('SugarCraft\Crush\Providers\Concerns\HttpClientDefaults', 'CONNECT_TIMEOUT_SECONDS'))->getValue(),
            (float) $connect[1],
            'the row still defaults to this many seconds — live CONNECT_TIMEOUT_SECONDS moved',
        );

        self::assertSame(
            1,
            preg_match('/anything below `([\d.]+)`, fall back to the default/', $env, $floor),
            'the fractional-floor sentence moved — the floor it names guards MIN_CONNECT_TIMEOUT_SECONDS',
        );
        self::assertSame(
            (float) (new \ReflectionClassConstant('SugarCraft\Crush\Providers\Concerns\HttpClientDefaults', 'MIN_CONNECT_TIMEOUT_SECONDS'))->getValue(),
            (float) $floor[1],
            'the row\'s floor is no longer MIN_CONNECT_TIMEOUT_SECONDS',
        );

        self::assertSame(
            1,
            preg_match('/`SUGARCRUSH_PARALLEL_TOOL_DEADLINE` \| `(\d+)` seconds/', $env, $deadlineRow),
            'the parallel-deadline row no longer states its default in seconds',
        );
        self::assertSame(
            1,
            preg_match('/`SUGARCRUSH_PARALLEL_TOOL_DEADLINE` \((\d+)s\)/', $arch, $deadlineArch),
            'the parallel-dispatch paragraph no longer restates the deadline in parentheses',
        );
        $live = (int) (new \ReflectionClassConstant(Runtime::class, 'PARALLEL_TOOL_DEADLINE_SECONDS'))->getValue();
        self::assertSame($live, (int) $deadlineRow[1], 'the ENVIRONMENT row\'s deadline drifted from Runtime::PARALLEL_TOOL_DEADLINE_SECONDS');
        self::assertSame($live, (int) $deadlineArch[1], 'the ARCHITECTURE restatement drifted from the constant the env row also quotes — cross-page family (arm S idiom)');
        self::assertStringContainsString("'parallelToolDeadlineSeconds'", self::sourceOf('Backend/EngineBackend.php'), 'the config-key fallback the row promises is no longer read at launch');
    }

    /**
     * E686 tranche-8 (AN): three ENVIRONMENT roster sentences. The deprecated
     * aliases table counts itself against the two legacy names src still
     * pairs; the permission-mode cell enumerates exactly the live enum values;
     * and the DEBUG_COMMANDS row's "five ... the two ... the per-file ... and
     * the control-plane" decomposition is re-counted from CommandLoader's own
     * report sites.
     */
    public function testEnvironmentAliasAndModeListsDivideTheirRosters(): void
    {
        $envRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md');
        $env = self::markdownProse($envRaw);
        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6];

        self::assertSame(1, preg_match('/for the (\w+) that briefly differed/', $env, $pair1), 'the app-variables preamble no longer previews the alias count');
        self::assertSame(1, preg_match('/^(\w+) app variables originally carried an underscore/m', $envRaw, $pair2), 'the aliases section no longer opens with its own count');
        preg_match_all('/^\| `(SUGAR_CRUSH_[A-Z_]+)` \| `(SUGARCRUSH_[A-Z_]+)` \|/m', $envRaw, $table, \PREG_SET_ORDER);
        self::assertCount($words[$pair1[1]] ?? -1, $table, 'the spelled "briefly differed" count no longer matches the table — flip word and rows together');
        self::assertSame($words[$pair1[1]] ?? -1, $words[strtolower($pair2[1])] ?? -2, 'the preamble and the section header state different alias counts');
        self::assertCount(2, $table, 'the alias table grew — this arm\'s word maps cover it, but the src pairing scan below must gain the pair too');
        foreach ($table as $row) {
            self::assertSame('SUGAR' . substr($row[1], \strlen('SUGAR_')), $row[2], 'the canonical column is no longer the legacy name with CRUSH_ folded out');
        }

        $legacy = [];
        foreach (self::srcTexts() as $text) {
            preg_match_all("/'(SUGAR_CRUSH_[A-Z_]+)'/", $text, $hits);
            $legacy = array_merge($legacy, $hits[1]);
        }
        $legacy = array_values(array_unique($legacy));
        self::assertEqualsCanonicalizing(array_column($table, 1), $legacy, 'src\'s legacy SUGAR_CRUSH_* spellings and the table\'s deprecated column drifted apart');
        foreach ($table as $row) {
            self::assertNotEmpty(self::srcOccurrences("'{$row[2]}', '{$row[1]}'"), "src no longer reads {$row[1]} as a fallback beside {$row[2]} — the alias stopped working or moved shape");
        }

        self::assertSame(
            1,
            preg_match('/The launch\'s permission mode: (.+?)\. Same vocabulary/', $env, $modes),
            'the permission row no longer enumerates the mode vocabulary inline',
        );
        preg_match_all('/`([a-z-]+)`/', $modes[1], $listed);
        $live = array_map(static fn (PermissionMode $mode): string => $mode->value, PermissionMode::cases());
        self::assertEqualsCanonicalizing($live, $listed[1], 'the permission row\'s vocabulary is no longer exactly PermissionMode::cases()');

        self::assertSame(
            1,
            preg_match('/`CommandLoader`\'s (\w+) refusal lines back on stderr: the (\w+) tier-directory refusals, the per-file containment skip, the per-file parse failure, and the control-plane name refusal/', $env, $refusals),
            'the DEBUG_COMMANDS row no longer decomposes its five refusals in one breath',
        );
        $loader = self::sourceOf('Commands/CommandLoader.php');
        $reported = substr_count($loader, '$this->report(');
        self::assertSame($words[$refusals[1]] ?? -1, $reported, 'the row\'s spelled total no longer counts CommandLoader\'s report() sites');
        $perFile = substr_count($loader, '$this->skippedFiles[');
        self::assertSame(2, $perFile, 'the two per-file refusals are no longer the two skippedFiles sinks — recount the decomposition');
        self::assertSame(2, substr_count($loader, '$this->refusedDirectories['), 'the tier-directory half is no longer the two refusedDirectories sinks');
        self::assertSame($words[$refusals[2]] ?? -2, substr_count($loader, '$this->refusedDirectories['), 'the row\'s spelled tier-directory count no longer matches the refusedDirectories sinks');
        self::assertSame(
            1,
            preg_match('/(\w+) of the five are paired with a collector.*?other (\w+) are per-file/s', $env, $splitDoc),
            'the collector/per-file split sentence moved — its numbers ride the same five sites',
        );
        self::assertSame($reported - $perFile, $words[strtolower($splitDoc[1])] ?? -3, 'the drained trio is no longer report sites minus per-file sites');
        self::assertSame($perFile, $words[strtolower($splitDoc[2])] ?? -2, 'the "other N are per-file" count drifted from the skippedFiles sinks');
        self::assertStringContainsString('Three of the five', $loader, 'the class docblock\'s own "three of the five" agreement with the row is gone');
    }

    /**
     * E686 tranche-8 (AO): ENVIRONMENT's provider row restates the repo's own
     * shipped dev fixture — name, URL, model, no-auth, effort vocabulary and
     * templateKwargs policy — every one of them readable from
     * .sugar-crush/config.dev.json and SglangProvider's sanitizer constants.
     */
    public function testEnvironmentDevSglangRowMatchesTheShippedConfigFile(): void
    {
        $env = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ENVIRONMENT.md'));
        $config = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/.sugar-crush/config.dev.json'), true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            1,
            preg_match('/declared in a project `\.sugar-crush\/config\.dev\.json` \(the repo ships `([^`]+)`\)\. That block points at `([^`]+)` serving `([^`]+)` with no auth/', $env, $row),
            'the row no longer names the shipped provider, its URL and its model in one breath',
        );
        $name = $row[1];
        self::assertSame('sglang', $config['providers'][$name]['type'] ?? null, "the row's block {$name} is no longer an sglang provider in the shipped file");
        self::assertSame($row[2], $config['providers'][$name]['baseUrl'] ?? null, 'ENVIRONMENT restates a baseUrl the shipped config.dev.json no longer holds');
        self::assertSame($row[3], $config['providers'][$name]['model'] ?? null, 'ENVIRONMENT restates a served model the shipped config.dev.json no longer names');
        self::assertArrayHasKey('apiKey', $config['providers'][$name], 'the row still says "with no auth" — the shipped file dropped the explicit null the no-auth reading rests on');
        self::assertNull($config['providers'][$name]['apiKey'], 'the row still says "with no auth" — the shipped file gained a key');
        self::assertSame($name, $config['defaultProvider'] ?? null, 'the row presents this block as the repo\'s shipped default provider — defaultProvider moved');

        self::assertSame(
            1,
            preg_match('/sanitized per request into the template\'s exact vocabulary — `xhigh` \(default\), `medium`, `low`, nothing else/', $env),
            'the effort-vocabulary sentence moved — it ties the page to the sanitizer\'s accepted set and default',
        );
        $templateEfforts = (new \ReflectionClassConstant(SglangProvider::class, 'QWEN3_NEXT_TEMPLATE_EFFORTS'))->getValue();
        self::assertEqualsCanonicalizing(['xhigh', 'medium', 'low'], $templateEfforts, 'the template vocabulary the page spells is no longer the sanitizer\'s accepted set');
        $shippedEffort = $config['providers'][$name]['reasoningEffort'] ?? null;
        self::assertSame(
            (new \ReflectionClassConstant(SglangProvider::class, 'QWEN3_NEXT_REASONING_EFFORT'))->getValue(),
            $shippedEffort,
            'the row calls xhigh the default on both sides — shipped file and sanitizer constant no longer agree',
        );

        self::assertSame(
            1,
            preg_match('/shipped policy is `\{"preserve_thinking": (true|false)\}`/', $env, $policy),
            'the preserve_thinking sentence lost its figure — it restates the shipped templateKwargs verbatim',
        );
        self::assertSame($policy[1] === 'true', ($config['providers'][$name]['templateKwargs']['preserve_thinking'] ?? null), 'the page\'s shipped policy is no longer the shipped file\'s');
    }

    /**
     * E686 tranche-8 (AP): the eleven-slot system-prompt list. The live method
     * appends conditionally ("a session that qualifies none of the optional
     * ones assembles fewer" — the page itself refuses to pin 11 to a runtime
     * count), so what IS pinned is the list's own arithmetic — spelled word,
     * item count, sequential ordinals — and the layer roster is DERIVED FROM
     * THE PROSE, not hand-typed beside it: every class-shaped backtick cite in
     * the list must resolve to a declared type, and every roster symbol must
     * still be cited (r71 review M10: a prose rename survived the hand-typed
     * existence checks — the doc could lie about a layer and nothing reddened).
     */
    public function testArchitectureSystemPromptSlotsCountTheirOwnList(): void
    {
        $archRaw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md');
        $start = strpos($archRaw, '### The system prompt, in assembly order');
        self::assertIsInt($start, 'the assembly-order heading moved — the eleven-slot paragraph lost its home');
        $end = strpos($archRaw, 'Item 10 is what makes', $start);
        self::assertIsInt($end, 'the follow-up paragraphs moved — the ordinals they cite stop being checkable');
        $segment = substr($archRaw, $start, $end - $start);

        self::assertSame(1, preg_match('/sections — (\w+) slots/', $segment, $word), 'the paragraph no longer spells its slot count beside the word "slots"');
        $words = ['nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        self::assertArrayHasKey($word[1], $words, "the spelled slot count '{$word[1]}' is outside the pinned word map — extend it deliberately");

        preg_match_all('/^(\d+)\. /m', $segment, $ordinals);
        self::assertSame(range(1, $words[$word[1]]), $ordinals[1] === [] ? [] : array_map('intval', $ordinals[1]), 'the numbered list is no longer exactly 1..N with N the spelled slot count');
        self::assertSame(1, preg_match('/^11\. `EnvironmentBlock` LAST/m', $segment), 'item eleven is no longer EnvironmentBlock LAST — the volatility-last ordering claim rots with it');

        // The roster is the doc's own vocabulary: the short symbol as the list
        // backticks it => the declared type it must resolve to. Every
        // class-shaped cite (optional ::method and () included) is checked both
        // ways below — cited-but-unknown is a prose rename, roster-but-uncited
        // is a prose deletion; either one stops the arm by name.
        $layers = [
            'Runtime' => 'SugarCraft\Crush\Runtime',
            'MaximsSection' => 'SugarCraft\Crush\Context\Sections\MaximsSection',
            'PromptGuidance' => 'SugarCraft\Crush\Tools\PromptGuidance',
            'RepoMapBlock' => 'SugarCraft\Crush\Context\RepoMapBlock',
            'RuleLoader' => 'SugarCraft\Crush\Context\RuleLoader',
            'InstructionFileLoader' => 'SugarCraft\Crush\Context\InstructionFileLoader',
            'PromptFence' => 'SugarCraft\Crush\Context\PromptFence',
            'MemoryBlock' => 'SugarCraft\Crush\Context\MemoryBlock',
            'SkillMatcher' => 'SugarCraft\Crush\Skills\SkillMatcher',
            'EnvironmentBlock' => 'SugarCraft\Crush\Context\EnvironmentBlock',
        ];

        preg_match_all('/`([A-Z][A-Za-z0-9]*)((?:::[A-Za-z_][A-Za-z0-9_]*)?)(?:\(\))?`/', $segment, $cites, PREG_SET_ORDER);
        self::assertNotEmpty($cites, 'the assembly list backticks not one class-shaped symbol — the derive-from-text binding has nothing left to check');
        $cited = [];
        foreach ($cites as $cite) {
            self::assertArrayHasKey($cite[1], $layers, "the assembly list cites `{$cite[1]}` as a layer — no declared type answers to that name (the prose renamed it, or invented it)");
            self::assertTrue(
                interface_exists($layers[$cite[1]]) || class_exists($layers[$cite[1]]),
                "a layer the assembly list names by symbol ({$layers[$cite[1]]}) no longer exists"
            );
            if ($cite[2] !== '') {
                $citedMethod = ltrim($cite[2], ':');
                self::assertTrue(method_exists($layers[$cite[1]], $citedMethod), "the assembly list cites {$cite[1]}::{$citedMethod}() — the code declares no such method");
            }
            $cited[$cite[1]] = true;
        }
        foreach (array_keys($layers) as $symbol) {
            self::assertArrayHasKey($symbol, $cited, "the assembly list stopped naming {$symbol} — the roster the arm derives from the prose lost a layer");
        }
        self::assertTrue(method_exists('SugarCraft\Crush\Skills\SkillMatcher', 'listForPrompt'), 'item 10 cites SkillMatcher::listForPrompt() which is gone');
        self::assertTrue(method_exists(Runtime::class, 'basePrompt'), 'item 1 is the base instructions — Runtime::basePrompt() is gone');
        self::assertTrue(class_exists('SugarCraft\Crush\Tools\BuiltIn\SkillTool'), 'the Skill tool that item 10 exists to advertise no longer exists');
    }

    /**
     * E686 tranche-8 (AR): three ARCHITECTURE engine/TUI digits the earlier
     * arms left standing free of doc-page pins — the 64 MiB frame cap, the
     * 100 ms reap window, and the 80-column split threshold — plus the split
     * doc-block's own division arithmetic (its 26/53 are quoted from the
     * constants in that same comment).
     */
    public function testArchitectureEngineAndSplitDigitsSurviveTheirConstants(): void
    {
        $arch = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/ARCHITECTURE.md'));

        self::assertSame(1, preg_match('/A frame is capped at (\d+) MiB/', $arch, $frame), 'the frame-cap bullet no longer states its MiB figure');
        $frameCap = (int) (new \ReflectionClassConstant(EngineBackend::class, 'MAX_FRAME_BYTES'))->getValue();
        self::assertSame((int) $frame[1] * 1024 * 1024, $frameCap, 'the page still caps a frame at this many MiB — live MAX_FRAME_BYTES moved');
        self::assertSame(
            $frameCap,
            (int) (new \ReflectionClassConstant('SugarCraft\Crush\MCP\StdioMcpServer', 'MAX_FRAME_BYTES'))->getValue(),
            'StdioMcpServer no longer derives the same ceiling EngineBackend owns — the family the docblock promises split',
        );

        self::assertSame(1, preg_match('/bounded (\d+) ms `WNOHANG` poll/', $arch, $reap), 'the reap bullet no longer states its millisecond budget');
        $attempts = (int) (new \ReflectionClassConstant(Runtime::class, 'REAP_ATTEMPTS'))->getValue();
        $micros = (int) (new \ReflectionClassConstant(Runtime::class, 'REAP_POLL_MICROSECONDS'))->getValue();
        self::assertSame((int) $reap[1], intdiv($attempts * $micros, 1000), 'the stated WNOHANG ceiling is no longer Runtime REAP_ATTEMPTS × REAP_POLL_MICROSECONDS');

        self::assertSame(1, preg_match('/the terminal is at least (\d+)\s+columns/', $arch, $split), 'the split sentence no longer states its minimum width');
        $renderer = new \ReflectionClass('SugarCraft\Crush\Tui\Renderer');
        self::assertSame((int) $split[1], (int) $renderer->getConstant('SPLIT_MIN_TOTAL_COLS'), 'the documented split threshold drifted from SPLIT_MIN_TOTAL_COLS');
        $tui = self::sourceOf('Tui/Renderer.php');
        self::assertSame(
            1,
            preg_match('/intdiv\(80, 3\) = (\d+)` for the agent column.*?80 - (\d+) - 1 = (\d+)/s', $tui, $math),
            'the split doc-block no longer works its own division arithmetic — the 80 inside it is a second quote of the constant',
        );
        self::assertSame(80, (int) $renderer->getConstant('SPLIT_MIN_TOTAL_COLS'), 'the doc-block still computes with a literal 80 while the constant is something else');
        self::assertSame(intdiv(80, 3), (int) $math[1], 'the doc-block quotient is no longer intdiv(80, 3)');
        self::assertSame((int) $math[1], (int) $math[2], 'the subtraction repeats a different agent-column width than the division above it');
        self::assertSame(80 - (int) $math[1] - 1, (int) $math[3], 'the shell band is no longer total minus agent minus one gutter');
        self::assertGreaterThanOrEqual((int) $renderer->getConstant('SPLIT_MIN_AGENT_COLS'), (int) $math[1], 'the agent column fell under SPLIT_MIN_AGENT_COLS — the doc-block asserts the split fits at 80 and it no longer does');
        self::assertGreaterThanOrEqual((int) $renderer->getConstant('SPLIT_MIN_BAND_COLS'), (int) $math[3], 'the band fell under SPLIT_MIN_BAND_COLS — same premise, other side');
    }

    /**
     * E686 tranche-9 (AS): the SKILLS.md tier counts and the merge orders the
     * page's precedence paragraphs state are re-counted from the walks they
     * cite — three native directory calls in priority order in
     * {@see SkillLoader::loadAllManifests()}, four foreign tree suffixes
     * across the two discoverers in {@see ForeignSkillDiscovery}, the
     * foreign-first/native-over-top registration order in
     * {@see SkillManager::loadAll()}, and the user-after-project append
     * inside tiers() that decides every within-convention collision.
     */
    public function testSkillsPageTierCountsMatchTheWalksTheyCite(): void
    {
        $skills = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SKILLS.md'));
        $bold = \chr(42) . \chr(42);

        self::assertSame(
            1,
            preg_match('/walks ' . \preg_quote($bold, '/') . 'three' . \preg_quote($bold, '/') . ' native locations; a separate discovery class walks ' . \preg_quote($bold, '/') . 'four' . \preg_quote($bold, '/') . ' foreign ones/', $skills),
            'the opening sentence no longer states both tier counts in one breath — re-pin with the prose, do not delete it',
        );
        $wordNumbers = ['three' => 3, 'four' => 4];

        $loaderBody = self::bodyExcerpt(self::sourceOf('Skills/SkillLoader.php'), 'loadAllManifests');
        self::assertSame(
            3,
            preg_match_all('/\$this->(\w+SkillsDir)\(/', $loaderBody, $native),
            'the native walk no longer calls its three directory methods through $this — the pin lost its ground',
        );
        self::assertSame(3, $wordNumbers['three'] ?? -1, 'the sentence spelled its native count as something other than three');
        self::assertSame(
            ['builtInSkillsDir', 'userSkillsDir', 'projectSkillsDir'],
            $native[1],
            'loadAllManifests() changed its native tier order or arity — the page names these three calls in this order',
        );
        self::assertSame(2, preg_match_all('/array_merge\(/', $loaderBody), 'the three tiers are no longer joined by exactly two array_merge calls — the "merged lowest-priority-first with array_merge" sentence describes a different shape');
        self::assertSame(
            1,
            preg_match('/The three are the three calls `SkillLoader::loadAllManifests\(\)` makes/', $skills),
            'the sentence stopped naming the method whose calls it counts',
        );

        $foreign = self::sourceOf('Skills/ForeignSkillDiscovery.php');
        self::assertSame(
            2,
            preg_match_all('/self::tiers\(([^)]*)\)/', $foreign, $tierCalls),
            'ForeignSkillDiscovery no longer builds its trees through tiers() — the four-suffix count lost its ground',
        );
        self::assertCount(2, $tierCalls[0], 'a third foreign convention arrived — the page names exactly two discoverers');
        $suffixes = [];
        foreach ($tierCalls[1] as $arguments) {
            preg_match_all("/'([^']+)'/", $arguments, $quoted);
            foreach ($quoted[1] as $suffix) {
                $suffixes[] = $suffix;
            }
        }

        self::assertSame(4, count($suffixes), 'the two discoverers no longer pass four tree suffixes between them — "four foreign ones" went stale');
        self::assertSame(
            1,
            preg_match('/' . \preg_quote($bold, '/') . 'four' . \preg_quote($bold, '/') . ' foreign ones/', $skills),
            'the foreign count left the opening sentence',
        );
        self::assertStringContainsString('`<root>/.claude/skills`, `~/.claude/skills`, `<root>/.opencode/skills`, `~/.config/opencode/skills`', $skills, 'the page no longer enumerates the four foreign trees the discoverers walk');
        self::assertStringContainsString('`src/Skills/ForeignSkillDiscovery.php`', $skills, 'the page lost its pointer to the discovery class');

        $managerBody = self::bodyExcerpt(self::sourceOf('Skills/SkillManager.php'), 'loadAll');
        $claudeAt = strpos($managerBody, 'discoverClaude(');
        $opencodeAt = strpos($managerBody, 'discoverOpencode(');
        $nativeAt = strpos($managerBody, 'registerFromManifest(');
        self::assertIsInt($claudeAt);
        self::assertIsInt($opencodeAt);
        self::assertIsInt($nativeAt);
        self::assertTrue($claudeAt < $opencodeAt, 'loadAll() no longer registers Claude before opencode — the page says opencode wins cross-convention, which needs this order');
        self::assertTrue($opencodeAt < $nativeAt, 'the native manifests no longer land AFTER the foreign trees — "Native always wins a name collision" is this order');

        $tiersBody = self::bodyExcerpt($foreign, 'tiers');
        $projectAt = strpos($tiersBody, '$projectRoot . $projectSuffix =>');
        $userAt = strpos($tiersBody, '$tiers[$home . $userSuffix] = [');
        self::assertIsInt($projectAt);
        self::assertIsInt($userAt);
        self::assertTrue($projectAt < $userAt, 'tiers() no longer appends the user tree after the project tree — the page says project LOSES to user within one convention');
    }

    /**
     * E686 tranche-9 (AT): the two dormancy paragraphs of SKILLS.md — the
     * auto-match chain that "reach[es] no production path" and the
     * "three methods consult" sentence for isContextFork(). Every call-site
     * count below is a live token scan of src/ plus bin/, definitions
     * excluded by the `->` needle.
     */
    public function testDormantSkillPathsStayUnreachableThroughTheWholeChain(): void
    {
        $skills = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SKILLS.md'));
        self::assertSame(
            1,
            preg_match('/implements the test and three methods consult it/', $skills),
            'the context paragraph no longer states its consulting-method count',
        );
        self::assertStringContainsString('and **reach no production path.**', $skills, 'the dormancy claim the pin polices left the page');
        self::assertStringContainsString('zero production call sites', $skills, 'the wrapper sentence left the page');

        $src = self::srcTexts();
        $binTexts = '';
        foreach (glob(\dirname(__DIR__, 2) . '/bin/' . '*') ?: [] as $path) {
            if (is_file($path)) {
                $binTexts .= (string) file_get_contents($path);
            }
        }

        $fork = [];
        foreach (self::srcOccurrences('->isContextFork(') as [$relative, $offset]) {
            $fork[] = self::enclosingMethodName($relative, $src[$relative], $offset);
        }
        sort($fork);
        self::assertCount(3, $fork, 'the number of methods consulting isContextFork() changed — the page names three');
        self::assertSame(['applySkillsToSystemPrompt', 'dispatchSkill', 'handleSelectSkill'], $fork, 'the page names these exact three consulters of isContextFork()');

        foreach (['->applySkillsToSystemPrompt(', '->dispatchSkill('] as $needle) {
            self::assertCount(0, self::srcOccurrences($needle), $needle . ' gained a caller in src/ — the page still says the method has none');
            self::assertSame(0, substr_count($binTexts, $needle), $needle . ' gained a caller under bin/ — the page still says src/ or bin/ has none');
        }

        $wrappers = [];
        foreach (self::srcOccurrences('->findForPrompt(') as [$relative, $offset]) {
            $wrappers[] = self::enclosingMethodName($relative, $src[$relative], $offset);
        }
        sort($wrappers);
        self::assertSame(
            ['findSkillsForTask', 'getSkillsForTask'],
            $wrappers,
            'findForPrompt() gained or lost a caller — the page says its only callers are exactly the two wrappers',
        );
        self::assertTrue(method_exists(SkillRegistry::class, 'findForPrompt'), 'SkillRegistry::findForPrompt() vanished — the dormant chain lost a link');
        self::assertTrue(method_exists('SugarCraft\Crush\Skills\Skill', 'matchesPrompt'), 'Skill::matchesPrompt() vanished — the page still names it');
        $matcher = self::srcOccurrences('->matchesPrompt(');
        self::assertCount(1, $matcher, 'matchesPrompt() call sites drifted — the page chains it under findForPrompt() only');
        self::assertSame(
            'findForPrompt',
            self::enclosingMethodName($matcher[0][0], $src[$matcher[0][0]], $matcher[0][1]),
            'the one remaining matchesPrompt() caller is no longer findForPrompt() — the sentence describing the chain went stale',
        );
        self::assertCount(0, self::srcOccurrences('->getSkillsForTask('), 'getSkillsForTask() gained a caller — its zero-production-call-site sentence (and this pin) must flip together');
        self::assertCount(0, self::srcOccurrences('->findSkillsForTask('), 'findSkillsForTask() gained a caller — its zero-production-call-site sentence (and this pin) must flip together');
    }

    /**
     * E686 tranche-9 (AU): the glob page's MEASURED lines are replayed, not
     * quoted — each `pattern` vs `path` → true pair parsed out of SKILLS.md is
     * re-answered through the live {@see SkillRegistry::pathMatches()}, and
     * each was/is pair through the private legacyPathMatch() the page says the
     * fallback still runs. The leading-** built-in roster is re-scanned from
     * the shipped SKILL.md frontmatter.
     */
    public function testSkillsGlobPageMeasuresMatchTheLiveMatcher(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SKILLS.md');
        $blocks = preg_split('/\n\n/', $raw) ?: [];
        $measured = null;
        $oldVersusNew = null;
        foreach ($blocks as $block) {
            if (!str_contains($block, 'MEASURED on PHP')) {
                continue;
            }

            if (str_contains($block, 'was ') && str_contains($block, 'and is ')) {
                $oldVersusNew = $block;
            } else {
                $measured ??= $block;
            }
        }

        self::assertIsString($measured, 'the four-way MEASURED paragraph left SKILLS.md — the pin against the live matcher lost its claim');
        self::assertSame(
            1,
            preg_match('/through `SkillRegistry::pathMatches\(\)`/', $measured),
            'the paragraph stopped naming the matcher it was measured through',
        );
        preg_match_all('/`([^`]+)` vs `([^`]+)` → true/', self::markdownProse($measured), $pairs, PREG_SET_ORDER);
        self::assertCount(4, $pairs, 'the MEASURED paragraph no longer lists four true verdicts — re-pin with the prose');
        foreach ($pairs as $pair) {
            self::assertTrue(
                SkillRegistry::pathMatches($pair[1], $pair[2]),
                "SKILLS.md claims pathMatches({$pair[1]}, {$pair[2]}) is true and the live matcher disagrees",
            );
        }

        self::assertIsString($oldVersusNew, 'the old-predicate paragraph left SKILLS.md — the was/is polarity pin lost its claim');
        preg_match_all('/`([^`]+)` vs `([^`]+)` was (true|false) and is (true|false)/', self::markdownProse($oldVersusNew), $flips, PREG_SET_ORDER);
        self::assertCount(2, $flips, 'the old-versus-new paragraph no longer states two was/is pairs');
        $legacy = new \ReflectionMethod(SkillRegistry::class, 'legacyPathMatch');
        $legacy->setAccessible(true);
        foreach ($flips as $flip) {
            self::assertSame(
                'false' === $flip[3],
                !$legacy->invoke(null, $flip[1], $flip[2]),
                "SKILLS.md says {$flip[1]} vs {$flip[2]} was {$flip[3]} under the legacy predicate and the shipped legacyPathMatch() disagrees",
            );
            self::assertSame(
                'true' === $flip[4],
                SkillRegistry::pathMatches($flip[1], $flip[2]),
                "SKILLS.md says {$flip[1]} vs {$flip[2]} is {$flip[4]} now and the live matcher disagrees",
            );
        }

        self::assertStringContainsString('self::legacyPathMatch($pattern, $path)', self::bodyExcerpt(self::sourceOf('Skills/SkillRegistry.php'), 'pathMatches'), 'pathMatches() no longer routes uncompileable patterns to the legacy predicate — the page calls the fallback reachable');

        $star = \chr(42);
        $leading = $star . $star;
        $builtInRoot = \dirname(__DIR__, 2) . '/src/Skills/BuiltIn';
        $declaring = [];
        foreach (scandir($builtInRoot) ?: [] as $entry) {
            $file = $builtInRoot . '/' . $entry . '/SKILL.md';
            if (!is_dir($builtInRoot . '/' . $entry) || !is_file($file)) {
                continue;
            }
            $lines = file($file);
            $inside = false;
            $paths = [];
            foreach ($lines ?: [] as $line) {
                $rtrimmed = rtrim($line);
                if ('---' === $rtrimmed) {
                    if (!$inside) {
                        $inside = true;

                        continue;
                    }
                    break;
                }
                if (!$inside) {
                    continue;
                }
                if (preg_match('/^paths:$/', $rtrimmed)) {
                    continue;
                }
                if (preg_match('/^  - "(.+)"$/', $rtrimmed, $m)) {
                    $paths[] = $m[1];
                }
            }
            foreach ($paths as $path) {
                if (str_starts_with($path, $leading . '/')) {
                    $declaring[$entry][] = $path;
                }
            }
        }

        self::assertSame(
            1,
            preg_match('/Three shipped built-in skills declare a leading/', $raw),
            'the leading-** paragraph no longer states its count',
        );
        self::assertCount(3, $declaring, 'the number of shipped built-ins with a leading-** path changed — flip the page sentence and this pin together');
        ksort($declaring);
        self::assertSame(['php-best-practices', 'phpunit-master', 'security-audit'], array_keys($declaring), 'a different built-in now leads with ** — the page names security-audit, php-best-practices and phpunit-master');
        foreach ($declaring as $name => $patterns) {
            self::assertStringContainsString('`' . $name . '`', $raw, "the page stopped naming {$name} in the leading-** paragraph");
            self::assertStringContainsString('(`paths: ' . json_encode($patterns, \JSON_UNESCAPED_SLASHES) . '`)', $raw, "the page no longer quotes {$name}'s shipped paths value verbatim");
        }
    }

    /**
     * E686 tranche-9 (AV): the staging section and the containment section of
     * SKILLS.md against SkillLoader — stage methods exist, the asset whitelist
     * equals its constant, and the two numeric bounds read
     * MAX_DEPTH/MAX_DIRECTORIES; the foreign user-tier door reads the
     * HomeDirectory::owned() guard tiers() opens it on.
     */
    public function testSkillsStagingAndContainmentBoundsReadTheirOwnConstants(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/SKILLS.md');
        $skills = self::markdownProse($raw);
        $bold = \chr(42) . \chr(42);

        self::assertStringContainsString('## The three loading stages', $raw, 'the staging heading moved or reworded its count');
        foreach (['loadSkillManifest', 'loadSkillBody', 'loadSkillAsset'] as $stage) {
            self::assertTrue(method_exists(SkillLoader::class, $stage), "SkillLoader::{$stage}() vanished — the loading-stages section of SKILLS.md names it");
        }
        $loader = new \ReflectionClass(SkillLoader::class);
        self::assertSame(
            ['scripts', 'references', 'assets'],
            $loader->getConstant('ASSET_SUBDIRS'),
            'the asset whitelist changed — the page names scripts/, references/ and assets/ as the only stage-3 roots',
        );
        self::assertStringContainsString('one file from `scripts/`, `references/` or `assets/`', $skills, 'the stage-3 sentence no longer enumerates the whitelist');
        self::assertStringContainsString(
            '!in_array($firstComponent, self::ASSET_SUBDIRS, true)',
            self::bodyExcerpt(self::sourceOf('Skills/SkillLoader.php'), 'loadSkillAsset'),
            'loadSkillAsset() no longer refuses non-whitelisted first components — the page says any other is refused',
        );

        self::assertSame(
            1,
            preg_match('/the walk is bounded in four separate ways \(`SkillLoader::skillFilesIn\(\)`\)/', $skills),
            'the containment sentence no longer states its bound count with its method cite',
        );
        $containment = self::markdownProse((string) preg_replace('/^.*## Containment\n/s', '', $raw));
        $containment = (string) explode('## Diagnostics', $containment)[0];
        self::assertSame(4, preg_match_all('/- ' . \preg_quote($bold, '/') . '/', $containment), 'the containment bullet count changed — the sentence above it says four separate ways');

        self::assertSame(
            1,
            preg_match('/Depth is capped at (\d+)\*\* and \*\*breadth at (\d+) directories/', $skills, $walkCaps),
            'the depth/breadth sentence no longer carries both digits in one breath',
        );
        self::assertSame((int) $walkCaps[1], (int) $loader->getConstant('MAX_DEPTH'), 'the page depth figure drifted from SkillLoader::MAX_DEPTH');
        self::assertSame((int) $walkCaps[2], (int) $loader->getConstant('MAX_DIRECTORIES'), 'the page breadth figure drifted from SkillLoader::MAX_DIRECTORIES');
        self::assertSame(6, (int) $loader->getConstant('MAX_DEPTH'), 'MAX_DEPTH moved — the page still caps the walk at 6');
        self::assertSame(2000, (int) $loader->getConstant('MAX_DIRECTORIES'), 'MAX_DIRECTORIES moved — the page still caps breadth at 2000');

        $tiersBody = self::bodyExcerpt(self::sourceOf('Skills/ForeignSkillDiscovery.php'), 'tiers');
        self::assertStringContainsString('HomeDirectory::owned()', $tiersBody, 'tiers() no longer asks who owns the home — the dropped-user-tier bullet lost its referent');
        self::assertStringContainsString('if ($home !== null) {', $tiersBody, 'the user-tier entry is no longer behind the owned() guard');
        self::assertStringContainsString('is dropped entirely**', $skills, 'the page stopped claiming the whole-tier drop — re-check against the guard above');
    }

    /**
     * E686 tranche-9 (AW): MCP.md's trust-gate table against the four
     * Bootstrap status constants (selected by their doc-block anchor to
     * mcpConfigDecision(), so message-format MCP_* constants cannot join),
     * the "contains no proc_open()" inventory claim against the method's own
     * token slice, the doctor verdict row against the closure that produces
     * it, and the "other three" cross-page count against PERMISSIONS.md's own
     * table.
     */
    public function testMcpTrustGateStatusesAndDoctorVerdictsReadTheirConstants(): void
    {
        $mcp = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md'));
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        self::assertSame(
            1,
            preg_match('/It returns one of four statuses:/', $mcp),
            'the trust-gate sentence no longer states its status count',
        );

        $bootstrap = new \ReflectionClass(Bootstrap::class);
        $statuses = [];
        foreach ($bootstrap->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            if (preg_match('/^MCP_/', $constant->getName()) === 1 && str_contains((string) $constant->getDocComment(), 'mcpConfigDecision()')) {
                $statuses[$constant->getName()] = $constant->getValue();
            }
        }
        self::assertSame(
            ['absent', 'outside-tree', 'untrusted', 'trusted'],
            array_values($statuses),
            'the status constants (doc-block-anchored to mcpConfigDecision) no longer spell the page\'s four-row table — flip constant, table and this pin together',
        );
        self::assertCount(4, $statuses);

        $rows = (string) preg_replace('/^.*\| Status \| Meaning \| What happens \|\n\|---\|---\|---\|\n/s', '', $raw);
        $rows = (string) explode("\n\n", $rows)[0];
        preg_match_all('/^\| `([a-z-]+)` \|/m', $rows, $table);
        self::assertSame($statuses, array_combine(array_keys($statuses), $table[1]), 'the status table rows drifted from the constants in value or order');

        $inventory = null;
        foreach (self::functionSpans(self::sourceOf('Cli/Bootstrap.php')) as $span) {
            if ('mcpServerInventory' === $span['name']) {
                $inventory = $span;
            }
        }
        self::assertIsArray($inventory, 'Bootstrap::mcpServerInventory() vanished — the no-proc_open claim lost its referent');
        $slice = (string) substr(self::sourceOf('Cli/Bootstrap.php'), $inventory['begin'], $inventory['end'] - $inventory['begin']);
        self::assertStringContainsString('mcpConfigDecision(', $slice, 'the slice no longer routes through the shared decision path — the zero-exec needles below would prove nothing without this anchor');
        foreach (['proc_open(', 'popen(', 'shell_exec(', 'passthru(', 'system(', 'exec('] as $sink) {
            self::assertSame(0, substr_count($slice, $sink), "mcpServerInventory() now calls {$sink} — the page claims the listing contains NO proc_open and starts nothing");
        }
        self::assertStringContainsString(
            'contains ' . \chr(42) . \chr(42) . 'no `proc_open()`' . \chr(42) . \chr(42),
            $mcp,
            'the inventory claim sentence reworded — the zero-sink pin above needs to keep citing it',
        );

        $probes = null;
        foreach (self::functionSpans(self::sourceOf('Cli/Subcommands.php')) as $span) {
            if ('doctorProbes' === $span['name']) {
                $probes = $span;
            }
        }
        self::assertIsArray($probes, 'doctorProbes() vanished — the doctor verdict row lost its closure');
        $probeText = (string) substr(self::sourceOf('Cli/Subcommands.php'), $probes['begin'], $probes['end'] - $probes['begin']);
        self::assertSame(
            3,
            preg_match_all('/Bootstrap::(MCP_[A-Z_]+)\s*\n\s*=> \[.status. => .(OK|WARN|FAIL)./', $probeText, $verdicts),
            'the mcp config row no longer matches its three named status constants to verdicts arm by arm',
        );
        self::assertSame(
            ['MCP_ABSENT' => 'OK', 'MCP_OUTSIDE_TREE' => 'FAIL', 'MCP_UNTRUSTED' => 'WARN'],
            array_combine($verdicts[1], $verdicts[2]),
            'a named status changed its doctor verdict — the page sentence and this pin must flip together',
        );
        self::assertSame(
            1,
            preg_match('/default\s*\n\s*=> \[.status. => .OK./', $probeText),
            'the trusted/default verdict is no longer OK',
        );
        self::assertSame(
            1,
            preg_match('/\[.error.\] !== null\s*\n\s*=> \[.status. => .FAIL./', $probeText),
            'the undecodable-config verdict is no longer FAIL',
        );
        self::assertStringContainsString(
            '`OK` for absent or trusted, `WARN` for untrusted, `FAIL` for out-of-tree or undecodable',
            $mcp,
            'the doctor verdict sentence reworded while the closure above still answers — re-pin with the prose',
        );

        $permissions = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/PERMISSIONS.md');
        self::assertSame(
            1,
            preg_match('/^## The four `trustedProject[^`]*` keys$/m', $permissions),
            'the PERMISSIONS.md heading no longer states its four-key count',
        );
        preg_match_all('/^\| `trustedProject([A-Za-z]+)` \|/m', $permissions, $keys);
        self::assertSame(['Hooks', 'Mcp', 'Commands', 'Settings'], $keys[1], 'the trustedProject* table rows changed — this family feeds two pages');
        self::assertCount(4, $keys[1]);
        foreach ($keys[1] as $suffix) {
            $literal = "'trustedProject" . $suffix . "'";
            $found = false;
            foreach (self::srcTexts() as $text) {
                if (str_contains($text, $literal)) {
                    $found = true;
                    break;
                }
            }
            self::assertTrue($found, "PERMISSIONS.md lists trustedProject{$suffix} but no src/ literal reads that config key any more");
        }
        self::assertSame(
            1,
            preg_match('/the other three `trustedProject[^`]*` keys/', $mcp),
            'MCP.md stopped claiming exactly three REMAINING keys — the count is the PERMISSIONS table minus the MCP row',
        );
        self::assertSame(\count($keys[1]) - 1, 3, 'the cross-page arithmetic broke: "other three" must equal the PERMISSIONS table count minus one');
    }

    /**
     * E686 tranche-9 (AX): MCP.md's type table against the factory that
     * builds it — match arms, classes, per-type config keys, the absent-type
     * default and the throw for anything else — plus the env-interpolation
     * section: the two resolveEnv call sites, the anchored pattern quoted
     * byte-for-byte, and the getenv ?: default form.
     */
    public function testMcpServerTypesKeysAndEnvInterpolationMatchTheFactory(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        $mcp = self::markdownProse($raw);
        self::assertSame(
            1,
            preg_match('/Three types, and they are the three `McpClient::startServer\(\)` constructs/', $mcp),
            'the type-table lead-in no longer states the count with its factory cite',
        );

        $clientSource = self::sourceOf('MCP/McpClient.php');
        $build = null;
        $start = null;
        $resolve = null;
        foreach (self::functionSpans($clientSource) as $span) {
            $build ??= 'buildServer' === $span['name'] ? $span : null;
            $start ??= 'startServer' === $span['name'] ? $span : null;
            $resolve ??= 'resolveEnv' === $span['name'] ? $span : null;
        }
        self::assertIsArray($build);
        self::assertIsArray($start);
        self::assertIsArray($resolve);
        $buildText = (string) substr($clientSource, $build['begin'], $build['end'] - $build['begin']);
        $startText = (string) substr($clientSource, $start['begin'], $start['end'] - $start['begin']);

        self::assertStringContainsString('$this->buildServer(', $startText, 'startServer() no longer delegates to buildServer() — the page credits it with the constructs');
        self::assertSame(
            1,
            preg_match("/\\\$type = \\\$config\['type'\] \?\? 'stdio'/", $startText),
            'the absent-type default is no longer stdio — the table row says it is',
        );
        self::assertStringContainsString('(the default when `type` is absent)', $mcp, 'the stdio row lost its default note');
        self::assertSame(
            3,
            preg_match_all("/'(stdio|http|git)' => new ([A-Za-z]+)/", $buildText, $arms, PREG_SET_ORDER),
            'buildServer() no longer constructs one named class per match arm — the pin lost its ground',
        );
        self::assertCount(3, $arms, 'the factory gained or lost a server type — the page table carries three rows');
        $built = [];
        foreach ($arms as $arm) {
            $built[$arm[1]] = $arm[2];
        }

        preg_match_all('/^\| `(stdio|http|git)`(?: \(the default when `type` is absent\))? \| `([A-Za-z]+)` \| ([^|]+) \|$/m', $raw, $rows, PREG_SET_ORDER);
        self::assertCount(3, $rows, 'the type table no longer carries three parseable rows — re-pin with the prose');
        $documented = [];
        $documentedKeys = [];
        foreach ($rows as $row) {
            $documented[$row[1]] = $row[2];
            preg_match_all('/`([a-zA-Z]+)`/', $row[3], $cells);
            $documentedKeys[$row[1]] = $cells[1];
        }
        self::assertSame($documented, $built, 'the factory classes and the table disagree — the page says the types ARE the three constructs');
        foreach ($documented as $type => $class) {
            self::assertTrue(class_exists('SugarCraft\Crush\MCP\\' . $class), "the table row for {$type} names {$class}, which no longer exists");
        }

        $armPositions = [];
        foreach (['stdio', 'http', 'git'] as $type) {
            $armPositions[$type] = strpos($buildText, "'{$type}' =>");
            self::assertIsInt($armPositions[$type]);
        }
        $defaultAt = strpos($buildText, 'default =>');
        self::assertIsInt($defaultAt);
        $order = $armPositions;
        $order['default'] = $defaultAt;
        asort($order);
        $boundary = array_values($order);
        foreach (array_slice($boundary, 0, 3) as $index => $begin) {
            $type = array_keys($order)[$index];
            $armSlice = substr($buildText, $begin, $boundary[$index + 1] - $begin);
            preg_match_all("/\\\$config\['(\w+)'\]/", $armSlice, $reads);
            self::assertEqualsCanonicalizing(
                $documentedKeys[$type],
                array_values(array_unique($reads[1])),
                "the {$type} row's key cell and the factory's \$config reads for that arm disagree",
            );
        }
        self::assertStringContainsString('$this->resolveEnv(', $buildText, 'buildServer() no longer interpolates at all — the two-key claim lost its ground');
        preg_match_all('/resolveEnv\(\$config\[.([a-z]+).\]/', $buildText, $interpolated);
        self::assertSame(['env', 'headers'], $interpolated[1], 'resolveEnv is applied to more or fewer config keys than the page\'s two');
        self::assertStringContainsString('applied to ' . \chr(42) . \chr(42) . 'two keys only' . \chr(42) . \chr(42), $mcp, 'the two-keys claim reworded while the call-site census above still answers — re-pin together');
        self::assertStringContainsString(
            'applied to `command`, `args`, `url` or `path`',
            $mcp,
            'the negative half of the interpolation claim left the page',
        );
        self::assertSame(
            1,
            preg_match('/default =>\s*throw new \\\\RuntimeException\(/', $buildText),
            'an unknown type no longer throws — the page says any other type throws',
        );
        self::assertStringContainsString(\chr(42) . \chr(42) . 'throws' . \chr(42) . \chr(42), $mcp, 'the throw claim reworded — this pin and the default arm must move together');

        $resolveText = (string) substr($clientSource, $resolve['begin'], $resolve['end'] - $resolve['begin']);
        self::assertSame(
            1,
            preg_match('/preg_match\(.(\/[^\/]+\/).,/', $resolveText, $anchored),
            'resolveEnv() no longer matches one quoted anchored pattern — the page quotes it verbatim',
        );
        self::assertSame(
            1,
            preg_match('/The pattern is anchored \(`([^`]+)`\)/', $mcp, $quoted),
            'the page stopped quoting the anchored pattern',
        );
        self::assertSame($anchored[1], $quoted[1], 'the anchored pattern in resolveEnv() and the one MCP.md quotes no longer match byte for byte');
        self::assertSame(
            1,
            preg_match('/getenv\(\$matches\[1\]\) \?: \(/', $resolveText),
            'the resolution is no longer the getenv-then-?: form the page paraphrases as `getenv($name) ?: $default` — flip paraphrase and code together',
        );
        self::assertStringContainsString('`getenv($name) ?: $default`', $mcp, 'the page stopped quoting the resolution form');
    }

    /**
     * E686 tranche-9 (AY): the bridge-name convention and the permission
     * matrix the MCP.md page summarizes — NAME_PREFIX and its concatenation,
     * the McpToolBridge class doc-block's own six-row table against the
     * PermissionMode enum, exactly one divergence (plan), and the isWriteTool
     * mcp__ clause the divergence runs through.
     */
    public function testBridgeNamingAndPermissionMatrixCoincideWithThePage(): void
    {
        $mcp = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md'));
        $bridgeSource = self::sourceOf('Tools/McpToolBridge.php');
        self::assertSame('mcp__', constant('SugarCraft\Crush\Tools\McpToolBridge::NAME_PREFIX'));
        self::assertStringContainsString('`mcp__<server>__<tool>`', $mcp, 'the naming-convention claim left the page');
        $rawPage = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        self::assertTrue(
            str_contains($rawPage, '`mcp__git__' . \chr(42) . '`'),
            'the page stopped citing a mcp__git__ rule pattern alongside the convention',
        );
        self::assertSame(
            1,
            preg_match('/self::NAME_PREFIX\s*\n?\s*\. self::sanitize\(/', $bridgeSource),
            'the bridge no longer builds its name by prefix + sanitize(server) — the convention the page quotes is this concatenation',
        );
        self::assertStringContainsString(". '__'", $bridgeSource, 'the double-underscore separator between server and tool is gone');

        self::assertSame(
            1,
            preg_match("/coincides with `Bash`'s in five of the six permission modes and diverges under `plan`/", $mcp),
            'the coincidence sentence no longer states five-of-six with its diverging mode',
        );
        $wordNumbers = ['five' => 5, 'six' => 6];
        $cases = PermissionMode::cases();
        self::assertCount($wordNumbers['six'], $cases, 'PermissionMode gained or lost a case — every mode matrix on the page and in the bridge doc-block must move together');

        $bridgeDoc = (string) (new \ReflectionClass('SugarCraft\Crush\Tools\McpToolBridge'))->getDocComment();
        preg_match_all('/^\s*\* {5}([a-z][a-z-]*) {2,}(\S+) {2,}(\S+)(.*)$/m', $bridgeDoc, $rows, PREG_SET_ORDER);
        $values = array_map(static fn ($case): string => $case->value, $cases);
        $byMode = [];
        foreach ($rows as $row) {
            if (in_array($row[1], $values, true)) {
                $byMode[$row[1]] = [$row[2], $row[3], trim($row[4])];
            }
        }
        self::assertCount(6, $byMode, 'the bridge doc-block matrix no longer carries one row per permission mode (its header row never counts)');
        self::assertEqualsCanonicalizing($values, array_keys($byMode), 'the matrix rows and PermissionMode::cases() values disagree — this is the same enum-equality gate the permission page carries, now also under the bridge note');
        $divergent = array_values(array_filter($byMode, static fn (array $row): bool => str_contains($row[2], 'diverges')));
        self::assertCount(1, $divergent, 'exactly one row may diverge — the page says five of six coincide');
        self::assertSame('plan', array_key_first(array_filter($byMode, static fn (array $row): bool => str_contains($row[2], 'diverges'))), 'the divergence moved off plan — MCP.md names plan as the diverging mode');
        self::assertSame(['DENIED', 'ALLOWED'], array_slice($byMode['plan'], 0, 2), 'plan no longer denies the mcp__ name while allowing Bash — the conservative-direction paragraph depends on this shape');
        $coincident = 0;
        foreach ($byMode as $row) {
            if ($row[0] === $row[1]) {
                ++$coincident;
            }
        }
        self::assertSame($wordNumbers['five'], $coincident, 'the column count of coinciding verdicts stopped matching the page and the bridge note ("Five of six coincide.")');
        self::assertStringContainsString('Five of six coincide.', self::proseOf($bridgeDoc), 'the bridge note\'s own count sentence drifted from its table — flip note and page together');

        $gate = self::bodyExcerpt(self::sourceOf('Permissions/PermissionGate.php'), 'isWriteTool');
        self::assertStringContainsString("'mcp__'", $gate, 'isWriteTool() no longer treats mcp__ names as writes — the plan-row divergence and the page sentence both run through this clause');
        self::assertStringContainsString('str_starts_with($call->name', $gate, 'isWriteTool() stopped prefix-matching the tool name');
    }

    /**
     * E686 tranche-9 (AZ): the commands surface — /mcp's three sub-commands
     * against the command's own match, the five help-listed subcommands
     * against ParsedArgs::SUBCOMMANDS (the second-class-in-file ParsedArgs is
     * touched through ArgvParser, per the lane-be autoload law), the server
     * halves the page enumerates, the no-`serve` negative, and `run` as the
     * sixth word argv treats specially (with the line-number anchor the page
     * carried until this tranche deleted).
     */
    public function testMcpCommandsSurfaceCountsHelpRowsAndTheRunArm(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        $mcp = self::markdownProse($raw);
        $wordNumbers = ['three' => 3, 'five' => 5, 'six' => 6];

        self::assertSame(
            1,
            preg_match('/with three\s+sub-commands, backed by `McpAuthStore` and `OAuthClientRegistration`/', $mcp),
            'the /mcp lead-in no longer states its count with both backing classes',
        );
        $authSource = self::sourceOf('Commands/McpAuthCommand.php');
        $execute = self::bodyExcerpt($authSource, 'execute', 1200);
        preg_match_all("/'(list|add|remove)' =>/", $execute, $subs);
        self::assertCount($wordNumbers['three'], $subs[1], 'the /mcp match arms changed — the page fence and this count move together');
        preg_match_all('/^\/mcp (list|add|remove)\b/m', $raw, $fence);
        self::assertSame(['list', 'add', 'remove'], $fence[1], 'the page fence no longer lists the three commands in code order');
        self::assertStringContainsString('Use: list, add, remove', $authSource, 'the unknown-sub-command message stopped enumerating the roster');
        self::assertTrue(class_exists('SugarCraft\Crush\MCP\McpAuthStore'), 'McpAuthStore vanished — the page credits it as backing');
        self::assertTrue(class_exists('SugarCraft\Crush\MCP\OAuthClientRegistration'), 'OAuthClientRegistration vanished — the page credits it as backing');

        $bold = \chr(42) . \chr(42);
        self::assertTrue(class_exists(ArgvParser::class), 'ArgvParser vanished — ParsedArgs has no loader without it');
        $commands = constant('SugarCraft\Crush\Cli\ParsedArgs::SUBCOMMANDS');
        sort($commands);
        self::assertCount($wordNumbers['five'], $commands, 'the subcommand roster changed size — help block, page sentence and this count move together');

        $helpSource = self::sourceOf('Cli/Help.php');
        self::assertSame(
            1,
            preg_match('/lists exactly five under its ' . \preg_quote($bold . 'Subcommands' . $bold, '/') . ' heading/', $mcp),
            'the exactly-five sentence reworded — the help block below must keep carrying five rows',
        );
        $block = (string) preg_replace('/^.*Subcommands \(/s', '', $helpSource);
        $block = explode("\n\n", $block)[0];
        preg_match_all('/^  ([a-z]+)\b/m', $block, $helpRows);
        $helpWords = array_values(array_unique($helpRows[1]));
        sort($helpWords);
        self::assertSame($commands, $helpWords, 'the help Subcommands block and ParsedArgs::SUBCOMMANDS diverged — the page quotes both agreeing');
        self::assertSame(6, preg_match_all('/^  (doctor|models|session|mcp|completion)\b/m', $block), 'the block no longer carries the six leaf rows the page enumerates (session and completion hold their own second words)');
        foreach (['doctor', 'models', 'session list', 'session delete', 'mcp list', 'completion bash|zsh|fish'] as $row) {
            self::assertStringContainsString('  ' . $row, $block, "the help block lost the `{$row}` row the page's list quotes");
        }
        self::assertStringContainsString('each answers and exits; none of them opens the TUI or needs a provider, an API key or a terminal', self::markdownProse($block), 'the help heading lost the promise the page repeats verbatim ("answer and exit without a provider, an API key or a terminal")');

        self::assertFalse(in_array('serve', $commands, true), 'a serve subcommand arrived — the page states there is none');
        self::assertStringContainsString('There is no `sugarcrush serve` subcommand', $mcp, 'the no-serve claim left the page');
        foreach (['McpServer', 'GitMcpServer', 'GitCommandHandlers', 'HttpMcpServer', 'StdioMcpServer'] as $class) {
            $half = 'SugarCraft\Crush\MCP\\' . $class;
            self::assertTrue(interface_exists($half) || class_exists($half), "the page enumerates {$class} among the server halves and it is gone");
        }

        self::assertSame(
            1,
            preg_match('/`run` is a sixth \(the `\$arg === .run.` arm in `Cli\\\\ArgvParser`/', $mcp),
            'the run-is-a-sixth sentence no longer cites the argv arm by symbol — the drifted line-number anchor it used to carry is the reason this pin exists',
        );
        self::assertSame(0, preg_match('/ArgvParser` line \d+/', $mcp), 'a bare line-number anchor came back into the page — E686 law: symbols by name, never by line');
        self::assertStringContainsString("\$arg === 'run' && !\$promptRequested", self::sourceOf('Cli/ArgvParser.php'), 'the bare-run arm the sentence cites is no longer shaped this way');
        self::assertFalse(in_array('run', $commands, true), 'run joined the subcommand roster — the five-vs-six split the page draws collapses');
        self::assertSame(
            1,
            preg_match('/sugarcrush run "<prompt>".*Alias for -p/s', $helpSource),
            'the Usage block stopped labelling run as an alias for -p',
        );
        self::assertStringContainsString('it is an alias for `-p`', $mcp, 'the alias half of the page sentence drifted from the help block');
        self::assertCount($wordNumbers['six'], array_merge($commands, ['run']), 'the five-plus-run-is-a-sixth arithmetic broke against the live roster');

        // E695 in-step: the auth section's new attachment truth, bound to the
        // code that makes it true — the page may only claim what the request
        // path actually does.
        self::assertSame(
            1,
            preg_match('/carries the stored\s+access token as a bearer `Authorization` header/', $mcp),
            'the page stopped stating that http requests carry the stored bearer token — E695 made the store read-bearing, keep prose and wiring moving together',
        );
        $httpSource = self::sourceOf('MCP/HttpMcpServer.php');
        self::assertStringContainsString('validAuthFor($this->url)', $httpSource, 'the request path no longer consults the store by server URL — the page exact-URL key claim is unfounded');
        self::assertTrue(
            method_exists(\SugarCraft\Crush\MCP\OAuthClientRegistration::class, 'validAuthFor'),
            'validAuthFor vanished — the page names getValidAuth as the refresh leg it rides',
        );
        self::assertStringContainsString(
            'getValidAuth(',
            self::sourceOf('MCP/OAuthClientRegistration.php'),
            'the store stopped delegating to getValidAuth — the page refresh-before-attach sentence drifts',
        );
        self::assertSame(
            1,
            preg_match('/wins — the store is then not\s+consulted/', $mcp),
            'the precedence sentence reworded — pin both doc and the case-insensitive guard beside it',
        );
        self::assertStringContainsString('strcasecmp((string) $headerName, \'Authorization\')', $httpSource, 'the static-header precedence guard changed shape — the page precedence sentence needs re-measuring');
    }

    /**
     * E686 tranche-9 (BA): the $onEvent roster of src/Backend.php. Four
     * sources of truth collapse to one set — the class doc-block's
     * {@see Events\...} cites, complete()'s literal `@param` union,
     * EngineBackend::encodeEvent()'s parameter union (which the page itself
     * names as the authority) and decodeEvent()'s return union — each short
     * name resolved through EngineBackend's use-imports and checked to exist.
     * encodeEvent admits the ToolStarted/ToolFinished pair AND SpendCapBreached,
     * so the FULL trio is bound here; the narrower tool-pair unions in
     * Runtime::emit()/Chat::enqueueToolEvent() are emission sites, not the
     * channel authority, and are deliberately NOT what this arm compares.
     */
    public function testOnEventRosterIsTheWireEncodersOwnUnion(): void
    {
        $backendSource = self::sourceOf('Backend.php');
        $engineSource = self::sourceOf('Backend/EngineBackend.php');
        $wordNumbers = ['three' => 3];

        self::assertSame(
            1,
            preg_match('/\*\*Turn-lifecycle events:\*\*/', self::markdownProse($backendSource)),
            'the class doc-block section the roster claim lives in was reworded — re-pin with it',
        );
        $section = (string) preg_replace('/^.*\*\*Turn-lifecycle events:\*\*/s', '', $backendSource);
        $section = explode(\chr(42) . \chr(42) . 'Reasoning:' . \chr(42) . \chr(42), $section)[0];
        preg_match_all('/\{\@see Events\\\\([A-Za-z]+)\}/', $section, $cited);
        self::assertCount(3, $cited[1], 'the doc-block section stopped naming exactly three event classes');
        self::assertSame(
            ['ToolStarted', 'ToolFinished', 'SpendCapBreached'],
            array_values(array_unique($cited[1])),
            'the prose trio in the class doc-block drifted — the encoder union below is the authority the page itself names',
        );
        self::assertStringContainsString('not a prose list, is the authority', self::markdownProse($section), 'the sentence naming the encoder as authority left — this arm exists to make it true');

        $param = (string) preg_replace('/^.*?\@param callable\|null \$onEvent/s', '', $backendSource);
        $param = (string) explode('public function complete(', $param)[0];
        self::assertSame(
            1,
            preg_match('/`function\(Events\\\\([A-Za-z]+)\|Events\\\\([A-Za-z]+)\|Events\\\\([A-Za-z]+) \$event\): void`/', $param, $union),
            'complete()\'s @param no longer states a three-class union inline — the page promise this arm guards is that union',
        );
        $documentedUnion = [$union[1], $union[2], $union[3]];
        self::assertStringContainsString('type-matches these three covers the channel', self::markdownProse($section), 'the "these three" count word left the section — flip it with the roster, census-trio law');
        self::assertCount($wordNumbers['three'], $documentedUnion);

        $encode = null;
        $decode = null;
        foreach (self::functionSpans($engineSource) as $span) {
            $encode ??= 'encodeEvent' === $span['name'] ? $span : null;
            $decode ??= 'decodeEvent' === $span['name'] ? $span : null;
        }
        self::assertIsArray($encode, 'EngineBackend::encodeEvent() vanished — the interface names it as the roster authority');
        self::assertIsArray($decode, 'EngineBackend::decodeEvent() vanished — the replay side of the same channel');
        $encodeText = (string) substr($engineSource, $encode['begin'], $encode['end'] - $encode['begin']);
        $decodeText = (string) substr($engineSource, $decode['begin'], $decode['end'] - $decode['begin']);
        self::assertSame(
            1,
            preg_match('/function encodeEvent\(([A-Za-z|]+) \$event\)/', $encodeText, $encodeUnion),
            'encodeEvent() no longer takes a bare class union — the authority stopped being a type',
        );
        $admitted = explode('|', $encodeUnion[1]);
        self::assertSame(
            1,
            preg_match('/function decodeEvent\([^)]*\): ([A-Za-z|]+)\|null/', $decodeText, $decodeUnion),
            'decodeEvent() no longer returns the union-or-null shape the interface documents',
        );
        $decoded = explode('|', $decodeUnion[1]);

        preg_match_all('/^use SugarCraft\\\\Crush\\\\Events\\\\([A-Za-z]+);$/m', $engineSource, $imports);
        foreach (array_merge($admitted, $decoded) as $short) {
            self::assertTrue(in_array($short, $imports[1], true), "encode/decode union names {$short} without a matching Events import — the resolution below would be a guess");
            self::assertTrue(class_exists('SugarCraft\Crush\Events\\' . $short), "SugarCraft\\Crush\\Events\\{$short} no longer exists — the wire channel's roster lost a member");
        }

        self::assertEqualsCanonicalizing($admitted, $decoded, 'the encoder admits one roster and the decoder replays another');
        self::assertEqualsCanonicalizing($admitted, $documentedUnion, 'complete()\'s @param union drifted from what encodeEvent() admits — the parameter type, not prose, is the authority');
        self::assertEqualsCanonicalizing($admitted, array_values(array_unique($cited[1])), 'the class doc-block cites stopped matching the encoder union — the page names encodeEvent() as authority');

        $observers = preg_match_all('/@param callable\|null \$onEvent [a-z]+ turn-lifecycle observer/i', $backendSource);
        self::assertSame(2, $observers, 'the two $onEvent @param lines no longer BOTH read turn-lifecycle — completeAsync carried the stale "tool-lifecycle" wording this tranche fixed IN-STEP');
        $async = (string) preg_replace('/^.*\@param callable\|null \$onEvent Optional turn-lifecycle/s', '', $backendSource);
        self::assertStringContainsString('{@see complete()}', $async, 'completeAsync() no longer defers its union to complete() — the single-source property this pin asserts');
    }

    /**
     * E686 tranche-10 (BB): MEMORY.md's index bounds and scope vocabulary. The
     * page went unguarded for nine tranches because its figures are private —
     * MAX_INDEX_LINES/MAX_INDEX_BYTES are private consts, the scope mapping is
     * the body of a private method, and the `local` sentence is an absence
     * claim. All three re-derive cleanly: the constants through reflection,
     * the mapping by invoking normalizeScope() itself, and the absence through
     * a comment-stripped literal scan over every src/ file.
     */
    public function testMemoryIndexBoundsAndScopeVocabularyNameTheStoreTheyDescribe(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));
        $store = new \ReflectionClass('SugarCraft\Crush\Memory\MemoryStore');

        self::assertSame(
            1,
            preg_match('/`MAX_INDEX_LINES = (\d+)` and `MAX_INDEX_BYTES = (\d+) \* (\d+)`/', $doc, $bounds),
            'MEMORY.md no longer states the index bounds pair in the sentence this arm pins',
        );
        self::assertSame(
            (int) $bounds[1],
            (int) $store->getReflectionConstant('MAX_INDEX_LINES')->getValue(),
            'MAX_INDEX_LINES drifted from the figure the page quotes without the page following',
        );
        self::assertSame(
            (int) $bounds[2] * (int) $bounds[3],
            (int) $store->getReflectionConstant('MAX_INDEX_BYTES')->getValue(),
            'MAX_INDEX_BYTES no longer equals the product the page itself spells out',
        );

        self::assertSame(
            1,
            preg_match('/enum.s cases are ((?:`\w+`, )+`\w+`) — but/', $doc, $casesCite),
            'the MemoryScope cases sentence left its pinned shape — re-point the arm, do not delete the claim',
        );
        preg_match_all('/`(\w+)`/', $casesCite[1], $documentedCases);
        self::assertSame(
            array_map(static fn (\UnitEnum $case): string => $case->name, MemoryScope::cases()),
            $documentedCases[1],
            'the documented case roster no longer matches MemoryScope::cases() in word or order',
        );

        $normalize = $store->getMethod('normalizeScope');
        $normalize->setAccessible(true);
        $bare = $store->newInstanceWithoutConstructor();
        $derivedDirs = [];
        foreach (MemoryScope::cases() as $case) {
            $derivedDirs[$case->name] = (string) $normalize->invoke($bare, $case);
        }

        self::assertSame(
            1,
            preg_match('/string-based caller says ((?:`\w+`, )+`\w+`), and/', $doc, $stringCallers),
            'the string-vocabulary half of the naming note is gone',
        );
        preg_match_all('/`(\w+)`/', $stringCallers[1], $documentedDirs);
        self::assertSame(
            array_values($derivedDirs),
            $documentedDirs[1],
            'the documented caller strings no longer match what normalizeScope() answers per case',
        );

        self::assertSame(
            1,
            preg_match('/store has (\w+) scopes — ((?:`\w+`(?:, | and )?)+)\./u', $doc, $tiers),
            'the three-tier sentence left its pinned shape',
        );
        $wordNumbers = ['three' => 3];
        self::assertArrayHasKey($tiers[1], $wordNumbers, 'the spelled scope count is now a word this arm cannot judge — re-read it into the map deliberately');
        preg_match_all('/`(\w+)`/', $tiers[2], $documentedTiers);
        self::assertCount($wordNumbers[$tiers[1]], $documentedTiers[1], 'the spelled scope count and the listed directory names no longer agree');
        self::assertEqualsCanonicalizing(
            array_values($derivedDirs),
            $documentedTiers[1],
            'the tier section lists directories normalizeScope() no longer answers',
        );

        self::assertSame(
            1,
            preg_match('/and `(\w+)` appears nowhere\s+else in the codebase/u', $doc, $absence),
            'the absence claim lost its sentence — the naming note needs re-arming, not silence',
        );
        $needle = $absence[1];
        $sites = [];
        foreach (self::srcTexts() as $relative => $text) {
            foreach (\PhpToken::tokenize($text) as $token) {
                if ($token->is(T_COMMENT) || $token->is(T_DOC_COMMENT)) {
                    continue;
                }
                if ($token->is(T_CONSTANT_ENCAPSED_STRING) && trim($token->text, "'\"") === $needle) {
                    $sites[$relative] = true;
                }
            }
        }
        self::assertSame(
            ['src/Agents/MemoryScope.php'],
            array_keys($sites),
            sprintf('the page claims `%s` appears nowhere else, yet a live string literal carries it elsewhere in src/', $needle),
        );
        self::assertSame($needle, MemoryScope::Local->value, 'MemoryScope::Local no longer backs onto the string the absence claim quotes');
    }

    /**
     * E686 tranche-10 (BC): MEMORY.md's entry vocabulary and folding policy —
     * the scope union signature, the no-scope pair, the four entry types, the
     * `type: pattern` no-tags add shape, the `/memory add` default, and the
     * project-only fold are each re-derived from reflection or the call sites
     * themselves, and the two wiring tests the page cites must still exist.
     */
    public function testMemoryPromptFoldingPolicyReadsTheCallShapesItStates(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));
        $store = new \ReflectionClass('SugarCraft\Crush\Memory\MemoryStore');

        self::assertSame(
            1,
            preg_match('/takes a scope accepts `(\w+)\|(\w+)`/', $doc, $union),
            'the union-signature sentence is gone from the naming note',
        );
        $enumClass = 'SugarCraft\Crush\Agents\\' . $union[2];
        self::assertTrue(class_exists($enumClass), sprintf('the quoted union names %s, which no longer resolves under SugarCraft\Crush\Agents — the cite rotted, not (just) the doc', $union[2]));
        $scopeMethods = [];
        foreach ($store->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getParameters() as $parameter) {
                if ($parameter->getName() !== 'scope') {
                    continue;
                }
                $scopeMethods[] = $method->getName();
                $declared = explode('|', (string) $parameter->getType());
                sort($declared);
                $quoted = [$union[1], $enumClass];
                sort($quoted);
                self::assertSame(
                    $quoted,
                    $declared,
                    sprintf('%s() no longer takes the union the page quotes', $method->getName()),
                );
            }
        }
        self::assertNotEmpty($scopeMethods, 'no public MemoryStore method takes a scope parameter at all — the naming note became fiction');

        self::assertSame(
            1,
            preg_match('/`(\w+)\(\)` and `(\w+)\(\)` take no scope at all/u', $doc, $noScope),
            'the no-scope pair sentence is gone',
        );
        foreach ([$noScope[1], $noScope[2]] as $method) {
            foreach ($store->getMethod($method)->getParameters() as $parameter) {
                self::assertNotSame('scope', $parameter->getName(), sprintf('the page says %s() takes no scope, yet it grew a scope parameter', $method));
            }
        }

        self::assertSame(
            1,
            preg_match('/supports ((?:`\w+`(?:, | and )?)+), but/u', $doc, $typesCite),
            'the MemoryEntry type-roster sentence left its pinned shape',
        );
        preg_match_all('/`(\w+)`/', $typesCite[1], $documentedTypes);
        $entryDoc = (string) (new \ReflectionClass('SugarCraft\Crush\Memory\MemoryEntry'))->getConstructor()->getDocComment();
        self::assertSame(
            1,
            preg_match("/Entry type: ('(?:\w+)',(?: '(?:\w+)',?)*(?: or '(?:\w+)')?)/", $entryDoc, $rosterLine),
            'MemoryEntry no longer lists its four types in the @param the page derives from',
        );
        preg_match_all("/'(\w+)'/", $rosterLine[1], $derivedTypes);
        self::assertSame($derivedTypes[1], $documentedTypes[1], 'the page roster and MemoryEntry\'s @param roster no longer match in word or order');

        self::assertSame(
            1,
            preg_match('/creates the entry with `type: (\w+)`/u', $doc, $addShape),
            'the add-shape sentence is gone',
        );
        self::assertSame(
            $derivedTypes[1][0],
            $addShape[1],
            'the page says the chat command writes only the FIRST roster type, yet the quoted type is no longer first',
        );
        $addBody = self::bodyExcerpt(self::sourceOf('Memory/MemoryStore.php'), 'add');
        self::assertStringContainsString("type: '" . $addShape[1] . "'", $addBody, 'MemoryStore::add() no longer stamps the type the page quotes');

        $chatAdd = self::bodyExcerpt(self::sourceOf('Chat.php'), 'memoryAdd');
        self::assertStringContainsString('->add($content, $scope)', $chatAdd, 'the chat command no longer calls add() in the two-argument no-tags shape the page states');
        self::assertStringNotContainsString('->add($content, $scope,', $chatAdd, 'the chat command started passing tags — the "and no tags" sentence needs rewriting in the same change');

        self::assertSame(
            1,
            preg_match('/`\/memory add` defaults to `(\w+)`/u', $doc, $default),
            'the add-default sentence is gone',
        );
        $defaultScope = null;
        foreach ($store->getMethod('add')->getParameters() as $parameter) {
            if ($parameter->getName() === 'scope') {
                $defaultScope = $parameter->getDefaultValue();
            }
        }
        self::assertSame($default[1], $defaultScope, '/memory add no longer defaults to the scope the page names');

        self::assertSame(
            1,
            preg_match('/\*\*`(\w+)` is the only scope that reaches the prompt\.\*\*/u', $doc, $onlyScope),
            'the bolded one-prompt-tier policy sentence is gone',
        );
        $foldCase = null;
        foreach (MemoryScope::cases() as $case) {
            if ($case->value === $onlyScope[1]) {
                $foldCase = $case->name;
            }
        }
        self::assertNotNull($foldCase, sprintf('the only prompt scope the page names (%s) is no longer an enum value', $onlyScope[1]));
        $captureBody = self::bodyExcerpt(self::sourceOf('Context/MemoryBlock.php'), 'capture');
        self::assertStringContainsString('list(MemoryScope::' . $foldCase . ')', $captureBody, 'capture() no longer reads the scope list the policy sentence names');
        // E25 piece 2 grew capture() a SECOND read of the same scope (the
        // repo-local store alongside the home one), so the census is of
        // DISTINCT scopes, not of call shapes — "only one prompt tier" stays
        // the policy the page states, and any foreign scope joining the fold
        // still reddens here.
        preg_match_all('/MemoryScope::(\w+)/', $captureBody, $foldScopes);
        self::assertSame(
            [$foldCase],
            array_values(array_unique($foldScopes[1])),
            'capture() reads more than one scope — the page\'s "only one prompt tier" is now a fold policy and its prose must say so',
        );

        self::assertSame(
            1,
            preg_match('/`Runtime::(\w+)\(\)` folds in a `MemoryBlock`/u', $doc, $fold),
            'the folding sentence left its pinned shape',
        );
        $runtimeText = self::sourceOf('Runtime.php');
        self::assertSame(
            1,
            preg_match('/assemblePrompt\(\$this->(\w+)\(/', self::bodyExcerpt($runtimeText, $fold[1]), $hop),
            sprintf('%s() no longer assembles through a single sections generator — the page attributes the fold to it by name', $fold[1]),
        );
        $runtimeSpans = [];
        foreach (self::functionSpans($runtimeText) as $runtimeSpan) {
            $runtimeSpans[$runtimeSpan['name']] = $runtimeSpan;
        }
        self::assertArrayHasKey($hop[1], $runtimeSpans, 'the sections generator the prompt assembly delegates to no longer exists on Runtime');
        $sectionsText = (string) substr($runtimeText, $runtimeSpans[$hop[1]]['begin'], $runtimeSpans[$hop[1]]['end'] - $runtimeSpans[$hop[1]]['begin']);
        self::assertStringContainsString('memorySnapshot(', $sectionsText, 'the sections generator no longer folds the memoized snapshot — the attribution of the fold to the named entry point runs through exactly this hop');
        $onceNumbers = ['once' => 1];
        self::assertSame(
            1,
            preg_match('/captured (\w+) per `Runtime` — not once per step/u', $doc, $memo),
            'the once-per-Runtime memo sentence left its shape — the negative half is contrast prose with no live counterpart (the memo census below IS its refutation), so it is pinned as sentence shape only',
        );
        self::assertArrayHasKey($memo[1], $onceNumbers, 'the memo count is now a word this arm cannot judge');
        self::assertSame($onceNumbers[$memo[1]], substr_count($runtimeText, '$this->memoryBlock ??='), 'the memo count the page spells no longer matches the memoization sites in src');

        preg_match_all('/`(MemoryPromptWiringTest)::(\w+)`/u', $doc, $wiringCites, PREG_SET_ORDER);
        self::assertNotEmpty($wiringCites, 'the wiring-test cites vanished from the folding section');
        foreach ($wiringCites as $cite) {
            self::assertTrue(
                method_exists('SugarCraft\Crush\Tests\Integration\\' . $cite[1], $cite[2]),
                sprintf('%s::%s no longer exists — the page names a test that never runs', $cite[1], $cite[2]),
            );
        }
    }

    /**
     * E694 slice-A (AQ): MEMORY.md's store-ops sentences must keep reading the
     * Chat arms they state. Every referent is derived LIVE from the Chat.php
     * bodies — no hand-typed roster beside the prose (the gg2 MAJOR's failure
     * mode): precedence is the forRoot-before-home-get order inside
     * memoryLocate's span; delete/edit route THROUGH memoryLocate; list/search
     * consult the repo resolver and group under the shared banner builder;
     * clear keeps EXACTLY ONE store-mutating call (the home one) behind a
     * refusal that carries no '--force'-shaped escape — the r76 ruling's
     * "unconditional" in code shape.
     */
    public function testMemoryStoreOpsSentenceReadsTheChatArmsItStates(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));
        self::assertSame(
            1,
            preg_match('/Since r75 `\/memory delete` and `\/memory edit` claim the same precedence — an id resolves in the repo store first, so the entry the prompt shows is the entry the command removes/u', $doc),
            'the store-precedence sentence left MEMORY.md — this arm and the doc-debt sentence land or revert together',
        );
        self::assertSame(
            1,
            preg_match('/read both stores and group their rows under a banner naming the store each row lives in/u', $doc),
            'the grouped-listing sentence left its pinned shape',
        );
        self::assertSame(
            1,
            preg_match('/bulk clear remains a home-store command and REFUSES, touching nothing, while the repo store holds project notes/u', $doc),
            'the bulk-clear refusal sentence left its pinned shape',
        );

        $chatText = self::sourceOf('Chat.php');
        $locate = self::bodyExcerpt($chatText, 'memoryLocate');
        $repoAt = strpos($locate, 'forRoot');
        $homeAt = strpos($locate, '$this->memoryStore->get($id)');
        self::assertNotFalse($repoAt, 'memoryLocate no longer consults the repo resolver — the "resolves in the repo store first" clause lost its code home');
        self::assertNotFalse($homeAt, 'memoryLocate no longer reads the home store — the precedence sentence names a two-store walk');
        self::assertLessThan($homeAt, $repoAt, 'memoryLocate flipped to home-first — the page and the fold law both say repo-first');

        foreach (['memoryDelete', 'memoryEdit'] as $arm) {
            self::assertStringContainsString(
                'memoryLocate(',
                self::bodyExcerpt($chatText, $arm),
                sprintf('the page says the per-id commands claim the shared precedence, yet %s() stopped routing through memoryLocate', $arm),
            );
        }
        foreach (['memoryList', 'memorySearch'] as $arm) {
            $body = self::bodyExcerpt($chatText, $arm);
            self::assertStringContainsString('forRoot', $body, sprintf('the page says list and search read both stores, yet %s() stopped consulting the repo resolver', $arm));
            self::assertStringContainsString('memoryStoreBanner(', $body, sprintf('%s() no longer groups rows under the store banners the page names', $arm));
        }

        $clear = self::bodyExcerpt($chatText, 'memoryClear');
        self::assertStringContainsString('forRoot', $clear, 'memoryClear no longer probes the repo store — the refusal the page promises has no code home');
        self::assertStringContainsString('Not cleared', $clear, 'the refusal wording left the arm — the page still promises a loud, total refusal');
        self::assertSame(
            1,
            substr_count($clear, '->clear('),
            'memoryClear grew a second clear() call site — the page promises bulk clear touches ONLY the home store',
        );
        self::assertStringNotContainsString('--force', $clear, 'a force-shaped escape hatch appeared — the r76 ruling records the project-clear refusal as unconditional');
    }

    /**
     * E686 tranche-10 (BD): MEMORY.md's three-bounds table and the marker
     * arithmetic hanging off it. The rows are matched by the live class's own
     * public MAX_* names — no hand-typed roster — and the "exactly 512 bytes,
     * not 527" sentence is re-derived from MAX_ENTRY_BYTES plus the byte length
     * of the private TRUNCATION_MARKER.
     */
    public function testMemoryBoundsTableRowsAndMarkerArithmeticMatchTheConstants(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));
        $block = new \ReflectionClass(MemoryBlock::class);

        self::assertSame(
            1,
            preg_match('/(\w+) bounds, not (\w+) — all three are `public const`/u', $doc, $countClaim),
            'the bounds preamble lost its count claim',
        );
        $wordNumbers = ['three' => 3, 'two' => 2];
        $boundsWord = strtolower($countClaim[1]);
        $counterWord = strtolower($countClaim[2]);
        self::assertArrayHasKey($boundsWord, $wordNumbers, 'the spelled bounds count is now a word this arm cannot judge — re-read it deliberately');
        self::assertArrayHasKey($counterWord, $wordNumbers, 'the spelled counter-claim ("not two") is now a word this arm cannot judge');
        $publicBounds = [];
        $privateBounds = [];
        foreach ($block->getReflectionConstants() as $constant) {
            if (!str_starts_with($constant->getName(), 'MAX_')) {
                continue;
            }
            if ($constant->isPublic()) {
                $publicBounds[$constant->getName()] = (int) $constant->getValue();
            } else {
                $privateBounds[] = $constant->getName();
            }
        }
        self::assertSame($wordNumbers[$boundsWord], count($publicBounds), sprintf('the page counts its bounds in words (%s), yet MemoryBlock now exposes %d public MAX_* constants', $countClaim[1], count($publicBounds)));
        self::assertNotSame($wordNumbers[$boundsWord], $wordNumbers[$counterWord], 'the preamble\'s two spelled counts collapsed onto each other');
        self::assertSame([], $privateBounds, 'a MAX_* bound went private — the page\'s "all three are public const" sentence is now false');

        $marker = (string) $block->getReflectionConstant('TRUNCATION_MARKER')->getValue();
        $rowValues = [];
        foreach (array_keys($publicBounds) as $name) {
            self::assertSame(
                1,
                preg_match('/`' . $name . '` \| (\d+) \|/u', $doc, $row),
                sprintf('the bounds table no longer carries the %s row this family pins', $name),
            );
            $rowValues[$name] = (int) $row[1];
            self::assertSame($publicBounds[$name], $rowValues[$name], sprintf('%s\'s table figure drifted from its public const', $name));
        }
        self::assertSame(1, substr_count($doc, '`' . $marker . '`'), sprintf('the visible %s marker is not quoted in the table exactly once (the table row carries it with its leading space)', var_export($marker, true)));
        self::assertSame(
            1,
            preg_match('/ends `XXXXX (\[…truncated\])`,? not/u', $doc, $ends),
            'the measured ends-with sentence is gone — the 512-vs-527 relation below needs a new home',
        );
        self::assertSame($marker, ' ' . $ends[1], 'the quoted marker no longer equals the byte-exact private const, leading space included');

        $entryName = null;
        $totalName = null;
        foreach (array_keys($publicBounds) as $name) {
            if (str_contains($name, '_ENTRY_BYTES')) {
                $entryName = $name;
            }
            if ($name === 'MAX_BYTES' || str_ends_with($name, '_BYTES')) {
                $totalName ??= $name;
            }
        }
        self::assertSame(
            1,
            preg_match('/`(\w+) <= (\w+)`/u', $doc, $relation),
            'the <= relation between the per-entry and total bounds left the prose',
        );
        self::assertArrayHasKey($relation[1], $rowValues, sprintf('the %s side of the quoted <= relation names %s, which is no longer one of this class\'s public bounds', $entryName, $relation[1]));
        self::assertArrayHasKey($relation[2], $rowValues, sprintf('the %s side of the quoted <= relation names %s, which is no longer one of this class\'s public bounds', $totalName, $relation[2]));
        self::assertLessThanOrEqual($rowValues[$totalName], $rowValues[$entryName], sprintf('%s <= %s stopped holding — the total bound is no longer a real ceiling with no first-entry exemption', $entryName, $totalName));

        self::assertSame(
            1,
            preg_match('/"(\d+), or one note, whichever is larger"/u', $doc, $fallback),
            'the quoted fallback-ceiling sentence is gone',
        );
        self::assertSame($rowValues[$totalName], (int) $fallback[1], 'the quoted fallback ceiling no longer names the total bound\'s value');

        self::assertSame(
            1,
            preg_match('/\*\*exactly (\d+) bytes\*\*/u', $doc, $cap),
            'the bolded exact-cap figure is gone from the marker paragraph',
        );
        self::assertSame($rowValues[$entryName], (int) $cap[1], 'the bolded cap no longer equals the per-entry bound');
        self::assertSame(
            1,
            preg_match('/not at the (\d+) it would be if the marker were added on top of the ceiling/u', $doc, $sum),
            'the 527-style counterfactual sentence is gone',
        );
        self::assertSame((int) $cap[1] + strlen($marker), (int) $sum[1], 'the stated counterfactual no longer equals cap + marker bytes — the paragraph\'s arithmetic is false');
        self::assertNotSame((int) $cap[1], (int) $sum[1], 'the paragraph now claims cap and cap+marker are the same number');
    }

    /**
     * E686 tranche-10 (BE): the `<project-memory>` fence section. The doc calls
     * PromptFence the single authority for the tag roster and makes three
     * behavioural promises about escape() — recognised tags rewrite only their
     * leading `<`, clean bodies pass through byte-for-byte, the function is
     * idempotent — all of which are replayed against the live class, while the
     * order-before-clip claim reads renderEntry()'s own nesting.
     */
    public function testProjectMemoryFenceRosterAndEscapePromiseHoldAgainstTheAuthority(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));

        self::assertSame(
            1,
            preg_match('/roster — ((?:`[\w-]+`, )+`[\w-]+`) — and/u', $doc, $rosterCite),
            'the fence-tag roster sentence left its pinned shape',
        );
        preg_match_all('/`([\w-]+)`/', $rosterCite[1], $documentedTags);
        $tags = (array) (new \ReflectionClass(PromptFence::class))->getReflectionConstant('TAGS')->getValue();
        self::assertSame($tags, $documentedTags[1], 'the documented roster no longer matches PromptFence::TAGS in word or order — the page names PromptFence as the single authority');

        self::assertSame(
            1,
            preg_match('/### The `(<[\w-]+>)` fence/u', $doc, $heading),
            'the fence section heading stopped naming the tag it pins',
        );
        $blockText = self::sourceOf('Context/MemoryBlock.php');
        $fenceBody = self::bodyExcerpt($blockText, 'fence');
        self::assertSame(1, preg_match("/return '([^']+)'/", $fenceBody, $ret), 'MemoryBlock::fence() no longer answers a bare literal return');
        self::assertSame($heading[1], $ret[1], 'fence() answers a different tag than the section heading quotes');

        self::assertSame(
            1,
            preg_match('/leading `(<)` of a recognised open\/close tag to `([^`]+)`/u', $doc, $rewrite),
            'the rewrite promise lost its literal pair',
        );
        foreach ($tags as $tag) {
            self::assertSame($rewrite[2] . $tag . '>', PromptFence::escape($rewrite[1] . $tag . '>'), sprintf('an open %s<%s> no longer escapes to the documented form', $rewrite[1], $tag));
            self::assertSame($rewrite[2] . '/' . $tag . '>', PromptFence::escape($rewrite[1] . '/' . $tag . '>'), sprintf('a close %s</%s> no longer escapes to the documented form', $rewrite[1], $tag));
        }
        self::assertSame('plain note body where 3 < 4 holds', PromptFence::escape('plain note body where 3 < 4 holds'), 'a bare < inside a clean body is being rewritten — "touches nothing else" broke');

        $fenceTag = trim($heading[1], '<>');
        $forged = $rewrite[1] . '/' . $fenceTag . '>';
        self::assertSame(
            1,
            preg_match('/arrives as `([^`]+)` and cannot close/u', $doc, $arrival),
            'the forging sentence lost its arrival literal',
        );
        self::assertSame($arrival[1], PromptFence::escape($forged), 'a note forging its fence no longer arrives as the doc promises');
        self::assertSame(PromptFence::escape($forged), PromptFence::escape(PromptFence::escape($forged)), 'escape() stopped being idempotent — the page promises it plainly');

        self::assertSame(
            1,
            preg_match('/once each `<` costs (\w+) bytes/u', $doc, $cost),
            'the four-byte cost claim is gone from the escape-before-clip paragraph',
        );
        $costNumbers = ['four' => 4];
        self::assertArrayHasKey($cost[1], $costNumbers, 'the byte cost is now a word this arm cannot judge');
        self::assertSame($costNumbers[$cost[1]], strlen($rewrite[2]), 'the rewrite target no longer costs the bytes the page states per <');

        self::assertSame(1, preg_match('/runs \*before\* the clip/u', $doc), 'the order promise left the prose');
        $renderEntry = self::bodyExcerpt($blockText, 'renderEntry');
        self::assertSame(1, preg_match('/clip\(PromptFence::escape\(/', $renderEntry), 'renderEntry() no longer nests escape INSIDE the clip — the escape-before-clip sentence must be rewritten in the same change');

        preg_match_all('/`(MemoryBlockTest)::(\w+)`/u', $doc, $fenceCites, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(3, count($fenceCites), 'the fence section lost cites — the escape promises now stand untested-by-name');
        foreach ($fenceCites as $cite) {
            self::assertTrue(
                method_exists('SugarCraft\Crush\Tests\Context\\' . $cite[1], $cite[2]),
                sprintf('%s::%s no longer exists — the page names a test that never runs', $cite[1], $cite[2]),
            );
        }
    }

    /**
     * E686 tranche-10 (BF): the foreign-import section. The `source:` tag, the
     * Local-scope-only write policy, the landing directory, the Chat sentinel
     * literal and the per-call UUID minting all re-derive from the importer,
     * the enum, the normalizer and the sentinel call site itself.
     */
    public function testForeignImportTagScopeAndSentinelNameTheLiveCode(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));

        self::assertSame(
            1,
            preg_match('/tagged `(\w+:)<skill-source>` — the same `(\w+)` vocabulary/u', $doc, $tag),
            'the tagging sentence left its pinned shape',
        );
        self::assertTrue(class_exists('SugarCraft\Crush\Skills\\' . $tag[2]), sprintf('%s no longer resolves — the vocabulary the page leans on moved', $tag[2]));
        $importer = self::sourceOf('Memory/ForeignMemoryImporter.php');
        self::assertStringContainsString("'" . $tag[1] . "' . \$source->value", $importer, sprintf('the importer no longer stamps the %s prefix the page quotes', $tag[1]));

        self::assertSame(
            1,
            preg_match('/writes every entry with `MemoryScope::(\w+)`, which `MemoryStore::normalizeScope\(\)` lands in the `(\w+)` directory/u', $doc, $scope),
            'the scope-landing sentence left its pinned shape',
        );
        $scopeWrites = preg_match_all('/scope: MemoryScope::(\w+)/', $importer, $written);
        self::assertGreaterThan(0, $scopeWrites, 'the importer no longer spells its scope at the construction sites the page describes');
        self::assertSame([$scope[1]], array_values(array_unique($written[1])), 'the importer writes more than one scope — "every entry" needs rewriting alongside it');
        $scopeCase = null;
        foreach (MemoryScope::cases() as $case) {
            if ($case->name === $scope[1]) {
                $scopeCase = $case;
            }
        }
        self::assertNotNull($scopeCase, sprintf('MemoryScope::%s, spelled by the page, is no longer an enum case', $scope[1]));
        $store = new \ReflectionClass('SugarCraft\Crush\Memory\MemoryStore');
        $normalize = $store->getMethod('normalizeScope');
        $normalize->setAccessible(true);
        self::assertSame(
            $scope[2],
            (string) $normalize->invoke($store->newInstanceWithoutConstructor(), $scopeCase),
            sprintf('normalizeScope() no longer lands %s in the `%s` directory the page states', $scope[1], $scope[2]),
        );

        self::assertSame(
            1,
            preg_match('/`\/memory list (\w+)` and `\/memory \w+`/u', $doc, $listable),
            'the list-and-search sentence is gone',
        );
        self::assertSame($scope[2], $listable[1], 'the directory the imports land in and the directory the page says /memory list can read have come apart');

        self::assertSame(
            1,
            preg_match('/mints a fresh (\w+) per call/u', $doc, $uuid),
            'the non-idempotence explanation is gone',
        );
        self::assertStringContainsString('generate' . ucfirst(strtolower($uuid[1])) . '(', self::bodyExcerpt(self::sourceOf('Memory/MemoryStore.php'), 'add'), 'MemoryStore::add() no longer mints per-call identifiers — the "imports are not idempotent" paragraph needs re-reading');

        self::assertSame(
            1,
            preg_match('/sentinel at `([^`]+)<target>` in the project/u', $doc, $sentinel),
            'the sentinel-path sentence left its pinned shape',
        );
        self::assertStringContainsString("'/" . $sentinel[1] . "'", self::sourceOf('Chat.php'), 'the sentinel path the page quotes no longer matches the literal at the Chat trigger site');

        preg_match_all('/`(MemoryImportCommandTest)::(\w+)`/u', $doc, $importCites, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(2, count($importCites), 'the import section lost its named-test cites');
        foreach ($importCites as $cite) {
            self::assertTrue(
                method_exists('SugarCraft\Crush\Tests\Commands\\' . $cite[1], $cite[2]),
                sprintf('%s::%s no longer exists — the page names a test that never runs', $cite[1], $cite[2]),
            );
        }
    }

    /**
     * E686 tranche-10 (BG): the containment section's counted claim. The page
     * spells SIX ContainedPath call sites "one per read decision" and names
     * five methods; the number is pinned three ways — the doc word, the token
     * census with comments stripped, and ContainedPathInventoryTest's public
     * roster row — and every census site must fall inside a method the sentence
     * names. The shared-set paragraph re-counts the four routes it lists.
     */
    public function testContainmentSitesAndSharedEmittedSetCountWhatThePageClaims(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));

        self::assertSame(
            1,
            preg_match('/through `ContainedPath` — (\w+) call sites, one per read decision: (.*?)\. The gate closure/u', $doc, $contain),
            'the containment count sentence left its pinned shape',
        );
        $wordNumbers = ['six' => 6, 'five' => 5, 'four' => 4];
        self::assertArrayHasKey($contain[1], $wordNumbers, 'the spelled call-site count is now a word this arm cannot judge — re-read it deliberately');
        preg_match_all('/`(\w+)\(\)`\'\w+/u', $contain[2], $named);
        self::assertNotEmpty($named[1], 'the containment sentence stopped naming its read decisions');
        self::assertSame(5, count($named[1]), 'the sentence names a different number of methods than the five decisions this arm walks — re-read the sentence, do not bend the walk');

        $loaderText = self::sourceOf('Context/InstructionFileLoader.php');
        self::assertTrue(class_exists('SugarCraft\Crush\Context\InstructionFileLoader'));
        $tokens = \PhpToken::tokenize($loaderText);
        $significant = [];
        foreach ($tokens as $index => $token) {
            if ($token->is([T_COMMENT, T_DOC_COMMENT, T_WHITESPACE])) {
                continue;
            }
            $significant[] = ['token' => $token, 'index' => $index];
        }
        $sites = [];
        $count = count($significant);
        for ($i = 1; $i < $count - 2; $i++) {
            if (
                $significant[$i]['token']->is(T_STRING)
                && $significant[$i]['token']->text === 'within'
                && $i >= 2
                && $significant[$i - 1]['token']->is(T_DOUBLE_COLON)
                && $significant[$i - 2]['token']->is(T_STRING)
                && $significant[$i - 2]['token']->text === 'ContainedPath'
                && $significant[$i + 1]['token']->text === '('
            ) {
                $sites[] = $significant[$i]['token']->pos;
            }
        }
        $roster = (array) (new \ReflectionClass('SugarCraft\Crush\Tests\Support\ContainedPathInventoryTest'))->getConstant('ROUTED_CALL_SITES');
        self::assertArrayHasKey('Context/InstructionFileLoader.php', $roster, 'the inventory roster lost this file\'s row — the sentence and the census need re-deriving together');
        self::assertSame($wordNumbers[$contain[1]], count($sites), sprintf('the page spells its containment count (%s), yet the comment-stripped census finds %d call sites', $contain[1], count($sites)));
        self::assertSame($wordNumbers[$contain[1]], $roster['Context/InstructionFileLoader.php'], 'the inventory roster row no longer agrees with the page\'s spelled count');

        $spans = self::functionSpans($loaderText);
        $byName = [];
        foreach ($spans as $span) {
            $byName[$span['name']] = $span;
        }
        $covered = 0;
        foreach ($named[1] as $method) {
            self::assertArrayHasKey($method, $byName, sprintf('the containment sentence names %s(), which no longer exists on the loader', $method));
            $inside = 0;
            foreach ($sites as $position) {
                if ($position >= $byName[$method]['begin'] && $position < $byName[$method]['end']) {
                    $inside++;
                }
            }
            self::assertGreaterThan(0, $inside, sprintf('%s() no longer performs a contained read the sentence credits it with', $method));
            $covered += $inside;
        }
        self::assertSame(count($sites), $covered, 'a containment call site now lives OUTSIDE every method the sentence names — the per-decision claim became false');

        self::assertSame(
            1,
            preg_match('/shared "already emitted" set covers all (\w+) routes — root, forced, `@import` inlining and on-touch/u', $doc, $routes),
            'the shared-set sentence left its pinned shape',
        );
        self::assertArrayHasKey($routes[1], $wordNumbers, 'the spelled route count is now a word this arm cannot judge');
        $routeMethods = 0;
        foreach (['loadRoot', 'loadForced', 'expandImports', 'loadForPath'] as $route) {
            self::assertArrayHasKey($route, $byName, sprintf('a route the shared-set sentence lists (%s) disappeared from the loader', $route));
            $slice = (string) substr($loaderText, $byName[$route]['begin'], $byName[$route]['end'] - $byName[$route]['begin']);
            if (str_contains($slice, 'emittedPaths')) {
                $routeMethods++;
            }
        }
        self::assertSame($wordNumbers[$routes[1]], $routeMethods, 'the routes touching the shared emitted-set no longer number what the sentence spells');

        $propertyDeclarations = 0;
        foreach ($significant as $j => $row) {
            if ($row['token']->is(T_VARIABLE) && $row['token']->text === '$emittedPaths' && $j > 0 && $significant[$j - 1]['token']->is(T_ARRAY)) {
                $propertyDeclarations++;
            }
        }
        self::assertSame(1, $propertyDeclarations, 'the "one loader ... share one dedup map" promise needs exactly one $emittedPaths property — a second appeared');

        self::assertSame(
            1,
            preg_match('/`(\w+)\(\)` is the pull-based seam/u', $doc, $seam),
            'the refusal-seam sentence is gone',
        );
        self::assertTrue(
            (new \ReflectionMethod('SugarCraft\Crush\Context\InstructionFileLoader', $seam[1]))->isPublic(),
            sprintf('%s() went non-public — the page still presents it as the pull-based seam', $seam[1]),
        );
    }

    /**
     * E686 tranche-10 (BH): the loader-threading and `@import` sections. The
     * tool list the page prints must equal, in order, the constructor calls
     * inside Bootstrap::unfilteredTools() that actually pass the named
     * argument; every name must still resolve; and the import resolver's depth
     * cap plus the two regex-shape claims (`.md`-only, code-span-skipped)
     * re-derive from the live pattern.
     */
    public function testLoaderThreadingNamesEveryToolThatReceivesTheLoader(): void
    {
        $doc = self::markdownProse((string) file_get_contents(dirname(__DIR__, 2) . '/docs/MEMORY.md'));

        self::assertSame(
            1,
            preg_match('/`Bootstrap::(\w+)\(\)` threads \*\*one\*\* loader into ((?:`\w+`(?:, | and )?)+) so/u', $doc, $thread),
            'the threading sentence left its pinned shape',
        );
        preg_match_all('/`(\w+)`/', $thread[2], $documentedTools);
        $bootstrap = self::sourceOf('Cli/Bootstrap.php');
        self::assertTrue(method_exists(Bootstrap::class, $thread[1]), sprintf('Bootstrap::%s() no longer exists — the sentence naming it as the threader is stale', $thread[1]));

        $threaderBody = self::bodyExcerpt($bootstrap, $thread[1], 6000);
        self::assertStringContainsString('self::unfilteredTools(', $threaderBody, sprintf('%s() no longer delegates to unfilteredTools() — the authority the wiring moved under needs re-reading', $thread[1]));

        $span = null;
        foreach (self::functionSpans($bootstrap) as $candidate) {
            if ($candidate['name'] === 'unfilteredTools') {
                $span = $candidate;
            }
        }
        self::assertNotNull($span, 'Bootstrap::unfilteredTools() no longer exists — this arm and the delegation above both lost their authority');
        $spanText = (string) substr($bootstrap, $span['begin'], $span['end'] - $span['begin']);
        $wiredCount = preg_match_all('/new (\w+)\((?:(?!new )[^;])*?instructionLoader: \$loader/', $spanText, $wired);
        self::assertGreaterThan(0, (int) $wiredCount, 'no constructor call in unfilteredTools passes the named loader argument anymore — the page\'s whole threading paragraph became fiction');
        self::assertSame($documentedTools[1], $wired[1], 'the documented tool list and the constructor calls that actually receive the loader no longer agree in membership or order');
        foreach ($wired[1] as $tool) {
            self::assertTrue(class_exists('SugarCraft\Crush\Tools\BuiltIn\\' . $tool), sprintf('%s, listed by the page and wired at Bootstrap, no longer resolves under Tools\BuiltIn', $tool));
        }
        self::assertSame(
            $wiredCount,
            substr_count($bootstrap, 'instructionLoader: $loader'),
            'a second site outside unfilteredTools started threading the loader — the single-thread claim this arm pins needs re-reading',
        );

        self::assertSame(
            1,
            preg_match('/depth is capped at (\d+)/u', $doc, $depth),
            'the depth-cap bullet is gone',
        );
        $resolver = self::sourceOf('Context/ImportResolver.php');
        self::assertSame(
            (int) $depth[1],
            (int) (new \ReflectionClass('SugarCraft\Crush\Context\ImportResolver'))->getReflectionConstant('MAX_DEPTH')->getValue(),
            'ImportResolver::MAX_DEPTH drifted from the digit the bullet states',
        );

        self::assertSame(
            1,
            preg_match('/Only `([^`]+)` targets are matched/u', $doc, $target),
            'the .md-only sentence is gone',
        );
        self::assertTrue(str_starts_with($target[1], '.'), 'the quoted target stopped being an extension — this arm derives the escaped-needle from a leading dot');
        self::assertStringContainsString('\\' . $target[1], $resolver, sprintf('the resolver pattern no longer anchors on the %s target the page states', $target[1]));
        self::assertStringContainsString('(?<!`', $resolver, 'the code-span skip vanished from the pattern — documenting the syntax would start triggering it');
        self::assertStringContainsString('(?!`', $resolver, 'the code-span skip vanished from the closing side — same hazard');
    }


    /**
     * E686 tranche-11 (AI): a bare line anchor is the one doc figure that rots
     * in total silence — no guard resolves it and every reader trusts it. The
     * five ARCHITECTURE anchors this tranche healed had drifted by up to a
     * thousand lines with the suite green, so from now on every
     * `File.php:NNN`, `(line NNN)`/`at line NNN`/`on line NNN` and backticked
     * `line NNN` anywhere in docs/ must resolve against real source (file
     * found, number inside the file, and — when the same markdown line cites
     * a `Class::method` — that method declared within forty lines of the
     * number) or sit in the named exemption roster, which is itself checked
     * both ways so a licensed self-narrative that moves reddens here.
     */
    public function testEveryBareLineAnchorInTheDocsResolvesToItsSource(): void
    {
        $root = \dirname(__DIR__, 2);
        $exemptions = [
            'HOOKS.md' => ['`line 181`'],
        ];
        $seenExemptions = [];

        $pages = array_values(array_filter(scandir($root . '/docs') ?: [], static fn(string $f): bool => str_ends_with($f, '.md')));
        self::assertCount(13, $pages, 'the docs page census moved — this anchor guard would silently stop covering a page');

        $patterns = [
            '/[A-Za-z0-9_\/\\\\.\-]+\.php:[0-9]+(?:-[0-9]+)?/',
            '/(?:\(|\bat |\bon )lines? [0-9]+(?:-[0-9]+)?/',
            '/`lines? [0-9]+(?:-[0-9]+)?`/',
        ];

        foreach ($pages as $page) {
            $text = (string) file_get_contents($root . '/docs/' . $page);
            self::assertNotEmpty($text, "docs/{$page} reads empty — the anchor census over it is void");
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $text, $hits, PREG_OFFSET_CAPTURE);
                foreach ($hits[0] as [$hit, $offset]) {
                    if (in_array($hit, $exemptions[$page] ?? [], true)) {
                        $seenExemptions[$page][] = $hit;
                        continue;
                    }
                    $line = substr_count(substr($text, 0, $offset), "\n");
                    $context = explode("\n", $text)[$line];
                    self::resolveBareAnchor($root, "docs/{$page}", $hit, $context, $line);
                }
            }
        }

        foreach ($exemptions as $page => $listed) {
            foreach ($listed as $entry) {
                self::assertContains($entry, $seenExemptions[$page] ?? [], "the anchor exemption roster still licenses {$entry} on {$page} — the self-narrative it describes is gone; delete the row");
            }
        }

        // r75-rv-ja MINOR-3: the forty-line method window was the one leg of
        // this guard no planted mismatch had ever proven. A synthetic file
        // (built here, committed nowhere) whose cited method is declared far
        // outside the window must be refused, and the SAME anchor re-pointed
        // inside it must resolve — the window discriminates, it does not wave
        // every in-file number through.
        $synthRoot = \sys_get_temp_dir() . '/ja2-anchors-' . \uniqid((string) \getmypid(), true);
        mkdir($synthRoot, 0700);
        file_put_contents(
            $synthRoot . '/Widget.php',
            "<?php\nclass Widget {\n" . implode('', array_fill(0, 57, "    // padding\n")) . "    public function late(): void\n    {\n    }\n}\n",
        );
        try {
            $windowRejection = null;
            try {
                self::resolveBareAnchor($synthRoot, 'synthetic', 'Widget.php:2', '`Widget::late()` at line 2', 1);
            } catch (\PHPUnit\Framework\ExpectationFailedException $failure) {
                $windowRejection = $failure->getMessage();
            }
            self::assertIsString($windowRejection, 'the resolver stopped rejecting an anchor whose cited method sits outside the forty-line window — the window leg went blind');
            self::assertStringContainsString('forty lines', (string) $windowRejection, 'the window rejection changed shape — update this planted leg along with it');
            self::resolveBareAnchor($synthRoot, 'synthetic', 'Widget.php:58', '`Widget::late()` at line 58', 1);
        } finally {
            unlink($synthRoot . '/Widget.php');
            rmdir($synthRoot);
        }
    }

    /**
     * Resolve one bare-anchor hit: name the file it points at (the path for a
     * `File.php:NNN` hit, the class-shaped backtick cite on the same markdown
     * line otherwise), require the number to sit inside that file, and when a
     * method is cited require its declaration within forty lines of the
     * number. Fail-closed with the page, the hit and the reason.
     */
    private static function resolveBareAnchor(string $root, string $page, string $hit, string $context, int $docLine): void
    {
        $numbers = [];
        preg_match_all('/([0-9]+)(?:-([0-9]+))?/', $hit, $numbers);
        $from = (int) ($numbers[1][count($numbers[1]) - 1] ?? 0);

        $relative = null;
        $method = null;
        if (preg_match('/([A-Za-z0-9_\/.\-]+)\.php:/', $hit, $path)) {
            $relative = $path[1] . '.php';
            if (!is_file($root . '/' . $relative)) {
                $relative = self::anchorFindFile($root, basename($relative), $page, $hit);
            }
        }
        preg_match('/`([A-Za-z0-9_\\\\]+)(?:::([a-zA-Z_][A-Za-z0-9_]*))?(?:\(\))?`/', $context, $cite);
        if ($relative === null && isset($cite[1]) && $cite[1] !== '') {
            $class = ltrim(str_replace('\\\\', '\\', $cite[1]), '\\');
            $short = substr(strrchr('\\' . $class, '\\'), 1);
            $relative = self::anchorFindFile($root, $short . '.php', $page, $hit);
            $method = $cite[2] ?? null;
        }
        if ($method === null && preg_match('/::([a-z][a-zA-Z0-9_]*)\(\)?`/', $context, $mx)) {
            $method = $mx[1];
        }

        self::assertNotNull($relative, "the bare anchor {$hit} on {$page} line " . ($docLine + 1) . ' names no file and no class-shaped cite around it — nothing can resolve it');

        $lines = file($root . '/' . $relative);
        self::assertIsArray($lines, "the bare anchor {$hit} points into {$relative}, which does not exist");
        self::assertGreaterThanOrEqual($from, count($lines), "the bare anchor {$hit} on {$page} points past the end of {$relative} (" . count($lines) . ' lines)');

        if ($method !== null) {
            $window = implode('', \array_slice($lines, max(0, $from - 1), 40));
            self::assertMatchesRegularExpression(
                '/function\s+' . preg_quote($method, '/') . '\s*\(/',
                $window,
                "the bare anchor {$hit} on {$page} claims {$method}() lives at line {$from} of {$relative} — no such declaration within forty lines"
            );
        }
    }

    private static function anchorFindFile(string $root, string $basename, string $page, string $hit): ?string
    {
        $found = [];
        foreach (array_keys(self::srcTexts()) as $relative) {
            if (basename($relative) === $basename) {
                $found[] = $relative;
            }
        }
        if (\count($found) === 1) {
            return $found[0];
        }
        self::fail("the bare anchor {$hit} on {$page} cannot resolve {$basename}: " . (\count($found) === 0 ? 'no such file under src/' : count($found) . ' same-named files — cite the path'));
    }

    /**
     * E686 tranche-11 (AJ): the Providers section's seven-name roster, its
     * type→Class table, the two "easy to get wrong" routing rows and the echo
     * degradation path are re-derived from ProviderFactory/Bootstrap sources —
     * the section this tranche found standing free of any guard while its
     * five numeric anchors rotted.
     */
    public function testProvidersSectionRosterDividesTheFactoryItDocuments(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/ARCHITECTURE.md');
        $start = strpos($raw, '## Providers');
        self::assertIsInt($start, 'the Providers heading moved — the seven-name table lost its home');
        $end = strpos($raw, '## ', $start + 5);
        self::assertIsInt($end, 'no heading follows Providers — the window this arm reads became the page tail');
        $window = substr($raw, $start, $end - $start);
        $segment = self::markdownProse($window);

        $factory = self::sourceOf('Providers/ProviderFactory.php');
        $typesWindow = self::bodyExcerpt($factory, 'availableTypes', 400);
        self::assertSame(1, preg_match("/return \[([^\]]*)\];/", $typesWindow, $lits), 'availableTypes() no longer returns one literal array — the documented roster lost its single source');
        preg_match_all("/'([a-z-]+)'/", $lits[1], $live);
        $liveTypes = $live[1];

        self::assertSame(1, preg_match('/returns \*\*(\w+)\*\* selectable names/', $segment, $word), 'the paragraph no longer spells its count beside "selectable names"');
        $wordNumbers = ['five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9];
        self::assertArrayHasKey($word[1], $wordNumbers, "the spelled count '{$word[1]}' is outside the pinned word map — extend it deliberately");
        self::assertCount($wordNumbers[$word[1]], $liveTypes, "the page still says the factory offers {$word[1]} selectable names — availableTypes() returns another count");

        preg_match_all('/^\| `([a-z-]+)` \| (.+?) \|\s*$/m', $window, $rows, PREG_SET_ORDER);
        self::assertNotEmpty($rows, 'the type→Class table is gone — the roster this arm derives from it has nothing left to read');
        $rows = array_values(array_filter($rows, static fn(array $r): bool => $r[1] !== 'type')); // the markdown header row cites `type` too
        $docTypes = array_map(static fn(array $r): string => $r[1], $rows);
        self::assertSame($liveTypes, $docTypes, 'the documented type column no longer matches availableTypes() in name or order — the page promises exactly what the factory returns');

        foreach ($rows as $row) {
            self::assertSame(
                1,
                preg_match('/`([A-Z][A-Za-z0-9]*)`/', $row[2], $cls),
                "the `{$row[1]}` row no longer names its built class in backticks — the table cell is the claim"
            );
            self::assertTrue(
                class_exists('SugarCraft\Crush\Providers\\' . $cls[1]),
                "the `{$row[1]}` row cites {$cls[1]} — no class by that name lives in Providers"
            );
        }

        self::assertMatchesRegularExpression('/\| `anthropic` \| \*\*`CustomProvider`\*\*, named `anthropic` \|/', $window, 'the anthropic row no longer states its CustomProvider-named-anthropic surprise — the section exists to keep this row from being re-simplified');
        $anthropic = self::bodyExcerpt($factory, 'createAnthropic', 3000);
        foreach (['x-api-key', 'anthropic-version', 'CustomProvider'] as $token) {
            self::assertStringContainsString($token, $anthropic, "createAnthropic() no longer touches {$token} — the paragraph flatly states this row builds that");
        }

        self::assertStringContainsString('a **separate, seventh** provider', $segment, 'the separate-seventh framing is gone — claude-code was repeatedly collapsed into anthropic');
        self::assertContains('claude-code', $liveTypes, 'the page still calls claude-code one of the seven — the factory no longer offers it');
        $claude = self::bodyExcerpt($factory, 'createClaudeCode', 1500);
        self::assertStringContainsString('new ClaudeCodeProvider', $claude, 'createClaudeCode() no longer returns the real ClaudeCodeProvider the row promises');
        self::assertStringContainsString('ClaudeCodeInvocation', $segment, 'the table no longer says the claude-code row rides over ClaudeCodeInvocation');
        self::assertTrue(class_exists('SugarCraft\Crush\Providers\ClaudeCodeInvocation'), 'ClaudeCodeInvocation, cited as the claude-code row carrier, is gone');

        self::assertStringContainsString('**`echo` is not one of the seven.**', $segment, 'the echo exclusion sentence moved — echo is a degradation path, never a selectable name');
        self::assertNotContains('echo', $liveTypes, 'echo joined availableTypes() — the exclusion this paragraph stakes out is no longer true');
        self::assertStringContainsString('Unknown provider type:', $factory, 'the factory no longer throws the named rejection the echo paragraph quotes');
        $provider = self::bodyExcerpt(self::sourceOf('Cli/Bootstrap.php'), 'provider', 6000);
        self::assertStringContainsString('new EchoProvider()', $provider, 'Bootstrap::provider() no longer hands out EchoProvider on the degradation path the paragraph describes');
    }

    /**
     * E686 tranche-11 (AK): the preset page's tool claims are re-read against
     * the built-in directory and the delegation code. The pre-E675 sentence
     * ("no `Task` ... eleven ... none of them delegates") survived a whole
     * campaign because the page was never parsed; this arm pins the flipped
     * truth AND the absence of the old refusal wording, so a revert that
     * re-imports stale prose from history reddens here.
     */
    public function testAgentsAuthoringToolClaimsSurviveTheBuiltInDirectory(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/AGENTS_AUTHORING.md');
        $start = strpos($raw, '## What you can actually do with a preset today');
        self::assertIsInt($start, 'the section heading moved — the Task bullet lost its home');
        $end = strpos($raw, "\n---", $start);
        self::assertIsInt($end, 'no rule closes the section — the bullet window this arm reads became the page tail');
        $segment = self::markdownProse(substr($raw, $start, $end - $start));

        self::assertSame(1, preg_match('/ships (\w+) built-in tools and one of them — `Task` — is exactly the delegation seam/', $segment, $word), 'the Task bullet no longer spells its built-in count beside the delegation sentence');
        $wordNumbers = ['ten' => 10, 'eleven' => 11, 'twelve' => 12, 'thirteen' => 13];
        self::assertArrayHasKey($word[1], $wordNumbers, "the spelled count '{$word[1]}' is outside the pinned word map — extend it deliberately");

        $files = array_values(array_filter(scandir($root . '/src/Tools/BuiltIn') ?: [], static fn(string $f): bool => str_ends_with($f, '.php')));
        self::assertCount($wordNumbers[$word[1]], $files, 'the page still counts this many built-in tools — src/Tools/BuiltIn/ moved');

        // r75-rv-ja MAJOR-1: this page flipped to twelve while ARCHITECTURE
        // still spelled the stale eleven two paragraphs over from its own tree
        // row (that row keeps its digit pin in tranche-2 H) — one directory,
        // two contradicting pages. The agreement leg derives EACH page's
        // spelled count from its own text and requires both to equal the
        // scandir, so neither page can drift alone again.
        $architecture = self::markdownProse((string) file_get_contents($root . '/docs/ARCHITECTURE.md'));
        self::assertSame(1, preg_match('/holds \*\*(\w+)\*\* concrete `Tool` classes/', $architecture, $archWord), 'the ARCHITECTURE Tools sentence no longer spells its directory count beside the class list — re-anchor this agreement leg');
        self::assertArrayHasKey($archWord[1], $wordNumbers, "the ARCHITECTURE spelled count '{$archWord[1]}' is outside the pinned word map — extend it deliberately");
        self::assertSame($wordNumbers[$archWord[1]], count($files), 'ARCHITECTURE spelled a count the built-in directory no longer holds — flip the page and the census together');
        self::assertSame($wordNumbers[$word[1]], $wordNumbers[$archWord[1]], 'AGENTS_AUTHORING and ARCHITECTURE count src/Tools/BuiltIn/ differently — the two pages drifted apart');
        self::assertContains('TaskTool.php', $files, 'the page says Task delegates — TaskTool.php is no longer in the built-in directory');
        self::assertTrue(class_exists('SugarCraft\Crush\Tools\BuiltIn\TaskTool'), 'TaskTool, the one delegate the page credits, no longer exists');

        $task = self::sourceOf('Tools/BuiltIn/TaskTool.php');
        self::assertStringContainsString('AgentManager::executeAll()', $task, 'Task no longer dispatches through the governed path the closing paragraph promises');
        self::assertTrue(class_exists('SugarCraft\Crush\Agents\AgentManager'), 'AgentManager, the refusal subject of the Task bullet, is gone');

        self::assertStringNotContainsString('none of them delegates', $segment, 'the pre-E675 refusal sentence is back — the model CAN delegate now, via Task');
        self::assertStringNotContainsString('There is no `Task`', $raw, 'a reworded copy of the stale no-Tool claim returned to the page');
        self::assertStringContainsString('`Chat::executeAgents()` drives', $segment, 'the closing paragraph no longer names the governed pool Task rides — that equivalence is the sentence');
        foreach (['AgentWorkerPool', 'ProcessExecutor', 'SubAgent'] as $executor) {
            self::assertTrue(class_exists('SugarCraft\Crush\Agents\\' . $executor), "the paragraph still lists {$executor} among the executor paths src/Agents/ owns");
        }
    }

    /**
     * E686 tranche-11 (AK²): "which fields reach the roster" is stated per
     * value and this arm reads every number back from the two files it cites
     * — AgentPreset's promoted readonly count, the distinct fields
     * fromPreset() actually reads, the provenance gate on permissionMode, the
     * registry heading the phantom quote was healed to, and the six built-in
     * agent definitions.
     */
    public function testAgentsAuthoringPresetRosterDividesTheRegistryItNames(): void
    {
        $root = \dirname(__DIR__, 2);
        $flat = self::markdownProse((string) file_get_contents($root . '/docs/AGENTS_AUTHORING.md'));
        $words = ['six' => 6, 'seven' => 7, 'sixteen' => 16];

        self::assertSame(1, preg_match('/carries (\w+) fields and the path/', $flat, $w1), 'the intro no longer spells what AgentPreset carries');
        self::assertArrayHasKey($w1[1], $words, "spelled count '{$w1[1]}' outside the pinned map — extend it deliberately");

        $presetSrc = self::sourceOf('Agents/AgentPreset.php');
        preg_match_all('/public readonly [^;{]*?\$([a-zA-Z]+)\s*(?:=[^,;]*)?[,;]/', $presetSrc, $props);
        $fields = array_values(array_unique($props[1]));
        self::assertCount($words[$w1[1]], $fields, "the intro still says AgentPreset carries {$w1[1]} fields — the promoted readonly census moved");

        self::assertSame(1, preg_match('/carries \*\*all (\w+)\*\* `AgentPreset` fields onto the `Agent` row:(.*?)Native and imported presets/s', $flat, $list), 'the per-field enumeration moved — the roster this arm derives from it has no home');
        self::assertSame($w1[1], $list[1], 'the intro count and the per-field count stopped agreeing with each other');
        preg_match_all('/`([a-z][a-zA-Z]+)`/', $list[2], $cited);
        self::assertEqualsCanonicalizing(
            [...$fields, 'inherit'],
            array_values(array_unique($cited[1])),
            'the enumerated fields no longer match what fromPreset() reads plus the parenthetical inherit vocabulary'
        );

        $body = self::bodyExcerpt(self::sourceOf('Agents/Agent.php'), 'fromPreset', 2200);
        preg_match_all('/\$preset->([a-zA-Z]+)/', $body, $reads);
        self::assertEqualsCanonicalizing($fields, array_values(array_unique($reads[1])), 'fromPreset() no longer reads exactly the fields the page enumerates — the per-field account drifted from the wiring');
        self::assertMatchesRegularExpression('/source === SkillSource::Native\s*\?\s*\$preset->permissionMode\s*:\s*PermissionMode::Default/', $body, 'the provenance gate on permissionMode is no longer one Native-check beside a Default collapse — the section stakes its exception on this shape');
        self::assertStringContainsString('source: $preset->source', $body, 'the page says source rides along — fromPreset() stopped copying it');
        self::assertStringContainsString('collapses to `PermissionMode::Default`', $flat, 'the gate paragraph no longer names the collapse target in backticks — the byte the code cites');

        self::assertStringContainsString('WHY THIS IS NOT COSMETIC', self::sourceOf('Agents/ForeignAgentPresetRegistry.php'), 'the registry heading the two-axes note cites is gone — heal the prose and this arm together');
        self::assertStringContainsString('headed WHY THIS IS NOT COSMETIC', $flat, 'the page no longer points at the registry heading by its own words — the phantom quote this heals must not return');

        self::assertSame(1, preg_match('/the (\w+) built-in definitions/', $flat, $w2), 'the roster-precedence fence no longer spells the built-in count inside the ordering claim');
        self::assertSame(1, preg_match('/The (\w+) built-in definitions \(`src\/Agents\/AgentDefinition\.php`\) are (.*?)\./s', $flat, $w3), 'the definitions sentence lost its per-name enumeration — the roster leg has nothing to divide');
        self::assertSame($w2[1], $w3[1], 'the fence word and the prose word for the built-in definitions disagree');
        $definitionSrc = self::sourceOf('Agents/AgentDefinition.php');
        preg_match_all("/public const TYPE_[A-Z_]+ = '([a-z-]+)';/", $definitionSrc, $types);
        preg_match_all('/`([a-z-]+)`/', $w3[2], $names);
        self::assertSame($words[$w2[1]], count($types[1]), "the page still calls them {$w2[1]} — AgentDefinition declares another number of TYPE_ constants");
        self::assertEqualsCanonicalizing($types[1], $names[1], 'the documented definition names no longer match the TYPE_ constant values in word');
    }

    /**
     * E686 tranche-11 (AL): the whole COMMANDS surface table is the roster —
     * every row, its S (slash) and P (palette) ticks, and the
     * "blank on new and docs alone" sentence are re-derived from
     * CommandRegistry::all() spec blocks. The page carried zero test
     * citations for its entire existence (even the /notices row shipped
     * self-declaring as unguarded).
     */
    public function testCommandsSurfaceTableSurvivesTheLiveRegistry(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/COMMANDS.md');
        preg_match_all('/^\| `\/([a-z-]+)` \| ?(✓)? ?\| ?(✓)? ?\|/m', $raw, $rows, PREG_SET_ORDER);
        self::assertNotEmpty($rows, 'the surface table shape changed — this arm parses name + S + P cells');

        $registry = self::sourceOf('Commands/CommandRegistry.php');
        self::assertSame(1, preg_match('/CONTROL_PLANE = \[((?:[^\]]*))\];/', $registry, $plane), 'the CONTROL_PLANE constant no longer carries a literal name list — the CP column derives from it');
        preg_match_all("/'([a-z-]+)'/", $plane[1], $reserved);
        $allWindow = self::bodyExcerpt($registry, 'all', 30000);
        $fragments = explode('CommandSpec::new(', $allWindow);
        $live = [];
        foreach (array_slice($fragments, 1) as $fragment) {
            if (preg_match("/^\s*'([a-z-]+)',/", $fragment, $n) === 1) {
                $live[$n[1]] = [
                    'slash' => !str_contains($fragment, 'slashVisible: false'),
                    'plane' => in_array($n[1], $reserved[1], true),
                ];
            }
        }
        self::assertCount(count($live), $rows, 'the surface table row count no longer equals the parsed CommandSpec::new( block count — either the registry grew untabulated or the spec-block walk is broken');
        self::assertSame(
            array_map(static fn(array $r): string => $r[1], $rows),
            array_keys($live),
            'the documented surface table no longer matches CommandRegistry::all() in name or order — a command was added, renamed or reordered without the table'
        );
        foreach ($rows as $row) {
            self::assertSame($live[$row[1]]['slash'], ($row[2] ?? '') === '✓', "the S column on /{$row[1]} disagrees with its slashVisible spec — the / and Ctrl+P columns ARE the two filters");
            self::assertSame($live[$row[1]]['plane'], ($row[3] ?? '') === '✓', "the CP column on /{$row[1]} disagrees with CommandRegistry::CONTROL_PLANE — the intro states CP marks exactly the reserved names");
        }
        self::assertSame(
            ['new', 'docs'],
            array_keys(array_filter($live, static fn(array $spec): bool => !$spec['slash'])),
            'the page says S is blank on new and docs ALONE — the palette-only pair changed shape'
        );
        self::assertStringContainsString('(`slashVisible: false`)', self::markdownProse($raw), 'the asymmetry paragraph no longer quotes the spec flag the S column proves');
        self::assertStringContainsString('**CP** marks a reserved name', self::markdownProse($raw), 'the intro no longer states what the CP column marks — that derivation is the sentence');
        self::assertTrue((new \ReflectionClass('SugarCraft\Crush\Commands\CommandSpec'))->hasProperty('slashVisible'), 'CommandSpec::$slashVisible, the property the table proves, is gone');
    }

    /**
     * E697: the /mcp palette row LISTS (its dispatch is `mcp auth list`; the
     * toggle WRITE was declined at E689 and that decline stands). The
     * registry label and the COMMANDS.md no-leading-slash paragraph are one
     * truth - the row carries the honest string, the page names the action by
     * that truth, and toggle wording may not return to either side without
     * reddening here. Before this arm neither side was pinned at all: every
     * existing palette guard derives from the registry and so survives any
     * relabel, truthful or not.
     */
    public function testCommandsMcpPaletteSentenceTracksTheTruthfulLabel(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/COMMANDS.md');
        $start = strpos($raw, 'One name reaches a handler');
        self::assertIsInt($start, 'the no-leading-slash paragraph moved - this arm reads it by its opening words');
        $end = strpos($raw, "\n\n", $start);
        self::assertIsInt($end, 'the no-leading-slash paragraph now runs to the file tail - the window shape this arm reads changed');
        $paragraph = self::markdownProse(substr($raw, $start, $end - $start));

        $mcp = null;
        foreach (\SugarCraft\Crush\Commands\CommandRegistry::all() as $spec) {
            if ($spec->name === 'mcp') {
                $mcp = $spec;
            }
        }
        self::assertNotNull($mcp, 'the /mcp registry row is gone - the palette action and the page sentence lost their subject together');
        self::assertSame(
            'List MCP servers',
            $mcp->label(),
            'E697: the palette row dispatches `mcp auth list` and nothing else - a relabel, back to a toggle or otherwise, must flip this figure, the registry row, and the page together',
        );
        self::assertStringContainsString(
            "the palette's MCP list action",
            $paragraph,
            'the page no longer names the palette action by its truthful list shape - E697 corrected this sentence together with the label',
        );
        self::assertStringNotContainsStringIgnoringCase(
            'toggle',
            $paragraph,
            'toggle wording returned to the no-leading-slash paragraph - the action has never toggled anything (E689 declined the write), so the sentence must not imply it again',
        );
    }

    /**
     * E686 tranche-11 (AM): the Template forms section counts itself against
     * CommandSpec::TEMPLATE_PATTERN — three top-level alternation branches,
     * the quoted first branch byte-for-byte, its three spells against the
     * five table rows — plus the acknowledged "all four template forms"
     * docblock erratum, the shared ten-second shell budget and its
     * sixty-forms arithmetic, and the /websearch flag row against the tool
     * schema it must match.
     */
    public function testCommandsTemplateSectionSurvivesThePatternItQuotes(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/COMMANDS.md');
        $start = strpos($raw, '## Template forms');
        self::assertIsInt($start, 'the Template forms heading moved');
        $end = strpos($raw, "\n## ", $start + 5);
        self::assertIsInt($end, 'no level-2 section follows Template forms — the window (which spans its subsections to the wedge bullets) became the page tail');
        $window = substr($raw, $start, $end - $start);
        $segment = self::markdownProse($window);
        $words = ['two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'ten' => 10, 'sixty' => 60];

        $spec = self::sourceOf('Commands/CommandSpec.php');
        $patternAt = strpos($spec, 'const TEMPLATE_PATTERN');
        self::assertIsInt($patternAt, 'TEMPLATE_PATTERN is gone — every count in this section is derived from it');
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", substr($spec, $patternAt, 420), $frags);
        $pattern = implode('', $frags[1] ?? []);
        self::assertGreaterThan(10, strlen($pattern), 'the pattern literal no longer parses as concatenated fragments');

        $depth = 0;
        $inClass = false;
        $escaped = false;
        $branches = 1;
        $firstBranchAlt = 0;
        $body = substr($pattern, 1, -1);
        for ($i = 0; $i < strlen($body); $i++) {
            $c = $body[$i];
            if ($escaped) {
                $escaped = false;
                continue;
            }
            if ($c === '\\') {
                $escaped = true;
                continue;
            }
            if ($inClass) {
                if ($c === ']') {
                    $inClass = false;
                }
                continue;
            }
            if ($c === '[') {
                $inClass = true;
            } elseif ($c === '(') {
                $depth++;
            } elseif ($c === ')') {
                $depth--;
            } elseif ($c === '|') {
                if ($depth === 0) {
                    $branches++;
                } elseif ($branches === 1) {
                    $firstBranchAlt++;
                }
            }
        }
        self::assertSame(1, preg_match('/the \*(\w+)\* alternation branches/', $segment, $w), 'the page no longer italic-spells its branch count');
        self::assertSame($words[$w[1]], $branches, "the page still says *{$w[1]}* alternation branches — TEMPLATE_PATTERN walks to another number");
        self::assertSame(1, preg_match('/\*\*([Ff]ive|[A-Za-z]+)\*\* substitutions/', $segment, $five), 'the opening sentence no longer bold-spells the substitution count');
        self::assertSame(1, preg_match('/spells (\w+) of the five/', $segment, $three), 'the first-branch sentence no longer states how many forms it spells');
        self::assertSame($words[$three[1]], $firstBranchAlt + 1, 'the first branch no longer spells this many forms by its inner alternation');
        self::assertSame($words[strtolower($five[1])], $firstBranchAlt + 1 + ($branches - 1), 'five substitutions must be the first branch spells plus one form per later branch');
        self::assertSame($words[strtolower($five[1])], substr_count($window, "\n| `"), 'the substitutions table stopped carrying one row per spelled substitution (window-local literal counts, not a roster)');
        self::assertSame(1, preg_match('/first branch, `(\$\(\\\\\$\|ARGUMENTS\|\[1-9\]\))`,/', $segment, $quote), 'the quoted first branch lost its backticked literal');
        self::assertStringContainsString($quote[1], $pattern, 'the doc-quoted branch literal is no longer a byte-for-byte piece of TEMPLATE_PATTERN');

        self::assertStringContainsString('all four template forms', $spec, 'the source docblock no longer carries the acknowledged-wrong count — and then the page sentence naming it as wrong goes stale with it');
        self::assertStringContainsString('that count is wrong in the source too', $segment, 'the page stopped flagging the source erratum it quotes');

        $budget = (int) (new \ReflectionClassConstant('SugarCraft\Crush\Commands\CommandSpec', 'SHELL_BUDGET_SECONDS'))->getValue();
        self::assertSame(1, preg_match('/(\w+) `` !`sleep 30` `` forms with a (\w+)-second per-command timeout wedges the single-threaded TUI for (\w+) minutes/', $segment, $math), 'the wedge arithmetic lost its sentence shape');
        self::assertSame($budget, $words[$math[2]], "the page still narrates a {$math[2]}-second command bound — SHELL_BUDGET_SECONDS moved");
        self::assertSame(intdiv($words[$math[1]] * $budget, 60), $words[$math[3]], 'sixty forms at this budget no longer divide to the stated wedge duration');
        self::assertStringContainsString('budget is shared by ALL of an', $segment, 'the per-expansion framing this arithmetic defends has been reworded away');

        $tool = self::sourceOf('Tools/BuiltIn/WebSearch.php');
        self::assertSame(1, preg_match('/^\| `\/websearch` \|.*?\[--safesearch ([0-9\\\\|]+)\] \[--time-range ([a-z\\\\|]+)\]/m', $raw, $flag), 'the /websearch row lost its flag cells');
        $ladder = explode('\\|', $flag[1]);
        self::assertSame(['0', '1', '2'], $ladder, 'the safesearch ladder no longer spells 0|1|2');
        self::assertSame(1, preg_match("/'safesearch' => \[.*?'minimum' => (\d+), 'maximum' => (\d+)/s", $tool, $range), 'the WebSearch schema no longer bounds safesearch by minimum/maximum');
        self::assertSame([$ladder[0], $ladder[2]], [$range[1], $range[2]], 'the documented safesearch ends no longer match the schema minimum/maximum');
        self::assertSame(['day', 'month', 'year'], explode('\\|', $flag[2]), 'the time-range ladder changed spelling');
        self::assertSame(1, preg_match("/'time_range' => \[.*?'enum' => \[([^\]]+)\]/s", $tool, $enum), 'the WebSearch schema no longer declares a time_range enum');
        preg_match_all("/'([a-z]+)'/", $enum[1], $enumValues);
        self::assertSame(['day', 'month', 'year'], $enumValues[1], 'the documented time-range values no longer match the schema enum in word or order');
    }

    /**
     * E686 tranche-11 (AN): PROMPT_ENGINEERING's eleven-slot list is the
     * parallel copy of the ARCHITECTURE assembly order — same count word,
     * same ordinal range, matching item-for-item — and its Stability
     * partition sentence must still divide the list contiguously with three
     * enum cases behind it.
     */
    public function testPromptEngineeringSlotsMirrorTheArchitectureAssembly(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/PROMPT_ENGINEERING.md');
        $start = strpos($raw, '## The eleven slots, in order of record');
        self::assertIsInt($start, 'the eleven-slots heading moved — the count word lives in its own text');
        $end = strpos($raw, "\n## ", $start + 5);
        self::assertIsInt($end, 'no heading follows the slots section — the window became the page tail');
        $window = substr($raw, $start, $end - $start);

        self::assertSame(1, preg_match('/there are (\w+) slots:/', $window, $word), 'the intro no longer spells the slot count beside "slots:"');
        $words = ['nine' => 9, 'ten' => 10, 'eleven' => 11, 'twelve' => 12];
        self::assertArrayHasKey($word[1], $words, "spelled count '{$word[1]}' outside the pinned map — extend it deliberately");
        preg_match_all('/^(\d+)\. \*\*/m', $window, $ordinals);
        self::assertSame(range(1, $words[$word[1]]), array_map('intval', $ordinals[1]), 'the numbered list stopped being exactly 1..N with N the spelled count');

        $archRaw = (string) file_get_contents($root . '/docs/ARCHITECTURE.md');
        $archStart = strpos($archRaw, '### The system prompt, in assembly order');
        self::assertIsInt($archStart, 'the ARCHITECTURE assembly heading moved — arm AP anchors on the same string and this cross-page leg anchors with it');
        $archEnd = strpos($archRaw, 'Item 10 is what makes', $archStart);
        self::assertIsInt($archEnd, 'the assembly follow-up paragraph moved — the cross-page item count lost its window');
        preg_match_all('/^(\d+)\. /m', substr($archRaw, $archStart, $archEnd - $archStart), $archOrdinals);
        self::assertCount(count($ordinals[1]), $archOrdinals[1], 'the two slot lists no longer carry the same number of items — one page was updated and the mirror left behind');

        self::assertSame(1, preg_match('/Slots (\d+).*?(\d+) are the Static prefix; (\d+).*?(\d+) are PerSession; (\d+).*?(\d+) are PerTurn\./s', $window, $split), 'the stability partition sentence lost its shape — the volatility story has no arithmetic left to check');
        self::assertSame(1, (int) $split[1], 'the Static prefix no longer starts at slot 1');
        self::assertSame((int) $split[2] + 1, (int) $split[3], 'the PerSession band does not open where the Static prefix closes');
        self::assertSame((int) $split[4] + 1, (int) $split[5], 'the PerTurn band does not open where PerSession closes');
        self::assertSame($words[$word[1]], (int) $split[6], 'the bands do not close on the spelled slot count');
        self::assertCount(3, \SugarCraft\Crush\Context\Stability::cases(), 'Stability grew a tier — every per-item stability label in the list needs re-reading, starting with this partition');

        self::assertSame(1, preg_match('/^11\. \*\*Environment\*\* \(`EnvironmentBlock`\).*\*\*LAST\*\*/m', $window), 'item eleven is no longer Environment LAST — the P3.S1 invariant this page exists to carry has moved');
        self::assertTrue(method_exists('SugarCraft\Crush\Runtime', 'systemPromptSections'), 'the intro cites Runtime::systemPromptSections() — gone');
        foreach (['basePrompt', 'toolGuidanceSection'] as $method) {
            self::assertTrue(method_exists('SugarCraft\Crush\Runtime', $method), "slot prose cites Runtime::{$method}() — gone");
        }
        self::assertTrue(method_exists('SugarCraft\Crush\Skills\SkillMatcher', 'listForPrompt'), 'slot 10 cites SkillMatcher::listForPrompt() — gone');
        self::assertTrue(class_exists('SugarCraft\Crush\Context\EnvironmentBlock'), 'slot 11 names EnvironmentBlock — gone');
    }

    /**
     * E686 tranche-11 (AO): the fence-tag roster, the breakpoint budget pair,
     * the "nothing consults CacheBreakpoints" census, the six-item
     * prohibition register and the five-subprocess cost claim all divide
     * their sources. The prohibition count stays internal (this page's own
     * list); the §9.12 bullet census in the plan document keeps its dated
     * "at base" framing and is HELD-external by the campaign's own law.
     */
    public function testPromptEngineeringTagsBreakpointsAndRegisterReadTheirSources(): void
    {
        $root = \dirname(__DIR__, 2);
        $raw = (string) file_get_contents($root . '/docs/PROMPT_ENGINEERING.md');
        $flat = self::markdownProse($raw);
        $words = ['four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'eight' => 8];

        self::assertSame(1, preg_match('/The roster is the (\w+) tags `PromptFence::tags\(\)` returns/', $flat, $tagWord), 'the tags sentence no longer spells its count beside PromptFence::tags()');
        $tags = PromptFence::tags();
        self::assertCount($words[$tagWord[1]], $tags, "the page still says {$tagWord[1]} tags — PromptFence::tags() returns another count");
        self::assertSame(1, preg_match('/read at this base: (.*?)\. Two entries/s', $flat, $listed), 'the inline tag list lost its boundaries');
        preg_match_all('/`([^`]+)`/', $listed[1], $docTags);
        self::assertSame($tags, $docTags[1], 'the documented tag list no longer matches PromptFence::tags() in word or order — cross-page: MEMORY.md arm BD pins the same authority against its own page');

        self::assertSame(1, preg_match('/budget of `CacheBreakpoints::MAX_BREAKPOINTS` — (\w+) —/', $flat, $bp), 'the breakpoint budget no longer spells its number between em-dashes');
        $max = (int) (new \ReflectionClassConstant('SugarCraft\Crush\Providers\CacheBreakpoints', 'MAX_BREAKPOINTS'))->getValue();
        self::assertSame($words[$bp[1]], $max, "the page still budgets {$bp[1]} breakpoints — MAX_BREAKPOINTS moved");
        $withAutomatic = (int) (new \ReflectionClassConstant('SugarCraft\Crush\Providers\CacheBreakpoints', 'BUDGET_WITH_AUTOMATIC'))->getValue();
        self::assertSame($max - 1, $withAutomatic, 'BUDGET_WITH_AUTOMATIC is no longer the explicit budget minus one, which the same paragraph states');
        self::assertStringContainsString('explicit budget minus one', $flat, 'the minus-one framing the constant pair is pinned to has been reworded away');

        foreach (self::srcTexts() as $relative => $text) {
            if ($relative === 'src/Providers/CacheBreakpoints.php') {
                continue;
            }
            $bare = (string) preg_replace('#(/\*.*?\*/|//[^\n]*)#s', '', $text);
            self::assertStringNotContainsString('CacheBreakpoints', $bare, "the page stakes its not-armed paragraph on no src/ file consulting CacheBreakpoints — {$relative} now does");
        }
        $bin = (string) file_get_contents($root . '/bin/sugarcrush');
        self::assertStringNotContainsString('CacheBreakpoints', (string) preg_replace('~(/\*.*?\*/|//[^\n]*|^\s*\#[^\n]*)~s', '', $bin), 'bin/sugarcrush now consults CacheBreakpoints — the unwired claim is dead prose');

        self::assertSame(1, preg_match('/§9\.12 enumerates (\w+) standing prohibitions/', $flat, $regWord), 'the register sentence no longer spells the prohibition count');
        $registerStart = strpos($raw, '## The "do not do this" register');
        self::assertIsInt($registerStart, 'the register heading moved — its item list is the count the page cites');
        preg_match_all('/^(\d+)\. \*\*Do not /m', substr($raw, $registerStart), $items);
        self::assertCount($words[$regWord[1]], $items[1], "the page still enumerates {$regWord[1]} standing prohibitions — the numbered list moved");

        self::assertSame(1, preg_match('/pays its (\w+) git subprocess/', $flat, $gitWord), 'the one-render-per-build sentence no longer spells its subprocess count');
        self::assertMatchesRegularExpression('/those ' . strtoupper($gitWord[1]) . ' subprocesses run per step/', self::sourceOf('Context/EnvironmentBlock.php'), "the page says {$gitWord[1]} git subprocess polls — EnvironmentBlock's own docblock counts them in capitals and no longer agrees");
    }

    /**
     * E696-α (BA): the per-preset MCP enforcement sentences — README's MCP
     * bullet and MCP.md's "What the model sees" section — against the seam
     * they name. The doc claim is three-legged: `resolveGrantedTools()`
     * consults `McpRouter::serverAllowed` on the `instanceof McpToolBridge`
     * gate, and the LAW the page paraphrases (empty allows all, raw keys,
     * `*` globs, sanitised spellings match nothing) is derived live from the
     * single predicate both callers share. A reverted seam or a forked second
     * implementation of the membership rule reddens this arm; the glob entry
     * is assembled via chr(42) so this file contributes no new glob-shaped
     * literal to the PathGlob corpus.
     */
    public function testPerPresetMcpNarrowingSentenceTracksTheGrantResolutionSeam(): void
    {
        $readme = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/README.md'));
        $mcp = self::markdownProse((string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md'));

        self::assertStringContainsString(
            'allowlists are enforced at grant-resolution',
            $readme,
            'README stopped claiming grant-resolution enforcement — flip this arm with the seam',
        );
        self::assertStringContainsString(
            'drops bridges whose server the preset does not name',
            $mcp,
            'MCP.md reworded what the narrowing does — re-pin this arm with the prose',
        );

        $body = self::bodyExcerpt(self::sourceOf('Agents/AgentManager.php'), 'resolveGrantedTools', 9000);
        self::assertStringContainsString(
            '$tool instanceof McpToolBridge',
            $body,
            'the docs name resolveGrantedTools() as the enforcement point; the bridge-specific gate is gone from it',
        );
        self::assertStringContainsString(
            'McpRouter::serverAllowed(',
            $body,
            'the seam no longer consults the router\'s single law — a forked second membership rule is exactly the divergence this arm exists for',
        );

        // The law itself, live off the shared predicate — every half the two
        // pages paraphrase, none hand-typed.
        self::assertTrue(McpRouter::serverAllowed('anything', []), 'empty = allow-all left the law; every built-in preset ships the empty list');
        self::assertTrue(McpRouter::serverAllowed('alpha', ['alpha']));
        self::assertFalse(McpRouter::serverAllowed('beta', ['alpha']));
        self::assertTrue(
            McpRouter::serverAllowed('data_lake', ['data_' . \chr(42)]),
            'the fnmatch leg left — MCP.md\'s glob sentence would now be a lie',
        );
        self::assertTrue(McpRouter::serverAllowed('my_files', ['my_files']), 'a raw key must keep the bridge whose wire name sanitises it');
        self::assertFalse(McpRouter::serverAllowed('my_files', ['my_5Ffiles']), 'the sanitised spelling is not a config key — matching it would be the E42 bug class back in the law');
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

    /**
     * E698: MCP.md's liveness table quotes the panel's SUFFIX VOCABULARY,
     * derived from `livenessSuffix()` itself (match arms, the null-row return,
     * and the unknown fallback — the labels the panel can emit, not a typed
     * copy), the two shared sentences quoted byte-for-byte from the panel and
     * the page, and the never-launches law sliced as tokens: the snapshot
     * method lives OUTSIDE the mcpServerInventory AW span, still routes
     * through mcpConfigDecision(), and contains no call of self::mcpClient(.
     */
    public function testMcpLivenessSuffixesAndTheNeverLaunchingSnapshotReadThePanel(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        $panel = self::sourceOf('Tui/McpPanel.php');

        $suffix = null;
        foreach (self::functionSpans($panel) as $span) {
            if ('livenessSuffix' === $span['name']) {
                $suffix = (string) substr($panel, $span['begin'], $span['end'] - $span['begin']);
            }
        }
        self::assertIsString($suffix, 'McpPanel::livenessSuffix() vanished — the page\'s suffix table lost its generator');

        $labels = [];
        preg_match_all("/\['\w+', true\] => '([^']+)'/", $suffix, $arms);
        foreach ($arms[1] as $label) {
            $labels[] = $label;
        }
        preg_match_all("/return ' \x{00B7} ([^'\$]+?)';/u", $suffix, $fixed);
        foreach ($fixed[1] as $label) {
            $labels[] = $label;
        }
        preg_match("/default => '([^']+)'/", $suffix, $down);
        self::assertSame(1, isset($down[1]) ? 1 : 0, 'the exited fallback arm left — one label is unminted');
        $labels[] = $down[1];
        $labels = array_values(array_unique($labels));
        self::assertSame(
            ['up', 'ready', 'ready (in-process)', 'not up', 'state unknown', 'exited'],
            $labels,
            'the panel\'s liveness vocabulary changed shape — the page table and this pin move with it',
        );

        $dot = "\u{00B7}";
        foreach ($labels as $label) {
            self::assertStringContainsString(
                '` ' . $dot . ' ' . $label,
                $raw,
                'MCP.md no longer quotes the suffix the panel emits for ' . $label,
            );
        }

        foreach (['  Live in this process: ', '  Started but no longer declared: '] as $sentence) {
            self::assertStringContainsString("'" . $sentence . "'", $panel, "the panel literal for \"{$sentence}\" moved");
            self::assertStringContainsString(
                $sentence === '  Live in this process: ' ? 'Live in this process: K of M declared' : trim($sentence),
                $raw,
                "MCP.md stopped quoting \"{$sentence}\"",
            );
        }

        $bootstrap = self::sourceOf('Cli/Bootstrap.php');
        $inventory = null;
        $snapshot = null;
        foreach (self::functionSpans($bootstrap) as $span) {
            $inventory ??= 'mcpServerInventory' === $span['name'] ? $span : null;
            $snapshot ??= 'mcpLivenessSnapshot' === $span['name'] ? $span : null;
        }
        self::assertIsArray($inventory, 'mcpServerInventory() vanished — the AW span lost its referent');
        self::assertIsArray($snapshot, 'Bootstrap::mcpLivenessSnapshot() vanished — the never-launches pin lost its subject');
        self::assertGreaterThanOrEqual(
            $inventory['end'],
            $snapshot['begin'],
            'the snapshot moved INSIDE or before the mcpServerInventory span — AW slices that span to pin its zero-exec claim, and a shared slice would blur which method owes what',
        );
        $snapText = (string) substr($bootstrap, $snapshot['begin'], $snapshot['end'] - $snapshot['begin']);
        self::assertStringContainsString('mcpConfigDecision(', $snapText, 'the snapshot no longer resolves through the shared decision path — its memo key could drift from the writer\'s');
        self::assertStringNotContainsString('self::mcpClient(', $snapText, 'the liveness readout now BUILDS a client — building one starts every server, which is the exact act this method exists to refuse');
        self::assertTrue(method_exists(Bootstrap::class, 'mcpLivenessSnapshot'), 'the page cites Bootstrap::mcpLivenessSnapshot() by name');
    }

    /**
     * E703-α: the changed-since-launch sentence is ONE literal shared by the
     * panel source and MCP.md (byte-compared after whitespace collapse), the
     * freeze law survives verbatim beside its trustedRootsForThisProcess()
     * cite, the β rejection stays stated, and the digest machinery is pinned
     * where it lives: the store line inside the mcpClient span, the keying
     * method outside the AW inventory span, and the private static itself.
     */
    public function testMcpConfigChangedSinceLaunchRowDigestsItsLiteralAndStaysWithinTheFreezeLaw(): void
    {
        $raw = (string) file_get_contents(\dirname(__DIR__, 2) . '/docs/MCP.md');
        $prose = self::markdownProse($raw);
        $panel = self::sourceOf('Tui/McpPanel.php');

        $needle = 'Config: changed since launch — restart sugar-crush to apply (reload is not implemented)';
        self::assertStringContainsString("'  " . $needle . "'", $panel, 'the panel literal moved — the page quotes it');
        self::assertStringContainsString($needle, $prose, 'MCP.md no longer quotes the panel sentence byte-for-byte');

        self::assertSame(
            1,
            substr_count($raw, 'read **once per process and frozen**' . "\n(`Bootstrap::trustedRootsForThisProcess()`)"),
            'the freeze law is no longer the verbatim sentence + cite it was — E703-α quotes it as the reason reload is declined',
        );
        self::assertStringContainsString('re-arms exactly', $prose, 'the β-rejection stance left the page — the design ruling must stay disclosed, not implied');

        $bootstrap = self::sourceOf('Cli/Bootstrap.php');
        $inventory = null;
        $changed = null;
        $builder = null;
        foreach (self::functionSpans($bootstrap) as $span) {
            $inventory ??= 'mcpServerInventory' === $span['name'] ? $span : null;
            $changed ??= 'mcpConfigChangedSinceLaunch' === $span['name'] ? $span : null;
            $builder ??= 'mcpClient' === $span['name'] ? $span : null;
        }
        self::assertIsArray($inventory);
        self::assertIsArray($changed, 'Bootstrap::mcpConfigChangedSinceLaunch() vanished — the page sentence lost its producer');
        self::assertIsArray($builder, 'Bootstrap::mcpClient() vanished — the digest-store pin lost its host');
        self::assertGreaterThanOrEqual($inventory['end'], $changed['begin'], 'the digest reader slid back inside the AW inventory span');
        $changedText = (string) substr($bootstrap, $changed['begin'], $changed['end'] - $changed['begin']);
        self::assertStringContainsString('mcpConfigDecision(', $changedText, 'the digest check no longer resolves through the shared decision path');
        self::assertStringNotContainsString('self::mcpClient(', $changedText, 'the changed-check now BUILDS a client — building starts servers, which no readout may do');
        $builderText = (string) substr($bootstrap, $builder['begin'], $builder['end'] - $builder['begin']);
        self::assertStringContainsString('self::$mcpConfigDigests[$pid][$path] = $digest;', $builderText, 'the launch stopped storing its digest at the memo point — the panel line would compare against nothing');

        $prop = new \ReflectionProperty(Bootstrap::class, 'mcpConfigDigests');
        self::assertTrue($prop->isPrivate() && $prop->isStatic(), 'the digest memo is process-global and private by design — the panel reads verdicts, not bytes');
        self::assertTrue(method_exists(Bootstrap::class, 'mcpConfigChangedSinceLaunch'));
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

    /**
     * Collapse markdown to one breath WITHOUT proseOf's comment-marker strip —
     * table rows and **bold** cells are the payload here, not decoration (the
     * proseOf stripper would eat the first star of a leading bold run).
     */
    private static function markdownProse(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', $text);
    }

    /**
     * The single literal a hook's name()/event()/matcher() returns, resolving
     * self::CONST through reflection and HookEvent::Case to its case name.
     * These ARE the table cells; a reworded return statement reddens here, not
     * in a reader's browser (E353's inventory shape).
     */
    private static function hookMethodLiteral(string $fileText, string $class, string $method): string
    {
        self::assertSame(
            1,
            preg_match('/function ' . $method . '\(\)[^;{]*\{\s*return (?:self::([A-Z_]+)|HookEvent::(\w+)|\'([^\']*)\');/', $fileText, $m),
            "{$class}::{$method}() no longer ends in a single literal return — the HOOKS.md tables quote that literal",
        );
        if (($m[1] ?? '') !== '') {
            $value = (new \ReflectionClassConstant($class, $m[1]))->getValue();
            self::assertIsString($value, "{$class}::{$m[1]} is no longer a string — the name table cannot quote it");

            return $value;
        }

        return ($m[2] ?? '') !== '' ? $m[2] : $m[3];
    }

    /**
     * @return array<string,string> every src/ PHP file, keyed by its
     *                         package-relative path, text included
     */
    private static function srcTexts(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        $cache = [];
        foreach (self::everySourceFileIn(\dirname(__DIR__, 2), ['src']) as $relative => $path) {
            $text = file_get_contents($path);
            self::assertIsString($text, "src file {$relative} vanished mid-scan");
            $cache[$relative] = $text;
        }

        return $cache;
    }

    /**
     * @return list<array{0:string,1:int}> [relative path, offset] per literal
     *                                    occurrence of $token across all of src/
     */
    private static function srcOccurrences(string $token): array
    {
        $hits = [];
        foreach (self::srcTexts() as $relative => $text) {
            $cursor = 0;
            while (false !== ($pos = strpos($text, $token, $cursor))) {
                $hits[] = [$relative, $pos];
                $cursor = $pos + 1;
            }
        }

        return $hits;
    }

    /**
     * @var array<string, list<array{name:string,begin:int,end:int}>>
     */
    private static array $functionSpansCache = [];

    /**
     * The innermost NAMED function enclosing an offset — tokenized once per
     * file, so a doc-block mention of another function never impersonates the
     * enclosing frame (why the text-only strrpos shortcut stays out).
     */
    private static function enclosingMethodName(string $relative, string $text, int $offset): string
    {
        $spans = self::$functionSpansCache[$relative] ??= self::functionSpans($text);
        $best = null;
        foreach ($spans as $span) {
            if ($span['begin'] <= $offset && $offset <= $span['end'] && ($best === null || $span['begin'] > $best['begin'])) {
                $best = $span;
            }
        }
        self::assertIsArray($best, 'no named function encloses the cited occurrence — the call-site shape changed');

        return $best['name'];
    }

    private static function enclosingFunctionSlice(string $relative, int $offset): string
    {
        $text = self::srcTexts()[$relative];
        $spans = self::$functionSpansCache[$relative] ??= self::functionSpans($text);
        $best = null;
        foreach ($spans as $span) {
            if ($span['begin'] <= $offset && $offset <= $span['end'] && ($best === null || $span['begin'] > $best['begin'])) {
                $best = $span;
            }
        }
        self::assertIsArray($best, 'no named function encloses the cited occurrence — the guard slice has no frame');

        return substr($text, $best['begin'], $best['end'] - $best['begin']);
    }

    /**
     * The argument text of a `f(` whose open paren sits just before $argStart,
     * balanced through nested calls, stopped at the matching close paren.
     */
    private static function balancedArguments(string $text, int $argStart): string
    {
        $depth = 1;
        for ($i = $argStart, $length = \strlen($text); $i < $length; $i++) {
            if ($text[$i] === '(') {
                ++$depth;
            } elseif ($text[$i] === ')') {
                --$depth;
                if ($depth === 0) {
                    return substr($text, $argStart, $i - $argStart);
                }
            }
        }
        self::fail('unbalanced parentheses after a call site — the scan cannot attribute its arguments');
    }

    /**
     * @return list<array{name:string,begin:int,end:int}>
     */
    private static function functionSpans(string $text): array
    {
        $spans = [];
        $tokens = \PhpToken::tokenize($text);
        $count = \count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$tokens[$i]->is(T_FUNCTION)) {
                continue;
            }
            $j = $i + 1;
            while ($j < $count && $tokens[$j]->is(T_WHITESPACE)) {
                ++$j;
            }
            if ($j >= $count || !$tokens[$j]->is(T_STRING)) {
                continue; // anonymous function/arrow-fn has no name token here
            }
            $name = $tokens[$j]->text;
            $brace = null;
            for ($k = $j; $k < $count; $k++) {
                if ($tokens[$k]->is('{')) {
                    $brace = $k;
                    break;
                }
                if ($tokens[$k]->is(';')) {
                    break; // abstract/interface signature
                }
            }
            if ($brace === null) {
                continue;
            }
            $depth = 0;
            for ($k = $brace; $k < $count; $k++) {
                if ($tokens[$k]->is('{')) {
                    ++$depth;
                } elseif ($tokens[$k]->is('}')) {
                    --$depth;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $spans[] = ['name' => $name, 'begin' => $tokens[$i]->pos, 'end' => $tokens[$k]->pos + 1];
        }

        return $spans;
    }
}
