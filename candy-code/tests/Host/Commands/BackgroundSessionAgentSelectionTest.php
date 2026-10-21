<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Host\Commands;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\AgentManager;
use SugarCraft\Code\Host\Commands\BackgroundCommand;
use SugarCraft\Code\Skills\SkillSource;

/**
 * ISS-087: `/bg` and `/fork` must fall back to OUR OWN agent, never to a
 * foreign import that happens to sit at roster position zero.
 *
 * Bootstrap's agentRoster inserts foreign imports before the built-ins and
 * PHP keeps the first-inserted position on overwrite, so the old positional
 * read `all()[0]` handed every default background session — its model and
 * its isolation — to a stray file in `~/.claude/agents/`.
 */
final class BackgroundSessionAgentSelectionTest extends TestCase
{
    /**
     * Mirrors the reflection seeding the roster tests already use; named
     * apart from their helper per the duplicate-helper drift guard.
     */
    private static function managerHolding(array $agents): AgentManager
    {
        $reflection = new \ReflectionClass(AgentManager::class);
        $manager = $reflection->newInstanceWithoutConstructor();
        $indexed = [];
        foreach ($agents as $agent) {
            $indexed[$agent->name] = $agent;
        }

        $property = $reflection->getProperty('agents');
        $property->setAccessible(true);
        $property->setValue($manager, $indexed);

        return $manager;
    }

    private static function agentNamed(string $name, SkillSource $source = SkillSource::Native, string $model = 'gpt-4o', bool $inheritsModel = false): Agent
    {
        return new Agent(
            name: $name,
            description: "agent {$name}",
            prompt: '',
            model: $model,
            provider: 'openai',
            tools: [],
            skillNames: [],
            hooks: [],
            isActive: false,
            source: $source,
            inheritsModel: $inheritsModel,
        );
    }

    private static function firstNative(?AgentManager $manager): ?Agent
    {
        $method = new ReflectionMethod(BackgroundCommand::class, 'firstNativeAgent');
        $method->setAccessible(true);

        return $method->invoke(null, $manager);
    }

    public function testTheFallbackSkipsForeignImportsToTheFirstNativeEntry(): void
    {
        // Foreign-first insertion order is exactly what made position zero a
        // trap; the native coder sits behind the import and must still win.
        $manager = self::managerHolding([
            self::agentNamed('helper', SkillSource::Claude),
            self::agentNamed('coder'),
        ]);

        $picked = self::firstNative($manager);

        $this->assertNotNull($picked);
        $this->assertSame('coder', $picked->name);
    }

    public function testAForeignOnlyRosterYieldsNoFallbackAgentAtAll(): void
    {
        $manager = self::managerHolding([self::agentNamed('helper', SkillSource::Opencode)]);

        $this->assertNull(self::firstNative($manager), 'no native — the chain must fall through to the stand-in');
    }

    public function testNoManagerMeansNoNativeAgent(): void
    {
        $this->assertNull(self::firstNative(null));
    }

    public function testTheStandInStillCarriesTheUnknownPlaceholders(): void
    {
        // BackgroundSessionRunner skips exporting a model that is '' or the
        // literal 'unknown'; the stand-in relies on that, unchanged here.
        $this->assertSame('unknown', BackgroundCommand::defaultAgent()->exportedModel());
    }

    public function testTheProductionChainReadsTheNativeHelperNotPositionZero(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Host/Commands/BackgroundCommand.php',
        );

        $this->assertStringContainsString('self::firstNativeAgent($agentManager)', $source);
        $this->assertStringNotContainsString('->all()[0]', $source, 'a positional default is the ISS-087 defect itself');
    }

    public function testTheDaemonArgReadsExportedModelNotTheRawAgentModel(): void
    {
        $source = (string) file_get_contents(
            \dirname(__DIR__, 3) . '/src/Background/BackgroundSupervisor.php',
        );

        $start = strpos($source, '$agent->provider,');
        $this->assertNotFalse($start, 'the daemon-code provider arg moved; re-anchor this pin');
        $window = substr($source, $start, 420);

        $this->assertStringContainsString('$agent->exportedModel(),', $window);
        $this->assertStringNotContainsString('$agent->model,', $window);
    }

    public function testExportedModelFollowsTheSessionWhenTheAgentInherits(): void
    {
        $this->assertSame('', self::agentNamed('flagged', inheritsModel: true)->exportedModel());
        $this->assertSame('', self::agentNamed('lit', model: 'inherit')->exportedModel(), 'the word inherit is inheritance even without the flag');
        $this->assertSame('', self::agentNamed('blank', model: '')->exportedModel());
        $this->assertSame('sonnet', self::agentNamed('padded', model: '  sonnet  ')->exportedModel(), 'the dictation is trimmed, not padded');
        $this->assertSame('gpt-4o', self::agentNamed('plain')->exportedModel());
    }
}
