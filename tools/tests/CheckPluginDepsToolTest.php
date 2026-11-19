<?php

declare(strict_types=1);

namespace SugarCraft\Tools\Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../check-plugin-deps.php';

/**
 * Guards `tools/check-plugin-deps.php`: the plugin libs stay independent of
 * candy-code, by manifest and by symbol, so the wire contract cannot quietly
 * grow a dependency on the host that loads it (plan_plugin.md 3.3, 6.5).
 */
final class CheckPluginDepsToolTest extends TestCase
{
    use TmpTree;

    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = $this->makeTmpTree('plugin_deps_');
        foreach (['sugar-plugins', 'sugar-serve-protocol', 'candy-discord', 'candy-msteams'] as $lib) {
            $this->writeManifest($lib, ['sugarcraft/sugar-core' => 'dev-master']);
            \mkdir("$this->tmpDir/$lib/src", 0777, true);
            \mkdir("$this->tmpDir/$lib/tests", 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== '') {
            $this->removeDir($this->tmpDir);
        }
    }

    /**
     * The real tree is clean, and the scan is not blind: both roster libs exist
     * and their PHP is read.
     */
    public function testTheRepositoryItselfPassesTheGate(): void
    {
        [$out, $err, $code] = $this->runTool(\dirname(__DIR__, 2));

        $this->assertSame(0, $code, "tool was red on its own repo:\n$out$err");
        $this->assertMatchesRegularExpression('/check-plugin-deps: 4 libs, \d{2,} php files, 0 problems/', $out);
    }

    /**
     * A clean fixture passes: this is the passing half of the 6.5 pair.
     */
    public function testACleanPluginTreePasses(): void
    {
        $this->writeFile('sugar-plugins', 'src/Registry.php', "final class Registry\n{\n    public function name(): string\n    {\n        return 'Plugin';\n    }\n}\n");
        $this->writeFile('sugar-serve-protocol', 'tests/WireTest.php', "<?php\n\nnamespace CandyCode\\Bundles\\Demo;\n\nfinal class WireTest\n{\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "a clean plugin tree was red:\n$out$err");
        $this->assertStringContainsString('4 libs, 2 php files, 0 problems', $out);
    }

    public function testARequireOfCandyCodeInTheManifestReds(): void
    {
        $this->writeManifest('sugar-plugins', ['sugarcraft/sugar-core' => 'dev-master', 'sugarcraft/candy-code' => 'dev-master']);

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-plugins/composer.json lists sugarcraft/candy-code in require', $err);
    }

    public function testARequireDevOfCandyCodeInAnyCaseReds(): void
    {
        $this->writeManifest('sugar-serve-protocol', [], ['SugarCraft/Candy-Code' => 'dev-master']);

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-serve-protocol/composer.json lists sugarcraft/candy-code in require-dev', $err);
    }

    public function testAHostNamespaceReferenceInCodeReds(): void
    {
        $this->writeFile('sugar-serve-protocol', 'src/Attach.php', "final class Attach\n{\n    public function host(): \\SugarCraft\\Code\\Host\\RemoteSessionHost\n    {\n    }\n}\n");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-serve-protocol/src/Attach.php:', $err);
        $this->assertStringContainsString('references', $err);
        $this->assertStringContainsString('SugarCraft\\Code\\Host\\RemoteSessionHost', $err);
    }

    public function testAFullyQualifiedBareCodeNamespaceReds(): void
    {
        $this->writeFile('sugar-plugins', 'src/Bridge.php', "final class Bridge\n{\n    public function make(): \\Code\\Session\n    {\n    }\n}\n");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-plugins/src/Bridge.php:', $err);
    }

    public function testAHostReferenceInAStringLiteralReds(): void
    {
        $this->writeFile('sugar-plugins', 'src/Loader.php', "final class Loader\n{\n    public function has(): bool\n    {\n        return class_exists('SugarCraft\\\\Code\\\\Session');\n    }\n}\n");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, 'a dynamic reference to the host passed the gate');
        $this->assertStringContainsString('sugar-plugins/src/Loader.php:', $err);
    }

