<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * Audit 15d-20 end to end: a `paths:`-scoped rule written AFTER the tools were
 * built reaches the model through a real boot-path Read.
 *
 * `Runtime::systemPromptSections()` re-walks the rule tiers on every prompt build
 * and skips every path-scoped rule as "delivered at tool time", so the tool-time
 * tracker is the ONLY channel such a rule has. `Bootstrap::tools()` used to hand
 * the tools a tracker over a single boot-time walk, which left a rule the agent
 * (or the user) wrote mid-session in neither channel until a restart. The unit
 * pins in {@see \SugarCraft\Crush\Tests\Context\RulePathNudgeTest} prove the
 * tracker CAN re-read; this file proves the shipped boot path actually wires a
 * re-reading tracker, which no unit test can see.
 *
 * Synthetic `$HOME` and repo under `sys_get_temp_dir()`, for the reason
 * {@see RulePathScopingWiringTest} gives: a committed fixture rule is not
 * re-materialised on a warm tree, so a test against one proves nothing.
 */
final class RuleNudgeMidSessionReloadTest extends TestCase
{
    use HomeSandboxTrait;

    private string $sandbox = '';

    private string $home = '';

    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->sandbox = sys_get_temp_dir() . '/sugarcrush_rulereload_' . uniqid('', true);
        $this->home = $this->sandbox . '/home';
        $this->repo = $this->sandbox . '/repo';
        mkdir($this->home . '/.sugar-crush/rules', 0o700, true);
        mkdir($this->repo . '/.sugar-crush/rules', 0o755, true);
        mkdir($this->repo . '/src/A', 0o755, true);
        file_put_contents($this->repo . '/src/A/B.php', "<?php\n// b\n");
        $this->useHomeSandbox($this->home, create: false);
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        // `2>&1` so a cleanup diagnostic lands in the array, not on the suite's
        // censused stderr (ChildStderrCaptureTest).
        exec('rm -rf ' . escapeshellarg($this->sandbox) . ' 2>&1', $cleanup);

        parent::tearDown();
    }

    public function testAProjectRuleWrittenAfterBootReachesTheNextMatchingRead(): void
    {
        $read = $this->bootedRead();
        $path = $this->repo . '/src/A/B.php';

        self::assertStringNotContainsString(
            'MIDSESSION RULE CANARY',
            $read->execute(['id' => 'c1', 'file_path' => $path])->content(),
            'precondition: no rule exists at boot',
        );

        $this->writeProjectRule('php', 'MIDSESSION RULE CANARY', ['**/*.php']);

        self::assertStringContainsString(
            'MIDSESSION RULE CANARY',
            $read->execute(['id' => 'c2', 'file_path' => $path])->content(),
            'the rule the splice now skips must arrive on the tool channel without a restart',
        );
    }

    public function testAnEditedProjectRuleDeliversItsCurrentBodyOnTheBootPath(): void
    {
        $this->writeProjectRule('php', 'BOOT TIME BODY CANARY', ['**/*.php']);
        $read = $this->bootedRead();

        $this->writeProjectRule('php', 'EDITED BODY CANARY', ['**/*.php']);
        $content = $read->execute(['id' => 'c1', 'file_path' => $this->repo . '/src/A/B.php'])->content();

        self::assertStringContainsString('EDITED BODY CANARY', $content);
        self::assertStringNotContainsString('BOOT TIME BODY CANARY', $content, 'the boot-time text is stale and must not be what the model is told');
    }

    public function testAProjectRuleThatLosesItsPathsMidSessionIsNotAlsoNudged(): void
    {
        $this->writeProjectRule('php', 'DEMOTED RULE CANARY', ['**/*.php']);
        $read = $this->bootedRead();

        // Now standing: the next prompt build splices it, so this channel must not.
        $this->writeProjectRule('php', 'DEMOTED RULE CANARY', []);

        self::assertStringNotContainsString(
            'DEMOTED RULE CANARY',
            $read->execute(['id' => 'c1', 'file_path' => $this->repo . '/src/A/B.php'])->content(),
        );
    }

    /**
     * @param list<string> $globs
     */
    private function writeProjectRule(string $name, string $body, array $globs): void
    {
        $front = 'name: ' . $name . "\n";
        if ($globs !== []) {
            $front .= "paths:\n";
            foreach ($globs as $glob) {
                $front .= '  - "' . $glob . "\"\n";
            }
        }

        file_put_contents($this->repo . '/.sugar-crush/rules/' . $name . '.md', "---\n" . $front . "---\n" . $body);
    }

    private function bootedRead(): Read
    {
        foreach (Bootstrap::tools($this->repo) as $tool) {
            if ($tool instanceof Read) {
                return $tool;
            }
        }

        self::fail('Bootstrap::tools() returned no Read');
    }
}
