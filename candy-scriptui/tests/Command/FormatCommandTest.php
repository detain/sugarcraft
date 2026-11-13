<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Tests\Command;

use SugarCraft\ScriptUi\Command\FormatCommand;
use SugarCraft\Markdown\Theme;
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

    public function testGarbageTimeoutRefusesLikeTheSiblings(): void
    {
        // scriptui-76(b): the old (float) cast laundered 'abc' into 0.0 and
        // the door passed — every sibling refuses the same string.
        $app = new \SugarCraft\ScriptUi\Application();
        $app->setAutoExit(false);
        $out = new \Symfony\Component\Console\Output\BufferedOutput();

        $status = $app->run(new \Symfony\Component\Console\Input\StringInput('format --timeout abc'), $out);

        self::assertSame(1, $status);
        self::assertSame('', trim($out->fetch()));
    }

    public function testPositiveTimeoutStillRefusesAsInert(): void
    {
        // The pre-existing "no effect" leg keeps its verdict — and now a
        // real duration spelling ('5s', -27 grammar) reaches it too.
        $app = new \SugarCraft\ScriptUi\Application();
        $app->setAutoExit(false);
        $out = new \Symfony\Component\Console\Output\BufferedOutput();

        $status = $app->run(new \Symfony\Component\Console\Input\StringInput('format --timeout 5s'), $out);

        self::assertSame(1, $status);
        self::assertSame('', trim($out->fetch()));
    }

    public function testPickThemeAcceptsExtendedNames(): void
    {
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('dark'));
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('dracula'));
        $this->assertInstanceOf(Theme::class, FormatCommand::pickTheme('tokyo-night'));
    }

    public function testTypeCodeWrapsInFences(): void
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
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
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
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
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
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

    public function testUnknownTypeIsRefusedOnStderrBeforeAnyRead(): void
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $status = $tester->execute(['file' => '# hi', '--type' => 'bogus']);
        $this->assertSame(1, $status);
        // The refusal is a diagnostic: the data stream must stay completely clean.
        $this->assertSame('', $tester->getDisplay());
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/FormatCommand.php');
        $this->assertStringContainsString("in_array(\$type, self::TYPES, true)", $src);
        $this->assertStringContainsString("Lang::t('runtime.unknown_choice'", $src);
    }

    public function testUnknownThemeIsRefusedOnStderr(): void
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $status = $tester->execute(['file' => '# hi', '--theme' => 'nightmare']);
        $this->assertSame(1, $status);
        $this->assertSame('', $tester->getDisplay());
    }

    public function testPositionalTextIsFormattedWhenNotAnExistingFile(): void
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $tester->execute(['file' => '# hi', '--theme' => 'plain']);
        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('hi', $tester->getDisplay());
    }

    public function testZeroValuedEnvVarSurvivesTemplateExpansion(): void
    {
        putenv('SC_FMT_ZERO=0');
        try {
            $out = self::runTemplate('{{SC_FMT_ZERO}}|', 'SC_FMT_ZERO');
        } finally {
            putenv('SC_FMT_ZERO');
        }
        $this->assertStringContainsString('0|', $out,
            'a literal "0" is data, not an unset variable');
    }

    public function testUnresolvedPlaceholdersAreAnnouncedNotSwallowed(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../src/Command/FormatCommand.php');
        $this->assertStringContainsString('format.template_unresolved', $src);
        $this->assertStringContainsString('&$unresolved', $src);
    }

    public function testControlByteResidueMatchesDocumentedContract(): void
    {
        $cmd = (new \SugarCraft\ScriptUi\Application())->find('format');
        $tester = new \Symfony\Component\Console\Tester\CommandTester($cmd);
        $tester->execute(['file' => "x \x1b[5mY \x07Z", '--theme' => 'plain']);
        $tester->assertCommandIsSuccessful();
        $display = $tester->getDisplay();

        // scriptui-77 contract: the markdown renderer drops control BYTES
        // but leaves visible bracket residue (\x1b[5m arrives as "[5m").
        // The README documents the exception; this pin keeps both halves
        // honest — bytes gone, residue present, sentence on disk.
        $this->assertStringNotContainsString("\x1b", $display);
        $this->assertStringNotContainsString("\x07", $display);
        $this->assertStringContainsString('[5m', $display);

        $readme = (string) file_get_contents(__DIR__ . '/../../README.md');
        $this->assertStringContainsString('bracket residue', $readme);
    }
}