    public function testMentionsInCommentsAndTheOwnNamespaceAreNotReferences(): void
    {
        $this->writeFile(
            'sugar-plugins',
            'src/Doc.php',
            "/**\n * Mirrors the {@see \\SugarCraft\\Code\\Server\\Ws\\Outbox} coalescing; kept here as prose.\n */\nfinal class Doc\n{\n    public function own(): \\CandyCode\\Bundles\\Code\\Thing\n    {\n    }\n}\n",
        );

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "a comment or the plugin's own namespace was judged:\n$out$err");
    }

    public function testAHostReferenceUnderTestsRedsToo(): void
    {
        $this->writeFile('sugar-serve-protocol', 'tests/Wire/RoundTripTest.php', "<?php\n\nnamespace Tests;\n\nuse SugarCraft\\Code\\Protocol\\JsonRpc;\n\nfinal class RoundTripTest\n{\n}\n");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-serve-protocol/tests/Wire/RoundTripTest.php:', $err);
    }

    public function testAMissingRosterLibFailsClosed(): void
    {
        \rmdir("$this->tmpDir/sugar-serve-protocol/tests");
        \unlink("$this->tmpDir/sugar-serve-protocol/composer.json");

        [, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(2, $code, 'a roster lib without a manifest passed as clean');
        $this->assertStringContainsString('cannot read sugar-serve-protocol/composer.json', $err);
    }

    /**
     * A bundle may keep candy-code for Lang: its manifest and its `Lang` import
     * are the whole allowance, and both pass.
     */
    public function testABundleMayRequireCandyCodeAndImportOnlyLang(): void
    {
        $this->writeManifest('candy-discord', ['sugarcraft/candy-code' => 'dev-master']);
        $this->writeFile('candy-discord', 'src/Commands.php', "use SugarCraft\\Code\\Lang;\n\nfinal class Commands\n{\n    public function label(): string\n    {\n        return Lang::t('x');\n    }\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(0, $code, "the Lang allowance was judged:\n$out$err");
    }

    /**
     * The allowance is exact: a bundle reaching past Lang into the host reds.
     */
    public function testABundleReachingPastLangReds(): void
    {
        $this->writeFile('candy-msteams', 'src/Probe.php', "use SugarCraft\\Code\\Cli\\Bootstrap;\n\nfinal class Probe\n{\n    public function dir(): string\n    {\n        return Bootstrap::trustedConfigDirPath();\n    }\n}\n");

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code, 'a bundle reaching into Bootstrap passed the gate');
        $this->assertStringContainsString('candy-msteams/src/Probe.php:', $err);
    }

    /**
     * A plugin library gets no allowance: candy-code in its manifest reds even
     * when the bundles keep theirs.
     */
    public function testTheBundleAllowanceDoesNotReachTheLibraries(): void
    {
        $this->writeManifest('sugar-serve-protocol', ['sugarcraft/candy-code' => 'dev-master']);

        [$out, $err, $code] = $this->runTool($this->tmpDir);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('sugar-serve-protocol/composer.json lists sugarcraft/candy-code in require', $err);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function runTool(string $root): array
    {
        $process = \proc_open(
            [\PHP_BINARY, \dirname(__DIR__) . '/check-plugin-deps.php', '--root=' . $root],
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
     * @param array<string, string> $require
     * @param array<string, string> $requireDev
     */
    private function writeManifest(string $lib, array $require, array $requireDev = []): void
    {
        if (!\is_dir("$this->tmpDir/$lib")) {
            \mkdir("$this->tmpDir/$lib", 0777, true);
        }
        \file_put_contents(
            "$this->tmpDir/$lib/composer.json",
            (string) \json_encode([
                'name' => "sugarcraft/$lib",
                'require' => $require,
                'require-dev' => $requireDev,
                'autoload' => ['psr-4' => ['SugarCraft\\PluginFixture\\' => 'src/']],
            ]),
        );
    }

    private function writeFile(string $lib, string $path, string $body): void
    {
        $full = "$this->tmpDir/$lib/$path";
        if (!\is_dir(\dirname($full))) {
            \mkdir(\dirname($full), 0777, true);
        }
        // Bodies are written without an opening tag so the scan reads real PHP.
        \file_put_contents($full, \str_starts_with($body, '<?php') ? $body : "<?php\n\ndeclare(strict_types=1);\n\n" . $body);
    }
}
