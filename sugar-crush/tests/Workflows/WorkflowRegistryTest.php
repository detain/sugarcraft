<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workflows;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\Workflow;
use SugarCraft\Crush\Workflows\WorkflowBuilder;
use SugarCraft\Crush\Workflows\WorkflowLoadException;
use SugarCraft\Crush\Workflows\WorkflowNotFoundException;
use SugarCraft\Crush\Workflows\WorkflowRegistry;

final class WorkflowRegistryTest extends TestCase
{
    private string $tempDir;
    private string $originalHome;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original HOME and create temp directory for tests
        $this->originalHome = $_SERVER['HOME'] ?? '/root';
        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-test-' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $_SERVER['HOME'] = $this->tempDir;
    }

    protected function tearDown(): void
    {
        // Restore original HOME
        $_SERVER['HOME'] = $this->originalHome;

        // Clean up temp directory
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * Creates a workflow PHP file with proper autoloading and namespace imports.
     */
    private function createWorkflowFile(string $dir, string $name, string $phpCode): void
    {
        $autoloader = "require_once '" . __DIR__ . '/../../vendor/autoload.php' . "';";
        $imports = <<<'PHP'
use SugarCraft\Crush\Workflows\TaskBuilder;
use SugarCraft\Crush\Workflows\Tasks;
use SugarCraft\Crush\Workflows\WorkflowBuilder;

PHP;
        file_put_contents($dir . '/' . $name . '.php', "<?php\n" . $autoloader . "\n" . $imports . $phpCode);
    }

    public function testListReturnsEmptyArrayWhenDirectoryDoesNotExist(): void
    {
        $registry = new WorkflowRegistry('/nonexistent/path/workflows');

        $this->assertSame([], $registry->list());
    }

    public function testListReturnsEmptyArrayWhenDirectoryIsEmpty(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $this->assertSame([], $registry->list());
    }

    public function testListReturnsWorkflowNamesFromDirectory(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // Create workflow files - these are just for listing, not loading
        file_put_contents($workflowsDir . '/refactor-service.php', '<?php
// Autoloader not needed for list() tests
class Dummy {}');
        file_put_contents($workflowsDir . '/audit-code.php', '<?php
class Dummy {}');
        file_put_contents($workflowsDir . '/add-feature.php', '<?php
class Dummy {}');

        $registry = new WorkflowRegistry($workflowsDir);
        $names = $registry->list();

        $this->assertCount(3, $names);
        $this->assertSame(['add-feature', 'audit-code', 'refactor-service'], $names);
    }

    public function testListIgnoresHiddenFilesAndNonPhpFiles(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        file_put_contents($workflowsDir . '/valid.php', '<?php
class Dummy {}');
        file_put_contents($workflowsDir . '/.hidden.php', '<?php
class Dummy {}');
        file_put_contents($workflowsDir . '/readme.txt', 'not a workflow');

        $registry = new WorkflowRegistry($workflowsDir);
        $names = $registry->list();

        $this->assertCount(1, $names);
        $this->assertSame(['valid'], $names);
    }

    public function testLoadLoadsWorkflowFromFile(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'test-workflow', 'return (new WorkflowBuilder())
    ->name("test-workflow")
    ->description("A test workflow")
    ->stage("step1", (new TaskBuilder())->agent("coder")->prompt("Do it"))
    ->build();');

        $registry = new WorkflowRegistry($workflowsDir);
        $workflow = $registry->load('test-workflow');

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('test-workflow', $workflow->name);
        $this->assertSame('A test workflow', $workflow->description);
        $this->assertCount(1, $workflow->stages);
    }

    public function testLoadReturnsRegisteredWorkflowWhenExists(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // Create a file that would return a different workflow
        $this->createWorkflowFile($workflowsDir, 'my-workflow', 'return (new WorkflowBuilder())
    ->name("different-name")
    ->description("From filesystem")
    ->build();');

        // Register a workflow with the same name
        $registeredWorkflow = (new WorkflowBuilder())
            ->name('my-workflow')
            ->description('From registry')
            ->build();

        $registry = new WorkflowRegistry($workflowsDir);
        $registry->register($registeredWorkflow);

        $workflow = $registry->load('my-workflow');

        // Should return the registered one, not the filesystem one
        $this->assertSame('From registry', $workflow->description);
    }

    public function testLoadThrowsNotFoundForNonexistentWorkflow(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' not found");

        $registry->load('nonexistent');
    }

    public function testLoadThrowsNotFoundForInvalidWorkflowName(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage('Invalid workflow name');

        $registry->load('../traversal');
    }

    public function testLoadThrowsLoadExceptionForNonWorkflowReturn(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // Create file that returns a string instead of Workflow
        $this->createWorkflowFile($workflowsDir, 'invalid', 'return "not a workflow";');

        $registry = new WorkflowRegistry($workflowsDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('must return a Workflow instance');

        $registry->load('invalid');
    }

    public function testRegisterStoresWorkflowInMemory(): void
    {
        $workflow = (new WorkflowBuilder())
            ->name('registered-workflow')
            ->description('A registered workflow')
            ->build();

        $registry = new WorkflowRegistry($this->tempDir . '/workflows');
        $registry->register($workflow);

        // Should be retrievable even without a file
        $loaded = $registry->load('registered-workflow');

        $this->assertSame('registered-workflow', $loaded->name);
        $this->assertSame('A registered workflow', $loaded->description);
    }

    public function testRegisterAllowsOverwritingFilesystemWorkflow(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'override-test', 'return (new WorkflowBuilder())
    ->name("override-test")
    ->description("Original from file")
    ->build();');

        $override = (new WorkflowBuilder())
            ->name('override-test')
            ->description('Overridden in memory')
            ->build();

        $registry = new WorkflowRegistry($workflowsDir);
        $registry->register($override);

        $loaded = $registry->load('override-test');

        $this->assertSame('Overridden in memory', $loaded->description);
    }

    public function testResolveReturnsNullWhenNoMatch(): void
    {
        $registry = new WorkflowRegistry($this->tempDir . '/workflows');

        $result = $registry->resolve('xyznonexistent');

        $this->assertNull($result);
    }

    public function testResolveMatchesWorkflowByName(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'refactor-service', 'return (new WorkflowBuilder())
    ->name("refactor-service")
    ->description("Refactor a microservice")
    ->build();');

        $registry = new WorkflowRegistry($workflowsDir);

        // Exact name match
        $result = $registry->resolve('refactor-service');

        $this->assertInstanceOf(Workflow::class, $result);
        $this->assertSame('refactor-service', $result->name);
    }

    public function testResolveMatchesWorkflowByDescription(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'code-audit', 'return (new WorkflowBuilder())
    ->name("code-audit")
    ->description("Audit code quality and security")
    ->build();');

        $registry = new WorkflowRegistry($workflowsDir);

        // Description contains match
        $result = $registry->resolve('code quality');

        $this->assertInstanceOf(Workflow::class, $result);
        $this->assertSame('code-audit', $result->name);
    }

    public function testResolvePrefersRegisteredOverFilesystem(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'same-name', 'return (new WorkflowBuilder())
    ->name("same-name")
    ->description("From filesystem")
    ->build();');

        $registered = (new WorkflowBuilder())
            ->name('same-name')
            ->description('From registry')
            ->build();

        $registry = new WorkflowRegistry($workflowsDir);
        $registry->register($registered);

        $result = $registry->resolve('same-name');

        $this->assertSame('From registry', $result->description);
    }

    public function testResolveIsCaseInsensitive(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $this->createWorkflowFile($workflowsDir, 'MixedCase', 'return (new WorkflowBuilder())
    ->name("MixedCase")
    ->description("Mixed Case Description")
    ->build();');

        $registry = new WorkflowRegistry($workflowsDir);

        $result = $registry->resolve('mixedcase');

        $this->assertInstanceOf(Workflow::class, $result);
    }

    public function testDefaultWorkflowPathUsesHomeDirectory(): void
    {
        $registry = new WorkflowRegistry();

        // The default path should contain '~/.sugar-crush/workflows'
        // which after expansion should use the temp home we set
        $names = $registry->list();

        // Just verify it doesn't throw and returns an array
        $this->assertIsArray($names);
    }

    // ===== YAML loading tests =====

    public function testLoadYamlLoadsSimpleWorkflowFromYaml(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $yaml = <<<'YAML'
name: yaml-workflow
description: A workflow defined in YAML

stages:
  - name: first-step
    agent: coder
    prompt: "Do the first step"
    tools: [Read, Grep]

config:
  maxConcurrent: 3
  timeout: 1800
YAML;
        file_put_contents($workflowsDir . '/yaml-workflow.yaml', $yaml);

        $registry = new WorkflowRegistry($workflowsDir);
        $workflow = $registry->loadYaml('yaml-workflow');

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('yaml-workflow', $workflow->name);
        $this->assertSame('A workflow defined in YAML', $workflow->description);
        $this->assertCount(1, $workflow->stages);
        $this->assertSame(3, $workflow->maxConcurrent);
        $this->assertSame(1800, $workflow->timeout);
    }

    public function testLoadYamlThrowsNotFoundForNonexistentYaml(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $registry = new WorkflowRegistry($workflowsDir);

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' not found");

        $registry->loadYaml('nonexistent');
    }

    public function testLoadYamlThrowsLoadExceptionForInvalidYaml(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // YAML that parses but has no name field
        file_put_contents($workflowsDir . '/no-name.yaml', "description: missing name\nstages: []");

        $registry = new WorkflowRegistry($workflowsDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('must have a "name" field');

        $registry->loadYaml('no-name');
    }

    public function testLoadYamlHandlesParallelStage(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $yaml = <<<'YAML'
name: parallel-workflow
description: A workflow with parallel tasks

stages:
  - name: implement
    parallel: true
    agents:
      - name: api-task
        type: coder
        prompt: "Implement API"
      - name: test-task
        type: coder
        prompt: "Write tests"
YAML;
        file_put_contents($workflowsDir . '/parallel-workflow.yaml', $yaml);

        $registry = new WorkflowRegistry($workflowsDir);
        $workflow = $registry->loadYaml('parallel-workflow');

        $this->assertInstanceOf(Workflow::class, $workflow);
        $this->assertSame('parallel-workflow', $workflow->name);
        $this->assertCount(1, $workflow->stages);
        $this->assertSame('parallel', $workflow->stages[0]['type']);
    }

    public function testLoadTriesPhpBeforeYaml(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // Create both PHP and YAML with same name but different content
        $this->createWorkflowFile($workflowsDir, 'either-way', 'return (new WorkflowBuilder())
    ->name("either-way")
    ->description("From PHP file")
    ->stage("step1", (new TaskBuilder())->agent("coder")->prompt("Do it"))
    ->build();');

        $yaml = <<<'YAML'
name: either-way
description: From YAML file
stages:
  - name: step1
    agent: architect
    prompt: "YAML step"
YAML;
        file_put_contents($workflowsDir . '/either-way.yaml', $yaml);

        $registry = new WorkflowRegistry($workflowsDir);
        $workflow = $registry->load('either-way');

        // Should load PHP version, not YAML
        $this->assertSame('From PHP file', $workflow->description);
    }

    public function testLoadFallsBackToYamlWhenPhpNotFound(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        // Only YAML exists
        $yaml = <<<'YAML'
name: yaml-only
description: Only exists in YAML
stages:
  - name: step1
    agent: scribe
    prompt: "Write docs"
YAML;
        file_put_contents($workflowsDir . '/yaml-only.yaml', $yaml);

        $registry = new WorkflowRegistry($workflowsDir);
        $workflow = $registry->load('yaml-only');

        $this->assertSame('Only exists in YAML', $workflow->description);
    }

    public function testListIncludesYamlFiles(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        file_put_contents($workflowsDir . '/php-workflow.php', '<?php class Dummy {}');
        file_put_contents($workflowsDir . '/yaml-workflow.yaml', "name: yaml-workflow\nstages: []");
        file_put_contents($workflowsDir . '/another.yaml', "name: another\nstages: []");

        $registry = new WorkflowRegistry($workflowsDir);
        $names = $registry->list();

        $this->assertCount(3, $names);
        $this->assertContains('php-workflow', $names);
        $this->assertContains('yaml-workflow', $names);
        $this->assertContains('another', $names);
    }

    public function testListIgnoresHiddenYamlFiles(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        file_put_contents($workflowsDir . '/valid.yaml', "name: valid\nstages: []");
        file_put_contents($workflowsDir . '/.hidden.yaml', "name: hidden\nstages: []");

        $registry = new WorkflowRegistry($workflowsDir);
        $names = $registry->list();

        $this->assertCount(1, $names);
        $this->assertSame(['valid'], $names);
    }
}
