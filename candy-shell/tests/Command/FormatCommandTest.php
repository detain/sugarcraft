<?php

declare(strict_types=1);

namespace SugarCraft\Shell\Tests\Command;

use SugarCraft\Shell\Command\FormatCommand;
use SugarCraft\Shine\Theme;
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
        $cmd = (new \SugarCraft\Shell\Application())->find('format');
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
        $cmd = (new \SugarCraft\Shell\Application())->find('format');
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
            $out = self::runTemplate('{{SC_FMT_GREETING}} world', 'SC_FMT_GREETING');
            $this->assertStringContainsString('hello world', $out);
        } finally {
            putenv('SC_FMT_GREETING');
        }
    }

    /**
     * Secure-by-default: without --allow-env, no {{VAR}} expands, so a
     * secret in the environment cannot be exfiltrated by an
     * attacker-influenced template body.
     */
    public function testTypeTemplateWithoutAllowlistDoesNotLeakEnv(): void
    {
        putenv('SC_FMT_SECRET=leak-me');
        try {
            $out = self::runTemplate('token={{SC_FMT_SECRET}}', null);
            $this->assertStringNotContainsString('leak-me', $out);
            $this->assertStringContainsString('token=', $out);
        } finally {
            putenv('SC_FMT_SECRET');
        }
    }

    public function testTypeTemplateAllowlistedVarExpands(): void
    {
        putenv('SC_FMT_SECRET=leak-me');
        try {
            $out = self::runTemplate('token={{SC_FMT_SECRET}}', 'SC_FMT_SECRET');
            $this->assertStringContainsString('leak-me', $out);
        } finally {
            putenv('SC_FMT_SECRET');
        }
    }

    public function testTypeTemplateNonMatchingAllowlistDoesNotLeak(): void
    {
        putenv('SC_FMT_SECRET=leak-me');
        try {
            $out = self::runTemplate('token={{SC_FMT_SECRET}}', 'SC_FMT_OTHER');
            $this->assertStringNotContainsString('leak-me', $out);
        } finally {
            putenv('SC_FMT_SECRET');
        }
    }

    public function testTypeTemplateWildcardAllowlistExpands(): void
    {
        putenv('SC_FMT_SECRET=leak-me');
        try {
            $out = self::runTemplate('token={{SC_FMT_SECRET}}', '*');
            $this->assertStringContainsString('leak-me', $out);
        } finally {
            putenv('SC_FMT_SECRET');
        }
    }

    /** Run `format --type template` on $body with an optional --allow-env value. */
    private static function runTemplate(string $body, ?string $allowEnv): string
    {
        $cmd = (new \SugarCraft\Shell\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $tmp = tempnam(sys_get_temp_dir(), 'fmt');
        file_put_contents($tmp, $body);
        try {
            $args = ['file' => $tmp, '--type' => 'template'];
            if ($allowEnv !== null) {
                $args['--allow-env'] = $allowEnv;
            }
            $tester->execute($args);
            return $tester->getDisplay();
        } finally {
            @unlink($tmp);
        }
    }
}
