<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Cli\LaunchNotices;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Tests\Support\BackendSelectionEnvSandboxTrait;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-1.b: the launch seam lowers a persisted reasoning tier ONLY where the
 * live pair shapes it — and when it does not, it says so once.
 *
 * The banned failure is PRV-2 relocated from the `/effort` command to the
 * launch door: a tier saved while a shaper was live, then silently riding onto
 * a launch whose provider+model pair has no field to read it — a config.json
 * key the wire ignores. {@see LaunchContext::withResolvedReasoningEffort()}
 * returns both halves (engine, reason); {@see Bootstrap}'s launch delegate is
 * the seam that SPEAKS the reason, on the same shelf every other launch row
 * rides, through the same funnel. The reason arrives pre-built, so the
 * launch-format and stderr censuses do not move.
 */
final class LaunchEffortNoticeTest extends TestCase
{
    use BackendSelectionEnvSandboxTrait;
    use HomeSandboxTrait;

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/candy_code_launch_effort_' . uniqid('', true);
        mkdir($this->root . '/project', 0700, true);
        $this->useHomeSandbox($this->root . '/home');
        $this->clearBackendSelectionEnv();
        Bootstrap::useConfigPath(null);
        LaunchNotices::reset();
    }

    protected function tearDown(): void
    {
        LaunchNotices::reset();
        Bootstrap::useConfigPath(null);
        $this->restoreBackendSelectionEnv();
        $this->restoreHomeSandbox();
        $this->removeEffortTree($this->root);

        parent::tearDown();
    }

    public function testAPersistedTierOnANonShapingPairComesBackAsEngineAndReason(): void
    {
        Bootstrap::writeUserConfig(['reasoningEffort' => 'high']);
        $engine = $this->customEngine();
        $this->assertNull($engine->reasoningEffort(), 'the engine as built carries no tier');

        [$carried, $reason] = LaunchContext::withResolvedReasoningEffort($engine);

        $this->assertNull($carried->reasoningEffort(), 'the saved tier is left off — it shapes nothing on this pair');
        $this->assertSame($engine, $carried, 'a verdict of no hands the SAME engine back, not a rebuilt one');
        $this->assertIsString($reason);
        $this->assertStringContainsString('high', $reason);
        $this->assertStringContainsString('custom', $reason);
        $this->assertStringContainsString('gpt-4', $reason);
        $this->assertStringContainsString('left off this launch', $reason, 'the row says where the tier did NOT go');
    }

    public function testAPersistedTierOnAShapingPairRidesWithNoReasonAndNoRow(): void
    {
        Bootstrap::writeUserConfig(['reasoningEffort' => 'high']);

        [$carried, $reason] = LaunchContext::withResolvedReasoningEffort($this->anthropicEngine());

        $this->assertSame('high', $carried->reasoningEffort(), 'on a shaper the old behavior stands');
        $this->assertNull($reason);
        $this->assertSame([], Bootstrap::launchNotices(), 'a tier that shapes is not a warning');
    }

    public function testNothingPersistedLeavesTheEngineAsBuiltAndSaysNothing(): void
    {
        [$carried, $reason] = LaunchContext::withResolvedReasoningEffort($this->customEngine());

        $this->assertNull($reason);
        $this->assertNull($carried->reasoningEffort());
        $this->assertSame([], Bootstrap::launchNotices());
    }

    public function testTheLaunchDelegateRecordsTheReasonExactlyOnce(): void
    {
        Bootstrap::writeUserConfig(['reasoningEffort' => 'high']);
        $door = new ReflectionMethod(Bootstrap::class, 'withResolvedReasoningEffort');

        $first = $door->invoke(null, $this->customEngine());
        $rows = Bootstrap::launchNotices();

        $this->assertNull($first->reasoningEffort());
        $this->assertCount(1, $rows, 'the reason rides the shelf as one launch row');
        $this->assertStringContainsString('left off this launch', $rows[0]);

        $door->invoke(null, $this->customEngine());
        $this->assertCount(1, Bootstrap::launchNotices(), 'the shelf keeps one copy per message — a re-built engine must not double-say it');
    }

    // ---- harness ----------------------------------------------------------

    /**
     * `custom` is deliberately off the shaper roster (it fronts arbitrary
     * servers), so a tier saved elsewhere must not ride onto it unannounced.
     */
    private function customEngine(): EngineBackend
    {
        return EngineBackend::new(
            new CustomProvider('custom', 'https://api.example.com', 'gpt-4', null, new Client(), false, false),
            'gpt-4',
        );
    }

    private function anthropicEngine(): EngineBackend
    {
        return EngineBackend::new(
            new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6'),
            'claude-sonnet-4-6',
        );
    }

    private function removeEffortTree(string $dir): void
    {
        if ($dir === '' || !is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
