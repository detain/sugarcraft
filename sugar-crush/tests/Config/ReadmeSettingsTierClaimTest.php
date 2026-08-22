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
        $path = __DIR__ . '/../../README.md';
        $text = file_get_contents($path);
        self::assertIsString($text, 'README.md is unreadable');

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
        $flat = (string) preg_replace('/\s+/', ' ', $this->readme());
        self::assertStringContainsString(
            sprintf('disabled %d of the %d tools your own settings left', count($removed), count($ceiling)),
            $flat,
            "README.md's launch-report sample no longer matches the measured tool census",
        );
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
