<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;

/**
 * @see SkillTool
 */
final class SkillToolTest extends TestCase
{
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempFiles = [];
    }

    public function testNameReturnsSkill(): void
    {
        $tool = new SkillTool(new SkillRegistry());

        $this->assertSame('Skill', $tool->name());
    }

    public function testDescriptionReturnsNonEmptyString(): void
    {
        $tool = new SkillTool(new SkillRegistry());

        $this->assertNotSame('', $tool->description());
    }

    public function testInputSchemaRequiresName(): void
    {
        $tool = new SkillTool(new SkillRegistry());
        $schema = $tool->inputSchema();

        $this->assertSame(['name'], $schema['required']);
        $this->assertArrayHasKey('name', $schema['properties']);
        $this->assertArrayHasKey('args', $schema['properties']);
    }

    public function testExecuteReturnsErrorForEmptyName(): void
    {
        $tool = new SkillTool(new SkillRegistry());

        $result = $tool->execute(['id' => 'call_1', 'name' => '']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('cannot be empty', $result->content());
    }

    public function testExecuteReturnsErrorForUnknownSkill(): void
    {
        $tool = new SkillTool(new SkillRegistry());

        $result = $tool->execute(['id' => 'call_2', 'name' => 'nonexistent']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('nonexistent', $result->content());
    }

    // =========================================================================
    // Regression: a skill with disable-model-invocation:true must stay
    // unreachable through this tool even though SkillRegistry::get() itself
    // happily returns it (get() only filters manually disable()'d skills, not
    // disableModelInvocation). A naive implementation that just called
    // registry->get($name) and, on a non-null result, loaded the body would
    // let the model invoke a skill explicitly marked as user-only.
    // =========================================================================
    public function testExecuteReturnsErrorForDisableModelInvocationSkill(): void
    {
        $path = $this->createSkillFile("---\ndescription: user only\n---\nbody content");
        $registry = new SkillRegistry();
        $registry->register([
            'user-only' => $this->skill('user-only', $path, disableModelInvocation: true),
        ]);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_3', 'name' => 'user-only']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('not model-invocable', $result->content());
    }

    public function testExecuteReturnsErrorForDisabledSkill(): void
    {
        $path = $this->createSkillFile("---\ndescription: d\n---\nbody");
        $registry = new SkillRegistry();
        $registry->register(['disabled-skill' => $this->skill('disabled-skill', $path)]);
        $registry->disable('disabled-skill');

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_4', 'name' => 'disabled-skill']);

        $this->assertTrue($result->isError());
    }

    // =========================================================================
    // Level-2 loading: the manifest-only Skill (as SkillRegistry::registerFromManifest
    // produces) carries content:'' — the real body lives only on disk at
    // sourcePath. A naive implementation that returned $skill->content instead
    // of loading the body from sourcePath would return an empty string here.
    // =========================================================================
    public function testExecuteLoadsBodyFromDiskNotFromInMemoryContent(): void
    {
        $path = $this->createSkillFile("---\ndescription: on demand\n---\nThe real body lives on disk.");
        $registry = new SkillRegistry();
        $registry->registerFromManifest([
            'name' => 'on-demand',
            'description' => 'on demand',
            'disableModelInvocation' => false,
            'userInvocable' => true,
            'context' => 'thread',
            'paths' => [],
            'sourcePath' => $path,
        ]);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_5', 'name' => 'on-demand']);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('The real body lives on disk.', $result->content());
    }

    public function testExecuteReturnsFormattedSkillHeaderAndBody(): void
    {
        $path = $this->createSkillFile("---\ndescription: fmt\n---\nSome instructions here.");
        $registry = new SkillRegistry();
        $registry->register(['formatted' => $this->skill('formatted', $path)]);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_6', 'name' => 'formatted']);

        $this->assertSame("## Skill: formatted\n\nSome instructions here.", $result->content());
    }

    public function testExecutePassesThroughOptionalArgs(): void
    {
        $path = $this->createSkillFile("---\ndescription: args\n---\nbody");
        $registry = new SkillRegistry();
        $registry->register(['argful' => $this->skill('argful', $path)]);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_7', 'name' => 'argful', 'args' => 'some args']);

        $this->assertFalse($result->isError());
        $this->assertSame('call_7', $result->toolCallId());
    }

    public function testExecuteReturnsErrorWhenSourceFileMissing(): void
    {
        $registry = new SkillRegistry();
        $registry->register(['missing-file' => $this->skill('missing-file', '/nonexistent/SKILL.md')]);

        $tool = new SkillTool($registry);
        $result = $tool->execute(['id' => 'call_8', 'name' => 'missing-file']);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('Error loading skill body', $result->content());
    }

    private function skill(string $name, string $sourcePath, bool $disableModelInvocation = false): Skill
    {
        return new Skill(
            name: $name,
            description: "Skill: $name",
            userInvocable: true,
            disableModelInvocation: $disableModelInvocation,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $sourcePath,
        );
    }

    private function createSkillFile(string $content): string
    {
        $path = sys_get_temp_dir() . '/skilltool_test_' . uniqid('', true) . '.md';
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;

        return $path;
    }
}
