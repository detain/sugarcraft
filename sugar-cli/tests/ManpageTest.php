<?php

declare(strict_types=1);

namespace SugarCraft\Cli\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Cli\CommandSpec;
use SugarCraft\Cli\Manpage;
use SugarCraft\Cli\Option;
use SugarCraft\Cli\Tests\Support\SampleSpec;

final class ManpageTest extends TestCase
{
    public function testPageIsPinned(): void
    {
        self::assertStringEqualsFile(__DIR__ . '/fixtures/manpage.golden', Manpage::render(SampleSpec::candyCode()));
    }

    public function testSectionsAppearInOrder(): void
    {
        $page = Manpage::render(SampleSpec::candyCode());

        preg_match_all('/^\.SH (.+)$/m', $page, $matches);
        self::assertSame(['NAME', 'SYNOPSIS', '"OPTIONS"', '"MODEL"', 'COMMANDS', 'ENVIRONMENT'], $matches[1]);
        self::assertStringStartsWith('.TH "CANDY\-CODE" 1 "" "candy\-code" "User Commands"' . "\n", $page);
        self::assertStringContainsString(".SH NAME\ncandy\\-code \\- An agentic coding assistant.\n", $page);
        self::assertStringContainsString(".SH SYNOPSIS\n.B candy\\-code\n[options] <command>\n", $page);
    }

    public function testSectionNumberAndDateFillTheTitleLine(): void
    {
        $page = Manpage::render(CommandSpec::new('tool'), 8, '2026-11-27');

        self::assertSame(".TH \"TOOL\" 8 \"2026\\-11\\-27\" \"tool\" \"\"\n.SH NAME\ntool\n.SH SYNOPSIS\n.B tool\n[<arg>...]\n", $page);
    }

    public function testSectionOutOfRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Manpage::render(CommandSpec::new('tool'), 0);
    }

    public function testCallerTextCannotIssueRequests(): void
    {
        $spec = CommandSpec::new('tool')
            ->withDescription("x\n.SH INJECTED")
            ->withOption(Option::flag('f')->withDescription(".so /etc/passwd\x1b[31m \\fBbold"));

        $page = Manpage::render($spec);

        self::assertDoesNotMatchRegularExpression('/^\.SH INJECTED/m', $page);
        self::assertDoesNotMatchRegularExpression('/^\.so/m', $page);
        self::assertStringContainsString("\\&.so /etc/passwd \\efBbold", $page);
        self::assertStringNotContainsString("\x1b", $page);
    }
}
