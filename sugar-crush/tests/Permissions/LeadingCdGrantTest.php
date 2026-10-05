<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Permissions;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Permissions\LeadingCd;
use SugarCraft\Crush\Permissions\PermissionAction;
use SugarCraft\Crush\Permissions\PermissionDecision;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\PermissionRule;
use SugarCraft\Crush\Permissions\SessionPermissionMemo;
use SugarCraft\Crush\ToolCall;

/**
 * User decision 2026-10-11: for an "always" grant a leading
 * `cd <dir inside the project> &&` is a no-op — `always` on
 * `cd /repo && git status --short` remembers `Bash(git status *)`, and a later
 * `cd /repo && git status`, `git status` or `cd /repo/sub && git status` is
 * covered. Anything that is not exactly one plain in-project `cd` followed by
 * `&&` is left as written (fail closed), and a refusal still judges the line
 * as written.
 */
final class LeadingCdGrantTest extends TestCase
{
    private string $base;

    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/lcd-' . bin2hex(random_bytes(4));
        mkdir($base . '/project/sub/deep', 0o700, true);
        mkdir($base . '/outside', 0o700, true);
        symlink($base . '/outside', $base . '/project/out');
        symlink($base . '/project/sub', $base . '/project/inlink');
        $this->base = (string) realpath($base);
        $this->root = $this->base . '/project';
    }

    protected function tearDown(): void
    {
        putenv('CDPATH');
        foreach (['project/out', 'project/inlink'] as $link) {
            @unlink($this->base . '/' . $link);
        }
        foreach (['project/sub/deep', 'project/sub', 'project', 'outside', ''] as $dir) {
            @rmdir(rtrim($this->base . '/' . $dir, '/'));
        }
    }

    // =====================================================================
    // what the normaliser strips
    // =====================================================================

    /** @return array<string, array{string, string}> */
    public static function stripped(): array
    {
        return [
            'the root itself' => ['cd {root} && git status --short', 'git status --short'],
            'a directory below it' => ['cd {root}/sub/deep && git status', 'git status'],
            'a relative directory' => ['cd sub && git status', 'git status'],
            'a dot-relative directory' => ['cd ./sub && ls', 'ls'],
            'a double-quoted path' => ['cd "{root}/sub" && ls', 'ls'],
            'a single-quoted path' => ["cd '{root}' && ls", 'ls'],
            'an in-project symlink' => ['cd inlink && ls', 'ls'],
            'a dot-dot that stays inside' => ['cd sub/../sub && ls', 'ls'],
            'a newline after the &&' => ["cd {root} &&\n  git status", 'git status'],
            'the remainder keeps its pipe' => ['cd {root} && git status | sh', 'git status | sh'],
            'the remainder keeps its chain' => ['cd {root} && a && b', 'a && b'],
            'only ONE cd comes off' => ['cd {root} && cd /etc && ls', 'cd /etc && ls'],
        ];
    }

    #[DataProvider('stripped')]
    public function testAnInProjectLeadingCdIsStripped(string $command, string $remainder): void
    {
        self::assertSame($remainder, LeadingCd::strip($this->expand($command), $this->root));
    }

    /** @return array<string, array{string}> */
    public static function kept(): array
    {
        return [
            'a directory outside the project' => ['cd /etc && git status'],
            'dot-dot out of the project' => ['cd ../.. && ls'],
            'a symlink pointing out' => ['cd out && ls'],
            'a symlink pointing out, absolute' => ['cd {root}/out && ls'],
            'a directory that does not exist' => ['cd nope && ls'],
            'a parameter expansion' => ['cd $HOME && ls'],
            'a braced expansion' => ['cd ${HOME} && ls'],
            'a substitution' => ['cd "$(x)" && ls'],
            'a backtick substitution' => ['cd `x` && ls'],
            'a glob' => ['cd su* && ls'],
            'a tilde' => ['cd ~ && ls'],
            'another user\'s home' => ['cd ~root && ls'],
            'cd -' => ['cd - && ls'],
            'an option' => ['cd -P {root} && ls'],
            'bare cd' => ['cd && ls'],
            'cd alone' => ['cd {root}'],
            'nothing after the &&' => ['cd {root} && '],
            'a semicolon' => ['cd {root}; ls'],
            'an or' => ['cd {root} || ls'],
            'a pipe' => ['cd {root} | ls'],
            'pushd' => ['pushd {root} && ls'],
            'a redirection on the cd' => ['cd {root} 2>/dev/null && ls'],
            'an unterminated quote' => ['cd {root} && echo "x'],
            'a doubled operator' => ['cd {root} &&& ls'],
            'not at the start' => ['ls && cd {root} && ls'],
        ];
    }

    #[DataProvider('kept')]
    public function testAnythingElseIsLeftAsWritten(string $command): void
    {
        self::assertNull(LeadingCd::strip($this->expand($command), $this->root));
    }

    public function testNoRootStripsNothing(): void
    {
        self::assertNull(LeadingCd::strip('cd / && ls', null));
        self::assertNull(LeadingCd::strip('cd / && ls', ''));
        self::assertSame(['command' => 'cd / && ls'], SessionPermissionMemo::grantArguments('Bash', ['command' => 'cd / && ls'], null));
    }

    public function testWithCdpathSetABareRelativeNameIsNotTrusted(): void
    {
        putenv('CDPATH=' . $this->base . '/outside');

        self::assertNull(LeadingCd::strip('cd sub && ls', $this->root), 'bash searches CDPATH before the working directory');
        self::assertSame('ls', LeadingCd::strip('cd ./sub && ls', $this->root));
        self::assertSame('ls', LeadingCd::strip('cd ' . $this->root . ' && ls', $this->root));
    }

    // =====================================================================
    // what "always" remembers, and what it covers later
    // =====================================================================

    public function testAlwaysOnAnInProjectCdRemembersTheRemaindersPattern(): void
    {
        $asked = ['command' => "cd {$this->root} && git status --short", 'description' => 'Status'];

        self::assertSame(['Bash(git status)', 'Bash(git status *)'], SessionPermissionMemo::patternsFor('Bash', $asked, $this->root));
        self::assertSame('Bash(git status *)', SessionPermissionMemo::scopeOf('Bash', $asked, $this->root));

        $memo = SessionPermissionMemo::new()->withGrant('Bash', $asked, $this->root);
        self::assertSame(['Bash(git status)', 'Bash(git status *)'], $memo->patterns());

        foreach ([
            "cd {$this->root} && git status",
            'git status',
            "cd {$this->root}/sub && git status",
            'cd sub/deep && git status --porcelain',
        ] as $later) {
            self::assertTrue($memo->allows('Bash', ['command' => $later], $this->root), $later);
        }

        foreach ([
            'cd /etc && git status' => 'a cd out of the project is part of the command',
            "cd {$this->root}/out && git status" => 'a symlink out of the project is not inside',
            'cd ../.. && git status' => 'dot-dot out of the project',
            'cd $HOME && git status' => 'an expansion is not provably inside',
            "cd {$this->root} && git push" => 'the remainder must be covered',
            "cd {$this->root} && git status && rm -rf x" => 'every command of the remainder must be covered',
            "cd {$this->root} && git status | sh" => 'a pipe in the remainder is not git status',
        ] as $later => $why) {
            self::assertFalse($memo->allows('Bash', ['command' => $later], $this->root), $why);
        }

        self::assertFalse($memo->allows('Bash', ['command' => "cd {$this->root} && git status"]), 'no root, no stripping');
    }

    public function testAnOutOfProjectCdStaysAnExactGrant(): void
    {
        $asked = ['command' => 'cd /etc && git status'];

        self::assertSame([], SessionPermissionMemo::patternsFor('Bash', $asked, $this->root));
        self::assertSame('this exact command', SessionPermissionMemo::scopeOf('Bash', $asked, $this->root));

        $memo = SessionPermissionMemo::new()->withGrant('Bash', $asked, $this->root);
        self::assertSame([], $memo->patterns());
        self::assertTrue($memo->allows('Bash', $asked + ['description' => 'again'], $this->root));
        self::assertFalse($memo->allows('Bash', ['command' => 'git status'], $this->root));
        self::assertFalse($memo->allows('Bash', ['command' => "cd {$this->root} && git status"], $this->root));
    }

    /** @return array<string, array{string, string, string}> */
    public static function exactRemainders(): array
    {
        return [
            'a pipe' => ['git status | sh', 'git status | sh', 'git status | bash'],
            'a further &&' => ['a && b', 'a && b', 'a && c'],
            'a semicolon' => ['ls; pwd', 'ls; pwd', 'ls; rm x'],
            'an or' => ['make || true', 'make || true', 'make || rm x'],
            'a redirection' => ['echo hi > out.txt', 'echo hi > out.txt', 'echo hi > other.txt'],
        ];
    }

    #[DataProvider('exactRemainders')]
    public function testAnExactOnlyRemainderIsRememberedExactlyWithoutTheCd(string $remainder, string $same, string $different): void
    {
        $asked = ['command' => "cd {$this->root} && {$remainder}", 'description' => 'x'];

        self::assertSame([], SessionPermissionMemo::patternsFor('Bash', $asked, $this->root), 'the remainder gets no pattern');
        self::assertSame('this exact command without the leading cd', SessionPermissionMemo::scopeOf('Bash', $asked, $this->root));

        $memo = SessionPermissionMemo::new()->withGrant('Bash', $asked, $this->root);
        self::assertSame([], $memo->patterns());
        self::assertTrue($memo->allows('Bash', ['command' => $same, 'description' => 'y'], $this->root));
        self::assertTrue($memo->allows('Bash', ['command' => "cd {$this->root}/sub && {$same}"], $this->root));
        self::assertFalse($memo->allows('Bash', ['command' => $different], $this->root));
        self::assertFalse($memo->allows('Bash', ['command' => "cd /etc && {$same}"], $this->root));
        self::assertSame(
            SessionPermissionMemo::callKey('Bash', ['command' => $same]),
            SessionPermissionMemo::callKey('Bash', $asked, $this->root),
            'the exact-call identity is the remainder',
        );
    }

    // =====================================================================
    // the gate: a remembered grant answers an Ask, never a refusal
    // =====================================================================

    public function testTheGateAnswersAnAskWithTheRemaindersGrant(): void
    {
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => "cd {$this->root} && git status --short"], $this->root);
        $gate = (new PermissionGate(PermissionMode::Default))->withSessionRules($memo->rules());

        foreach (["cd {$this->root} && git status", 'git status', "cd {$this->root}/sub && git status"] as $later) {
            self::assertSame(PermissionDecision::Allow, $gate->evaluate(new ToolCall('Bash', ['command' => $later]), $this->root), $later);
        }
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('Bash', ['command' => 'cd /etc && git status']), $this->root));
        self::assertSame(PermissionDecision::Ask, $gate->evaluate(new ToolCall('Bash', ['command' => "cd {$this->root} && git status | sh"]), $this->root));
        self::assertSame(
            PermissionDecision::Ask,
            $gate->evaluate(new ToolCall('Bash', ['command' => "cd {$this->root} && git status"])),
            'a gate that is not told the root strips nothing',
        );
    }

    /**
     * The rule grammar's own reading applies: `Bash(git status *)` needs an
     * argument after `git status` (which is why a grant records the bare
     * `Bash(git status)` beside it), so the bare form is denied by the bare
     * rule.
     */
    public function testAConfiguredDenyStillWinsOverTheStrippedForm(): void
    {
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => "cd {$this->root} && git status --short"], $this->root);
        $gate = (new PermissionGate(PermissionMode::Default, [
            new PermissionRule('Bash(git status *)', PermissionAction::Deny),
            new PermissionRule('Bash(git status)', PermissionAction::Deny),
        ]))->withSessionRules($memo->rules());

        foreach (["cd {$this->root} && git status --short", "cd {$this->root} && git status", 'git status --short', 'git status'] as $command) {
            self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => $command]), $this->root), $command);
        }
        self::assertSame(PermissionDecision::Allow, (new PermissionGate(PermissionMode::Default))->withSessionRules($memo->rules())
            ->evaluate(new ToolCall('Bash', ['command' => "cd {$this->root} && git status --short"]), $this->root), 'fixture: without the deny it is granted');
    }

    public function testPlanModeStillRefusesAWriteTheGrantCovers(): void
    {
        $memo = SessionPermissionMemo::new()->withGrant('Bash', ['command' => "cd {$this->root} && touch notes.txt"], $this->root);
        self::assertSame(['Bash(touch)', 'Bash(touch *)'], $memo->patterns());
        $gate = (new PermissionGate(PermissionMode::Plan))->withSessionRules($memo->rules());

        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => "cd {$this->root} && touch notes.txt"]), $this->root));
        self::assertSame(PermissionDecision::Deny, $gate->evaluate(new ToolCall('Bash', ['command' => 'touch notes.txt']), $this->root));
    }

    private function expand(string $command): string
    {
        return str_replace('{root}', $this->root, $command);
    }
}
