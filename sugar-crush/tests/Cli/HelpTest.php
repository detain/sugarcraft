<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Cli\Help;

final class HelpTest extends TestCase
{
    public function testScreenReturnsNonEmptyString(): void
    {
        $screen = Help::screen();

        $this->assertIsString($screen);
        $this->assertNotEmpty($screen);
    }

    public function testScreenContainsKeyFlagStrings(): void
    {
        $screen = Help::screen();

        // One-shot prompt flag
        $this->assertStringContainsString('-p', $screen);
        $this->assertStringContainsString('--prompt', $screen);

        // Output format flag
        $this->assertStringContainsString('--output-format', $screen);
        $this->assertStringContainsString('json', $screen);

        // Help flags
        $this->assertStringContainsString('--help', $screen);
        $this->assertStringContainsString('-h', $screen);

        // Root flag. bin/sugarcrush's unknown-flag error tells the user to
        // "Try `sugarcrush --help` for the list of supported options", which
        // made this omission load-bearing: the screen it points at has to
        // actually list every option ArgvParser accepts.
        $this->assertStringContainsString('--root', $screen);

        // Env vars section. Kept for the smoke value, but NOT the guard: a
        // prefix match cannot tell SUGARCRUSH_BACKEND_CMD from
        // SUGARCRUSH_BACKEND_CMD_STREAM, which is how the latter's whole block
        // shipped unpinned. See
        // testEveryBackendSelectionVariableIsDocumentedOnTheHelpScreen().
        $this->assertStringContainsString('SUGARCRUSH_PROVIDER', $screen);
        $this->assertStringContainsString('SUGARCRUSH_MODEL', $screen);
        $this->assertStringContainsString('SUGARCRUSH_BACKEND_CMD', $screen);
        $this->assertStringContainsString('SUGARCRUSH_BACKEND_CMD_STREAM', $screen);
    }

    /**
     * Read the flag literals straight out of {@see ArgvParser}'s source and
     * require each one to be documented.
     *
     * Scraping the source rather than restating a hand-kept list is the
     * point: `--root` shipped recognised-but-undocumented precisely because
     * nothing tied the two files together, and a hand-kept list here would
     * have been just as easy to forget to update. A new flag in the parser
     * now fails this test until it is added to the screen.
     *
     * @return array<string, array{0: string}>
     */
    public static function flagsRecognizedByTheParser(): array
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/ArgvParser.php');

        $flags = [];
        // `$arg === '--foo'` / `$arg === '-p'` / `$arg === '--'`
        \preg_match_all("/\\\$arg === '(--?[a-z-]*)'/", $source, $exact);
        // `str_starts_with($arg, '--foo=')` — the inline-value form of a flag
        // whose bare form may not appear as an `===` comparison at all.
        \preg_match_all("/str_starts_with\\(\\\$arg, '(--[a-z-]+)='\\)/", $source, $valued);

        foreach ([...$exact[1], ...$valued[1]] as $flag) {
            $flags[$flag] = [$flag];
        }

        \ksort($flags);

