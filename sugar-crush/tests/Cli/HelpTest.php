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

        // Env vars section
        $this->assertStringContainsString('SUGARCRUSH_PROVIDER', $screen);
        $this->assertStringContainsString('SUGARCRUSH_MODEL', $screen);
        $this->assertStringContainsString('SUGARCRUSH_BACKEND_CMD', $screen);
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
