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

    public function testPositiveTimeoutIsLiveNotRefused(): void
    {
        // scriptui-32: a deadline bounds the wait for piped input instead of
        // being refused as inert; text given as an argument never waits, so
        // the run succeeds well inside it ('5s' is the -27 duration grammar).
        $app = new \SugarCraft\ScriptUi\Application();
        $app->setAutoExit(false);
        $out = new \Symfony\Component\Console\Output\BufferedOutput();

        $status = $app->run(new \Symfony\Component\Console\Input\StringInput('format --type emoji --timeout 5s ":tada:"'), $out);

        self::assertSame(0, $status);
        self::assertStringContainsString('🎉', $out->fetch());
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

        // scriptui-53: the type door is exercised end-to-end on the real bin
        // — the refusal sentence lands on captured STDERR with captured
        // STDOUT clean, BEFORE any read. (The old arms grepped the
        // in_array(..., true) and Lang::t spellings out of source.) A file
        // that cannot be opened proves ordering too: the door answers with
        // the type refusal, never the io.read_failed sentence.
        $missing = '/definitely/not/here.phpunit-' . getmypid();
        [$childStatus, $stdout, $stderr] = self::runBinChild(['format', $missing, '--type=bogus']);
        $this->assertSame(1, $childStatus);
        $this->assertSame('', $stdout);
        $this->assertStringContainsString(
            "Invalid value for --type: 'bogus'. Allowed: markdown|code|template|emoji.",
            $stderr,
        );
        $this->assertStringNotContainsString('Could not read file', $stderr,
            'the type door must close before anything is read');
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
        // scriptui-53: the announcement is exercised on the real bin — a
        // vanished placeholder must be NAMED on captured STDERR while the
        // run still succeeds and stdout keeps only the rendered data.
        // (The old arms grepped the key name and the &$unresolved out-param
        // spelling from source.)
        [$status, $stdout, $stderr] = self::runBinChild(
            ['format', '--type=template'],
            'value=[{{SC_53_GONE}}] and [{{SC_53_ALSO_GONE}}]',
        );
        $this->assertSame(0, $status, 'the announcement is a note, not a failure');
        $this->assertStringContainsString('value=[] and []', $stdout);
        $this->assertStringContainsString(
            'Template: 2 placeholder(s) expanded to empty: SC_53_GONE, SC_53_ALSO_GONE',
            $stderr,
        );
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
        // scriptui-53 KEEP (doc-pin): the byte half above is the behaviour
        // test; this leg pins that the documented exception sentence exists
        // beside it — the README claim is part of the contract here.
        $this->assertStringContainsString('bracket residue', $readme);
    }

    /** @return array{0:int,1:string,2:string} [exitCode, stdout, stderr] */
    private static function runBinChild(array $args, string $stdin = ''): array
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('bounded child probes require a POSIX timeout(1)');
        }
        $bin = \dirname(__DIR__, 2) . '/bin/candy-scriptui';
        $proc = proc_open(
            ['timeout', '-s', 'KILL', '20', PHP_BINARY, $bin, ...$args],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            \dirname($bin),
            ['PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
        );
        self::assertIsResource($proc, 'the bin child must spawn');
        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), $stdout, $stderr];
    }
}
