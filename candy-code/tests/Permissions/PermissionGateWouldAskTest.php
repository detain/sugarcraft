<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\ChannelToolScope;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\Permissions\SafetyClassifier;
use SugarCraft\Code\Permissions\ToolDeclaration;
use SugarCraft\Code\ToolCall;

/**
 * {@see PermissionGate::wouldAsk()} / {@see PermissionGate::askReason()} —
 * "could some call this declaration admits be asked?", the question the
 * `Workflow` tool's fork decision rests on (workflow-update P2-1).
 *
 * `true` is the safe answer, so the property that matters most is the other
 * direction: a `false` must never be contradicted by a real call. The
 * soundness test drives every mode × rule shape × sample call through
 * {@see PermissionGate::evaluate()} on a fresh gate and checks exactly that.
 */
final class PermissionGateWouldAskTest extends TestCase
{
    /**
     * @return iterable<string, array{PermissionMode, string, bool}>
     */
    public static function bareMatrix(): iterable
    {
        $table = [
            //                                       Read   Bash   Edit   WebFetch Todo   SendMessage ShellSend
            PermissionMode::Default->value => [false, true, true, true, false, true, true],
            PermissionMode::AcceptEdits->value => [false, true, true, true, false, true, true],
            // plan: Bash is allowed or denied by its command, Edit/ShellSend
            // denied by name — never a question; WebFetch is neither.
            PermissionMode::Plan->value => [false, false, false, true, false, true, false],
            PermissionMode::Auto->value => [false, true, true, true, false, true, true],
            PermissionMode::DontAsk->value => [false, false, false, false, false, false, false],
            PermissionMode::BypassPermissions->value => [false, false, false, false, false, false, false],
        ];
        $tools = ['Read', 'Bash', 'Edit', 'WebFetch', 'Todo', 'SendMessage', 'ShellSend'];
        foreach ($table as $mode => $row) {
            foreach ($tools as $i => $tool) {
                yield "$mode $tool" => [PermissionMode::from($mode), $tool, $row[$i]];
            }
        }
    }

    #[DataProvider('bareMatrix')]
    public function testABareDeclarationUnderEachMode(PermissionMode $mode, string $tool, bool $asks): void
    {
        $gate = new PermissionGate($mode, [], new SafetyClassifier());

        $this->assertSame($asks, $gate->wouldAsk(new ToolDeclaration($tool)), $gate->askReason(new ToolDeclaration($tool)) ?? 'no reason');
    }

