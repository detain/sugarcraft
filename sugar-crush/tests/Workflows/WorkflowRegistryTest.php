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

            // is_link() FIRST, and it is not tidiness: is_dir() answers true
            // THROUGH a symlink to a directory, so recursing on that answer
            // would empty the LINK'S TARGET instead of removing the link. The
            // symlinked-directory fixtures below exist precisely to point a
            // project workflows directory at another directory, so a teardown
            // that followed them would delete the thing under test.
            if (is_link($path) || !is_dir($path)) {
                unlink($path);
                continue;
            }

            $this->removeDirectory($path);
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

    /**
     * `~` EXPANSION IS NOT A REGEX SUBSTITUTION. The expansion used to be
     * `preg_replace('/^~/', HomeDirectory::path(), $path)`, and the REPLACEMENT
     * argument is where `$1`, `\1` and `\\` are meaningful to PCRE — so a home
     * directory containing any of them came back mangled (a `$1` with no
     * capture group substitutes the empty string). A home path is user data,
     * not a pattern.
     */
    public function testATildeIsExpandedLiterallyEvenWhenTheHomePathLooksLikeABackreference(): void
    {
        $awkwardHome = $this->tempDir . '/ho$1me';
        mkdir($awkwardHome . '/.sugar-crush/workflows', 0755, true);
        file_put_contents($awkwardHome . '/.sugar-crush/workflows/from-home.php', "<?php\nclass Dummy {}");

        $originalEnvHome = getenv('HOME');
        putenv('HOME=' . $awkwardHome);
        $_SERVER['HOME'] = $awkwardHome;

        try {
            $this->assertSame(['from-home'], (new WorkflowRegistry())->list());
        } finally {
            $originalEnvHome === false ? putenv('HOME') : putenv('HOME=' . $originalEnvHome);
            $_SERVER['HOME'] = $this->tempDir;
        }
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


    // ===== YAML loading tests (P4.S9 scope) =====

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
    public function testLoadYamlThrowsNotFoundForNonexistentYaml(): void
    {
        $workflowsDir = $this->tempDir . '/workflows';
        mkdir($workflowsDir, 0755);

        $registry = new WorkflowRegistry($workflowsDir);

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage("Workflow 'nonexistent' not found");

        $registry->loadYaml('nonexistent');
    }

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
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

    /**
     * @group yaml
     * @group p4s9
     */
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

    // =========================================================================
    // Project tier — <root>/.sugar-crush/workflows, YAML only
    // =========================================================================

    /**
     * A repository may ship workflows, and they must be both discoverable and
     * loadable — otherwise the second constructor argument buys nothing.
     */
    public function testAProjectYamlIsListedAndLoadable(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        file_put_contents($projectDir . '/ship.yaml', "name: ship\ndescription: From the repo\nstages: []");

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['ship'], $registry->list());
        $this->assertSame('From the repo', $registry->load('ship')->description);
    }

    /**
     * THE security property of the project tier: `load()` reaches a `.php`
     * workflow through `require`, so honouring one out of a checkout would make
     * `/workflow run <name>` arbitrary code execution from cloned content. The
     * marker file is what proves it: an assertion that `load()` threw would
     * still pass against an implementation that executed the file and then
     * rejected its return value.
     */
    public function testAProjectPhpWorkflowIsNeitherListedNorExecuted(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        $marker = $this->tempDir . '/rce-marker';
        file_put_contents(
            $projectDir . '/pwn.php',
            "<?php\nfile_put_contents('" . $marker . "', 'executed');\nreturn null;\n",
        );

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame([], $registry->list());

        try {
            $registry->load('pwn');
            $this->fail('a project-tier .php file must not resolve as a workflow');
        } catch (WorkflowNotFoundException) {
            // expected
        }

        $this->assertFileDoesNotExist($marker, 'a project-tier .php file must never be require()d');
    }

    /**
     * A project `.php` file being invisible is also what stops it SHADOWING a
     * same-named workflow of the user's own — the user's file still loads.
     */
    public function testAProjectPhpFileDoesNotShadowTheUsersOwnWorkflow(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        file_put_contents($projectDir . '/build.php', "<?php\nreturn null;\n");
        $this->createWorkflowFile($userDir, 'build', <<<'PHP'
return (new WorkflowBuilder())->name('build')->description('Mine')->build();
PHP);

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame('Mine', $registry->load('build')->description);
    }

    /**
     * Precedence: the checkout wins for YAML, the same way a checked-in agent
     * preset beats a same-named one in the user's home.
     */
    public function testAProjectYamlOverridesASameNamedUserYaml(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/deploy.yaml', "name: deploy\ndescription: From home\nstages: []");
        file_put_contents($projectDir . '/deploy.yaml', "name: deploy\ndescription: From the repo\nstages: []");

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['deploy'], $registry->list());
        $this->assertSame('From the repo', $registry->load('deploy')->description);
        $this->assertSame('From the repo', $registry->loadYaml('deploy')->description);
    }

    /**
     * The tiers merge rather than replace, and a name present in both is
     * reported once.
     */
    public function testListMergesBothTiersAndDeduplicates(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/mine.yaml', "name: mine\nstages: []");
        file_put_contents($userDir . '/shared.yaml', "name: shared\nstages: []");
        file_put_contents($projectDir . '/theirs.yaml', "name: theirs\nstages: []");
        file_put_contents($projectDir . '/shared.yaml', "name: shared\nstages: []");
        $this->createWorkflowFile($userDir, 'dsl', 'return null;');

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['dsl', 'mine', 'shared', 'theirs'], $registry->list());
    }

    /**
     * A session rooted at the user's own home names one directory twice. It
     * must not show up twice in the listing.
     */
    public function testTheSameDirectoryInBothTiersIsListedOnce(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/only.yaml', "name: only\nstages: []");

        $registry = new WorkflowRegistry($userDir, $userDir);

        $this->assertSame(['only'], $registry->list());
    }

    // =========================================================================
    // YAML shape validation — these files are hand-authored, and since the
    // project tier exists they may have been hand-authored by somebody else.
    // =========================================================================

    public function testAStageWithoutANameIsRejectedWithTheFilePath(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/no-stage-name.yaml', "name: no-stage-name\nstages:\n  - agent: coder\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('stage #0 must have a "name" field');

        $registry->load('no-stage-name');
    }

    public function testAStageThatIsNotAMapIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/scalar-stage.yaml', "name: scalar-stage\nstages:\n  - just-a-string\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('stage #0 must be a map, got string');

        $registry->load('scalar-stage');
    }

    public function testANonStringPromptIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/bad-prompt.yaml',
            "name: bad-prompt\nstages:\n  - name: one\n    agent: coder\n    prompt:\n      nested: map\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"prompt" must be a string, got array');

        $registry->load('bad-prompt');
    }

    public function testAToolsValueThatIsNotAListOfNamesIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/bad-tools.yaml',
            "name: bad-tools\nstages:\n  - name: one\n    agent: coder\n    tools: Read\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"tools" must be a list of tool names, got string');

        $registry->load('bad-tools');
    }

    public function testAParallelStageWhoseAgentsAreNotAListIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/bad-parallel.yaml',
            "name: bad-parallel\nstages:\n  - name: fan\n    parallel: true\n    agents: nope\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('its "agents" must be a list, got string');

        $registry->load('bad-parallel');
    }

    public function testMalformedYamlIsReportedAsALoadFailureRatherThanAParseError(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/broken.yaml', "name: broken\nstages:\n  - name: one\n   agent: coder\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('is not valid YAML');

        $registry->load('broken');
    }

    public function testANonStringTopLevelNameIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/numeric-name.yaml', "name: 42\nstages: []");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('must have a "name" field');

        $registry->load('numeric-name');
    }

    /**
     * The not-found message must name every directory that was actually
     * searched — with two tiers, naming one of them sends the user to fix the
     * wrong place.
     *
     * @return void
     */
    public function testTheNotFoundMessageNamesBothSearchedDirectories(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        $registry = new WorkflowRegistry($userDir, $projectDir);

        try {
            $registry->load('absent');
            $this->fail('expected a WorkflowNotFoundException');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringContainsString($projectDir . '/absent.yaml', $e->getMessage());
            $this->assertStringContainsString($userDir . '/absent.yaml', $e->getMessage());
        }
    }

    /**
     * The not-found message must not name the SAME directory twice, which is
     * the reason yamlDirectories() deduplicates — the listing's own dedup
     * happens by array key in list() and would hide a missing one there.
     */
    public function testTheNotFoundMessageNamesOneDirectoryOnceWhenBothTiersAreTheSameDirectory(): void
    {
        [$userDir] = $this->twoTierDirs();
        $registry = new WorkflowRegistry($userDir, $userDir);

        try {
            $registry->load('absent');
            $this->fail('expected a WorkflowNotFoundException');
        } catch (WorkflowNotFoundException $e) {
            $this->assertSame(
                1,
                substr_count($e->getMessage(), $userDir . '/absent.yaml'),
                'one directory searched once must be reported once, not twice',
            );
        }
    }

    // =========================================================================
    // list() must offer exactly the names load() can resolve
    // =========================================================================

    /**
     * Stripping the `.yaml` suffix leaves the rest of the basename intact, so a
     * dotted file name used to be LISTED under a name validateName() then
     * rejects — `/workflow list` offering an entry whose `/workflow run` answers
     * "Invalid workflow name". Both halves are asserted here, because fixing
     * only the listing (or only the loader) leaves the same contradiction
     * pointing the other way.
     */
    public function testADottedFileNameIsNeitherListedNorLoadable(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/lint.v2.yaml', "name: lint\nstages: []");
        file_put_contents($userDir . '/ok.yaml', "name: ok\nstages: []");
        file_put_contents($projectDir . '/deploy.v2.yaml', "name: deploy\nstages: []");
        file_put_contents($userDir . '/evil.php.yaml', "name: evil\nstages: []");

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['ok'], $registry->list());

        foreach (['lint.v2', 'deploy.v2', 'evil.php'] as $name) {
            try {
                $registry->load($name);
                $this->fail("load('{$name}') must refuse a name list() does not offer");
            } catch (WorkflowNotFoundException $e) {
                $this->assertStringContainsString('Invalid workflow name', $e->getMessage());
            }
        }
    }

    /**
     * A DIRECTORY named `<name>.yaml` is not a workflow. Listing one would offer
     * a name whose load() cannot succeed.
     */
    public function testADirectoryNamedLikeAYamlWorkflowIsNotListed(): void
    {
        [$userDir] = $this->twoTierDirs();
        mkdir($userDir . '/looks-like.yaml', 0755);
        file_put_contents($userDir . '/real.yaml', "name: real\nstages: []");

        $registry = new WorkflowRegistry($userDir);

        $this->assertSame(['real'], $registry->list());
    }

    /**
     * The same directory trap on the PHP side, where it is worse: `require` of a
     * directory emits a PHP Warning — straight onto the stderr of a live TUI,
     * corrupting the frame — and then fails with an uncatchable compile error
     * that no `catch (\Throwable)` in `Chat` can survive. is_file() is what stops
     * load() being more permissive than list().
     */
    public function testADirectoryNamedLikeAPhpWorkflowIsNotRequired(): void
    {
        [$userDir] = $this->twoTierDirs();
        mkdir($userDir . '/looks-like.php', 0755);

        $registry = new WorkflowRegistry($userDir);

        $this->assertSame([], $registry->list());

        $this->expectException(WorkflowNotFoundException::class);
        $this->expectExceptionMessage("Workflow 'looks-like' not found");

        $registry->load('looks-like');
    }

    // =========================================================================
    // The directories this registry answers for
    // =========================================================================

    /**
     * Both tiers are readable as expanded paths, because
     * {@see \SugarCraft\Crush\Workflows\WorkflowEngine} anchors its pause files
     * to the user tier rather than to `~` — see that engine's
     * getPauseFilePath().
     */
    public function testTheTierAccessorsReportExpandedPaths(): void
    {
        // getenv(), not $_SERVER: HomeDirectory::path() reads the former, and
        // setUp() only sets the latter (which every other test here can live
        // with because none of them expands a `~`).
        $realHome = getenv('HOME');
        putenv('HOME=' . $this->tempDir);

        try {
            $registry = new WorkflowRegistry(
                '~/.sugar-crush/workflows/',
                $this->tempDir . '/repo/.sugar-crush/workflows',
            );

            $this->assertSame($this->tempDir . '/.sugar-crush/workflows', $registry->workflowsPath());
            $this->assertSame($this->tempDir . '/repo/.sugar-crush/workflows', $registry->projectWorkflowsPath());
            $this->assertNull((new WorkflowRegistry($this->tempDir))->projectWorkflowsPath());
        } finally {
            $realHome === false ? putenv('HOME') : putenv('HOME=' . $realHome);
        }
    }

    // =========================================================================
    // YAML shapes that used to be swallowed
    //
    // Every row here loaded "successfully" before, which for `stages: nope`
    // meant /workflow run printing "completed" for a run that executed nothing.
    // =========================================================================

    public function testAStagesValueThatIsNotAListIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/scalar-stages.yaml', "name: scalar-stages\nstages: nope\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"stages" must be a list of stages, got string');

        $registry->load('scalar-stages');
    }

    /**
     * `stages: []` stays legal — a workflow that deliberately does nothing is a
     * real thing, and it is what the wiring tests drive. Asserted so the fix
     * above cannot be over-applied.
     */
    public function testAnEmptyStagesListIsStillLoadable(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/empty-stages.yaml', "name: empty-stages\nstages: []\n");

        $registry = new WorkflowRegistry($userDir);

        $this->assertSame([], $registry->load('empty-stages')->stages);
    }

    public function testANonStringDescriptionIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/numeric-desc.yaml', "name: numeric-desc\ndescription: 42\nstages: []\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"description" must be a string, got int');

        $registry->load('numeric-desc');
    }

    public function testANonNumericTimeoutIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/bad-timeout.yaml',
            "name: bad-timeout\nstages: []\nconfig:\n  timeout: abc\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"config.timeout" must be a whole number, got string');

        $registry->load('bad-timeout');
    }

    public function testAListMaxConcurrentIsRejectedRatherThanCastToOne(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/list-concurrency.yaml',
            "name: list-concurrency\nstages: []\nconfig:\n  maxConcurrent: [1, 2]\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"config.maxConcurrent" must be a whole number, got array');

        $registry->load('list-concurrency');
    }

    /**
     * `maxConcurrent: 0` is the compounding case: AgentWorkerPool's dispatch
     * loop is `while (count($active) < $max)`, which at 0 never runs, so
     * executeAll() yields nothing and executeParallelStage() maps "no failures
     * among zero results" onto Completed — a parallel stage reporting success
     * having executed nothing at all.
     */
    public function testAZeroMaxConcurrentIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/zero-concurrency.yaml',
            "name: zero-concurrency\nstages: []\nconfig:\n  maxConcurrent: 0\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"config.maxConcurrent" must be at least 1, got 0');

        $registry->load('zero-concurrency');
    }

    public function testAConfigThatIsNotAMapIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/scalar-config.yaml', "name: scalar-config\nstages: []\nconfig: nope\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"config" must be a map, got string');

        $registry->load('scalar-config');
    }

    /**
     * A quoted YAML scalar is how a config value arrives when its author quoted
     * it, and it means what the unquoted form means — so it is accepted, not
     * refused. The control for the two rejections above.
     */
    public function testAQuotedWholeNumberConfigValueIsAccepted(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/quoted-config.yaml',
            "name: quoted-config\nstages: []\nconfig:\n  maxConcurrent: \"3\"\n  timeout: \"600\"\n",
        );

        $registry = new WorkflowRegistry($userDir);
        $workflow = $registry->load('quoted-config');

        $this->assertSame(3, $workflow->maxConcurrent);
        $this->assertSame(600, $workflow->timeout);
    }

    // =========================================================================
    // Per-entry guards inside a stage
    // =========================================================================

    public function testAToolsListContainingANonStringIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/mixed-tools.yaml',
            "name: mixed-tools\nstages:\n  - name: one\n    agent: coder\n    tools: [Read, 42]\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"tools" must be a list of tool names, got int in it');

        $registry->load('mixed-tools');
    }

    public function testAParallelAgentThatIsNotAMapIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/scalar-agent.yaml',
            "name: scalar-agent\nstages:\n  - name: fan\n    parallel: true\n    agents:\n      - just-a-string\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('agent #0 must be a map, got string');

        $registry->load('scalar-agent');
    }

    /**
     * `name: 42` is the guard the validation claim most directly rests on:
     * without the is_string() half, `return $stage['name'];` on an int is a
     * TypeError under strict_types, i.e. the user gets a raw PHP error instead
     * of the file-naming refusal every other malformed shape produces.
     */
    public function testANonStringStageNameIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/numeric-stage-name.yaml',
            "name: numeric-stage-name\nstages:\n  - name: 42\n    agent: coder\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('stage #0 must have a "name" field');

        $registry->load('numeric-stage-name');
    }

    public function testAnEmptyStageNameIsRejected(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/empty-stage-name.yaml',
            "name: empty-stage-name\nstages:\n  - name: \"\"\n    agent: coder\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('stage #0 must have a "name" field');

        $registry->load('empty-stage-name');
    }

    // =========================================================================
    // Project-tier symlinks (and what a REJECTED file used to leak)
    // =========================================================================

    /**
     * A committed symlink out of the project tier is neither listed nor loaded.
     *
     * The constructor used to argue a symlink here "buys nothing new", because
     * "nothing here reads a byte that is not the value of a known workflow
     * key". Measured, that was false, and what made it false was the loader's
     * own error path: `proj/leak.yaml -> ../secret/id_rsa` was LISTED, and
     * `/workflow run leak` answered with the YAML parser's message — which
     * quotes the offending LINE of whatever it was parsing. A line of the
     * private key reached the transcript and the session store, so a rejected
     * target leaked more than an accepted one would.
     */
    public function testAProjectTierSymlinkOutOfTheDirectoryIsNeitherListedNorLoaded(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();

        $secretDir = $this->tempDir . '/secret';
        mkdir($secretDir, 0700, true);
        file_put_contents($secretDir . '/id_rsa', "-----BEGIN OPENSSH PRIVATE KEY-----\nSENTINEL-SECRET-BYTES: x\n");

        $this->assertTrue(symlink($secretDir . '/id_rsa', $projectDir . '/leak.yaml'), 'test needs a real symlink');
        file_put_contents($projectDir . '/ok.yaml', "name: ok\nstages: []\n");

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['ok'], $registry->list(), 'a link out of the project tier must not be advertised');

        try {
            $registry->load('leak');
            $this->fail('loading a link out of the project tier must not succeed');
        } catch (WorkflowNotFoundException | WorkflowLoadException $e) {
            $this->assertStringNotContainsString(
                'SENTINEL-SECRET-BYTES',
                $e->getMessage(),
                'the error must not carry a byte of the linked target',
            );
        }
    }

    /**
     * A symlink INSIDE the project tier still works: confinement is about
     * escaping the directory, not about links. Without this control the test
     * above would also pass against a build that refused every symlink, or
     * every project-tier file.
     */
    public function testAProjectTierSymlinkThatStaysInsideTheDirectoryStillLoads(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();

        file_put_contents($projectDir . '/real.yaml', "name: aliased\ndescription: From the repo\nstages: []\n");
        $this->assertTrue(symlink($projectDir . '/real.yaml', $projectDir . '/aliased.yaml'));

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame(['aliased', 'real'], $registry->list());
        $this->assertSame('From the repo', $registry->load('aliased')->description);
    }

    /**
     * THIS TEST USED TO PIN THE ESCAPE, and it is inverted rather than deleted
     * because its old name and doc-block are the evidence. It read: "The user's
     * OWN tier is not confined. It is the directory whose `.php` files this class
     * `require`s, so a link inside it is the user pointing at their own file
     * rather than repository content reaching for a path the session happens to
     * be able to read" — and asserted that `kept.yaml -> <outside>` loaded.
     *
     * The premise was measured false (see the constructor: a tarball carries a
     * symlink and carries no `.git`, and ownership cannot answer who chose where
     * a link points), and the same directory's `.php` files reach `require`. So
     * the entry is now confined in BOTH tiers, and this is the COST — a
     * user-authored link out of their own workflows directory stops resolving.
     * {@see \SugarCraft\Crush\Tests\Workflows\WorkflowUserTierContainmentTest}
     * drives the code-execution half.
     */
    public function testTheUsersOwnTierRefusesASymlinkOutOfItsDirectory(): void
    {
        [$userDir] = $this->twoTierDirs();

        $elsewhere = $this->tempDir . '/elsewhere';
        mkdir($elsewhere, 0755, true);
        file_put_contents($elsewhere . '/kept.yaml', "name: kept\ndescription: Mine, elsewhere\nstages: []\n");
        $this->assertTrue(symlink($elsewhere . '/kept.yaml', $userDir . '/kept.yaml'));

        $registry = new WorkflowRegistry($userDir);

        $this->assertSame([], $registry->list(), 'an entry resolving outside its own directory is not listed');
        $this->expectException(WorkflowNotFoundException::class);
        $registry->load('kept');
    }

    /**
     * The control for the test above, so it cannot pass against a build that
     * refused every symlink in the user's tier: a link whose target is INSIDE the
     * directory still resolves, exactly as it does for the project tier.
     */
    public function testAUserTierSymlinkThatStaysInsideTheDirectoryStillLoads(): void
    {
        [$userDir] = $this->twoTierDirs();

        file_put_contents($userDir . '/real.yaml', "name: aliased\ndescription: Mine, here\nstages: []\n");
        $this->assertTrue(symlink($userDir . '/real.yaml', $userDir . '/aliased.yaml'));

        $registry = new WorkflowRegistry($userDir);

        $this->assertSame(['aliased', 'real'], $registry->list());
        $this->assertSame('Mine, here', $registry->load('aliased')->description);
    }

    /**
     * When ONE directory is both tiers — a session rooted at the user's own
     * home — the entry is confined to it.
     *
     * THIS USED TO BE THE TIE-BREAK TEST and is now a plain confinement one, which
     * is worth saying rather than leaving the name to imply a distinction that has
     * gone: both tiers confine their entries, so the dedupe in
     * `yamlDirectories()` no longer decides a policy question here — only which
     * ANCHOR the directory itself was judged against, which this fixture does not
     * exercise. What it still pins is that a collided directory does not lose the
     * per-entry check, which is the direction a "tidy" of the dedupe would break.
     */
    public function testWhenBothTiersNameOneDirectoryTheStricterTierWins(): void
    {
        $shared = $this->tempDir . '/shared-workflows';
        mkdir($shared, 0755, true);

        $elsewhere = $this->tempDir . '/elsewhere';
        mkdir($elsewhere, 0755, true);
        file_put_contents($elsewhere . '/out.yaml', "name: out\nstages: []\n");
        $this->assertTrue(symlink($elsewhere . '/out.yaml', $shared . '/out.yaml'));
        file_put_contents($shared . '/in.yaml', "name: in\nstages: []\n");

        $registry = new WorkflowRegistry($shared, $shared);

        $this->assertSame(['in'], $registry->list());
        $this->expectException(WorkflowNotFoundException::class);
        $registry->load('out');
    }

    /**
     * The escape per-entry confinement is structurally blind to: the project
     * workflows DIRECTORY committed as a symlink.
     *
     * `containedIn()` resolves both sides, so it judges an entry against the
     * RESOLVED directory — and when the directory is the link, the boundary
     * travels with it and nothing inside it is ever outside it. Committing
     * `.sugar-crush/workflows` as a link is not hypothetical: it is exactly the
     * path `Bootstrap::workflowEngine()` builds, and git stores it happily.
     *
     * The sentinel is in a `description`, which is the field that reaches the
     * listing and the transcript; the enumeration half is pinned by the
     * `list()` assertion, because a directory of yaml files whose NAMES are
     * disclosed is already a leak before anything is loaded.
     */
    public function testASymlinkedProjectWorkflowsDirectoryIsNeitherListedNorLoaded(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($userDir, 0755, true);
        file_put_contents($userDir . '/mine.yaml', "name: mine\nstages: []\n");

        $victim = $this->tempDir . '/victim';
        mkdir($victim, 0755, true);
        file_put_contents($victim . '/creds.yaml', "name: creds\ndescription: SENTINEL-VICTIM-CONTENT\nstages: []\n");

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($victim, $projectDir), 'test needs a real symlinked directory');

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertSame(['mine'], $registry->list(), 'a symlinked project directory must disclose no basename');

        try {
            $registry->load('creds');
            $this->fail('a workflow behind a symlinked project directory must not load');
        } catch (WorkflowNotFoundException | WorkflowLoadException $e) {
            $this->assertStringNotContainsString('SENTINEL-VICTIM-CONTENT', $e->getMessage());
        }
    }

    /**
     * The same refusal for a caller that gave no project root, which is the
     * anchor the registry falls back to being the directory's own parent.
     *
     * Pinned separately because the fallback is the WEAKER of the two boundaries
     * and its limit is documented rather than absolute — a test that only ever
     * passed the root would leave the documented fallback unmeasured, which is
     * the class of claim this file keeps getting wrong.
     */
    public function testASymlinkedProjectWorkflowsDirectoryIsRefusedWithNoProjectRootToo(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($userDir, 0755, true);

        $victim = $this->tempDir . '/victim';
        mkdir($victim, 0755, true);
        file_put_contents($victim . '/creds.yaml', "name: creds\ndescription: SENTINEL-VICTIM-CONTENT\nstages: []\n");

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($victim, $projectDir));

        $registry = new WorkflowRegistry($userDir, $projectDir);

        $this->assertSame([], $registry->list());
        $this->expectException(WorkflowNotFoundException::class);
        $registry->load('creds');
    }

    /**
     * The control the two tests above need: a symlinked project workflows
     * directory that stays INSIDE the checkout still works.
     *
     * Without this they would both also pass against a build that refused every
     * symlinked directory — and refusing one is wrong, because a repository
     * linking `.sugar-crush/workflows -> tools/workflows` is repository content
     * pointing at repository content, the same trust as a committed `.yaml`.
     *
     * It also shows what the project root BUYS, which is why the registry takes
     * one: the fallback anchor is this directory's own parent, and
     * `repo/tools/workflows` is not inside `repo/.sugar-crush`, so this layout
     * loads only for the caller that says where the checkout is.
     */
    public function testASymlinkedProjectWorkflowsDirectoryInsideTheCheckoutStillLoads(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($root . '/tools/workflows', 0755, true);
        mkdir($userDir, 0755, true);
        file_put_contents($root . '/tools/workflows/ship.yaml', "name: ship\ndescription: From the repo\nstages: []\n");

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($root . '/tools/workflows', $projectDir));

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertSame(['ship'], $registry->list());
        $this->assertSame('From the repo', $registry->load('ship')->description);
    }

    /**
     * The trailing separator in `containedIn()` is load-bearing, and nothing
     * measured it: `str_starts_with($realPath, $realDir)` without it reads
     * `/a/bevil/x.yaml` as living inside `/a/b`.
     *
     * A repository controls both names, so shipping a `projevil/` beside the
     * `proj/` its workflows directory is, plus one committed link between them,
     * is entirely within reach. Dropping the `. '/'` from either side of that
     * comparison leaves the rest of this file green and makes this file load.
     */
    public function testASiblingDirectorySharingTheProjectDirsNameIsNotContained(): void
    {
        $userDir = $this->tempDir . '/home-workflows';
        $projectDir = $this->tempDir . '/proj';
        $sibling = $this->tempDir . '/projevil';
        mkdir($userDir, 0755, true);
        mkdir($projectDir, 0755, true);
        mkdir($sibling, 0755, true);

        file_put_contents($sibling . '/x.yaml', "name: sib\ndescription: SENTINEL-SIBLING-CONTENT\nstages: []\n");
        $this->assertTrue(symlink($sibling . '/x.yaml', $projectDir . '/sib.yaml'));
        file_put_contents($projectDir . '/ok.yaml', "name: ok\nstages: []\n");

        $registry = new WorkflowRegistry($userDir, $projectDir, $this->tempDir);

        $this->assertSame(['ok'], $registry->list(), 'a name-prefix sibling is not a subdirectory');

        try {
            $registry->load('sib');
            $this->fail('a link into a directory that merely shares the prefix must not load');
        } catch (WorkflowNotFoundException | WorkflowLoadException $e) {
            $this->assertStringNotContainsString('SENTINEL-SIBLING-CONTENT', $e->getMessage());
        }
    }

    /**
     * A genuinely malformed file the user wrote themselves still reports
     * something usable — the LINE — while never quoting the file's content.
     *
     * Both halves matter. Withholding the parser message is what closes the
     * leak; keeping the line number is what stops the fix from turning "your
     * YAML is broken at line 3" into "your YAML is broken, good luck".
     */
    public function testAMalformedYamlErrorNamesTheLineButQuotesNoneOfTheFile(): void
    {
        [$userDir] = $this->twoTierDirs();
        // The sentinel is ON the offending line on purpose: Symfony's
        // ParseException quotes the line it choked on in a `(near "...")`
        // clause, which is the exact mechanism that leaked a line of a
        // symlinked private key into the transcript. A fixture whose bad line
        // is elsewhere would pass whether or not the message is withheld.
        file_put_contents(
            $userDir . '/broken-secret.yaml',
            "name: broken-secret\nSENTINELSECRETBYTES: aaa: bbb\n",
        );

        $registry = new WorkflowRegistry($userDir);

        try {
            $registry->load('broken-secret');
            $this->fail('a malformed YAML file must be reported');
        } catch (WorkflowLoadException $e) {
            $this->assertStringContainsString('is not valid YAML', $e->getMessage());
            $this->assertStringContainsString('line 2', $e->getMessage());
            $this->assertStringNotContainsString('SENTINELSECRETBYTES', $e->getMessage());
        }
    }

    // =========================================================================
    // Shapes the loader READS, so shapes the loader checks
    // =========================================================================

    /**
     * `parallel: "true"` — the quoted form a YAML author writes by accident —
     * used to fail both of the two separate `=== true` tests, so the whole
     * `agents:` list (including its tool declarations, which nothing then
     * permission-checked) was never read, one no-tool agent ran on the
     * stage-level prompt, and the run reported **completed**. Verbatim the
     * "silent failure reported as a success" the `stages: nope` check exists to
     * stop, one level down.
     */
    public function testAQuotedParallelFlagIsRefusedRatherThanSilentlyDegradingTheFanOut(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/quoted-parallel.yaml',
            "name: quoted-parallel\nstages:\n  - name: fan\n    parallel: \"true\"\n    prompt: leftover\n"
            . "    agents:\n      - {type: coder, name: style-fixer, prompt: A, tools: [Bash, Edit]}\n",
        );

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('"parallel" must be a boolean (true or false, unquoted), got string');

        $registry->load('quoted-parallel');
    }

    /**
     * The control: the unquoted form still builds a real fan-out, with each
     * agent's own declared tools on it. Refusing the quoted spelling is only
     * correct if the correct spelling still works.
     */
    public function testAnUnquotedParallelFlagStillBuildsTheFanOutWithEachAgentsTools(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/real-parallel.yaml',
            "name: real-parallel\nstages:\n  - name: fan\n    parallel: true\n"
            . "    agents:\n      - {type: coder, name: style-fixer, prompt: A, tools: [Bash, Edit]}\n"
            . "      - {type: coder, name: doc-fixer, prompt: B, tools: [Read]}\n",
        );

        $workflow = (new WorkflowRegistry($userDir))->load('real-parallel');

        $this->assertCount(1, $workflow->stages);
        $this->assertSame('parallel', $workflow->stages[0]['type']);
        $this->assertCount(2, $workflow->stages[0]['tasks']);
        $this->assertSame(['Bash', 'Edit'], $workflow->stages[0]['tasks'][0]->tools);
        $this->assertSame(['Read'], $workflow->stages[0]['tasks'][1]->tools);
    }

    /**
     * `parallel: false` is a legal, explicit "no" and must keep loading as a
     * plain stage — the refusal above is about the SHAPE, not about the key.
     */
    public function testAnExplicitFalseParallelFlagLoadsAsAPlainStage(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/not-parallel.yaml',
            "name: not-parallel\nstages:\n  - name: one\n    parallel: false\n    agent: coder\n    prompt: Go\n",
        );

        $workflow = (new WorkflowRegistry($userDir))->load('not-parallel');

        $this->assertSame('stage', $workflow->stages[0]['type']);
    }

    /**
     * "Is a list" used to mean `is_array()`, which is a different claim: a MAP
     * satisfied it and loaded with the author's keys silently discarded. Three
     * keys said "list" and tested `is_array` — `stages`, `tools`, and a parallel
     * stage's `agents` — and the docblock explicitly invited an audit of the
     * claim.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function mapWhereAListIsRequired(): iterable
    {
        yield 'stages as a map' => [
            "name: m\nstages:\n  first:\n    name: one\n    agent: coder\n",
            '"stages" must be a list of stages, got a map',
        ];
        yield 'tools as a map' => [
            "name: m\nstages:\n  - name: one\n    agent: coder\n    tools:\n      a: Read\n",
            '"tools" must be a list of tool names, got a map',
        ];
        yield 'parallel agents as a map' => [
            "name: m\nstages:\n  - name: fan\n    parallel: true\n    agents:\n      x:\n        type: coder\n",
            'its "agents" must be a list, got a map',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('mapWhereAListIsRequired')]
    public function testAMapIsRefusedWhereTheLoaderDocumentsAList(string $yaml, string $expectedMessage): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/m.yaml', $yaml);

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage($expectedMessage);

        $registry->load('m');
    }

    /**
     * A key present with an explicit null is refused, not read as absent.
     * `isset()` cannot tell those apart, and `stages: ~` was the expensive
     * case: it loaded as a workflow with zero stages, which `/workflow run`
     * then reported as `completed` having executed nothing.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function explicitNullValues(): iterable
    {
        yield 'stages' => ["name: n\nstages: ~\n", '"stages" must be a list of stages, got null'];
        yield 'config.timeout' => ["name: n\nstages: []\nconfig:\n  timeout: ~\n", '"config.timeout" must be a whole number, got null'];
        yield 'config.maxConcurrent' => ["name: n\nstages: []\nconfig:\n  maxConcurrent: ~\n", '"config.maxConcurrent" must be a whole number, got null'];
        yield 'stage prompt' => ["name: n\nstages:\n  - name: one\n    prompt: ~\n", '"prompt" must be a string, got null'];
        yield 'stage tools' => ["name: n\nstages:\n  - name: one\n    tools: ~\n", '"tools" must be a list of tool names, got null'];
        yield 'description' => ["name: n\ndescription: ~\nstages: []\n", '"description" must be a string, got null'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('explicitNullValues')]
    public function testAKeyWrittenWithAnExplicitNullIsRefused(string $yaml, string $expectedMessage): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/n.yaml', $yaml);

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage($expectedMessage);

        $registry->load('n');
    }

    /**
     * Two stages with one name: `{{build.output}}` names a stage, and the
     * engine keys stage output as `<name>.output`, so a duplicate makes every
     * reference to either one mean "whichever ran last". There is no answer the
     * interpolation can give that is not a guess.
     *
     * The message names the two INDICES and not the stage name, and the sentinel
     * is what pins it: this was the only one of the loader's load-error paths
     * that interpolated a value read out of the file, i.e. the undocumented
     * exception to the withhold-the-content policy the parse-error arm states.
     */
    public function testTwoStagesWithTheSameNameAreRefusedWithoutQuotingTheName(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents(
            $userDir . '/dupes.yaml',
            "name: dupes\nstages:\n  - name: SENTINELSECRET\n    agent: coder\n"
            . "  - name: other\n    agent: coder\n  - name: SENTINELSECRET\n    agent: reviewer\n",
        );

        $registry = new WorkflowRegistry($userDir);

        try {
            $registry->load('dupes');
            $this->fail('two stages sharing one name must be refused');
        } catch (WorkflowLoadException $e) {
            $this->assertStringContainsString('has two stages with the same name', $e->getMessage());
            $this->assertStringContainsString('(stages #0 and #2)', $e->getMessage());
            $this->assertStringNotContainsString(
                'SENTINELSECRET',
                $e->getMessage(),
                'no load-error path may echo a value read out of the file back to the transcript',
            );
        }
    }

    /**
     * The document's own `name:` is held to the same rule as the name used to
     * look it up. `name: " w "` used to load and flow through the engine's
     * `generateWorkflowId()` into a pause FILENAME — bounded by
     * `getPauseFilePath()`'s own `..`/`/` guard, so asymmetry rather than a
     * hole, but a name the loader accepts and the lookup rejects is a name
     * `/workflow run` can never be used on.
     */
    public function testADocumentNameTheLookupWouldRejectIsRefusedAtLoadTime(): void
    {
        [$userDir] = $this->twoTierDirs();
        file_put_contents($userDir . '/spacey.yaml', "name: \" w \"\nstages: []\n");

        $registry = new WorkflowRegistry($userDir);

        $this->expectException(WorkflowLoadException::class);
        $this->expectExceptionMessage('has an unusable "name"');

        $registry->load('spacey');
    }

    /**
     * The shipped example still loads unchanged — the one file in the
     * repository that exercises `parallel: true`, `tools:` lists and a
     * `config:` block together, so it is the regression test for every shape
     * check above being stricter than intended.
     */
    public function testTheShippedLintThenFixExampleStillLoads(): void
    {
        $path = dirname(__DIR__, 2) . '/examples/workflows/lint-then-fix.yaml';
        $this->assertFileExists($path);

        $dir = $this->tempDir . '/shipped';
        mkdir($dir, 0755, true);
        copy($path, $dir . '/lint-then-fix.yaml');

        $workflow = (new WorkflowRegistry($dir))->load('lint-then-fix');

        $this->assertSame('lint-then-fix', $workflow->name);
        $this->assertCount(3, $workflow->stages);
        $this->assertSame(['stage', 'parallel', 'stage'], array_column($workflow->stages, 'type'));
        $this->assertSame(3, $workflow->maxConcurrent);
    }

    /**
     * Two existing, populated directories: the user's own and a project's.
     *
     * @return array{0:string,1:string}
     */
    private function twoTierDirs(): array
    {
        $userDir = $this->tempDir . '/home-workflows';
        $projectDir = $this->tempDir . '/repo/.sugar-crush/workflows';
        mkdir($userDir, 0755, true);
        mkdir($projectDir, 0755, true);

        return [$userDir, $projectDir];
    }

    /**
     * The escape the DIRECTORY-level check used to accept because it counted
     * "resolves onto the anchor" as contained: `.sugar-crush/workflows -> ..`.
     *
     * One committed line, and it needs no knowledge of the victim's machine. The
     * boundary was "inside the checkout"; a link to `..` resolves EXACTLY to the
     * checkout root, which is where a developer's untracked, gitignored files
     * conventionally sit. Measured against the pre-fix build with the fixture
     * below: `list()` returned `["kubeconfig","local-secrets"]` and
     * `load('local-secrets')->description` was the sentinel.
     *
     * This is also the only test of the `$realPath === $realDir` arm in either
     * direction — it had none, which is why the arm could be the answer to two
     * questions with opposite right answers and nothing noticed.
     */
    public function testAProjectWorkflowsDirectoryResolvingOntoTheCheckoutRootIsRefused(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($userDir, 0755, true);
        file_put_contents($userDir . '/mine.yaml', "name: mine\nstages: []\n");

        // Untracked developer-local files, which is the point: they are inside
        // the checkout and are not repository content.
        file_put_contents($root . '/kubeconfig.yaml', "name: kubeconfig\nstages: []\n");
        file_put_contents(
            $root . '/local-secrets.yaml',
            "name: local-secrets\ndescription: SENTINEL-LOCAL-ONLY\nstages: []\n",
        );

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink('..', $projectDir), 'test needs a real relative symlink');

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertSame(
            ['mine'],
            $registry->list(),
            'a workflows directory resolving onto the checkout root must disclose no basename from it',
        );

        try {
            $registry->load('local-secrets');
            $this->fail('a file in the checkout root must not be loadable as a project workflow');
        } catch (WorkflowNotFoundException | WorkflowLoadException $e) {
            $this->assertStringNotContainsString('SENTINEL-LOCAL-ONLY', $e->getMessage());
        }
    }

    /**
     * The control for the test above, and the reason the rule is "strictly
     * inside" rather than "refuse every link": a link that lands somewhere else
     * INSIDE the checkout is still honoured.
     *
     * Distinct from `testASymlinkedProjectWorkflowsDirectoryInsideTheCheckoutStillLoads`
     * in the depth it exercises — that one links to `repo/tools/workflows`, this
     * one to a directory one level below the root, which is the shallowest
     * in-checkout target the strictness rule still has to accept.
     */
    public function testAProjectWorkflowsDirectoryResolvingOneLevelInsideTheCheckoutStillLoads(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($root . '/wf', 0755, true);
        mkdir($userDir, 0755, true);
        file_put_contents($root . '/wf/ship.yaml', "name: ship\ndescription: From the repo\nstages: []\n");

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink('../wf', $projectDir));

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertSame(['ship'], $registry->list());
        $this->assertSame('From the repo', $registry->load('ship')->description);
    }

    /**
     * "The refusal drops the tier rather than emptying it" — documented three
     * times on `WorkflowRegistry` and measured nowhere until this test.
     *
     * The sabotage it exists to catch: making a refused directory vanish from
     * BOTH tiers. That is invisible to every other test in this file, because no
     * other one puts a REFUSED directory in both tiers at once. Here `$shared`
     * is the user's own workflows directory AND the configured project one, and
     * it resolves outside the PROJECT anchor — so the project tier drops it and
     * `yamlDirectories()` must offer it to `readableUserDir()`, which judges the
     * same directory against the user's home instead of the checkout (here,
     * with no `$userHome` passed, against its own parent).
     *
     * "UNDER THE USER TIER'S UNCONFINED READING" is what this doc-block used to
     * say, and that tier is no longer unconfined: the entries of a directory added
     * back this way are confined to it exactly as a project tier's are. What is
     * still pinned is that the directory is added back AT ALL.
     */
    public function testACollidedDirectoryRefusedAsTheProjectTierIsStillReadAsTheUserTier(): void
    {
        $shared = $this->tempDir . '/shared-workflows';
        $anchor = $this->tempDir . '/unrelated-checkout';
        mkdir($shared, 0755, true);
        mkdir($anchor, 0755, true);
        file_put_contents($shared . '/mine.yaml', "name: mine\ndescription: The user's own\nstages: []\n");

        $registry = new WorkflowRegistry($shared, $shared, $anchor);

        $this->assertNotNull(
            $registry->projectTierRefusal(),
            'the fixture is only meaningful while the project tier IS refused',
        );
        $this->assertSame(
            ['mine'],
            $registry->list(),
            'a refused project tier must drop to the user tier, not empty the directory out of both',
        );
        $this->assertSame("The user's own", $registry->load('mine')->description);
    }

    /**
     * A DANGLING project workflows symlink is refused, and a MISSING directory
     * is not — the two halves of "does not resolve" that used to share one
     * answer.
     *
     * The refused half closes a check-then-use window a repository could open by
     * itself: granting a dangling link and reading through it later means a
     * target created in between is read, and per-ENTRY confinement cannot refuse
     * it because by then both sides resolve through the same link. A link naming
     * nothing has no workflows to lose, so refusing it costs nothing.
     */
    public function testADanglingProjectWorkflowsSymlinkIsRefusedRatherThanGrantedForLater(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($userDir, 0755, true);

        $target = $this->tempDir . '/appears-later';
        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($target, $projectDir), 'test needs a dangling symlink');

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertNotNull($registry->projectTierRefusal(), 'a dangling directory link must be refused');
        try {
            $registry->load('creds');
            $this->fail('nothing is loadable through a refused directory');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringNotContainsString(
                $projectDir,
                $e->getMessage(),
                'a refused directory is not a directory the loader looked in',
            );
        }

        // The window this closes, made concrete: the target appears, and the
        // directory is still refused rather than read through.
        mkdir($target, 0755, true);
        file_put_contents($target . '/creds.yaml', "name: creds\ndescription: SENTINEL-APPEARED-LATER\nstages: []\n");

        $this->assertSame([], $registry->list(), 'a target that appears later must not become readable');
        try {
            $registry->load('creds');
            $this->fail('a target that appears later must not become loadable');
        } catch (WorkflowNotFoundException | WorkflowLoadException $e) {
            $this->assertStringNotContainsString('SENTINEL-APPEARED-LATER', $e->getMessage());
        }
    }

    /**
     * The control the test above needs: a project workflows directory that is
     * simply NOT THERE is still named in the not-found message.
     *
     * Without this, refusing the dangling case could be "implemented" by
     * refusing every unresolvable path — which would take the fresh-checkout
     * message with it and send a user who has not created the directory yet
     * looking somewhere else entirely.
     */
    public function testAProjectWorkflowsDirectoryThatWasNeverCreatedIsStillNamedInTheNotFoundMessage(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root, 0755, true);
        mkdir($userDir, 0755, true);

        $projectDir = $root . '/.sugar-crush/workflows';
        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $this->assertNull($registry->projectTierRefusal(), 'absent is not refused');

        try {
            $registry->load('zzz');
            $this->fail('nothing exists to load');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringContainsString($projectDir, $e->getMessage());
        }
    }

    /**
     * `projectTierRefusal()` is the only thing that can tell a user their
     * repository's workflows directory was rejected, so it says WHICH path and
     * WHY — and answers null when nothing was rejected.
     *
     * Everything else about a refused tier is silent by design: the not-found
     * message drops the directory, `projectWorkflowsPath()` still reports the
     * configured path, and `list()` just has fewer names. Both of those silences
     * are asserted here so the accessor cannot be "fixed" by making one of them
     * noisy instead.
     */
    public function testARefusedProjectTierNamesItselfAndItsReasonWhileStayingSilentElsewhere(): void
    {
        $root = $this->tempDir . '/repo';
        $userDir = $this->tempDir . '/home-workflows';
        mkdir($root . '/.sugar-crush', 0755, true);
        mkdir($userDir, 0755, true);
        $outside = $this->tempDir . '/outside';
        mkdir($outside, 0755, true);

        $projectDir = $root . '/.sugar-crush/workflows';
        $this->assertTrue(symlink($outside, $projectDir));

        $registry = new WorkflowRegistry($userDir, $projectDir, $root);

        $refusal = $registry->projectTierRefusal();
        $this->assertNotNull($refusal);
        $this->assertStringContainsString($outside, $refusal, 'the refusal must say where it actually resolved to');

        // A REASON, NOT A SENTENCE, and pinned as such: the path belongs to the
        // caller (`projectWorkflowsPath()` here, the map key in
        // Bootstrap::projectTierRefusals()), and the one notice that prints it
        // composes `ignoring <path> — <reason>`. Repeating it inside the reason
        // put it in that line twice where the skills tier's equivalent printed it
        // once — three subsystems feed one collector and they now say it the same
        // way, so this asserts the ABSENCE rather than leaving it to prose.
        $this->assertStringNotContainsString(
            $projectDir,
            $refusal,
            'the reason must not repeat the path its caller already has',
        );

        // The silences the accessor exists to compensate for.
        $this->assertSame($projectDir, $registry->projectWorkflowsPath());
        try {
            $registry->load('zzz');
        } catch (WorkflowNotFoundException $e) {
            $this->assertStringNotContainsString($projectDir, $e->getMessage());
        }
    }

    public function testProjectTierRefusalIsNullForAnHonouredDirectoryAndForNoProjectTierAtAll(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();

        $this->assertNull((new WorkflowRegistry($userDir, $projectDir, $this->tempDir . '/repo'))->projectTierRefusal());
        $this->assertNull((new WorkflowRegistry($userDir))->projectTierRefusal());
    }

    /**
     * A path that is nothing but separators keeps one, because `rtrim('/','/')`
     * is the empty string and `realpath('')` is the process CWD.
     *
     * Reachable in production: `--root /` is accepted (`ArgvParser::rootError()`
     * only requires a directory), and this method's result is what
     * `readableProjectDir()` anchors containment on — so the anchor silently
     * became `getcwd()`. It failed SAFE, since a narrower anchor refuses more,
     * but the boundary was a path nobody named. Measured before the fix:
     * `workflowsPath()` returned `''`.
     */
    public function testARootOnlyPathExpandsToRootRatherThanTheEmptyStringOrTheProcessCwd(): void
    {
        $registry = new WorkflowRegistry('/', '/', '/');

        $this->assertSame('/', $registry->workflowsPath());
        $this->assertSame('/', $registry->projectWorkflowsPath());
        $this->assertNotSame(getcwd(), $registry->workflowsPath());

        // Multiple trailing separators collapse the same way, and an ordinary
        // path is untouched.
        $trailing = new WorkflowRegistry($this->tempDir . '/wf///', '///');
        $this->assertSame($this->tempDir . '/wf', $trailing->workflowsPath());
        $this->assertSame('/', $trailing->projectWorkflowsPath());
    }

    /**
     * The substitution a cloned repository can actually perform, which the suite
     * had yaml-over-yaml and php-over-php for but not this: a project
     * `deploy.yaml` shadows the USER'S OWN `deploy.php`.
     *
     * Deliberate — `load()`'s project-first fast path runs before
     * `resolvePhpPath()` — and asserted rather than assumed, because "the
     * override it can perform is bounded to data" is a statement about the
     * PAYLOAD that says nothing about the substitution. The payload half is
     * asserted too: the user's PHP file is not executed, so the repository
     * replaces code with data and never the reverse.
     */
    public function testAProjectYamlShadowsASameNamedUserPhpWorkflow(): void
    {
        [$userDir, $projectDir] = $this->twoTierDirs();

        $marker = $this->tempDir . '/php-was-required';
        $this->createWorkflowFile($userDir, 'd', <<<PHP
        file_put_contents('{$marker}', 'yes');

        return (new WorkflowBuilder())->name('d')->description('USER-PHP-WINS')->build();
        PHP);
        file_put_contents($projectDir . '/d.yaml', "name: d\ndescription: REPO-YAML-WINS\nstages: []\n");

        $registry = new WorkflowRegistry($userDir, $projectDir, $this->tempDir . '/repo');

        $this->assertSame('REPO-YAML-WINS', $registry->load('d')->description);
        $this->assertFileDoesNotExist($marker, "the user's .php workflow must not have been require()d");
        $this->assertSame(['d'], $registry->list(), 'one name, whichever tier answers for it');
    }
}
