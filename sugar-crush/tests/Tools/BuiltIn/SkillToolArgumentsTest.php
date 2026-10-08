<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools\BuiltIn;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Skills\Skill;
use SugarCraft\Crush\Skills\SkillRegistry;
use SugarCraft\Crush\Tools\BuiltIn\SkillTool;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Audit F-H4: the `args` SkillTool advertises reaches the skill body.
 *
 * Before the fix the schema declared `args` and execute() never read it, so a
 * body written around `$ARGUMENTS` arrived with the literal token and the
 * model's input was silently gone. Pinned here: substitution, the
 * `ARGUMENTS:` line appended to a body with no placeholder, the no-arguments
 * cases, and a malformed `args` answered as a tool error.
 *
 * @see SkillTool
 */
final class SkillToolArgumentsTest extends TestCase
{
    /** @var list<string> */
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

    public function testEveryPlaceholderIsReplacedByTheArgs(): void
    {
        $result = $this->invoke("Review \$ARGUMENTS.\nThen summarise \$ARGUMENTS again.", 'src/Foo.php');

        self::assertFalse($result->isError());
        self::assertSame(
            $this->header() . "Review src/Foo.php.\nThen summarise src/Foo.php again.",
            $result->content(),
        );
    }

    public function testBodyWithoutAPlaceholderGetsTheArgsAppended(): void
    {
        $result = $this->invoke('Deploy the service.', 'staging --dry-run');

        self::assertSame($this->header() . "Deploy the service.\n\nARGUMENTS: staging --dry-run", $result->content());
    }

    public function testMissingArgsLeavesAPlaceholderFreeBodyUnchanged(): void
    {
        $result = $this->invoke('Deploy the service.', null);

        self::assertSame($this->header() . 'Deploy the service.', $result->content());
    }

    public function testEmptyArgsEmptiesThePlaceholderRatherThanLeakingIt(): void
    {
        $result = $this->invoke('Fix issue $ARGUMENTS now.', '   ');

        self::assertSame($this->header() . 'Fix issue  now.', $result->content());
    }

    public function testArgsContainingThePlaceholderAreNotExpandedTwice(): void
    {
        $result = $this->invoke('Echo: $ARGUMENTS', 'literal $ARGUMENTS here');

        self::assertSame($this->header() . 'Echo: literal $ARGUMENTS here', $result->content());
    }

    public function testShellDollarFormsInTheBodyAreLeftAlone(): void
    {
        $body = "Run: awk '{print \$1}' | xargs echo \$\$ \$PATH";

        $result = $this->invoke($body, 'x');

        self::assertSame($this->header() . "{$body}\n\nARGUMENTS: x", $result->content());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStringArgs(): iterable
    {
        yield 'integer' => [42];
        yield 'array' => [['a', 'b']];
        yield 'boolean' => [true];
    }

    #[DataProvider('nonStringArgs')]
    public function testNonStringArgsAreAToolErrorNotACrash(mixed $args): void
    {
        $result = $this->invoke('Body $ARGUMENTS', $args);

        self::assertTrue($result->isError());
        self::assertSame('Error: args must be a string', $result->content());
    }

    public function testNonStringNameIsAToolErrorNotACrash(): void
    {
        $result = (new SkillTool(new SkillRegistry()))->execute(['id' => 'c', 'name' => 7]);

        self::assertTrue($result->isError());
        self::assertSame('Error: name must be a string', $result->content());
    }

    public function testSubstituteArgumentsIsTheSameRuleOutsideTheTool(): void
    {
        self::assertSame('a b', SkillTool::substituteArguments('a $ARGUMENTS', ' b '));
        self::assertSame("a\n\nARGUMENTS: b", SkillTool::substituteArguments('a', 'b'));
        self::assertSame('a', SkillTool::substituteArguments('a', ''));
    }

    public function testSchemaDescribesWhatArgsDo(): void
    {
        $schema = (new SkillTool(new SkillRegistry()))->inputSchema();

        self::assertSame('string', $schema['properties']['args']['type']);
        self::assertStringContainsString('$ARGUMENTS', $schema['properties']['args']['description']);
    }

    /**
     * The two header lines every successful load now opens with: the display
     * name marker and the skill's base directory (the fixture files sit
     * directly in the temp dir, so dirname() is that dir — read, not guessed).
     */
    private function header(): string
    {
        return "## Skill: s\n\n> Base directory for this skill: " . sys_get_temp_dir() . "\n\n";
    }

    private function invoke(string $body, mixed $args): ToolResult
    {
        $path = sys_get_temp_dir() . '/skilltool_args_' . uniqid((string) getmypid(), true) . '.md';
        file_put_contents($path, "---\ndescription: d\n---\n{$body}");
        $this->tempFiles[] = $path;

        $registry = new SkillRegistry();
        $registry->register(['s' => new Skill(
            name: 's',
            description: 'Skill: s',
            userInvocable: true,
            disableModelInvocation: false,
            allowedTools: null,
            disallowedTools: null,
            model: null,
            effort: 'medium',
            context: 'thread',
            paths: [],
            content: '',
            sourcePath: $path,
        )]);

        $call = ['id' => 'call_args', 'name' => 's'];
        if ($args !== null) {
            $call['args'] = $args;
        }

        return (new SkillTool($registry))->execute($call);
    }
}
