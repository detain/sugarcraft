<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Cli;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\RuleSource;

/**
 * Finding #9: the loader tags each rule with its tier and, for a rule the `p`
 * key saved, with `saved`, so `/permissions` can say where a rule came from.
 */
final class PermissionRulesSourceTest extends TestCase
{
    public function testAHandWrittenUserRuleIsUserSourced(): void
    {
        $rules = Bootstrap::permissionRules(
            ['permissionRules' => [['pattern' => 'Bash(git status *)', 'action' => 'allow']]],
            null,
            RuleSource::User,
            '/home/u/.candy-code/config.json',
        );

        self::assertCount(1, $rules);
        self::assertSame(RuleSource::User, $rules[0]->source);
        self::assertSame('/home/u/.candy-code/config.json', $rules[0]->file);
    }

    public function testARuleMarkedSavedIsSavedNotUser(): void
    {
        $rules = Bootstrap::permissionRules(
            ['permissionRules' => [['pattern' => 'Bash(npm test)', 'action' => 'allow', 'saved' => true]]],
            null,
            RuleSource::User,
            '/home/u/.candy-code/config.json',
        );

        self::assertSame(RuleSource::Saved, $rules[0]->source);
        self::assertSame(PermissionAction::Allow, $rules[0]->action);
    }

    public function testAProjectTierRuleKeepsTheProjectSource(): void
    {
        $rules = Bootstrap::permissionRules(
            ['permissionRules' => [['pattern' => 'Bash(git push *)', 'action' => 'deny', 'saved' => true]]],
            null,
            RuleSource::Project,
            '/repo/.candy-code/settings.json',
        );

        self::assertSame(RuleSource::Project, $rules[0]->source, 'a saved marker cannot lift a project rule into the saved tier');
    }
}
