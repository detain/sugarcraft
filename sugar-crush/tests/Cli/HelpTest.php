<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Cli;

use PHPUnit\Framework\TestCase;
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

        // Env vars section
        $this->assertStringContainsString('SUGARCRUSH_PROVIDER', $screen);
        $this->assertStringContainsString('SUGARCRUSH_MODEL', $screen);
        $this->assertStringContainsString('SUGARCRUSH_BACKEND_CMD', $screen);
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
}
