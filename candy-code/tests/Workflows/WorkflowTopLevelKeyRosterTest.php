<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Workflows;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Workflows\WorkflowLoadException;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * C6 (wf-impl): the rider law at the document's own level. An unknown
 * top-level key and an unknown `config:` key are refused at load, naming the
 * key and the supported set — `config: {maxToken: 50000}` used to load as a
 * run with no token ceiling at all. Also `config.stopOnFirstFailure`, the
 * PHP builder's switch spelled in YAML (strict boolean, default false).
 */
final class WorkflowTopLevelKeyRosterTest extends TestCase
{
    private const STAGES = "stages:\n  - name: one\n    prompt: p\n";

    public function testAnUnknownTopLevelKeyIsRefusedNamingItAndTheSupportedSet(): void
    {
        $this->assertRefused("name: r\nmaxTokens: 5\n" . self::STAGES, 'unknown top-level key "maxTokens"; supported keys: name, description, config, schemas, stages');
    }

    public function testAnUnknownConfigKeyIsRefusedNamingItAndTheSupportedSet(): void
    {
        $this->assertRefused(
            "name: r\nconfig: {maxToken: 50000}\n" . self::STAGES,
            '"config" has unknown key "maxToken"; supported keys: timeout, maxConcurrent, maxAgents, maxTokens, maxCost, stopOnFirstFailure',
        );
    }

    public function testEveryRosterKeyLoads(): void
    {
        $workflow = $this->registry()->fromYamlString(
            "name: r\ndescription: d\nschemas: {s: {type: object}}\n"
            . "config: {timeout: 60, maxConcurrent: 2, maxAgents: 9, maxTokens: 100, maxCost: 1.5, stopOnFirstFailure: true}\n"
            . self::STAGES,
        );

        self::assertSame(60, $workflow->timeout);
        self::assertSame(2, $workflow->maxConcurrent);
        self::assertSame(9, $workflow->maxAgents);
        self::assertSame(100, $workflow->maxTokens);
        self::assertSame(1.5, $workflow->maxCost);
        self::assertTrue($workflow->stopOnFirstFailure);
    }

    public function testStopOnFirstFailureDefaultsToFalseAndTakesAnExplicitFalse(): void
    {
        self::assertFalse($this->registry()->fromYamlString("name: r\n" . self::STAGES)->stopOnFirstFailure);
        self::assertFalse($this->registry()->fromYamlString("name: r\nconfig: {stopOnFirstFailure: false}\n" . self::STAGES)->stopOnFirstFailure);
    }

    /** @return array<string, array{string}> */
    public static function nonBooleans(): array
    {
        return [
            'quoted yes' => ["'yes'"],
            'one' => ['1'],
            'quoted true' => ["'true'"],
            'null' => ['~'],
        ];
    }

    #[DataProvider('nonBooleans')]
    public function testStopOnFirstFailureIsAStrictBoolean(string $value): void
    {
        $this->assertRefused(
            "name: r\nconfig:\n  stopOnFirstFailure: {$value}\n" . self::STAGES,
            '"config.stopOnFirstFailure" must be a boolean (true or false, unquoted)',
        );
    }

    public function testTheRostersAreWhatTheLoaderReads(): void
    {
        // Every roster key the parser does not read would be a key that
        // loads and does nothing — the bug class this roster closes.
        $source = (string) file_get_contents((new \ReflectionClass(WorkflowRegistry::class))->getFileName());
        foreach (WorkflowRegistry::YAML_CONFIG_KEYS as $key) {
            self::assertStringContainsString("array_key_exists('{$key}', \$data['config'])", $source, "config.{$key} is on the roster but never read");
        }
        foreach (WorkflowRegistry::YAML_WORKFLOW_KEYS as $key) {
            self::assertMatchesRegularExpression("/\\\$data\\['{$key}'\\]|'{$key}', \\\$data|\\\$data, '{$key}'/", $source, "{$key} is on the roster but never read");
        }
    }

    private function assertRefused(string $yaml, string $needle): void
    {
        try {
            $this->registry()->fromYamlString($yaml);
            self::fail('the document must not load quietly');
        } catch (WorkflowLoadException $error) {
            self::assertStringContainsString($needle, $error->getMessage());
        }
    }

    private function registry(): WorkflowRegistry
    {
        return new WorkflowRegistry(sys_get_temp_dir() . '/wf-top-level-roster-unused');
    }
}
