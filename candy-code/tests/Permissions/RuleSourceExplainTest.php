<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Host\Commands\PermissionsCommand;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\RuleSource;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\ToolCall;

/**
 * Finding #9: a rule carries where it came from, and the gate can say which
 * rule answers a call without evaluating it.
 */
final class RuleSourceExplainTest extends TestCase
{
    public function testARuleWithoutAnOriginIsUnknownAndHasNoFile(): void
    {
        $rule = new PermissionRule('Bash(git status *)', PermissionAction::Allow);

        self::assertSame(RuleSource::Unknown, $rule->source);
        self::assertNull($rule->file);
    }

    public function testTheSourceAndFileDoNotChangeWhatTheRuleMatches(): void
    {
        $call = new ToolCall('Bash', ['command' => 'git push origin main']);
        $bare = new PermissionRule('Bash(git push *)', PermissionAction::Deny);
        $sourced = new PermissionRule('Bash(git push *)', PermissionAction::Deny, RuleSource::Project, '/repo/.candy-code/settings.json');

        self::assertSame($bare->matches($call), $sourced->matches($call));
        self::assertTrue($sourced->matches($call));
    }

    public function testExplainReturnsTheFirstMatchingConfiguredRuleWithItsFile(): void
    {
        $deny = new PermissionRule('Bash(git push *)', PermissionAction::Deny, RuleSource::Project, '/repo/.candy-code/settings.json');
        $allow = new PermissionRule('Bash(git *)', PermissionAction::Allow, RuleSource::User, '/home/u/.candy-code/config.json');
        $gate = new PermissionGate(PermissionMode::Default, [$deny, $allow], new SafetyClassifier());

        $rule = $gate->explainRule(new ToolCall('Bash', ['command' => 'git push origin main']));

        self::assertSame($deny, $rule);
        self::assertSame(RuleSource::Project, $rule->source);
        self::assertSame('/repo/.candy-code/settings.json', $rule->file);
    }

    public function testExplainNamesTheSessionGrantThatAnswersACall(): void
    {
        $grant = new PermissionRule('Bash(npm test)', PermissionAction::Allow, RuleSource::Session);
        $gate = (new PermissionGate(PermissionMode::Default, [], new SafetyClassifier()))->withSessionRules([$grant]);

        $rule = $gate->explainRule(new ToolCall('Bash', ['command' => 'npm test']));

        self::assertNotNull($rule);
        self::assertSame(RuleSource::Session, $rule->source);
    }

    public function testExplainReturnsNullWhenNoRuleSpeaks(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [], new SafetyClassifier());

        self::assertNull($gate->explainRule(new ToolCall('Bash', ['command' => 'ls'])));
    }

    public function testExplainDoesNotMoveTheCircuitBreakerOrTheDecision(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [], new SafetyClassifier());
        $before = $gate->autoBreaker();

        $gate->explainRule(new ToolCall('Bash', ['command' => 'rm -rf /']));

        self::assertSame($before, $gate->autoBreaker());
    }

    public function testParseCallSplitsToolNameAndSubject(): void
    {
        self::assertSame(['Bash', 'git push'], PermissionsCommand::parseCall('Bash(git push)'));
        self::assertSame(['Read', null], PermissionsCommand::parseCall('Read'));
        self::assertNull(PermissionsCommand::parseCall('(git push)'));
        self::assertNull(PermissionsCommand::parseCall(''));
    }
}
