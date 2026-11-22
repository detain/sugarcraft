<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-cross-file-test-types.php';

/**
 * Guards `tools/check-cross-file-test-types.php`: no test file uses a type that
 * another test file declares as an extra top-level type, because only serial
 * phpunit's load-everything-first order makes that resolve; a ParaTest worker
 * loads one file per dispatch and fails "Class not found" (sugar-async
 * FakeClock went red at four workers).
 */
final class CrossFileTestTypesToolTest extends TestCase
{
    use TmpTree;

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('cross_file_test_types_');
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
        $this->assertMatchesRegularExpression('/check-cross-file-test-types: ([5-9]\d|\d{3,}) libs, \d{4,} test files/', $out);
    }

    public function testAnExtraTypeUsedFromAnotherTestFileRedsNamingHostTypeAndUser(): void
    {
        $this->writeTest('lib-x', 'BoundedTest.php', "final class BoundedTest\n{\n}\n\nfinal class FakeClock\n{\n}\n");
        $this->writeTest('lib-x', 'CacheTest.php', "final class CacheTest\n{\n    public function clock(): FakeClock\n    {\n        return new FakeClock();\n    }\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, "a cross-file extra type passed the gate:\n$out$err");
        $this->assertStringContainsString(
            'lib-x/tests/BoundedTest.php declares SugarCraft\ToolFixture\Tests\FakeClock, which 1 other file also uses: lib-x/tests/CacheTest.php; move it to its own autoloadable file (FakeClock.php)',
            $err,
        );
        $this->assertStringContainsString('1 libs, 2 test files, 1 problems', $out);
    }

    public function testAnExtraTypeUsedOnlyInsideItsOwnFileIsFine(): void
    {
        $this->writeTest('lib-x', 'SpyTest.php', "final class SpyTest\n{\n    public function spy(): Spy\n    {\n        return new Spy();\n    }\n}\n\nfinal class Spy\n{\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "a file-local helper type was judged:\n$out$err");
    }

    public function testFullyQualifiedAliasedAndGroupImportedUsesAreAllCaught(): void
    {
        $this->writeTest('lib-x', 'HostTest.php', "final class HostTest\n{\n}\n\nfinal class Fixture\n{\n}\n");
        $this->writeTest('lib-x', 'Sub/FqnTest.php', "final class FqnTest\n{\n    public function f(): void\n    {\n        \\SugarCraft\\ToolFixture\\Tests\\Fixture::class;\n    }\n}\n", 'Sub');
        $this->writeTest('lib-x', 'Sub/AliasTest.php', "use SugarCraft\\ToolFixture\\Tests\\Fixture as F;\n\nfinal class AliasTest\n{\n    public function f(F \$f): void\n    {\n    }\n}\n", 'Sub');
        $this->writeTest('lib-x', 'Sub/GroupTest.php', "use SugarCraft\\ToolFixture\\{Tests\\Fixture, Tests\\HostTest};\n\nfinal class GroupTest extends HostTest\n{\n    public function f(): Fixture\n    {\n        return new Fixture();\n    }\n}\n", 'Sub');

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, "an imported use passed the gate:\n$out$err");
        $this->assertStringContainsString('which 3 other files also use: lib-x/tests/Sub/AliasTest.php, lib-x/tests/Sub/FqnTest.php, lib-x/tests/Sub/GroupTest.php;', $err);
    }

    public function testCommentsStringsMembersFunctionsAndADifferentSameNamedTypeAreNotUses(): void
    {
        $this->writeTest('lib-x', 'HostTest.php', "final class HostTest\n{\n}\n\nfinal class FixtureMaster\n{\n}\n");
        $this->writeTest(
            'lib-x',
            'QuietTest.php',
            "final class QuietTest\n{\n"
            . "    /** @var FixtureMaster mentioned in a doc-block */\n"
            . "    private string \$s = 'FixtureMaster';\n\n"
            . "    // FixtureMaster in a line comment\n"
            . "    public function FixtureMaster(): void\n    {\n"
            . "        \$this->FixtureMaster();\n"
            . "        self::FixtureMaster();\n"
            . "    }\n}\n",
        );
        $this->writeTest(
            'lib-x',
            'Other/OwnTest.php',
            "use SugarCraft\\ToolFixture\\Tests\\Other\\Support\\FixtureMaster;\n\n"
            . "final class OwnTest\n{\n    public function f(): FixtureMaster\n    {\n        return new FixtureMaster();\n    }\n}\n",
            'Other',
        );

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "a non-use was counted as a use:\n$out$err");
        $this->assertStringContainsString('1 libs, 3 test files, 0 problems', $out);
    }

    public function testAutoloadDevRootsBesidesTestsAreRead(): void
    {
        $this->writeTest('lib-x', 'HostTest.php', "final class HostTest\n{\n}\n\nfinal class Shared\n{\n}\n");
        \mkdir("$this->tmpDir/lib-x/spec", 0777, true);
        \file_put_contents(
            "$this->tmpDir/lib-x/composer.json",
            (string) \json_encode(['name' => 'sugarcraft/lib-x', 'autoload-dev' => ['psr-4' => ['SugarCraft\\ToolFixture\\Spec\\' => 'spec/']]]),
        );
        \file_put_contents(
            "$this->tmpDir/lib-x/spec/UserSpec.php",
            "<?php\n\nnamespace SugarCraft\\ToolFixture\\Spec;\n\nfinal class UserSpec\n{\n    public function f(): \\SugarCraft\\ToolFixture\\Tests\\Shared\n    {\n        return new \\SugarCraft\\ToolFixture\\Tests\\Shared();\n    }\n}\n",
        );

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, "a user under an autoload-dev root was missed:\n$out$err");
        $this->assertStringContainsString('also uses: lib-x/spec/UserSpec.php;', $err);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runTool(string $root): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/check-cross-file-test-types.php', '--root=' . $root],
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
     * One test file under `<lib>/tests/`, in `SugarCraft\ToolFixture\Tests`
     * (plus `$subNamespace` when given).
     */
    private function writeTest(string $lib, string $relative, string $body, string $subNamespace = ''): void
    {
        if (!\is_file("$this->tmpDir/$lib/composer.json")) {
            \mkdir("$this->tmpDir/$lib/tests", 0777, true);
            \file_put_contents("$this->tmpDir/$lib/composer.json", (string) \json_encode(['name' => "sugarcraft/$lib"]));
        }
        $path = "$this->tmpDir/$lib/tests/$relative";
        if (!\is_dir(\dirname($path))) {
            \mkdir(\dirname($path), 0777, true);
        }
        $namespace = 'SugarCraft\\ToolFixture\\Tests' . ($subNamespace === '' ? '' : '\\' . $subNamespace);
        \file_put_contents($path, "<?php\n\ndeclare(strict_types=1);\n\nnamespace $namespace;\n\n" . $body);
    }
}
