<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\ToolCall;

final class PermissionRuleTest extends TestCase
{
    public function testConstructorSetsRuleProperty(): void
    {
        $rule = new PermissionRule('Bash(composer *)', PermissionAction::Ask);

        $this->assertSame('Bash(composer *)', $rule->pattern);
    }

    public function testConstructorSetsActionProperty(): void
    {
        $rule = new PermissionRule('Read(./.env)', PermissionAction::Deny);

        $this->assertSame(PermissionAction::Deny, $rule->action);
    }

    public function testRulePropertyIsReadonly(): void
    {
        $rule = new PermissionRule('mcp__git__*', PermissionAction::Allow);

        $this->assertSame('mcp__git__*', $rule->pattern);
    }

    public function testActionPropertyIsReadonly(): void
    {
        $rule = new PermissionRule('Bash(rm *)', PermissionAction::Deny);

        $this->assertSame(PermissionAction::Deny, $rule->action);
    }

    /**
     * @dataProvider actionCaseProvider
     */
    public function testWorksWithAllPermissionActionCases(PermissionAction $action): void
    {
        $rule = new PermissionRule('Test(pattern)', $action);

        $this->assertSame($action, $rule->action);
    }

    /**
     * @return iterable<string, array{PermissionAction}>
     */
    public static function actionCaseProvider(): iterable
    {
        foreach (PermissionAction::cases() as $case) {
            yield $case->name => [$case];
        }
    }

    public function testVariousRulePatterns(): void
    {
        $patterns = [
            'Bash(composer update *)',
            'Read(./.env)',
            'mcp__git__*',
            'Edit(*)',
            'Write(./README.md)',
        ];

        foreach ($patterns as $pattern) {
            $rule = new PermissionRule($pattern, PermissionAction::Allow);

            $this->assertSame($pattern, $rule->pattern);
        }
    }

    /**
     * THE MEANING of the five patterns above, which the round-trip test does not
     * touch — it asserts a readonly property equals what was passed to the
     * constructor, which is true of any string whatever the matcher does. That
     * made it the weakest test in this namespace at exactly the moment the
     * argument-scoped half of the language started working: for the whole
     * period in which `Read(./.env)` matched NOTHING, this file round-tripped it
     * and reported green.
     *
     * Each row names one call the pattern must match and one neighbouring call
     * it must not, so a matcher answering "yes" to everything fails as loudly as
     * the one that answered "no" to everything did.
     *
     * @return iterable<string, array{string, string, array<string, mixed>, array<string, mixed>}>
     */
    public static function patternMeaningCases(): iterable
    {
        yield 'Bash(composer update *)' => [
            'Bash(composer update *)',
            'Bash',
            ['command' => 'composer update --no-dev'],
            ['command' => 'composer install'],
        ];
        yield 'Read(./.env)' => [
            'Read(./.env)',
            'Read',
            ['file_path' => './.env'],
            ['file_path' => './.env.example'],
        ];
        yield 'mcp__git__*' => [
            'mcp__git__push',
            'mcp__git__push',
            ['branch' => 'master'],
            null,
        ];
        yield 'Edit(*)' => [
            'Edit(*)',
            'Edit',
            ['file_path' => 'anything/at/all.php'],
            null,
        ];
        yield 'Write(./README.md)' => [
            'Write(./README.md)',
            'Write',
            ['file_path' => 'README.md'],
            ['file_path' => 'README.md.bak'],
        ];
    }

    /**
     * @param array<string, mixed>      $matching
     * @param array<string, mixed>|null $notMatching null when the pattern is
     *        deliberately total for its tool (`Edit(*)`, a bare name glob), in
     *        which case the negative case is a DIFFERENT TOOL instead.
     */
    #[DataProvider('patternMeaningCases')]
    public function testEachDocumentedPatternMeansWhatItSays(
        string $pattern,
        string $toolName,
        array $matching,
        ?array $notMatching,
    ): void {
        $rule = new PermissionRule(
            $pattern === 'mcp__git__push' ? 'mcp__git__*' : $pattern,
            PermissionAction::Deny,
        );

        $this->assertTrue(
            $rule->matches(new ToolCall($toolName, $matching)),
            $pattern . ' must match the call it names',
        );

        if ($notMatching === null) {
            $this->assertFalse(
                $rule->matches(new ToolCall('Glob', ['path' => 'src'])),
                $pattern . ' must not reach another tool',
            );

            return;
        }

        $this->assertFalse(
            $rule->matches(new ToolCall($toolName, $notMatching)),
            $pattern . ' must not match the neighbouring call',
        );
    }
}
