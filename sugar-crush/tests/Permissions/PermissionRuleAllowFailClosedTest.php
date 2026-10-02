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
use SugarCraft\Crush\ToolCall;

/**
 * Audit F-P5: an argument-scoped ALLOW rule must not grant what its greedy `*`
 * swallows.
 *
 * Measured before the fix with the audit's `r11_rules.php`, `dont-ask` mode and
 * `Allow Bash(git *)`: `git log $(python3 -c …)`, ``git log `id` `` and
 * `git log > /home/u/.bashrc` were all ALLOW, because the matcher split on
 * `[;&|\r\n]` only and every one of those constructs stays inside the segment.
 *
 * Every grant is observed under {@see PermissionMode::DontAsk}, whose evaluator
 * denies `Bash`, so an `Allow` can only come from the rule and a refusal shows
 * up as the mode's `Deny`. Every deny is observed under
 * {@see PermissionMode::BypassPermissions}, where only the rule can deny.
 */
final class PermissionRuleAllowFailClosedTest extends TestCase
{
    private static function decide(PermissionMode $mode, PermissionRule $rule, string $command): PermissionDecision
    {
        return (new PermissionGate($mode, [$rule]))->evaluate(new ToolCall('Bash', ['command' => $command]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function bypassesOfAGitAllow(): iterable
    {
        // The audit's three, verbatim.
        yield 'command substitution' => ['git log $(python3 -c "exec(__import__(\'base64\').b64decode(\'cHJpbnQoMSk=\'))")'];
        yield 'backtick' => ['git log `id`'];
        yield 'output redirection' => ['git log > /home/u/.bashrc'];
        // Neighbours of the same hole.
        yield 'substitution inside double quotes' => ['git log "$(id)"'];
        yield 'backtick inside double quotes' => ['git log "`id`"'];
        yield 'input process substitution' => ['git diff --no-index <(cat /etc/shadow) x'];
        yield 'output process substitution' => ['git log >(sh)'];
        yield 'parameter expansion assigning a substitution' => ['git log ${x:=$(id)}'];
        yield 'prompt-expanded parameter' => ['git log ${PS1@P}'];
        yield 'append redirection' => ['git log >> ~/.bashrc'];
        yield 'fd-prefixed redirection to a file' => ['git log 2> errors.txt'];
        yield 'both-streams redirection' => ['git log &> out.txt'];
        yield 'read-write open creates the file' => ['git log <> f'];
        yield 'here-doc body bash would expand' => ["git commit -F - <<EOF\n\$(id)\nEOF"];
        yield 'chained second command' => ['git log; rm -rf build'];
        yield 'piped second command' => ['git log | sh'];
        yield 'unterminated quote does not parse' => ["git log 'oops"];
        yield 'unterminated substitution does not parse' => ['git log $(id'];
    }

    #[DataProvider('bypassesOfAGitAllow')]
    public function testAGitAllowRuleDoesNotGrantWhatItsStarSwallows(string $command): void
    {
        self::assertSame(
            PermissionDecision::Deny,
            self::decide(PermissionMode::DontAsk, new PermissionRule('Bash(git *)', PermissionAction::Allow), $command),
            json_encode($command) . ' must fall through to the mode, not be granted by Allow Bash(git *)',
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainGitCommands(): iterable
    {
        yield 'bare' => ['git log --oneline'];
        yield 'substitution text inside single quotes is literal' => ["git log --format='\$(id) `id`'"];
        yield 'stderr to /dev/null' => ['git log 2>/dev/null'];
        yield 'stderr onto stdout' => ['git log 2>&1'];
        yield 'stdout to /dev/null' => ['git status > /dev/null'];
        yield 'input from a file' => ['git apply < fix.patch'];
        yield 'quoted separator is not a separator' => ['git commit -m "fix; tidy && done"'];
        yield 'quoted command name' => ["'git' status"];
        yield 'every segment is git' => ['git status && git log | git stripspace'];
        yield 'plain variable' => ['git -C $HOME/proj status'];
    }

    /**
     * The controls: fail-closed must not mean useless. Each of these is one
     * plain `git` invocation, and the quoted-separator case was REFUSED before
     * the fix (the regex split made `git commit -m "fix` and ` tidy` two
     * segments), so this is a precision gain, not only a restriction.
     */
    #[DataProvider('plainGitCommands')]
    public function testAGitAllowRuleStillGrantsPlainGitCommands(string $command): void
    {
        self::assertSame(
            PermissionDecision::Allow,
            self::decide(PermissionMode::DontAsk, new PermissionRule('Bash(git *)', PermissionAction::Allow), $command),
            json_encode($command) . ' is a plain git command and Allow Bash(git *) must grant it',
        );
    }

    /**
     * "Unless a rule explicitly covers it": a pattern that spells the construct
     * itself grants it — and is then matched against the RAW source only, so a
     * quoted `'>'` cannot stand in for the real redirection it hides behind.
     */
    public function testARuleThatSpellsARedirectionGrantsThatRedirectionOnly(): void
    {
        $rule = new PermissionRule('Bash(git log > /tmp/*)', PermissionAction::Allow);

        self::assertSame(PermissionDecision::Allow, self::decide(PermissionMode::DontAsk, $rule, 'git log > /tmp/out.txt'));
        // The quote-removed words of this read `git log > /tmp/x`, which the
        // glob matches; the real redirection is `2> /home/u/.bashrc`.
        self::assertSame(
            PermissionDecision::Deny,
            self::decide(PermissionMode::DontAsk, $rule, "git log '>' /tmp/x 2> /home/u/.bashrc"),
        );
        self::assertSame(PermissionDecision::Deny, self::decide(PermissionMode::DontAsk, $rule, 'git log > /tmp/x; rm -rf y'));
        // Spelling a redirection does not cover a substitution.
        self::assertSame(PermissionDecision::Deny, self::decide(PermissionMode::DontAsk, $rule, 'git log > /tmp/$(id)'));
    }

    public function testARuleThatSpellsASubstitutionGrantsThatSubstitution(): void
    {
        $rule = new PermissionRule('Bash(echo $(date))', PermissionAction::Allow);

        self::assertSame(PermissionDecision::Allow, self::decide(PermissionMode::DontAsk, $rule, 'echo $(date)'));
        self::assertSame(PermissionDecision::Deny, self::decide(PermissionMode::DontAsk, $rule, 'echo $(id)'));
        self::assertSame(PermissionDecision::Deny, self::decide(PermissionMode::DontAsk, $rule, 'echo `date`'));
    }

    /**
     * The restrictive half is untouched in direction and gains the quote-aware
     * readings: a deny still fires on ANY segment, including one that only
     * becomes `rm` after quote removal, and still fires on the substitution
     * spellings an allow now refuses.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function denyCases(): iterable
    {
        yield 'second segment' => ['Bash(rm *)', 'echo hi && rm -rf build'];
        yield 'newline segment' => ['Bash(rm *)', "echo hi\nrm -rf build"];
        yield 'quoted command name in a later segment' => ['Bash(rm *)', "git log && 'rm' -rf build"];
        yield 'backslash-escaped command name' => ['Bash(rm *)', 'true; \\rm -rf build'];
        yield 'substitution under a deny' => ['Bash(git *)', 'git log $(id)'];
        yield 'redirection under a deny' => ['Bash(git *)', 'git log > /home/u/.bashrc'];
        yield 'unparseable line under a deny' => ['Bash(git *)', "git log 'oops"];
    }

    #[DataProvider('denyCases')]
    public function testADenyRuleStillFiresOnAnySegment(string $pattern, string $command): void
    {
        self::assertSame(
            PermissionDecision::Deny,
            self::decide(PermissionMode::BypassPermissions, new PermissionRule($pattern, PermissionAction::Deny), $command),
            json_encode($command) . " must be denied by Deny {$pattern}",
        );
    }

    /**
     * `Ask` is restrictive, so a substitution that an `Allow` refuses still
     * raises an `Ask` rule rather than slipping past both.
     */
    public function testAnAskRuleStillFiresOnASubstitutionSpelling(): void
    {
        self::assertSame(
            PermissionDecision::Ask,
            self::decide(PermissionMode::BypassPermissions, new PermissionRule('Bash(git *)', PermissionAction::Ask), 'git log `id`'),
        );
    }
}