        return $flags;
    }

    /**
     * @dataProvider flagsRecognizedByTheParser
     */
    public function testEveryFlagTheParserRecognizesIsDocumented(string $flag): void
    {
        // Anchored to the start of an option line so that a bare substring
        // match cannot pass -- '--' occurs inside '--help', and '-p' inside
        // '--prompt'. The trailing class allows "-p," (paired short form),
        // "--root <dir>" and a flag alone at end of line.
        $pattern = '/^ +(?:-[a-z], )?' . \preg_quote($flag, '/') . '(?:[ =,]|$)/m';

        $this->assertMatchesRegularExpression(
            $pattern,
            Help::screen(),
            "ArgvParser recognizes {$flag} but Help::screen() does not document it"
        );
    }

    public function testTheParserFlagScrapeActuallyFoundTheKnownFlags(): void
    {
        // Guards the test above from silently passing on an empty set if the
        // parser's source layout ever changes shape.
        $scraped = \array_keys(self::flagsRecognizedByTheParser());

        $this->assertContains('--root', $scraped);
        $this->assertContains('--prompt', $scraped);
        $this->assertContains('--output-format', $scraped);
        $this->assertContains('--', $scraped);
        $this->assertContains('-p', $scraped);
        $this->assertContains('-h', $scraped);
        $this->assertContains('--help', $scraped);
    }

    /**
     * The environment-variable analogue of {@see flagsRecognizedByTheParser()}:
     * read the backend-selection variables straight out of {@see Bootstrap}'s
     * own selection methods and require each one to be named on the help screen.
     *
     * WHY THIS EXISTS. `SUGARCRUSH_BACKEND_CMD_STREAM` shipped with a nine-line
     * block in `--help` that NOTHING pinned: deleting the entire block left this
     * file green, because the only assertion in the neighbourhood was
     * `assertStringContainsString('SUGARCRUSH_BACKEND_CMD', $screen)` and the
     * OLDER variable's line satisfies it. A prefix cannot tell two variables
     * apart, so that assertion was blind to the newer one by construction —
     * presence of a substring, not truth of a claim.
     *
     * WHY IT SCRAPES INSTEAD OF LISTING. A hand-written list here would be
     * exactly as blind to the NEXT variable as the assertion above was to this
     * one. The names come from the source of the four methods that actually
     * decide and label the tier, so a fifth variable added to any of them is
     * documented or red.
     *
     * SCOPE, stated so it is not mistaken for more than it is. This guards the
     * BACKEND-SELECTION variables only — the subset the help screen's own
     * "Environment variables" section exists to cover for that purpose:
     * `SUGARCRUSH_PROVIDER`, `SUGARCRUSH_MODEL`, `SUGARCRUSH_BACKEND_CMD` and
     * `SUGARCRUSH_BACKEND_CMD_STREAM`. MEASURED on this tree: `src/` and `bin/`
     * name 20 distinct `SUGARCRUSH_*` variables and `Help.php` documents 6 of
     * them — the four above plus `SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS` and
     * `SUGARCRUSH_PARALLEL_TOOL_DEADLINE`. (Five before this bundle added the
     * streaming variable, which is the figure the review that prompted this
     * guard quotes.) The other 14 — `SUGARCRUSH_TITLE_MODEL`,
     * `SUGARCRUSH_SUMMARY_MODEL`, `SUGARCRUSH_MAX_COST`,
     * `SUGARCRUSH_PERMISSION_MODE`, `SUGARCRUSH_SEARCH_ENDPOINT`,
     * `SUGARCRUSH_SESSION_RETENTION_DAYS`, `SUGARCRUSH_CONNECT_TIMEOUT`,
     * `SUGARCRUSH_BACKGROUND`, `SUGARCRUSH_DEBUG_SKILLS`,
     * `SUGARCRUSH_TOOL_CALL_PARSER`, `SUGARCRUSH_DISABLE_MOUSE`,
     * `SUGARCRUSH_DISABLE_MOUSE_CLICKS`, `SUGARCRUSH_WORKTREES_DIR` and
     * `SUGARCRUSH_SHARE_UPLOAD_URL` — are undocumented on this screen and are a
     * KNOWN, separately tracked gap in the hardening backlog. Widening this
     * scrape to all 20 would red immediately, which is that backlog item's job
     * and not this guard's. `docs/ENVIRONMENT.md` is the page that does claim to
     * cover all of them, and does: all 20 appear there.
     *
     * @return array<string, array{0: string}>
     */
    public static function backendSelectionVariables(): array
    {
        $source = (string) \file_get_contents(\dirname(__DIR__, 2) . '/src/Cli/Bootstrap.php');
        $lines = \explode("\n", $source);

        $vars = [];
        // Reflection gives the BODY's line span, which excludes the docblock —
        // so a variable merely NAMED in prose above a method is not scraped as
        // one the method reads. That matters: `backend()`'s docblock mentions
        // `$OPENAI_API_KEY`, which this screen has no business documenting.
        foreach ([
            'backend',                         // tiers 1-3, the selection itself
            'selectedProviderName',            // tier 1 vs tier 4, for the label
            'selectedProviderLabel',           // the model override on the label
            'backendCommandTierIsSelected',    // the two shell-out tiers, by name
        ] as $method) {
            $reflected = new \ReflectionMethod(Bootstrap::class, $method);
            $body = \implode("\n", \array_slice(
                $lines,
                $reflected->getStartLine() - 1,
                $reflected->getEndLine() - $reflected->getStartLine() + 1,
            ));

            \preg_match_all("/'(SUGARCRUSH_[A-Z0-9_]+)'/", $body, $found);
            foreach ($found[1] as $var) {
                $vars[$var] = [$var];
            }
        }

        \ksort($vars);

        return $vars;
    }

    /**
     * @dataProvider backendSelectionVariables
     */
    public function testEveryBackendSelectionVariableIsDocumentedOnTheHelpScreen(string $var): void
    {
        $section = self::environmentSectionOfTheScreen();

        // The variable has to be named in the ENVIRONMENT VARIABLES section, not
        // merely somewhere on the screen: the exit-code prose names
        // $SUGARCRUSH_PROVIDER too, and a variable documented only there is not
        // documented as a variable.
        $this->assertStringContainsString(
            $var,
            $section,
            "Bootstrap's backend selection reads {$var} but Help::screen()'s"
                . ' "Environment variables" section does not name it',
        );
    }

    public function testTheBackendSelectionVariableScrapeActuallyFoundTheKnownVariables(): void
    {
        // Guards the test above from passing vacuously if Bootstrap's selection
        // methods are renamed or restructured: an empty provider would document
        // nothing and assert nothing.
        $scraped = \array_keys(self::backendSelectionVariables());

        $this->assertContains('SUGARCRUSH_PROVIDER', $scraped);
        $this->assertContains('SUGARCRUSH_BACKEND_CMD', $scraped);
        $this->assertContains('SUGARCRUSH_BACKEND_CMD_STREAM', $scraped);
        $this->assertContains('SUGARCRUSH_MODEL', $scraped);
    }

    /**
     * The "Environment variables:" block of the screen, up to the next
     * top-level heading.
     */
    private static function environmentSectionOfTheScreen(): string
    {
        $screen = Help::screen();
        $start = \strpos($screen, 'Environment variables:');
        self::assertNotFalse($start, 'the screen has no "Environment variables:" section at all');

        $rest = \substr($screen, $start);
        $end = \strpos($rest, "\nExit codes");

        return $end === false ? $rest : \substr($rest, 0, $end);
    }

    public function testScreenContainsUsageExamples(): void
    {
        $screen = Help::screen();

        $this->assertStringContainsString('Usage:', $screen);
        // `run` is a positional-prompt alias for -p "<prompt>" (spec: crush_feat.md
        // section 2 E5 lines 273/288) -- not a `.crush` script interpreter, so pin
        // the exact wording rather than a bare 'run' substring match.
        $this->assertStringContainsString('sugarcrush run "<prompt>"        Alias for -p "<prompt>" (one-shot mode)', $screen);
    }

    /**
     * The exit-2 list has to name BOTH sources a provider selection can come
     * from. Listing only `$SUGARCRUSH_PROVIDER` sent an operator whose run
     * failed over a persisted Ctrl+P "Switch model" choice looking for a
     * variable nothing had set — the same omission the stderr hint carried.
     */
    public function testScreenDocumentsBothProviderSelectionSourcesInTheExitCodeList(): void
    {
        $screen = Help::screen();

        $this->assertStringContainsString('Exit codes (one-shot mode):', $screen);
        $this->assertStringContainsString('$SUGARCRUSH_PROVIDER', $screen);
        $this->assertStringContainsString('~/.sugar-crush/config.json', $screen);
        $this->assertStringContainsString('Switch model', $screen);
        // "no prompt given" moved from 1 to 2 with the rest of the
        // "nothing was attempted" causes; the screen must not still promise 1.
        $this->assertStringContainsString('no prompt given, an', $screen);
    }

    public function testScreenPointsAtReadme(): void
    {
        $screen = Help::screen();

        $this->assertStringContainsString('README', $screen);
        $this->assertStringContainsString('github.com/detain/sugarcraft', $screen);
    }

    /**
     * The unknown-flag error tells the user to "Try `sugarcrush --help` for
     * the list of supported options", so every flag ArgvParser accepts has to
     * be listed here — `--version` included, or it is a supported option the
     * documented discovery path never mentions.
     */
    public function testScreenListsTheVersionFlag(): void
    {
        $screen = Help::screen();

        $this->assertStringContainsString('--version', $screen);
        $this->assertStringContainsString('-v,', $screen);
    }

    public function testScreenDoesNotContainTuiRenderingCode(): void
    {
        $screen = Help::screen();

        // This is plain text output, not a TUI component.
        // It should not contain ANSI escape codes (TUI rendering).
        $this->assertStringNotContainsString("\x1b[", $screen);
        // It should not contain PHP opening tags.
        $this->assertStringNotContainsString('<?', $screen);
        // It should not contain printf-style format placeholders (implies
        // missed sprintf() interpolation).
        $this->assertStringNotContainsString('%s', $screen);
    }

    // -------------------------------------------------------------------------
    // --version (crush_code.md Phase 4 item 3)
    // -------------------------------------------------------------------------

    public function testVersionNamesTheBinaryAndEndsWithASingleNewline(): void
    {
        $version = Help::version();

        $this->assertStringStartsWith('sugarcrush ', $version);
        $this->assertStringEndsWith("\n", $version);
        $this->assertSame(1, substr_count($version, "\n"), '--version is one line');
        $this->assertStringNotContainsString("\x1b[", $version, 'plain text, never a TUI component');
    }

    /**
     * The point of sourcing this from Composer's install metadata rather than
     * a literal: the string has to be SOMETHING specific, and "unknown" means
     * the package could not be found in the installed set — which, running out
     * of this repo's own vendor/, would be a wiring bug.
     */
    public function testVersionStringResolvesFromTheInstalledPackageMetadata(): void
    {
        $resolved = Help::versionString();

        $this->assertNotSame('unknown', $resolved);
        $this->assertNotSame('', $resolved);
    }

    /**
     * A dev checkout carries its commit reference *when Composer knows one*:
     * every checkout since the branch existed reports the same `dev-master`, so
     * the bare version identifies nothing on its own. A tagged install is
     * already exact and is deliberately left undecorated.
     *
     * The precondition is the REFERENCE, not the word "dev", and the difference
     * is a real environment split rather than a hypothetical. Composer guesses
     * the root package's version from VCS only when `COMPOSER_ROOT_VERSION` is
     * unset; with it set, the pretty version comes from the variable and the
     * reference stays null. `.github/workflows/ci.yml` sets it to `dev-master`
     * for the whole workflow, so CI's own install is exactly the referenceless
     * dev case — measured there as a bare `dev-master` — while a local
     * `composer install` in this checkout guesses from git and has a reference.
     * Asserting the decoration on the word "dev" alone is what made the earlier
     * version of this test pass on every developer machine and fail on every CI
     * run, which is a claim that had travelled without its domain.
     *
     * Both arms therefore assert the exact string, not a shape: with a
     * reference, the decoration must be THAT reference's first 7 characters,
     * which the old `[0-9a-f]{7}` pattern would have accepted from any commit.
     */
    public function testDevVersionsCarryACommitReferenceWhenComposerKnowsOne(): void
    {
        $resolved = Help::versionString();

        // Read through the class's own constant so the package name cannot
        // drift between production and this test.
        $package = (new \ReflectionClass(Help::class))->getConstant('PACKAGE');
        self::assertIsString($package);

        $pretty = InstalledVersions::getPrettyVersion($package);
        $reference = InstalledVersions::getReference($package);

        if ($pretty === null || !str_contains($pretty, 'dev')) {
            $this->assertSame($pretty, $resolved, 'a tagged install is left undecorated');
            $this->assertDoesNotMatchRegularExpression('/\([0-9a-f]{7}\)$/', $resolved);

            return;
        }

        if ($reference === null) {
            $this->assertSame(
                $pretty,
                $resolved,
                'with no reference to append there is nothing to decorate with',
            );
            $this->assertDoesNotMatchRegularExpression('/\([0-9a-f]{7}\)$/', $resolved);

            return;
        }

        $this->assertSame($pretty . ' (' . substr($reference, 0, 7) . ')', $resolved);
    }

    /**
     * The environment split above leaves one arm unreachable in any single
     * environment: locally there is always a reference, under CI's
     * `COMPOSER_ROOT_VERSION` there is never one. A test that only runs the arm
     * its own environment permits is not a test of the rule — that is precisely
     * how a bare `dev-master` reached CI while every local run stayed green — so
     * the rule itself is driven through {@see Help::versionStringFor()}, which
     * takes the metadata instead of reading it.
     *
     * Note what is deliberately NOT used here. `InstalledVersions::reload()`
     * looks like the seam for this and is not one: it clears the by-vendor
     * cache, but `getInstalled()` then re-reads `installed.php` from every
     * registered ClassLoader and puts those data sets AHEAD of the reloaded
     * array, so the real reference wins every lookup. Measured — faking
     * `dev-master` with reference `abcdef0…` still returned the real `3f9eac2`.
     * A pure argument has no such back door, and mutates no process global.
     *
     * The fake reference is a recognisable non-SHA whose first 7 characters are
     * `abcdef0`, so a truncation bug cannot coincidentally match the real one.
     *
     * @dataProvider versionDecorationCases
     */
    public function testTheReferenceIsAppendedToDevVersionsAndOnlyToThem(
        ?string $pretty,
        ?string $reference,
        string $expected,
    ): void {
        $this->assertSame($expected, Help::versionStringFor($pretty, $reference));
    }

    /** @return array<string,array{0:?string,1:?string,2:string}> */
    public static function versionDecorationCases(): array
    {
        $fake = 'abcdef0' . str_repeat('9', 33);

        return [
            'dev branch with a reference' => ['dev-master', $fake, 'dev-master (abcdef0)'],
            'dev branch without one'      => ['dev-master', null, 'dev-master'],
            'tagged release is undecorated even with a reference' => ['v1.2.0', $fake, 'v1.2.0'],
            'a dev alias still reads as dev' => ['dev-feature/x', $fake, 'dev-feature/x (abcdef0)'],
            'no version at all'           => [null, $fake, 'unknown'],
            'empty version'               => ['', $fake, 'unknown'],
        ];
    }

    /**
     * And the reader still agrees with the rule on this install, so splitting
     * the rule out cannot drift from what `--version` actually prints.
     */
    public function testTheReaderAgreesWithTheRuleOnThisInstall(): void
    {
        $package = (new \ReflectionClass(Help::class))->getConstant('PACKAGE');
        self::assertIsString($package);

        $this->assertSame(
            Help::versionStringFor(
                InstalledVersions::getPrettyVersion($package),
                InstalledVersions::getReference($package),
            ),
            Help::versionString(),
        );
    }
}
