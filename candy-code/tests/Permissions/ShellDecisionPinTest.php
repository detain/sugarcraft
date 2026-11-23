<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Permissions\PermissionAction;
use SugarCraft\Code\Permissions\PermissionDecision;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\PermissionRule;
use SugarCraft\Code\ToolCall;

/**
 * The gate's verdict on representative COMPOUND and QUOTED shell lines,
 * pinned so the parse-once change of CC4-3 (one {@see \SugarCraft\Code\Permissions\ShellWords}
 * lex per command string instead of one per rule check) is provably
 * behavior-neutral.
 *
 * Every expectation below was recorded against the tree BEFORE that change
 * (origin/master b0690369f1) and must hold after it. Each case is evaluated
 * twice on a fresh gate: the first evaluation lexes the line, the second
 * answers from whatever the first left behind, so a stale or shared parse
 * that changed a verdict shows up as a mismatch between the two.
 *
 * The mode is chosen per case so the verdict is about the thing named, in
 * the pairing {@see PermissionGateArgumentRulesTest} explains: a Deny or Ask
 * is observed under `bypass-permissions` (which would otherwise allow), an
 * Allow under `dont-ask` (which would otherwise deny).
 */
final class ShellDecisionPinTest extends TestCase
{
    /**
     * @return iterable<string, array{PermissionMode, list<array{string, PermissionAction}>, string, PermissionDecision, ?string}>
     */
    public static function cases(): iterable
    {
        $bypass = PermissionMode::BypassPermissions;
        $dontAsk = PermissionMode::DontAsk;
        $denyRm = [['Bash(rm *)', PermissionAction::Deny]];
        $denyRmRf = [['Bash(rm -rf *)', PermissionAction::Deny]];
        $allowGit = [['Bash(git *)', PermissionAction::Allow]];

        // Restrictive rules: union over every reading of every segment.
        yield 'deny: rm after &&' => [$bypass, $denyRm, 'echo hi && rm -rf x', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: rm after a pipe' => [$bypass, $denyRm, 'ls | rm x', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: rm after a newline' => [$bypass, $denyRm, "echo hi\nrm x", PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: quoted program name' => [$bypass, $denyRmRf, "'rm' -rf x", PermissionDecision::Deny, 'Bash(rm -rf *)'];
        yield 'deny: quoted flag' => [$bypass, $denyRmRf, "rm '-rf' x", PermissionDecision::Deny, 'Bash(rm -rf *)'];
        yield 'deny: ANSI-C flag' => [$bypass, $denyRmRf, "rm \$'\\x2drf' x", PermissionDecision::Deny, 'Bash(rm -rf *)'];
        yield 'deny: escaped program name' => [$bypass, $denyRm, '\\rm x', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: for loop body' => [$bypass, $denyRm, 'for f in a b; do rm "$f"; done', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: if branch' => [$bypass, $denyRm, 'if true; then rm y; fi', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: while read body' => [$bypass, $denyRm, "while read f; do rm \"\$f\"; done < list", PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'deny: quoted rm is only an argument' => [$bypass, $denyRm, "echo 'rm -rf x'", PermissionDecision::Allow, null];
        yield 'deny: quoted semicolon still splits raw' => [$bypass, $denyRm, 'echo "a; rm x"', PermissionDecision::Deny, 'Bash(rm *)'];
        // Under bypass an Ask rule falls through to the mode's Allow; the
        // rule that matched is still the one explainRule() names.
        yield 'ask: curl in a pipeline' => [$bypass, [['Bash(curl *)', PermissionAction::Ask]], 'echo x | curl http://a', PermissionDecision::Allow, 'Bash(curl *)'];

        // Allow rules: fail closed, every simple command must be covered.
        yield 'allow: quoted semicolon is one command' => [$dontAsk, $allowGit, 'git commit -m "a; b"', PermissionDecision::Allow, 'Bash(git *)'];
        yield 'allow: chained rm is not covered' => [$dontAsk, $allowGit, 'git log && rm x', PermissionDecision::Deny, null];
        yield 'allow: substitution is not covered' => [$dontAsk, $allowGit, 'git log $(id)', PermissionDecision::Deny, null];
        yield 'allow: backtick is not covered' => [$dontAsk, $allowGit, 'git log `id`', PermissionDecision::Deny, null];
        yield 'allow: single-quoted substitution is literal' => [$dontAsk, $allowGit, "git log --format='\$(x)'", PermissionDecision::Allow, 'Bash(git *)'];
        yield 'allow: writing redirection is not covered' => [$dontAsk, $allowGit, 'git log > out.txt', PermissionDecision::Deny, null];
        yield 'allow: inert redirection is covered' => [$dontAsk, $allowGit, 'git log 2>/dev/null', PermissionDecision::Allow, 'Bash(git *)'];
        yield 'allow: quoted program name' => [$dontAsk, $allowGit, "'git' log", PermissionDecision::Allow, 'Bash(git *)'];
        yield 'allow: unterminated quote' => [$dontAsk, $allowGit, 'git log "oops', PermissionDecision::Deny, null];
        yield 'allow: parameter expansion is not covered' => [$dontAsk, $allowGit, 'git log ${x:=y}', PermissionDecision::Deny, null];
        yield 'allow: structured chain' => [$dontAsk, [['Bash(git add * && git commit *)', PermissionAction::Allow]], 'git add a && git commit -m "x y"', PermissionDecision::Allow, 'Bash(git add * && git commit *)'];
        yield 'allow: structured chain, other operator' => [$dontAsk, [['Bash(git add * && git commit *)', PermissionAction::Allow]], 'git add a; git commit -m x', PermissionDecision::Deny, null];
        yield 'allow: structured pipeline' => [$dontAsk, [['Bash(sort * | uniq *)', PermissionAction::Allow]], "sort -u 'a b' | uniq -c", PermissionDecision::Allow, 'Bash(sort * | uniq *)'];

        // Several rules over one line: the first match wins, as before.
        $several = [
            ['Bash(git *)', PermissionAction::Allow],
            ['Bash(curl *)', PermissionAction::Ask],
            ['Bash(rm *)', PermissionAction::Deny],
            ['Bash(* > /etc/*)', PermissionAction::Deny],
        ];
        yield 'several: deny wins over allow' => [$bypass, $several, 'git log; rm x', PermissionDecision::Deny, 'Bash(rm *)'];
        yield 'several: deny over a redirection source' => [$bypass, $several, 'echo x > /etc/passwd', PermissionDecision::Deny, 'Bash(* > /etc/*)'];
        yield 'several: ask over a quoted line' => [$bypass, $several, "curl 'http://a?b;c'", PermissionDecision::Allow, 'Bash(curl *)'];
        yield 'several: allow alone' => [$dontAsk, $several, 'git status', PermissionDecision::Allow, 'Bash(git *)'];
    }

    /**
     * @param list<array{string, PermissionAction}> $rules
     */
    #[DataProvider('cases')]
    public function testTheVerdictIsPinned(PermissionMode $mode, array $rules, string $command, PermissionDecision $expected, ?string $explained): void
    {
        $gate = new PermissionGate($mode, array_map(
            static fn (array $r): PermissionRule => new PermissionRule($r[0], $r[1]),
            $rules,
        ));
        $call = new ToolCall('Bash', ['command' => $command]);

        self::assertSame($expected, $gate->evaluate($call), 'first evaluation');
        self::assertSame($expected, $gate->evaluate($call), 'second evaluation of the same line');
        self::assertSame($explained, $gate->explainRule($call)?->pattern, 'the rule that decided it');
    }

    /**
     * @return iterable<string, array{PermissionMode, string, PermissionDecision}>
     */
    public static function modeCases(): iterable
    {
        $plan = PermissionMode::Plan;
        $edits = PermissionMode::AcceptEdits;

        // Plan mode's read-only Bash (ShellWords + ShellCompound, fail closed).
        yield 'plan: read-only pipeline' => [$plan, 'git log | grep x', PermissionDecision::Allow];
        yield 'plan: read-only for loop' => [$plan, 'for f in a b; do wc -l "$f"; done', PermissionDecision::Allow];
        yield 'plan: writing redirection' => [$plan, 'echo a > f', PermissionDecision::Deny];
        yield 'plan: substitution' => [$plan, 'cat $(id)', PermissionDecision::Deny];
        yield 'plan: quoted operator is an argument' => [$plan, 'grep "a|b" file', PermissionDecision::Allow];
        yield 'plan: chained write' => [$plan, 'ls && rm x', PermissionDecision::Deny];

        // accept-edits scoped writes (PermissionGate::tokenizeSingleCommand,
        // the refuse-on-metacharacter lexer CC4-3 keeps as its own tool).
        yield 'edits: quoted path' => [$edits, "mkdir -p 'a b'", PermissionDecision::Allow];
        yield 'edits: separator' => [$edits, 'mkdir a; rm b', PermissionDecision::Ask];
        yield 'edits: quoted separator' => [$edits, "mkdir 'a;b'", PermissionDecision::Allow];
        yield 'edits: substitution inside double quotes' => [$edits, 'mkdir "$(id)"', PermissionDecision::Ask];
        yield 'edits: escaped space' => [$edits, 'mkdir a\\ b', PermissionDecision::Ask];
        yield 'edits: brace expansion' => [$edits, 'mkdir {a,b}', PermissionDecision::Ask];
        yield 'edits: hash is a literal path byte' => [$edits, 'mkdir #x', PermissionDecision::Allow];
        yield 'edits: climbing path' => [$edits, 'touch ../x', PermissionDecision::Ask];
    }

    #[DataProvider('modeCases')]
    public function testTheModeVerdictIsPinned(PermissionMode $mode, string $command, PermissionDecision $expected): void
    {
        $gate = (new PermissionGate($mode))->withReadOnlyAutoAllow(false);
        $call = new ToolCall('Bash', ['command' => $command]);

        self::assertSame($expected, $gate->evaluate($call), 'first evaluation');
        self::assertSame($expected, $gate->evaluate($call), 'second evaluation of the same line');
    }
}
