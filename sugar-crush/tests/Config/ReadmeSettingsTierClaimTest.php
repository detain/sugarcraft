<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Config;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\Tool;

/**
 * `sugar-crush/README.md` carried two settings-tier claims that the source
 * contradicted, and both were the kind a reader has no reason to re-derive.
 *
 * FIRST, AND IT IS THE SECURITY ONE. The README said `disabledTools` is
 * available to a trusted project because "expressing the same attack there
 * means naming every tool it removes — a value you can see". That advertises an
 * AUDITABILITY property the code does not have:
 * {@see \SugarCraft\Crush\Cli\Bootstrap::filterToolSet()} matches through
 * {@see PermissionRule::matchesToolName()}, which is bare `fnmatch()`, and a
 * negated character class removes an unbounded set while naming none of it.
 * It is the sentence an operator leans on when deciding whether a cloned
 * repository's settings file needs reading at all.
 *
 * SECOND, the README called `config.json` "the deprecated name". Nothing marks
 * it deprecated, and it is the ONLY settings file this app ever writes back to
 * ({@see \SugarCraft\Crush\Cli\Bootstrap::writeUserConfig()} through
 * {@see \SugarCraft\Crush\Cli\Bootstrap::userConfigPath()}), so the word pointed
 * readers away from the one file that receives their persisted `theme` and
 * `provider`.
 *
 * WHY A TEST AND NOT JUST A REWRITE. Both claims were already corrected in
 * `src/Config/LayeredSettings.php` and in `docs/SETTINGS.md` while README.md
 * kept saying the opposite for two rounds, which is exactly the failure mode a
 * prose fix on its own does not close. What is pinned here is the pair: the
 * MECHANISM, measured rather than quoted, and the README text that describes
 * it. Change the mechanism and the mechanism tests red; restore either false
 * sentence and the prose tests red.
 *
 * BOTH PROSE RULES ARE STRUCTURAL, NOT PROXIMITY-BASED, and that is load-bearing
 * rather than stylistic. Each was first written as "the retraction must be
 * within N characters", and each SURVIVED the mutation that restored its false
 * sentence verbatim, because a window wide enough to reach the retraction is
 * wide enough for a restored sentence to sit inside it and inherit it. Both now
 * say the same thing instead: the retracted wording may appear only on a `>`
 * line. If you find yourself widening a window here, that is the bug.
 *
 * WHAT IS DELIBERATELY NOT HERE. The end-to-end effect of the counterexample —
 * a trusted project's `{"disabledTools": ["[!B]*"]}` handing the model exactly
 * `Bash` — is already pinned by
 * {@see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest::testATrustedProjectsGlobStillChoosesTheToolSet()}
 * and is not duplicated. This file asserts the matcher and the tool census the
 * README's numbers are quoted FROM, which is the half that would go stale
 * silently.
 *
 * @internal
 */
final class ReadmeSettingsTierClaimTest extends TestCase
{
    use HomeSandboxTrait;

    /**
     * The pattern the README quotes as the counterexample. A negated character
     * class: "any name whose first character is not `B`, then anything".
     */
    private const COUNTEREXAMPLE_GLOB = '[!B]*';

    private string $tmpDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir() . '/readme_tier_claim_' . uniqid('', true);
        mkdir($this->tmpDir . '/home/.sugar-crush', 0o700, true);
        mkdir($this->tmpDir . '/repo/' . LayeredSettings::dir(), 0o700, true);

