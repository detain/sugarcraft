<?php

declare(strict_types=1);

namespace CandyCore\Shell\Tests\Command;

use CandyCore\Shell\Command\FormatCommand;
use CandyCore\Shine\Theme;
use PHPUnit\Framework\TestCase;

final class FormatCommandTest extends TestCase
{
    public function testPickThemeAnsi(): void
    {
        $theme = FormatCommand::pickTheme('ansi');
        $this->assertInstanceOf(Theme::class, $theme);
    }

    public function testPickThemePlain(): void
    {
        $theme = FormatCommand::pickTheme('plain');
        $this->assertSame('plain', $theme->paragraph->render('plain'));
    }

    public function testPickThemeCaseInsensitive(): void
    {
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('ANSI'));
    }

    public function testPickThemeRejectsUnknown(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FormatCommand::pickTheme('nightmare');
    }

    public function testPickThemeAcceptsExtendedNames(): void
    {
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('dark'));
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('dracula'));
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('tokyo-night'));
    }

    public function testTypeCodeWrapsInFences(): void
    {
        $cmd = (new \CandyCore\Shell\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        // Use a lighter-weight invocation: write the input to a tmp file,
        // pass via 'file' argument to avoid stdin interference.
        $tmp = tempnam(sys_get_temp_dir(), 'fmt');
        file_put_contents($tmp, "echo hi");
        try {
            $tester->execute([
                'file'    => $tmp,
                '--type'  => 'code',
                '--language' => 'bash',
                '--theme' => 'plain',
            ]);
            $tester->assertCommandIsSuccessful();
            $out = $tester->getDisplay();
            $this->assertStringContainsString('echo hi', $out);
        } finally {
            @unlink($tmp);
        }
    }

    public function testTypeEmojiExpandsShortcodes(): void
    {
        $cmd = (new \CandyCore\Shell\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $tmp = tempnam(sys_get_temp_dir(), 'fmt');
        file_put_contents($tmp, ":candy: :rocket: :unknownNonsense:");
        try {
            $tester->execute([
                'file'   => $tmp,
                '--type' => 'emoji',
            ]);
            $out = $tester->getDisplay();
            $this->assertStringContainsString('🍬', $out);
            $this->assertStringContainsString('🚀', $out);
            // Unknown shortcode passes through verbatim.
            $this->assertStringContainsString(':unknownNonsense:', $out);
        } finally {
            @unlink($tmp);
        }
    }

    public function testTypeTemplateExpandsEnvVars(): void
    {
        putenv('SC_FMT_GREETING=hello');
        try {
            $cmd = (new \CandyCore\Shell\Application())->find('format');
            $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
            $tmp = tempnam(sys_get_temp_dir(), 'fmt');
            file_put_contents($tmp, "{{SC_FMT_GREETING}} world");
            try {
                $tester->execute([
                    'file'   => $tmp,
                    '--type' => 'template',
                ]);
                $out = $tester->getDisplay();
                $this->assertStringContainsString('hello world', $out);
            } finally {
                @unlink($tmp);
            }
        } finally {
            putenv('SC_FMT_GREETING');
        }
    }
}