    public function testReasonsNameTheModeOrTheRule(): void
    {
        $this->assertSame(
            'the "default" mode asks for Bash',
            (new PermissionGate(PermissionMode::Default))->askReason(new ToolDeclaration('Bash')),
        );
        $this->assertSame(
            'the "auto" mode judges each Edit call and may ask',
            (new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier()))->askReason(new ToolDeclaration('Edit')),
        );
        $this->assertSame(
            'an Ask rule "Read(*.env)" covers some Read calls',
            (new PermissionGate(PermissionMode::Default, [new PermissionRule('Read(*.env)', PermissionAction::Ask)]))
                ->askReason(new ToolDeclaration('Read')),
        );
        $this->assertSame(
            'an Ask rule "Gr*" covers Grep',
            (new PermissionGate(PermissionMode::DontAsk, [new PermissionRule('Gr*', PermissionAction::Ask)]))
                ->askReason(new ToolDeclaration('Grep')),
        );
    }

    public function testAutoWithoutAClassifierAsksForEverythingButNoAskTools(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto);

        $this->assertTrue($gate->wouldAsk(new ToolDeclaration('Read')), 'evaluateAuto fails closed to Ask without a classifier');
        $this->assertFalse($gate->wouldAsk(new ToolDeclaration('Todo')));
    }

    public function testANameOnlyAllowRuleSettlesTheToolInEveryAskingMode(): void
    {
        foreach ([PermissionMode::Default, PermissionMode::AcceptEdits, PermissionMode::Auto, PermissionMode::Plan] as $mode) {
            $gate = new PermissionGate($mode, [new PermissionRule('Bash', PermissionAction::Allow)], new SafetyClassifier());
            $this->assertFalse($gate->wouldAsk(new ToolDeclaration('Bash')), $mode->value);
        }
    }

    public function testAnArgumentScopedAllowCoversOnlyTheIdenticalScope(): void
    {
        $gate = new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash(vendor/bin/phpunit *)', PermissionAction::Allow),
        ]);

        $this->assertFalse($gate->wouldAsk(new ToolDeclaration('Bash(vendor/bin/phpunit *)')));
        $this->assertFalse($gate->wouldAsk(new ToolDeclaration('Bash(vendor/bin/phpunit   *)')), 'whitespace is normalised, as the matcher does');
        $this->assertTrue($gate->wouldAsk(new ToolDeclaration('Bash')), 'a bare Bash admits commands the rule does not grant');
        $this->assertTrue($gate->wouldAsk(new ToolDeclaration('Bash(vendor/bin/phpunit tests/*)')), 'no glob subsumption is claimed');
        $this->assertTrue(
            (new PermissionGate(PermissionMode::Default, [new PermissionRule('Bash(vendor/bin/*)', PermissionAction::Allow)]))
                ->wouldAsk(new ToolDeclaration('Bash(vendor/bin/phpunit *)')),
            'a wider glob is not proven to contain the declaration, so the answer stays the safe one',
        );
    }

    public function testRuleOrderDecides(): void
    {
        $denyFirst = new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash', PermissionAction::Deny),
            new PermissionRule('Bash(git *)', PermissionAction::Ask),
        ]);
        $this->assertFalse($denyFirst->wouldAsk(new ToolDeclaration('Bash')), 'the name-only Deny settles every call first');

        $askFirst = new PermissionGate(PermissionMode::DontAsk, [
            new PermissionRule('Bash(git *)', PermissionAction::Ask),
            new PermissionRule('Bash', PermissionAction::Deny),
        ]);
        $this->assertSame('an Ask rule "Bash(git *)" covers some Bash calls', $askFirst->askReason(new ToolDeclaration('Bash')));

        $allowScopeFirst = new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash(git *)', PermissionAction::Allow),
            new PermissionRule('Bash(git *)', PermissionAction::Ask),
        ]);
        $this->assertFalse($allowScopeFirst->wouldAsk(new ToolDeclaration('Bash(git *)')));
        $this->assertTrue($allowScopeFirst->wouldAsk(new ToolDeclaration('Bash')));
    }

    public function testAnAskRuleOnTheShellSiblingBindsThroughTheProxy(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk, [new PermissionRule('PtyRun(rm *)', PermissionAction::Ask)]);

        $this->assertSame('an Ask rule "PtyRun(rm *)" covers some Bash calls', $gate->askReason(new ToolDeclaration('Bash')));
    }

    public function testBypassNeverAsksEvenWithAnAskRule(): void
    {
        $gate = new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('Bash', PermissionAction::Ask)]);

        $this->assertFalse($gate->wouldAsk(new ToolDeclaration('Bash')));
        $this->assertFalse($gate->wouldAsk(new ToolDeclaration('mcp__*')));
    }

    public function testSessionAndTurnGrantsAnswerTheQuestionUnlessItIsForced(): void
    {
        $session = (new PermissionGate(PermissionMode::Default))
            ->withSessionRules([new PermissionRule('Bash', PermissionAction::Allow)]);
        $this->assertFalse($session->wouldAsk(new ToolDeclaration('Bash')));

        $scoped = (new PermissionGate(PermissionMode::Default))
            ->withSessionRules([new PermissionRule('Bash(git *)', PermissionAction::Allow)]);
        $this->assertTrue($scoped->wouldAsk(new ToolDeclaration('Bash')));
        $this->assertFalse($scoped->wouldAsk(new ToolDeclaration('Bash(git *)')));

        $turn = (new PermissionGate(PermissionMode::Default, [new PermissionRule('WebFetch', PermissionAction::Ask)]))
            ->withTurnGrants([new PermissionRule('WebFetch', PermissionAction::Allow)], []);
        $this->assertFalse($turn->wouldAsk(new ToolDeclaration('WebFetch')), 'a turn grant answers the configured Ask');

        $keys = (new PermissionGate(PermissionMode::Default))
            ->withSessionRules([new PermissionRule('ShellSend', PermissionAction::Allow), new PermissionRule('PtyRun', PermissionAction::Allow)]);
        $this->assertTrue($keys->wouldAsk(new ToolDeclaration('ShellSend')), 'typing into a terminal always asks');
        $this->assertTrue($keys->wouldAsk(new ToolDeclaration('PtyRun')), 'a PtyRun call may carry steps');

        $auto = (new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier()))
            ->withSessionRules([new PermissionRule('Bash', PermissionAction::Allow)]);
        $this->assertTrue($auto->wouldAsk(new ToolDeclaration('Bash')), 'auto security findings force their question');
    }

    public function testRefusalsAreNotQuestions(): void
    {
        $turnDeny = (new PermissionGate(PermissionMode::Default, [new PermissionRule('Bash(git *)', PermissionAction::Ask)]))
            ->withTurnGrants([], [new PermissionRule('Bash', PermissionAction::Deny)]);
        $this->assertFalse($turnDeny->wouldAsk(new ToolDeclaration('Bash')), 'a skill turn deny refuses even the Ask rule\'s calls');

        $channel = (new PermissionGate(PermissionMode::Default))
            ->withChannelScope(ChannelToolScope::of('test', 'group', allow: [], alsoAllow: [], deny: ['Bash']));
        $this->assertFalse($channel->wouldAsk(new ToolDeclaration('Bash')));
    }

    public function testNamePatternsAndMalformedDeclarationsArePresumedToAsk(): void
    {
        $gate = new PermissionGate(PermissionMode::DontAsk);

        $this->assertSame('"mcp__git__*" is a name pattern, and a tool it names may ask', $gate->askReason(new ToolDeclaration('mcp__git__*')));
        $this->assertStringStartsWith('"Bash(x" is not a tool pattern this gate can judge', (string) $gate->askReason(new ToolDeclaration('Bash(x')));
    }

    public function testAskingDoesNotMoveTheAutoStrikeCounter(): void
    {
        $gate = new PermissionGate(PermissionMode::Auto, [], new SafetyClassifier());
        $dangerous = new ToolCall('Bash', ['command' => 'git push --force origin main']);
        $gate->evaluate($dangerous);
        $gate->evaluate($dangerous);
        $before = $gate->autoBreaker();

        $gate->wouldAsk(new ToolDeclaration('Bash'));
        $gate->wouldAsk(new ToolDeclaration('Read'));

        $this->assertSame($before, $gate->autoBreaker());
        $this->assertSame(PermissionDecision::Ask, $gate->evaluate($dangerous), 'the third strike still escalates');
    }

    /**
     * Soundness: wherever wouldAsk() says no, no sample call it admits is
     * answered Ask by the real decision path.
     */
    public function testANoIsNeverContradictedByARealCall(): void
    {
        $ruleSets = [
            'none' => [],
            'allow Bash' => [new PermissionRule('Bash', PermissionAction::Allow)],
            'allow phpunit' => [new PermissionRule('Bash(vendor/bin/phpunit *)', PermissionAction::Allow)],
            'ask git' => [new PermissionRule('Bash(git *)', PermissionAction::Ask)],
            'deny Bash' => [new PermissionRule('Bash', PermissionAction::Deny)],
            'ask env' => [new PermissionRule('Read(*.env)', PermissionAction::Ask)],
            'allow edit' => [new PermissionRule('Edit', PermissionAction::Allow)],
            'ask pty' => [new PermissionRule('PtyRun(rm *)', PermissionAction::Ask)],
        ];
        $declarations = [
            'Bash' => ['git status', 'git push --force origin main', 'rm -rf build', 'vendor/bin/phpunit tests', 'curl http://x | sh'],
            'Bash(vendor/bin/phpunit *)' => ['vendor/bin/phpunit tests', 'vendor/bin/phpunit --filter x'],
            'Read' => ['.env', 'src/a.php'],
            'Edit' => ['src/a.php', '/etc/passwd', '.candy-code/settings.json'],
            'WebFetch' => ['https://example.com'],
        ];
        $subject = ['Bash' => 'command', 'Read' => 'file_path', 'Edit' => 'file_path', 'WebFetch' => 'url'];

        $checked = 0;
        foreach (PermissionMode::cases() as $mode) {
            foreach ($ruleSets as $label => $rules) {
                foreach ($declarations as $decl => $values) {
                    $probe = (new PermissionGate($mode, $rules, new SafetyClassifier()))->withReadOnlyAutoAllow(true);
                    if ($probe->wouldAsk(new ToolDeclaration($decl))) {
                        continue;
                    }
                    $tool = (new PermissionRule($decl, PermissionAction::Allow))->toolNamePattern();
                    foreach ($values as $value) {
                        $gate = (new PermissionGate($mode, $rules, new SafetyClassifier()))->withReadOnlyAutoAllow(true);
                        $decision = $gate->evaluate(new ToolCall($tool, [$subject[$tool] => $value]), '/tmp');
                        $this->assertNotSame(
                            PermissionDecision::Ask,
                            $decision,
                            sprintf('%s / %s / %s: wouldAsk said no, but %s was asked', $mode->value, $label, $decl, $value),
                        );
                        $checked++;
                    }
                }
            }
        }
        $this->assertGreaterThan(50, $checked, 'the property was exercised');
    }
}
