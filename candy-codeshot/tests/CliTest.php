<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    private static string $phpBinary;
    private static string $binPath;

    public static function setUpBeforeClass(): void
    {
        self::$phpBinary = PHP_BINARY;
        self::$binPath = dirname(__DIR__) . '/bin/candy-codeshot';
        if (!is_executable(self::$binPath) && !file_exists(self::$binPath)) {
            throw new \RuntimeException('CLI bin not found: ' . self::$binPath);
        }
    }

    private function runCli(array $args, ?string $stdin = null, ?string $cwd = null): array
    {
        $cmd = [self::$phpBinary, self::$binPath, ...$args];

        $desc = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        // Pin English output deterministically: the bin now activates the host
        // locale (T::detect), so child runs must not inherit a translated LANG.
        $env = array_merge((array) getenv(), ['LANG' => 'C', 'LC_ALL' => 'C']);
        $handle = proc_open($cmd, $desc, $pipes, $cwd, $env);

        try {
            if ($stdin !== null) {
                fwrite($pipes[0], $stdin);
            }
            fclose($pipes[0]);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);

            $exitCode = proc_close($handle);
            return ['stdout' => $stdout, 'stderr' => $stderr, 'exitCode' => $exitCode];
        } catch (\Throwable $e) {
            // Ensure cleanup on exception
            if (is_resource($pipes[0])) fclose($pipes[0]);
            if (is_resource($pipes[1])) fclose($pipes[1]);
            if (is_resource($pipes[2])) fclose($pipes[2]);
            proc_close($handle);
            throw $e;
        }
    }

    public function testHelpExitsZeroAndPrintsToStdout(): void
    {
        $result = $this->runCli(['--help']);

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('candy-codeshot', $result['stdout']);
        $this->assertStringContainsString('--theme', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testStdinHappyPath(): void
    {
        $result = $this->runCli(['--no-window', '--no-shadow'], "hello\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('<?xml', $result['stdout']);
        $this->assertStringContainsString('<svg', $result['stdout']);
        $this->assertSame('', $result['stderr']);
    }

    public function testFileInput(): void
    {
        $tmp = sys_get_temp_dir() . '/codeshot_test_' . uniqid() . '.txt';
        file_put_contents($tmp, "world\n");

        try {
            // $tmp lives in the system temp dir, outside the CWD, so the path
            // guard requires the --unsafe-paths opt-out.
            $result = $this->runCli(['--no-window', '--no-shadow', '--unsafe-paths', $tmp]);

            $this->assertSame(0, $result['exitCode']);
            $this->assertStringStartsWith('<?xml', $result['stdout']);
            $this->assertStringContainsString('<svg', $result['stdout']);
            $this->assertSame('', $result['stderr']);
        } finally {
            unlink($tmp);
        }
    }

    public function testUnknownFlagExitsTwo(): void
    {
        $result = $this->runCli(['--bogus']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('unrecognised flag', $result['stderr']);
    }

    public function testUnknownThemeExitsTwo(): void
    {
        $result = $this->runCli(['--theme', 'zzz']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('unknown theme', $result['stderr']);
    }

    public function testOutputWritesFileAndNotStdout(): void
    {
        $tmp = sys_get_temp_dir() . '/codeshot_out_' . uniqid() . '.svg';

        try {
            // Temp-dir output is outside the CWD → needs --unsafe-paths.
            $result = $this->runCli(['--no-window', '--no-shadow', '--unsafe-paths', '-o', $tmp], "test\n");

            $this->assertSame(0, $result['exitCode']);
            $this->assertSame('', $result['stdout']);
            $this->assertFileExists($tmp);
            $content = file_get_contents($tmp);
            $this->assertStringStartsWith('<?xml', $content);
            $this->assertStringContainsString('<svg', $content);
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    public function testWriteFailureExitsOne(): void
    {
        // --unsafe-paths bypasses confinement so the write itself is what fails.
        $result = $this->runCli(['--no-window', '--no-shadow', '--unsafe-paths', '-o', '/nonexistent-dir-xyz/candy-codeshot.svg'], "test\n");

        $this->assertSame(1, $result['exitCode']);
        $this->assertStringContainsString('failed to write', $result['stderr']);
    }

    public function testWindowStyleNoneProducesWindowlessSvg(): void
    {
        $result = $this->runCli(['--window-style', 'none', '--no-shadow'], "x\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringStartsWith('<?xml', $result['stdout']);
        $this->assertSame(0, substr_count($result['stdout'], '<circle'));
    }

    public function testLigaturesEmitsLigatureAttribute(): void
    {
        $result = $this->runCli(['--ligatures', '--no-window', '--no-shadow'], "x\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringContainsString('font-variant-ligatures="normal"', $result['stdout']);
    }

    public function testExplicitLanguageHighlightsRawCode(): void
    {
        // Raw PHP with an explicit type flag → keywords picked up the accent.
        $result = $this->runCli(['--no-window', '--no-shadow', '-t', 'php'], "return 0;\n");

        $this->assertSame(0, $result['exitCode']);
        // Dracula pink keyword surfaces as a <text fill> in the SVG.
        $this->assertStringContainsString('fill="#ff79c6"', $result['stdout']);
    }

    public function testAnsiInputIsNotRecoloured(): void
    {
        // Input that already carries ANSI SGR must pass through untouched: the
        // pre-styled green wins, and no keyword-highlight colour is injected.
        $result = $this->runCli(['--no-window', '--no-shadow', '-t', 'php'], "\x1b[32mreturn\x1b[0m 0;\n");

        $this->assertSame(0, $result['exitCode']);
        // ANSI 32 = green #00cd00 is preserved …
        $this->assertStringContainsString('fill="#00cd00"', $result['stdout']);
        // … and the plaintext keyword colour was NOT applied on top.
        $this->assertStringNotContainsString('fill="#ff79c6"', $result['stdout']);
    }

    public function testPlainTextInputIsNotHighlighted(): void
    {
        // Undetectable prose stays plain — no accent colours injected.
        $result = $this->runCli(['--no-window', '--no-shadow'], "hello world\n");

        $this->assertSame(0, $result['exitCode']);
        $this->assertStringNotContainsString('fill="#ff79c6"', $result['stdout']);
    }

    // -------------------------------------------------------------------------
    // Path confinement (--unsafe-paths opt-out) + font signature validation
    // -------------------------------------------------------------------------

    public function testInputInsideCwdResolves(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/in.txt', "hello\n");
            // Relative path, run with the temp dir as CWD → confined, allowed.
            $result = $this->runCli(['--no-window', '--no-shadow', 'in.txt'], null, $dir);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringStartsWith('<?xml', $result['stdout']);
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testInputOutsideCwdRejectedExitsTwo(): void
    {
        $dir     = $this->makeTempDir();
        $outside = $this->makeTempDir();
        try {
            file_put_contents($outside . '/secret.txt', "secret\n");
            $result = $this->runCli(['--no-window', '--no-shadow', $outside . '/secret.txt'], null, $dir);

            $this->assertSame(2, $result['exitCode']);
            $this->assertStringContainsString('outside the current working directory', $result['stderr']);
            $this->assertSame('', $result['stdout']);
        } finally {
            $this->rmTree($dir);
            $this->rmTree($outside);
        }
    }

    public function testTraversalInputRejectedExitsTwo(): void
    {
        $dir     = $this->makeTempDir();
        $outside = $this->makeTempDir();
        try {
            file_put_contents($outside . '/secret.txt', "secret\n");
            // A ../ escape resolves outside the CWD and must be rejected.
            $relative = '../' . basename($outside) . '/secret.txt';
            $result = $this->runCli(['--no-window', '--no-shadow', $relative], null, $dir);

            $this->assertSame(2, $result['exitCode']);
            $this->assertStringContainsString('outside the current working directory', $result['stderr']);
        } finally {
            $this->rmTree($dir);
            $this->rmTree($outside);
        }
    }

    public function testUnsafePathsBypassesConfinement(): void
    {
        $dir     = $this->makeTempDir();
        $outside = $this->makeTempDir();
        try {
            file_put_contents($outside . '/data.txt', "data\n");
            $result = $this->runCli(
                ['--no-window', '--no-shadow', '--unsafe-paths', $outside . '/data.txt'],
                null,
                $dir,
            );

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringStartsWith('<?xml', $result['stdout']);
        } finally {
            $this->rmTree($dir);
            $this->rmTree($outside);
        }
    }

    public function testFontWithValidMagicIsEmbedded(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/in.txt', "x\n");
            file_put_contents($dir . '/good.ttf', "\x00\x01\x00\x00rest-of-a-truetype-file");
            $result = $this->runCli(
                ['--no-window', '--no-shadow', '--font', 'good.ttf', 'in.txt'],
                null,
                $dir,
            );

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('data:font/ttf;base64,', $result['stdout']);
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testFontWithInvalidMagicRejectedExitsTwo(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/in.txt', "x\n");
            file_put_contents($dir . '/bad.ttf', 'this is definitely not a font');
            $result = $this->runCli(
                ['--no-window', '--no-shadow', '--font', 'bad.ttf', 'in.txt'],
                null,
                $dir,
            );

            $this->assertSame(2, $result['exitCode']);
            $this->assertStringContainsString('not a valid font file', $result['stderr']);
        } finally {
            $this->rmTree($dir);
        }
    }

    // -------------------------------------------------------------------------
    // Review fixes (docs-fix-1023): filename-driven detection, option-value
    // refusals, highlight range refusal, PNG honesty, chrome-band geometry
    // -------------------------------------------------------------------------

    public function testFilenameExtensionDrivesHighlightWhereContentCannot(): void
    {
        $dir = $this->makeTempDir();
        try {
            // Content alone detects 'text' (probed); only the .py filename can
            // route this body through the python highlighter.
            file_put_contents($dir . '/loop.py', "total = 0\nfor i in range(3):\n    total += i\n");
            $result = $this->runCli(['--no-window', '--no-shadow', '--theme', 'dracula', 'loop.py'], null, $dir);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('fill="#ff79c6"', $result['stdout'], 'filename detection must highlight for/in keywords');
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testUnknownExtensionFallsBackToContentDetection(): void
    {
        $dir = $this->makeTempDir();
        try {
            file_put_contents($dir . '/notes.txt', "<?php echo 1;\n");
            $result = $this->runCli(['--no-window', '--no-shadow', '--theme', 'dracula', 'notes.txt'], null, $dir);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('fill="#ff79c6"', $result['stdout'], 'content detection must still fire for unknown extensions');
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testNonNumericPaddingExitsTwoWithFriendlyMessage(): void
    {
        $result = $this->runCli(['--padding', 'wide']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString("--padding expects a non-negative integer", $result['stderr']);
        $this->assertStringNotContainsString('Uncaught', $result['stderr']);
    }

    public function testFlagWithoutValueExitsTwoWithFriendlyMessage(): void
    {
        $result = $this->runCli(['--theme']);

        $this->assertSame(2, $result['exitCode']);
        $this->assertStringContainsString('--theme requires a value', $result['stderr']);
    }

    public function testImpossibleHighlightRangesExitTwoWithoutFatal(): void
    {
        foreach (['0:5' => 'start must be >= 1', '5:3' => 'end must be >= start'] as $spec => $needle) {
            $result = $this->runCli(['--no-window', '--no-shadow', '--highlight', $spec], "x\n");

            $this->assertSame(2, $result['exitCode'], $spec . ': ' . $result['stderr']);
            $this->assertStringContainsString("rejected highlight '$spec'", $result['stderr']);
            $this->assertStringContainsString($needle, $result['stderr']);
            $this->assertStringNotContainsString('Uncaught', $result['stderr']);
            $this->assertSame('', $result['stdout']);
        }
    }

    public function testPngOutputAnnouncesIgnoredSvgOnlyOptions(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not loaded');
        }

        $dir = $this->makeTempDir();
        try {
            $result = $this->runCli(['--format', 'png', '--ligatures', '-o', 'shot.png'], "x\n", $dir);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString('ignores SVG-only options: --ligatures', $result['stderr']);
            $this->assertFileExists($dir . '/shot.png');
            $this->assertSame("\x89PNG", substr((string) file_get_contents($dir . '/shot.png'), 0, 4));
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testPngFilenameWithSvgFormatWarnsButKeepsDocumentedExit(): void
    {
        $dir = $this->makeTempDir();
        try {
            $result = $this->runCli(['-o', 'shot.png'], "x\n", $dir);

            $this->assertSame(0, $result['exitCode'], $result['stderr']);
            $this->assertStringContainsString("does not match the extension", $result['stderr']);
            $this->assertStringStartsWith('<?xml', (string) file_get_contents($dir . '/shot.png'));
        } finally {
            $this->rmTree($dir);
        }
    }

    public function testWindowStyleNoneCollapsesTheHeaderBand(): void
    {
        $none  = $this->runCli(['--window-style', 'none', '--no-shadow'], "x\n");
        $macos = $this->runCli(['--window-style', 'macos', '--no-shadow'], "x\n");

        $this->assertSame(0, $none['exitCode'], $none['stderr']);
        $this->assertSame(0, $macos['exitCode'], $macos['stderr']);
        $this->assertMatchesRegularExpression('/height="(\d+)"/', $none['stdout']);
        $this->assertMatchesRegularExpression('/height="(\d+)"/', $macos['stdout']);

        // The chrome band is 36 user-space units tall: dropping it must shrink
        // the canvas by exactly that, not leave an empty band behind.
        preg_match('/height="(\d+)"/', $none['stdout'], $a);
        preg_match('/height="(\d+)"/', $macos['stdout'], $b);
        $this->assertSame(36, (int) $b[1] - (int) $a[1], 'WindowStyle::None must collapse the 36px header band');
    }

    public function testVersionFlagsExitZeroWithPrefix(): void
    {
        foreach (['--version', '-v'] as $flag) {
            $r = $this->runCli([$flag]);
            $this->assertSame(0, $r['exitCode'], $flag . ': ' . $r['stderr']);
            $this->assertStringStartsWith('candy-codeshot', $r['stdout']);
            $this->assertSame('', $r['stderr']);
        }
    }

    public function testDirectoryInputRefusedWithExitTwo(): void
    {
        // Measured pre-fix: @file_get_contents('/etc') returned '' and the
        // false/null guard passed, rendering an empty frame silently.
        $r = $this->runCli(['--unsafe-paths', '/etc']);
        $this->assertSame(2, $r['exitCode']);
        $this->assertStringContainsString('is a directory', $r['stderr']);
    }

    public function testPipedStdinStaysSilentAndPngToPipeStillStreams(): void
    {
        if (!extension_loaded('gd')) {
            $this->markTestSkipped('ext-gd not loaded');
        }

        // Both tty-only branches (stdin notice, PNG-to-terminal refusal) must
        // stay silent on pipes: this pins the pipe polarity of the fix.
        $r = $this->runCli(['--format', 'png'], "x\n");
        $this->assertSame(0, $r['exitCode'], $r['stderr']);
        $this->assertSame('', $r['stderr']);
        $this->assertStringStartsWith(chr(137) . 'PNG', $r['stdout']);
    }

    public function testEscapeBytesCannotReachTerminalThroughFlagValues(): void
    {
        // An unknown flag embeds the raw argv value into the stderr message;
        // the sanitising Lang::t choke must strip the CSI bytes.
        $r = $this->runCli(["--\x1b[31mx"]);
        $this->assertSame(2, $r['exitCode']);
        $this->assertStringNotContainsString("\x1b", $r['stderr']);
    }

    public function testEscapeBytesCannotReachTerminalThroughEngineRefusalMessage(): void
    {
        // The one stderr leg that does NOT ride Lang::t is the mid-render engine
        // catch. Its message interpolates the confined font path verbatim, and a
        // Linux filename may carry ESC bytes — so the raw-message precedent needs
        // its own Sanitize::untrustedForDisplay sweep. We plant an over-cap TTF
        // (real 4-byte magic so it clears the signature door, then a sparse 34 MiB
        // size to trip MAX_EMBEDDED_FONT_BYTES inside render()) whose basename
        // carries CSI bytes. Pre-fix the ESC reached the terminal; the choke must
        // now neutralise it.
        $dir = $this->makeTempDir();
        $font = "\x1b[31moversized.ttf";
        try {
            file_put_contents($dir . '/in.txt', "x\n");
            $h = fopen($dir . '/' . $font, 'wb');
            self::assertIsResource($h);
            fwrite($h, "\x00\x01\x00\x00");   // truetype signature -> passes sniffFontMime
            ftruncate($h, 34_000_000);         // exceeds 32 MiB embed cap -> render throws
            fclose($h);

            $r = $this->runCli(
                ['--no-window', '--no-shadow', '--font', $font, 'in.txt'],
                null,
                $dir,
            );

            $this->assertSame(2, $r['exitCode'], $r['stderr']);
            $this->assertStringContainsString('rendering failed', $r['stderr']);
            // The refusal reason survives, only its CSI bytes are swept.
            $this->assertStringContainsString('too large', $r['stderr']);
            $this->assertStringNotContainsString("\x1b", $r['stderr']);
        } finally {
            $this->rmTree($dir);
        }
    }

    private function makeTempDir(): string
    {
        $dir = sys_get_temp_dir() . '/codeshot_cwd_' . uniqid('', true);
        if (!mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create temp dir: ' . $dir);
        }
        return $dir;
    }

    private function rmTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rmTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
