<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use SugarCraft\Core\Util\Editor;
use PHPUnit\Framework\TestCase;

final class EditorTest extends TestCase
{
    /** @var list<list<string>> */
    private array $captured = [];

    /** @var \Closure(list<string>): int|null */
    private ?\Closure $previousRunner = null;

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->captured = [];
        $this->previousRunner = Editor::setRunner(function (array $argv): int {
            $this->captured[] = $argv;
            return 0;
        });

        foreach (['VISUAL', 'EDITOR'] as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    protected function tearDown(): void
    {
        Editor::setRunner($this->previousRunner);

        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
            } else {
                putenv("{$key}={$value}");
            }
        }
        $this->savedEnv = [];
    }

    public function testEditReturnsSeedWhenRunnerLeavesFileUnchanged(): void
    {
        $result = Editor::edit('hello world', '.txt', 'true');
        $this->assertSame('hello world', $result);
        $this->assertCount(1, $this->captured);
    }

    public function testEditReturnsModifiedContentWhenRunnerEditsFile(): void
    {
        Editor::setRunner(static function (array $argv): int {
            $tmp = $argv[count($argv) - 1];
            file_put_contents($tmp, "edited\n");
            return 0;
        });

        $this->assertSame("edited\n", Editor::edit('seed', '.txt', 'true'));
    }

    public function testEditAppendsExtensionToTempFilePath(): void
    {
        Editor::edit('seed', '.md', 'true');
        $this->assertCount(1, $this->captured);
        $argv = $this->captured[0];
        $tmp  = end($argv);
        $this->assertIsString($tmp);
        $this->assertStringEndsWith('.md', $tmp);
    }

    public function testEditOmitsExtensionWhenEmptyString(): void
    {
        Editor::edit('seed', '', 'true');
        $argv = $this->captured[0];
        $tmp  = end($argv);
        $this->assertIsString($tmp);
        $this->assertDoesNotMatchRegularExpression('/\.[a-z0-9]+$/i', $tmp);
    }

    public function testEditNormalizesExtensionWithoutLeadingDot(): void
    {
        Editor::edit('seed', 'json', 'true');
        $argv = $this->captured[0];
        $tmp  = end($argv);
        $this->assertIsString($tmp);
        $this->assertStringEndsWith('.json', $tmp);
    }

    public function testEditUnlinksTempFileAfterSuccess(): void
    {
        Editor::edit('seed', '.txt', 'true');
        $argv = $this->captured[0];
        $tmp  = end($argv);
        $this->assertIsString($tmp);
        $this->assertFileDoesNotExist($tmp);
    }

    public function testEditUnlinksTempFileAfterRunnerThrows(): void
    {
        $captured = null;
        Editor::setRunner(static function (array $argv) use (&$captured): int {
            $captured = $argv[count($argv) - 1];
            throw new \RuntimeException('boom');
        });

        try {
            Editor::edit('seed', '.txt', 'true');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertIsString($captured);
        $this->assertFileDoesNotExist($captured);
    }

    public function testEditThrowsOnNonZeroExit(): void
    {
        Editor::setRunner(static fn (array $argv): int => 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Editor exited with status 1');
        Editor::edit('seed', '.txt', 'true');
    }

    public function testEditThrowsWhenNoEditorFound(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No usable editor found');
        Editor::edit('seed', '.txt', '__sugarcraft_no_such_editor__');
    }

    public function testEditPassesSeedToRunnerViaTempFile(): void
    {
        $observed = null;
        Editor::setRunner(static function (array $argv) use (&$observed): int {
            $observed = file_get_contents($argv[count($argv) - 1]);
            return 0;
        });

        Editor::edit('the seed bytes', '.txt', 'true');
        $this->assertSame('the seed bytes', $observed);
    }

    public function testCommandPrefersExplicitOverride(): void
    {
        putenv('EDITOR=__sugarcraft_no_such_editor__');
        $argv = Editor::command('true');
        $this->assertNotEmpty($argv);
        $this->assertStringContainsString('true', $argv[0]);
    }

    public function testCommandSplitsArgvOnWhitespace(): void
    {
        $argv = Editor::command('true -x -y');
        $this->assertCount(3, $argv);
        $this->assertStringContainsString('true', $argv[0]);
        $this->assertSame(['-x', '-y'], array_slice($argv, 1));
    }

    public function testCommandUsesVisualBeforeEditor(): void
    {
        putenv('VISUAL=true');
        putenv('EDITOR=__sugarcraft_no_such_editor__');
        $argv = Editor::command();
        $this->assertStringContainsString('true', $argv[0]);
    }

    public function testCommandFallsBackToEditorWhenVisualMissing(): void
    {
        putenv('EDITOR=true');
        $argv = Editor::command();
        $this->assertStringContainsString('true', $argv[0]);
    }

    public function testCommandThrowsOnUnknownOverride(): void
    {
        $this->expectException(\RuntimeException::class);
        Editor::command('__sugarcraft_no_such_editor__');
    }

    public function testEditAppendsTempFileAsLastArgv(): void
    {
        Editor::edit('seed', '.txt', 'true -p');
        $argv = $this->captured[0];
        $this->assertGreaterThanOrEqual(3, count($argv));
        $this->assertSame('-p', $argv[1]);
        $this->assertStringEndsWith('.txt', $argv[count($argv) - 1]);
    }

    public function testSetRunnerReturnsPreviousRunner(): void
    {
        $first  = static fn (array $argv): int => 0;
        $second = static fn (array $argv): int => 0;
        $prev1  = Editor::setRunner($first);
        $prev2  = Editor::setRunner($second);
        $this->assertSame($first, $prev2);
        Editor::setRunner($prev1);
    }

    public function testIntegrationWithRealCatLeavesSeedUnchanged(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('cat is POSIX-only');
        }
        $cat = trim((string) @shell_exec('command -v cat 2>/dev/null'));
        if ($cat === '') {
            $this->markTestSkipped('cat not available on this PATH');
        }
        Editor::setRunner(null);

        $result = Editor::edit('round-trip seed', '.txt', 'cat');
        $this->assertSame('round-trip seed', $result);
    }

    public function testIntegrationWithRealSedRewritesSeed(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('sed -i is POSIX-only');
        }
        $sed = trim((string) @shell_exec('command -v sed 2>/dev/null'));
        if ($sed === '') {
            $this->markTestSkipped('sed not available on this PATH');
        }
        Editor::setRunner(null);

        // GNU sed wants `-i` (no suffix); BSD sed needs `-i ''`. Use a
        // shell wrapper to keep the test portable: run sed via /bin/sh
        // with a here-string of inline expression.
        $shell = trim((string) @shell_exec('command -v sh 2>/dev/null'));
        if ($shell === '') {
            $this->markTestSkipped('sh not available on this PATH');
        }
        // Write a tiny shim script that invokes sed in a way both
        // GNU and BSD accept: `sed -e ... < file > file.new && mv`.
        $shim = tempnam(sys_get_temp_dir(), 'sc-editor-shim-');
        $this->assertNotFalse($shim);
        $script = <<<'SH'
            #!/bin/sh
            sed -e 's/foo/bar/' "$1" > "$1.new" && mv "$1.new" "$1"
            SH;
        file_put_contents($shim, $script);
        chmod($shim, 0o755);

        try {
            $result = Editor::edit('foo', '.txt', $shim);
            $this->assertSame('bar', $result);
        } finally {
            @unlink($shim);
        }
    }
}
