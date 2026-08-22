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
     * value you can see". Asserted as a shape rather than as a list, so adding
     * a twelfth built-in tool strengthens it instead of reding it.
     *
     * The day someone teaches {@see PermissionRule::matchesToolName()} to
     * refuse negated classes, this reds — and that is the intent: the README
     * paragraph below would then be describing a hazard that no longer exists,
     * and both have to move together.
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

        // The whole of the claim: the value an operator would read in the file
        // does not contain the name of anything it takes away.
        foreach ($removed as $name) {
            self::assertStringNotContainsString(
                $name,
                self::COUNTEREXAMPLE_GLOB,
                'the deny value names a tool it removes, so the old README claim would be true again',
            );
        }
    }

    /**
     * THE PROSE. Wherever README.md still quotes the retracted sentence — and it
     * must, {@see \SugarCraft\Crush\Config\LayeredSettings} keeps the retraction
     * rather than deleting it, because a reader who finds only the conclusion
     * deletes the guard — the counterexample has to be within reach of it.
     *
     * THE WINDOW IS THE ASSERTION. 2000 characters forward of the quote, not the
     * whole file: a `[!B]*` sitting in some unrelated section three pages down
     * would satisfy a whole-file contains() while the paragraph an operator
     * actually reads carried the bare false claim. Measured against the current
     * file, the gap from the quote to the glob is well under that.
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
