<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Commands\RenderModeCommand;
use SugarCraft\Crush\Config\LayeredSettings;
use SugarCraft\Crush\Config\Settings\SettingsWriter;
use SugarCraft\Crush\Host\Commands\CommandContext;
use SugarCraft\Crush\Host\Commands\RenderModeHostCommand;
use SugarCraft\Crush\ToolResult;
use SugarCraft\Mosaic\Mosaic;

/**
 * The W5.7 `/rendermode` door: argv coercion, the ladder/asymmetry report,
 * the settings round-trip through the ONE validated writer door, and the
 * {@see ToolResult::resetRenderMode()} memo seam — all without a terminal
 * probe (every word under test is forced, so `Mosaic::fromModeString`
 * constructs directly and no capability detection runs).
 */
final class RenderModeCommandTest extends TestCase
{
    private string $rmcRoot = '';

    protected function setUp(): void
    {
        $this->rmcRoot = sys_get_temp_dir() . '/w5a2-rendermode-' . getmypid() . '-' . substr(hash('sha256', uniqid('rmc', true)), 0, 8);
        \mkdir($this->rmcRoot . '/' . LayeredSettings::dir(), 0700, true);
        $this->rmcResetStatics();
    }

    protected function tearDown(): void
    {
        $this->rmcResetStatics();
        $this->rmcRemoveTree($this->rmcRoot);
    }

    public function testTheParserNormalizesCaseAndWhitespace(): void
    {
        $plan = RenderModeCommand::parse(['  KiTtY ']);

        self::assertSame('kitty', $plan['word']);
        self::assertNull($plan['error']);
    }

    public function testAutoIsAnAllowedWordNotAProbe(): void
    {
        $plan = RenderModeCommand::parse(['auto']);

        self::assertSame('auto', $plan['word']);
        self::assertNull($plan['error']);
        self::assertSame('auto', ToolResult::renderMode(), 'parsing alone must not touch the live word');
    }

    public function testAnUnknownWordIsRefusedWithTheAllowedSet(): void
    {
        $plan = RenderModeCommand::parse(['vaporgraph']);

        self::assertNotNull($plan['error']);
        self::assertStringContainsString('unknown render mode', (string) $plan['error']);
        self::assertStringContainsString('chafa', (string) $plan['error'], 'the refusal must teach the vocabulary');
        self::assertStringContainsString('quarterblock', (string) $plan['error']);
    }

    public function testFlagsAndDoublesKeepThePlayHouseShape(): void
    {
        self::assertStringContainsString('unknown flag', (string) RenderModeCommand::parse(['--zoom', 'kitty'])['error']);
        self::assertStringContainsString('exactly one mode', (string) RenderModeCommand::parse(['kitty', 'sixel'])['error']);
        self::assertTrue(RenderModeCommand::parse(['--help'])['help']);
    }

    public function testBareCommandReportsCurrentWordAndBothLadders(): void
    {
        ToolResult::setRenderMode('halfblock');

        $result = (new RenderModeHostCommand())->run(CommandContext::new(), '/rendermode');

        $body = $result->rows[1]->content;
        self::assertStringContainsString('render mode: halfblock', $body);
        self::assertStringContainsString('reel analogue: halfblock', $body, 'the pure tryFrom half of the player pick law must show');
        self::assertStringContainsString('images:', RenderModeCommand::help());
        self::assertStringContainsString('video:', RenderModeCommand::help(), 'the asymmetry has to live in the help text');
        self::assertStringContainsString('m cycles', RenderModeCommand::help());
    }

    public function testAutoWordHasNoReelAnalogueAndSaysSo(): void
    {
        $report = RenderModeCommand::report('auto');

        self::assertStringContainsString('none — player uses its own auto-ladder', $report);
    }

    public function testWithoutTheSettingsWriterTheWordStandsUntouched(): void
    {
        ToolResult::setRenderMode('auto');

        $result = (new RenderModeHostCommand())->run(CommandContext::new(), '/rendermode sixel');

        self::assertStringContainsString('no settings writer', $result->rows[1]->content);
        self::assertSame('auto', ToolResult::renderMode(), 'a refused write must not flip the live word either');
    }

    public function testSettingAWordPersistsThroughTheDoorAndFlipsTheLiveState(): void
    {
        $config = $this->rmcRoot . '/' . LayeredSettings::dir() . '/config.json';
        $context = CommandContext::new(workspace: $this->rmcWorkspace($config));

        $result = (new RenderModeHostCommand())->run($context, '/rendermode halfblock');

        self::assertStringContainsString('render mode: halfblock', $result->rows[1]->content);
        self::assertSame('halfblock', ToolResult::renderMode(), 'the session follows the word without a restart');

        $written = (array) json_decode((string) \file_get_contents($config), true);
        self::assertSame('halfblock', $written['ui.imageRenderMode'], 'the SAME validated writer persisted it');
    }

    public function testTheResetSeamForcesAFreshProbeForTheSameWord(): void
    {
        ToolResult::setRenderMode('sixel');

        $first = ToolResult::mosaic();
        self::assertSame($first, ToolResult::mosaic(), 'the per-mode memo must still memoize');

        ToolResult::resetRenderMode();

        $second = ToolResult::mosaic();
        self::assertNotSame($first, $second, 'reset must discard the cached probe, not reuse it');
        self::assertSame('sixel', $second->protocol(), 'reset resets caches, never the word');
        self::assertInstanceOf(Mosaic::class, $second);
    }

    public function testHelpAnswerCarriesTheAllowedVocabulary(): void
    {
        $result = (new RenderModeHostCommand())->run(CommandContext::new(), '/rendermode --help');

        self::assertStringContainsString(RenderModeCommand::USAGE, $result->rows[1]->content);
        self::assertStringContainsString('allowed: auto, sixel, kitty', $result->rows[1]->content);
    }

    private function rmcWorkspace(string $config): \SugarCraft\Crush\Host\WorkspaceContext
    {
        $writer = SettingsWriter::new($config, static function (array $set, array $unset) use ($config): void {
            $current = \is_file($config) ? (array) \json_decode((string) \file_get_contents($config), true) : [];
            \file_put_contents($config, (string) \json_encode(SettingsWriter::patched($current, $set, $unset)));
        });

        return \SugarCraft\Crush\Host\WorkspaceContext::new(root: $this->rmcRoot)
            ->withService(SettingsWriter::class, $writer);
    }

    /**
     * The command moves process-global statics; pin them back to the boot
     * shape after every test so sibling suites see the default world
     * (same reflection idiom as ToolResultRenderModeTest).
     */
    private function rmcResetStatics(): void
    {
        (new \ReflectionProperty(ToolResult::class, 'renderMode'))->setValue(null, 'auto');
        (new \ReflectionProperty(ToolResult::class, 'mosaics'))->setValue(null, []);
    }

    private function rmcRemoveTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            \is_dir($child) ? $this->rmcRemoveTree($child) : \unlink($child);
        }

        \rmdir($path);
    }
}