        $this->useHomeSandbox($this->tmpDir . '/home');
    }

    protected function tearDown(): void
    {
        Bootstrap::useProjectRootForSettings(null);
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();
        $this->removeTree($this->tmpDir);

        parent::tearDown();
    }

    private function readme(): string
    {
        return $this->document('README.md');
    }

    /**
     * Any page in the package root, read as bytes.
     *
     * PATH-RELATIVE AND ASSERTED PRESENT, because a page that has been renamed
     * or moved must red as "the reader is gone" rather than as a containment
     * miss against an empty string.
     */
    private function document(string $relativePath): string
    {
        $path = \dirname(__DIR__, 2) . '/' . $relativePath;
        self::assertFileExists($path, "{$relativePath} is quoted as a reader of the launch report but is gone");

        $text = file_get_contents($path);
        self::assertIsString($text, "{$relativePath} is unreadable");

        return $text;
    }

    /**
     * The built-in tool set with nothing configured — MEASURED, never written
     * as a literal. The census has already gone from ten tools to eleven once,
     * and a hardcoded count here would turn this file into the decayed figure
     * it exists to prevent.
     *
     * @return list<string>
     */
    private function toolCeiling(): array
    {
        $root = $this->tmpDir . '/repo';
        Bootstrap::useProjectRootForSettings($root);

        return array_map(
            static function (Tool $tool): string {
                return $tool->name();
            },
            Bootstrap::tools($root),
        );
    }

    // -------------------------------------------------------------------------
    // E74 — the auditability claim
    // -------------------------------------------------------------------------

    /**
     * THE MECHANISM, measured here so the README's numbers have a generator.
     *
     * One five-character glob removes every tool but one, and NAMES NONE OF
     * THEM — which is the precise negation of "naming every tool it removes, a
     * value you can see".
     *
     * THIS DOC-BLOCK USED TO SAY the assertion was "a shape rather than a
     * list, so adding a twelfth built-in tool strengthens it instead of reding
     * it". WHAT IS TRUE NOW: that was wrong for exactly the tools this project
     * would add next. `$survivors` is the census minus everything
     * `fnmatch('[!B]*', …)` matches, i.e. every name beginning with `B`, so
     * `assertCount(1, …)` is really "exactly one B-named built-in exists".
     * Adding `BashOutput` or `BashBackground` makes it two and REDS THIS TEST.
     * WHY IT STILL EARNS ITS PLACE: that red is correct rather than incidental.
     * README.md's retraction says the counterexample "leaves exactly `Bash` out
     * of the eleven built-in tools", and its launch-report sample says
     * "disabled 10 of the 11" — a twelfth B-named tool falsifies both, so the
     * test and the prose have to move together. The census-derived assertion
     * below pins that sample against the measured count for the same reason.
     *
     * The day someone teaches {@see PermissionRule::matchesToolName()} to
     * refuse negated classes, this reds too — and that is the intent: the
     * README paragraph below would then be describing a hazard that no longer
     * exists, and both have to move together.
     */
    public function testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem(): void
    {
        $ceiling = $this->toolCeiling();
        self::assertGreaterThan(2, count($ceiling), 'the tool census is too small to say anything');

        $removed = array_values(array_filter(
            $ceiling,
            static function (string $name): bool {
                return PermissionRule::matchesToolName(self::COUNTEREXAMPLE_GLOB, $name);
            },
        ));
        $survivors = array_values(array_diff($ceiling, $removed));

        self::assertCount(1, $survivors, 'the counterexample no longer isolates a single tool');
        self::assertCount(count($ceiling) - 1, $removed);

        // THIS BLOCK USED TO BE a loop asserting that no removed tool's name is
        // a substring of `[!B]*`, commented "the whole of the claim". WHAT IS
        // TRUE NOW: that assertion could not fail. Both operands are fixed with
        // respect to the code under test — the haystack is a class constant, and
        // the only substrings of `[!B]*` that could ever be a tool name are the
        // ones beginning with `B`, which are precisely the names
        // `fnmatch('[!B]*', …)` does NOT match and so can never be in
        // `$removed`. It was 10 of this file's 25 assertions and pinned nothing.
        //
        // WHY THE PROPERTY STILL EARNS AN ASSERTION, in a form that can fail:
        // what the retracted README sentence promised is that reading the value
        // tells you what it takes away. It does not, and the reason is not that
        // the string happens to be short — it is that the value denotes an
        // UNBOUNDED set. The same five characters match names that do not exist
        // yet, so no reader and no reviewer can enumerate the removals from the
        // value alone; only the census can. Teach
        // {@see PermissionRule::matchesToolName()} to compare literally, or to
        // refuse negated classes, and every one of these goes false.
        foreach (['Zzz', 'a-tool-added-next-year', 'mcp__git__status'] as $neverBuilt) {
            self::assertNotContains($neverBuilt, $ceiling, 'this probe name has become a real tool');
            self::assertTrue(
                PermissionRule::matchesToolName(self::COUNTEREXAMPLE_GLOB, $neverBuilt),
                'the deny value no longer removes names outside the census, so it names a set '
                . 'an operator could read off the file and the old README claim would be true again',
            );
        }

        // The README quotes this census twice — "out of the eleven built-in
        // tools" and a launch-report sample reading "disabled 10 of the 11".
        // Both are figures, so both get a generator rather than a proof-read.
        //
        // THIS BLOCK USED TO SAY "both get a generator" WHILE GENERATING ONE.
        // WHAT IT SAID: the two quotes are covered. WHAT WAS TRUE: only the
        // launch-report sample was asserted; the spelled "eleven" was prose
        // nothing read, so a twelfth built-in tool moved the digits and left
        // the word behind. WHY THE SENTENCE STILL EARNS ITS PLACE: the claim
        // was the right one, it just had no second assertion under it — so the
        // spelled count is derived below rather than the sentence softened.
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());
        // LOUD ON WHAT IT CANNOT SPELL, never silently ''. A const-array miss
        // on PHP 8.3.6 is a `Warning: Undefined array key` evaluating to null,
        // NOT a throw — measured, not assumed — and `'out of the  built-in
        // tools'` is a needle the page will never contain, so the failure
        // would arrive as an unexplained miss rather than as "widen the map".
        self::assertArrayHasKey(
            count($ceiling),
            self::SPELLED_COUNTS,
            'the built-in tool census has moved outside the range self::SPELLED_COUNTS can spell; '
            . 'add the word, and check that README.md now uses it',
        );
        self::assertStringContainsString(
            'out of the ' . self::SPELLED_COUNTS[count($ceiling)] . ' built-in tools',
            $flat,
            "README.md's retraction spells a built-in tool count that is not the measured one",
        );

        // EXACT, NOT CONTAINED, and the difference is not pedantry — it is a
        // survived mutation. `assertStringContainsString($rendered, $flat)` was
        // written first and MEASURED: with {@see Bootstrap::STDERR_LINE_FORMAT}
        // mutated `"sugarcrush: %s.\n"` → `"sugarcrush: %s\n"`, the rendered
        // needle loses its trailing full stop and is then a PREFIX of the
        // page's sample, so the containment passed —
        // `OK (5 tests, 27 assertions)` on PHP 8.3.6. Any mutation that only
        // SHORTENS the envelope is invisible to a containment check. So the
        // fenced sample block is extracted and compared whole.
        self::assertSame(
            $this->launchReportSample($ceiling, $removed),
            $this->launchReportBlockIn('README.md'),
            "README.md's launch-report sample is not what the launcher would print",
        );
    }

    /**
     * THE SECOND PAGE CARRYING THE SAME SAMPLE, and until round 46 nothing read
     * it at all.
     *
     * HOW IT WAS FOUND. E152 repaired README.md's copy of the launch report and
     * the doc-block written for it said the README held "the only other copy of
     * the phrase". That was a claim about a grep, not a measurement, and it was
     * wrong: `docs/SETTINGS.md` fences the identical line and then tells the
     * reader, in prose, "That is the stderr form, byte for byte." MEASURED on
     * PHP 8.3.6, scope = the nine classes in `tests/` that read `SETTINGS.md`:
     * with {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} mutated
     * `disabled` → `removed`, exactly one of them redded, and it was
     * {@see ReadmeRosterDriftTest::testTheLaunchReportSampleIsStillTheLineTheLauncherWouldPrint()}
     * reading README.md. A page that promises byte-for-byte agreement and is
     * checked by nothing is worse than one that paraphrases, because its
     * promise is what stops the next reader from checking it by hand.
     *
     * RENDERED FROM THE SAME HELPER AS THE README ASSERTION, deliberately, and
     * this is NOT the render-from-the-code-under-test tautology the backlog
     * records for this round. The tautology is when expectation and actual both
     * come from `src/`. Here the actual is a MARKDOWN PAGE — a byte string no
     * part of the launcher can move — so rendering the expectation from the
     * launcher's constants is exactly what makes the two parties independent.
     *
     * THE PATH FIELD IS THE PAGE'S OWN EXAMPLE, `/repo/…`, which is why
     * {@see launchReportSample()} substitutes it rather than measuring it. Both
     * pages chose the same example; if one changes it, this reds and the answer
     * is to make them agree rather than to soften the assertion.
     */
    public function testTheSettingsPageQuotesTheSameLaunchReportByteForByte(): void
    {
        $ceiling = $this->toolCeiling();

        $removed = array_values(array_filter(
            $ceiling,
            static function (string $name): bool {
                return PermissionRule::matchesToolName(self::COUNTEREXAMPLE_GLOB, $name);
            },
        ));

        self::assertNotSame([], $removed, 'the counterexample removed nothing, so there is no sample to check');

        self::assertSame(
            $this->launchReportSample($ceiling, $removed),
            $this->launchReportBlockIn('docs/SETTINGS.md'),
            "docs/SETTINGS.md calls its launch-report sample the stderr form byte for byte, and it is not "
            . 'what the launcher would print',
        );
    }

    /**
     * The one fenced block in `$relativePath` that shows the launch-report
     * line, flattened the same way {@see launchReportSample()} flattens its
     * render.
     *
     * IT READS ANY PAGE, and the header above said "README.md" for as long as
     * README.md was the only one. `docs/SETTINGS.md` carries the identical
     * sample — see
     * {@see testTheSettingsPageQuotesTheSameLaunchReportByteForByte()} — so
     * every "the README" below means "the page this call was handed".
     *
     * EXACTLY ONE IS ASSERTED. A second block carrying `(disabledTools)` would
     * make "the sample" ambiguous, and picking the first would silently stop
     * checking the other — the shape of hole rule 14 is about.
     *
     * EVERY FENCE, NOT ONLY A `text`-TAGGED ONE, AND THE OLD ALPHABET IS WHY.
     * WHAT IT SAID: scan for `text`-tagged fences only and assert one hit, on
     * the reasoning that a second sample would make "the sample" ambiguous.
     * WHAT IS TRUE NOW, MEASURED on PHP 8.3.6 at round 46: that assertion could
     * not SEE a second sample unless it happened to carry that one tag.
     * Appending to README.md a BARE-fenced block reading `sugarcrush: …
     * (disabledTools) disabled 99 of the 3 tools your own settings left — Nope
     * — leaving: Nope.` left the full suite green and its totals unchanged to
     * the assertion. README.md already carries bare-fenced blocks, so the shape
     * was live rather than hypothetical. WHY THIS STILL EARNS ITS PLACE: the
     * reasoning above was right and only its alphabet was wrong — rule 11, an
     * instrument reporting zero because of what it cannot express.
     *
     * THE FENCES ARE COUNTED BEFORE THEY ARE PAIRED, because a widened opener
     * can also mis-pair: if some block's body held a line opening with three
     * backticks, that inner line would close its parent early and every later
     * block would be offset by one, quietly. The regex yields one match per
     * PAIR, so `2 × matches === fence lines` is the parse check. It fails loudly
     * on a README this extractor cannot read, rather than comparing against
     * whatever a shifted pairing happened to select.
     */
    private function launchReportBlockIn(string $relativePath): string
    {
        $page = $this->document($relativePath);

        preg_match_all('/^```[^\n]*\n(.*?)^```/ms', $page, $blocks);

        self::assertSame(
            preg_match_all('/^```/m', $page),
            2 * count($blocks[1]),
            "{$relativePath} has an odd or nested run of ``` fences, so this extractor cannot say which "
            . 'lines belong to which block; the launch-report sample it would compare is not trustworthy',
        );

        $samples = array_values(array_filter(
            $blocks[1],
            static function (string $block): bool {
                return str_contains($block, '(disabledTools)');
            },
        ));

        self::assertCount(
            1,
            $samples,
            "{$relativePath} no longer holds exactly one fenced block showing the (disabledTools) launch "
            . 'report; this guard cannot say which one it is comparing',
        );

        return trim((string) preg_replace('/\s+/', ' ', $samples[0]));
    }

    /**
     * The digit-to-word map the README's retraction paragraph is written in.
     *
     * Deliberately SHORT: a census outside this range is a tree this file has
     * never seen, and {@see testOneShortDenyGlobRemovesEveryToolButOneWithoutNamingAnyOfThem()}
     * asserts the key is present BEFORE reading it, so the answer is "widen this
     * map" rather than a containment miss on a needle with a hole in it.
     *
     * @var array<int, string>
     */
    private const SPELLED_COUNTS = [
        9 => 'nine',
        10 => 'ten',
        11 => 'eleven',
        12 => 'twelve',
        13 => 'thirteen',
    ];

    /**
     * The launch-report line README.md prints as its sample, RENDERED FROM THE
     * LAUNCHER'S OWN FORMATS rather than retyped here.
     *
     * WHY THIS IS THE WHOLE POINT OF E118 HAVING PROMOTED THEM. This file used
     * to retype `sprintf('disabled %d of the %d tools your own settings left',
     * …)` and compare that to the page. MEASURED in round 45: with
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} mutated
     * `disabled`→`removed`, neither
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapLaunchNoticeRoutingTest} nor
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapToolAndPermissionSettingsTest}
     * reds, and this file held the only other copy of the phrase in `tests/` —
     * so the copy that was supposed to catch the drift drifted WITH it, in the
     * one direction that leaves the README describing a line the launcher no
     * longer prints. A promoted constant the checker does not read is a second
     * copy with a nicer name.
     *
     * FOUR CONSTANTS AND NOT ONE, because the sample carries the whole
     * envelope: {@see Bootstrap::STDERR_LINE_FORMAT} contributes the
     * `sugarcrush: ` prefix and the trailing full stop,
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT} the body, and
     * {@see Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING} the survivor clause.
     * The path is the README's own illustrative one — it is a sample, not a
     * capture — and everything else comes from the measured census.
     *
     * WHITESPACE IS FLATTENED ON BOTH SIDES. The page wraps this sample across
     * three lines inside a fenced block, so a raw containment would fail on the
     * line breaks alone; the same flattening the README guards below use.
     *
     * @param list<string> $ceiling
     * @param list<string> $removed
     */
    private function launchReportSample(array $ceiling, array $removed): string
    {
        $line = sprintf(
            Bootstrap::STDERR_LINE_FORMAT,
            sprintf(
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_FORMAT,
                '/repo/' . LayeredSettings::SHARED_PATH,
                count($removed),
                count($ceiling),
                implode(', ', $removed),
                Bootstrap::PROJECT_TIER_TOOL_REMOVAL_LEAVING
                    . implode(', ', array_values(array_diff($ceiling, $removed))),
            ),
        );

        return trim((string) preg_replace('/\s+/', ' ', $line));
    }

    /**
     * Fragments of the retracted auditability sentence, any ONE of which is
     * enough to identify a line as asserting it. Three rather than one because
     * the sentence is re-wrapped every time the paragraph around it is edited,
     * and a single 28-character needle is evaded by a line break landing inside
     * it.
     */
    private const RETRACTED_AUDITABILITY_FRAGMENTS = [
        'naming every tool',
        'every tool it removes',
        'a value you can see',
    ];

    /**
     * THE PROSE. Wherever README.md still quotes the retracted sentence — and it
     * must, {@see \SugarCraft\Crush\Config\LayeredSettings} keeps the retraction
     * rather than deleting it, because a reader who finds only the conclusion
     * deletes the guard — it has to be visibly A QUOTE, and the counterexample
     * has to be within reach of it.
     *
     * THE STRUCTURAL RULE IS THE REAL ASSERTION, and it is here because the
     * proximity rule alone SURVIVED ITS MUTATION. Restoring the false sentence
     * verbatim as body prose — "…available to a trusted project, because
     * expressing the same attack there means naming every tool it removes — a
     * value you can see." — immediately above the retraction left the window
     * check GREEN: measured on the corrected file, `[!B]*` sits +600 characters
     * from the quote and "That is false" +57, so ~1400 characters of the
     * 2000-character window are slack in front of the retraction, and any
     * restored occurrence inside that slack simply inherits the retraction's own
     * counterexample. The window, not the mutation, was wrong — the same defect
     * {@see testTheWordDeprecatedAppearsInTheReadmeOnlyInsideTheQuoteThatRetractsIt()}
     * already had to solve, and it is solved the same way: the sentence may
     * appear only on a `>` line. Body prose is what a reader skims and believes;
     * a block quote is visibly an aside about a sentence that used to be there.
     *
     * THE WINDOW IS KEPT AS WELL, narrowed to the quoted occurrences. It answers
     * a different question — that the retraction still carries its
     * counterexample rather than having drifted into a bare assertion — and a
     * `[!B]*` sitting in some unrelated section three pages down would satisfy a
     * whole-file contains() while the paragraph an operator actually reads
     * carried the bare false claim.
     */
    public function testWhereverTheReadmeQuotesTheRetractedClaimTheCounterexampleIsRightThere(): void
    {
        $readme = $this->readme();
        $quote = 'naming every tool it removes';

        self::assertStringContainsString(
            $quote,
            $readme,
            'README.md dropped the retracted claim instead of correcting it in place',
        );

        // PER PARAGRAPH, NOT PER LINE. A per-line scan is evaded by nothing more
        // deliberate than a re-wrap: fold the sentence so that "naming every
        // tool it removes" straddles a line break and no single line carries a
        // whole fragment. Markdown paragraphs are the unit a reader actually
        // reads, so each is flattened to one whitespace-normalised string first
        // and only then searched.
        $offenders = [];
        $quotedParagraphs = 0;
        $paragraph = [];
        $paragraphStart = 1;
        $lines = explode("\n", $readme);
        $lines[] = ''; // sentinel so the final paragraph is flushed by the loop

        foreach ($lines as $index => $line) {
            if (trim($line) !== '') {
                if ($paragraph === []) {
                    $paragraphStart = $index + 1;
                }
                $paragraph[] = $line;

                continue;
            }
            if ($paragraph === []) {
                continue;
            }

            $flat = (string) preg_replace('/\s+/', ' ', implode(' ', $paragraph));
            $carries = false;
            foreach (self::RETRACTED_AUDITABILITY_FRAGMENTS as $fragment) {
                if (str_contains($flat, $fragment)) {
                    $carries = true;

                    break;
                }
            }
            if ($carries) {
                $unquoted = array_values(array_filter(
                    $paragraph,
                    static function (string $one): bool {
                        return !str_starts_with(ltrim($one), '>');
                    },
                ));
                if ($unquoted === []) {
                    ++$quotedParagraphs;
                } else {
                    $offenders[] = $paragraphStart . ': ' . trim($unquoted[0]);
                }
            }
            $paragraph = [];
        }

        self::assertSame(
            [],
            $offenders,
            'README.md asserts the retracted disabledTools auditability claim as body prose. It is false: '
            . '`Bootstrap::filterToolSet()` matches through `PermissionRule::matchesToolName()`, which is bare '
            . '`fnmatch()`, so `[!B]*` removes an unbounded set and names none of it. If the sentence has to '
            . 'be restated, restate it inside the `>` retraction that disproves it',
        );
        self::assertGreaterThan(
            0,
            $quotedParagraphs,
            'the retraction quote is gone; it records what this section used to claim and why it was wrong',
        );

        $offset = 0;
        $seen = 0;
        while (($at = strpos($readme, $quote, $offset)) !== false) {
            ++$seen;
            $window = substr($readme, $at, 2000);
            self::assertStringContainsString(
                self::COUNTEREXAMPLE_GLOB,
                $window,
                'README.md states the retracted disabledTools claim without the counterexample that disproves it',
            );
            self::assertStringContainsString(
                'That is false',
                $window,
                'README.md quotes the retracted claim without marking it retracted',
            );
            $offset = $at + 1;
        }

        self::assertGreaterThan(0, $seen);
    }

    // -------------------------------------------------------------------------
    // E75 — "the deprecated name"
    // -------------------------------------------------------------------------

    /**
     * THE SOURCE TRUTH BEHIND THE REWRITE: the file the CLI writes is
     * `config.json`, and the hand-authored `settings.json` is a different file
     * that never receives a write. That is the entire reason `config.json`
     * outranks `settings.json`, so if it ever stops being true the README's
     * ranking paragraph is wrong again — from the other direction.
     */
    public function testTheFileTheCliWritesIsStillConfigJsonAndIsNotTheHandAuthoredOne(): void
    {
        self::assertSame('config.json', basename(Bootstrap::userConfigPath()));
        self::assertNotSame(
            LayeredSettings::USER_FILE,
            basename(Bootstrap::userConfigPath()),
            'the written file and the hand-authored layer-3 file have become the same name',
        );
    }

    /**
     * "Nothing in `src/` marks it deprecated" is a claim about the tree, so it
     * is scanned rather than believed. A doc-block that both mentions
     * `config.json` and carries `@deprecated` is the one shape that would make
     * the README's retracted wording defensible again.
     *
     * Scanned per DOC-BLOCK, not per file: `src/Chat.php` and
     * `src/Agents/PathJail.php` each carry a legitimate `@deprecated` on an
     * unrelated alias, and a per-file check would red on those the moment
     * either file mentioned a config path.
     */
    public function testNoDocBlockInSourceMarksConfigJsonDeprecated(): void
    {
        $root = realpath(__DIR__ . '/../../src');
        self::assertIsString($root);

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (!str_contains($source, '@deprecated')) {
                continue;
            }
            preg_match_all('#/\*\*.*?\*/#s', $source, $blocks);
            foreach ($blocks[0] as $block) {
                if (str_contains($block, '@deprecated') && str_contains($block, 'config.json')) {
                    $offenders[] = $file->getFilename();
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            'a doc-block now deprecates config.json; README.md and LayeredSettings both say nothing does',
        );
    }

    /**
     * THE PROSE. The word "deprecated" appears in README.md only inside the
     * block quote that RETRACTS it — a structural rule rather than a
     * proximity one, and the structure is the whole point: body prose is what
     * a reader skims and believes, a `>` quote is visibly an aside about a
     * sentence that used to be there.
     *
     * A PROXIMITY RULE WAS TRIED FIRST AND IS RECORDED HERE BECAUSE IT
     * SURVIVED ITS MUTATION. The first cut asked that every occurrence of
     * "deprecated" have a `\bnot\b` within 500 characters either side. Restoring
     * the false sentence verbatim — "`config.json` beats `settings.json`
     * despite being the deprecated name" — left that assertion GREEN, because
     * the settings-file table three lines above contains "which is not a trust
     * signal", about an entirely different key. The window, not the mutation,
     * was wrong: any window wide enough to reach this file's own retraction is
     * wide enough to reach a `not` that has nothing to do with it.
     */
    public function testTheWordDeprecatedAppearsInTheReadmeOnlyInsideTheQuoteThatRetractsIt(): void
    {
        $lines = explode("\n", $this->readme());

        $offenders = [];
        $quoted = 0;
        foreach ($lines as $number => $line) {
            if (stripos($line, 'deprecat') === false) {
                continue;
            }
            if (str_starts_with(ltrim($line), '>')) {
                ++$quoted;

                continue;
            }
            $offenders[] = ($number + 1) . ': ' . trim($line);
        }

        self::assertSame(
            [],
            $offenders,
            'README.md uses "deprecated" outside the retraction quote; `config.json` is the OLDER of the '
            . 'two settings-file names, not a deprecated one, and it is the only one the CLI ever writes',
        );
        self::assertGreaterThan(
            0,
            $quoted,
            'the retraction quote is gone; it records what this section used to claim and why it was wrong',
        );
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        /** @var \SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() && !$entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($dir);
    }
}
