<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SessionPermissionMemo;
use SugarCraft\Crush\ToolCall;

/**
 * Roadmap 1.C-2: "always allow (this session)" on the engine path remembers a
 * PATTERN, and the pattern reaches the gate as an `Allow` that can only answer
 * an `Ask` ({@see PermissionGate::withSessionRules()}).
 */
final class SessionPermissionMemoTest extends TestCase
{
    /** @return array<string, array{string, array<string, mixed>, list<string>}> */
    public static function grants(): array
    {
        return [
            'a plain command keeps its first word' => ['Bash', ['command' => 'ls -la src'], ['Bash(ls)', 'Bash(ls *)']],
            'a subcommand tool keeps two words' => ['Bash', ['command' => 'git status --short'], ['Bash(git status)', 'Bash(git status *)']],
            'an option is not a subcommand' => ['Bash', ['command' => 'git --version'], ['Bash(git)', 'Bash(git *)']],
            'npm run keeps the script name' => ['Bash', ['command' => 'npm run build -- --prod'], ['Bash(npm run build)', 'Bash(npm run build *)']],
            'an interpreter keeps its script' => ['Bash', ['command' => 'python3 tools/gen.py --write'], ['Bash(python3 tools/gen.py)', 'Bash(python3 tools/gen.py *)']],
            'inline interpreter code is exact' => ['Bash', ['command' => "php -r 'echo 1;'"], []],
            'a launcher is exact' => ['Bash', ['command' => 'bash -c ls'], []],
            'sudo is exact' => ['Bash', ['command' => 'sudo apt-get install x'], []],
            'a chain is exact' => ['Bash', ['command' => 'git add . && git commit -m x'], []],
            'a pipe is exact' => ['Bash', ['command' => 'ls | wc -l'], []],
            'a substitution is exact' => ['Bash', ['command' => 'echo $(id)'], []],
            'a writing redirection is exact' => ['Bash', ['command' => 'echo hi > out.txt'], []],
            'an env assignment is exact' => ['Bash', ['command' => 'FOO=1 make test'], []],
            'glob characters are escaped' => ['Bash', ['command' => 'ls*x'], ['Bash(ls\\*x)', 'Bash(ls\\*x *)']],
            'a path tool keeps the exact path' => ['Edit', ['file_path' => 'src/A.php', 'old_string' => 'a'], ['Edit(src/A.php)']],
            'WebFetch keeps the host' => ['WebFetch', ['url' => 'https://Example.com/docs?q=1'], ['WebFetch(domain:example.com)']],
            'a tool with no subject is the tool' => ['mcp__git__status', ['repo' => '.'], ['mcp__git__status']],
            'a missing subject is exact' => ['Edit', [], []],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param list<string>         $expected
     */
    #[DataProvider('grants')]
    public function testTheGrantAnAlwaysRecords(string $tool, array $arguments, array $expected): void
    {
        self::assertSame($expected, SessionPermissionMemo::patternsFor($tool, $arguments));
        foreach ($expected as $pattern) {
            self::assertTrue(PermissionRule::isWellFormedPattern($pattern), $pattern);
        }

        // Whatever the shape, the call that was answered is covered again.
        self::assertTrue(SessionPermissionMemo::new()->withGrant($tool, $arguments)->allows($tool, $arguments));
    }

    public function testAPatternCoversTheSameCommandWithOtherArgumentsAndNothingElse(): void
    {
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'git status']);

        self::assertTrue($memo->allows('Bash', ['command' => 'git status']));
        self::assertTrue($memo->allows('Bash', ['command' => 'git status --porcelain']));
        self::assertFalse($memo->allows('Bash', ['command' => 'git push --force']), 'a subcommand is part of what was granted');
        self::assertFalse($memo->allows('Bash', ['command' => 'git status && rm -rf x']), 'every command in a chain must be covered');
        self::assertFalse($memo->allows('Bash', ['command' => 'git status $(id)']), 'a substitution is not git');
        self::assertFalse($memo->allows('Read', ['file_path' => 'git status']));
    }

