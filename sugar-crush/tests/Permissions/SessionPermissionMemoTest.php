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
            'a chain is generalised per segment' => ['Bash', ['command' => 'git add . && git commit -m x'], ['Bash(git add * && git commit *)']],
            'a chain of launchers is exact' => ['Bash', ['command' => 'find . -name x | xargs rm'], []],
            'a pipe is generalised per segment' => ['Bash', ['command' => 'ls | wc -l'], ['Bash(ls * | wc *)']],
            'the user\'s pipeline' => ['Bash', ['command' => 'sed -n 1,5p f | sort | uniq'], ['Bash(sed * | sort * | uniq *)']],
            'a destructive segment stays literal' => ['Bash', ['command' => 'rm -f a.txt && ls'], ['Bash(rm -f a.txt && ls *)']],
            'a fetch piped on stays literal' => ['Bash', ['command' => 'curl -s https://x.test/a | jq .b'], ['Bash(curl -s https://x.test/a | jq *)']],
            'a writing redirection stays literal' => ['Bash', ['command' => 'sort -u a > b.txt && wc -l b.txt'], ['Bash(sort -u a > b.txt && wc *)']],
            'a reserved word stays literal' => ['Bash', ['command' => 'for f in a b; do echo $f; done | sort'], ['Bash(for f in a b; do echo $f; done | sort *)']],
            'a destructive command is exact' => ['Bash', ['command' => 'rm -rf build'], []],
            'a newline is not structure' => ['Bash', ['command' => "ls\nsort"], []],
            'a background job is not structure' => ['Bash', ['command' => 'sleep 1 & ls'], []],
            'a substitution is exact' => ['Bash', ['command' => 'echo $(id)'], []],
            'a writing redirection is exact' => ['Bash', ['command' => 'echo hi > out.txt'], []],
            'an env assignment is exact' => ['Bash', ['command' => 'FOO=1 make test'], []],
            'glob characters are escaped' => ['Bash', ['command' => 'npm run b*x'], ['Bash(npm run b\\*x)', 'Bash(npm run b\\*x *)']],
            'an expandable program name is exact' => ['Bash', ['command' => 'ls*x'], []],
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
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => 'find . | xargs wc -l', 'timeout' => 5]);

        self::assertSame([], $memo->patterns(), 'nothing a rule could fire for');
        self::assertTrue($memo->allows('Bash', ['timeout' => 5, 'command' => 'find . | xargs wc -l']));
        self::assertFalse($memo->allows('Bash', ['command' => 'find . | xargs wc -c', 'timeout' => 5]));
    }

    public function testTheMemoRoundTripsThroughTheSessionGrantMapAndIgnoresOtherKeys(): void
    {
        $memo = SessionPermissionMemo::new()
            ->withGrant('Bash', ['command' => 'git log'])
            ->withGrant('Bash', ['command' => 'find . | xargs wc -l']);
        $map = ['bash {"cmd":"x"}' => true, ...$memo->grants()];

        $back = SessionPermissionMemo::fromGrants($map);

        self::assertSame($memo->patterns(), $back->patterns());
        self::assertSame($memo->grants(), $back->grants(), 'the Chat-native exact key is not this class\'s');
        self::assertTrue($back->allows('Bash', ['command' => 'find . | xargs wc -l']));
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

    public function testAUserWrittenScopeIsRememberedOnlyWhenItCoversTheCallAndNamesTheTool(): void
    {
        $asked = ['command' => 'sed -n 1,5p f | sort | uniq'];
        self::assertSame('sed * | sort * | uniq *', SessionPermissionMemo::editableScopeOf('Bash', $asked));

        $memo = SessionPermissionMemo::new()->withPattern('sed * | sort | uniq', 'Bash', $asked);
        self::assertNotNull($memo);
        self::assertSame(['Bash(sed * | sort | uniq)'], $memo->patterns());
        self::assertTrue($memo->allows('Bash', ['command' => 'sed s/a/b/ g | sort | uniq']));
        self::assertFalse($memo->allows('Bash', ['command' => 'sed x | sort -u | uniq']), 'a bare segment means no arguments');

        self::assertNull(SessionPermissionMemo::new()->withPattern('grep *', 'Bash', $asked), 'it must cover the call being asked about');
        self::assertNull(SessionPermissionMemo::new()->withPattern('', 'Bash', $asked));
        self::assertNull(SessionPermissionMemo::new()->withPattern('Bash*(sed * | sort * | uniq *)', 'Bash', $asked), 'no tool-name glob');
        self::assertSame(
            ['Bash(sed * | sort * | uniq *)'],
            SessionPermissionMemo::new()->withPattern('Bash(sed * | sort * | uniq *)', 'Bash', $asked)?->patterns(),
            'the whole pattern may be typed too',
        );
        self::assertSame(
            ['Bash(rm *)'],
            SessionPermissionMemo::new()->withPattern('rm *', 'Bash', ['command' => 'rm a.txt'])?->patterns(),
            'broader than the suggestion, because the user wrote it',
        );
    }

    public function testTheEditorStartsFromTheExactCommandWhenNoPatternFits(): void
    {
        self::assertSame('find . -name x\\* | xargs rm', SessionPermissionMemo::editableScopeOf('Bash', ['command' => 'find . -name x* | xargs rm']));
        self::assertNull(SessionPermissionMemo::editableScopeOf('mcp__git__status', ['repo' => '.']), 'a tool with no subject has only the tool');
    }

    public function testTheGrantsAreListedOnceEachAndCanBeRevokedOneByOne(): void
    {
        $memo = SessionPermissionMemo::new()
            ->withGrant('Bash', ['command' => 'git status'])
            ->withGrant('Bash', ['command' => 'find . | xargs rm', 'description' => 'x'])
            ->withGrant('Edit', ['file_path' => 'src/A.php']);

        self::assertSame(['Bash(git status *)', 'Edit(src/A.php)', 'Bash: find . | xargs rm'], $memo->entries());

        $revoked = $memo->without(1);
        self::assertNotNull($revoked);
        self::assertSame(['Edit(src/A.php)', 'Bash: find . | xargs rm'], $revoked->entries());
        self::assertFalse($revoked->allows('Bash', ['command' => 'git status']), 'the bare pattern went with it');
        self::assertSame(['Edit(src/A.php)'], $revoked->without(2)?->entries());
        self::assertNull($memo->without(0));
        self::assertNull($memo->without(4));
    }
}
