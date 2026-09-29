<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-one-type-per-file.php';

/**
 * Guards `tools/check-one-type-per-file.php`: every PSR-4 file declares only
 * the type its path names, because Composer cannot autoload a second one by
 * name (sugar-crush's App\LayoutResetMsg died "class not found" that way, and
 * sugar-dash's twice-declared SortDirection was a redeclaration fatal).
 */
final class OneTypePerFileToolTest extends TestCase
{
    use TmpTree;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('one_type_per_file_');
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * The real tree is clean, and the scan is not blind: it reads every lib.
     */
    public function testTheRepositoryItselfPassesTheGate(): void
    {
        [$out, $err, $code] = $this->runTool(\dirname(__DIR__, 2));

        $this->assertSame(0, $code, "tool was red on its own repo:\n$out$err");
        $this->assertStringContainsString(' 0 problems', $out);
        $this->assertMatchesRegularExpression('/check-one-type-per-file: ([5-9]\d|\d{3,}) libs, \d{4,} psr-4 files/', $out);
    }

    public function testASecondTypeInAFileRedsNamingTheFix(): void
    {
        $this->writeFile('lib-x', 'Sort.php', "enum SortDirection\n{\n    case Asc;\n}\n\nfinal class Sort\n{\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, "a secondary type passed the gate:\n$out");
        $this->assertStringContainsString('lib-x/src/Sort.php declares SortDirection besides its PSR-4 symbol Sort — move it to SortDirection.php', $err);
    }

    public function testAFileWhoseOnlyTypeIsMisnamedReds(): void
    {
        $this->writeFile('lib-x', 'Widget.php', "final class Gadget\n{\n}\n");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('lib-x/src/Widget.php declares Gadget', $err);
    }

    public function testClassConstantsAnonymousClassesAndDeclarationFreeFilesAreNotTypes(): void
    {
        $this->writeFile('lib-x', 'Maker.php', "final class Maker\n{\n    public function make(): object\n    {\n        \$n = self::class;\n\n        return new class {\n        };\n    }\n}\n");
        $this->writeFile('lib-x', 'functions.php', "function helper(): int\n{\n    return 1;\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "a non-declaration was judged:\n$out$err");
        $this->assertStringContainsString('1 libs, 2 psr-4 files, 0 problems', $out);
    }

    public function testOnlyPsr4RootsAreRead(): void
    {
        $this->writeFile('lib-x', 'Clean.php', "final class Clean\n{\n}\n");
        \mkdir("$this->tmpDir/lib-x/legacy", 0777, true);
        \file_put_contents("$this->tmpDir/lib-x/legacy/Many.php", "<?php\nfinal class A {}\nfinal class B {}\n");

        [$out, , $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, 'a directory outside the psr-4 map was judged');
        $this->assertStringContainsString('1 psr-4 files', $out);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runTool(string $root): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/check-one-type-per-file.php', '--root=' . $root],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [$out, $err, \proc_close($process)];
    }

    /**
     * One lib whose psr-4 root is `src/`, holding one file.
     */
    private function writeFile(string $lib, string $name, string $body): void
    {
        if (!\is_dir("$this->tmpDir/$lib/src")) {
            \mkdir("$this->tmpDir/$lib/src", 0777, true);
            \file_put_contents(
                "$this->tmpDir/$lib/composer.json",
                (string) \json_encode([
                    'name' => "sugarcraft/$lib",
                    'autoload' => ['psr-4' => ['SugarCraft\\ToolFixture\\' => 'src/']],
                ]),
            );
        }
        \file_put_contents("$this->tmpDir/$lib/src/$name", "<?php\n\ndeclare(strict_types=1);\n\nnamespace SugarCraft\\ToolFixture;\n\n" . $body);
    }
}