    public function testAnExactGrantCoversOnlyThatCallWhateverItsKeyOrder(): void
    {
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'ls | wc -l', 'timeout' => 5]);

        self::assertSame([], $memo->patterns(), 'nothing a rule could fire for');
        self::assertTrue($memo->allows('Bash', ['timeout' => 5, 'command' => 'ls | wc -l']));
        self::assertFalse($memo->allows('Bash', ['command' => 'ls | wc -c', 'timeout' => 5]));
    }

    public function testTheMemoRoundTripsThroughTheSessionGrantMapAndIgnoresOtherKeys(): void
    {
        $memo = SessionPermissionMemo::new()
            ->withGrant('Bash', ['command' => 'git log'])
            ->withGrant('Bash', ['command' => 'ls | wc -l']);
        $map = ['bash {"cmd":"x"}' => true, ...$memo->grants()];

        $back = SessionPermissionMemo::fromGrants($map);

        self::assertSame($memo->patterns(), $back->patterns());
        self::assertSame($memo->grants(), $back->grants(), 'the Chat-native exact key is not this class\'s');
        self::assertTrue($back->allows('Bash', ['command' => 'ls | wc -l']));
        self::assertSame([], SessionPermissionMemo::fromGrants(['rule:Bash(oops' => true, 'call:' => true])->grants(), 'malformed entries are skipped');
        self::assertTrue(SessionPermissionMemo::fromGrants([])->isEmpty());
    }

    public function testGrantingTheSameThingTwiceIsANoOp(): void
    {
        $once = SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'git log']);

        self::assertSame($once, $once->withGrant('Bash', ['command' => 'git log -p']));
    }

    public function testTheRulesAreAllows(): void
    {
        $rules = SessionPermissionMemo::new()->withGrant('Edit', ['file_path' => 'a.txt'])->rules();

        self::assertCount(1, $rules);
        self::assertSame(PermissionAction::Allow, $rules[0]->action);
        self::assertSame('Edit(a.txt)', $rules[0]->pattern);
    }

    // ── the gate ──────────────────────────────────────────────────────────

    public function testASessionRuleAnswersTheQuestionTheModeWouldHaveAsked(): void
    {
        $gate = new PermissionGate(PermissionMode::Default);
        $call = new ToolCall('Bash', ['command' => 'git status -s']);
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($call), 'fixture: default mode asks about Bash');

        $granted = $gate->withSessionRules(SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'git status'])->rules());

        self::assertSame(PermissionDecision::Allow, $granted->evaluate($call));
        self::assertSame(PermissionDecision::Ask, $granted->evaluate(new ToolCall('Bash', ['command' => 'git push'])));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate($call), 'the receiver is untouched');
        self::assertSame([], $gate->sessionRules());
        self::assertCount(2, $granted->sessionRules());
    }

    public function testASessionRuleNeverLiftsADeny(): void
    {
        $rules = [new PermissionRule('Bash(git *)', PermissionAction::Allow)];

        $configured = (new PermissionGate(PermissionMode::Default, [new PermissionRule('Bash(git push *)', PermissionAction::Deny)]))
            ->withSessionRules($rules);
        self::assertSame(PermissionDecision::Deny, $configured->evaluate(new ToolCall('Bash', ['command' => 'git push origin'])), 'a configured deny wins');

        $plan = (new PermissionGate(PermissionMode::Plan))->withSessionRules($rules);
        self::assertSame(PermissionDecision::Deny, $plan->evaluate(new ToolCall('Bash', ['command' => 'git commit -m x'])), 'plan mode refuses writes whatever was remembered');

        $breaker = (new PermissionGate(PermissionMode::Default))->withSessionRules([new PermissionRule('Bash(rm *)', PermissionAction::Allow)]);
        self::assertSame(PermissionDecision::Deny, $breaker->evaluate(new ToolCall('Bash', ['command' => 'rm -rf /'])));
    }

    public function testASessionRuleAlsoAnswersAConfiguredAsk(): void
    {
        $gate = (new PermissionGate(PermissionMode::BypassPermissions, [new PermissionRule('mcp__git__*', PermissionAction::Ask)]))
            ->withSessionRules(SessionPermissionMemo::new()->withGrant('mcp__git__status', [])->rules());

        self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('mcp__git__status', [])));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('mcp__git__push', [])));
    }

    public function testADeclarationIsNeverAnsweredByAnArgumentScopedGrant(): void
    {
        $gate = (new PermissionGate(PermissionMode::DontAsk))->withSessionRules([new PermissionRule('Bash(git *)', PermissionAction::Allow)]);

        self::assertTrue($gate->refuses(new \SugarCraft\Crush\Permissions\ToolDeclaration('Bash')));
    }

    public function testOnlyAllowRulesCanBeRemembered(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new PermissionGate(PermissionMode::Default))->withSessionRules([new PermissionRule('Bash', PermissionAction::Deny)]);
    }
}
